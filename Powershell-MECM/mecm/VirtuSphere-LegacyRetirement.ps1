# Retirement of the manually created legacy getinfo applications (MC04,
# decided 27.09.2026): take them out of targeting and retire them, never delete.
#
# Reading, planning and writing are separate. The Read-* functions collect site
# evidence, Get-VsLegacyRetirementPlan decides purely from that evidence and
# Invoke-VsLegacyRetirementStage executes one stage of an already previewed plan
# through injected handlers. retire-VirtuSphere-LegacyGetInfo.ps1 wires the CM
# calls. Requires VirtuSphere-ClientPackaging.ps1 (spec, policy, property
# helpers) and VirtuSphere-ClientPreflight.ps1 (plan hash). No import-time side
# effects.
Set-StrictMode -Version 1.0

$script:VsLegacyRetirementSchema = 1

# The provider computes these on SMS_ApplicationLatest for the whole site, so
# they also count references the caller cannot open. Missing or unparsable
# values are unknown, never zero.
$script:VsLegacyCounterNames = @('IsExpired', 'NumberOfDeployments', 'NumberOfDependentDTs', 'NumberOfDependentTS', 'IsSuperseded', 'IsSuperseding')

function ConvertTo-VsLegacyCounters {
    param($Counters)
    if ($null -eq $Counters) { return $null }
    $result = [ordered]@{}
    foreach ($name in @('NumberOfDeployments', 'NumberOfDependentDTs', 'NumberOfDependentTS')) {
        $number = 0L
        if (-not [long]::TryParse([string]$Counters.$name, [ref]$number) -or $number -lt 0) { return $null }
        $result[$name] = $number
    }
    foreach ($name in @('IsExpired', 'IsSuperseded', 'IsSuperseding')) {
        $text = [string]$Counters.$name
        if ($text -ceq 'True') { $result[$name] = $true }
        elseif ($text -ceq 'False') { $result[$name] = $false }
        else { return $null }
    }
    return [pscustomobject]$result
}

# ModelName is ScopeId_<guid>/Application_<guid>. Only the second part is
# revision independent and appears in application-group definitions.
function Get-VsLegacyLogicalName {
    param([string]$ModelName)
    $parts = @(([string]$ModelName) -split '/')
    if ($parts.Count -ne 2 -or $parts[1] -cnotmatch '\AApplication_[0-9A-Fa-f]{8}(-[0-9A-Fa-f]{4}){3}-[0-9A-Fa-f]{12}\z') { return '' }
    return $parts[1]
}

function Read-VsApplicationDeploymentRows {
    param([Parameter(Mandatory)][string]$Name)
    foreach ($deployment in @(Get-CMApplicationDeployment -Name $Name -ErrorAction Stop)) {
        $applicationName = Get-VsObjectPropertyText -Object $deployment -Names @('ApplicationName')
        if ($applicationName -and $applicationName -cne $Name) { continue }
        [pscustomobject]@{
            AssignmentId = Get-VsObjectPropertyText -Object $deployment -Names @('AssignmentID', 'AssignmentId')
            CollectionId = Get-VsObjectPropertyText -Object $deployment -Names @('TargetCollectionID', 'CollectionID', 'CollectionId')
            CollectionName = Get-VsObjectPropertyText -Object $deployment -Names @('CollectionName')
            Enabled = Get-VsObjectPropertyText -Object $deployment -Names @('Enabled')
            OfferTypeId = Get-VsObjectPropertyText -Object $deployment -Names @('OfferTypeID')
        }
    }
}

# Informational answer to "which version is in this application": the files the
# deployment type distributes, its content path and whether its detection reads
# the SetupState marker that client_getInfos also uses.
function Get-VsLegacyContentFingerprint {
    param([AllowEmptyCollection()][object[]]$DeploymentTypes)
    $files = New-Object System.Collections.Generic.List[string]
    $locations = New-Object System.Collections.Generic.List[string]
    $readable = @($DeploymentTypes).Count -gt 0
    $setupState = $false
    foreach ($type in @($DeploymentTypes)) {
        $raw = Get-VsObjectPropertyText -Object $type -Names @('SDMPackageXML')
        if (-not $raw) { $readable = $false; continue }
        try { [xml]$xml = $raw } catch { $readable = $false; continue }
        foreach ($node in @($xml.SelectNodes("//*[local-name()='File']"))) {
            $fileName = $node.GetAttribute('Name')
            if ($fileName) { $files.Add($fileName) }
        }
        foreach ($node in @($xml.SelectNodes("//*[local-name()='Location']"))) {
            if ($node.InnerText.Trim()) { $locations.Add($node.InnerText.Trim()) }
        }
        if ($raw -match 'SetupState') { $setupState = $true }
    }
    return [pscustomobject]@{
        Readable = $readable
        Files = @($files | Sort-Object -Unique)
        Locations = @($locations | Sort-Object -Unique)
        DetectsSetupState = $setupState
    }
}

# Reads the (Vnn) header of the distributed getinfo script. .NET file access
# works for UNC paths from the CM site drive, where Test-Path does not.
function Get-VsLegacyScriptVersion {
    param([AllowEmptyCollection()][string[]]$Locations, [AllowEmptyCollection()][string[]]$Files)
    foreach ($location in @($Locations)) {
        foreach ($file in @($Files | Where-Object { $_ -match '\Aclient_getinfo.*\.ps1\z' })) {
            $reader = $null
            try {
                $reader = New-Object IO.StreamReader([IO.Path]::Combine($location, $file))
                for ($line = 0; $line -lt 15 -and -not $reader.EndOfStream; $line++) {
                    $version = [regex]::Match($reader.ReadLine(), '\((V\d+)\)')
                    if ($version.Success) { return $version.Groups[1].Value }
                }
            } catch {
                Write-Debug $_
            } finally {
                if ($reader) { $reader.Dispose() }
            }
        }
    }
    return ''
}

function Read-VsLegacyApplicationEvidence {
    param([Parameter(Mandatory)][string]$Name, [switch]$SkipContent)
    $evidence = [ordered]@{
        Name = $Name; State = 'unknown'; Detail = ''
        CiId = ''; ModelName = ''; LogicalName = ''; SecurityScopes = @()
        Counters = $null; DevicesWithApp = ''
        Deployments = @(); DeploymentTypeIds = @()
        ContentReadable = $false; ContentFiles = @(); ContentLocations = @(); DetectsSetupState = $false; ScriptVersion = ''
        DependentManagedApps = @()
    }
    try {
        $applications = @(Get-CMApplication -Name $Name -ShowHidden -DisableWildcardHandling -ErrorAction Stop |
            Where-Object { (Get-VsObjectPropertyText -Object $_ -Names @('LocalizedDisplayName', 'Name')) -ceq $Name })
        if ($applications.Count -eq 0) { $evidence.State = 'not-visible'; return [pscustomobject]$evidence }
        if ($applications.Count -gt 1) {
            $evidence.State = 'ambiguous'
            $evidence.Detail = ('{0} applications with exactly this name' -f $applications.Count)
            return [pscustomobject]$evidence
        }
        $application = $applications[0]
        $evidence.CiId = Get-VsObjectPropertyText -Object $application -Names @('CI_ID')
        $evidence.ModelName = Get-VsObjectPropertyText -Object $application -Names @('ModelName')
        if (-not $evidence.CiId -or -not $evidence.ModelName) { throw 'CI_ID or ModelName missing' }
        $evidence.LogicalName = Get-VsLegacyLogicalName -ModelName $evidence.ModelName
        if ($application.PSObject.Properties['SecuredScopeNames']) { $evidence.SecurityScopes = @($application.SecuredScopeNames | ForEach-Object { [string]$_ }) }
        $evidence.DevicesWithApp = Get-VsObjectPropertyText -Object $application -Names @('NumberOfDevicesWithApp')
        $counters = [ordered]@{}
        foreach ($counter in $script:VsLegacyCounterNames) {
            if (-not $application.PSObject.Properties[$counter] -or $null -eq $application.$counter) { $counters = $null; break }
            $counters[$counter] = [string]$application.$counter
        }
        if ($null -ne $counters) { $evidence.Counters = [pscustomobject]$counters }
        $evidence.Deployments = @(Read-VsApplicationDeploymentRows -Name $Name)
        $types = @(Get-CMDeploymentType -ApplicationName $Name -ErrorAction Stop)
        $evidence.DeploymentTypeIds = @($types | ForEach-Object { Get-VsObjectPropertyText -Object $_ -Names @('CI_ID') } | Where-Object { $_ })
        $evidence.State = 'visible'
        if (-not $SkipContent) {
            $fingerprint = Get-VsLegacyContentFingerprint -DeploymentTypes $types
            $evidence.ContentReadable = [bool]$fingerprint.Readable
            $evidence.ContentFiles = @($fingerprint.Files)
            $evidence.ContentLocations = @($fingerprint.Locations)
            $evidence.DetectsSetupState = [bool]$fingerprint.DetectsSetupState
            $evidence.ScriptVersion = Get-VsLegacyScriptVersion -Locations $fingerprint.Locations -Files $fingerprint.Files
        }
    } catch {
        $evidence.State = 'unknown'
        $evidence.Detail = [string]$_.Exception.Message
    }
    return [pscustomobject]$evidence
}

# Applications whose name starts like the legacy names but is neither of them
# nor the managed client_getInfos. They are reported, never touched.
function Read-VsLegacySimilarNames {
    param([Parameter(Mandatory)][string[]]$LegacyNames, [Parameter(Mandatory)][string]$ManagedName)
    try {
        $names = @(Get-CMApplication -Name 'client_getinfo*' -ShowHidden -Fast -ErrorAction Stop |
            ForEach-Object { Get-VsObjectPropertyText -Object $_ -Names @('LocalizedDisplayName', 'Name') } |
            Where-Object { $_ -and $_ -cnotin $LegacyNames -and $_ -cne $ManagedName } | Sort-Object -Unique)
        return [pscustomobject]@{ State = 'complete'; Names = @($names); Detail = '' }
    } catch {
        return [pscustomobject]@{ State = 'unknown'; Names = @(); Detail = [string]$_.Exception.Message }
    }
}

function Read-VsApplicationGroupMembership {
    param([AllowEmptyCollection()][string[]]$LogicalNames)
    $members = @{}
    foreach ($logicalName in @($LogicalNames | Where-Object { $_ })) { $members[$logicalName] = @() }
    try {
        $groups = @(Get-CMApplicationGroup -ShowHidden -ErrorAction Stop)
        foreach ($group in $groups) {
            $groupName = Get-VsObjectPropertyText -Object $group -Names @('LocalizedDisplayName', 'Name')
            $raw = Get-VsObjectPropertyText -Object $group -Names @('SDMPackageXML')
            if (-not $raw -and $group.PSObject.Methods['Get']) {
                # Lazy property of the provider object; load it once explicitly.
                $group.Get()
                $raw = Get-VsObjectPropertyText -Object $group -Names @('SDMPackageXML')
            }
            if (-not $raw) {
                return [pscustomobject]@{ State = 'unknown'; GroupCount = $groups.Count; Members = @{}; Detail = ("definition of application group '{0}' unreadable" -f $groupName) }
            }
            foreach ($logicalName in @($members.Keys)) {
                if ($raw.IndexOf($logicalName, [StringComparison]::OrdinalIgnoreCase) -ge 0) { $members[$logicalName] += $groupName }
            }
        }
        return [pscustomobject]@{ State = 'complete'; GroupCount = $groups.Count; Members = $members; Detail = '' }
    } catch {
        return [pscustomobject]@{ State = 'unknown'; GroupCount = 0; Members = @{}; Detail = [string]$_.Exception.Message }
    }
}

# "The new chain demonstrably runs": the managed first phase and the one
# deployable entry exist exactly once with the managed marker, are not retired,
# the entry has an enabled Required deployment and that deployment has at least
# one successful client in the site summary.
function Read-VsCoreChainEvidence {
    param([Parameter(Mandatory)][object[]]$Specs)
    $reasons = New-Object System.Collections.Generic.List[string]
    $successCount = 0L
    $detail = ''
    $entry = @($Specs | Where-Object { $_.PSObject.Properties['Role'] -and [string]$_.Role -ceq 'deployable_entry' })
    $first = @($Specs | Where-Object { $null -eq $_.DependsOn })
    if ($entry.Count -ne 1 -or $first.Count -ne 1) {
        $reasons.Add('core_spec_invalid')
    } else {
        try {
            foreach ($spec in @($first[0], $entry[0])) {
                $name = [string]$spec.AppName
                $applications = @(Get-CMApplication -Name $name -ShowHidden -DisableWildcardHandling -ErrorAction Stop |
                    Where-Object { (Get-VsObjectPropertyText -Object $_ -Names @('LocalizedDisplayName', 'Name')) -ceq $name })
                if ($applications.Count -ne 1) { $reasons.Add(('core_application_not_unique:{0}' -f $name)); continue }
                if (-not (Test-VsClientApplicationOwnership -Application $applications[0] -Spec $spec -AppFolder 'VirtuSphere_Core')) {
                    $reasons.Add(('core_application_unowned:{0}' -f $name))
                } elseif ((Get-VsObjectPropertyText -Object $applications[0] -Names @('IsExpired')) -cne 'False') {
                    $reasons.Add(('core_application_retired_or_unknown:{0}' -f $name))
                }
            }
            $entryName = [string]$entry[0].AppName
            $required = @(Read-VsApplicationDeploymentRows -Name $entryName | Where-Object {
                $_.Enabled -ceq 'True' -and $_.OfferTypeId -ceq '0' -and $_.AssignmentId -cmatch '\A\d+\z'
            })
            if ($required.Count -eq 0) {
                $reasons.Add('core_entry_not_deployed')
            } else {
                $assignmentIds = @($required | ForEach-Object { [string]$_.AssignmentId })
                foreach ($summary in @(Get-CMDeployment -SoftwareName $entryName -FeatureType Application -DisableWildcardHandling -ErrorAction Stop)) {
                    if ((Get-VsObjectPropertyText -Object $summary -Names @('AssignmentID')) -cnotin $assignmentIds) { continue }
                    $success = 0L
                    if ([long]::TryParse((Get-VsObjectPropertyText -Object $summary -Names @('NumberSuccess')), [ref]$success) -and $success -gt $successCount) { $successCount = $success }
                }
                if ($successCount -lt 1) { $reasons.Add('core_entry_no_success') }
            }
        } catch {
            $reasons.Add('core_evidence_unknown')
            $detail = [string]$_.Exception.Message
        }
    }
    return [pscustomobject]@{
        Live = ($reasons.Count -eq 0)
        Reasons = @($reasons | Sort-Object -Unique)
        SuccessCount = $successCount
        Detail = $detail
    }
}

# Informational: which managed phase still points at a legacy deployment type.
function Read-VsManagedDependencyTargets {
    param([Parameter(Mandatory)][object[]]$Specs)
    $rows = New-Object System.Collections.Generic.List[object]
    try {
        foreach ($spec in $Specs) {
            $name = [string]$spec.AppName
            $applications = @(Get-CMApplication -Name $name -ShowHidden -DisableWildcardHandling -ErrorAction Stop |
                Where-Object { (Get-VsObjectPropertyText -Object $_ -Names @('LocalizedDisplayName', 'Name')) -ceq $name })
            if ($applications.Count -ne 1) { continue }
            foreach ($type in @(Get-CMDeploymentType -ApplicationName $name -ErrorAction Stop)) {
                foreach ($group in @(Get-CMDeploymentTypeDependencyGroup -InputObject $type -ErrorAction Stop)) {
                    foreach ($dependency in @(Get-CMDeploymentTypeDependency -InputObject $group -ErrorAction Stop)) {
                        $rows.Add([pscustomobject]@{ AppName = $name; TargetId = (Get-VsObjectPropertyText -Object $dependency -Names @('CI_ID')) })
                    }
                }
            }
        }
        return [pscustomobject]@{ State = 'complete'; Rows = $rows.ToArray(); Detail = '' }
    } catch {
        return [pscustomobject]@{ State = 'unknown'; Rows = @(); Detail = [string]$_.Exception.Message }
    }
}

function New-VsLegacyRetirementItem {
    param($Stage, $Kind, $Application, $AssignmentId, $CollectionId, $Blockers, [bool]$RequiresConvergenceConfirmation)
    $sorted = @($Blockers | Sort-Object -Unique)
    return [pscustomobject]@{
        Stage = [string]$Stage
        Kind = [string]$Kind
        Name = [string]$Application.Name
        ModelName = [string]$Application.ModelName
        CiId = [string]$Application.CiId
        AssignmentId = [string]$AssignmentId
        CollectionId = [string]$CollectionId
        State = $(if ($sorted.Count -eq 0) { 'ready' } else { 'blocked' })
        Blockers = $sorted
        RequiresConvergenceConfirmation = $RequiresConvergenceConfirmation
    }
}

# Pure decision over read evidence. Stage DisableDeployments takes active
# legacy deployments out of targeting only while the new chain demonstrably
# runs. Stage Retire needs no active deployment, no dependent deployment type
# or task sequence, no supersedence and no application-group membership; an
# unused legacy application can therefore be retired before the cutover.
function Get-VsLegacyRetirementPlan {
    param(
        [Parameter(Mandatory)][AllowEmptyCollection()][object[]]$Applications,
        [Parameter(Mandatory)]$CoreChain,
        [Parameter(Mandatory)]$Groups,
        [Parameter(Mandatory)]$SimilarNames
    )
    $items = New-Object System.Collections.Generic.List[object]
    $findings = New-Object System.Collections.Generic.List[object]
    foreach ($application in @($Applications)) {
        $name = [string]$application.Name
        $state = [string]$application.State
        if ($state -ceq 'not-visible') {
            $findings.Add([pscustomobject]@{ Code = 'legacy_not_visible'; Target = $name; Blocking = $false })
            continue
        }
        if ($state -ceq 'ambiguous') {
            $findings.Add([pscustomobject]@{ Code = 'legacy_ambiguous'; Target = $name; Blocking = $true })
            continue
        }
        if ($state -cne 'visible') {
            $findings.Add([pscustomobject]@{ Code = 'legacy_inventory_unknown'; Target = $name; Blocking = $true })
            continue
        }
        $counters = ConvertTo-VsLegacyCounters -Counters $application.Counters
        $deployments = @($application.Deployments)
        $enabled = @($deployments | Where-Object { [string]$_.Enabled -ceq 'True' })
        $shared = New-Object System.Collections.Generic.List[string]
        if ($null -eq $counters) { $shared.Add('legacy_counters_unknown') }
        elseif ($counters.NumberOfDeployments -ne $deployments.Count) { $shared.Add('legacy_deployments_not_fully_visible') }
        if (@($deployments | Where-Object { [string]$_.Enabled -cnotin @('True', 'False') }).Count -gt 0) { $shared.Add('legacy_deployment_state_unknown') }

        foreach ($deployment in $enabled) {
            $blockers = New-Object System.Collections.Generic.List[string]
            if ([string]$deployment.AssignmentId -cnotmatch '\A\d+\z') { $blockers.Add('deployment_identity_unknown') }
            if (-not [bool]$CoreChain.Live) { $blockers.Add('core_chain_not_live') }
            $items.Add((New-VsLegacyRetirementItem -Stage 'DisableDeployments' -Kind 'disable-deployment' -Application $application `
                -AssignmentId $deployment.AssignmentId -CollectionId $deployment.CollectionId -Blockers $blockers -RequiresConvergenceConfirmation $false))
        }

        if ($null -ne $counters -and $counters.IsExpired) {
            $findings.Add([pscustomobject]@{ Code = 'legacy_already_retired'; Target = $name; Blocking = $false })
            continue
        }
        $blockers = New-Object System.Collections.Generic.List[string]
        foreach ($shareBlocker in $shared) { $blockers.Add($shareBlocker) }
        if ($null -ne $counters) {
            if ($enabled.Count -gt 0) { $blockers.Add('legacy_deployment_enabled') }
            if ($counters.NumberOfDependentDTs -gt 0) { $blockers.Add('legacy_dependents_present') }
            if ($counters.NumberOfDependentTS -gt 0) { $blockers.Add('legacy_task_sequence_reference') }
            if ($counters.IsSuperseded -or $counters.IsSuperseding) { $blockers.Add('legacy_supersedence_present') }
        }
        # A hashtable miss is $null and @($null) counts one; test the hit first.
        $groupHits = $null
        if ([string]$application.LogicalName -and $Groups.Members -is [hashtable]) { $groupHits = $Groups.Members[[string]$application.LogicalName] }
        if ([string]$Groups.State -cne 'complete') { $blockers.Add('application_group_scan_unknown') }
        elseif (-not [string]$application.LogicalName) { $blockers.Add('legacy_identity_unparsed') }
        elseif ($null -ne $groupHits -and @($groupHits).Count -gt 0) { $blockers.Add('legacy_application_group_member') }
        $items.Add((New-VsLegacyRetirementItem -Stage 'Retire' -Kind 'retire-application' -Application $application `
            -AssignmentId '' -CollectionId '' -Blockers $blockers -RequiresConvergenceConfirmation ($deployments.Count -gt 0)))
    }
    if ([string]$SimilarNames.State -cne 'complete') {
        $findings.Add([pscustomobject]@{ Code = 'similar_name_scan_unknown'; Target = 'client_getinfo*'; Blocking = $false })
    }
    foreach ($similar in @($SimilarNames.Names)) {
        $findings.Add([pscustomobject]@{ Code = 'legacy_similar_name'; Target = [string]$similar; Blocking = $false })
    }

    $orderedItems = @($items.ToArray() | Sort-Object Stage, Name, AssignmentId)
    $orderedFindings = @($findings.ToArray() | Sort-Object Code, Target)
    # Only decision-relevant, stable values enter the plan identity: device
    # counts, summary numbers and content details change without changing
    # what may be done.
    $canonical = [ordered]@{
        Schema = $script:VsLegacyRetirementSchema
        CoreChainLive = [bool]$CoreChain.Live
        CoreChainReasons = @($CoreChain.Reasons | Sort-Object -Unique)
        GroupScan = [string]$Groups.State
        Items = $orderedItems
        Findings = $orderedFindings
    }
    return [pscustomobject]@{
        Schema = $script:VsLegacyRetirementSchema
        PlanId = (Get-VsClientPreflightPlanId -Rows @([pscustomobject]$canonical))
        CoreChain = $CoreChain
        GroupScan = [pscustomobject]@{ State = [string]$Groups.State; GroupCount = $Groups.GroupCount; Detail = [string]$Groups.Detail }
        Items = $orderedItems
        Findings = $orderedFindings
        Applications = @($Applications)
    }
}

function Get-VsLegacyRetirementReport {
    param([Parameter(Mandatory)][object[]]$Specs, [Parameter(Mandatory)][string[]]$LegacyNames)
    $total = $LegacyNames.Count + 3
    $position = 0
    Write-Host "[0/$total] RUN legacy-retirement-inventory"
    $applications = New-Object System.Collections.Generic.List[object]
    foreach ($name in $LegacyNames) {
        $position++
        Write-Host "[$position/$total] RUN legacy-application $name"
        $applications.Add((Read-VsLegacyApplicationEvidence -Name $name))
        Write-Host "[$position/$total] DONE legacy-application $name"
    }
    $position++
    Write-Host "[$position/$total] RUN similar-names"
    $managedFirst = @($Specs | Where-Object { $null -eq $_.DependsOn } | ForEach-Object { [string]$_.AppName })
    $similar = Read-VsLegacySimilarNames -LegacyNames $LegacyNames -ManagedName ([string]($managedFirst | Select-Object -First 1))
    Write-Host "[$position/$total] DONE similar-names"
    $position++
    Write-Host "[$position/$total] RUN application-groups"
    $groups = Read-VsApplicationGroupMembership -LogicalNames @($applications.ToArray() | ForEach-Object { [string]$_.LogicalName })
    Write-Host "[$position/$total] DONE application-groups"
    $position++
    Write-Host "[$position/$total] RUN core-chain"
    $core = Read-VsCoreChainEvidence -Specs $Specs
    $targets = Read-VsManagedDependencyTargets -Specs $Specs
    foreach ($application in $applications) {
        if ($targets.State -cne 'complete') { $application.DependentManagedApps = @('unknown'); continue }
        $typeIds = @($application.DeploymentTypeIds)
        $application.DependentManagedApps = @($targets.Rows | Where-Object { $_.TargetId -and $_.TargetId -cin $typeIds } | ForEach-Object { [string]$_.AppName } | Sort-Object -Unique)
    }
    Write-Host "[$position/$total] DONE core-chain"
    return Get-VsLegacyRetirementPlan -Applications $applications.ToArray() -CoreChain $core -Groups $groups -SimilarNames $similar
}

# Re-reads one plan item just before and just after its write. Returns 'ready',
# 'done' or the reason the plan no longer holds. After the write only the
# revision-independent ModelName is compared, because a retire may add a
# revision.
function Test-VsLegacyRetirementItemCurrent {
    param([Parameter(Mandatory)]$Item, [Parameter(Mandatory)]$Current, [switch]$AfterWrite)
    if ([string]$Current.State -cne 'visible') { return 'application_not_visible' }
    if ([string]$Current.ModelName -cne [string]$Item.ModelName) { return 'application_identity_changed' }
    if (-not $AfterWrite -and [string]$Current.CiId -cne [string]$Item.CiId) { return 'application_revision_changed' }
    if ([string]$Item.Stage -ceq 'DisableDeployments') {
        $deployment = @($Current.Deployments | Where-Object { [string]$_.AssignmentId -ceq [string]$Item.AssignmentId })
        if ($deployment.Count -ne 1) { return 'deployment_not_visible' }
        if ([string]$deployment[0].CollectionId -cne [string]$Item.CollectionId) { return 'deployment_identity_changed' }
        if ([string]$deployment[0].Enabled -ceq 'False') { return 'done' }
        if ([string]$deployment[0].Enabled -cne 'True') { return 'deployment_state_unknown' }
        return 'ready'
    }
    $counters = ConvertTo-VsLegacyCounters -Counters $Current.Counters
    if ($null -eq $counters) { return 'legacy_counters_unknown' }
    if ($counters.IsExpired) { return 'done' }
    $deployments = @($Current.Deployments)
    if ($counters.NumberOfDeployments -ne $deployments.Count) { return 'legacy_deployments_not_fully_visible' }
    if (@($deployments | Where-Object { [string]$_.Enabled -cne 'False' }).Count -gt 0) { return 'legacy_deployment_enabled' }
    if ($counters.NumberOfDependentDTs -gt 0) { return 'legacy_dependents_present' }
    if ($counters.NumberOfDependentTS -gt 0) { return 'legacy_task_sequence_reference' }
    if ($counters.IsSuperseded -or $counters.IsSuperseding) { return 'legacy_supersedence_present' }
    return 'ready'
}

# Executes one stage of the previewed plan. The caller passes -WhatIf through
# explicitly; each write is confirmed here with ConfirmImpact High, journaled
# before and after, and verified by an independent re-read. The first failure
# stops the stage; blocked items are skipped with their reasons.
function Invoke-VsLegacyRetirementStage {
    [CmdletBinding(SupportsShouldProcess, ConfirmImpact = 'High')]
    param(
        [Parameter(Mandatory)]$Plan,
        [Parameter(Mandatory)][ValidateSet('DisableDeployments', 'Retire')][string]$Stage,
        [Parameter(Mandatory)][string]$ApprovedPlanId,
        [switch]$PolicyConvergenceConfirmed,
        [Parameter(Mandatory)][scriptblock]$ReadApplication,
        [Parameter(Mandatory)][scriptblock]$DisableDeployment,
        [Parameter(Mandatory)][scriptblock]$RetireApplication,
        [Parameter(Mandatory)][scriptblock]$WriteJournal
    )
    if ([string]$Plan.PlanId -cne $ApprovedPlanId) {
        throw 'Der Rueckbauplan hat sich seit der Vorschau geaendert. Neue Vorschau erstellen; es wurde nichts geaendert.'
    }
    $results = New-Object System.Collections.Generic.List[object]
    $stageItems = @($Plan.Items | Where-Object { [string]$_.Stage -ceq $Stage })
    $total = $stageItems.Count
    $index = 0
    Write-Host "[0/$total] RUN legacy-retirement $Stage"
    foreach ($item in $stageItems) {
        $index++
        if ($Stage -ceq 'DisableDeployments') {
            $label = '{0} deployment {1}' -f $item.Name, $item.AssignmentId
            $action = 'Deployment auf Collection {0} deaktivieren' -f $item.CollectionId
        } else {
            $label = '{0} application {1}' -f $item.Name, $item.CiId
            $action = 'Anwendung auf Retired setzen (Suspend-CMApplication)'
        }
        Write-Host "[$index/$total] RUN $label"
        $reasons = @($item.Blockers)
        if ([string]$item.State -ceq 'ready' -and $Stage -ceq 'Retire' -and [bool]$item.RequiresConvergenceConfirmation -and -not $PolicyConvergenceConfirmed) {
            $reasons = @('policy_convergence_unconfirmed')
        }
        if ([string]$item.State -cne 'ready' -or $reasons.Count -gt 0) {
            $results.Add([pscustomobject]@{ Item = $item; Result = 'blocked'; Reasons = $reasons; Error = '' })
            Write-Host ("[{0}/{1}] skip {2} ({3})" -f $index, $total, $label, ($reasons -join ', '))
            continue
        }
        $journalBase = [ordered]@{
            PlanId = [string]$Plan.PlanId; Stage = $Stage; Kind = [string]$item.Kind; Name = [string]$item.Name
            ModelName = [string]$item.ModelName; CiId = [string]$item.CiId; AssignmentId = [string]$item.AssignmentId
        }
        try {
            $gate = Test-VsLegacyRetirementItemCurrent -Item $item -Current (& $ReadApplication ([string]$item.Name))
            if ($gate -ceq 'done') {
                $results.Add([pscustomobject]@{ Item = $item; Result = 'already'; Reasons = @(); Error = '' })
                Write-Host "[$index/$total] pass $label (bereits erledigt)"
                continue
            }
            if ($gate -cne 'ready') { throw ('Zustand hat sich seit der Vorschau geaendert: {0}' -f $gate) }
            if (-not $PSCmdlet.ShouldProcess($label, $action)) {
                $results.Add([pscustomobject]@{ Item = $item; Result = 'not-confirmed'; Reasons = @(); Error = '' })
                Write-Host "[$index/$total] skip $label (nicht bestaetigt)"
                continue
            }
            $intent = [ordered]@{ TimestampUtc = [DateTime]::UtcNow.ToString('o'); Phase = 'intent' }
            foreach ($key in $journalBase.Keys) { $intent[$key] = $journalBase[$key] }
            & $WriteJournal ([pscustomobject]$intent)
            if ($Stage -ceq 'DisableDeployments') { & $DisableDeployment $item } else { & $RetireApplication $item }
            $check = Test-VsLegacyRetirementItemCurrent -Item $item -Current (& $ReadApplication ([string]$item.Name)) -AfterWrite
            if ($check -cne 'done') { throw ('Rueckleseprobe nach der Aenderung fehlgeschlagen: {0}' -f $check) }
            $outcome = [ordered]@{ TimestampUtc = [DateTime]::UtcNow.ToString('o'); Phase = 'outcome'; Result = 'done' }
            foreach ($key in $journalBase.Keys) { $outcome[$key] = $journalBase[$key] }
            & $WriteJournal ([pscustomobject]$outcome)
            $results.Add([pscustomobject]@{ Item = $item; Result = 'done'; Reasons = @(); Error = '' })
            Write-Host "[$index/$total] pass $label"
        } catch {
            $message = [string]$_.Exception.Message
            try {
                $failure = [ordered]@{ TimestampUtc = [DateTime]::UtcNow.ToString('o'); Phase = 'outcome'; Result = 'failed'; Error = $message }
                foreach ($key in $journalBase.Keys) { $failure[$key] = $journalBase[$key] }
                & $WriteJournal ([pscustomobject]$failure)
            } catch {
                Write-Debug $_
            }
            $results.Add([pscustomobject]@{ Item = $item; Result = 'failed'; Reasons = @(); Error = $message })
            Write-Host "[$index/$total] fail $label ($message)"
            break
        }
    }
    return $results.ToArray()
}

function Write-VsLegacyRetirementSummary {
    param([Parameter(Mandatory)]$Plan)
    Write-Host ''
    Write-Host ('Rueckbaubericht Altanwendungen getinfo, Plan-ID {0}' -f $Plan.PlanId)
    if ([bool]$Plan.CoreChain.Live) {
        Write-Host ('  Neue Kette: laeuft nachweislich ({0} erfolgreiche Clients am Required-Deployment von client_staticip)' -f $Plan.CoreChain.SuccessCount)
    } else {
        Write-Host ('  Neue Kette: nicht nachgewiesen ({0})' -f (@($Plan.CoreChain.Reasons) -join ', '))
    }
    foreach ($application in @($Plan.Applications)) {
        if ([string]$application.State -cne 'visible') {
            Write-Host ('  {0}: {1} {2}' -f $application.Name, $application.State, $application.Detail)
            continue
        }
        $counters = ConvertTo-VsLegacyCounters -Counters $application.Counters
        $enabledCount = @($application.Deployments | Where-Object { [string]$_.Enabled -ceq 'True' }).Count
        $missing = @(@('VirtuSphere-Client-Common.ps1', 'VirtuSphere-Client-Logging.ps1', 'bootstrap.json') | Where-Object { $_ -cnotin @($application.ContentFiles) })
        Write-Host ('  {0} (CI {1})' -f $application.Name, $application.CiId)
        if ($null -eq $counters) {
            Write-Host '    Zaehler des Providers unvollstaendig: Stand unbekannt'
        } else {
            Write-Host ('    Retired: {0}; Deployments: {1} (aktiv {2}); abhaengige Deployment Types: {3}; Tasksequenzen: {4}; Supersedence: {5}' -f `
                $(if ($counters.IsExpired) { 'ja' } else { 'nein' }), $counters.NumberOfDeployments, $enabledCount, `
                $counters.NumberOfDependentDTs, $counters.NumberOfDependentTS, $(if ($counters.IsSuperseded -or $counters.IsSuperseding) { 'ja' } else { 'nein' }))
        }
        if (@($application.DependentManagedApps).Count -gt 0) { Write-Host ('    Verwaltete Phase haengt davon ab: {0}' -f (@($application.DependentManagedApps) -join ', ')) }
        Write-Host ('    Geraete mit installierter Anwendung: {0}' -f $(if ($application.DevicesWithApp) { $application.DevicesWithApp } else { 'unbekannt' }))
        if ($application.ContentReadable) {
            Write-Host ('    Inhalt: {0}; Skriptversion: {1}; Detection liest SetupState: {2}' -f `
                ((@($application.ContentFiles) -join ', ')), $(if ($application.ScriptVersion) { $application.ScriptVersion } else { 'unbekannt' }), $(if ($application.DetectsSetupState) { 'ja' } else { 'nein' }))
            if ($missing.Count -gt 0) { Write-Host ('    Fehlt im Inhalt: {0}' -f ($missing -join ', ')) }
        } else {
            Write-Host '    Inhalt: Definition des Deployment Types nicht lesbar'
        }
    }
    foreach ($item in @($Plan.Items)) {
        $target = if ($item.AssignmentId) { 'Deployment ' + $item.AssignmentId } else { 'Anwendung' }
        $state = if ([string]$item.State -ceq 'ready') { 'bereit' } else { 'blockiert: ' + (@($item.Blockers) -join ', ') }
        Write-Host ('  Stufe {0}: {1} {2} -> {3}' -f $item.Stage, $item.Name, $target, $state)
    }
    foreach ($finding in @($Plan.Findings)) {
        Write-Host ('  Hinweis {0}: {1}' -f $finding.Code, $finding.Target)
    }
}
