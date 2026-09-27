BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:MecmRoot = Join-Path $script:RepoRoot 'Powershell-MECM/mecm'
    . (Join-Path $script:MecmRoot 'VirtuSphere-ClientPackaging.ps1')
    . (Join-Path $script:MecmRoot 'VirtuSphere-ClientPreflight.ps1')
    $script:InstallerSource = Get-Content -LiteralPath (Join-Path $script:RepoRoot 'Powershell-MECM/install-VirtuSphere-Clients.ps1') -Raw

    # Pester mocks stand in for the CM provider; no site connection is used.
    function Get-CMApplication { param($Name, [switch]$ShowHidden, [switch]$DisableWildcardHandling, $ErrorAction) }
    function Get-CMApplicationDeployment { param($Name, $ErrorAction) }
    function Get-CMApplicationGroup { param([switch]$ShowHidden, $ErrorAction) }
    function Get-CMDeploymentType { param($ApplicationName, $ErrorAction) }
    function Get-CMDeploymentTypeSupersedence { param($InputObject, $ErrorAction) }
    function Get-CMDeviceCollection { param($CollectionId, $ErrorAction) }
    function Get-CMDistributionPointGroup { param($Name, $ErrorAction) }
    function Get-CMDistributionPoint { param($DistributionPointGroup, $ErrorAction) }
    function New-CMApplication { throw 'write was called' }
    function Copy-VsClientContent { throw 'write was called' }
}

Describe 'MC02 read-only client preflight inventory' {
    It 'runs the same report before the old content and CM write paths for both modes' {
        $script:InstallerSource | Should -Match 'param\(\[switch\]\$ValidateOnly\)'
        $script:InstallerSource | Should -Not -Match 'SkipPreflight'
        $preflightAt = $script:InstallerSource.IndexOf('$preflight = Get-VsClientPreflightReport')
        $contentAt = $script:InstallerSource.IndexOf('$stagedContent = @{}')
        $cmWriteAt = $script:InstallerSource.IndexOf('New-CMFolder')
        $preflightAt | Should -BeGreaterThan 0
        $contentAt | Should -BeGreaterThan $preflightAt
        $cmWriteAt | Should -BeGreaterThan $preflightAt
        $script:InstallerSource | Should -Match 'if \(-not \$preflight.CanApply\) \{\s+throw'
    }

    BeforeEach {
        $script:sourceRoot = Join-Path $TestDrive 'source'
        $script:shareRoot = Join-Path $TestDrive 'share'
        New-Item -ItemType Directory -Path $script:sourceRoot, $script:shareRoot -Force | Out-Null
        $script:identity = '0123456789abcdef' * 2
        [IO.File]::WriteAllText((Join-Path $script:sourceRoot '.virtusphere-source-identity'), $script:identity)
        [IO.File]::WriteAllText((Join-Path $script:shareRoot '.virtusphere-source-identity'), $script:identity)
        $script:config = @{
            PackagesBase = $script:sourceRoot
            ContentShare = $script:shareRoot
            SourceIdentity = $script:identity
            CoreLimitingCollectionId = 'PS100012'
            DpGroupName = 'Test DP'
        }
        Mock Get-CMDeviceCollection { [pscustomobject]@{ CollectionID = 'PS100012' } }
        Mock Get-CMDistributionPointGroup { [pscustomobject]@{ GroupID = 7 } }
        Mock Get-CMDistributionPoint { [pscustomobject]@{ SiteSystemServerName = 'dp01.test' } }
        Mock Get-CMApplication {
            if ($Name -eq 'client_getinfo' -or $Name -eq 'client_getinfo_2.1') {
                $ciId = if ($Name -eq 'client_getinfo') { 42 } else { 43 }
                [pscustomobject]@{ LocalizedDisplayName = $Name; ModelName = "legacy/$Name"; CI_ID = $ciId }
            }
        }
        Mock Get-CMDeploymentType {
            if ($ApplicationName -eq 'client_getinfo') { [pscustomobject]@{ CI_ID = 200 } }
        }
        Mock Get-CMDeploymentTypeSupersedence { [pscustomobject]@{ CI_ID = 201 } }
        Mock Get-CMApplicationGroup { [pscustomobject]@{ LocalizedDisplayName = 'Other group'; ModelName = 'group/1'; CI_ID = 300 } }
        Mock Get-CimInstance {
            [pscustomobject]@{ PackageID = 'PS100099'; RefAppModelName = 'legacy/client_getinfo'; RefAppCI_ID = 42 }
        }
        Mock Get-CMApplicationDeployment {
            if ($Name -eq 'client_getinfo') { [pscustomobject]@{ AssignmentID = 99; CollectionID = 'PS100013' } }
        }
        Mock New-CMApplication { throw 'write was called' }
        Mock Copy-VsClientContent { throw 'write was called' }
    }

    It 'reads markers and content from a FileSystem location while a non-FileSystem drive is active' {
        # Initialize-VsCmSite leaves the session on the CM site drive; UNC paths
        # only resolve from a FileSystem location. HKCU stands in for that drive.
        foreach ($spec in @(Get-VsClientAppSpecs)) {
            New-Item -ItemType Directory -Path (Join-Path $script:sourceRoot $spec.Folder), (Join-Path $script:shareRoot $spec.Folder) -Force | Out-Null
        }
        $script:manifestProviders = @()
        Mock Compare-VsClientContentManifest { $script:manifestProviders += (Get-Location).Provider.Name; @() }
        Push-Location -LiteralPath 'HKCU:\'
        try {
            $report = Get-VsClientPreflightReport -Config $script:config -Specs @(Get-VsClientAppSpecs) -ClientSourceDir $TestDrive -SiteCode 'PS1'
            (Get-Location).Provider.Name | Should -Be 'Registry'
        } finally {
            Pop-Location
        }
        $script:manifestProviders | Should -Be @('FileSystem', 'FileSystem', 'FileSystem', 'FileSystem')
        @($report.Findings | Where-Object { $_.Code -in @('source_identity_unknown', 'content_inventory_unknown') }).Count | Should -Be 0
        @($report.Inventory | Where-Object { $_.Kind -eq 'source' }).Count | Should -Be 2
    }

    It 'reports both legacy objects and blocks apply without a write' {
        $report = Get-VsClientPreflightReport -Config $script:config -Specs @(Get-VsClientAppSpecs) -ClientSourceDir $TestDrive -SiteCode 'PS1'
        $report.CanApply | Should -BeFalse
        @($report.Inventory | Where-Object Kind -eq 'legacy-application').Count | Should -Be 2
        @($report.Inventory | Where-Object { $_.Kind -eq 'application-deployment' -and $_.Name -eq 'client_getinfo' }).Count | Should -Be 1
        @($report.Inventory | Where-Object { $_.Kind -eq 'supersedence-forward' -and $_.SourceId -eq '200' -and $_.TargetId -eq '201' }).Count | Should -Be 1
        @($report.Inventory | Where-Object { $_.Kind -eq 'application-group' -and $_.Identity -eq 'group/1' }).Count | Should -Be 1
        @($report.Inventory | Where-Object { $_.Kind -eq 'task-sequence-reference' -and $_.Identity -eq 'PS100099' -and $_.Name -eq 'client_getinfo' }).Count | Should -Be 1
        @($report.Inventory | Where-Object { $_.Kind -eq 'distribution-point' -and $_.Identity -eq 'dp01.test' }).Count | Should -Be 1
        @($report.Findings | Where-Object { $_.Code -eq 'legacy_deployment_present' -and $_.Target -eq 'client_getinfo' }).Count | Should -Be 1
        @($report.Findings | Where-Object Code -eq 'references_unverified').Count | Should -Be 1
        $report.PlanId | Should -Match '^[0-9a-f]{64}$'
        Should -Invoke New-CMApplication -Times 0
        Should -Invoke Copy-VsClientContent -Times 0
    }

    It 'marks a missing UNC marker as blocking and never infers path identity from matching content' {
        Remove-Item -LiteralPath (Join-Path $script:shareRoot '.virtusphere-source-identity')
        $report = Get-VsClientPreflightReport -Config $script:config -Specs @(Get-VsClientAppSpecs) -ClientSourceDir $TestDrive -SiteCode 'PS1'
        @($report.Findings | Where-Object { $_.Code -eq 'source_identity_unknown' -and $_.Target -eq 'unc' }).Count | Should -Be 1
        @($report.Findings | Where-Object Code -eq 'source_mapping_unverified').Count | Should -Be 1
    }

    It 'reports provider failure as unknown rather than zero task-sequence references' {
        Mock Get-CimInstance { throw 'provider unavailable' }
        $report = Get-VsClientPreflightReport -Config $script:config -Specs @(Get-VsClientAppSpecs) -ClientSourceDir $TestDrive -SiteCode 'PS1'
        @($report.Findings | Where-Object Code -eq 'task_sequence_references_unknown').Count | Should -Be 1
        @($report.Inventory | Where-Object Kind -eq 'task-sequence-reference-scan').Count | Should -Be 0
        $report.CanApply | Should -BeFalse
    }

    It 'reports a core deployment with outside-window drift as a blocker' {
        Mock Get-CMApplicationDeployment {
            if ($Name -eq 'client_staticip') {
                [pscustomobject]@{
                    AssignmentID = 100; TargetCollectionID = 'PS100022'; OfferTypeID = 0
                    OverrideServiceWindows = $true; RebootOutsideOfServiceWindows = $false
                    Enabled = $true; EnforcementDeadline = '2026-09-27T12:00:00Z'
                }
            }
        }
        $report = Get-VsClientPreflightReport -Config $script:config -Specs @(Get-VsClientAppSpecs) -ClientSourceDir $TestDrive -SiteCode 'PS1'
        @($report.Findings | Where-Object Code -eq 'core_deployment_policy_drift').Count | Should -Be 1
        @($report.Inventory | Where-Object { $_.Kind -eq 'application-deployment' -and $_.Name -eq 'client_staticip' -and $_.Collection -eq 'PS100022' }).Count | Should -Be 1
    }

    It 'does not treat an empty visible DP group as distributed' {
        Mock Get-CMDistributionPoint { }
        $report = Get-VsClientPreflightReport -Config $script:config -Specs @(Get-VsClientAppSpecs) -ClientSourceDir $TestDrive -SiteCode 'PS1'
        @($report.Findings | Where-Object Code -eq 'dp_group_unknown').Count | Should -Be 1
        @($report.Inventory | Where-Object Kind -eq 'distribution-point').Count | Should -Be 0
    }
}
