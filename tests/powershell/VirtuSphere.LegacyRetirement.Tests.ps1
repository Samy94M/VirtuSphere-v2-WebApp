BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:MecmRoot = Join-Path $script:RepoRoot 'Powershell-MECM/mecm'
    . (Join-Path $script:MecmRoot 'VirtuSphere-ClientPackaging.ps1')
    . (Join-Path $script:MecmRoot 'VirtuSphere-ClientPreflight.ps1')
    . (Join-Path $script:MecmRoot 'VirtuSphere-LegacyRetirement.ps1')
    $script:EntryScriptPath = Join-Path $script:RepoRoot 'Powershell-MECM/retire-VirtuSphere-LegacyGetInfo.ps1'
    $script:ModulePath = Join-Path $script:MecmRoot 'VirtuSphere-LegacyRetirement.ps1'

    # Stand-ins for the CM provider; Pester mocks replace them per test.
    function Get-CMApplication { param($Name, [switch]$ShowHidden, [switch]$DisableWildcardHandling, [switch]$Fast, $ErrorAction) }
    function Get-CMApplicationDeployment { param($Name, $ErrorAction) }
    function Get-CMDeploymentType { param($ApplicationName, $ErrorAction) }
    function Get-CMApplicationGroup { param([switch]$ShowHidden, $ErrorAction) }
    function Get-CMDeployment { param($SoftwareName, $FeatureType, [switch]$DisableWildcardHandling, $ErrorAction) }
    function Get-CMDeploymentTypeDependencyGroup { param($InputObject, $ErrorAction) }
    function Get-CMDeploymentTypeDependency { param($InputObject, $ErrorAction) }

    $script:ModelA = 'ScopeId_11111111-1111-1111-1111-111111111111/Application_aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'
    $script:ModelB = 'ScopeId_11111111-1111-1111-1111-111111111111/Application_bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'

    function New-TestEvidence {
        param(
            [string]$Name = 'client_getinfo',
            [string]$ModelName = $script:ModelA,
            [string]$CiId = '101',
            [object[]]$Deployments = @(),
            [hashtable]$Counters = @{}
        )
        $values = @{ IsExpired = 'False'; NumberOfDeployments = [string]@($Deployments).Count; NumberOfDependentDTs = '0'; NumberOfDependentTS = '0'; IsSuperseded = 'False'; IsSuperseding = 'False' }
        foreach ($key in $Counters.Keys) { $values[$key] = $Counters[$key] }
        return [pscustomobject]@{
            Name = $Name; State = 'visible'; Detail = ''
            CiId = $CiId; ModelName = $ModelName; LogicalName = (Get-VsLegacyLogicalName -ModelName $ModelName); SecurityScopes = @('Default')
            Counters = [pscustomobject]$values; DevicesWithApp = '57'
            Deployments = @($Deployments); DeploymentTypeIds = @('9' + $CiId)
            ContentReadable = $true; ContentFiles = @('client_getinfo.ps1'); ContentLocations = @('\\mecm\src\client_getinfo'); DetectsSetupState = $true; ScriptVersion = 'V22'
            DependentManagedApps = @()
        }
    }
    function New-TestDeployment { param([string]$Id = '16777220', [string]$Enabled = 'True') [pscustomobject]@{ AssignmentId = $Id; CollectionId = 'PS100013'; CollectionName = 'Deploy Windows 2022'; Enabled = $Enabled; OfferTypeId = '0' } }
    function New-TestCore { param([bool]$Live = $true) [pscustomobject]@{ Live = $Live; Reasons = @($(if ($Live) { } else { 'core_entry_not_deployed' })); SuccessCount = $(if ($Live) { 3 } else { 0 }); Detail = '' } }
    function New-TestGroups { param([string]$State = 'complete', [hashtable]$Members = @{}) [pscustomobject]@{ State = $State; GroupCount = 1; Members = $Members; Detail = '' } }
    function New-TestSimilar { param([string[]]$Names = @(), [string]$State = 'complete') [pscustomobject]@{ State = $State; Names = @($Names); Detail = '' } }
    function New-TestPlan {
        param([object[]]$Applications, [bool]$Live = $true, $Groups = (New-TestGroups), $Similar = (New-TestSimilar))
        Get-VsLegacyRetirementPlan -Applications $Applications -CoreChain (New-TestCore -Live $Live) -Groups $Groups -SimilarNames $Similar
    }
    function Get-TestItem { param($Plan, [string]$Stage, [string]$Name) @($Plan.Items | Where-Object { $_.Stage -ceq $Stage -and $_.Name -ceq $Name }) }
}

Describe 'MC04 legacy getinfo retirement plan' {
    It 'marks an unused legacy application ready for Retire even before the cutover' {
        $plan = New-TestPlan -Applications @(New-TestEvidence) -Live $false
        $retire = @(Get-TestItem -Plan $plan -Stage 'Retire' -Name 'client_getinfo')
        $retire.Count | Should -Be 1
        $retire[0].State | Should -Be 'ready'
        $retire[0].RequiresConvergenceConfirmation | Should -BeFalse
        @($plan.Items | Where-Object Stage -eq 'DisableDeployments').Count | Should -Be 0
        $plan.PlanId | Should -Match '\A[0-9a-f]{64}\z'
    }

    It 'blocks disabling an active legacy deployment until the new chain demonstrably runs' {
        $evidence = New-TestEvidence -Deployments @(New-TestDeployment)
        $blocked = New-TestPlan -Applications @($evidence) -Live $false
        (Get-TestItem -Plan $blocked -Stage 'DisableDeployments' -Name 'client_getinfo')[0].Blockers | Should -Be @('core_chain_not_live')
        (Get-TestItem -Plan $blocked -Stage 'Retire' -Name 'client_getinfo')[0].Blockers | Should -Contain 'legacy_deployment_enabled'
        $live = New-TestPlan -Applications @($evidence) -Live $true
        $disable = Get-TestItem -Plan $live -Stage 'DisableDeployments' -Name 'client_getinfo'
        $disable[0].State | Should -Be 'ready'
        $disable[0].AssignmentId | Should -Be '16777220'
        $disable[0].CollectionId | Should -Be 'PS100013'
    }

    It 'blocks Retire for <Case>' -ForEach @(
        @{ Case = 'a dependent deployment type such as client_hostname'; Counters = @{ NumberOfDependentDTs = '1' }; Expected = 'legacy_dependents_present' }
        @{ Case = 'a task sequence reference'; Counters = @{ NumberOfDependentTS = '2' }; Expected = 'legacy_task_sequence_reference' }
        @{ Case = 'an application superseding it'; Counters = @{ IsSuperseded = 'True' }; Expected = 'legacy_supersedence_present' }
        @{ Case = 'its own supersedence'; Counters = @{ IsSuperseding = 'True' }; Expected = 'legacy_supersedence_present' }
        @{ Case = 'a missing provider counter'; Counters = @{ NumberOfDependentTS = '' }; Expected = 'legacy_counters_unknown' }
        @{ Case = 'an unparsable provider counter'; Counters = @{ IsExpired = 'maybe' }; Expected = 'legacy_counters_unknown' }
        @{ Case = 'deployments the caller cannot see'; Counters = @{ NumberOfDeployments = '2' }; Expected = 'legacy_deployments_not_fully_visible' }
    ) {
        $plan = New-TestPlan -Applications @(New-TestEvidence -Counters $Counters)
        $retire = Get-TestItem -Plan $plan -Stage 'Retire' -Name 'client_getinfo'
        $retire[0].State | Should -Be 'blocked'
        $retire[0].Blockers | Should -Contain $Expected
    }

    It 'blocks Retire for application-group membership, an unreadable group scan and an unparsed identity' {
        $logicalName = Get-VsLegacyLogicalName -ModelName $script:ModelA
        $member = New-TestPlan -Applications @(New-TestEvidence) -Groups (New-TestGroups -Members @{ $logicalName = @('Server-Basis') })
        (Get-TestItem -Plan $member -Stage 'Retire' -Name 'client_getinfo')[0].Blockers | Should -Be @('legacy_application_group_member')
        $unknown = New-TestPlan -Applications @(New-TestEvidence) -Groups (New-TestGroups -State 'unknown')
        (Get-TestItem -Plan $unknown -Stage 'Retire' -Name 'client_getinfo')[0].Blockers | Should -Be @('application_group_scan_unknown')
        $unparsed = New-TestPlan -Applications @(New-TestEvidence -ModelName 'odd-model')
        (Get-TestItem -Plan $unparsed -Stage 'Retire' -Name 'client_getinfo')[0].Blockers | Should -Be @('legacy_identity_unparsed')
    }

    It 'does not invent a group membership from a missing hashtable key' {
        $plan = New-TestPlan -Applications @(New-TestEvidence) -Groups (New-TestGroups -Members @{})
        (Get-TestItem -Plan $plan -Stage 'Retire' -Name 'client_getinfo')[0].State | Should -Be 'ready'
    }

    It 'treats an unreadable deployment state as a blocker instead of disabled' {
        $plan = New-TestPlan -Applications @(New-TestEvidence -Deployments @(New-TestDeployment -Enabled ''))
        (Get-TestItem -Plan $plan -Stage 'Retire' -Name 'client_getinfo')[0].Blockers | Should -Contain 'legacy_deployment_state_unknown'
        @($plan.Items | Where-Object Stage -eq 'DisableDeployments').Count | Should -Be 0
    }

    It 'requires convergence confirmation only when the application had deployments' {
        $plan = New-TestPlan -Applications @(New-TestEvidence -Deployments @(New-TestDeployment -Enabled 'False'))
        $retire = Get-TestItem -Plan $plan -Stage 'Retire' -Name 'client_getinfo'
        $retire[0].State | Should -Be 'ready'
        $retire[0].RequiresConvergenceConfirmation | Should -BeTrue
    }

    It 'reports an already retired application without a new Retire step' {
        $plan = New-TestPlan -Applications @(New-TestEvidence -Deployments @(New-TestDeployment -Enabled 'False') -Counters @{ IsExpired = 'True' })
        @($plan.Items).Count | Should -Be 0
        @($plan.Findings | Where-Object { $_.Code -eq 'legacy_already_retired' -and -not $_.Blocking }).Count | Should -Be 1
    }

    It 'turns invisible, ambiguous and unreadable legacy objects into findings, never into steps' {
        $applications = @(
            [pscustomobject]@{ Name = 'client_getinfo'; State = 'not-visible'; Detail = '' }
            [pscustomobject]@{ Name = 'client_getinfo_2.1'; State = 'ambiguous'; Detail = '2 applications' }
            [pscustomobject]@{ Name = 'client_getinfo_x'; State = 'unknown'; Detail = 'provider down' }
        )
        $plan = New-TestPlan -Applications $applications -Similar (New-TestSimilar -Names @('Client_GetInfo_3'))
        @($plan.Items).Count | Should -Be 0
        @($plan.Findings | ForEach-Object { '{0}:{1}:{2}' -f $_.Code, $_.Target, $_.Blocking }) | Should -Be @(
            'legacy_ambiguous:client_getinfo_2.1:True'
            'legacy_inventory_unknown:client_getinfo_x:True'
            'legacy_not_visible:client_getinfo:False'
            'legacy_similar_name:Client_GetInfo_3:False'
        )
    }

    It 'keeps the plan id for informational changes and changes it for decision-relevant ones' {
        $first = New-TestPlan -Applications @(New-TestEvidence)
        $informational = New-TestEvidence
        $informational.DevicesWithApp = '58'
        $informational.ScriptVersion = 'V23'
        $informational.ContentFiles = @('client_getinfo.ps1', 'bootstrap.json')
        (New-TestPlan -Applications @($informational)).PlanId | Should -Be $first.PlanId
        (New-TestPlan -Applications @(New-TestEvidence -Counters @{ NumberOfDependentDTs = '1' })).PlanId | Should -Not -Be $first.PlanId
        (New-TestPlan -Applications @(New-TestEvidence -CiId '102')).PlanId | Should -Not -Be $first.PlanId
    }
}

Describe 'MC04 retirement stage execution' {
    BeforeEach {
        # A small in-memory site: the handlers mutate it and ReadApplication
        # returns fresh evidence from it, like the real provider would.
        $script:Site = @{
            'client_getinfo' = New-TestEvidence -Name 'client_getinfo' -ModelName $script:ModelA -CiId '101'
            'client_getinfo_2.1' = New-TestEvidence -Name 'client_getinfo_2.1' -ModelName $script:ModelB -CiId '201' -Counters @{ NumberOfDependentDTs = '1' }
        }
        $script:Journal = New-Object System.Collections.Generic.List[object]
        $script:Writes = New-Object System.Collections.Generic.List[string]
        $script:ReadApplication = { param($name) $script:Site[$name] }
        $script:DisableDeployment = {
            param($item)
            $script:Writes.Add('disable:' + $item.AssignmentId)
            foreach ($deployment in $script:Site[$item.Name].Deployments) { if ($deployment.AssignmentId -eq $item.AssignmentId) { $deployment.Enabled = 'False' } }
        }
        $script:RetireApplication = {
            param($item)
            $script:Writes.Add('retire:' + $item.CiId)
            $script:Site[$item.Name].Counters.IsExpired = 'True'
        }
        $script:WriteJournal = { param($entry) $script:Journal.Add($entry) }
    }

    It 'refuses a plan id that no longer matches the fresh read and writes nothing' {
        $plan = New-TestPlan -Applications @($script:Site['client_getinfo'])
        { Invoke-VsLegacyRetirementStage -Plan $plan -Stage Retire -ApprovedPlanId ('0' * 64) -ReadApplication $script:ReadApplication `
            -DisableDeployment $script:DisableDeployment -RetireApplication $script:RetireApplication -WriteJournal $script:WriteJournal -Confirm:$false } |
            Should -Throw '*Vorschau*'
        $script:Writes.Count | Should -Be 0
        $script:Journal.Count | Should -Be 0
    }

    It 'writes nothing under -WhatIf' {
        $plan = New-TestPlan -Applications @($script:Site['client_getinfo'], $script:Site['client_getinfo_2.1'])
        $results = @(Invoke-VsLegacyRetirementStage -Plan $plan -Stage Retire -ApprovedPlanId $plan.PlanId -ReadApplication $script:ReadApplication `
            -DisableDeployment $script:DisableDeployment -RetireApplication $script:RetireApplication -WriteJournal $script:WriteJournal -WhatIf)
        @($results | ForEach-Object Result) | Should -Be @('not-confirmed', 'blocked')
        $script:Writes.Count | Should -Be 0
        $script:Journal.Count | Should -Be 0
    }

    It 'retires only the unused application, journals it and verifies by re-reading' {
        $plan = New-TestPlan -Applications @($script:Site['client_getinfo'], $script:Site['client_getinfo_2.1'])
        $output = @(Invoke-VsLegacyRetirementStage -Plan $plan -Stage Retire -ApprovedPlanId $plan.PlanId -ReadApplication $script:ReadApplication `
            -DisableDeployment $script:DisableDeployment -RetireApplication $script:RetireApplication -WriteJournal $script:WriteJournal -Confirm:$false 6>&1)
        $results = @($output | Where-Object { $_ -isnot [System.Management.Automation.InformationRecord] })
        $lines = @($output | Where-Object { $_ -is [System.Management.Automation.InformationRecord] } | ForEach-Object { [string]$_.MessageData })
        @($results | ForEach-Object { '{0}:{1}' -f $_.Item.Name, $_.Result }) | Should -Be @('client_getinfo:done', 'client_getinfo_2.1:blocked')
        @($results[1].Reasons) | Should -Be @('legacy_dependents_present')
        @($script:Writes) | Should -Be @('retire:101')
        @($script:Journal | ForEach-Object { '{0}:{1}' -f $_.Phase, $_.CiId }) | Should -Be @('intent:101', 'outcome:101')
        $script:Journal[1].Result | Should -Be 'done'
        $lines | Should -Contain '[0/2] RUN legacy-retirement Retire'
        $lines | Should -Contain '[1/2] RUN client_getinfo application 101'
        $lines | Should -Contain '[1/2] pass client_getinfo application 101'
        $lines | Should -Contain '[2/2] skip client_getinfo_2.1 application 201 (legacy_dependents_present)'
    }

    It 'stops before the write when the state changed after the preview' {
        $plan = New-TestPlan -Applications @($script:Site['client_getinfo'])
        $script:Site['client_getinfo'].Counters.NumberOfDependentDTs = '1'
        $results = @(Invoke-VsLegacyRetirementStage -Plan $plan -Stage Retire -ApprovedPlanId $plan.PlanId -ReadApplication $script:ReadApplication `
            -DisableDeployment $script:DisableDeployment -RetireApplication $script:RetireApplication -WriteJournal $script:WriteJournal -Confirm:$false)
        $results[0].Result | Should -Be 'failed'
        $results[0].Error | Should -Match 'legacy_dependents_present'
        $script:Writes.Count | Should -Be 0
        @($script:Journal | ForEach-Object Phase) | Should -Be @('outcome')
    }

    It 'fails on a write that the re-read does not confirm and stops the stage' {
        $script:Site['client_getinfo_2.1'].Counters.NumberOfDependentDTs = '0'
        $plan = New-TestPlan -Applications @($script:Site['client_getinfo'], $script:Site['client_getinfo_2.1'])
        $noEffect = { param($item) $script:Writes.Add('retire:' + $item.CiId) }
        $results = @(Invoke-VsLegacyRetirementStage -Plan $plan -Stage Retire -ApprovedPlanId $plan.PlanId -ReadApplication $script:ReadApplication `
            -DisableDeployment $script:DisableDeployment -RetireApplication $noEffect -WriteJournal $script:WriteJournal -Confirm:$false)
        $results.Count | Should -Be 1
        $results[0].Result | Should -Be 'failed'
        $results[0].Error | Should -Match 'Rueckleseprobe'
        @($script:Writes) | Should -Be @('retire:101')
        @($script:Journal | ForEach-Object { '{0}:{1}' -f $_.Phase, $_.Result }) | Should -Be @('intent:', 'outcome:failed')
    }

    It 'asks for policy convergence confirmation after disabled deployments' {
        $script:Site['client_getinfo'] = New-TestEvidence -Deployments @(New-TestDeployment -Enabled 'False')
        $plan = New-TestPlan -Applications @($script:Site['client_getinfo'])
        $withoutConfirmation = @(Invoke-VsLegacyRetirementStage -Plan $plan -Stage Retire -ApprovedPlanId $plan.PlanId -ReadApplication $script:ReadApplication `
            -DisableDeployment $script:DisableDeployment -RetireApplication $script:RetireApplication -WriteJournal $script:WriteJournal -Confirm:$false)
        $withoutConfirmation[0].Result | Should -Be 'blocked'
        @($withoutConfirmation[0].Reasons) | Should -Be @('policy_convergence_unconfirmed')
        $script:Writes.Count | Should -Be 0
        $confirmed = @(Invoke-VsLegacyRetirementStage -Plan $plan -Stage Retire -ApprovedPlanId $plan.PlanId -PolicyConvergenceConfirmed -ReadApplication $script:ReadApplication `
            -DisableDeployment $script:DisableDeployment -RetireApplication $script:RetireApplication -WriteJournal $script:WriteJournal -Confirm:$false)
        $confirmed[0].Result | Should -Be 'done'
    }

    It 'reports an item that is already done without writing again' {
        $plan = New-TestPlan -Applications @($script:Site['client_getinfo'])
        $script:Site['client_getinfo'].Counters.IsExpired = 'True'
        $results = @(Invoke-VsLegacyRetirementStage -Plan $plan -Stage Retire -ApprovedPlanId $plan.PlanId -ReadApplication $script:ReadApplication `
            -DisableDeployment $script:DisableDeployment -RetireApplication $script:RetireApplication -WriteJournal $script:WriteJournal -Confirm:$false)
        $results[0].Result | Should -Be 'already'
        $script:Writes.Count | Should -Be 0
    }

    It 'does not write when the journal intent cannot be recorded' {
        $plan = New-TestPlan -Applications @($script:Site['client_getinfo'])
        $brokenJournal = { param($entry) throw 'disk full' }
        $results = @(Invoke-VsLegacyRetirementStage -Plan $plan -Stage Retire -ApprovedPlanId $plan.PlanId -ReadApplication $script:ReadApplication `
            -DisableDeployment $script:DisableDeployment -RetireApplication $script:RetireApplication -WriteJournal $brokenJournal -Confirm:$false)
        $results[0].Result | Should -Be 'failed'
        $script:Writes.Count | Should -Be 0
    }

    It 'disables an active legacy deployment once the new chain runs and verifies it' {
        $script:Site['client_getinfo'] = New-TestEvidence -Deployments @(New-TestDeployment)
        $plan = New-TestPlan -Applications @($script:Site['client_getinfo']) -Live $true
        $results = @(Invoke-VsLegacyRetirementStage -Plan $plan -Stage DisableDeployments -ApprovedPlanId $plan.PlanId -ReadApplication $script:ReadApplication `
            -DisableDeployment $script:DisableDeployment -RetireApplication $script:RetireApplication -WriteJournal $script:WriteJournal -Confirm:$false)
        $results[0].Result | Should -Be 'done'
        @($script:Writes) | Should -Be @('disable:16777220')
        $script:Site['client_getinfo'].Deployments[0].Enabled | Should -Be 'False'
    }
}

Describe 'MC04 retirement evidence readers' {
    BeforeEach {
        $script:DtXml = @'
<AppMgmtDigest><DeploymentType><Installer Technology="Script"><Contents><Content ContentId="Content_1" Version="1"><File Name="client_getinfo.ps1" Size="100"/><Location>__LOCATION__</Location></Content></Contents></Installer>
<EnhancedDetectionMethod><Settings><SimpleSetting><RegistryDiscoverySource Hive="HKEY_LOCAL_MACHINE"><Key>SOFTWARE\VirtuSphere</Key><ValueName>SetupState</ValueName></RegistryDiscoverySource></SimpleSetting></Settings></EnhancedDetectionMethod></DeploymentType></AppMgmtDigest>
'@
        $script:ContentRoot = Join-Path $TestDrive 'client_getinfo'
        New-Item -ItemType Directory -Path $script:ContentRoot -Force | Out-Null
        [IO.File]::WriteAllText((Join-Path $script:ContentRoot 'client_getinfo.ps1'), "#Requires -Version 5.1`r`n# client_getinfo.ps1 (V22) - old`r`n")
    }

    It 'reads identity, provider counters, exact deployments and the content fingerprint of one legacy application' {
        Mock Get-CMApplication {
            [pscustomobject]@{ LocalizedDisplayName = 'client_getinfo'; CI_ID = 101; ModelName = $script:ModelA; SecuredScopeNames = @('Default')
                NumberOfDevicesWithApp = 57; IsExpired = $false; NumberOfDeployments = 1; NumberOfDependentDTs = 1; NumberOfDependentTS = 0; IsSuperseded = $false; IsSuperseding = $false }
            [pscustomobject]@{ LocalizedDisplayName = 'client_getinfo_2.1'; CI_ID = 201; ModelName = $script:ModelB }
        }
        Mock Get-CMApplicationDeployment {
            [pscustomobject]@{ ApplicationName = 'client_getinfo'; AssignmentID = 16777220; TargetCollectionID = 'PS100013'; CollectionName = 'Deploy Windows 2022'; Enabled = $true; OfferTypeID = 0 }
            [pscustomobject]@{ ApplicationName = 'client_getinfo_2.1'; AssignmentID = 16777221; TargetCollectionID = 'PS100014'; Enabled = $true; OfferTypeID = 0 }
        }
        Mock Get-CMDeploymentType { [pscustomobject]@{ CI_ID = 9101; SDMPackageXML = $script:DtXml.Replace('__LOCATION__', $script:ContentRoot) } }
        $evidence = Read-VsLegacyApplicationEvidence -Name 'client_getinfo'
        $evidence.State | Should -Be 'visible'
        $evidence.CiId | Should -Be '101'
        $evidence.LogicalName | Should -Be 'Application_aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'
        $evidence.Counters.NumberOfDependentDTs | Should -Be '1'
        $evidence.Counters.IsExpired | Should -Be 'False'
        @($evidence.Deployments | ForEach-Object AssignmentId) | Should -Be @('16777220')
        $evidence.Deployments[0].Enabled | Should -Be 'True'
        $evidence.DeploymentTypeIds | Should -Be @('9101')
        $evidence.ContentFiles | Should -Be @('client_getinfo.ps1')
        $evidence.DetectsSetupState | Should -BeTrue
        $evidence.ScriptVersion | Should -Be 'V22'
    }

    It 'distinguishes invisible, ambiguous and unreadable applications' {
        Mock Get-CMApplication { }
        (Read-VsLegacyApplicationEvidence -Name 'client_getinfo').State | Should -Be 'not-visible'
        Mock Get-CMApplication { 1..2 | ForEach-Object { [pscustomobject]@{ LocalizedDisplayName = 'client_getinfo'; CI_ID = $_; ModelName = $script:ModelA } } }
        (Read-VsLegacyApplicationEvidence -Name 'client_getinfo').State | Should -Be 'ambiguous'
        Mock Get-CMApplication { throw 'provider unavailable' }
        $unknown = Read-VsLegacyApplicationEvidence -Name 'client_getinfo'
        $unknown.State | Should -Be 'unknown'
        $unknown.Detail | Should -Be 'provider unavailable'
    }

    It 'proves the new chain only with owned applications, an enabled Required deployment and a successful client' {
        $marker = 'VirtuSphere managed client application contract v1'
        Mock Get-CMApplication { [pscustomobject]@{ LocalizedDisplayName = $Name; LocalizedDescription = $marker; IsExpired = $false } }
        Mock Get-CMApplicationDeployment { [pscustomobject]@{ ApplicationName = 'client_staticip'; AssignmentID = 7; TargetCollectionID = 'PS100020'; Enabled = $true; OfferTypeID = 0 } }
        Mock Get-CMDeployment { [pscustomobject]@{ AssignmentID = 7; NumberSuccess = 3 }; [pscustomobject]@{ AssignmentID = 8; NumberSuccess = 99 } }
        $live = Read-VsCoreChainEvidence -Specs @(Get-VsClientAppSpecs)
        $live.Live | Should -BeTrue
        $live.SuccessCount | Should -Be 3

        Mock Get-CMDeployment { [pscustomobject]@{ AssignmentID = 7; NumberSuccess = 0 } }
        (Read-VsCoreChainEvidence -Specs @(Get-VsClientAppSpecs)).Reasons | Should -Be @('core_entry_no_success')

        Mock Get-CMApplicationDeployment { [pscustomobject]@{ ApplicationName = 'client_staticip'; AssignmentID = 7; Enabled = $false; OfferTypeID = 0 } }
        (Read-VsCoreChainEvidence -Specs @(Get-VsClientAppSpecs)).Reasons | Should -Be @('core_entry_not_deployed')

        Mock Get-CMApplication { [pscustomobject]@{ LocalizedDisplayName = $Name; LocalizedDescription = 'manual'; IsExpired = $false } }
        (Read-VsCoreChainEvidence -Specs @(Get-VsClientAppSpecs)).Reasons | Should -Contain 'core_application_unowned:client_getInfos'
    }

    It 'finds a group member by the revision-independent logical name and fails closed on an unreadable group' {
        $logicalName = Get-VsLegacyLogicalName -ModelName $script:ModelA
        Mock Get-CMApplicationGroup { [pscustomobject]@{ LocalizedDisplayName = 'Server-Basis'; SDMPackageXML = "<AppGroup><Ref LogicalName=`"$logicalName`" /></AppGroup>" } }
        $scan = Read-VsApplicationGroupMembership -LogicalNames @($logicalName)
        $scan.State | Should -Be 'complete'
        @($scan.Members[$logicalName]) | Should -Be @('Server-Basis')
        Mock Get-CMApplicationGroup { [pscustomobject]@{ LocalizedDisplayName = 'Unlesbar' } }
        (Read-VsApplicationGroupMembership -LogicalNames @($logicalName)).State | Should -Be 'unknown'
    }
}

Describe 'MC04 retirement boundaries' {
    It 'never deletes and writes only through Retire and the deployment Enabled flag' {
        foreach ($path in @($script:EntryScriptPath, $script:ModulePath)) {
            $source = Get-Content -LiteralPath $path -Raw
            $source | Should -Not -Match '(?i)\b(Remove|New|Set)-CM[A-Za-z]+'
            $source | Should -Not -Match '(?i)Invoke-CimMethod|Remove-CimInstance|\.Delete\('
        }
        $entry = Get-Content -LiteralPath $script:EntryScriptPath -Raw
        ([regex]::Matches($entry, 'Suspend-CMApplication')).Count | Should -Be 1
        ([regex]::Matches($entry, 'Set-CimInstance')).Count | Should -Be 1
        $entry | Should -Match "Set-CimInstance -InputObject \`$assignments\[0\] -Property @\{ Enabled = \`$false \}"
        $entry | Should -Match '-WhatIf:\$WhatIfPreference'
    }

    It 'reaches the executor only in the -Apply branch' {
        $tokens = $null
        $errors = $null
        $ast = [System.Management.Automation.Language.Parser]::ParseFile($script:EntryScriptPath, [ref]$tokens, [ref]$errors)
        $branch = @($ast.FindAll({ param($node) $node -is [System.Management.Automation.Language.IfStatementAst] -and $node.Clauses[0].Item1.Extent.Text -ceq '-not $Apply' }, $true))
        $branch.Count | Should -Be 1
        $branch[0].Clauses[0].Item2.Extent.Text | Should -Not -Match 'Invoke-VsLegacyRetirementStage'
        $branch[0].ElseClause.Extent.Text | Should -Match 'Invoke-VsLegacyRetirementStage'
    }

    It 'keeps legacy handling out of both installers' {
        foreach ($installer in @('install-VirtuSphere-Clients.ps1', 'install-VirtuSphere-MECM.ps1')) {
            $source = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') $installer) -Raw
            $source | Should -Not -Match '(?i)Suspend-CMApplication|RemoveLegacy|Retire'
        }
    }

    It 'names the legacy applications once, in the packaging policy' {
        (Get-VsClientPackagingPolicy).LegacyApplicationNames | Should -Be @('client_getinfo', 'client_getinfo_2.1')
        foreach ($path in @($script:ModulePath, (Join-Path $script:MecmRoot 'VirtuSphere-ClientPreflight.ps1'), $script:EntryScriptPath)) {
            (Get-Content -LiteralPath $path -Raw) | Should -Not -Match "'client_getinfo_2\.1'"
        }
    }
}
