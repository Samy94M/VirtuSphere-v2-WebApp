BeforeAll {
    $script:RepoRoot = $PSScriptRoot
    while ($script:RepoRoot -and -not (Test-Path -LiteralPath (Join-Path $script:RepoRoot 'AGENTS.md'))) {
        $parent = Split-Path $script:RepoRoot -Parent
        if ($parent -eq $script:RepoRoot) { break }
        $script:RepoRoot = $parent
    }
    if (-not (Test-Path -LiteralPath (Join-Path $script:RepoRoot 'AGENTS.md'))) { throw 'Repository root not found.' }
    $script:PackagingPath = Join-Path $script:RepoRoot 'Powershell-MECM/mecm/VirtuSphere-ClientPackaging.ps1'
    $script:BundlesPath = Join-Path $script:RepoRoot 'Powershell-MECM/mecm/VirtuSphere-ClientBundles.ps1'
    . $script:PackagingPath
    . $script:BundlesPath

    function Write-TestUtf8 {
        param([string]$Path, [string]$Text)
        [IO.File]::WriteAllText($Path, $Text, (New-Object Text.UTF8Encoding($false)))
    }

    function New-TestClientSource {
        param([string]$Root)
        New-Item -ItemType Directory -Path $Root -Force | Out-Null
        $specs = @(Get-VsClientAppSpecs)
        $names = @($specs | ForEach-Object { @($_.RequiredFiles) } | Where-Object { $_ -ne 'bootstrap.json' } | Sort-Object -Unique)
        foreach ($name in $names) {
            $text = "# real fixture $name`r`n"
            if ($name -eq 'VirtuSphere-Client-Common.ps1') { $text += '$script:VsExpectedClientLoggingContractVersion = 1' + "`r`n" }
            elseif ($name -eq 'VirtuSphere-Client-Logging.ps1') { $text += '$script:VsClientLoggingContractVersion = 1' + "`r`n" }
            else { $text += "exit 0`r`n" }
            Write-TestUtf8 -Path (Join-Path $Root $name) -Text $text
        }
    }

    function New-TestStage {
        param([string]$Root, [string]$ConfigHash = ('a' * 64))
        $source = Join-Path $Root 'source'
        $packages = Join-Path $Root 'packages'
        New-TestClientSource -Root $source
        New-Item -ItemType Directory -Path $packages -Force | Out-Null
        New-VsClientBundleStage -PackagesBase $packages -SourceDir $source -PlanId 'MC03-test-plan' -ConfigHash $ConfigHash -BootstrapConfiguration @{
            WebAPI = 'virtusphere.test:8021'; Scheme = 'https'; CertThumbprint = ('A' * 40)
        }
    }

    function New-TestCmEvidence {
        param([object[]]$Specs, [string]$DistributionPoint = 'DP01')
        $definitions = @()
        $distribution = @()
        foreach ($spec in $Specs) {
            $contentId = 'content-' + $spec.AppName
            $requiredBy = @($Specs | Where-Object { [string]$_.DependsOn -ceq [string]$spec.AppName } | ForEach-Object { [string]$_.AppName })
            $definitions += [pscustomobject]@{
                AppName = $spec.AppName; ApplicationId = 'app-' + $spec.AppName
                DeploymentTypeId = 'dt-' + $spec.AppName; DefinitionHash = ('b' * 64)
                ContentId = $contentId; Role = $spec.Role; DependsOn = $spec.DependsOn; RequiredBy = $requiredBy; State = 'confirmed'
            }
            $distribution += [pscustomobject]@{
                AppName = $spec.AppName; ContentId = $contentId
                DistributionPoint = $DistributionPoint; State = 'ready'
            }
        }
        [pscustomobject]@{ Definitions = $definitions; Distribution = $distribution; Targets = @($DistributionPoint) }
    }

    function New-TestPredecessor {
        param([string]$Root, $Stage)
        $bundleId = 'c' * 64
        $archive = Join-Path $Root $bundleId
        New-Item -ItemType Directory -Path $archive -Force | Out-Null
        foreach ($spec in $Stage.Specs) {
            Copy-Item -LiteralPath (Join-Path $Stage.StageRoot $spec.Folder) -Destination $archive -Recurse
            $bootstrapPath = Join-Path (Join-Path $archive $spec.Folder) 'bootstrap.json'
            $bootstrap = Get-Content -LiteralPath $bootstrapPath -Raw -Encoding UTF8 | ConvertFrom-Json
            $bootstrap.BundleId = $bundleId
            Write-TestUtf8 -Path $bootstrapPath -Text ($bootstrap | ConvertTo-Json)
        }
        $validation = Get-VsClientBundleValidation -Root $archive -Specs $Stage.Specs -BundleId $bundleId -BootstrapConfiguration @{
            WebAPI = [string]$Stage.Bootstrap.WebAPI; Scheme = [string]$Stage.Bootstrap.Scheme; CertThumbprint = [string]$Stage.Bootstrap.CertThumbprint
        }
        if ($validation.Status -ne 'ready') { throw 'Test predecessor fixture is invalid.' }
        $manifest = [ordered]@{ Schema = 1; BundleId = $bundleId; ManifestHash = $validation.ManifestHash; Files = @($validation.Manifest) }
        $manifestPath = Join-Path $archive 'manifest.json'
        Write-TestUtf8 -Path $manifestPath -Text ($manifest | ConvertTo-Json -Depth 8)
        $manifestFileHash = Get-VsClientSha256HexFromFile -Path $manifestPath
        $receipt = [ordered]@{
            Schema = 1; Status = 'success'; BundleId = $bundleId
            ContentManifestHash = $validation.ManifestHash; ManifestFileHash = $manifestFileHash
            CompletedAtUtc = ([DateTime]'2026-01-01T00:00:00Z').ToString('o')
            Graph = @($Stage.Specs | ForEach-Object { [ordered]@{ AppName = $_.AppName; Folder = $_.Folder; Role = $_.Role; DependsOn = $_.DependsOn } })
        }
        $receiptPath = Join-Path $archive 'receipt.json'
        Write-TestUtf8 -Path $receiptPath -Text ($receipt | ConvertTo-Json -Depth 5)
        [pscustomobject]@{
            BundleId = $bundleId; ArchivePath = $archive
            ReceiptHash = Get-VsClientSha256HexFromFile -Path $receiptPath
            ManifestFileHash = $manifestFileHash
        }
    }

    function New-TestRetentionReceipt {
        param([string]$Root, [int]$Number, [DateTime]$CompletedAtUtc, [switch]$Corrupt)
        $bundleId = ([string]::Format('{0:x}', $Number)).PadLeft(64, '0')
        $path = Join-Path $Root $bundleId
        New-Item -ItemType Directory -Path $path -Force | Out-Null
        $manifestPath = Join-Path $path 'manifest.json'
        Write-TestUtf8 -Path $manifestPath -Text '{}'
        if ($Corrupt) { Write-TestUtf8 -Path (Join-Path $path 'receipt.json') -Text '{broken'; return $bundleId }
        $receipt = [ordered]@{
            Schema = 1; Status = 'success'; BundleId = $bundleId
            CompletedAtUtc = $CompletedAtUtc.ToUniversalTime().ToString('o')
            ManifestFileHash = Get-VsClientSha256HexFromFile -Path $manifestPath
        }
        Write-TestUtf8 -Path (Join-Path $path 'receipt.json') -Text ($receipt | ConvertTo-Json)
        return $bundleId
    }
}

Describe 'MC03 four-content client bundle' {
    BeforeEach {
        $script:CaseRoot = Join-Path ([IO.Path]::GetTempPath()) ('vs-mc03-bundle-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $script:CaseRoot -Force | Out-Null
    }

    AfterEach {
        Remove-Item -LiteralPath $script:CaseRoot -Recurse -Force -ErrorAction SilentlyContinue
    }

    It 'uses the existing builder to produce four closed real-file folders and a deterministic 64-hex identity' {
        $first = New-TestStage -Root (Join-Path $script:CaseRoot 'one')
        $second = New-TestStage -Root (Join-Path $script:CaseRoot 'two')
        $first.BundleId | Should -Match '^[0-9a-f]{64}$'
        $second.BundleId | Should -BeExactly $first.BundleId
        @(Get-ChildItem -LiteralPath $first.StageRoot -Directory -Force).Count | Should -Be 4
        @(Get-ChildItem -LiteralPath $first.StageRoot -File -Force).Count | Should -Be 0
        foreach ($spec in $first.Specs) {
            $files = @(Get-ChildItem -LiteralPath (Join-Path $first.StageRoot $spec.Folder) -File -Force)
            $files.Count | Should -Be 4
            @($files | Where-Object { ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 }).Count | Should -Be 0
        }
        $validation = Get-VsClientBundleValidation -Root $first.StageRoot -Specs $first.Specs -BundleId $first.BundleId -BootstrapConfiguration @{
            WebAPI = 'virtusphere.test:8021'; Scheme = 'https'; CertThumbprint = ('A' * 40)
        }
        $validation.Status | Should -Be 'ready'
        $validation.ManifestHash | Should -BeExactly $first.ManifestHash
    }

    It 'changes BundleId when a real source hash changes' {
        $firstRoot = Join-Path $script:CaseRoot 'one'
        $secondRoot = Join-Path $script:CaseRoot 'two'
        $first = New-TestStage -Root $firstRoot
        $source = Join-Path $secondRoot 'source'
        $packages = Join-Path $secondRoot 'packages'
        New-TestClientSource -Root $source
        Add-Content -LiteralPath (Join-Path $source 'client_staticip.ps1') -Value '# changed'
        New-Item -ItemType Directory -Path $packages -Force | Out-Null
        $second = New-VsClientBundleStage -PackagesBase $packages -SourceDir $source -PlanId 'MC03-test-plan' -ConfigHash ('a' * 64) -BootstrapConfiguration @{
            WebAPI = 'virtusphere.test:8021'; Scheme = 'https'; CertThumbprint = ('A' * 40)
        }
        $second.SourceManifestHash | Should -Not -Be $first.SourceManifestHash
        $second.BundleId | Should -Not -Be $first.BundleId
    }

    It 'lets a FileSystem-scoped body see the caller''s own Root and Path values' {
        $Root = Join-Path $script:CaseRoot 'caller-root'
        $Path = Join-Path $script:CaseRoot 'caller-path'
        $seen = Invoke-VsClientFileSystemScope -VsScopePath $script:CaseRoot -VsScopeBody { @($Root, $Path) }
        $seen | Should -Be @($Root, $Path)
    }

    It 'binds the spec hash to the deployment-type runtime from the one spec table' {
        $specs = @(Get-VsClientAppSpecs)
        $before = Get-VsClientSpecHash -Specs $specs
        $specs[3].MaximumRuntimeMins = 30
        Get-VsClientSpecHash -Specs $specs | Should -Not -Be $before
    }

    It 'writes schema 2 bootstraps whose BundleId matches the stage identity' {
        $stage = New-TestStage -Root $script:CaseRoot
        foreach ($spec in $stage.Specs) {
            $document = Get-Content -LiteralPath (Join-Path (Join-Path $stage.StageRoot $spec.Folder) 'bootstrap.json') -Raw -Encoding UTF8 | ConvertFrom-Json
            @($document.PSObject.Properties.Name | Sort-Object) | Should -Be @('BundleId', 'CertThumbprint', 'Schema', 'Scheme', 'WebAPI')
            $document.Schema | Should -Be 2
            $document.BundleId | Should -BeExactly $stage.BundleId
        }
    }

    It 'reports missing, extra, and corrupt bootstrap content as partial' {
        $stage = New-TestStage -Root $script:CaseRoot
        $config = @{ WebAPI = 'virtusphere.test:8021'; Scheme = 'https'; CertThumbprint = ('A' * 40) }
        Remove-Item -LiteralPath (Join-Path $stage.StageRoot 'client_getInfos/client_getInfos.ps1')
        Write-TestUtf8 -Path (Join-Path $stage.StageRoot 'client_hostname/extra.ps1') -Text 'extra'
        Write-TestUtf8 -Path (Join-Path $stage.StageRoot 'client_VMDisksOnline/bootstrap.json') -Text '{broken'
        $result = Get-VsClientBundleValidation -Root $stage.StageRoot -Specs $stage.Specs -BundleId $stage.BundleId -BootstrapConfiguration $config
        $result.Status | Should -Be 'partial'
        ($result.Findings -join '|') | Should -Match 'file:client_getInfos'
        ($result.Findings -join '|') | Should -Match 'extra.ps1'
        ($result.Findings -join '|') | Should -Match 'bootstrap:unreadable'
        $result.ManifestHash | Should -BeNullOrEmpty
    }

    It 'uses FileSystem context and restores a non-FileSystem caller location' {
        $stage = New-TestStage -Root $script:CaseRoot
        $before = (Get-Location).Path
        Push-Location HKCU:\
        try {
            $registryLocation = (Get-Location).Path
            $result = Get-VsClientBundleValidation -Root $stage.StageRoot -Specs $stage.Specs -BundleId $stage.BundleId -BootstrapConfiguration @{
                WebAPI = 'virtusphere.test:8021'; Scheme = 'https'; CertThumbprint = ('A' * 40)
            }
            $result.Status | Should -Be 'ready'
            (Get-Location).Path | Should -BeExactly $registryLocation
        } finally { Pop-Location }
        (Get-Location).Path | Should -BeExactly $before
    }

    It 'blocks archive completion for partial CM or DP-ready evidence' {
        $stage = New-TestStage -Root $script:CaseRoot
        $predecessor = New-TestPredecessor -Root (Join-Path $script:CaseRoot 'previous') -Stage $stage
        $evidence = New-TestCmEvidence -Specs $stage.Specs
        $evidence.Definitions = @($evidence.Definitions | Select-Object -First 3)
        { Complete-VsClientBundleArchive -StageEvidence $stage -ArchiveRoot (Join-Path $script:CaseRoot 'archive') -CmDefinitions $evidence.Definitions -ExpectedDistributionPoints $evidence.Targets -DistributionEvidence $evidence.Distribution -PredecessorEvidence $predecessor -Actor 'tester' -HostName 'host' -CompletedAtUtc ([DateTime]'2026-09-28T10:00:00Z') } | Should -Throw '*Completion evidence is partial*'
        Test-Path -LiteralPath (Join-Path (Join-Path $script:CaseRoot 'archive') $stage.BundleId) | Should -BeFalse
    }

    It 'keeps an explicit provider unknown distinct from partial as a blocking status' {
        $stage = New-TestStage -Root $script:CaseRoot
        $evidence = New-TestCmEvidence -Specs $stage.Specs
        $evidence.Distribution[0].State = 'unknown'
        $status = Get-VsClientCompletionEvidenceStatus -Specs $stage.Specs -CmDefinitions $evidence.Definitions -ExpectedDistributionPoints $evidence.Targets -DistributionEvidence $evidence.Distribution
        $status.Status | Should -Be 'unknown'
    }

    It 'rejects a definition whose reverse dependency evidence is incomplete' {
        $stage = New-TestStage -Root $script:CaseRoot
        $evidence = New-TestCmEvidence -Specs $stage.Specs
        $evidence.Definitions[0].RequiredBy = @()
        $status = Get-VsClientCompletionEvidenceStatus -Specs $stage.Specs -CmDefinitions $evidence.Definitions -ExpectedDistributionPoints $evidence.Targets -DistributionEvidence $evidence.Distribution
        $status.Status | Should -Be 'partial'
        ($status.Findings -join '|') | Should -Match 'reverse-dependency'
    }

    It 'blocks a missing or corrupt rollback predecessor' {
        $stage = New-TestStage -Root $script:CaseRoot
        $evidence = New-TestCmEvidence -Specs $stage.Specs
        $predecessor = [pscustomobject]@{ BundleId = ('c' * 64); ArchivePath = (Join-Path $script:CaseRoot 'missing'); ReceiptHash = ('d' * 64); ManifestFileHash = ('e' * 64) }
        { Complete-VsClientBundleArchive -StageEvidence $stage -ArchiveRoot (Join-Path $script:CaseRoot 'archive') -CmDefinitions $evidence.Definitions -ExpectedDistributionPoints $evidence.Targets -DistributionEvidence $evidence.Distribution -PredecessorEvidence $predecessor -Actor 'tester' -HostName 'host' -CompletedAtUtc ([DateTime]'2026-09-28T10:00:00Z') } | Should -Throw '*Predecessor evidence is partial*'

        $predecessor = New-TestPredecessor -Root (Join-Path $script:CaseRoot 'previous') -Stage $stage
        Add-Content -LiteralPath (Join-Path $predecessor.ArchivePath 'client_staticip/client_staticip.ps1') -Value '# corrupt'
        (Test-VsClientPredecessorEvidence -Evidence $predecessor).Status | Should -Be 'partial'
    }

    It 'writes receipt last without BOM and never overwrites an immutable successful archive' {
        $stage = New-TestStage -Root $script:CaseRoot
        $predecessor = New-TestPredecessor -Root (Join-Path $script:CaseRoot 'previous') -Stage $stage
        $evidence = New-TestCmEvidence -Specs $stage.Specs
        $archiveRoot = Join-Path $script:CaseRoot 'archive'
        $result = Complete-VsClientBundleArchive -StageEvidence $stage -ArchiveRoot $archiveRoot -CmDefinitions $evidence.Definitions -ExpectedDistributionPoints $evidence.Targets -DistributionEvidence $evidence.Distribution -PredecessorEvidence $predecessor -Actor 'tester' -HostName 'host' -CompletedAtUtc ([DateTime]'2026-09-28T10:00:00Z')
        $result.Status | Should -Be 'success'
        $receiptPath = Join-Path $result.ArchivePath 'receipt.json'
        $bytes = [IO.File]::ReadAllBytes($receiptPath)
        ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) | Should -BeFalse
        $receipt = Get-Content -LiteralPath $receiptPath -Raw -Encoding UTF8 | ConvertFrom-Json
        @($receipt.CmDefinitions).Count | Should -Be 4
        $beforeHash = Get-VsClientSha256HexFromFile -Path $receiptPath
        { Complete-VsClientBundleArchive -StageEvidence $stage -ArchiveRoot $archiveRoot -CmDefinitions $evidence.Definitions -ExpectedDistributionPoints $evidence.Targets -DistributionEvidence $evidence.Distribution -PredecessorEvidence $predecessor -Actor 'different' -HostName 'host' -CompletedAtUtc ([DateTime]'2026-09-28T11:00:00Z') } | Should -Throw '*Immutable bundle archive already exists*'
        (Get-VsClientSha256HexFromFile -Path $receiptPath) | Should -BeExactly $beforeHash
    }
}

Describe 'MC03 client bundle retention plan' {
    BeforeEach {
        $script:RetentionRoot = Join-Path ([IO.Path]::GetTempPath()) ('vs-mc03-retention-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $script:RetentionRoot -Force | Out-Null
    }
    AfterEach { Remove-Item -LiteralPath $script:RetentionRoot -Recurse -Force -ErrorAction SilentlyContinue }

    It 'selects only a verified success with five newer successes and more than 180 full days' {
        $now = [DateTime]'2026-09-28T12:00:00Z'
        for ($index = 1; $index -le 5; $index++) { $null = New-TestRetentionReceipt -Root $script:RetentionRoot -Number $index -CompletedAtUtc $now.AddDays(-$index) }
        $boundary = New-TestRetentionReceipt -Root $script:RetentionRoot -Number 6 -CompletedAtUtc $now.AddDays(-180)
        $eligible = New-TestRetentionReceipt -Root $script:RetentionRoot -Number 7 -CompletedAtUtc $now.AddDays(-181)
        $corrupt = New-TestRetentionReceipt -Root $script:RetentionRoot -Number 8 -CompletedAtUtc $now.AddDays(-400) -Corrupt
        $plan = Get-VsClientBundleRetentionPlan -ArchiveRoot $script:RetentionRoot -NowUtc $now
        $plan.Status | Should -Be 'unknown'
        @($plan.Items | Where-Object Candidate).BundleId | Should -Contain $eligible
        @($plan.Items | Where-Object Candidate).BundleId | Should -Not -Contain $boundary
        ($plan.Items | Where-Object BundleId -eq $boundary).Reason | Should -Be 'not-older-than-180-full-days'
        ($plan.Items | Where-Object BundleId -eq $corrupt).Status | Should -Be 'unknown'
    }

    It 'keeps an otherwise eligible predecessor explicitly protected and exposes no apply command' {
        $now = [DateTime]'2026-09-28T12:00:00Z'
        for ($index = 1; $index -le 5; $index++) { $null = New-TestRetentionReceipt -Root $script:RetentionRoot -Number $index -CompletedAtUtc $now.AddDays(-$index) }
        $protected = New-TestRetentionReceipt -Root $script:RetentionRoot -Number 9 -CompletedAtUtc $now.AddDays(-181)
        $plan = Get-VsClientBundleRetentionPlan -ArchiveRoot $script:RetentionRoot -NowUtc $now -ProtectedBundleIds @($protected)
        ($plan.Items | Where-Object BundleId -eq $protected).Candidate | Should -BeFalse
        ($plan.Items | Where-Object BundleId -eq $protected).Reason | Should -Be 'explicitly-protected'
        Get-Command -Name Remove-VsClientBundle*, Invoke-VsClientBundleRetention* -ErrorAction SilentlyContinue | Should -BeNullOrEmpty
    }
}
