BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:ServerInstaller = Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'install-VirtuSphere-MECM.ps1'
    $tokens = $null
    $parseErrors = $null
    $installerAst = [System.Management.Automation.Language.Parser]::ParseFile($script:ServerInstaller, [ref]$tokens, [ref]$parseErrors)
    $script:InstallerParseErrors = @($parseErrors)

    $stageTry = @($installerAst.FindAll({
        param($node)
        $node -is [System.Management.Automation.Language.TryStatementAst] -and
            $node.Body.Extent.Text -match '\$templateSources' -and
            $node.Body.Extent.Text -match '\$stagedExpectedVersion'
    }, $true) | Sort-Object { $_.Extent.Text.Length } | Select-Object -First 1)[0]
    $stageStatements = if ($stageTry) { @($stageTry.Body.Statements) } else { @() }
    $stageStart = -1
    $stageEnd = -1
    for ($i = 0; $i -lt $stageStatements.Count; $i++) {
        if ($stageStart -lt 0 -and $stageStatements[$i].Extent.Text -match 'New-Item\s+-ItemType\s+Directory\s+-Path\s+\$templateStage') { $stageStart = $i }
        if ($stageStatements[$i].Extent.Text -match '^\s*\$stagedExpectedVersion\s*=') { $stageEnd = $i; break }
    }
    $stageSource = if ($stageStart -ge 0 -and $stageEnd -gt $stageStart) {
        ($stageStatements[$stageStart..($stageEnd - 1)] | ForEach-Object { $_.Extent.Text }) -join "`n"
    } else { '' }
    $script:TemplateStageBlock = if ($stageSource) { [scriptblock]::Create($stageSource) } else { $null }
    $script:TemplateStageSource = $stageSource

    $activationTry = @($installerAst.FindAll({
        param($node)
        $node -is [System.Management.Automation.Language.TryStatementAst] -and
            $node.Body.Extent.Text -match 'Move-Item\s+-LiteralPath\s+\$templateStage\s+-Destination\s+\$templateDest' -and
            $node.Body.Extent.Text -match '\$liveTemplateDirectories'
    }, $true) | Sort-Object { $_.Extent.Text.Length } | Select-Object -First 1)[0]
    $activationStatements = if ($activationTry) { @($activationTry.Body.Statements) } else { @() }
    $activationStart = -1
    for ($i = 0; $i -lt $activationStatements.Count; $i++) {
        if ($activationStatements[$i].Extent.Text -match '^\s*\$templateBackup\s*=') { $activationStart = $i; break }
    }
    $activationSource = if ($activationStart -ge 0 -and $activationStatements.Count -gt 0) {
        ($activationStatements[$activationStart..($activationStatements.Count - 1)] | ForEach-Object { $_.Extent.Text }) -join "`n"
    } else { '' }
    $script:TemplateActivationBlock = if ($activationSource) { [scriptblock]::Create($activationSource) } else { $null }
    $script:TemplateActivationSource = $activationSource

    $restoreFunction = $installerAst.Find(
        { param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Restore-VsInstallTransaction' },
        $true)
    $script:RestoreFunction = $restoreFunction
    if ($restoreFunction) { . ([scriptblock]::Create($restoreFunction.Extent.Text)) }

    function New-TemplateTree {
        param([Parameter(Mandatory)][string]$Root, [Parameter(Mandatory)][string]$Label)
        [void][IO.Directory]::CreateDirectory($Root)
        [void][IO.Directory]::CreateDirectory((Join-Path $Root 'nested\empty'))
        [void][IO.Directory]::CreateDirectory((Join-Path $Root 'nested\payload'))
        [IO.File]::WriteAllText((Join-Path $Root 'install.ps1'), ('install-' + $Label), (New-Object Text.UTF8Encoding($false)))
        [IO.File]::WriteAllText((Join-Path $Root 'config.json'), ('{"label":"' + $Label + '"}'), (New-Object Text.UTF8Encoding($false)))
        [IO.File]::WriteAllText((Join-Path $Root 'extra.txt'), ('extra-' + $Label), (New-Object Text.UTF8Encoding($false)))
        [IO.File]::WriteAllText((Join-Path $Root 'nested\payload\data.bin'), ('nested-' + $Label), (New-Object Text.UTF8Encoding($false)))
        [IO.File]::WriteAllText((Join-Path $Root 'nested\.gitkeep'), 'excluded', (New-Object Text.UTF8Encoding($false)))
    }

    function Get-TemplateTreeSnapshot {
        param([Parameter(Mandatory)][string]$Root)
        if (-not (Test-Path -LiteralPath $Root)) { return @('<missing>') }
        $rootPath = (Get-Item -LiteralPath $Root -Force).FullName.TrimEnd('\')
        return @(Get-ChildItem -LiteralPath $Root -Recurse -Force | Sort-Object FullName | ForEach-Object {
            $relative = $_.FullName.Substring($rootPath.Length).TrimStart('\')
            if ($_.PSIsContainer) {
                'D:' + $relative
            } else {
                'F:' + $relative + ':' + [Convert]::ToBase64String([IO.File]::ReadAllBytes($_.FullName))
            }
        })
    }
}

Describe 'Server installer Package_Vorlage transaction (U09)' {
    It 'extracts the actual stage, activation and rollback owners without executing the installer' {
        $script:InstallerParseErrors.Count | Should -Be 0
        $script:TemplateStageBlock | Should -Not -BeNullOrEmpty
        $script:TemplateActivationBlock | Should -Not -BeNullOrEmpty
        $script:RestoreFunction | Should -Not -BeNullOrEmpty
        $script:TemplateStageSource | Should -Match 'Get-FileHash -Algorithm SHA256 -LiteralPath \$source\.FullName'
        $script:TemplateStageSource | Should -Not -Match '\$templateDest|ScheduledTask'
        $script:TemplateActivationSource | Should -Match 'Move-Item -LiteralPath \$templateDest -Destination \$templateBackup'
        $script:TemplateActivationSource | Should -Match 'Move-Item -LiteralPath \$templateStage -Destination \$templateDest'
    }

    It 'leaves the live tree exact and untouched when staging copy or hash verification fails' -TestCases @(
        @{ Fault = 'copy' }
        @{ Fault = 'hash' }
    ) {
        param($Fault)
        $caseRoot = Join-Path $TestDrive ('stage-fault-' + $Fault)
        $templateSource = Join-Path $caseRoot 'source'
        $templateStage = Join-Path $caseRoot 'stage'
        $templateDest = Join-Path $caseRoot 'live'
        New-TemplateTree -Root $templateSource -Label 'new'
        New-TemplateTree -Root $templateDest -Label 'old'
        $before = @(Get-TemplateTreeSnapshot -Root $templateDest)

        if ($Fault -eq 'copy') {
            Mock Copy-Item { throw 'injected template copy fault' }
        } else {
            $script:FaultTemplateStage = $templateStage
            Mock Get-FileHash {
                param($Algorithm, $LiteralPath, $ErrorAction)
                $hash = if ([string]$LiteralPath -like ($script:FaultTemplateStage + '*')) { 'STAGED-MISMATCH' } else { 'SOURCE-HASH' }
                [pscustomobject]@{ Hash = $hash }
            }
        }

        { . $script:TemplateStageBlock } | Should -Throw
        @(Get-TemplateTreeSnapshot -Root $templateDest) | Should -Be $before
        Test-Path -LiteralPath (Join-Path $templateDest 'install.ps1') | Should -BeTrue
    }

    It 'activates the complete verified tree over a fresh empty target including extra and nested files' {
        $caseRoot = Join-Path $TestDrive 'fresh-activation'
        $PackagesRoot = Join-Path $caseRoot 'packages'
        $templateSource = Join-Path $caseRoot 'source'
        $templateStage = Join-Path $PackagesRoot '.template-stage'
        $templateDest = Join-Path $PackagesRoot 'Package_Vorlage'
        [void][IO.Directory]::CreateDirectory($templateDest)
        New-TemplateTree -Root $templateSource -Label 'new'

        . $script:TemplateStageBlock
        $verifiedStage = @(Get-TemplateTreeSnapshot -Root $templateStage)
        . $script:TemplateActivationBlock

        @(Get-TemplateTreeSnapshot -Root $templateDest) | Should -Be $verifiedStage
        Test-Path -LiteralPath (Join-Path $templateDest 'extra.txt') -PathType Leaf | Should -BeTrue
        Test-Path -LiteralPath (Join-Path $templateDest 'nested\payload\data.bin') -PathType Leaf | Should -BeTrue
        Test-Path -LiteralPath (Join-Path $templateDest 'nested\empty') -PathType Container | Should -BeTrue
        Test-Path -LiteralPath (Join-Path $templateDest 'nested\.gitkeep') | Should -BeFalse
        $templateActivationStarted | Should -BeTrue
        Test-Path -LiteralPath $templateBackup -PathType Container | Should -BeTrue
        @(Get-TemplateTreeSnapshot -Root $templateBackup).Count | Should -Be 0
    }

    It 'restores the exact old tree after activation before restarting the old task' {
        $caseRoot = Join-Path $TestDrive 'rollback-after-activation'
        $PackagesRoot = Join-Path $caseRoot 'packages'
        $templateSource = Join-Path $caseRoot 'source'
        $templateStage = Join-Path $PackagesRoot '.template-stage'
        $templateDest = Join-Path $PackagesRoot 'Package_Vorlage'
        New-TemplateTree -Root $templateSource -Label 'new'
        New-TemplateTree -Root $templateDest -Label 'old'
        [IO.File]::WriteAllText((Join-Path $templateDest 'old-only.txt'), 'must-return', (New-Object Text.UTF8Encoding($false)))
        $oldTree = @(Get-TemplateTreeSnapshot -Root $templateDest)

        . $script:TemplateStageBlock
        $script:installDir = Join-Path $caseRoot 'server-live'
        $script:registryPath = Join-Path $caseRoot 'registry-does-not-exist'
        [void][IO.Directory]::CreateDirectory($script:installDir)
        $activatedFiles = New-Object System.Collections.Generic.List[string]
        $activatedServerFile = 'new-server-file.ps1'
        [IO.File]::WriteAllText((Join-Path $script:installDir $activatedServerFile), 'new-server-content', (New-Object Text.UTF8Encoding($false)))
        [void]$activatedFiles.Add($activatedServerFile)
        $taskSnapshots = @([pscustomobject]@{
            Name = 'VirtuSphere MECM Devices Sync'
            Script = 'mecm_new-device-sync.ps1'
            Existed = $true
            Xml = '<Task />'
            WasRunning = $true
        })
        $script:RollbackEvents = New-Object System.Collections.Generic.List[string]
        $script:TreeSeenAtTaskStart = @()
        $script:RollbackTemplateDestination = $templateDest
        Mock Get-ScheduledTask { $null }
        Mock Register-ScheduledTask { [void]$script:RollbackEvents.Add('register') }
        Mock Start-ScheduledTask {
            [void]$script:RollbackEvents.Add('start')
            $script:TreeSeenAtTaskStart = @(Get-TemplateTreeSnapshot -Root $script:RollbackTemplateDestination)
        }

        $treeAfterActivation = @()
        $faultMessage = $null
        $rollbackErrors = @()
        try {
            . $script:TemplateActivationBlock
            $treeAfterActivation = @(Get-TemplateTreeSnapshot -Root $templateDest)
            throw 'injected fault after template activation'
        } catch {
            $faultMessage = $_.Exception.Message
            $rollbackErrors = @(Restore-VsInstallTransaction `
                -RegistrySnapshot ([pscustomobject]@{ Existed = $false; Values = @(); Acl = $null }) `
                -TaskSnapshots $taskSnapshots -BackupPath $null -ActivatedFiles $activatedFiles `
                -TemplateBackupPath $templateBackup -TemplateDestination $templateDest -TemplateActivationStarted $templateActivationStarted)
        }

        $faultMessage | Should -Be 'injected fault after template activation'
        $treeAfterActivation | Should -Not -Be $oldTree
        $rollbackErrors.Count | Should -Be 0
        @(Get-TemplateTreeSnapshot -Root $templateDest) | Should -Be $oldTree
        $script:TreeSeenAtTaskStart | Should -Be $oldTree
        @($script:RollbackEvents) | Should -Be @('register', 'start')
        Test-Path -LiteralPath $templateBackup | Should -BeFalse
        Test-Path -LiteralPath (Join-Path $script:installDir $activatedServerFile) | Should -BeFalse
        Test-Path -LiteralPath (Join-Path $templateDest 'old-only.txt') -PathType Leaf | Should -BeTrue
        Test-Path -LiteralPath (Join-Path $templateDest 'extra.txt') -PathType Leaf | Should -BeTrue
    }
}
