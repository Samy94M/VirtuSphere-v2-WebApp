BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:PsRoot = Join-Path $script:RepoRoot 'Powershell-MECM'
    $script:MecmCommon = Join-Path (Join-Path $script:PsRoot 'mecm') 'VirtuSphere-Common.ps1'
    $script:Installer = Join-Path $script:PsRoot 'install-VirtuSphere-MECM.ps1'
    $script:Importer = Join-Path (Join-Path $script:PsRoot 'mecm') 'mecm_autoimporter.ps1'

    function Invoke-WithMecmCommon {
        param([scriptblock]$Body, [object[]]$Arguments = @())
        & {
            param($common, $body, $arguments)
            . $common
            & $body @arguments
        } $script:MecmCommon $Body $Arguments
    }

    function New-ReporterTemplateFixture {
        param([Parameter(Mandatory)][string]$Root, [Parameter(Mandatory)][string]$Label)
        New-Item -ItemType Directory -Path $Root -Force | Out-Null
        $wrapper = Join-Path $Root 'install.ps1'
        [IO.File]::WriteAllText($wrapper, ('wrapper-' + $Label), (New-Object Text.UTF8Encoding($false)))
        $sourceBytes = [Text.Encoding]::UTF8.GetBytes('reporter-' + $Label)
        $sha = [Security.Cryptography.SHA256]::Create()
        try { $fileHash = ([BitConverter]::ToString($sha.ComputeHash($sourceBytes)).Replace('-', '')).ToLowerInvariant() } finally { $sha.Dispose() }
        $basis = 'Reporter.ps1|{0}|{1}' -f $sourceBytes.Length, $fileHash
        $sha = [Security.Cryptography.SHA256]::Create()
        try { $bundleId = ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($basis))).Replace('-', '')).ToLowerInvariant() } finally { $sha.Dispose() }
        $reporting = Join-Path $Root 'reporting'
        $bundle = Join-Path $reporting $bundleId
        New-Item -ItemType Directory -Path $bundle -Force | Out-Null
        [IO.File]::WriteAllBytes((Join-Path $bundle 'Reporter.ps1'), $sourceBytes)
        $contracts = [ordered]@{ common = 1; logging = 1; adapter = 1; host = 1 }
        $manifest = [ordered]@{
            schema_version = 1; bundle_id = $bundleId; contracts = $contracts
            files = @([ordered]@{ path = 'Reporter.ps1'; length = $sourceBytes.Length; sha256 = $fileHash })
        }
        [IO.File]::WriteAllText((Join-Path $bundle 'manifest.json'), (($manifest | ConvertTo-Json -Depth 5) + "`n"), (New-Object Text.UTF8Encoding($false)))
        $wrapperHash = (Get-FileHash -LiteralPath $wrapper -Algorithm SHA256).Hash.ToLowerInvariant()
        $descriptor = [ordered]@{ schema_version = 1; bundle_id = $bundleId; wrapper_sha256 = $wrapperHash; contracts = $contracts }
        [IO.File]::WriteAllText((Join-Path $reporting 'current.json'), (($descriptor | ConvertTo-Json -Depth 4) + "`n"), (New-Object Text.UTF8Encoding($false)))
        return $bundleId
    }
}

Describe 'T3 package reporter distribution contract' {
    It 'binds the generated bundle to the four canonical client sources and switches the descriptor last' {
        $installer = Get-Content -LiteralPath $script:Installer -Raw
        foreach ($name in @(
            'VirtuSphere-Client-Common.ps1',
            'VirtuSphere-Client-Logging.ps1',
            'VirtuSphere-Package-Reporter.ps1',
            'VirtuSphere-Package-ReporterHost.ps1'
        )) { $installer | Should -Match ([regex]::Escape($name)) }
        $installer | Should -Match '\$bundleBasis'
        $installer | Should -Match '\$bundleId'
        $installer | Should -Match 'VsPackageReporterExpectedCommonContractVersion'
        $installer | Should -Match 'VsPackageReporterExpectedLoggingContractVersion'
        $installer | Should -Match 'VsPackageReporterHostExpectedAdapterContractVersion'
        $installer | Should -Match 'Reporterbundle-Vertragsversionen sind untereinander oder mit Schema V1 nicht kompatibel'
        $importer = Get-Content -LiteralPath $script:Importer -Raw
        $importer | Should -Match 'Get-VsFilesManifestStamp -Path \$basePath -TemplatePath \$templatePath'
        $common = Get-Content -LiteralPath $script:MecmCommon -Raw
        $bundleMove = $common.IndexOf('Move-Item -LiteralPath $stageBundle -Destination $targetBundle')
        $wrapperCopy = $common.IndexOf('Copy-Item -LiteralPath $wrapperSource -Destination $wrapperTarget')
        $descriptorSwitch = $common.IndexOf('[IO.File]::Replace($descriptorTemp, $descriptorTarget')
        $bundleMove | Should -BeGreaterThan -1
        $wrapperCopy | Should -BeGreaterThan $bundleMove
        $descriptorSwitch | Should -BeGreaterThan $wrapperCopy
    }

    It 'publishes the complete set without changing package config or payload and retains the prior generation' {
        $templateOne = Join-Path $TestDrive 'template-one'
        $templateTwo = Join-Path $TestDrive 'template-two'
        $package = Join-Path $TestDrive 'package'
        $firstId = New-ReporterTemplateFixture -Root $templateOne -Label 'one'
        $secondId = New-ReporterTemplateFixture -Root $templateTwo -Label 'two'
        New-Item -ItemType Directory -Path (Join-Path $package 'powershell') -Force | Out-Null
        Set-Content -LiteralPath (Join-Path $package 'config.json') -Value '{"ProjectName":"Keep"}' -Encoding UTF8
        Set-Content -LiteralPath (Join-Path $package 'powershell\01.keep.ps1') -Value 'keep-payload' -Encoding UTF8

        Invoke-WithMecmCommon -Arguments @($templateOne, $package) -Body {
            param($template, $target) Sync-VsPackageTemplateSet -TemplateRoot $template -PackageRoot $target
        }
        Invoke-WithMecmCommon -Arguments @($templateTwo, $package) -Body {
            param($template, $target) Sync-VsPackageTemplateSet -TemplateRoot $template -PackageRoot $target
        }

        (Get-Content -LiteralPath (Join-Path $package 'config.json') -Raw) | Should -Match 'Keep'
        (Get-Content -LiteralPath (Join-Path $package 'powershell\01.keep.ps1') -Raw).Trim() | Should -Be 'keep-payload'
        Test-Path -LiteralPath (Join-Path $package ('reporting\{0}' -f $firstId)) -PathType Container | Should -BeTrue
        Test-Path -LiteralPath (Join-Path $package ('reporting\{0}' -f $secondId)) -PathType Container | Should -BeTrue
        $current = Get-Content -LiteralPath (Join-Path $package 'reporting\current.json') -Raw | ConvertFrom-Json
        $current.bundle_id | Should -Be $secondId
        Invoke-WithMecmCommon -Arguments @($templateTwo, $package) -Body {
            param($template, $target) Test-VsPackageTemplateSetCurrent -TemplateRoot $template -PackageRoot $target
        } | Should -BeTrue
    }

    It 'rejects a damaged existing content-addressed generation before switching wrapper or descriptor' {
        $templateOne = Join-Path $TestDrive 'damage-template-one'
        $templateTwo = Join-Path $TestDrive 'damage-template-two'
        $package = Join-Path $TestDrive 'damage-package'
        $firstId = New-ReporterTemplateFixture -Root $templateOne -Label 'old'
        $secondId = New-ReporterTemplateFixture -Root $templateTwo -Label 'new'
        New-Item -ItemType Directory -Path $package -Force | Out-Null
        Invoke-WithMecmCommon -Arguments @($templateOne, $package) -Body {
            param($template, $target) Sync-VsPackageTemplateSet -TemplateRoot $template -PackageRoot $target
        }
        New-Item -ItemType Directory -Path (Join-Path $package ('reporting\{0}' -f $secondId)) -Force | Out-Null
        Set-Content -LiteralPath (Join-Path $package ('reporting\{0}\Reporter.ps1' -f $secondId)) -Value 'corrupt' -Encoding UTF8
        $beforeWrapper = (Get-FileHash -LiteralPath (Join-Path $package 'install.ps1') -Algorithm SHA256).Hash
        $beforeDescriptor = (Get-FileHash -LiteralPath (Join-Path $package 'reporting\current.json') -Algorithm SHA256).Hash

        { Invoke-WithMecmCommon -Arguments @($templateTwo, $package) -Body {
            param($template, $target) Sync-VsPackageTemplateSet -TemplateRoot $template -PackageRoot $target
        } } | Should -Throw
        (Get-FileHash -LiteralPath (Join-Path $package 'install.ps1') -Algorithm SHA256).Hash | Should -Be $beforeWrapper
        (Get-FileHash -LiteralPath (Join-Path $package 'reporting\current.json') -Algorithm SHA256).Hash | Should -Be $beforeDescriptor
        (Get-Content -LiteralPath (Join-Path $package 'reporting\current.json') -Raw | ConvertFrom-Json).bundle_id | Should -Be $firstId
    }

    It 'changes the scan stamp when only a nested reporter file changes' {
        $files = Join-Path $TestDrive 'stamp-files'
        $template = Join-Path $TestDrive 'stamp-template'
        New-Item -ItemType Directory -Path (Join-Path $files 'Pkg') -Force | Out-Null
        Set-Content -LiteralPath (Join-Path $files 'Pkg\payload.bin') -Value 'payload' -Encoding UTF8
        $bundleId = New-ReporterTemplateFixture -Root $template -Label 'stamp'
        $before = Invoke-WithMecmCommon -Arguments @($files, $template) -Body {
            param($root, $templateRoot) Get-VsFilesManifestStamp -Path $root -TemplatePath $templateRoot
        }
        Set-Content -LiteralPath (Join-Path $template ('reporting\{0}\Reporter.ps1' -f $bundleId)) -Value 'changed-only-reporter' -Encoding UTF8
        $after = Invoke-WithMecmCommon -Arguments @($files, $template) -Body {
            param($root, $templateRoot) Get-VsFilesManifestStamp -Path $root -TemplatePath $templateRoot
        }
        $after | Should -Not -Be $before
    }
}
