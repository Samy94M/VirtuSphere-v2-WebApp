#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Rueckbau der manuell angelegten Altanwendungen client_getinfo und
    client_getinfo_2.1 bis Retire. Loescht nichts.

.DESCRIPTION
    Die verwaltete getinfo-Phase ist ausschliesslich client_getInfos. Die beiden
    Altanwendungen werden nie adoptiert (Entscheidung 11), sondern hier aus dem
    Targeting genommen und auf Retired gesetzt. Loeschen ist ein spaeterer,
    getrennt freizugebender Auftrag und nicht Teil dieses Skripts.

    Ohne -Apply laeuft nur der lesende Bericht. Er nennt je Altanwendung
    Deployments, abhaengige Deployment Types und Tasksequenzen, Supersedence,
    Application-Group-Mitgliedschaft, Inhalt und Skriptversion sowie eine
    Plan-ID.

    Stufe DisableDeployments deaktiviert aktive Deployments der Altanwendungen
    (umkehrbar) und nur, wenn die neue Kette nachweislich laeuft.
    Stufe Retire setzt eine Altanwendung auf Retired (umkehrbar mit
    Resume-CMApplication), wenn kein aktives Deployment, keine abhaengige
    Anwendung oder Tasksequenz, keine Supersedence und keine Gruppe mehr darauf
    zeigt. Eine ungenutzte Altanwendung ist damit auch vor dem Cutover bereit.
    Hatte die Anwendung Deployments, verlangt Retire zusaetzlich
    -ConfirmPolicyConvergence: der Betrieb bestaetigt, dass repraesentative
    Clients die Policy-Aenderung empfangen haben.

    Jede Aenderung wird unmittelbar vorher erneut gelesen, einzeln bestaetigt
    (ConfirmImpact High, -WhatIf moeglich), vorher und nachher im Journal unter
    %ProgramData%\VirtuSphere\MECM\LegacyRetirement festgehalten und danach
    unabhaengig zurueckgelesen. Der erste Fehler beendet die Stufe.

.EXAMPLE
    .\retire-VirtuSphere-LegacyGetInfo.ps1
    Nur Bericht mit Plan-ID.

.EXAMPLE
    .\retire-VirtuSphere-LegacyGetInfo.ps1 -Stage Retire -Apply -PlanId <Plan-ID aus dem Bericht>

.EXAMPLE
    .\retire-VirtuSphere-LegacyGetInfo.ps1 -Stage DisableDeployments -Apply -PlanId <Plan-ID> -WhatIf
#>
[CmdletBinding(SupportsShouldProcess, ConfirmImpact = 'High')]
param(
    [ValidateSet('DisableDeployments', 'Retire')][string]$Stage,
    [switch]$Apply,
    [string]$PlanId,
    [switch]$ConfirmPolicyConvergence
)

$ErrorActionPreference = 'Stop'
# Version 1.0, nicht Latest: siehe die Begruendung in mecm\VirtuSphere-Common.ps1.
Set-StrictMode -Version 1.0

. (Join-Path $PSScriptRoot 'mecm\VirtuSphere-Common.ps1')
. (Join-Path $PSScriptRoot 'mecm\VirtuSphere-ClientPackaging.ps1')
. (Join-Path $PSScriptRoot 'mecm\VirtuSphere-ClientPreflight.ps1')
. (Join-Path $PSScriptRoot 'mecm\VirtuSphere-LegacyRetirement.ps1')

if ($Apply) {
    if (-not $Stage) { throw 'Mit -Apply ist -Stage DisableDeployments oder -Stage Retire Pflicht.' }
    if ($PlanId -cnotmatch '\A[0-9a-f]{64}\z') { throw 'Mit -Apply ist die Plan-ID aus der letzten Vorschau Pflicht (-PlanId).' }
} elseif ($Stage -or $PlanId -or $ConfirmPolicyConvergence) {
    throw '-Stage, -PlanId und -ConfirmPolicyConvergence gelten nur zusammen mit -Apply. Ohne -Apply laeuft nur der Bericht.'
}

$config = Get-VsConfig
$logRoot = if ($config) { $config.LogRoot } else { $null }
Initialize-VsLog -Component 'legacy-retirement' -LogRoot $logRoot
$siteCode = Initialize-VsCmSite -Config $config
if (-not $siteCode) { throw 'MECM-Site nicht initialisierbar (MECM-Konsole/Site-Drive pruefen).' }

$exitCode = 1
$mutex = New-Object Threading.Mutex($false, 'Global\VirtuSphereLegacyRetirement')
$locked = $false
try {
    try { $locked = $mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $locked = $true }
    if (-not $locked) { throw 'Ein anderer Rueckbau-Lauf ist aktiv; es wurde nichts geaendert.' }

    $plan = Get-VsLegacyRetirementReport -Specs (Get-VsClientAppSpecs) -LegacyNames @((Get-VsClientPackagingPolicy).LegacyApplicationNames)
    $plan | ConvertTo-Json -Depth 8 | Write-Output
    Write-VsLegacyRetirementSummary -Plan $plan
    $ready = @($plan.Items | Where-Object { [string]$_.State -ceq 'ready' })
    Write-VsLog -Level INFO -Context 'legacy-retirement' -Message ('Plan {0}: {1} Schritt(e) bereit, {2} blockiert' -f $plan.PlanId, $ready.Count, (@($plan.Items).Count - $ready.Count))

    if (-not $Apply) {
        foreach ($stageName in @('DisableDeployments', 'Retire')) {
            if (@($ready | Where-Object { [string]$_.Stage -ceq $stageName }).Count -gt 0) {
                Write-Host ('Naechster Schritt: .\retire-VirtuSphere-LegacyGetInfo.ps1 -Stage {0} -Apply -PlanId {1}' -f $stageName, $plan.PlanId)
            }
        }
        $exitCode = 0
    } else {
        $journalDirectory = Join-Path $env:ProgramData 'VirtuSphere\MECM\LegacyRetirement'
        $journalPath = Join-Path $journalDirectory ('{0}.jsonl' -f $plan.PlanId)
        $actor = '{0}\{1}' -f $env:USERDOMAIN, $env:USERNAME
        $results = @(Invoke-VsLegacyRetirementStage -Plan $plan -Stage $Stage -ApprovedPlanId $PlanId `
            -PolicyConvergenceConfirmed:$ConfirmPolicyConvergence -WhatIf:$WhatIfPreference `
            -ReadApplication { param($name) Read-VsLegacyApplicationEvidence -Name $name -SkipContent } `
            -DisableDeployment {
                param($item)
                # Enabled ist an SMS_ApplicationAssignment les- und schreibbar;
                # das CM-Cmdlet fuer Deployments kennt dafuer keinen Parameter.
                $assignments = @(Get-CimInstance -Namespace ('root/SMS/site_{0}' -f $siteCode) -ClassName 'SMS_ApplicationAssignment' `
                    -Filter ('AssignmentID = {0}' -f [int]$item.AssignmentId) -ErrorAction Stop)
                if ($assignments.Count -ne 1) { throw ('Deployment {0} ist nicht eindeutig lesbar.' -f $item.AssignmentId) }
                Set-CimInstance -InputObject $assignments[0] -Property @{ Enabled = $false } -Confirm:$false -ErrorAction Stop | Out-Null
            } `
            -RetireApplication {
                param($item)
                Suspend-CMApplication -Id ([int]$item.CiId) -Confirm:$false -ErrorAction Stop | Out-Null
            } `
            -WriteJournal {
                param($entry)
                $entry | Add-Member -NotePropertyName Actor -NotePropertyValue $actor -Force
                $entry | Add-Member -NotePropertyName Computer -NotePropertyValue $env:COMPUTERNAME -Force
                [IO.Directory]::CreateDirectory($journalDirectory) | Out-Null
                [IO.File]::AppendAllText($journalPath, ((ConvertTo-Json -InputObject $entry -Compress -Depth 4) + "`r`n"), (New-Object Text.UTF8Encoding($false)))
            })
        foreach ($result in $results) {
            Write-VsLog -Level INFO -Context 'legacy-retirement' -Message ('{0} {1} {2}: {3} {4}' -f $Stage, $result.Item.Name, $result.Item.AssignmentId, $result.Result, (@($result.Reasons) + @($result.Error) -join ' '))
        }
        $failed = @($results | Where-Object { $_.Result -ceq 'failed' }).Count
        $blocked = @($results | Where-Object { $_.Result -ceq 'blocked' }).Count
        Write-Host ('Stufe {0}: {1} erledigt, {2} bereits erledigt, {3} blockiert, {4} nicht bestaetigt, {5} fehlgeschlagen. Journal: {6}' -f $Stage,
            @($results | Where-Object { $_.Result -ceq 'done' }).Count, @($results | Where-Object { $_.Result -ceq 'already' }).Count,
            $blocked, @($results | Where-Object { $_.Result -ceq 'not-confirmed' }).Count, $failed, $journalPath)
        $exitCode = if ($failed -eq 0 -and $blocked -eq 0) { 0 } else { 1 }
    }
} finally {
    if ($locked) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
exit $exitCode
