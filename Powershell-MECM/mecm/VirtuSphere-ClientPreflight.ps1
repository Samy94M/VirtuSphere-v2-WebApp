# Read-only MC02 inventory. This module has no import-time side effects.
Set-StrictMode -Version 1.0

function Get-VsClientPreflightPlanId {
    param([Parameter(Mandatory)][object[]]$Rows)
    $json = ConvertTo-Json -InputObject $Rows -Depth 12 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($json)
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}

# Only forward DT supersedence is exposed by this CM cmdlet. A reverse scan
# over all visible applications still needs a provider-wide completeness proof.
function Get-VsClientSupersedenceRows {
    param([Parameter(Mandatory)][string]$ApplicationName, [Parameter(Mandatory)]$DeploymentType)
    $sourceId = Get-VsObjectPropertyText -Object $DeploymentType -Names @('CI_ID')
    if (-not $sourceId) { throw 'deployment type CI_ID missing' }
    $oldTypes = @(Get-CMDeploymentTypeSupersedence -InputObject $DeploymentType -ErrorAction Stop)
    foreach ($oldType in $oldTypes) {
        $targetId = Get-VsObjectPropertyText -Object $oldType -Names @('CI_ID')
        if (-not $targetId) { throw 'superseded deployment type CI_ID missing' }
        [pscustomobject]@{
            Kind = 'supersedence-forward'
            Name = $ApplicationName
            SourceId = $sourceId
            TargetId = $targetId
        }
    }
}

function Get-VsClientPreflightReport {
    param(
        [Parameter(Mandatory)][hashtable]$Config,
        [Parameter(Mandatory)][object[]]$Specs,
        [Parameter(Mandatory)][string]$ClientSourceDir,
        [Parameter(Mandatory)][string]$SiteCode
    )
    $findings = New-Object System.Collections.Generic.List[object]
    $inventory = New-Object System.Collections.Generic.List[object]
    $total = 6
    Write-Host "[0/$total] RUN client-preflight"

    Write-Host "[1/$total] RUN source-identity"
    # Initialize-VsCmSite has already switched to the CM site drive. From a
    # non-FileSystem location Test-Path and Get-ChildItem cannot resolve UNC
    # paths, so every share check would report a false unknown. Run the file
    # checks from a FileSystem location and restore the site drive afterwards.
    Push-Location -LiteralPath ([IO.Path]::GetPathRoot([Environment]::SystemDirectory))
    try {
        $markerName = '.virtusphere-source-identity'
        $localMarker = Join-Path ([string]$Config.PackagesBase) $markerName
        $shareMarker = Join-Path ([string]$Config.ContentShare) $markerName
        foreach ($pair in @(@('local', $localMarker), @('unc', $shareMarker))) {
            $value = ''
            try {
                if (-not (Test-Path -LiteralPath $pair[1] -PathType Leaf)) { throw 'marker missing' }
                $value = [IO.File]::ReadAllText($pair[1]).TrimEnd("`r", "`n")
                if ($value -cne [string]$Config.SourceIdentity) { throw 'marker differs from site configuration' }
                $inventory.Add([pscustomobject]@{ Kind = 'source'; Name = $pair[0]; Identity = $value })
            } catch {
                $findings.Add([pscustomobject]@{ Code = 'source_identity_unknown'; Target = $pair[0]; Detail = [string]$_.Exception.Message; Blocking = $true })
            }
        }
        $specCount = $Specs.Count
        $specIndex = 0
        Write-Host "[0/$specCount] RUN content-manifests"
        foreach ($spec in $Specs) {
            $specIndex++
            Write-Host "[$specIndex/$specCount] RUN content-manifest $($spec.AppName)"
            foreach ($fileName in @($spec.RequiredFiles | Where-Object { $_ -cne 'bootstrap.json' })) {
                $sourceFile = Join-Path $ClientSourceDir ([string]$fileName)
                try {
                    $hash = (Get-FileHash -LiteralPath $sourceFile -Algorithm SHA256 -ErrorAction Stop).Hash.ToLowerInvariant()
                    $inventory.Add([pscustomobject]@{ Kind = 'client-source-file'; Name = [string]$fileName; Identity = $hash })
                } catch {
                    $findings.Add([pscustomobject]@{ Code = 'client_source_unknown'; Target = [string]$fileName; Detail = [string]$_.Exception.Message; Blocking = $true })
                }
            }
            $localPath = Join-Path ([string]$Config.PackagesBase) ([string]$spec.Folder)
            $sharePath = Join-Path ([string]$Config.ContentShare) ([string]$spec.Folder)
            try {
                if (-not (Test-Path -LiteralPath $localPath -PathType Container) -or
                    -not (Test-Path -LiteralPath $sharePath -PathType Container)) { throw 'local or UNC content folder missing' }
                $drift = @(Compare-VsClientContentManifest -StagedPath $localPath -PublishedPath $sharePath)
                foreach ($issue in $drift) {
                    $findings.Add([pscustomobject]@{ Code = 'content_manifest_drift'; Target = [string]$spec.AppName; Detail = [string]$issue; Blocking = $true })
                }
                $inventory.Add([pscustomobject]@{ Kind = 'content'; Name = [string]$spec.AppName; Local = $localPath; Unc = $sharePath; DriftCount = $drift.Count })
            } catch {
                $findings.Add([pscustomobject]@{ Code = 'content_inventory_unknown'; Target = [string]$spec.AppName; Detail = [string]$_.Exception.Message; Blocking = $true })
            }
            Write-Host "[$specIndex/$specCount] DONE content-manifest $($spec.AppName)"
        }
        # Equal marker text is only an identity hint: a copied directory can carry
        # the same file. Proving local/UNC object identity needs site evidence.
        $findings.Add([pscustomobject]@{ Code = 'source_mapping_unverified'; Target = 'PackagesBase/ContentShare'; Detail = 'Local and UNC paths need a site-verified same-directory proof'; Blocking = $true })
    } finally {
        Pop-Location
    }
    Write-Host "[1/$total] DONE source-identity"

    Write-Host "[2/$total] RUN site-scope"
    if ($SiteCode -cnotmatch '\A[A-Z0-9]{3}\z' -or [string]$Config.CoreLimitingCollectionId -cnotlike "$SiteCode*") {
        $findings.Add([pscustomobject]@{ Code = 'site_mismatch'; Target = 'CoreLimitingCollectionId'; Detail = 'Collection ID and active site differ'; Blocking = $true })
    }
    try {
        $collections = @(Get-CMDeviceCollection -CollectionId ([string]$Config.CoreLimitingCollectionId) -ErrorAction Stop)
        if ($collections.Count -ne 1 -or [string]$collections[0].CollectionID -cne [string]$Config.CoreLimitingCollectionId) {
            throw 'limiting collection is not uniquely visible'
        }
        $inventory.Add([pscustomobject]@{ Kind = 'collection'; Name = 'limiting'; Identity = [string]$collections[0].CollectionID })
    } catch {
        $findings.Add([pscustomobject]@{ Code = 'limiting_collection_unknown'; Target = [string]$Config.CoreLimitingCollectionId; Detail = [string]$_.Exception.Message; Blocking = $true })
    }
    $findings.Add([pscustomobject]@{ Code = 'rbac_scope_unverified'; Target = $SiteCode; Detail = 'Complete provider visibility and security scopes require independent site evidence'; Blocking = $true })
    Write-Host "[2/$total] DONE site-scope"

    Write-Host "[3/$total] RUN legacy-applications"
    $legacyNames = @((Get-VsClientPackagingPolicy).LegacyApplicationNames)
    $legacyTotal = $legacyNames.Count
    $legacyIndex = 0
    Write-Host "[0/$legacyTotal] RUN legacy-applications"
    foreach ($name in $legacyNames) {
        $legacyIndex++
        Write-Host "[$legacyIndex/$legacyTotal] RUN legacy-application $name"
        try {
            $appMatches = @(Get-CMApplication -Name $name -ShowHidden -DisableWildcardHandling -ErrorAction Stop | Where-Object { (Get-VsObjectPropertyText -Object $_ -Names @('LocalizedDisplayName', 'Name')) -ceq $name })
            foreach ($app in $appMatches) {
                $inventory.Add([pscustomobject]@{ Kind = 'legacy-application'; Name = $name; Identity = (Get-VsObjectPropertyText -Object $app -Names @('ModelName')); CiId = (Get-VsObjectPropertyText -Object $app -Names @('CI_ID')) })
            }
            if ($appMatches.Count -gt 1) { $findings.Add([pscustomobject]@{ Code = 'legacy_ambiguous'; Target = $name; Detail = 'Multiple exact-name applications'; Blocking = $true }) }
            if ($appMatches.Count -eq 1) {
                try {
                    $legacyTypes = @(Get-CMDeploymentType -ApplicationName $name -ErrorAction Stop)
                    foreach ($legacyType in $legacyTypes) {
                        $inventory.Add([pscustomobject]@{ Kind = 'legacy-deployment-type'; Name = $name; Identity = (Get-VsObjectPropertyText -Object $legacyType -Names @('CI_ID')) })
                        foreach ($row in @(Get-VsClientSupersedenceRows -ApplicationName $name -DeploymentType $legacyType)) { $inventory.Add($row) }
                    }
                } catch {
                    $findings.Add([pscustomobject]@{ Code = 'legacy_supersedence_unknown'; Target = $name; Detail = [string]$_.Exception.Message; Blocking = $true })
                }
            }
        } catch {
            $findings.Add([pscustomobject]@{ Code = 'legacy_inventory_unknown'; Target = $name; Detail = [string]$_.Exception.Message; Blocking = $true })
        } finally {
            Write-Host "[$legacyIndex/$legacyTotal] DONE legacy-application $name"
        }
    }
    Write-Host "[3/$total] DONE legacy-applications"

    Write-Host "[4/$total] RUN managed-applications"
    $managedIndex = 0
    Write-Host "[0/$($Specs.Count)] RUN managed-applications"
    foreach ($spec in $Specs) {
        $managedIndex++
        $name = [string]$spec.AppName
        Write-Host "[$managedIndex/$($Specs.Count)] RUN managed-application $name"
        try {
            $appMatches = @(Get-CMApplication -Name $name -ShowHidden -DisableWildcardHandling -ErrorAction Stop | Where-Object { (Get-VsObjectPropertyText -Object $_ -Names @('LocalizedDisplayName', 'Name')) -ceq $name })
            if ($appMatches.Count -gt 1) { throw 'multiple exact-name applications' }
            if ($appMatches.Count -eq 0) {
                $inventory.Add([pscustomobject]@{ Kind = 'managed-application'; Name = $name; State = 'not-visible' })
                $findings.Add([pscustomobject]@{ Code = 'application_absence_unverified'; Target = $name; Detail = 'Zero visible objects cannot establish absence without full RBAC scope'; Blocking = $true })
                continue
            }
            $app = $appMatches[0]
            $ciId = Get-VsObjectPropertyText -Object $app -Names @('CI_ID')
            $model = Get-VsObjectPropertyText -Object $app -Names @('ModelName')
            $scopes = if ($app.PSObject.Properties['SecuredScopeNames']) { @($app.SecuredScopeNames) } else { @() }
            $inventory.Add([pscustomobject]@{ Kind = 'managed-application'; Name = $name; State = 'visible'; CiId = $ciId; Identity = $model; SecurityScopes = @($scopes) })
            if (-not $ciId -or -not $model -or -not (Test-VsClientApplicationOwnership -Application $app -Spec $spec -AppFolder 'VirtuSphere_Core')) {
                $findings.Add([pscustomobject]@{ Code = 'application_identity_unverified'; Target = $name; Detail = 'CI_ID, ModelName or managed marker missing'; Blocking = $true })
            }
            $types = @(Get-CMDeploymentType -ApplicationName $name -ErrorAction Stop)
            if ($types.Count -ne 1) {
                $findings.Add([pscustomobject]@{ Code = 'deployment_type_count'; Target = $name; Detail = "Visible deployment types: $($types.Count)"; Blocking = $true })
                continue
            }
            $codes = @(Get-CMDeploymentTypeReturnCode -InputObject $types[0] -ErrorAction Stop)
            $issues = @(Get-VsClientDeploymentTypeContractIssues -DeploymentType $types[0] -Spec $spec -ContentLocation (Join-Path ([string]$Config.ContentShare) ([string]$spec.Folder)) -InstallCommand (Get-VsClientInstallCommand -Spec $spec) -ReturnCodes $codes)
            foreach ($issue in $issues) { $findings.Add([pscustomobject]@{ Code = 'deployment_type_drift'; Target = $name; Detail = [string]$issue; Blocking = $true }) }
            try {
                foreach ($row in @(Get-VsClientSupersedenceRows -ApplicationName $name -DeploymentType $types[0])) { $inventory.Add($row) }
            } catch {
                $findings.Add([pscustomobject]@{ Code = 'supersedence_inventory_unknown'; Target = $name; Detail = [string]$_.Exception.Message; Blocking = $true })
            }
            $groups = @(Get-CMDeploymentTypeDependencyGroup -InputObject $types[0] -ErrorAction Stop)
            foreach ($group in $groups) {
                $deps = @(Get-CMDeploymentTypeDependency -InputObject $group -ErrorAction Stop)
                $inventory.Add([pscustomobject]@{ Kind = 'dependency-group'; Name = $name; Group = (Get-VsObjectPropertyText -Object $group -Names @('GroupName', 'LocalizedDisplayName')); TargetIds = @($deps | ForEach-Object { Get-VsObjectPropertyText -Object $_ -Names @('CI_ID') }) })
            }
        } catch {
            $findings.Add([pscustomobject]@{ Code = 'application_inventory_unknown'; Target = $name; Detail = [string]$_.Exception.Message; Blocking = $true })
        } finally {
            Write-Host "[$managedIndex/$($Specs.Count)] DONE managed-application $name"
        }
    }
    Write-Host "[4/$total] DONE managed-applications"

    Write-Host "[5/$total] RUN deployment-references"
    $deploymentNames = @(@($Specs | ForEach-Object { [string]$_.AppName }) + $legacyNames)
    $deploymentIndex = 0
    Write-Host "[0/$($deploymentNames.Count)] RUN application-deployments"
    foreach ($name in $deploymentNames) {
        $deploymentIndex++
        Write-Host "[$deploymentIndex/$($deploymentNames.Count)] RUN application-deployment $name"
        try {
            $deployments = @(Get-CMApplicationDeployment -Name $name -ErrorAction Stop)
            foreach ($deployment in $deployments) {
                $assignmentId = Get-VsObjectPropertyText -Object $deployment -Names @('AssignmentID', 'AssignmentId')
                $collectionId = Get-VsObjectPropertyText -Object $deployment -Names @('TargetCollectionID', 'CollectionID', 'CollectionId')
                $offerType = Get-VsObjectPropertyText -Object $deployment -Names @('OfferTypeID')
                $outsideInstall = Get-VsObjectPropertyText -Object $deployment -Names @('OverrideServiceWindows')
                $outsideRestart = Get-VsObjectPropertyText -Object $deployment -Names @('RebootOutsideOfServiceWindows')
                $enabled = Get-VsObjectPropertyText -Object $deployment -Names @('Enabled')
                $inventory.Add([pscustomobject]@{
                    Kind = 'application-deployment'; Name = $name; Identity = $assignmentId
                    Collection = $collectionId; OfferTypeId = $offerType
                    OverrideServiceWindows = $outsideInstall
                    RebootOutsideOfServiceWindows = $outsideRestart
                    Enabled = $enabled
                    Deadline = (Get-VsObjectPropertyText -Object $deployment -Names @('EnforcementDeadline'))
                })
                if ($name -eq 'client_staticip') {
                    if (-not $assignmentId -or -not $collectionId -or -not $offerType -or -not $outsideInstall -or -not $outsideRestart -or -not $enabled) {
                        $findings.Add([pscustomobject]@{ Code = 'core_deployment_fields_unknown'; Target = $name; Detail = 'Assignment ID, collection or policy fields incomplete'; Blocking = $true })
                    } elseif ($offerType -ne '0' -or $outsideInstall -ne 'True' -or $outsideRestart -ne 'True' -or $enabled -ne 'True') {
                        $findings.Add([pscustomobject]@{ Code = 'core_deployment_policy_drift'; Target = $name; Detail = 'Required, enabled, install and reboot outside maintenance windows expected'; Blocking = $true })
                    }
                } elseif ($name -cin $legacyNames) {
                    # A deployment the retirement tool already disabled no longer
                    # targets clients; an enabled or unreadable state still blocks.
                    if ($enabled -ceq 'False') {
                        $findings.Add([pscustomobject]@{ Code = 'legacy_deployment_disabled'; Target = $name; Detail = ('Assignment {0} on collection {1} is disabled' -f $assignmentId, $collectionId); Blocking = $false })
                    } else {
                        $findings.Add([pscustomobject]@{ Code = 'legacy_deployment_present'; Target = $name; Detail = ('Assignment {0} targets collection {1}' -f $assignmentId, $collectionId); Blocking = $true })
                    }
                } else {
                    $findings.Add([pscustomobject]@{ Code = 'internal_phase_deployment_present'; Target = $name; Detail = ('Assignment {0} targets collection {1}' -f $assignmentId, $collectionId); Blocking = $true })
                }
            }
        } catch {
            $findings.Add([pscustomobject]@{ Code = 'deployment_inventory_unknown'; Target = $name; Detail = [string]$_.Exception.Message; Blocking = $true })
        } finally {
            Write-Host "[$deploymentIndex/$($deploymentNames.Count)] DONE application-deployment $name"
        }
    }
    Write-Host '[0/2] RUN shared-references'
    Write-Host '[1/2] RUN application-groups'
    try {
        $appGroups = @(Get-CMApplicationGroup -ShowHidden -ErrorAction Stop)
        foreach ($group in $appGroups) {
            $inventory.Add([pscustomobject]@{
                Kind = 'application-group'
                Name = (Get-VsObjectPropertyText -Object $group -Names @('LocalizedDisplayName', 'Name'))
                Identity = (Get-VsObjectPropertyText -Object $group -Names @('ModelName'))
                CiId = (Get-VsObjectPropertyText -Object $group -Names @('CI_ID'))
            })
        }
        $inventory.Add([pscustomobject]@{ Kind = 'application-group-scan'; Name = 'all-visible'; Count = $appGroups.Count })
    } catch {
        $findings.Add([pscustomobject]@{ Code = 'application_group_inventory_unknown'; Target = 'all-visible'; Detail = [string]$_.Exception.Message; Blocking = $true })
    } finally {
        Write-Host '[1/2] DONE application-groups'
    }
    Write-Host '[2/2] RUN task-sequence-references'
    try {
        # The provider class exposes PackageID and exact referenced app IDs.
        # It is queried locally because this installer runs on the MECM server;
        # a missing local provider is an unknown result, never zero references.
        $taskRefs = @(Get-CimInstance -Namespace ("root/SMS/site_{0}" -f $SiteCode) -ClassName 'SMS_TaskSequenceAppReferencesInfo' -ErrorAction Stop)
        $knownApps = @($inventory.ToArray() | Where-Object { $_.Kind -in @('managed-application', 'legacy-application') })
        foreach ($reference in $taskRefs) {
            $packageId = Get-VsObjectPropertyText -Object $reference -Names @('PackageID')
            $model = Get-VsObjectPropertyText -Object $reference -Names @('RefAppModelName')
            $ciId = Get-VsObjectPropertyText -Object $reference -Names @('RefAppCI_ID')
            if (-not $packageId -or (-not $model -and -not $ciId)) { throw 'task sequence reference has incomplete identity' }
            foreach ($app in @($knownApps | Where-Object { ($model -and $_.Identity -ceq $model) -or ($ciId -and $_.CiId -eq $ciId) })) {
                $inventory.Add([pscustomobject]@{ Kind = 'task-sequence-reference'; Name = [string]$app.Name; Identity = $packageId; RefAppModelName = $model; RefAppCiId = $ciId })
            }
        }
        $inventory.Add([pscustomobject]@{ Kind = 'task-sequence-reference-scan'; Name = 'all-visible'; Count = $taskRefs.Count })
    } catch {
        $findings.Add([pscustomobject]@{ Code = 'task_sequence_references_unknown'; Target = 'all-visible'; Detail = [string]$_.Exception.Message; Blocking = $true })
    } finally {
        Write-Host '[2/2] DONE task-sequence-references'
    }
    $findings.Add([pscustomobject]@{ Code = 'references_unverified'; Target = 'reverse-supersedence/application-group-membership'; Detail = 'Forward supersedence and visible group identities do not prove complete reverse references'; Blocking = $true })
    Write-Host "[5/$total] DONE deployment-references"

    Write-Host "[6/$total] RUN distribution-and-plan"
    if ([string]::IsNullOrEmpty([string]$Config.DpGroupName)) {
        $findings.Add([pscustomobject]@{ Code = 'dp_target_unset'; Target = 'DpGroupName'; Detail = 'Distribution target requires a site decision'; Blocking = $true })
    } else {
        try {
            $groups = @(Get-CMDistributionPointGroup -Name ([string]$Config.DpGroupName) -ErrorAction Stop)
            if ($groups.Count -ne 1) { throw 'DP group is not uniquely visible' }
            $inventory.Add([pscustomobject]@{ Kind = 'distribution-point-group'; Name = [string]$Config.DpGroupName; Identity = (Get-VsObjectPropertyText -Object $groups[0] -Names @('GroupID', 'GroupId')) })
            $points = @(Get-CMDistributionPoint -DistributionPointGroup $groups[0] -ErrorAction Stop)
            if ($points.Count -eq 0) { throw 'DP group has no visible distribution points' }
            $pointIndex = 0
            Write-Host "[0/$($points.Count)] RUN distribution-points"
            foreach ($point in $points) {
                $pointIndex++
                Write-Host "[$pointIndex/$($points.Count)] RUN distribution-point"
                $server = Get-VsObjectPropertyText -Object $point -Names @('SiteSystemServerName', 'NetworkOSPath', 'ServerName')
                if (-not $server) { throw 'distribution point identity missing' }
                $inventory.Add([pscustomobject]@{ Kind = 'distribution-point'; Name = [string]$Config.DpGroupName; Identity = $server })
                Write-Host "[$pointIndex/$($points.Count)] DONE distribution-point"
            }
        } catch {
            $findings.Add([pscustomobject]@{ Code = 'dp_group_unknown'; Target = [string]$Config.DpGroupName; Detail = [string]$_.Exception.Message; Blocking = $true })
        }
    }
    $findings.Add([pscustomobject]@{ Code = 'deployment_policy_unverified'; Target = 'core'; Detail = 'Runtime, outside-window flags, implicit uninstall and simulation require site evidence'; Blocking = $true })
    $planRows = @($inventory.ToArray() | Sort-Object Kind, Name, Identity)
    $planInputs = @($Config.Keys | Sort-Object | ForEach-Object { [pscustomobject]@{ Kind = 'config'; Name = [string]$_; Value = [string]$Config[$_] } })
    $planInputs += $planRows
    $planInputs += @($findings.ToArray() | Sort-Object Code, Target, Detail)
    $planId = Get-VsClientPreflightPlanId -Rows @($planInputs)
    Write-Host "[6/$total] DONE distribution-and-plan"
    return [pscustomobject]@{
        SchemaVersion = 1
        SiteCode = $SiteCode
        PlanId = $planId
        CanApply = $false
        Inventory = @($planRows)
        Findings = @($findings.ToArray())
    }
}
