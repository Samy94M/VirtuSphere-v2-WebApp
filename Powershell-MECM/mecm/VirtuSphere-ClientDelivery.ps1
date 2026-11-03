# MC03 delivery decisions. Pure functions without import-time side effects
# and without Configuration Manager cmdlets: the caller reads the site and
# passes neutral evidence; these functions only decide. Unknown or
# contradictory evidence is always "blocked", never a guessed request.
# The installer does not call this module while MC-R4 blocks Apply.
Set-StrictMode -Version 1.0

# One flushed JSON line per journal event (UTF-8 without BOM). The line is on
# disk before the next rename, so an interrupted run leaves an intent without
# outcome instead of an unexplained folder state.
function Write-VsClientDeliveryJournal {
    param([Parameter(Mandatory)][string]$JournalPath, [Parameter(Mandatory)][System.Collections.IDictionary]$Entry)
    $line = ($Entry | ConvertTo-Json -Compress -Depth 6) + "`n"
    $bytes = (New-Object Text.UTF8Encoding($false)).GetBytes($line)
    $stream = New-Object IO.FileStream($JournalPath, [IO.FileMode]::Append, [IO.FileAccess]::Write, [IO.FileShare]::Read)
    try {
        $stream.Write($bytes, 0, $bytes.Length)
        $stream.Flush($true)
    } finally { $stream.Dispose() }
}

# Swaps the four validated stage folders into PackagesBase as one set. The
# previous four folders move into one sibling backup set on the same volume
# and stay there until a successful receipt or an explicit recovery decision.
# Any failed rename restores the complete previous set; no CM write may follow
# a result other than "activated". The caller validates the stage beforehand.
function Invoke-VsClientSourceActivation {
    param(
        [Parameter(Mandatory)][string]$PackagesBase,
        [Parameter(Mandatory)][string]$StageRoot,
        [Parameter(Mandatory)][object[]]$Specs,
        [Parameter(Mandatory)][string]$BundleId,
        [Parameter(Mandatory)][string]$JournalPath
    )
    if ($BundleId -cnotmatch '\A[0-9a-f]{64}\z') { throw 'BundleId must be lowercase SHA-256 hex.' }
    $base = [IO.Path]::GetFullPath($PackagesBase).TrimEnd('\')
    $stage = [IO.Path]::GetFullPath($StageRoot).TrimEnd('\')
    # Only a direct child of PackagesBase is a same-volume sibling rename.
    if (-not [string]::Equals([IO.Path]::GetDirectoryName($stage), $base, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Activation stage must be a direct child of PackagesBase.'
    }
    $vsFsRoot = [IO.Path]::GetPathRoot($base)
    Push-Location -LiteralPath $vsFsRoot
    try {
        foreach ($spec in $Specs) {
            if (-not (Test-Path -LiteralPath (Join-Path $stage $spec.Folder) -PathType Container)) {
                throw ('Activation stage lacks folder {0}.' -f $spec.Folder)
            }
        }
        $backupRoot = Join-Path $base ('.virtusphere-client-backup-' + $BundleId + '-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $backupRoot -ErrorAction Stop | Out-Null
        Write-VsClientDeliveryJournal -JournalPath $JournalPath -Entry ([ordered]@{
            Event = 'intent'; Step = 'source-activation'; BundleId = $BundleId; Stage = $stage; Backup = $backupRoot
            AtUtc = [DateTime]::UtcNow.ToString('o', [Globalization.CultureInfo]::InvariantCulture)
        })
        $done = New-Object System.Collections.Generic.List[object]
        try {
            foreach ($spec in $Specs) {
                $active = Join-Path $base $spec.Folder
                $entry = [pscustomobject]@{ Folder = [string]$spec.Folder; BackedUp = $false; Activated = $false }
                $done.Add($entry)
                if (Test-Path -LiteralPath $active) {
                    Move-Item -LiteralPath $active -Destination (Join-Path $backupRoot $spec.Folder) -ErrorAction Stop
                    $entry.BackedUp = $true
                }
                Move-Item -LiteralPath (Join-Path $stage $spec.Folder) -Destination $active -ErrorAction Stop
                $entry.Activated = $true
            }
        } catch {
            $failure = [string]$_.Exception.Message
            $outcome = 'rolled_back'
            for ($i = $done.Count - 1; $i -ge 0; $i--) {
                $entry = $done[$i]
                $active = Join-Path $base $entry.Folder
                try {
                    if ($entry.Activated) { Move-Item -LiteralPath $active -Destination (Join-Path $stage $entry.Folder) -ErrorAction Stop }
                    if ($entry.BackedUp) { Move-Item -LiteralPath (Join-Path $backupRoot $entry.Folder) -Destination $active -ErrorAction Stop }
                } catch {
                    $outcome = 'rollback_failed'
                    $failure += ('; rollback {0}: {1}' -f $entry.Folder, $_.Exception.Message)
                }
            }
            Write-VsClientDeliveryJournal -JournalPath $JournalPath -Entry ([ordered]@{
                Event = 'outcome'; Step = 'source-activation'; BundleId = $BundleId; Outcome = $outcome; Detail = $failure
                AtUtc = [DateTime]::UtcNow.ToString('o', [Globalization.CultureInfo]::InvariantCulture)
            })
            return [pscustomobject]@{ Status = $outcome; BackupRoot = $backupRoot; Detail = $failure }
        }
        Write-VsClientDeliveryJournal -JournalPath $JournalPath -Entry ([ordered]@{
            Event = 'outcome'; Step = 'source-activation'; BundleId = $BundleId; Outcome = 'activated'; Detail = ''
            AtUtc = [DateTime]::UtcNow.ToString('o', [Globalization.CultureInfo]::InvariantCulture)
        })
        return [pscustomobject]@{ Status = 'activated'; BackupRoot = $backupRoot; Detail = '' }
    } finally {
        Pop-Location
    }
}

# One decision per application content and distribution point. Association
# is whether the DP is a target of this content at all (absent, present,
# unknown); DpState is the observed copy state (none, ready, failed,
# in_progress, removing, unknown).
function Get-VsClientDistributionAction {
    param(
        [Parameter(Mandatory)][ValidateSet('absent', 'present', 'unknown')][string]$Association,
        [Parameter(Mandatory)][bool]$ContentChanged,
        [Parameter(Mandatory)][string]$DpState
    )
    $blocked = { param($reason) [pscustomobject]@{ Action = 'blocked'; Reason = $reason } }
    if ($Association -eq 'unknown') { return (& $blocked 'association-unknown') }
    if ($Association -eq 'absent') {
        # A DP without association has never received this content, whether
        # the content changed or the DP joined the group later.
        if ($DpState -ne 'none') { return (& $blocked ('absent-association-with-state:' + $DpState)) }
        return [pscustomobject]@{ Action = 'initial'; Reason = 'no-association' }
    }
    switch ($DpState) {
        'ready' {
            if ($ContentChanged) { return [pscustomobject]@{ Action = 'update'; Reason = 'content-changed' } }
            return [pscustomobject]@{ Action = 'none'; Reason = 'current-copy-ready' }
        }
        'failed' {
            if ($ContentChanged) { return [pscustomobject]@{ Action = 'update'; Reason = 'content-changed' } }
            return [pscustomobject]@{ Action = 'redistribute'; Reason = 'unchanged-copy-failed' }
        }
        default { return (& $blocked ('dp-state:' + $DpState)) }
    }
}

# The target graph from the one spec table: each dependency is its own group
# "Requires <predecessor>" with exactly one auto-install target.
function Get-VsClientDesiredDependencyEdges {
    param([Parameter(Mandatory)][object[]]$Specs)
    Assert-VsClientAppSpecGraph -Specs $Specs
    foreach ($spec in $Specs) {
        if (-not $spec.DependsOn) { continue }
        [pscustomobject]@{ App = [string]$spec.AppName; DependsOn = [string]$spec.DependsOn; Group = ('Requires {0}' -f $spec.DependsOn) }
    }
}

function Test-VsClientDependencyCycle {
    param([Parameter(Mandatory)][AllowEmptyCollection()][object[]]$Edges)
    $next = @{}
    foreach ($edge in $Edges) {
        if (-not $next.ContainsKey($edge.App)) { $next[$edge.App] = @() }
        $next[$edge.App] += $edge.DependsOn
    }
    $state = @{}
    $visit = $null
    $visit = {
        param($node)
        if ($state[$node] -eq 'open') { return $true }
        if ($state[$node] -eq 'done') { return $false }
        $state[$node] = 'open'
        foreach ($target in @($next[$node])) {
            if ($null -ne $target -and (& $visit $target)) { return $true }
        }
        $state[$node] = 'done'
        return $false
    }
    foreach ($node in @($next.Keys)) {
        if (& $visit $node) { return $true }
    }
    return $false
}

# Computes every graph step before any mutation. Removals run first, from the
# deployable entry backwards; removing an edge can never create a cycle. Only
# target edges remain afterwards, so adding them in chain order stays acyclic.
# Any change needs deployment-free evidence (no active or converging Core
# policy); a partial execution is reported by the executor, not retried here.
function Get-VsClientGraphReconciliationPlan {
    param(
        [Parameter(Mandatory)][object[]]$Specs,
        [Parameter(Mandatory)][AllowEmptyCollection()][object[]]$ActualGroups,
        [Parameter(Mandatory)][bool]$DeploymentFree
    )
    $findings = New-Object System.Collections.Generic.List[string]
    $desired = @(Get-VsClientDesiredDependencyEdges -Specs $Specs)
    $order = @{}
    for ($i = 0; $i -lt $Specs.Count; $i++) { $order[[string]$Specs[$i].AppName] = $i }
    $removable = @($Specs | ForEach-Object { [string]$_.AppName }) + @((Get-VsClientPackagingPolicy).LegacyApplicationNames)

    $actualEdges = @()
    $keep = @{}
    $removals = @()
    foreach ($group in $ActualGroups) {
        $app = [string]$group.App
        if (-not $order.ContainsKey($app)) { $findings.Add('unexpected-owner:' + $app); continue }
        if ([string]$group.State -ne 'confirmed') { $findings.Add(('group-state:{0}:{1}' -f $app, $group.Group)); continue }
        $targets = @($group.Targets)
        foreach ($target in $targets) {
            if ($removable -cnotcontains [string]$target.App) { $findings.Add(('foreign-dependency:{0}:{1}' -f $app, $target.App)) }
            $actualEdges += [pscustomobject]@{ App = $app; DependsOn = [string]$target.App }
        }
        $match = @($desired | Where-Object { $_.App -ceq $app -and $_.Group -ceq [string]$group.Group })
        $isTarget = $match.Count -eq 1 -and $targets.Count -eq 1 -and [string]$targets[0].App -ceq $match[0].DependsOn -and $targets[0].AutoInstall -eq $true
        if ($isTarget -and -not $keep.ContainsKey($app)) { $keep[$app] = $true }
        else { $removals += [pscustomobject]@{ Action = 'remove-group'; App = $app; Group = [string]$group.Group; Order = $order[$app] } }
    }
    if (Test-VsClientDependencyCycle -Edges $actualEdges) { $findings.Add('actual-graph-cycle') }

    # Ordinal keys: a culture-aware sort would order groups differently per
    # server locale, and the plan must be identical wherever it is computed.
    $byKey = @{}
    $keys = New-Object System.Collections.Generic.List[string]
    foreach ($removal in $removals) {
        $key = '{0:D3}|{1}|{2}' -f (999 - $removal.Order), $removal.Group, $keys.Count
        $byKey[$key] = $removal
        $keys.Add($key)
    }
    $keys.Sort([StringComparer]::Ordinal)
    $steps = @($keys | ForEach-Object { $r = $byKey[$_]; [pscustomobject]@{ Action = $r.Action; App = $r.App; Group = $r.Group } })
    foreach ($edge in $desired) {
        if (-not $keep.ContainsKey($edge.App)) {
            $steps += [pscustomobject]@{ Action = 'add-dependency'; App = $edge.App; Group = $edge.Group; DependsOn = $edge.DependsOn }
        }
    }
    if ($steps.Count -gt 0 -and -not $DeploymentFree) { $findings.Add('deployment-free-evidence-missing') }
    if ($findings.Count -gt 0) {
        return [pscustomobject]@{ Status = 'blocked'; Findings = @($findings.ToArray()); Steps = @() }
    }
    return [pscustomobject]@{ Status = 'ready'; Findings = @(); Steps = @($steps) }
}
