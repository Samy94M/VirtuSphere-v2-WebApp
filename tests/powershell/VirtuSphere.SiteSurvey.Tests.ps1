BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:MecmRoot = Join-Path $script:RepoRoot 'Powershell-MECM/mecm'
    . (Join-Path $script:MecmRoot 'VirtuSphere-ClientPackaging.ps1')
    . (Join-Path $script:MecmRoot 'VirtuSphere-ClientPreflight.ps1')
    . (Join-Path $script:MecmRoot 'VirtuSphere-LegacyRetirement.ps1')
    . (Join-Path $script:MecmRoot 'VirtuSphere-SiteSurvey.ps1')
    $script:EntryScriptPath = Join-Path $script:RepoRoot 'Powershell-MECM/survey-VirtuSphere-MecmSite.ps1'

    # Stand-ins for the CM provider and Windows-only cmdlets; Pester mocks replace them per test.
    function Get-CMApplication { param($Name, [switch]$ShowHidden, [switch]$DisableWildcardHandling, [switch]$Fast, $ErrorAction) }
    function Get-CMDeploymentType { param($ApplicationName, $ErrorAction) }
    function Get-CMApplicationGroup { param([switch]$ShowHidden, $ErrorAction) }
    function Get-CMDeploymentTypeDependencyGroup { param($InputObject, $ErrorAction) }
    function Get-CMDeploymentTypeDependency { param($InputObject, $ErrorAction) }
    function Get-CMDeploymentTypeSupersedence { param($InputObject, $ErrorAction) }
    function Get-CMDistributionPointGroup { param($ErrorAction) }
    function Get-SmbShare { param($ErrorAction) }

    $script:ModelA = 'ScopeId_11111111-1111-1111-1111-111111111111/Application_aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'

    function Get-TestSiteResult {
        param($Config = $null)
        Get-VsSiteSurveyResult -Config $Config -Specs @(Get-VsClientAppSpecs) `
            -LegacyNames @((Get-VsClientPackagingPolicy).LegacyApplicationNames) `
            -InitializeSite { param($c) 'XYZ' } -ResolveProvider { param($code) [pscustomobject]@{ Source = 'local'; Computer = '' } } `
            -CurrentSids @('S-1-5-21-1-2-3-500') -ComputerNames @('SRV01', 'srv01.corp.example')
    }

    # A fresh site: the provider answers, but no VirtuSphere object exists.
    function Set-TestFreshSite {
        Mock Get-CMApplication { }
        Mock Get-CMDeploymentType { throw 'no object for this name' }
        Mock Get-CMDistributionPointGroup { }
        Mock Get-SmbShare { }
        Mock Get-CimClass { [pscustomobject]@{ CimClassProperties = @([pscustomobject]@{ Name = 'CI_ID'; CimType = 'UInt32'; Qualifiers = @([pscustomobject]@{ Name = 'key' }) }) } }
        Mock Get-CimInstance {
            if ($ClassName -ceq 'SMS_Site') { return [pscustomobject]@{ SiteCode = 'XYZ'; Version = '5.00.9135.1000' } }
        }
    }

    # Finds write operations in PowerShell source: denied command verbs and
    # mutating .NET member calls. The allowed exceptions are named, not inferred.
    function Get-TestWriteFindings {
        param([Parameter(Mandatory)][string]$Source)
        $tokens = $null
        $errors = $null
        $ast = [System.Management.Automation.Language.Parser]::ParseInput($Source, [ref]$tokens, [ref]$errors)
        $deniedVerbs = '\A(Set|New|Remove|Add|Invoke|Start|Stop|Suspend|Resume|Export|Copy|Move|Rename|Clear|Update|Enable|Disable|Register|Unregister|Out|Install|Uninstall|Grant|Revoke|Publish|Save|Restore|Reset|Unblock|Lock|Unlock|Mount|Dismount|Push|Pop)-'
        $allowed = @('New-Object', 'Set-StrictMode', 'Out-String', 'Push-Location', 'Pop-Location')
        $findings = New-Object System.Collections.Generic.List[string]
        foreach ($command in @($ast.FindAll({ param($node) $node -is [System.Management.Automation.Language.CommandAst] }, $true))) {
            $name = [string]$command.GetCommandName()
            if ($name -and $name -match $deniedVerbs -and $name -cnotin $allowed) { $findings.Add($name) }
        }
        foreach ($member in @($ast.FindAll({ param($node) $node -is [System.Management.Automation.Language.InvokeMemberExpressionAst] }, $true))) {
            $memberName = [string]$member.Member.Extent.Text
            if ($memberName -match '\A(Put|Delete|Create|Execute|Invoke|Set|Write)') { $findings.Add(('.' + $memberName)) }
        }
        $findings.ToArray()
    }
}

Describe 'Site survey value conversion' {
    It 'writes DateTime as ISO-8601 UTC and keeps numbers and booleans' {
        $local = New-Object DateTime 2026, 10, 15, 12, 30, 0, ([DateTimeKind]::Utc)
        ConvertTo-VsSurveyValue -Value $local | Should -Be '2026-10-15T12:30:00.0000000Z'
        ConvertTo-VsSurveyValue -Value 42 | Should -Be 42
        ConvertTo-VsSurveyValue -Value $true | Should -BeTrue
        ConvertTo-VsSurveyValue -Value $null | Should -BeNullOrEmpty
    }

    It 'keeps arrays as arrays, also with zero or one element' {
        $empty = ConvertTo-VsSurveyValue -Value @()
        , $empty | Should -BeOfType [object[]]
        @($empty).Count | Should -Be 0
        $one = ConvertTo-VsSurveyValue -Value @('a')
        , $one | Should -BeOfType [object[]]
        @($one).Count | Should -Be 1
    }

    It 'turns embedded objects into nested records and stops at the depth limit' {
        $record = ConvertTo-VsSurveyRecord -Object ([pscustomobject]@{ Window = [pscustomobject]@{ Name = 'MW'; Duration = 120 } })
        $record.Window.Name | Should -Be 'MW'
        $record.Window.Duration | Should -Be 120
        $deep = [pscustomobject]@{ L1 = [pscustomobject]@{ L2 = [pscustomobject]@{ L3 = [pscustomobject]@{ L4 = [pscustomobject]@{ L5 = [pscustomobject]@{ L6 = 'end' } } } } } }
        $converted = ConvertTo-VsSurveyRecord -Object $deep
        $converted.L1.L2.L3.L4.Contains('L5') | Should -BeTrue
        $converted.L1.L2.L3.L4.L5 | Should -BeOfType [string]
    }

    It 'redacts secret-like property names, also nested, and skips CIM system properties' {
        $record = ConvertTo-VsSurveyRecord -Object ([pscustomobject]@{ Name = 'x'; AdminPassword = 'p'; ClientSecret = 's'; PSComputerName = 'srv'; Inner = @{ Credential = 'c'; Plain = 'ok' } })
        $record.AdminPassword | Should -Be '[redacted]'
        $record.ClientSecret | Should -Be '[redacted]'
        $record.Contains('PSComputerName') | Should -BeFalse
        $record.Inner.Credential | Should -Be '[redacted]'
        $record.Inner.Plain | Should -Be 'ok'
    }

    It 'truncates long strings with a marker and honours an exclusion list' {
        $long = 'a' * ($script:VsSurveyMaxStringLength + 5)
        $text = Format-VsSurveyText -Text $long
        $text | Should -Match '\.\.\.\[truncated 5 chars\]\z'
        $text.Length | Should -BeLessThan ($long.Length + 40)
        $record = ConvertTo-VsSurveyRecord -Object ([pscustomobject]@{ Keep = 1; CollectionRules = 'x' }) -Exclude @('CollectionRules')
        @($record.Keys) | Should -Be @('Keep')
    }
}

Describe 'Site survey UNC parsing and local mapping' {
    It 'parses host, share and rest and ignores a trailing backslash' {
        $parsed = ConvertFrom-VsSurveyUncPath -Path '\\MECM01\VirtuSphere$\files\client_getInfos\'
        $parsed.Host | Should -Be 'MECM01'
        $parsed.Share | Should -Be 'VirtuSphere$'
        $parsed.Rest | Should -Be '\files\client_getInfos'
    }

    It 'rejects malformed paths and relative segments' {
        foreach ($path in @('', 'D:\files', '\\host', '\\host\', 'host\share', '//host/share', '\\host\share\..\other', '\\host\.\x', '\\-bad\share')) {
            ConvertFrom-VsSurveyUncPath -Path $path | Should -BeNullOrEmpty -Because $path
        }
    }

    It 'maps the local host by NetBIOS or FQDN name, case-insensitive, and nothing else' {
        $shares = @([pscustomobject]@{ Name = 'VirtuSphere'; Path = 'D:\VirtuSphere\Packages\' })
        $names = @('SRV01', 'srv01.corp.example')
        $netbios = Get-VsSurveyLocalPathForUnc -Path '\\srv01\virtusphere\files\a' -LocalShares $shares -LocalNames $names
        $netbios.IsLocal | Should -BeTrue
        $netbios.LocalPath | Should -Be 'D:\VirtuSphere\Packages\files\a'
        (Get-VsSurveyLocalPathForUnc -Path '\\SRV01.CORP.EXAMPLE\VirtuSphere\x' -LocalShares $shares -LocalNames $names).LocalPath | Should -Be 'D:\VirtuSphere\Packages\x'
        $foreign = Get-VsSurveyLocalPathForUnc -Path '\\other\VirtuSphere\x' -LocalShares $shares -LocalNames $names
        $foreign.Parsed | Should -BeTrue
        $foreign.IsLocal | Should -BeFalse
        $foreign.LocalPath | Should -Be ''
        $unknownShare = Get-VsSurveyLocalPathForUnc -Path '\\SRV01\missing\x' -LocalShares $shares -LocalNames $names
        $unknownShare.IsLocal | Should -BeTrue
        $unknownShare.LocalPath | Should -Be ''
        (Get-VsSurveyLocalPathForUnc -Path 'not-unc' -LocalShares $shares -LocalNames $names).Parsed | Should -BeFalse
    }

    It 'reads every content location from a namespaced manifest' {
        $xml = '<AppMgmtDigest xmlns="http://schemas.microsoft.com/SystemCenterConfigurationManager/2009/AppMgmtDigest"><DeploymentType><Installer><Contents><Content><Location>\\SRV01\VirtuSphere\a\</Location></Content><Content><Location>\\SRV01\VirtuSphere\b\</Location></Content></Contents></Installer></DeploymentType></AppMgmtDigest>'
        @(Get-VsSurveyContentLocations -Xml $xml) | Should -Be @('\\SRV01\VirtuSphere\a\', '\\SRV01\VirtuSphere\b\')
        @(Get-VsSurveyContentLocations -Xml '').Count | Should -Be 0
    }

    It 'selects rows by any matching property and returns nothing for an empty key set' {
        $rows = @([pscustomobject]@{ FromApplicationCIID = 5; ToApplicationCIID = 9 }, [pscustomobject]@{ FromApplicationCIID = 7; ToApplicationCIID = 8 })
        @(Select-VsSurveyRowsReferencing -Rows $rows -Values @('9') -NamePattern '(?i)CI_?ID').Count | Should -Be 1
        @(Select-VsSurveyRowsReferencing -Rows $rows -Values @() -NamePattern '(?i)CI_?ID').Count | Should -Be 0
    }
}

Describe 'Site survey progress and section frame' {
    It 'formats the repository progress line' {
        Format-VsSurveyProgress -Position 3 -Total 11 -Phase 'RUN' -Unit 'applications' | Should -Be '[3/11] RUN applications'
    }

    It 'reports RUN before and the status after each section and keeps failures local' {
        $script:lines = New-Object System.Collections.Generic.List[string]
        Mock Write-Host { $script:lines.Add([string]$Object) }
        $ok = Get-VsSurveySection -Name 'a' -Position 1 -Total 4 -Body { param($x) @{ v = $x } } -Argument 7
        $partial = Get-VsSurveySection -Name 'b' -Position 2 -Total 4 -Body { Write-VsSurveyNote -Text 'one read failed'; 1 }
        $failed = Get-VsSurveySection -Name 'c' -Position 3 -Total 4 -Body { throw 'boom' }
        Mock Get-CimClass { throw 'Invalid class' }
        $absent = Get-VsSurveySection -Name 'd' -Position 4 -Total 4 -Body { param($c) Get-VsSurveyClassSchemas -Context $c } -Argument @{ Namespace = 'rootSMSsite_XYZ'; CimComputer = '' }
        $ok.status | Should -Be 'ok'
        $ok.data.v | Should -Be 7
        $partial.status | Should -Be 'partial'
        $partial.error | Should -Be 'one read failed'
        $failed.status | Should -Be 'failed'
        $failed.error | Should -Be 'boom'
        $failed.data | Should -BeNullOrEmpty
        $absent.status | Should -Be 'absent'
        @($script:lines) | Should -Be @('[1/4] RUN a', '[1/4] ok a', '[2/4] RUN b', '[2/4] partial b', '[3/4] RUN c', '[3/4] failed c', '[4/4] RUN d', '[4/4] absent d')
    }
}

Describe 'Site survey run' {
    BeforeEach {
        Mock Write-Host { }
    }

    It 'exits 2 without any MECM query when no site code is found' {
        Mock Get-CimInstance { throw 'must not be queried' }
        Mock Get-CMApplication { throw 'must not be queried' }
        $result = Get-VsSiteSurveyResult -Config $null -Specs @(Get-VsClientAppSpecs) -LegacyNames @((Get-VsClientPackagingPolicy).LegacyApplicationNames) `
            -InitializeSite { param($c) $null } -ResolveProvider { throw 'must not be resolved' }
        $result.ExitCode | Should -Be 2
        $result.Report | Should -BeNullOrEmpty
        Should -Invoke Get-CimInstance -Times 0 -Exactly
        Should -Invoke Get-CMApplication -Times 0 -Exactly
    }

    It 'reports a fresh site without VirtuSphere registry and objects as ok with empty lists' {
        Set-TestFreshSite
        $result = Get-TestSiteResult
        $result.ExitCode | Should -Be 0
        $report = $result.Report
        $report.schema | Should -Be 'virtusphere.mecm-site-survey/1'
        $report.site.hasVirtuSphereRegistry | Should -BeFalse
        $report.site.targetApplicationCount | Should -Be 0
        @($report.sections.Keys) | Should -Be $script:VsSurveySectionNames
        foreach ($name in @($report.sections.Keys)) { $report.sections[$name].status | Should -Be 'ok' -Because $name }
        $report.sections.sourceMapping.data.registry.status | Should -Be 'absent'
        @($report.sections.deployments.data.rows).Count | Should -Be 0
        @($report.sections.applications.data.targets) | Should -Contain 'client_getInfos'
        Should -Invoke Get-CMDeploymentType -Times 0 -Exactly
        $json = ConvertTo-Json -InputObject $report -Depth 16
        ($json | ConvertFrom-Json).site.siteCode | Should -Be 'XYZ'
    }

    It 'keeps going when one section throws and marks dependent sections as incomplete' {
        Set-TestFreshSite
        Mock Get-VsSurveyDeployments { throw 'provider timeout' }
        $result = Get-TestSiteResult
        $result.ExitCode | Should -Be 0
        $sections = $result.Report.sections
        $sections.deployments.status | Should -Be 'failed'
        $sections.deployments.error | Should -Be 'provider timeout'
        $sections.collections.status | Should -Be 'partial'
        $sections.collections.error | Should -Match 'deployments'
        $sections.legacy.status | Should -Be 'ok'
        { ConvertTo-Json -InputObject $result.Report -Depth 16 | ConvertFrom-Json } | Should -Not -Throw
    }

    It 'reports only the four documented registry values when VirtuSphere is installed' {
        Set-TestFreshSite
        $config = [pscustomobject]@{ WebApi = 'portal.lan:8021'; Scheme = 'https'; PackagesShare = '\\SRV01\VirtuSphere\files'; LogRoot = 'C:\Logs'; ReportToken = 'token-value'; CertThumbprint = 'ABC' }
        $registry = (Get-TestSiteResult -Config $config).Report.sections.sourceMapping.data.registry
        $registry.status | Should -Be 'ok'
        @($registry.Keys) | Should -Be @('status', 'PackagesShare', 'LogRoot', 'VirtuSphere_WebAPI', 'Scheme')
        (Get-TestSiteResult -Config $config).Report.site.hasVirtuSphereRegistry | Should -BeTrue
    }

    It 'follows an installed application to deployments, limiting chain, scopes, folders and its local content path' {
        Set-TestFreshSite
        $script:Xml = '<AppMgmtDigest xmlns="urn:x"><Content><Location>\\SRV01\VirtuSphere\files\client_getInfos\</Location></Content></AppMgmtDigest>'
        Mock Get-CMApplication {
            if ($Name -ceq 'client_getInfos' -or $Name -ceq 'client_*') {
                [pscustomobject]@{ LocalizedDisplayName = 'client_getInfos'; CI_ID = 16777300; ModelName = $script:ModelA; SDMPackageXML = '<App/>' }
            }
        }
        Mock Get-CMDeploymentType { [pscustomobject]@{ LocalizedDisplayName = 'client_getInfos Deployment'; CI_ID = 16777301; SDMPackageXML = $script:Xml } }
        Mock Get-CMDeploymentTypeDependencyGroup { }
        Mock Get-CMDeploymentTypeSupersedence { }
        Mock Get-CMApplicationGroup { }
        Mock Get-SmbShare { [pscustomobject]@{ Name = 'VirtuSphere'; Path = 'D:\VirtuSphere\Packages'; Description = ''; ScopeName = '*'; Special = $false } }
        Mock Test-Path { $true }
        # The real Get-CimInstance binds -InputObject as CimInstance; the lazy reload is mocked at its own seam.
        Mock Get-VsSurveyCimFull { $InputObject }
        Mock Get-CimInstance {
            switch ($ClassName) {
                'SMS_Site' { [pscustomobject]@{ SiteCode = 'XYZ' } }
                'SMS_ApplicationAssignment' { [pscustomobject]@{ ApplicationName = 'client_getInfos'; AssignmentID = 16777220; TargetCollectionID = 'XYZ00013' }; [pscustomobject]@{ ApplicationName = 'foreign'; AssignmentID = 1; TargetCollectionID = 'XYZ00099' } }
                'SMS_Collection' {
                    if ($Filter -ceq "CollectionID = 'XYZ00013'") { [pscustomobject]@{ CollectionID = 'XYZ00013'; Name = 'Deploy Windows 2022'; LimitToCollectionID = 'SMS00001'; CollectionRules = 'lazy' } }
                    elseif ($Filter -ceq "CollectionID = 'SMS00001'") { [pscustomobject]@{ CollectionID = 'SMS00001'; Name = 'All Systems'; LimitToCollectionID = '' } }
                }
                'SMS_SecuredCategory' { [pscustomobject]@{ CategoryID = 'SMS00UNA'; CategoryName = 'Default' } }
                'SMS_SecuredCategoryMembership' { if ($Filter -match 'XYZ00013') { [pscustomobject]@{ CategoryID = 'SMS00UNA'; ObjectKey = 'XYZ00013'; ObjectTypeID = 1 } } }
                'SMS_Admin' { [pscustomobject]@{ AdminSid = 'S-1-5-21-1-2-3-500'; LogonName = 'CORP\admin' }; [pscustomobject]@{ AdminSid = 'S-1-5-21-9'; LogonName = 'CORP\other' } }
                'SMS_ObjectContainerItem' { [pscustomobject]@{ InstanceKey = $script:ModelA; ContainerNodeID = 16777250 } }
                'SMS_ObjectContainerNode' { if ($Filter -ceq 'ContainerNodeID = 16777250') { [pscustomobject]@{ ContainerNodeID = 16777250; Name = 'VirtuSphere_Core' } } }
            }
        }
        $report = (Get-TestSiteResult).Report
        foreach ($name in @($report.sections.Keys)) { $report.sections[$name].status | Should -Be 'ok' -Because ('{0}: {1}' -f $name, $report.sections[$name].error) }
        $report.site.targetApplicationCount | Should -Be 1
        @($report.sections.deployments.data.rows).Count | Should -Be 1
        $collections = @($report.sections.collections.data.rows)
        @($collections | ForEach-Object { $_.collectionId }) | Should -Be @('XYZ00013', 'SMS00001')
        $collections[0].reasons | Should -Be @('deployment-target')
        $collections[1].reasons | Should -Be @('limiting-chain')
        $collections[0].collection.Contains('CollectionRules') | Should -BeFalse
        @($report.sections.securityScopes.data.memberships).Count | Should -Be 1
        @($report.sections.securityScopes.data.currentAdmins | ForEach-Object { $_.LogonName }) | Should -Be @('CORP\admin')
        @($report.sections.folders.data.nodes | ForEach-Object { $_.Name }) | Should -Be @('VirtuSphere_Core')
        $location = @($report.sections.sourceMapping.data.contentLocations)[0]
        $location.isLocalHost | Should -BeTrue
        $location.localPath | Should -Be 'D:\VirtuSphere\Packages\files\client_getInfos'
        $location.uncReachable | Should -BeTrue
        { ConvertTo-Json -InputObject $report -Depth 16 | ConvertFrom-Json } | Should -Not -Throw
    }

    It 'stops a cyclic limiting chain' {
        Set-TestFreshSite
        Mock Get-CMApplication { if ($Name -ceq 'client_getInfos') { [pscustomobject]@{ LocalizedDisplayName = 'client_getInfos'; CI_ID = 1; ModelName = $script:ModelA } } }
        Mock Get-CMDeploymentType { }
        Mock Get-CimInstance {
            switch ($ClassName) {
                'SMS_ApplicationAssignment' { [pscustomobject]@{ ApplicationName = 'client_getInfos'; TargetCollectionID = 'XYZ00001' } }
                'SMS_Collection' {
                    if ($Filter -ceq "CollectionID = 'XYZ00001'") { [pscustomobject]@{ CollectionID = 'XYZ00001'; LimitToCollectionID = 'XYZ00002' } }
                    elseif ($Filter -ceq "CollectionID = 'XYZ00002'") { [pscustomobject]@{ CollectionID = 'XYZ00002'; LimitToCollectionID = 'XYZ00001' } }
                }
            }
        }
        $rows = @((Get-TestSiteResult).Report.sections.collections.data.rows)
        @($rows | ForEach-Object { $_.collectionId }) | Should -Be @('XYZ00001', 'XYZ00002')
    }
}

Describe 'Site survey read-only boundary' {
    It 'finds no write operation in the survey files the entry script loads' {
        $tokens = $null
        $errors = $null
        $entryAst = [System.Management.Automation.Language.Parser]::ParseFile($script:EntryScriptPath, [ref]$tokens, [ref]$errors)
        $loaded = @($entryAst.FindAll({ param($node) $node -is [System.Management.Automation.Language.CommandAst] -and $node.InvocationOperator -eq [System.Management.Automation.Language.TokenKind]::Dot }, $true) |
            ForEach-Object { [regex]::Match($_.Extent.Text, "'mecm\\([^']+)'").Groups[1].Value } |
            Where-Object { $_ -match 'SiteSurvey' } |
            ForEach-Object { Join-Path $script:MecmRoot $_ })
        $files = @($script:EntryScriptPath) + $loaded
        $files.Count | Should -Be 2
        foreach ($path in $files) {
            Test-Path -LiteralPath $path | Should -BeTrue
            $findings = @(Get-TestWriteFindings -Source (Get-Content -LiteralPath $path -Raw))
            ($findings -join ', ') | Should -BeNullOrEmpty -Because $path
        }
    }

    It 'catches a write cmdlet and a mutating member call (mutants)' {
        $module = Get-Content -LiteralPath (Join-Path $script:MecmRoot 'VirtuSphere-SiteSurvey.ps1') -Raw
        @(Get-TestWriteFindings -Source ($module + "`nfunction Get-X { Set-CimInstance -InputObject `$x -Property @{ A = 1 } }")) | Should -Contain 'Set-CimInstance'
        @(Get-TestWriteFindings -Source ($module + "`nfunction Get-Y { `$wmi.Put() }")) | Should -Contain '.Put'
        @(Get-TestWriteFindings -Source ($module + "`nfunction Get-Z { Invoke-CimMethod -ClassName X -MethodName Y }")) | Should -Contain 'Invoke-CimMethod'
    }

    It 'loads the survey module without side effects' {
        $source = Get-Content -LiteralPath (Join-Path $script:MecmRoot 'VirtuSphere-SiteSurvey.ps1') -Raw
        $tokens = $null
        $errors = $null
        $ast = [System.Management.Automation.Language.Parser]::ParseInput($source, [ref]$tokens, [ref]$errors)
        @($errors).Count | Should -Be 0
        $topLevel = @($ast.EndBlock.Statements | Where-Object { $_ -isnot [System.Management.Automation.Language.FunctionDefinitionAst] })
        foreach ($statement in $topLevel) {
            $statement.Extent.Text | Should -Match '\A(\$script:VsSurvey[A-Za-z]+ = |Set-StrictMode )' -Because 'only constants and strict mode at import'
        }
    }
}
