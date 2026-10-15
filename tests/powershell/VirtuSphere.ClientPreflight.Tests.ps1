BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:MecmRoot = Join-Path $script:RepoRoot 'Powershell-MECM/mecm'
    . (Join-Path $script:MecmRoot 'VirtuSphere-ClientPackaging.ps1')
    . (Join-Path $script:MecmRoot 'VirtuSphere-ClientPreflight.ps1')
    $script:InstallerSource = Get-Content -LiteralPath (Join-Path $script:RepoRoot 'Powershell-MECM/install-VirtuSphere-Clients.ps1') -Raw
    $script:ClientSourceDir = Join-Path $script:RepoRoot 'Powershell-MECM/clients'
    $script:PlaceholderCodes = @('rbac_scope_unverified', 'source_mapping_unverified', 'references_unverified', 'deployment_policy_unverified')

    # Pester mocks stand in for the CM provider; no site connection is used.
    function Get-CMApplication { param($Name, [switch]$ShowHidden, [switch]$DisableWildcardHandling, [switch]$Fast, $ErrorAction) }
    function Get-CMApplicationDeployment { param($Name, $ErrorAction) }
    function Get-CMApplicationGroup { param([switch]$ShowHidden, $ErrorAction) }
    function Get-CMDeploymentType { param($ApplicationName, $ErrorAction) }
    function Get-CMDeploymentTypeSupersedence { param($InputObject, $ErrorAction) }
    function Get-CMDeploymentTypeReturnCode { param($InputObject, $ErrorAction) }
    function Get-CMDeploymentTypeDependencyGroup { param($InputObject, $ErrorAction) }
    function Get-CMDeploymentTypeDependency { param($InputObject, $ErrorAction) }
    function Get-CMDeviceCollection { param($CollectionId, $ErrorAction) }
    function Get-CMDistributionPointGroup { param($Name, $ErrorAction) }
    function Get-CMDistributionPoint { param($DistributionPointGroup, $ErrorAction) }
    function Get-SmbShare { param($ErrorAction) }
    function New-CMApplication { throw 'write was called' }
    function Copy-VsClientContent { throw 'write was called' }
    # The install command comes from VirtuSphere-Common.ps1, which these tests do not load.
    function Get-VsPowerShellCommandLine { param($ScriptPath) 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File {0}' -f $ScriptPath }

    # Evaluates the WQL conditions the preflight sends ("Prop = 'text'" or
    # "Prop = 123", joined by OR) against fixture rows, without case like WQL.
    function Select-TestCimRows {
        param([object[]]$Rows, [string]$Filter)
        if (-not $Filter) { return @($Rows | Where-Object { $null -ne $_ }) }
        $terms = @(foreach ($part in ($Filter -split ' OR ')) {
            if ($part -notmatch "\A(?<p>\w+) = (?:'(?<s>(?:[^'\\]|\\.)*)'|(?<n>[0-9]+))\z") { throw "fixture cannot evaluate filter: $Filter" }
            $value = if ($Matches.ContainsKey('n')) { $Matches['n'] } else { $Matches['s'] -replace '\\(.)', '$1' }
            [pscustomobject]@{ Property = $Matches['p']; Value = $value }
        })
        foreach ($row in @($Rows)) {
            if ($null -eq $row) { continue }
            foreach ($term in $terms) {
                if ($row.PSObject.Properties[$term.Property] -and [string]$row.($term.Property) -ieq $term.Value) { $row; break }
            }
        }
    }

    # Provider fixture: rows per class as the enumeration returns them, and the
    # lazy view per key (AdminID, CollectionID) for the explicit reload.
    function Use-TestCim {
        param([hashtable]$Rows, [hashtable]$Full = @{})
        $script:CimRows = $Rows
        $script:CimFull = $Full
        Mock Get-VsPreflightCim {
            if (-not $script:CimRows.ContainsKey($ClassName)) { throw ('fixture has no class {0}' -f $ClassName) }
            Select-TestCimRows -Rows @($script:CimRows[$ClassName]) -Filter $Filter
        }
        # The real reload binds -InputObject as CimInstance; it is mocked at its own seam.
        Mock Get-VsPreflightCimFull {
            foreach ($key in @('AdminID', 'CollectionID')) {
                if ($InputObject.PSObject.Properties[$key]) {
                    $id = '{0}={1}' -f $key, $InputObject.$key
                    if ($script:CimFull.ContainsKey($id)) { return $script:CimFull[$id] }
                }
            }
            $InputObject
        }
    }

    function Get-TestModel { param([string]$Suffix) 'ScopeId_0A0A0A0A-1111-2222-3333-444444444444/Application_{0}' -f $Suffix }

    function New-TestAssignment {
        param($Id, $App, $AppCi, $Collection, $CollectionName, [DateTime]$Deadline)
        [pscustomobject]@{
            AssignmentID = $Id; ApplicationName = $App; AssignedCIs = @($AppCi); AssignmentAction = 2; AssignmentType = 2
            TargetCollectionID = $Collection; CollectionName = $CollectionName
            OfferTypeID = 0; DesiredConfigType = 1; OverrideServiceWindows = $false; RebootOutsideOfServiceWindows = $false
            EnforcementDeadline = $Deadline; StartTime = $Deadline; SoftDeadlineEnabled = $false; OfferFlags = 0
            RequireApproval = $false; Enabled = $true; SuppressReboot = 0; UseGMTTimes = $true; NotifyUser = $true; UserUIExperience = $true
        }
    }

    function New-TestRevision {
        param($CiId, $Model, $Name, $Version, [bool]$Latest)
        [pscustomobject]@{ CI_ID = $CiId; CIVersion = $Version; IsLatest = $Latest; IsExpired = (-not $Latest); LocalizedDisplayName = $Name; ModelName = $Model; IsDeployed = $Latest; NumberOfDeployments = 0 }
    }

    function New-TestTypeRevision {
        param($CiId, $Model, $Name, $Version, [bool]$Latest)
        [pscustomobject]@{ CI_ID = $CiId; CIVersion = $Version; IsLatest = $Latest; IsExpired = (-not $Latest); LocalizedDisplayName = $Name; AppModelName = $Model }
    }

    function New-TestRelation {
        param($FromApp, $FromType, $ToApp, $ToType)
        [pscustomobject]@{ FromApplicationCIID = $FromApp; FromDeploymentTypeCIID = $FromType; ToApplicationCIID = $ToApp; ToDeploymentTypeCIID = $ToType; TypeFlag = 3 }
    }

    # SMS_Admin as the site returned it: names and AdminSid on enumeration,
    # Categories, Roles and Permissions only after the explicit reload.
    function New-TestAdmin {
        param($Id, $Sid, $Logon, [bool]$IsGroup, [string[]]$Scopes, [string[]]$ScopeNames, [string[]]$Roles)
        [pscustomobject]@{
            AccountType = $(if ($IsGroup) { 1 } else { 0 }); AdminID = $Id; AdminSid = $Sid; Categories = $null
            CategoryNames = $ScopeNames; CollectionNames = @('All Systems', 'All Users and User Groups')
            DisplayName = $Logon; IsGroup = $IsGroup; LogonName = $Logon; Permissions = $null; RoleNames = $Roles; Roles = $null
        }
    }

    function New-TestAdminFull {
        param($Admin, [string[]]$Scopes)
        $permissions = @(foreach ($scope in $Scopes) {
            [pscustomobject]@{ CategoryID = $scope; CategoryName = $scope; CategoryTypeID = 29; RoleID = 'SMS0001R'; RoleName = 'Full Administrator' }
        }) + @(
            [pscustomobject]@{ CategoryID = 'SMS00001'; CategoryName = 'All Systems'; CategoryTypeID = 1; RoleID = 'SMS0001R'; RoleName = 'Full Administrator' }
            [pscustomobject]@{ CategoryID = 'SMS00004'; CategoryName = 'All Users and User Groups'; CategoryTypeID = 1; RoleID = 'SMS0001R'; RoleName = 'Full Administrator' }
        )
        $full = $Admin.PSObject.Copy()
        $full.Categories = $Scopes
        $full.Roles = @('SMS0001R')
        $full.Permissions = $permissions
        $full
    }

    # Deployment-type definition in the shape of the site manifest: one
    # MaxExecuteTime in the install action and one in the repair action.
    function New-TestTypeXml {
        param([string]$Script, [int]$InstallMinutes, [int]$RepairMinutes)
        ('<AppMgmtDigest xmlns="http://schemas.microsoft.com/SystemCenterConfigurationManager/2009/AppMgmtDigest"><DeploymentType><Installer Technology="Script">' +
            '<InstallAction><Provider>Script</Provider><Args><Arg Name="InstallCommandLine" Type="String">powershell.exe -ExecutionPolicy Bypass -File {0}</Arg><Arg Name="ExecutionContext" Type="String">System</Arg><Arg Name="MaxExecuteTime" Type="Int32">{1}</Arg><Arg Name="RunAs32Bit" Type="Boolean">false</Arg></Args></InstallAction>' +
            '<RepairAction><Provider>Script</Provider><Args><Arg Name="InstallCommandLine" Type="String">powershell.exe -ExecutionPolicy Bypass -File {0}</Arg><Arg Name="ExecutionContext" Type="String">System</Arg><Arg Name="MaxExecuteTime" Type="Int32">{2}</Arg><Arg Name="RunAs32Bit" Type="Boolean">false</Arg></Args></RepairAction>' +
            '</Installer></DeploymentType></AppMgmtDigest>') -f $Script, $InstallMinutes, $RepairMinutes
    }

    # The MECM default return codes of a script deployment type.
    function Get-TestReturnCodes {
        @(
            [pscustomobject]@{ ReturnCode = 0; CodeType = 'Success' }
            [pscustomobject]@{ ReturnCode = 1707; CodeType = 'Success' }
            [pscustomobject]@{ ReturnCode = 3010; CodeType = 'SoftReboot' }
            [pscustomobject]@{ ReturnCode = 1641; CodeType = 'HardReboot' }
            [pscustomobject]@{ ReturnCode = 1618; CodeType = 'FastRetry' }
        )
    }

    $script:LegacySid = 'S-1-5-21-1-2-3-500'
    function Invoke-TestPreflight {
        param($Config = $script:config, [string[]]$Sids = @($script:LegacySid), [string[]]$Names = @('SRV01'))
        Get-VsClientPreflightReport -Config $Config -Specs @(Get-VsClientAppSpecs) -ClientSourceDir $TestDrive -SiteCode 'PS1' -CurrentSids $Sids -ComputerNames $Names
    }

    # Both legacy applications visible, one task-sequence reference, one
    # legacy deployment; the caller holds scope All.
    function Get-TestLegacyCim {
        $admin = New-TestAdmin -Id 1 -Sid $script:LegacySid -Logon 'TEST\admin' -IsGroup $false -ScopeNames @('All') -Roles @('Full Administrator')
        $rows = @{
            SMS_Application = @(
                (New-TestRevision -CiId 42 -Model 'legacy/client_getinfo' -Name 'client_getinfo' -Version 1 -Latest $true)
                (New-TestRevision -CiId 43 -Model 'legacy/client_getinfo_2.1' -Name 'client_getinfo_2.1' -Version 1 -Latest $true)
            )
            SMS_DeploymentType = @((New-TestTypeRevision -CiId 200 -Model 'legacy/client_getinfo' -Name 'client_getinfo' -Version 1 -Latest $true))
            SMS_ApplicationGroup = @()
            SMS_TaskSequenceAppReferencesInfo = @([pscustomobject]@{ PackageID = 'PS100099'; RefAppCI_ID = 42; RefAppModelName = 'legacy/client_getinfo'; RefAppPackageID = 'PS100042' })
            SMS_TaskSequencePackage = @([pscustomobject]@{ PackageID = 'PS100099'; Name = 'Test task sequence' })
            SMS_AppDependenceRelation = @()
            SMS_CIRelation = @()
            SMS_Admin = @($admin)
            SMS_SecuredCategoryMembership = @(
                [pscustomobject]@{ CategoryID = 'SMS00UNA'; ObjectKey = 'LEGACY/CLIENT_GETINFO'; ObjectTypeID = 31 }
                [pscustomobject]@{ CategoryID = 'SMS00UNA'; ObjectKey = 'LEGACY/CLIENT_GETINFO_2.1'; ObjectTypeID = 31 }
            )
            SMS_SecuredCategory = @([pscustomobject]@{ CategoryID = 'SMS00ALL'; CategoryName = 'All' }, [pscustomobject]@{ CategoryID = 'SMS00UNA'; CategoryName = 'Default' })
            SMS_Collection = @(
                [pscustomobject]@{ CollectionID = 'PS100012'; Name = 'Core limit'; LimitToCollectionID = 'SMS00001' }
                [pscustomobject]@{ CollectionID = 'PS100013'; Name = 'Legacy target'; LimitToCollectionID = 'SMS00001' }
                [pscustomobject]@{ CollectionID = 'PS100022'; Name = 'Core target'; LimitToCollectionID = 'PS100012' }
                [pscustomobject]@{ CollectionID = 'SMS00001'; Name = 'All Systems'; LimitToCollectionID = $null }
            )
            SMS_CollectionSettings = @()
            SMS_ApplicationAssignment = @((New-TestAssignment -Id 99 -App 'client_getinfo' -AppCi 42 -Collection 'PS100013' -CollectionName 'Legacy target' -Deadline ([DateTime]::new(2026, 9, 1, 8, 0, 0, [DateTimeKind]::Utc))))
        }
        return [pscustomobject]@{ Rows = $rows; Full = @{ 'AdminID=1' = (New-TestAdminFull -Admin $admin -Scopes @('SMS00ALL')) } }
    }

    # The surveyed site in neutral values: a manual chain staticip -> Hostname
    # -> old getInfos, a second getInfos (v2.3) Required on the same
    # collection, expired revisions that still point at the chain, every
    # application in scope Default only, no maintenance window, both outside
    # window flags false and no project marker on any application.
    function Set-TestSiteLikeMecm {
        $script:ModelGetInfos = Get-TestModel 'aaaaaaaa-0000-0000-0000-000000000001'
        $script:ModelGetInfos23 = Get-TestModel 'aaaaaaaa-0000-0000-0000-000000000002'
        $script:ModelHostname = Get-TestModel 'aaaaaaaa-0000-0000-0000-000000000003'
        $script:ModelStaticIp = Get-TestModel 'aaaaaaaa-0000-0000-0000-000000000004'
        $script:ModelDisks = Get-TestModel 'aaaaaaaa-0000-0000-0000-000000000005'
        $script:SiteApps = @(
            [pscustomobject]@{ LocalizedDisplayName = 'client_getInfos'; ModelName = $script:ModelGetInfos; CI_ID = 2002; CIVersion = 2; IsLatest = $true; IsExpired = $false; SecuredScopeNames = @('Default'); LocalizedDescription = 'Old version, kept as a backup'; NumberOfDependentDTs = 1 }
            [pscustomobject]@{ LocalizedDisplayName = 'client_getInfos_v2.3'; ModelName = $script:ModelGetInfos23; CI_ID = 3026; CIVersion = 26; IsLatest = $true; IsExpired = $false; SecuredScopeNames = @('Default'); LocalizedDescription = 'PowerShell script'; NumberOfDependentDTs = 2 }
            [pscustomobject]@{ LocalizedDisplayName = 'client_Hostname'; ModelName = $script:ModelHostname; CI_ID = 4009; CIVersion = 9; IsLatest = $true; IsExpired = $false; SecuredScopeNames = @('Default'); LocalizedDescription = ''; NumberOfDependentDTs = 1 }
            [pscustomobject]@{ LocalizedDisplayName = 'client_Set-VMDisksOnline'; ModelName = $script:ModelDisks; CI_ID = 6001; CIVersion = 1; IsLatest = $true; IsExpired = $false; SecuredScopeNames = @('Default'); LocalizedDescription = ''; NumberOfDependentDTs = 0 }
            [pscustomobject]@{ LocalizedDisplayName = 'client_staticip'; ModelName = $script:ModelStaticIp; CI_ID = 5010; CIVersion = 10; IsLatest = $true; IsExpired = $false; SecuredScopeNames = @('Default'); LocalizedDescription = ''; NumberOfDependentDTs = 0 }
        )
        # Get-CMDeploymentType returned some types without their manifest.
        $script:SiteTypes = @{
            'client_getInfos' = [pscustomobject]@{ CI_ID = 2102; LocalizedDisplayName = 'client_getInfos'; SDMPackageXML = $null }
            'client_Hostname' = [pscustomobject]@{ CI_ID = 4109; LocalizedDisplayName = 'client_Hostname'; SDMPackageXML = (New-TestTypeXml -Script 'client_Hostname.ps1' -InstallMinutes 120 -RepairMinutes 120) }
            'client_staticip' = [pscustomobject]@{ CI_ID = 5110; LocalizedDisplayName = 'static powershell'; SDMPackageXML = (New-TestTypeXml -Script 'client_staticip.ps1' -InstallMinutes 120 -RepairMinutes 120) }
        }
        $deadline = [DateTime]::new(2025, 11, 24, 9, 52, 0, [DateTimeKind]::Utc)
        $script:SiteAssignments = @(
            (New-TestAssignment -Id 1001 -App 'client_Set-VMDisksOnline' -AppCi 6001 -Collection 'PS100015' -CollectionName 'Deploy Server 2019' -Deadline $deadline)
            (New-TestAssignment -Id 1002 -App 'client_Set-VMDisksOnline' -AppCi 6001 -Collection 'PS100014' -CollectionName 'Deploy Server 2022' -Deadline $deadline)
            (New-TestAssignment -Id 1003 -App 'client_getInfos' -AppCi 2002 -Collection 'PS100014' -CollectionName 'Deploy Server 2022' -Deadline $deadline.AddDays(302))
            (New-TestAssignment -Id 1004 -App 'client_Hostname' -AppCi 4009 -Collection 'PS100014' -CollectionName 'Deploy Server 2022' -Deadline $deadline)
            (New-TestAssignment -Id 1005 -App 'client_Hostname' -AppCi 4009 -Collection 'PS100015' -CollectionName 'Deploy Server 2019' -Deadline $deadline)
            (New-TestAssignment -Id 1006 -App 'client_staticip' -AppCi 5010 -Collection 'PS100015' -CollectionName 'Deploy Server 2019' -Deadline $deadline)
            (New-TestAssignment -Id 1007 -App 'client_staticip' -AppCi 5010 -Collection 'PS100014' -CollectionName 'Deploy Server 2022' -Deadline $deadline)
            (New-TestAssignment -Id 1008 -App 'client_getInfos_v2.3' -AppCi 3026 -Collection 'PS100014' -CollectionName 'Deploy Server 2022' -Deadline $deadline.AddDays(302))
        )
        Mock Get-CMApplication { @($script:SiteApps | Where-Object { $_.LocalizedDisplayName -ieq $Name }) }
        Mock Get-CMDeploymentType { foreach ($key in @($script:SiteTypes.Keys)) { if ($key -ieq $ApplicationName) { $script:SiteTypes[$key] } } }
        Mock Get-CMDeploymentTypeReturnCode { Get-TestReturnCodes }
        Mock Get-CMDeploymentTypeSupersedence { }
        Mock Get-CMDeploymentTypeDependencyGroup {
            if ($InputObject.CI_ID -eq 4109) { [pscustomobject]@{ GroupName = 'Requires client_getInfos'; GroupId = 1 } }
            if ($InputObject.CI_ID -eq 5110) { [pscustomobject]@{ GroupName = 'Requires client_hostname'; GroupId = 2 } }
        }
        Mock Get-CMDeploymentTypeDependency {
            if ($InputObject.GroupId -eq 1) { [pscustomobject]@{ CI_ID = 2102; LocalizedDisplayName = 'client_getInfos' } }
            if ($InputObject.GroupId -eq 2) { [pscustomobject]@{ CI_ID = 4109; LocalizedDisplayName = 'client_Hostname' } }
        }
        Mock Get-CMApplicationDeployment { @($script:SiteAssignments | Where-Object { $_.ApplicationName -ieq $Name }) }
        Mock Get-CMApplicationGroup { }
        Mock Get-CMDeviceCollection { [pscustomobject]@{ CollectionID = 'PS100020' } }
        Mock Get-CMDistributionPointGroup { [pscustomobject]@{ GroupID = 7 } }
        Mock Get-CMDistributionPoint { [pscustomobject]@{ SiteSystemServerName = 'dp01.test' } }
        # The share root lives under TestDrive: Join-Path rejects a drive that
        # does not exist on the test machine, and pwsh on Linux has none.
        $script:SiteShareRoot = Join-Path $TestDrive 'VirtuSphere'
        $script:SitePackagesBase = $script:SiteShareRoot + '\Base\Packages'
        Mock Get-SmbShare {
            [pscustomobject]@{ Name = 'ADMIN$'; Path = 'C:\Windows'; Special = $true }
            [pscustomobject]@{ Name = 'Software'; Path = 'D:\Software'; Special = $false }
            [pscustomobject]@{ Name = 'VirtuSphere'; Path = $script:SiteShareRoot; Special = $false }
        }
        Mock Read-VsSourceMarkerText { $script:identity }
        # pwsh on Linux may turn the backslashes of a joined path into slashes.
        Mock Test-Path { $true } -ParameterFilter { [string]$LiteralPath -match 'VirtuSphere[\\/]Base[\\/]Packages' }
        Mock Compare-VsClientContentManifest { @() }

        $user = New-TestAdmin -Id 16777217 -Sid 'S-1-5-21-1000-2000-3000-1223' -Logon 'TEST\admin1' -IsGroup $false -ScopeNames @('All') -Roles @('Full Administrator')
        $group = New-TestAdmin -Id 16777218 -Sid 'S-1-5-21-1000-2000-3000-1107' -Logon 'TEST\T1-Admins' -IsGroup $true -ScopeNames @('Default') -Roles @('Full Administrator', 'Read-only Analyst', 'Application Administrator')
        $other = New-TestAdmin -Id 16777219 -Sid 'S-1-5-21-1000-2000-3000-1500' -Logon 'TEST\other' -IsGroup $false -ScopeNames @('Default') -Roles @('Read-only Analyst')
        $rows = @{
            SMS_Application = @(
                (New-TestRevision -CiId 2001 -Model $script:ModelGetInfos -Name 'client_getInfos' -Version 1 -Latest $false)
                (New-TestRevision -CiId 2002 -Model $script:ModelGetInfos -Name 'client_getInfos' -Version 2 -Latest $true)
                (New-TestRevision -CiId 3026 -Model $script:ModelGetInfos23 -Name 'client_getInfos_v2.3' -Version 26 -Latest $true)
                (New-TestRevision -CiId 4006 -Model $script:ModelHostname -Name 'client_Hostname' -Version 6 -Latest $false)
                (New-TestRevision -CiId 4007 -Model $script:ModelHostname -Name 'client_Hostname' -Version 7 -Latest $false)
                (New-TestRevision -CiId 4009 -Model $script:ModelHostname -Name 'client_Hostname' -Version 9 -Latest $true)
                (New-TestRevision -CiId 5006 -Model $script:ModelStaticIp -Name 'client_staticip' -Version 6 -Latest $false)
                (New-TestRevision -CiId 5007 -Model $script:ModelStaticIp -Name 'client_staticip' -Version 7 -Latest $false)
                (New-TestRevision -CiId 5008 -Model $script:ModelStaticIp -Name 'client_staticip' -Version 8 -Latest $false)
                (New-TestRevision -CiId 5010 -Model $script:ModelStaticIp -Name 'client_staticip' -Version 10 -Latest $true)
                (New-TestRevision -CiId 6001 -Model $script:ModelDisks -Name 'client_Set-VMDisksOnline' -Version 1 -Latest $true)
            )
            SMS_DeploymentType = @(
                (New-TestTypeRevision -CiId 2101 -Model $script:ModelGetInfos -Name 'client_getInfos' -Version 1 -Latest $false)
                (New-TestTypeRevision -CiId 2102 -Model $script:ModelGetInfos -Name 'client_getInfos' -Version 2 -Latest $true)
                (New-TestTypeRevision -CiId 3101 -Model $script:ModelGetInfos23 -Name 'client_getInfos_v2.3' -Version 26 -Latest $true)
                (New-TestTypeRevision -CiId 4106 -Model $script:ModelHostname -Name 'client_Hostname' -Version 6 -Latest $false)
                (New-TestTypeRevision -CiId 4107 -Model $script:ModelHostname -Name 'client_Hostname' -Version 7 -Latest $false)
                (New-TestTypeRevision -CiId 4109 -Model $script:ModelHostname -Name 'client_Hostname' -Version 9 -Latest $true)
                (New-TestTypeRevision -CiId 5106 -Model $script:ModelStaticIp -Name 'static powershell' -Version 6 -Latest $false)
                (New-TestTypeRevision -CiId 5107 -Model $script:ModelStaticIp -Name 'static powershell' -Version 7 -Latest $false)
                (New-TestTypeRevision -CiId 5108 -Model $script:ModelStaticIp -Name 'static powershell' -Version 8 -Latest $false)
                (New-TestTypeRevision -CiId 5110 -Model $script:ModelStaticIp -Name 'static powershell' -Version 10 -Latest $true)
            )
            SMS_ApplicationGroup = @()
            SMS_TaskSequenceAppReferencesInfo = @()
            SMS_TaskSequencePackage = @()
            # The relations of the surveyed site: To always names the latest
            # revision, From also expired ones.
            SMS_AppDependenceRelation = @(
                (New-TestRelation 5006 5106 3026 3101)
                (New-TestRelation 4006 4106 3026 3101)
                (New-TestRelation 4007 4107 2002 2102)
                (New-TestRelation 4007 4107 3026 3101)
                (New-TestRelation 5007 5107 3026 3101)
                (New-TestRelation 5007 5107 4009 4109)
                (New-TestRelation 5008 5108 4009 4109)
                (New-TestRelation 4009 4109 2002 2102)
                (New-TestRelation 5010 5110 4009 4109)
            )
            # Relation types are arbitrary numbers here; the preflight does not interpret them.
            SMS_CIRelation = @(
                [pscustomobject]@{ FromCIID = 4009; ToCIID = 4109; RelationType = 10; IsBroken = $false; IsVersionSpecific = $false; Priority = 0 }
                [pscustomobject]@{ FromCIID = 5110; ToCIID = 4109; RelationType = 2; IsBroken = $false; IsVersionSpecific = $false; Priority = 0 }
                [pscustomobject]@{ FromCIID = 4107; ToCIID = 2102; RelationType = 2; IsBroken = $false; IsVersionSpecific = $false; Priority = 0 }
                [pscustomobject]@{ FromCIID = 4109; ToCIID = 2102; RelationType = 2; IsBroken = $false; IsVersionSpecific = $false; Priority = 0 }
            )
            SMS_Admin = @($user, $group, $other)
            SMS_SecuredCategoryMembership = @(
                foreach ($model in @($script:ModelGetInfos, $script:ModelGetInfos23, $script:ModelHostname, $script:ModelStaticIp, $script:ModelDisks)) {
                    [pscustomobject]@{ CategoryID = 'SMS00UNA'; ObjectKey = $model.ToUpperInvariant(); ObjectTypeID = 31 }
                }
            )
            SMS_SecuredCategory = @(
                [pscustomobject]@{ CategoryID = 'SMS00ALL'; CategoryName = 'All'; IsBuiltIn = $true }
                [pscustomobject]@{ CategoryID = 'SMS00UNA'; CategoryName = 'Default'; IsBuiltIn = $true }
            )
            SMS_Collection = @(
                [pscustomobject]@{ CollectionID = 'PS100014'; Name = 'Deploy Server 2022'; LimitToCollectionID = 'SMS00001'; CollectionType = 2; MemberCount = 4 }
                [pscustomobject]@{ CollectionID = 'PS100015'; Name = 'Deploy Server 2019'; LimitToCollectionID = 'SMS00001'; CollectionType = 2; MemberCount = 0 }
                [pscustomobject]@{ CollectionID = 'PS100020'; Name = 'Core limit'; LimitToCollectionID = 'SMS00001'; CollectionType = 2; MemberCount = 0 }
                [pscustomobject]@{ CollectionID = 'SMS00001'; Name = 'All Systems'; LimitToCollectionID = $null; CollectionType = 2; MemberCount = 206 }
            )
            # One collection has settings without a window, the other none.
            SMS_CollectionSettings = @([pscustomobject]@{ CollectionID = 'PS100014'; ServiceWindows = $null; RebootCountdown = 0 })
            SMS_ApplicationAssignment = $script:SiteAssignments
        }
        $full = @{
            'AdminID=16777217' = (New-TestAdminFull -Admin $user -Scopes @('SMS00ALL'))
            'AdminID=16777218' = (New-TestAdminFull -Admin $group -Scopes @('SMS00UNA'))
            'AdminID=16777219' = (New-TestAdminFull -Admin $other -Scopes @('SMS00UNA'))
            'CollectionID=PS100014' = [pscustomobject]@{ CollectionID = 'PS100014'; ServiceWindows = @(); RebootCountdown = 0 }
        }
        Use-TestCim -Rows $rows -Full $full
    }

    function Invoke-TestSiteLikePreflight {
        $siteConfig = @{
            PackagesBase = $script:SitePackagesBase
            ContentShare = '\\MECM01\VirtuSphere\Base\Packages'
            SourceIdentity = $script:identity
            CoreLimitingCollectionId = 'PS100020'
            DpGroupName = 'Test DP'
        }
        Get-VsClientPreflightReport -Config $siteConfig -Specs @(Get-VsClientAppSpecs) -ClientSourceDir $script:ClientSourceDir -SiteCode 'PS1' `
            -CurrentSids @('S-1-5-21-1000-2000-3000-1223', 'S-1-5-21-1000-2000-3000-1107', 'S-1-5-32-544', 'S-1-1-0') `
            -ComputerNames @('MECM01', 'mecm01.corp.example')
    }

    function Get-TestRows {
        param($Report, [string]$Code, [string]$Target)
        @($Report.Checks | Where-Object { $_.Code -ceq $Code -and (-not $Target -or $_.Target -ceq $Target) })
    }
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
        Mock Get-CMApplicationGroup { [pscustomobject]@{ LocalizedDisplayName = 'Other group'; ModelName = 'group/1'; CI_ID = 300; SDMPackageXML = '<AppGroup />' } }
        Mock Get-CMApplicationDeployment {
            if ($Name -eq 'client_getinfo') { [pscustomobject]@{ AssignmentID = 99; CollectionID = 'PS100013' } }
        }
        Mock Get-SmbShare { }
        Mock New-CMApplication { throw 'write was called' }
        Mock Copy-VsClientContent { throw 'write was called' }
        $legacy = Get-TestLegacyCim
        Use-TestCim -Rows $legacy.Rows -Full $legacy.Full
    }

    It 'reads markers and content from a FileSystem location while a non-FileSystem drive is active' {
        # Initialize-VsCmSite leaves the session on the CM site drive; UNC paths
        # only resolve from a FileSystem location. Env: stands in for that drive
        # because it exists on every engine (HKCU: has no Linux counterpart).
        foreach ($spec in @(Get-VsClientAppSpecs)) {
            New-Item -ItemType Directory -Path (Join-Path $script:sourceRoot $spec.Folder), (Join-Path $script:shareRoot $spec.Folder) -Force | Out-Null
        }
        $script:manifestProviders = @()
        Mock Compare-VsClientContentManifest { $script:manifestProviders += (Get-Location).Provider.Name; @() }
        Push-Location -LiteralPath 'Env:\'
        try {
            $report = Invoke-TestPreflight
            (Get-Location).Provider.Name | Should -Be 'Environment'
        } finally {
            Pop-Location
        }
        $script:manifestProviders | Should -Be @('FileSystem', 'FileSystem', 'FileSystem', 'FileSystem')
        @($report.Findings | Where-Object { $_.Code -in @('source_identity_unknown', 'content_inventory_unknown') }).Count | Should -Be 0
        @($report.Inventory | Where-Object { $_.Kind -eq 'source' }).Count | Should -Be 2
    }

    It 'reports both legacy objects and blocks apply without a write' {
        $report = Invoke-TestPreflight
        $report.CanApply | Should -BeFalse
        $report.SchemaVersion | Should -Be 2
        @($report.Inventory | Where-Object Kind -eq 'legacy-application').Count | Should -Be 2
        @($report.Inventory | Where-Object { $_.Kind -eq 'application-deployment' -and $_.Name -eq 'client_getinfo' }).Count | Should -Be 1
        @($report.Inventory | Where-Object { $_.Kind -eq 'supersedence-forward' -and $_.SourceId -eq '200' -and $_.TargetId -eq '201' }).Count | Should -Be 1
        @($report.Inventory | Where-Object { $_.Kind -eq 'application-group' -and $_.Identity -eq 'group/1' }).Count | Should -Be 1
        @($report.Inventory | Where-Object { $_.Kind -eq 'task-sequence-reference' -and $_.Identity -eq 'PS100099' -and $_.Name -eq 'client_getinfo' }).Count | Should -Be 1
        @($report.Inventory | Where-Object { $_.Kind -eq 'distribution-point' -and $_.Identity -eq 'dp01.test' }).Count | Should -Be 1
        @($report.Findings | Where-Object { $_.Code -eq 'legacy_deployment_present' -and $_.Target -eq 'client_getinfo' }).Count | Should -Be 1
        $reference = @(Get-TestRows -Report $report -Code 'task_sequence_reference' -Target 'client_getinfo')
        $reference.Count | Should -Be 1
        $reference[0].Status | Should -Be 'fail'
        $reference[0].Reason | Should -Match "PS100099 'Test task sequence'"
        @($report.Findings | Where-Object { $_.Code -in $script:PlaceholderCodes }).Count | Should -Be 0
        $report.PlanId | Should -Match '^[0-9a-f]{64}$'
        Should -Invoke New-CMApplication -Times 0
        Should -Invoke Copy-VsClientContent -Times 0
    }

    It 'keeps a legacy deployment disabled by the retirement tool as a note, not a blocker' {
        Mock Get-CMApplicationDeployment {
            if ($Name -eq 'client_getinfo') { [pscustomobject]@{ AssignmentID = 99; CollectionID = 'PS100013'; Enabled = $false } }
        }
        $report = Invoke-TestPreflight
        @($report.Findings | Where-Object { $_.Code -eq 'legacy_deployment_disabled' -and $_.Target -eq 'client_getinfo' -and -not $_.Blocking }).Count | Should -Be 1
        @($report.Findings | Where-Object Code -eq 'legacy_deployment_present').Count | Should -Be 0
    }

    It 'marks a missing UNC marker as blocking and never infers path identity from matching content' {
        # -Force: pwsh on Linux treats a dot file as hidden and would leave it.
        Remove-Item -LiteralPath (Join-Path $script:shareRoot '.virtusphere-source-identity') -Force
        $report = Invoke-TestPreflight
        @($report.Findings | Where-Object { $_.Code -eq 'source_identity_unknown' -and $_.Target -eq 'unc' }).Count | Should -Be 1
        $mapping = @($report.Checks | Where-Object Check -eq 'source_mapping')
        $mapping.Count | Should -Be 1
        $mapping[0].Status | Should -Be 'unknown'
        $mapping[0].Blocking | Should -BeTrue
    }

    It 'reports provider failure as unknown rather than zero references' {
        Mock Get-VsPreflightCim { throw 'provider unavailable' }
        $report = Invoke-TestPreflight
        @($report.Findings | Where-Object Code -eq 'task_sequence_references_unknown').Count | Should -Be 1
        @($report.Inventory | Where-Object Kind -eq 'task-sequence-reference-scan').Count | Should -Be 0
        @($report.Inventory | Where-Object Kind -eq 'reverse-dependency-scan').Count | Should -Be 0
        @(Get-TestRows -Report $report -Code 'references_complete').Count | Should -Be 0
        @(Get-TestRows -Report $report -Code 'rbac_admin_unknown').Count | Should -Be 1
        @($report.Checks | Where-Object { $_.Check -eq 'references' -and $_.Status -eq 'unknown' -and $_.Blocking }).Count | Should -BeGreaterThan 0
        $report.CanApply | Should -BeFalse
    }

    It 'blocks an installer-managed Core deployment without the outside-window flags (Q1)' {
        $marker = (Get-VsClientAppSpecs | Where-Object AppName -eq 'client_staticip').ManagedMarker
        Mock Get-CMApplication {
            if ($Name -eq 'client_staticip') { [pscustomobject]@{ LocalizedDisplayName = 'client_staticip'; ModelName = 'managed/client_staticip'; CI_ID = 70; LocalizedDescription = $marker } }
        }
        Mock Get-CMDeploymentType { if ($ApplicationName -eq 'client_staticip') { [pscustomobject]@{ CI_ID = 71; LocalizedDisplayName = 'client_staticip Deployment' } } }
        Mock Get-CMDeploymentTypeReturnCode { Get-TestReturnCodes }
        Mock Get-CMDeploymentTypeSupersedence { }
        Mock Get-CMDeploymentTypeDependencyGroup { }
        Mock Get-CMApplicationDeployment {
            if ($Name -eq 'client_staticip') {
                [pscustomobject]@{
                    AssignmentID = 100; TargetCollectionID = 'PS100022'; OfferTypeID = 0; DesiredConfigType = 1
                    OverrideServiceWindows = $true; RebootOutsideOfServiceWindows = $false; SoftDeadlineEnabled = $false; RequireApproval = $false
                    Enabled = $true; EnforcementDeadline = '2026-09-27T12:00:00Z'; OfferFlags = 0
                }
            }
        }
        $report = Invoke-TestPreflight
        $drift = @(Get-TestRows -Report $report -Code 'core_deployment_policy_drift' -Target 'client_staticip')
        $drift.Count | Should -Be 1
        $drift[0].Blocking | Should -BeTrue
        $drift[0].Reason | Should -Match 'no restart outside maintenance windows'
        @($report.Findings | Where-Object Code -eq 'core_deployment_policy_drift').Count | Should -Be 1
        @($report.Inventory | Where-Object { $_.Kind -eq 'application-deployment' -and $_.Name -eq 'client_staticip' -and $_.Collection -eq 'PS100022' }).Count | Should -Be 1
    }

    It 'only warns about the same flags on a manual Core deployment' {
        Mock Get-CMApplication {
            if ($Name -eq 'client_staticip') { [pscustomobject]@{ LocalizedDisplayName = 'client_staticip'; ModelName = 'manual/client_staticip'; CI_ID = 70; LocalizedDescription = '' } }
        }
        Mock Get-CMDeploymentType { if ($ApplicationName -eq 'client_staticip') { [pscustomobject]@{ CI_ID = 71; LocalizedDisplayName = 'static powershell' } } }
        Mock Get-CMDeploymentTypeReturnCode { Get-TestReturnCodes }
        Mock Get-CMDeploymentTypeSupersedence { }
        Mock Get-CMDeploymentTypeDependencyGroup { }
        Mock Get-CMApplicationDeployment {
            if ($Name -eq 'client_staticip') {
                [pscustomobject]@{ AssignmentID = 100; TargetCollectionID = 'PS100022'; OfferTypeID = 0; DesiredConfigType = 1; OverrideServiceWindows = $false; RebootOutsideOfServiceWindows = $false; Enabled = $true }
            }
        }
        $report = Invoke-TestPreflight
        $warn = @(Get-TestRows -Report $report -Code 'manual_deployment_window_flags' -Target 'client_staticip')
        $warn.Count | Should -Be 1
        $warn[0].Status | Should -Be 'warn'
        $warn[0].Blocking | Should -BeFalse
        @(Get-TestRows -Report $report -Code 'core_deployment_policy_drift').Count | Should -Be 0
    }

    It 'does not treat an empty visible DP group as distributed' {
        Mock Get-CMDistributionPoint { }
        $report = Invoke-TestPreflight
        @($report.Findings | Where-Object Code -eq 'dp_group_unknown').Count | Should -Be 1
        @($report.Inventory | Where-Object Kind -eq 'distribution-point').Count | Should -Be 0
    }
}

Describe 'MC02 preflight on a site like the surveyed one' {
    BeforeEach {
        $script:identity = 'fedcba9876543210' * 2
        Set-TestSiteLikeMecm
    }

    It 'answers all four checks with a status and a reason and no placeholder' {
        $report = Invoke-TestSiteLikePreflight
        foreach ($check in @('rbac_scope', 'source_mapping', 'references', 'deployment_policy')) {
            @($report.Checks | Where-Object Check -ceq $check).Count | Should -BeGreaterThan 0 -Because $check
        }
        foreach ($row in @($report.Checks)) {
            $row.Status | Should -BeIn @('pass', 'fail', 'warn', 'unknown')
            $row.Reason | Should -Not -BeNullOrEmpty
            if ($row.Status -ne 'pass') {
                @($report.Findings | Where-Object { $_.Code -ceq $row.Code -and $_.Target -ceq $row.Target -and $_.Blocking -eq $row.Blocking -and $_.Detail -ceq $row.Reason }).Count | Should -BeGreaterThan 0 -Because $row.Code
            }
        }
        @($report.Findings | Where-Object { $_.Code -in $script:PlaceholderCodes }).Count | Should -Be 0
        $report.CanApply | Should -BeFalse
    }

    It 'proves the share mapping on this computer' {
        $report = Invoke-TestSiteLikePreflight
        $mapping = @($report.Checks | Where-Object Check -eq 'source_mapping')
        $mapping.Count | Should -Be 1
        $mapping[0].Code | Should -Be 'source_mapping_verified'
        $mapping[0].Status | Should -Be 'pass'
    }

    It 'covers scope Default and the deployment collections through the caller and its group' {
        $report = Invoke-TestSiteLikePreflight
        @(Get-TestRows -Report $report -Code 'rbac_view_complete' -Target 'caller').Count | Should -Be 1
        foreach ($name in @('client_getInfos', 'client_hostname', 'client_staticip')) {
            @(Get-TestRows -Report $report -Code 'rbac_scope_covered' -Target $name).Count | Should -Be 1 -Because $name
        }
        foreach ($id in @('PS100014', 'PS100015', 'PS100020')) {
            $row = @(Get-TestRows -Report $report -Code 'rbac_collection_covered' -Target $id)
            $row.Count | Should -Be 1 -Because $id
            $row[0].Reason | Should -Match 'SMS00001'
        }
        # client_VMDisksOnline is not on the site; the full view proves it.
        @(Get-TestRows -Report $report -Code 'application_absent' -Target 'client_VMDisksOnline').Count | Should -Be 1
        $caller = @($report.Inventory | Where-Object Kind -eq 'rbac-caller')
        $caller[0].Logons | Should -Be @('TEST\admin1', 'TEST\T1-Admins')
        $caller[0].ScopeIds | Should -Be @('SMS00ALL', 'SMS00UNA')
    }

    It 'finds the manual chain and the expired revisions that still point at it' {
        $report = Invoke-TestSiteLikePreflight
        $internal = @(Get-TestRows -Report $report -Code 'reverse_dependency_internal')
        $internal.Count | Should -Be 2
        @($internal | Where-Object { $_.Target -eq 'client_getInfos' -and $_.Reason -match 'client_Hostname \(revision 9\)' }).Count | Should -Be 1
        @($internal | Where-Object { $_.Target -eq 'client_hostname' -and $_.Reason -match 'client_staticip \(revision 10\)' }).Count | Should -Be 1
        $old = @(Get-TestRows -Report $report -Code 'reverse_dependency_old_revision')
        $old.Count | Should -Be 3
        @($old | Where-Object { $_.Status -ne 'warn' -or $_.Blocking }).Count | Should -Be 0
        @($old | Where-Object { $_.Target -eq 'client_getInfos' -and $_.Reason -match 'client_Hostname \(revision 7\)' }).Count | Should -Be 1
        @($report.Checks | Where-Object { $_.Check -eq 'references' -and $_.Status -in @('fail', 'unknown') }).Count | Should -Be 0
        # The versioned getInfos is no target; its own incoming relations are not reported.
        @($report.Checks | Where-Object { $_.Check -eq 'references' -and $_.Reason -match 'client_getInfos_v2\.3' }).Count | Should -Be 0
    }

    It 'blocks two Required getInfos on one collection and only warns about the manual flags' {
        $report = Invoke-TestSiteLikePreflight
        $parallel = @(Get-TestRows -Report $report -Code 'parallel_required_deployment')
        $parallel.Count | Should -Be 1
        $parallel[0].Target | Should -Be 'PS100014'
        $parallel[0].Blocking | Should -BeTrue
        $parallel[0].Reason | Should -Match 'client_getInfos and client_getInfos_v2\.3'
        $flags = @(Get-TestRows -Report $report -Code 'manual_deployment_window_flags')
        $flags.Count | Should -Be 6
        @($flags | Where-Object { $_.Status -ne 'warn' -or $_.Blocking }).Count | Should -Be 0
        @($flags | Where-Object { $_.Reason -match 'Assignment 1008 of client_getInfos_v2\.3 on PS100014' }).Count | Should -Be 1
        @(Get-TestRows -Report $report -Code 'core_deployment_policy_drift').Count | Should -Be 0
        foreach ($id in @('PS100014', 'PS100015')) {
            @(Get-TestRows -Report $report -Code 'maintenance_windows_none' -Target $id).Count | Should -Be 1 -Because $id
        }
        foreach ($code in @('implicit_uninstall_unknown', 'simulation_unknown')) {
            $row = @(Get-TestRows -Report $report -Code $code -Target 'core')
            $row.Count | Should -Be 1
            $row[0].Status | Should -Be 'unknown'
            $row[0].Blocking | Should -BeFalse
        }
        # The other applications on the collections are not part of the chain.
        @($report.Checks | Where-Object { $_.Reason -match 'client_Set-VMDisksOnline' }).Count | Should -Be 0
    }

    It 'reads the maximum runtime from the install action of the site manifest' {
        $report = Invoke-TestSiteLikePreflight
        foreach ($name in @('client_hostname', 'client_staticip')) {
            $row = @(Get-TestRows -Report $report -Code 'maximum_runtime_drift' -Target $name)
            $row.Count | Should -Be 1 -Because $name
            $row[0].Reason | Should -Match 'is 120 min, the contract expects 15 min'
        }
        @(Get-TestRows -Report $report -Code 'maximum_runtime_unknown' -Target 'client_getInfos').Count | Should -Be 1
    }

    It 'keeps the report stable for the same site state' {
        $first = Invoke-TestSiteLikePreflight
        $second = Invoke-TestSiteLikePreflight
        $second.PlanId | Should -Be $first.PlanId
        { $first | ConvertTo-Json -Depth 12 | ConvertFrom-Json } | Should -Not -Throw
    }
}

Describe 'MC02 source mapping' {
    BeforeAll {
        $script:Shares = @(
            [pscustomobject]@{ Name = 'VirtuSphere'; Path = 'D:\VirtuSphere' }
            [pscustomobject]@{ Name = 'Other'; Path = 'E:\Other' }
        )
        function Get-TestMapping {
            param([string]$Share = '\\SRV01\VirtuSphere\Base\Packages', [string]$Base = 'D:\VirtuSphere\Base\Packages', [string]$ShareError = '', [bool]$Markers = $true, $Shares = $script:Shares)
            Get-VsSourceMappingCheck -PackagesBase $Base -ContentShare $Share -Shares $Shares -ShareError $ShareError -ComputerNames @('SRV01', 'srv01.corp.example') -MarkersConfirmed $Markers
        }
    }

    It 'passes for the NetBIOS and the FQDN name in any case' {
        (Get-TestMapping).Status | Should -Be 'pass'
        (Get-TestMapping -Share '\\SRV01.CORP.EXAMPLE\virtusphere\Base\Packages\').Status | Should -Be 'pass'
        (Get-TestMapping -Base 'd:\virtusphere\base\packages').Status | Should -Be 'pass'
    }

    It 'fails when the share leads elsewhere or does not exist here' {
        $other = Get-TestMapping -Share '\\SRV01\Other\Base\Packages'
        $other.Status | Should -Be 'fail'
        $other.Code | Should -Be 'source_mapping_mismatch'
        $other.Reason | Should -Match "E:\\Other\\Base\\Packages"
        (Get-TestMapping -Share '\\SRV01\Missing\Base\Packages').Code | Should -Be 'source_mapping_mismatch'
    }

    It 'stays unknown for a foreign host, unreadable shares, an unconfirmed marker or no UNC path' {
        foreach ($case in @(
            @{ Share = '\\OTHER01\VirtuSphere\Base\Packages'; ShareError = ''; Markers = $true }
            @{ Share = '\\SRV01\VirtuSphere\Base\Packages'; ShareError = 'access denied'; Markers = $true }
            @{ Share = '\\SRV01\VirtuSphere\Base\Packages'; ShareError = ''; Markers = $false }
            @{ Share = 'D:\VirtuSphere\Base\Packages'; ShareError = ''; Markers = $true }
        )) {
            $row = Get-TestMapping -Share $case.Share -ShareError $case.ShareError -Markers $case.Markers
            $row.Status | Should -Be 'unknown' -Because $case.Share
            $row.Code | Should -Be 'source_mapping_unknown'
            $row.Blocking | Should -BeTrue
        }
    }
}

Describe 'MC02 RBAC evaluation' {
    It 'loads AdminSid per row when the provider leaves it out of the enumeration' {
        $admin = New-TestAdmin -Id 5 -Sid $null -Logon 'TEST\lazy' -IsGroup $true -ScopeNames @('Default') -Roles @('Application Administrator')
        $withSid = (New-TestAdminFull -Admin $admin -Scopes @('SMS00UNA'))
        $withSid.AdminSid = 'S-1-5-21-9-9-9-1107'
        Use-TestCim -Rows @{ SMS_Admin = @($admin) } -Full @{ 'AdminID=5' = $withSid }
        $result = Read-VsCallerAdminScope -SiteCode 'PS1' -CurrentSids @('s-1-5-21-9-9-9-1107')
        $result.State | Should -Be 'complete'
        $result.ScopeIds | Should -Be @('SMS00UNA')
        $result.CollectionIds | Should -Be @('SMS00001', 'SMS00004')
        $result.RoleNames | Should -Be @('Application Administrator')
    }

    It 'is unknown when no SMS_Admin row matches the caller' {
        $admin = New-TestAdmin -Id 6 -Sid 'S-1-5-21-9-9-9-1' -Logon 'TEST\other' -IsGroup $false -ScopeNames @('All') -Roles @('Full Administrator')
        Use-TestCim -Rows @{ SMS_Admin = @($admin) }
        $result = Read-VsCallerAdminScope -SiteCode 'PS1' -CurrentSids @('S-1-5-21-9-9-9-2')
        $result.State | Should -Be 'unknown'
        $rows = @(Get-VsRbacChecks -Admin $result -AbsentApplications @('client_getInfos'))
        @($rows | Where-Object { $_.Code -eq 'rbac_admin_unknown' -and $_.Blocking }).Count | Should -Be 1
        @($rows | Where-Object { $_.Code -eq 'application_absence_unverified' -and $_.Blocking }).Count | Should -Be 1
    }

    It 'covers by a shared scope, fails a foreign scope and an unassigned collection chain' {
        $admin = [pscustomobject]@{ State = 'complete'; Detail = ''; Logons = @('TEST\T1-Admins'); ScopeIds = @('SMS00UNA'); CollectionIds = @('PS100030'); CollectionNames = @('Servers') }
        $applications = @(
            [pscustomobject]@{ Name = 'client_getInfos'; State = 'complete'; Detail = ''; ScopeIds = @('SMS00UNA') }
            [pscustomobject]@{ Name = 'client_hostname'; State = 'complete'; Detail = ''; ScopeIds = @('PS100002') }
            [pscustomobject]@{ Name = 'client_staticip'; State = 'unknown'; Detail = 'No security scope membership is readable for this application'; ScopeIds = @() }
        )
        $collections = @(
            [pscustomobject]@{ CollectionId = 'PS100031'; State = 'complete'; Detail = ''; Chain = @([pscustomobject]@{ Id = 'PS100031'; Name = 'Core' }, [pscustomobject]@{ Id = 'PS100030'; Name = 'Servers' }, [pscustomobject]@{ Id = 'SMS00001'; Name = 'All Systems' }) }
            [pscustomobject]@{ CollectionId = 'PS100032'; State = 'complete'; Detail = ''; Chain = @([pscustomobject]@{ Id = 'PS100032'; Name = 'Clients' }, [pscustomobject]@{ Id = 'SMS00001'; Name = 'All Systems' }) }
        )
        $scopes = [pscustomobject]@{ State = 'complete'; Detail = ''; Scopes = @([pscustomobject]@{ Id = 'SMS00ALL'; Name = 'All' }, [pscustomobject]@{ Id = 'SMS00UNA'; Name = 'Default' }, [pscustomobject]@{ Id = 'PS100002'; Name = 'Servers' }) }
        $rows = @(Get-VsRbacChecks -Admin $admin -Applications $applications -Collections $collections -SiteScopes $scopes -AbsentApplications @('client_VMDisksOnline'))
        @($rows | Where-Object { $_.Code -eq 'rbac_scope_covered' -and $_.Target -eq 'client_getInfos' }).Count | Should -Be 1
        @($rows | Where-Object { $_.Code -eq 'rbac_scope_uncovered' -and $_.Target -eq 'client_hostname' -and $_.Blocking }).Count | Should -Be 1
        @($rows | Where-Object { $_.Code -eq 'rbac_scope_unknown' -and $_.Target -eq 'client_staticip' }).Count | Should -Be 1
        @($rows | Where-Object { $_.Code -eq 'rbac_collection_covered' -and $_.Target -eq 'PS100031' -and $_.Reason -match 'PS100030 \(Servers\)' }).Count | Should -Be 1
        @($rows | Where-Object { $_.Code -eq 'rbac_collection_uncovered' -and $_.Target -eq 'PS100032' }).Count | Should -Be 1
        $partial = @($rows | Where-Object Code -eq 'rbac_view_partial')
        $partial.Count | Should -Be 1
        $partial[0].Blocking | Should -BeFalse
        $partial[0].Reason | Should -Match 'SMS00ALL \(All\), PS100002 \(Servers\)'
        @($rows | Where-Object { $_.Code -eq 'application_absence_unverified' -and $_.Target -eq 'client_VMDisksOnline' }).Count | Should -Be 1
    }

    It 'walks the limiting chain and stops at a cycle' {
        Use-TestCim -Rows @{ SMS_Collection = @(
            [pscustomobject]@{ CollectionID = 'PS100041'; Name = 'A'; LimitToCollectionID = 'PS100042' }
            [pscustomobject]@{ CollectionID = 'PS100042'; Name = 'B'; LimitToCollectionID = 'PS100041' }
            [pscustomobject]@{ CollectionID = 'PS100043'; Name = 'C'; LimitToCollectionID = 'SMS00001' }
            [pscustomobject]@{ CollectionID = 'SMS00001'; Name = 'All Systems'; LimitToCollectionID = $null }
        ) }
        $cycle = Read-VsCollectionChain -SiteCode 'PS1' -CollectionId 'PS100041'
        $cycle.State | Should -Be 'unknown'
        $cycle.Detail | Should -Match 'returns to PS100041'
        $chain = Read-VsCollectionChain -SiteCode 'PS1' -CollectionId 'PS100043'
        $chain.State | Should -Be 'complete'
        @($chain.Chain | ForEach-Object Id) | Should -Be @('PS100043', 'SMS00001')
        (Read-VsCollectionChain -SiteCode 'PS1' -CollectionId 'PS100099').Detail | Should -Match 'not visible'
    }
}

Describe 'MC02 reverse references' {
    BeforeAll {
        $script:TargetModel = Get-TestModel 'bbbbbbbb-0000-0000-0000-000000000001'
        $script:ForeignModel = Get-TestModel 'bbbbbbbb-0000-0000-0000-000000000002'
        $script:Revisions = [pscustomobject]@{
            State = 'complete'; Detail = ''
            Applications = @([pscustomobject]@{ CiId = '700'; ModelName = $script:TargetModel; Name = 'client_getInfos'; Version = '3'; IsLatest = $true; Target = 'client_getInfos' })
            DeploymentTypes = @([pscustomobject]@{ CiId = '701'; ModelName = $script:TargetModel; Name = 'client_getInfos Deployment'; Version = '3'; IsLatest = $true; Target = 'client_getInfos' })
        }
        function Set-TestForeignCim {
            param([object[]]$Relations = @(), [object[]]$CiRelations = @())
            Use-TestCim -Rows @{
                SMS_AppDependenceRelation = $Relations
                SMS_CIRelation = $CiRelations
                SMS_Application = @(
                    (New-TestRevision -CiId 800 -Model $script:ForeignModel -Name 'Foreign tool' -Version 4 -Latest $true)
                    (New-TestRevision -CiId 799 -Model $script:ForeignModel -Name 'Foreign tool' -Version 3 -Latest $false)
                )
                SMS_DeploymentType = @((New-TestTypeRevision -CiId 801 -Model $script:ForeignModel -Name 'Foreign tool DT' -Version 4 -Latest $true))
                SMS_ApplicationGroup = @([pscustomobject]@{ CI_ID = 900; IsLatest = $true; LocalizedDisplayName = 'Server baseline group'; ModelName = 'ScopeId_0A0A0A0A-1111-2222-3333-444444444444/AppGroup_1' })
            }
        }
    }

    It 'blocks a foreign latest application and warns about its old revision' {
        Set-TestForeignCim -Relations @((New-TestRelation 800 801 700 701), (New-TestRelation 799 798 700 701))
        $result = Get-VsDependenceRelationChecks -SiteCode 'PS1' -Revisions $script:Revisions -Cache @{}
        @($result.Checks | Where-Object { $_.Code -eq 'reverse_dependency_foreign' -and $_.Blocking -and $_.Reason -match 'Foreign tool \(revision 4\)' }).Count | Should -Be 1
        @($result.Checks | Where-Object { $_.Code -eq 'reverse_dependency_old_revision' -and -not $_.Blocking }).Count | Should -Be 1
        $result.Reported.Contains(('{0}|{1}' -f $script:ForeignModel, $script:TargetModel)) | Should -BeTrue
    }

    It 'is unknown for a dependent CI it cannot resolve' {
        Set-TestForeignCim -Relations @((New-TestRelation 555 556 700 701))
        $result = Get-VsDependenceRelationChecks -SiteCode 'PS1' -Revisions $script:Revisions -Cache @{}
        @($result.Checks | Where-Object { $_.Code -eq 'references_unknown' -and $_.Blocking }).Count | Should -Be 1
    }

    It 'reports CI relations of foreign deployment types and groups, and broken relations' {
        $ci = @(
            [pscustomobject]@{ FromCIID = 801; ToCIID = 701; RelationType = 6; IsBroken = $false }
            [pscustomobject]@{ FromCIID = 900; ToCIID = 700; RelationType = 7; IsBroken = $false }
            [pscustomobject]@{ FromCIID = 777; ToCIID = 700; RelationType = 1; IsBroken = $false }
            [pscustomobject]@{ FromCIID = 700; ToCIID = 701; RelationType = 10; IsBroken = $true }
        )
        Set-TestForeignCim -CiRelations $ci
        $result = Get-VsCiRelationChecks -SiteCode 'PS1' -Revisions $script:Revisions -Cache @{} -Reported $null
        @($result.Checks | Where-Object { $_.Code -eq 'reverse_relation_foreign' -and $_.Reason -match "deployment-type 'Foreign tool DT' \(revision 4\).*type 6" }).Count | Should -Be 1
        @($result.Checks | Where-Object { $_.Code -eq 'application_group_member' -and $_.Reason -match 'Server baseline group' }).Count | Should -Be 1
        @($result.Checks | Where-Object { $_.Code -eq 'references_unknown' -and $_.Reason -match 'CI 777' }).Count | Should -Be 1
        @($result.Checks | Where-Object { $_.Code -eq 'ci_relation_broken' -and -not $_.Blocking }).Count | Should -Be 1
    }

    It 'does not report a CI relation twice that the dependence class already named' {
        Set-TestForeignCim -Relations @((New-TestRelation 800 801 700 701)) -CiRelations @([pscustomobject]@{ FromCIID = 801; ToCIID = 701; RelationType = 2; IsBroken = $false })
        $cache = @{}
        $dependence = Get-VsDependenceRelationChecks -SiteCode 'PS1' -Revisions $script:Revisions -Cache $cache
        $relations = Get-VsCiRelationChecks -SiteCode 'PS1' -Revisions $script:Revisions -Cache $cache -Reported $dependence.Reported
        @($relations.Checks).Count | Should -Be 0
    }

    It 'queries SMS_CIRelation by key in batches instead of reading the class' {
        $script:Filters = New-Object System.Collections.Generic.List[string]
        Mock Get-VsPreflightCim { $script:Filters.Add($Filter) }
        $ids = @(1..45 | ForEach-Object { [string](1000 + $_) })
        @(Get-VsPreflightCimByIds -SiteCode 'PS1' -ClassName 'SMS_CIRelation' -KeyName 'ToCIID' -Ids $ids).Count | Should -Be 0
        $script:Filters.Count | Should -Be 3
        $script:Filters[0] | Should -Match '\AToCIID = 1001 OR ToCIID = 1002 OR '
        { Get-VsPreflightCimByIds -SiteCode 'PS1' -ClassName 'SMS_CIRelation' -KeyName 'ToCIID' -Ids @("1 OR 1=1") } | Should -Throw '*unexpected CI_ID*'
    }
}

Describe 'MC02 deployment policy' {
    BeforeAll {
        function New-TestDeploymentRow {
            param($Id = 1, $Name = 'client_staticip', $Collection = 'PS100050', $Family = 'client_staticip', $Override = 'True', $Reboot = 'True', $Offer = '0', $Config = '1', $Enabled = 'True', $Soft = 'False', $Approval = 'False', $Deadline = '2026-10-01')
            [pscustomobject]@{
                Name = $Name; QueriedName = $Name; Source = 'name'; Family = $Family; AssignmentId = [string]$Id; CollectionId = $Collection; CollectionName = 'Core'
                OfferTypeId = $Offer; DesiredConfigType = $Config; OverrideServiceWindows = $Override; RebootOutsideOfServiceWindows = $Reboot
                Enabled = $Enabled; Deadline = $Deadline; SoftDeadlineEnabled = $Soft; OfferFlags = '0'; RequireApproval = $Approval
            }
        }
    }

    It 'passes a compliant managed Core deployment and names every deviation of a drifted one' {
        $ok = @(Get-VsDeploymentPolicyChecks -Deployments @((New-TestDeploymentRow)) -EntryName 'client_staticip' -EntryOwnership 'managed')
        @($ok | Where-Object { $_.Code -eq 'core_deployment_policy_ok' -and $_.Status -eq 'pass' }).Count | Should -Be 1
        $bad = @(Get-VsDeploymentPolicyChecks -Deployments @((New-TestDeploymentRow -Offer '2' -Config '2' -Override 'False' -Soft 'True' -Approval 'True' -Deadline '')) -EntryName 'client_staticip' -EntryOwnership 'managed')
        $drift = @($bad | Where-Object Code -eq 'core_deployment_policy_drift')
        $drift.Count | Should -Be 1
        foreach ($text in @('not Required', 'not an install deployment', 'no installation outside', 'soft deadline', 'requires approval', 'no enforcement deadline')) {
            $drift[0].Reason | Should -Match $text
        }
        $unknown = @(Get-VsDeploymentPolicyChecks -Deployments @((New-TestDeploymentRow -Reboot '')) -EntryName 'client_staticip' -EntryOwnership 'managed')
        @($unknown | Where-Object { $_.Code -eq 'core_deployment_fields_unknown' -and $_.Blocking }).Count | Should -Be 1
    }

    It 'leaves the Q1 rule open while the owner of the entry is unknown' {
        $rows = @(Get-VsDeploymentPolicyChecks -Deployments @((New-TestDeploymentRow -Override 'False')) -EntryName 'client_staticip' -EntryOwnership 'unknown')
        @($rows | Where-Object { $_.Code -eq 'deployment_owner_unknown' -and $_.Blocking }).Count | Should -Be 1
    }

    It 'warns when a maintenance window delays a deployment and no window fits the runtime' {
        $windows = @(
            [pscustomobject]@{ CollectionId = 'PS100050'; State = 'complete'; Detail = ''; Count = 1; LongestMinutes = '60' }
            [pscustomobject]@{ CollectionId = 'PS100051'; State = 'complete'; Detail = ''; Count = 2; LongestMinutes = '240' }
            [pscustomobject]@{ CollectionId = 'PS100052'; State = 'unknown'; Detail = 'settings not readable'; Count = 0; LongestMinutes = '' }
        )
        $deployments = @(
            (New-TestDeploymentRow -Id 1 -Name 'client_hostname' -Family 'client_hostname' -Collection 'PS100050' -Override 'False')
            (New-TestDeploymentRow -Id 2 -Name 'client_hostname' -Family 'client_hostname' -Collection 'PS100051')
        )
        $runtimes = @([pscustomobject]@{ Name = 'client_hostname'; Expected = '15'; State = 'drift'; Minutes = '120'; Detail = '' })
        $rows = @(Get-VsDeploymentPolicyChecks -Deployments $deployments -EntryName 'client_staticip' -EntryOwnership 'manual' -Windows $windows -Runtimes $runtimes)
        $delay = @($rows | Where-Object { $_.Code -eq 'maintenance_windows_delay' -and $_.Target -eq 'PS100050' })
        $delay.Count | Should -Be 1
        $delay[0].Blocking | Should -BeFalse
        $delay[0].Reason | Should -Match 'no window fits a maximum runtime of 120 min \(longest 60 min\)'
        @($rows | Where-Object { $_.Code -eq 'maintenance_windows_overridden' -and $_.Target -eq 'PS100051' }).Count | Should -Be 1
        @($rows | Where-Object { $_.Code -eq 'maintenance_windows_unknown' -and $_.Target -eq 'PS100052' -and $_.Blocking }).Count | Should -Be 1
        @($rows | Where-Object { $_.Code -eq 'maximum_runtime_drift' -and $_.Blocking }).Count | Should -Be 1
    }

    It 'reads windows from the lazy settings and ignores disabled ones' {
        Use-TestCim -Rows @{ SMS_CollectionSettings = @([pscustomobject]@{ CollectionID = 'PS100060'; ServiceWindows = $null }) } -Full @{
            'CollectionID=PS100060' = [pscustomobject]@{ CollectionID = 'PS100060'; ServiceWindows = @(
                [pscustomobject]@{ Name = 'Night'; Duration = 180; IsEnabled = $true }
                [pscustomobject]@{ Name = 'Old'; Duration = 600; IsEnabled = $false }
            ) }
        }
        $result = Read-VsCollectionServiceWindows -SiteCode 'PS1' -CollectionId 'PS100060'
        $result.State | Should -Be 'complete'
        $result.Count | Should -Be 1
        $result.LongestMinutes | Should -Be '180'
        (Read-VsCollectionServiceWindows -SiteCode 'PS1' -CollectionId 'PS100061').Count | Should -Be 0
    }

    It 'assigns versioned copies and legacy names to their phase, and nothing else' {
        $specs = @(Get-VsClientAppSpecs)
        $legacy = @((Get-VsClientPackagingPolicy).LegacyApplicationNames)
        Get-VsClientPhaseFamily -ApplicationName 'client_getInfos_v2.3' -Specs $specs -LegacyNames $legacy | Should -Be 'client_getInfos'
        Get-VsClientPhaseFamily -ApplicationName $legacy[1] -Specs $specs -LegacyNames $legacy | Should -Be 'client_getInfos'
        Get-VsClientPhaseFamily -ApplicationName 'client_Hostname' -Specs $specs -LegacyNames $legacy | Should -Be 'client_hostname'
        Get-VsClientPhaseFamily -ApplicationName 'client_staticip-test' -Specs $specs -LegacyNames $legacy | Should -Be 'client_staticip'
        Get-VsClientPhaseFamily -ApplicationName 'client_Set-VMDisksOnline' -Specs $specs -LegacyNames $legacy | Should -Be ''
        Get-VsClientPhaseFamily -ApplicationName 'client_staticipX' -Specs $specs -LegacyNames $legacy | Should -Be ''
    }

    It 'takes the maximum runtime from the install action, not the repair action' {
        $spec = @(Get-VsClientAppSpecs)[1]
        [xml]$xml = New-TestTypeXml -Script 'client_hostname.ps1' -InstallMinutes 15 -RepairMinutes 120
        @(Get-VsDeploymentTypeMaximumRuntimeArgs -Xml $xml) | Should -Be @('15')
        $type = [pscustomobject]@{ LocalizedDisplayName = 'client_hostname Deployment'; SDMPackageXML = $xml.OuterXml }
        $issues = @(Get-VsClientDeploymentTypeContractIssues -DeploymentType $type -Spec $spec -ContentLocation '\\SRV01\VirtuSphere\client_hostname' -InstallCommand 'x' -ReturnCodes @(Get-TestReturnCodes))
        $issues | Should -Not -Contain 'maximum-runtime'
        (Get-VsClientRuntimeState -Spec $spec -DeploymentType $type -Issues $issues).State | Should -Be 'ok'
    }
}
