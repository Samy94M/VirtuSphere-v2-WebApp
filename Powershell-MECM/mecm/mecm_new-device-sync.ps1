#Requires -Version 5.1
# ============================================================================
# mecm_new-device-sync.ps1 - synchronisiert VMs aus der VirtuSphere-Datenbank
# nach MECM: importiert Geraete, approved sie, weist OS-/Paket-/Mission-
# Collections zu und meldet die ResourceID zurueck.
# Laeuft als geplante Aufgabe "VirtuSphere MECM Devices Sync".
#
# Haertung/Optimierung gegenueber V2:
#  - Konfiguration aus Registry (keine harten IPs/Site-Codes)
#  - Leerlauf-Abkuerzung: bei 0 Devices werden die teuren MECM-Vollabfragen
#    uebersprungen (Normalfall alle 10s ist damit fast kostenlos)
#  - Collection-Cache je Scan statt Get-CMDeviceCollection pro Device/Paket
#  - Task-Sequence-Collections werden EINMAL pro Scan geprueft, nicht je Device
#  - MAC-Vergleich normalisiert (keine False-Positive-Konfliktwarnungen mehr)
#  - eingebettetes mission-Objekt aus getDeviceList statt N+1 getMissionName
#  - Heartbeat je Scan; Site-Drive-Recovery nach wiederholten Fehlern
# ============================================================================

. "$PSScriptRoot\VirtuSphere-Common.ps1"
. "$PSScriptRoot\VirtuSphere-MembershipJournal.ps1"

$config = Get-VsConfig
if (-not $config) {
    # Auch ohne Registry-Konfiguration ins Dateilog schreiben (Default-LogRoot),
    # damit der Fehler bei einem SYSTEM-Task ohne Konsole sichtbar bleibt.
    Initialize-VsLog -Component 'device-sync'
    Write-VsLog -Level ERROR -Message 'Registry-Konfiguration fehlt (HKLM:\SOFTWARE\VirtuSphere\MECM). install-VirtuSphere-MECM.ps1 ausfuehren - warte auf Konfiguration.'
    # Selbstheilung statt exit 1: die 3 Taskplaner-Neustarts waeren nach
    # wenigen Minuten aufgebraucht, danach bliebe der Task bis zum Reboot tot.
    while (-not $config) {
        Start-Sleep -Seconds 60
        $config = Get-VsConfig
    }
    Write-VsLog -Message 'Registry-Konfiguration gefunden - starte.'
}
Initialize-VsLog -Component 'device-sync' -LogRoot $config.LogRoot
# TLS 1.2 und, bei Scheme=https mit hinterlegtem Fingerabdruck, das Pinning. Muss
# in JEDEM Aufgabenprozess passieren: der Installer setzte es nur in seinem.
Initialize-VsTls -Config $config
Write-VsLog -Message '=== Device-Sync gestartet ==='

$membershipJournalPath = Get-VsMembershipJournalPath -Root $PSScriptRoot
try {
    # Held for the complete process lifetime. The Task Scheduler setting is a
    # convenience; this OS lock is the actual two-instance safety boundary.
    $membershipJournalLock = Enter-VsMembershipJournalInstance -Path $membershipJournalPath
    if (-not $membershipJournalLock.CanRead) { throw 'Membership-Journalsperre ist nicht verwendbar.' }
    [void](Read-VsMembershipJournal -Path $membershipJournalPath)
} catch {
    Write-VsLog -Level ERROR -Message ("Membership-Journal nicht sicher verwendbar; Device-Sync beendet ohne MECM-Mutation: {0}" -f $_.Exception.Message)
    throw
}

# Skript-Version fuer den Run-Report (script_version, <=32 Zeichen).
$SCRIPT_VERSION = 'device-sync/2.0'

$intervalSeconds = Resolve-VsInterval -Source 'device-sync' -Configured $config.DeviceSyncInterval
$siteCode = $null
$consecutiveErrors = 0
$loop = 0

# Legt eine Device Collection an (falls noetig) und verschiebt sie in den
# VirtuSphere-Ordner.
#
# Liefert Collection UND das Ergebnis des Verschiebens, weil der Fehlschlag den
# Aufrufer erreichen muss: er wurde vorher nur als WARN protokolliert, ohne
# Zaehler und ohne Ursache, also meldete der Lauf `ok`, waehrend die Collection
# im Wurzelordner liegen blieb. Ein Fehlerpfad ohne Zaehler erreicht den
# Run-Report nicht.
function New-VsDeviceCollection {
    param([string]$Name, [string]$FolderPath)
    $existing = Get-CMDeviceCollection -Name $Name -ErrorAction SilentlyContinue
    # Eine bestehende Collection wird bewusst nicht angefasst: wo sie liegt, hat
    # dann jemand entschieden.
    if ($existing) { return [pscustomobject]@{ Collection = $existing; FolderFailed = $false } }

    New-CMDeviceCollection -Name $Name -LimitingCollectionName 'All Systems' -Comment 'Autogeneriert by VirtuSphere' -ErrorAction Stop | Out-Null
    Start-Sleep -Seconds 3
    $created = Get-CMDeviceCollection -Name $Name -ErrorAction SilentlyContinue
    $folderFailed = $false
    if ($created -and $FolderPath) {
        try { $created | Move-CMObject -FolderPath $FolderPath -ErrorAction Stop | Out-Null } catch {
            Write-VsLog -Level WARN -Context $Name -Message ("Collection angelegt, aber Verschieben nach '{0}' fehlgeschlagen: {1}" -f $FolderPath, $_.Exception.Message)
            $folderFailed = $true
        }
    }
    return [pscustomobject]@{ Collection = $created; FolderFailed = $folderFailed }
}

# Decides what a stale-owned PLAN observation means after this run's actual
# adds. The plan deliberately keeps add(A) and stale_owned(A): one describes
# desired-versus-live, the other owned-versus-live. Only the apply boundary has
# the new exact CollectionID and knows whether the restoration really returned.
function Get-VsStaleOwnedDisposition {
    param(
        [Parameter(Mandatory)][string]$CollectionId,
        [Parameter(Mandatory)][string]$CollectionName,
        [array]$PlannedAdds = @(),
        [array]$AppliedAdds = @()
    )

    foreach ($applied in @($AppliedAdds)) {
        if ([string]::Equals([string]$applied.collection_id, $CollectionId, [StringComparison]::Ordinal)) {
            return 'restored_same_id'
        }
    }

    $isBeingRestored = $false
    foreach ($planned in @($PlannedAdds)) {
        if ([string]::Equals([string]$planned.name, $CollectionName, [StringComparison]::Ordinal)) {
            $isBeingRestored = $true
            break
        }
    }
    if (-not $isBeingRestored) { return 'withdraw' }

    foreach ($applied in @($AppliedAdds)) {
        if ([string]::Equals([string]$applied.collection_name, $CollectionName, [StringComparison]::Ordinal)) {
            return 'withdraw_replaced_id'
        }
    }
    return 'defer_until_restored'
}

while ($true) {
    $loop++
    $scanStart = Get-Date
    # Ein neuer Lauf beginnt: run_id minten, Start melden, dann die eine
    # Abschlussmeldung im finally garantieren (kein continue darf sie umgehen).
    $runId = New-VsRunId
    Send-VsRunReport -Config $config -Source 'device-sync' -RunEvent 'started' -RunId $runId -IntervalSeconds $intervalSeconds -ScriptVersion $SCRIPT_VERSION

    # Phase entscheidet die Fehlerkategorie: bricht der Geraetelisten-Abruf, ist
    # das Portal weg (portal_unreachable); bricht danach etwas, ist es MECM
    # (mecm_unavailable).
    $phase = 'portal'
    $outcome = 'ok'
    $category = $null
    $detail = $null
    $received = 0
    $imported = 0
    $itemFailures = 0
    $dataWarnings = 0
    $resourceUpdateFailures = 0
    $sleepSeconds = $intervalSeconds
    # Ursachen dieses Laufs mit VM- und Collection-Namen. Ohne sie zeigte die
    # Portalkarte "Datenwarnungen: 3" ohne eine einzige VM zu nennen.
    $causes = New-VsRunCauseList

    try {
        # --- VirtuSphere-Geraeteliste laden --------------------------------
        # Windows PowerShell 5.1 gibt ein leeres JSON-Array aus
        # Invoke-RestMethod in einem direkten @(...)-Ausdruck als EIN
        # verschachteltes Object[] weiter. Das sah danach wie ein Device ohne
        # Namen/Mission aus. Erst separat empfangen und ueber die Pipeline
        # enumerieren: [] -> 0, ein Objekt -> 1, mehrere Objekte -> n.
        $deviceResponse = Invoke-VsApi -Config $config -Path '/mecm-api.php?action=getDeviceList' -TimeoutSec 20
        $devices = @($deviceResponse | ForEach-Object { $_ })
        $received = $devices.Count
        $phase = 'mecm'

        if ($devices.Count -eq 0) {
            # Leerlauf-Abkuerzung: keine MECM-Vollabfragen ausloesen. Ein leerer
            # Scan ist ein gelungener Lauf (outcome bleibt ok).
        } else {
            if (-not $siteCode) {
                $siteCode = Initialize-VsCmSite -Config $config
                if (-not $siteCode) { throw 'MECM-Site nicht initialisierbar.' }
            }
            $osFolder = "{0}:\DeviceCollection\VirtuSphere_OS" -f $siteCode
            $missionFolder = "{0}:\DeviceCollection\VirtuSphere_Missions" -f $siteCode

            Write-VsLog -Message ("Scan #{0}: {1} Devices von VirtuSphere geladen." -f $loop, $devices.Count)

            # --- MECM-Daten EINMAL je Scan cachen ------------------------------
            #
            # -ErrorAction Stop auf allen drei Vollabfragen: ohne sie ist ein
            # Providerfehler nicht von "MECM ist leer" zu unterscheiden. Der Lauf
            # lief dann mit leeren Caches weiter und tat lauter falsche Dinge, die
            # alle nach Datenfehlern aussahen: jedes Device schien nicht in MECM
            # zu existieren (Re-Import), jede Zielcollection schien zu fehlen, und
            # keine Task-Sequence-Collection wurde angelegt. Der Wurf hier landet
            # im catch des Laufs und wird als mecm_unavailable gemeldet, was der
            # Wahrheit entspricht.
            # Drei Multimaps statt einer name-keyed Hashtabelle (Etappe 14D).
            # `$mecmDevices[$d.Name] = $d` loeste Duplikate still nach last-wins
            # auf; welcher von zwei gleichnamigen Datensaetzen gewann, entschied
            # die Reihenfolge der Providerantwort. Jetzt bleibt eine
            # Mehrdeutigkeit sichtbar und wird zum Fehler, nicht zur Heuristik.
            $mecmIndex = New-VsMecmDeviceIndex -Devices @(Get-CMDevice -Fast -ErrorAction Stop | Select-Object Name, MACAddress, ResourceID)
            $taskSequences = @(Get-CMTaskSequence -Fast -ErrorAction Stop | Select-Object -ExpandProperty Name)
            $collectionCache = [System.Collections.Generic.Dictionary[string,object]]::new([System.StringComparer]::Ordinal)
            $ambiguousCollectionNames = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::Ordinal)
            foreach ($c in @(Get-CMDeviceCollection -ErrorAction Stop | Select-Object Name, CollectionID)) {
                if (-not $c.Name) { continue }
                $collectionName = [string]$c.Name
                if ($collectionCache.ContainsKey($collectionName)) {
                    [void]$ambiguousCollectionNames.Add($collectionName)
                    continue
                }
                $collectionCache[$collectionName] = $c.CollectionID
            }
            $collectionsToUpdate = @{}

            # Ordner sicherstellen. Get-/New-CMFolder erwarten laut MS-Doku
            # RELATIVE Pfade ohne Site-Drive-Praefix (anders als Move-CMObject).
            foreach ($folderName in @('VirtuSphere_OS', 'VirtuSphere_Missions')) {
                $fp = "DeviceCollection\{0}" -f $folderName
                if (-not (Get-CMFolder -FolderPath $fp -ErrorAction SilentlyContinue)) {
                    try { New-CMFolder -Name $folderName -ParentFolderPath 'DeviceCollection' -ErrorAction Stop | Out-Null } catch {
                        Write-VsLog -Level WARN -Context $folderName -Message ("Collection-Ordner konnte nicht angelegt werden: {0}" -f $_.Exception.Message)
                        $dataWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'collection_folder_failed' -Target $folderName
                    }
                }
            }

            # --- Task-Sequence-Collections EINMAL je Scan ----------------------
            foreach ($tsName in $taskSequences) {
                if (-not $collectionCache.ContainsKey($tsName)) {
                    try {
                        $result = New-VsDeviceCollection -Name $tsName -FolderPath $osFolder
                        if ($result.Collection) { $collectionCache[$tsName] = $result.Collection.CollectionID }
                        if ($result.FolderFailed) {
                            $dataWarnings++
                            Add-VsRunCause -Causes $causes -Cause 'collection_folder_failed' -Collection $tsName
                        }
                    } catch {
                        Write-VsLog -Level WARN -Context $tsName -Message ("Task-Sequence-Collection konnte nicht angelegt werden: {0}" -f $_.Exception.Message)
                        $dataWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'collection_missing' -Collection $tsName
                    }
                }
            }

            foreach ($device in $devices) {
                # $deviceName ist der ESXi-/Portalname und dient AUSSCHLIESSLICH
                # der Protokollierung: er benennt die Zeile, die der Operator im
                # Portal sucht. Was MECM als Geraetenamen bekommt, ist seit
                # Etappe 14D $rolloutName, der eingefrorene Rollout-Snapshot aus
                # dem Wire-Feld vm_hostname. Die beiden nie vertauschen: der eine
                # identifiziert die VM in ESXi, der andere den Windowsrechner.
                $deviceName = [string]$device.vm_name
                $rolloutName = [string]$device.vm_hostname
                $rolloutRevision = $device.rollout_revision
                $boundResourceId = if ($device.mecm_id) { "$($device.mecm_id)" } else { '' }
                $previousResourceId = if ($device.previous_resource_id) { "$($device.previous_resource_id)" } else { '' }
                $deviceOS = [string]$device.vm_os
                $missionName = if ($device.mission) { [string]$device.mission.mission_name } else { '' }

                # DHCP-MAC ermitteln (PXE-NIC)
                $dhcpMacs = @($device.interfaces | Where-Object { $_.mode -eq 'DHCP' } | Select-Object -ExpandProperty mac)
                if ($dhcpMacs.Count -gt 1) {
                    Write-VsLog -Level WARN -Context $deviceName -Message ("Mehrere DHCP-Interfaces ({0}) - nutze die erste MAC. Falls PXE ueber eine andere NIC laeuft, Interfaces im Portal pruefen." -f $dhcpMacs.Count)
                }
                $deviceMac = if ($dhcpMacs.Count -gt 0) { ConvertTo-VsNormalizedMac $dhcpMacs[0] } else { $null }

                if ([string]::IsNullOrWhiteSpace($missionName)) {
                    Write-VsLog -Level WARN -Context $deviceName -Message ("Mission fehlt (Id {0}) - Device uebersprungen." -f $device.mission_id)
                    $dataWarnings++
                    Add-VsRunCause -Causes $causes -Cause 'mission_missing' -Target $deviceName
                    continue
                }
                if (-not $deviceMac) {
                    Write-VsLog -Level WARN -Context $deviceName -Message 'Keine MAC-Adresse - Device uebersprungen.'
                    $dataWarnings++
                    Add-VsRunCause -Causes $causes -Cause 'mac_missing' -Target $deviceName
                    continue
                }

                # Mission-Collection sicherstellen
                if (-not $collectionCache.ContainsKey($missionName)) {
                    try {
                        $result = New-VsDeviceCollection -Name $missionName -FolderPath $missionFolder
                        if ($result.Collection) { $collectionCache[$missionName] = $result.Collection.CollectionID }
                        if ($result.FolderFailed) {
                            $dataWarnings++
                            Add-VsRunCause -Causes $causes -Cause 'collection_folder_failed' -Target $deviceName -Collection $missionName
                        }
                    } catch {
                        Write-VsLog -Level WARN -Context $missionName -Message ("Mission-Collection fehlgeschlagen: {0}" -f $_.Exception.Message)
                        $dataWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'collection_missing' -Target $deviceName -Collection $missionName
                    }
                }

                # --- Identitaet aufloesen (Etappe 14D, ADR-0043) ---------------
                #
                # Eine reine Entscheidung ueber die drei Multimaps, damit sie
                # ohne MECM testbar bleibt. Sie beantwortet genau drei Dinge:
                # dieser Datensatz gehoert uns (use), es gibt noch keinen
                # (import), oder etwas stimmt nicht und die VM bleibt in der
                # Warteschlange (block, mit geschlossenem Code).
                $identity = Resolve-VsDeviceIdentity -Index $mecmIndex -RolloutHostname $rolloutName -Mac $deviceMac `
                    -BoundResourceId $boundResourceId -PreviousResourceId $previousResourceId
                if ($identity.Action -eq 'block') {
                    # Ein Identitaetsfehler tritt VOR jeder Mitgliedschafts-
                    # mutation ein: eine Collection-Zuweisung an den falschen
                    # ResourceID-Datensatz ist nicht zurueckzunehmen, ohne dass
                    # jemand weiss, dass sie passiert ist.
                    Write-VsLog -Level ERROR -Context $deviceName -Message ("Identitaet nicht aufloesbar ({0}): Rolloutname '{1}', MAC {2}, gebundene ResourceID '{3}', Vorgaenger '{4}' - Device bleibt in der Warteschlange." -f $identity.Cause, $rolloutName, $deviceMac, $boundResourceId, $previousResourceId)
                    $itemFailures++
                    Add-VsRunCause -Causes $causes -Cause $identity.Cause -Target $deviceName
                    continue
                }

                $resourceId = $identity.ResourceId
                if ($identity.Action -eq 'import') {
                    # Importiert wird der ROLLOUTNAME, nicht der ESXi-Name.
                    $importThrew = $false
                    try {
                        Import-CMComputerInformation -ComputerName $rolloutName -MacAddress $deviceMac -CollectionName 'All Systems' -ErrorAction Stop | Out-Null
                        Write-VsLog -Context $deviceName -Message ("Device als '{0}' importiert (MAC {1})." -f $rolloutName, $deviceMac)
                        $imported++
                        Start-Sleep -Seconds 2
                    } catch {
                        $importThrew = $true
                        # Kein Fehlertext-Parsing (Texte variieren je MECM-Version
                        # und -Sprache) und kein -MergeIfExist: nach dem Import
                        # wird Name UND MAC erneut EINDEUTIG gelesen, und das
                        # Ergebnis entscheidet. Ein Import-Race sieht danach
                        # genauso aus wie ein gelungener Import, was er auch ist.
                        Write-VsLog -Level DEBUG -Context $deviceName -Message ("Import meldete einen Fehler, Ergebnis wird nachgelesen: {0}" -f $_.Exception.Message)
                    }

                    # Nachlesen ueber dieselbe Entscheidung wie oben, nur gegen
                    # einen frischen, eng gefassten Index. Ein Name-only-Fallback
                    # waere hier genau der Fehlgriff, den der Tombstone verhindern
                    # soll: er wuerde ein fremdes Geraet gleichen Namens adoptieren.
                    Start-Sleep -Seconds 2
                    $freshIndex = New-VsMecmDeviceIndex -Devices @(Get-CMDevice -Name $rolloutName -Fast -ErrorAction SilentlyContinue | Select-Object Name, MACAddress, ResourceID)
                    $confirmed = Resolve-VsDeviceIdentity -Index $freshIndex -RolloutHostname $rolloutName -Mac $deviceMac
                    if ($confirmed.Action -ne 'use') {
                        if ($confirmed.Action -eq 'import' -and $importThrew) {
                            # Nichts da UND der Aufruf hat geworfen: das ist ein
                            # echter Importfehlschlag, kein Wartefall. Die beiden
                            # zu vermengen waere der teuerste Fehler von allen -
                            # ein dauerhaft gescheiterter Import haette sich als
                            # "kommt beim naechsten Scan" gemeldet, und die Ampel
                            # waere gelb statt rot geblieben, fuer immer.
                            Write-VsLog -Level ERROR -Context $deviceName -Message ("Import von '{0}' fehlgeschlagen und kein Datensatz vorhanden." -f $rolloutName)
                            $itemFailures++
                            Add-VsRunCause -Causes $causes -Cause 'device_import_failed' -Target $deviceName
                        } elseif ($confirmed.Action -eq 'import') {
                            # Der Aufruf lief durch, MECM hat den Datensatz nur
                            # noch nicht sichtbar gemacht. Kein Konflikt, ein
                            # spaeterer Scan.
                            Write-VsLog -Level WARN -Context $deviceName -Message ("Import von '{0}' ist noch nicht sichtbar - naechster Scan." -f $rolloutName)
                            $dataWarnings++
                            Add-VsRunCause -Causes $causes -Cause 'resource_id_pending' -Target $deviceName
                        } else {
                            Write-VsLog -Level ERROR -Context $deviceName -Message ("Import von '{0}' nicht eindeutig bestaetigt ({1}) - Device bleibt in der Warteschlange." -f $rolloutName, $confirmed.Cause)
                            $itemFailures++
                            Add-VsRunCause -Causes $causes -Cause $confirmed.Cause -Target $deviceName
                        }
                        continue
                    }
                    $resourceId = $confirmed.ResourceId
                }

                if (-not $resourceId) {
                    # Approve nur fuer ein frisch importiertes, noch nicht
                    # freigegebenes Geraet; adressiert ueber den Rolloutnamen.
                    try { Approve-CMDevice -Name $rolloutName -ErrorAction Stop; Start-Sleep -Seconds 5 } catch {
                        Write-VsLog -Level DEBUG -Context $deviceName -Message ("Auto-Approve nicht moeglich: {0}" -f $_.Exception.Message)
                    }
                    $approvedIndex = New-VsMecmDeviceIndex -Devices @(Get-CMDevice -Name $rolloutName -Fast -ErrorAction SilentlyContinue | Select-Object Name, MACAddress, ResourceID)
                    $approved = Resolve-VsDeviceIdentity -Index $approvedIndex -RolloutHostname $rolloutName -Mac $deviceMac
                    $resourceId = if ($approved.Action -eq 'use') { $approved.ResourceId } else { $null }
                }
                if (-not $resourceId) {
                    Write-VsLog -Level WARN -Context $deviceName -Message 'Noch keine ResourceID - naechster Scan.'
                    $dataWarnings++
                    Add-VsRunCause -Causes $causes -Cause 'resource_id_pending' -Target $deviceName
                    continue
                }

                # Replay is deliberately before a fresh plan. A confirmed
                # remote result may be reported again idempotently; an intent
                # without that confirmation is an ownership-uncertain crash
                # window and must never be reconstructed from current presence.
                $journalEntries = @(Get-VsMembershipJournalEntriesForVm -Path $membershipJournalPath -VmId ([int]$device.id))
                if ($journalEntries.Count -gt 0) {
                    $journalBlocked = $false
                    $journalReplayed = $false
                    $journalFailureCounted = $false
                    # Upgrade recovery for journals written before D-01: that
                    # sender could persist a confirmed add and a stale-owned
                    # removal for the same exact fenced CollectionID. Replaying
                    # them as separate requests would recreate SC-005 even when
                    # the receiver coalesces one batch. Only a current,
                    # confirmed add suppresses its matching non-remote stale
                    # observation, and both ACK records are removed in ONE
                    # journal replacement after the add report succeeds.
                    $replayAddsByCollectionId = [System.Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
                    $replaySuppressedRemovals = [System.Collections.Generic.Dictionary[string,object]]::new([StringComparer]::Ordinal)
                    foreach ($candidate in $journalEntries) {
                        if ([int]$candidate.rollout_revision -ne [int]$rolloutRevision -or [string]$candidate.resource_id -ne [string]$resourceId -or [string]$candidate.state -ne 'remote_confirmed') { continue }
                        $candidateId = [string]$candidate.collection_id
                        if ([string]$candidate.change -eq 'added') { [void]$replayAddsByCollectionId.Add($candidateId) }
                    }
                    foreach ($candidate in $journalEntries) {
                        if ([int]$candidate.rollout_revision -ne [int]$rolloutRevision -or [string]$candidate.resource_id -ne [string]$resourceId -or [string]$candidate.state -ne 'remote_confirmed' -or [string]$candidate.change -ne 'removed') { continue }
                        $candidateId = [string]$candidate.collection_id
                        if (-not $replayAddsByCollectionId.Contains($candidateId)) { continue }
                        if (-not $replaySuppressedRemovals.ContainsKey($candidateId)) { $replaySuppressedRemovals[$candidateId] = New-Object System.Collections.Generic.List[string] }
                        $replaySuppressedRemovals[$candidateId].Add([string]$candidate.operation_id)
                    }
                    foreach ($journalEntry in $journalEntries) {
                        if ([int]$journalEntry.rollout_revision -ne [int]$rolloutRevision -or [string]$journalEntry.resource_id -ne [string]$resourceId) {
                            Set-VsMembershipJournalState -Path $membershipJournalPath -OperationId $journalEntry.operation_id -State uncertain -Reason 'rollout_or_resource_changed'
                            $journalBlocked = $true
                            continue
                        }
                        if ([string]$journalEntry.state -ne 'remote_confirmed') {
                            $journalBlocked = $true
                            continue
                        }
                        if ([string]$journalEntry.change -eq 'removed' -and $replayAddsByCollectionId.Contains([string]$journalEntry.collection_id)) {
                            continue
                        }
                        if ([string]$journalEntry.change -eq 'removed') {
                            # A schema-1 orphan can be either a confirmed remote
                            # remove or the old sender's non-remote stale-owned
                            # observation after its paired add was already ACKed.
                            # Presence therefore cannot authorize a provenance
                            # delete. Only confirmed absence is safe to replay;
                            # present/unknown becomes durable manual evidence.
                            $removeReplayState = Get-VsDirectMembershipState -CollectionId ([string]$journalEntry.collection_id) -ResourceId ([string]$resourceId)
                            if ($removeReplayState.State -ne 'absent') {
                                $removeReplayReason = if ($removeReplayState.State -eq 'present') { 'replay_remove_still_present' } else { 'replay_remove_presence_unknown' }
                                Set-VsMembershipJournalState -Path $membershipJournalPath -OperationId $journalEntry.operation_id -State uncertain -Reason $removeReplayReason
                                $journalBlocked = $true
                                Write-VsLog -Level WARN -Context $deviceName -Message ("Entfernte Membership aus altem Journal ist nicht als abwesend bestaetigt ({0}); Provenienz bleibt zur manuellen Klaerung erhalten." -f $journalEntry.collection_id)
                                continue
                            }
                        }
                        try {
                            $replayBody = @{
                                deviceid = [int]$journalEntry.vm_id
                                rollout_revision = [int]$journalEntry.rollout_revision
                                memberships = @(@{
                                    collection_id = [string]$journalEntry.collection_id
                                    collection_name = [string]$journalEntry.collection_name
                                    type = [string]$journalEntry.type
                                    change = [string]$journalEntry.change
                                })
                            }
                            Invoke-VsApi -Config $config -Path '/mecm_updateid.php?action=reportMembership' -Method POST -Body $replayBody | Out-Null
                            $acknowledgedOperationIds = @([string]$journalEntry.operation_id)
                            if ([string]$journalEntry.change -eq 'added' -and $replaySuppressedRemovals.ContainsKey([string]$journalEntry.collection_id)) {
                                $acknowledgedOperationIds += @($replaySuppressedRemovals[[string]$journalEntry.collection_id])
                            }
                            Remove-VsMembershipJournalEntries -Path $membershipJournalPath -OperationIds $acknowledgedOperationIds
                            $journalReplayed = $true
                        } catch {
                            $statusCode = Get-VsErrorStatusCode -ErrorRecord $_
                            if ($statusCode -in @(404, 409)) {
                                Set-VsMembershipJournalState -Path $membershipJournalPath -OperationId $journalEntry.operation_id -State uncertain -Reason ('portal_http_' + $statusCode)
                            }
                            $journalBlocked = $true
                            $journalFailureCounted = $true
                            $itemFailures++
                            Add-VsRunCause -Causes $causes -Cause 'membership_operation_uncertain' -Target $deviceName
                            Write-VsLog -Level WARN -Context $deviceName -Message ("Membership-Journal konnte nicht quittiert werden; VM bleibt blockiert: {0}" -f (Get-VsErrorDetail -ErrorRecord $_))
                        }
                    }
                    if ($journalBlocked -or $journalReplayed) {
                        if (-not $journalFailureCounted) { $itemFailures++ }
                        $journalCause = if ($journalBlocked) { 'membership_operation_uncertain' } else { 'membership_report_pending' }
                        if (-not $journalFailureCounted) { Add-VsRunCause -Causes $causes -Cause $journalCause -Target $deviceName }
                        continue
                    }
                }

                # Reconciliation (ADR-0034): desired/owned/present -> Plan.
                # desired kommt aus dem Payload (OS, Pakete, Mission), owned aus
                # der mitgelieferten Provenienz (owned_collections), present aus
                # GEZIELTEN Proben: die desired-Namen ueber den Collection-Cache
                # und jede owned-ID. Fremde Regeln betreten den Plan nie und
                # bleiben dadurch unantastbar; ein Vollabzug aller
                # Mitgliedschaften waere teuer und wuerde nichts schuetzen.
                $desired = New-Object System.Collections.Generic.List[object]
                if ($deviceOS) { $desired.Add(@{ name = [string]$deviceOS; type = 'os' }) }
                foreach ($pkg in @($device.packages)) { if ($pkg.package_name) { $desired.Add(@{ name = [string]$pkg.package_name; type = 'package' }) } }
                $desired.Add(@{ name = [string]$missionName; type = 'mission' })

                if (-not (Test-VsDesiredMembershipTargets -Desired $desired)) {
                    Write-VsLog -Level ERROR -Context $deviceName -Message 'Gewuenschte Collectionnamen sind leer oder demselben exakten Namen sind widerspruechliche Typen zugeordnet; keine Mitgliedschaft geaendert.'
                    $itemFailures++
                    Add-VsRunCause -Causes $causes -Cause 'membership_identity_ambiguous' -Target $deviceName
                    continue
                }

                $ownedRules = @()
                $ownedRulesValid = $true
                foreach ($ownedRule in @($device.owned_collections)) {
                    $ownedId = [string]$ownedRule.collection_id
                    $ownedName = [string]$ownedRule.collection_name
                    $ownedType = [string]$ownedRule.collection_type
                    if ([string]::IsNullOrWhiteSpace($ownedId) -or [string]::IsNullOrWhiteSpace($ownedName) -or $ownedType -notin @('os', 'package', 'mission')) {
                        $ownedRulesValid = $false
                        break
                    }
                    $ownedRules += @{
                        collection_id   = $ownedId
                        collection_name = $ownedName
                        type            = $ownedType
                    }
                }
                if (-not $ownedRulesValid) {
                    Write-VsLog -Level ERROR -Context $deviceName -Message 'Portal-Provenienz ist unvollstaendig oder ungueltig; keine Mitgliedschaft wird geaendert und die VM bleibt in der Warteschlange.'
                    $itemFailures++
                    Add-VsRunCause -Causes $causes -Cause 'membership_provenance_invalid' -Target $deviceName
                    continue
                }

                # Zaehlt die Zuweisungen, die NICHT gesessen haben. Der Zaehler
                # entscheidet danach, ob die ResourceID gemeldet werden darf.
                $targetsSkipped = 0

                $present = @()
                $membershipReadUnknown = $false
                $membershipReadCause = 'membership_query_failed'
                foreach ($target in $desired) {
                    if ($ambiguousCollectionNames.Contains([string]$target.name)) {
                        Write-VsLog -Level ERROR -Context $deviceName -Message ("Collectionname '{0}' ist im MECM-Bestand mehrfach vorhanden; keine zufaellige ID wird verwendet." -f $target.name)
                        $membershipReadUnknown = $true
                        $membershipReadCause = 'membership_identity_ambiguous'
                        break
                    }
                    if (-not $collectionCache.ContainsKey($target.name)) {
                        Write-VsLog -Level WARN -Context $deviceName -Message ("Collection '{0}' existiert nicht - uebersprungen." -f $target.name)
                        $dataWarnings++
                        Add-VsRunCause -Causes $causes -Cause 'collection_missing' -Target $deviceName -Collection $target.name
                        $targetsSkipped++
                        continue
                    }
                    $collectionId = [string]$collectionCache[$target.name]
                    $membership = Get-VsDirectMembershipState -CollectionId $collectionId -ResourceId $resourceId
                    if ($membership.State -eq 'unknown') {
                        $membershipReadUnknown = $true
                        Write-VsLog -Level ERROR -Context $deviceName -Message ("Mitgliedschaft in Collection '{0}' ist wegen Providerfehler unbekannt; keine Aenderung ausgefuehrt." -f $target.name)
                        break
                    }
                    if ($membership.State -eq 'present') { $present += @{ collection_id = $collectionId; collection_name = [string]$target.name } }
                }
                if (-not $membershipReadUnknown) { foreach ($ownedRule in $ownedRules) {
                    if (@($present | Where-Object { $_.collection_id -eq $ownedRule.collection_id }).Count -gt 0) { continue }
                    $membership = Get-VsDirectMembershipState -CollectionId $ownedRule.collection_id -ResourceId $resourceId
                    if ($membership.State -eq 'unknown') {
                        $membershipReadUnknown = $true
                        Write-VsLog -Level ERROR -Context $deviceName -Message ("Eigene Mitgliedschaft in Collection '{0}' ist wegen Providerfehler unbekannt; keine Aenderung und kein Provenienzrueckzug ausgefuehrt." -f $ownedRule.collection_name)
                        break
                    }
                    if ($membership.State -eq 'present') { $present += @{ collection_id = [string]$ownedRule.collection_id; collection_name = [string]$ownedRule.collection_name } }
                } }
                if ($membershipReadUnknown) {
                    $itemFailures++
                    Add-VsRunCause -Causes $causes -Cause $membershipReadCause -Target $deviceName
                    continue
                }

                $plan = Get-VsMembershipPlan -Desired $desired -Owned $ownedRules -Present $present
                # Validate the complete known operation shape before the first
                # remote membership write. The PHP endpoint stays strict; a
                # malformed removal must not discard otherwise valid additions
                # only after MECM was already changed.
                if (-not (Test-VsMembershipPlanOperations -Plan $plan)) {
                    Write-VsLog -Level ERROR -Context $deviceName -Message 'Mitgliedschaftsplan enthaelt keine vollstaendig meldbare ID-/Name-/Typ-Provenienz; keine Remote-Aenderung ausgefuehrt.'
                    $itemFailures++
                    Add-VsRunCause -Causes $causes -Cause 'membership_provenance_invalid' -Target $deviceName
                    continue
                }
                $membershipReport = New-Object System.Collections.Generic.List[object]
                $appliedMembershipAdds = New-Object System.Collections.Generic.List[object]

                # Nenner der Unvollstaendigkeits-Meldung weiter unten, und der
                # heisst hier mit Absicht, was er ist: ALLE Mitgliedschafts-
                # operationen, die dieser Lauf fuer diese VM vorhat.
                # $desired.Count allein waere falsch, denn $targetsSkipped zaehlt
                # auch fehlgeschlagene ENTFERNUNGEN, und die stehen nicht in
                # $desired: bei einer VM, deren Mission gewechselt hat, meldete
                # die Zeile sonst "4 von 3 Zuweisungen unvollstaendig".
                $targetsPlanned = $desired.Count + @($plan.remove).Count

                foreach ($target in @($plan.add)) {
                    # Ein desired-Ziel ohne Collection wurde oben schon als
                    # collection_missing gezaehlt; der Plan kennt es trotzdem
                    # als add, weil es nicht present ist.
                    if (-not $collectionCache.ContainsKey($target.name)) { continue }
                    $collectionId = [string]$collectionCache[$target.name]
                    $journalRevision = if ([int]$rolloutRevision -gt 0) { [int]$rolloutRevision } else { 1 }
                    $journalEntry = New-VsMembershipJournalEntry -VmId ([int]$device.id) -RolloutRevision $journalRevision -ResourceId ([string]$resourceId) -CollectionId $collectionId -CollectionName ([string]$target.name) -Type ([string]$target.type) -Change added
                    try {
                        Set-VsMembershipJournalEntry -Path $membershipJournalPath -Entry $journalEntry
                        Add-CMDeviceCollectionDirectMembershipRule -CollectionId $collectionId -ResourceId $resourceId -ErrorAction Stop | Out-Null
                        Set-VsMembershipJournalState -Path $membershipJournalPath -OperationId $journalEntry.operation_id -State remote_confirmed
                        $collectionsToUpdate[$target.name] = $true
                        $appliedMembershipAdds.Add(@{ collection_id = $collectionId; collection_name = [string]$target.name })
                        $membershipReport.Add(@{ operation_id = $journalEntry.operation_id; collection_id = $collectionId; collection_name = [string]$target.name; type = [string]$target.type; change = 'added' })
                    } catch {
                        try { Set-VsMembershipJournalState -Path $membershipJournalPath -OperationId $journalEntry.operation_id -State uncertain -Reason 'remote_add_not_confirmed' } catch {
                            $itemFailures++
                            Add-VsRunCause -Causes $causes -Cause 'membership_operation_uncertain' -Target $deviceName
                            Write-VsLog -Level ERROR -Context $deviceName -Message ("Membership-Journal konnte nach unklarem Add nicht aktualisiert werden: {0}" -f $_.Exception.Message)
                        }
                        Write-VsLog -Level ERROR -Context $deviceName -Message ("Zuweisung zu '{0}' fehlgeschlagen: {1}" -f $target.name, $_.Exception.Message)
                        $itemFailures++
                        Add-VsRunCause -Causes $causes -Cause 'collection_assign_failed' -Target $deviceName -Collection $target.name
                        $targetsSkipped++
                    }
                }

                # Entfernen NUR aus $plan.remove: owned UND present UND nicht
                # mehr desired (Entscheidung 2). Ein Fehlschlag laesst das
                # Device in der Warteschlange, der naechste Lauf konvergiert.
                foreach ($rule in @($plan.remove)) {
                    $journalRevision = if ([int]$rolloutRevision -gt 0) { [int]$rolloutRevision } else { 1 }
                    $journalEntry = New-VsMembershipJournalEntry -VmId ([int]$device.id) -RolloutRevision $journalRevision -ResourceId ([string]$resourceId) -CollectionId ([string]$rule.collection_id) -CollectionName ([string]$rule.collection_name) -Type ([string]$rule.type) -Change removed
                    try {
                        Set-VsMembershipJournalEntry -Path $membershipJournalPath -Entry $journalEntry
                        Remove-CMDeviceCollectionDirectMembershipRule -CollectionId $rule.collection_id -ResourceId $resourceId -Force -ErrorAction Stop
                        Set-VsMembershipJournalState -Path $membershipJournalPath -OperationId $journalEntry.operation_id -State remote_confirmed
                        $collectionsToUpdate[$rule.collection_name] = $true
                        $membershipReport.Add(@{ operation_id = $journalEntry.operation_id; collection_id = [string]$rule.collection_id; collection_name = [string]$rule.collection_name; type = [string]$rule.type; change = 'removed' })
                        Write-VsLog -Context $deviceName -Message ("Eigene, nicht mehr zugewiesene Regel entfernt: '{0}' ({1})." -f $rule.collection_name, $rule.collection_id)
                    } catch {
                        try { Set-VsMembershipJournalState -Path $membershipJournalPath -OperationId $journalEntry.operation_id -State uncertain -Reason 'remote_remove_not_confirmed' } catch {
                            $itemFailures++
                            Add-VsRunCause -Causes $causes -Cause 'membership_operation_uncertain' -Target $deviceName
                            Write-VsLog -Level ERROR -Context $deviceName -Message ("Membership-Journal konnte nach unklarem Remove nicht aktualisiert werden: {0}" -f $_.Exception.Message)
                        }
                        Write-VsLog -Level ERROR -Context $deviceName -Message ("Entfernen der eigenen Regel '{0}' fehlgeschlagen: {1}" -f $rule.collection_name, $_.Exception.Message)
                        $itemFailures++
                        Add-VsRunCause -Causes $causes -Cause 'collection_remove_failed' -Target $deviceName -Collection $rule.collection_name
                        $targetsSkipped++
                    }
                }

                # Verfallene Provenienz fuer ein nicht mehr gewuenschtes Ziel
                # wird zurueckgezogen. Ist dasselbe Ziel weiterhin gewuenscht,
                # gilt D-01: der aktuelle Lauf stellt es wieder her. Bei exakt
                # derselben CollectionID bleibt die vorhandene Ownershipzeile
                # erhalten; bei einer ersetzten ID wird nur die alte entfernt.
                # Ein nicht bestaetigtes Add darf die alte Ownership nicht
                # loeschen, denn der naechste Lauf muss den Wunsch weiter sicher
                # rekonstruieren koennen.
                foreach ($rule in @($plan.stale_owned)) {
                    # Windows PowerShell 5.1 wirft beim direkten Binden einer
                    # generischen List[object] durch @() eine ArgumentException.
                    # Die Apply-Grenze braucht ein echtes Object-Array.
                    $staleDisposition = Get-VsStaleOwnedDisposition -CollectionId ([string]$rule.collection_id) -CollectionName ([string]$rule.collection_name) -PlannedAdds @($plan.add) -AppliedAdds ($appliedMembershipAdds.ToArray())
                    if ($staleDisposition -eq 'restored_same_id') {
                        Write-VsLog -Context $deviceName -Message ("Eigene Regel '{0}' ({1}) wurde unter derselben CollectionID wiederhergestellt; Provenienz bleibt erhalten." -f $rule.collection_name, $rule.collection_id)
                        continue
                    }
                    if ($staleDisposition -eq 'defer_until_restored') {
                        Write-VsLog -Level WARN -Context $deviceName -Message ("Eigene Regel '{0}' ist weiterhin gewuenscht, wurde aber nicht bestaetigt wiederhergestellt; Provenienz bleibt bis zum sicheren Folgelauf erhalten." -f $rule.collection_name)
                        continue
                    }
                    $journalRevision = if ([int]$rolloutRevision -gt 0) { [int]$rolloutRevision } else { 1 }
                    $journalEntry = New-VsMembershipJournalEntry -VmId ([int]$device.id) -RolloutRevision $journalRevision -ResourceId ([string]$resourceId) -CollectionId ([string]$rule.collection_id) -CollectionName ([string]$rule.collection_name) -Type ([string]$rule.type) -Change removed -State remote_confirmed
                    Set-VsMembershipJournalEntry -Path $membershipJournalPath -Entry $journalEntry
                    $membershipReport.Add(@{ operation_id = $journalEntry.operation_id; collection_id = [string]$rule.collection_id; collection_name = [string]$rule.collection_name; type = [string]$rule.type; change = 'removed' })
                    Write-VsLog -Level WARN -Context $deviceName -Message ("Eigene Regel '{0}' wurde in MECM entfernt oder durch eine neue CollectionID ersetzt - alte Provenienz {1} wird zurueckgezogen." -f $rule.collection_name, $rule.collection_id)
                }

                # Angewandte Aenderungen zurueckmelden, BEVOR die ResourceID das
                # Device aus der Warteschlange nimmt.
                #
                # Ein Fehlschlag haelt die VM in der Warteschlange, genau wie
                # eine unvollstaendige Zuweisung zwei Bloecke tiefer. Es gibt
                # keinen "naechsten Lauf", auf den sich die Provenienz vertagen
                # liesse: updateDevice setzt die VM auf `registered`, und
                # getDeviceList liefert sie danach nicht mehr aus. Ohne den
                # Eigentumsnachweis darf das Portal die eigenen Mitgliedschaften
                # nie wieder entfernen (ADR-0034 verlangt owned UND present UND
                # nicht mehr desired), also behaelt die VM nach einem Missions-
                # oder Paketwechsel dauerhaft das alte Image und die alten
                # Pakete, waehrend in MECM alles korrekt aussieht.
                #
                # Der Count-Guard bleibt: ein Lauf, der nichts geaendert hat,
                # betritt den Block nicht und wird nicht aufgehalten. Beide
                # Aufrufe sind idempotent, der Retry kostet nur einen Durchlauf,
                # und eine haengende VM blockiert keine andere (continue, nicht
                # break).
                if ($membershipReport.Count -gt 0) {
                    try {
                        # Die Rolloutrevision faehrt mit (Etappe 14D): eine alte
                        # Scaniteration darf nach einem Reset weder Provenienz
                        # noch ResourceID des NEUEN Rollouts schreiben. Fehlt sie
                        # (Portal vor dem Cutover), laesst der Server sie nur fuer
                        # Revision 1 ohne Tombstone durch.
                        $membershipBody = @{
                            deviceid    = $device.id
                            memberships = @($membershipReport | Select-Object collection_id, collection_name, type, change)
                        }
                        if ($rolloutRevision) { $membershipBody['rollout_revision'] = [int]$rolloutRevision }
                        Invoke-VsApi -Config $config -Path '/mecm_updateid.php?action=reportMembership' -Method POST -Body $membershipBody | Out-Null
                        Remove-VsMembershipJournalEntries -Path $membershipJournalPath -OperationIds @($membershipReport | ForEach-Object { [string]$_.operation_id })
                    } catch {
                        # 409 heisst: dieser Scan arbeitet mit einem veralteten
                        # Rollout. Kein Transportfehler, keine Handarbeit - der
                        # naechste Scan liest die neue Revision und laeuft durch.
                        # Eigener Code, weil "Meldung fehlgeschlagen" den Operator
                        # sonst einen Netzwerkfehler suchen laesst, den es nicht
                        # gibt.
                        $isStale = ($_.Exception.Response -and [int]$_.Exception.Response.StatusCode -eq 409)
                        $cause = if ($isStale) { 'stale_rollout_revision' } else { 'membership_report_failed' }
                        Write-VsLog -Level WARN -Context $deviceName -Message ("Provenienz-Meldung nicht uebernommen ({0}): {1} - ResourceID wird NICHT gemeldet, Device bleibt in der Warteschlange." -f $cause, (Get-VsErrorDetail -ErrorRecord $_))
                        $itemFailures++
                        Add-VsRunCause -Causes $causes -Cause $cause -Target $deviceName
                        continue
                    }
                }

                # ResourceID NUR melden, wenn jede Zuweisung gesessen hat.
                #
                # mecm_updateid.php ist der einzige Weg aus der Warteschlange: es
                # setzt die VM auf `registered`, und getDeviceList liefert sie
                # danach nicht mehr. Die Meldung lief bisher unbedingt, also fiel
                # eine VM dauerhaft aus der Warteschlange, obwohl ihre OS-, Paket-
                # oder Mission-Collection fehlte. Auf dem Client sah das aus wie
                # ein PXE-Boot ohne Task Sequence oder eine Installation ohne
                # Pakete, und im Portal wie eine fertig registrierte VM. Kein
                # Wire-Change: derselbe Aufruf, nur nicht mehr fuer ein Device,
                # dessen Zuweisung unvollstaendig ist. Es bleibt in getDeviceList
                # und der naechste Scan versucht es erneut.
                if ($targetsSkipped -gt 0) {
                    Write-VsLog -Level ERROR -Context $deviceName -Message ("{0} von {1} Mitgliedschaftsoperationen unvollstaendig - ResourceID wird NICHT gemeldet, Device bleibt in der Warteschlange." -f $targetsSkipped, $targetsPlanned)
                    $itemFailures++
                    continue
                }

                try {
                    # deviceName bleibt als Wire-Feld unveraendert der ESXi-Name:
                    # das Portal identifiziert die Zeile ueber deviceid, und das
                    # Feld hier zu drehen waere ein Wire-Change ohne Nutzen.
                    $updateBody = @{
                        deviceName       = $deviceName
                        deviceResourceID = "$resourceId"
                        deviceid         = $device.id
                    }
                    if ($rolloutRevision) { $updateBody['rollout_revision'] = [int]$rolloutRevision }
                    Invoke-VsApi -Config $config -Path '/mecm_updateid.php?action=updateDevice' -Method POST -Body $updateBody | Out-Null
                } catch {
                    $isStale = ($_.Exception.Response -and [int]$_.Exception.Response.StatusCode -eq 409)
                    $cause = if ($isStale) { 'stale_rollout_revision' } else { 'resource_update_failed' }
                    Write-VsLog -Level WARN -Context $deviceName -Message ("ResourceID-Update nicht uebernommen ({0}): {1}" -f $cause, (Get-VsErrorDetail -ErrorRecord $_))
                    $resourceUpdateFailures++
                    Add-VsRunCause -Causes $causes -Cause $cause -Target $deviceName
                }
            }

            # Collection-Updates gesammelt anstossen (einmal je geaenderter Collection)
            foreach ($name in $collectionsToUpdate.Keys) {
                try { Invoke-CMCollectionUpdate -Name $name -ErrorAction Stop | Out-Null } catch {
                    Write-VsLog -Level WARN -Context $name -Message ("Collection-Update nicht angestossen (Mitgliedschaft greift erst beim naechsten MECM-Zyklus): {0}" -f $_.Exception.Message)
                    $dataWarnings++
                    Add-VsRunCause -Causes $causes -Cause 'collection_update_failed' -Collection $name
                }
            }

            if ($imported -gt 0 -or $itemFailures -gt 0) {
                Write-VsLog -Message ("Scan #{0} fertig: {1} importiert, {2} Fehler, {3:N1}s." -f $loop, $imported, $itemFailures, ((Get-Date) - $scanStart).TotalSeconds)
            }
        }

        # Teil-Fehler eines sonst gelungenen Scans -> warning/partial_failure.
        # Das Detail nennt dabei VM und Collection: eine Zahl ohne Ursache ist
        # das, was die Karte vorher zeigte, und der naechste saubere Lauf
        # ueberschreibt sie.
        if ($itemFailures -gt 0 -or $dataWarnings -gt 0 -or $resourceUpdateFailures -gt 0) {
            $outcome = 'warning'
            $category = 'partial_failure'
            $detail = Format-VsRunDetail -Causes $causes
        }
        # Reset only after the complete portal + MECM section returned. A
        # successful API read must not hide a repeatedly broken SMS Provider.
        # Per-item data warnings still prove the provider itself answered.
        $consecutiveErrors = 0
    } catch {
        $consecutiveErrors++
        $detail = Get-VsErrorDetail -ErrorRecord $_
        # Get-VsErrorDetail statt .Exception.Message: bei einem API-Fehler steht der
        # Grund in der JSON-Envelope der WebApp, die Statuszeile sagt nur "400".
        Write-VsLog -Level ERROR -Message ("Scan-Fehler (Versuch {0}): {1}" -f $consecutiveErrors, $detail)
        $outcome = 'fail'
        $category = if ($phase -eq 'portal') { 'portal_unreachable' } else { 'mecm_unavailable' }
        if ($consecutiveErrors -ge 3) {
            $siteCode = $null
            $sleepSeconds = 60
        } else {
            $sleepSeconds = 30
        }
    } finally {
        # Genau EINE Abschlussmeldung pro Iteration, auch bei continue/throw.
        $durationMs = Get-VsRunDurationMilliseconds -StartedAt $scanStart
        $summary = @{
            received                 = $received
            imported                 = $imported
            item_failures            = $itemFailures
            data_warnings            = $dataWarnings
            resource_update_failures = $resourceUpdateFailures
        }
        Send-VsRunReport -Config $config -Source 'device-sync' -RunEvent 'completed' -RunId $runId `
            -IntervalSeconds $intervalSeconds -Outcome $outcome -ErrorCategory $category `
            -DurationMs $durationMs -Detail $detail -Summary $summary -ScriptVersion $SCRIPT_VERSION
    }

    if ($loop % 100 -eq 0) { [System.GC]::Collect() }
    Start-Sleep -Seconds $sleepSeconds
}
