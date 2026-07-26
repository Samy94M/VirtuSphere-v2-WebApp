#Requires -Version 5.1
# ============================================================================
# mecm_Packages-TaskSeq-sync.ps1 - meldet den Paket-/Task-Sequence-Katalog
# an die VirtuSphere WebAPI (mecm_packages.php).
# ----------------------------------------------------------------------------
# Quelle: Device Collections im Ordner "VirtuSphere_Applications" (Pakete)
# plus alle Task Sequences (Betriebssysteme). Laeuft als geplante Aufgabe
# "VirtuSphere MECM Packages Sync" auf dem MECM-Server.
#
# Haertung gegenueber der Vorversion:
#  - durchgaengiges try/catch mit Backoff (frueher beendete der erste Fehler
#    das Skript bis zum Reboot)
#  - Sende-Guard: liefert WMI den Applications-Ordner nicht, wird NICHT
#    gesendet (frueher leerte ein leerer Payload den Katalog serverseitig)
#  - Change-Detection: gesendet wird nur bei geaendertem Katalog-Hash,
#    zusaetzlich ein erzwungener Voll-Sync pro Stunde
#  - Heartbeat je Durchlauf (Statusseite im Portal)
# ============================================================================

. "$PSScriptRoot\VirtuSphere-Common.ps1"

$config = Get-VsConfig
if (-not $config) {
    # Auch ohne Registry-Konfiguration ins Dateilog schreiben (Default-LogRoot),
    # damit der Fehler bei einem SYSTEM-Task ohne Konsole sichtbar bleibt.
    Initialize-VsLog -Component 'packages-sync'
    Write-VsLog -Level ERROR -Message 'Registry-Konfiguration fehlt (HKLM:\SOFTWARE\VirtuSphere\MECM). install-VirtuSphere-MECM.ps1 ausfuehren - warte auf Konfiguration.'
    # Selbstheilung statt exit 1: die 3 Taskplaner-Neustarts waeren nach
    # wenigen Minuten aufgebraucht, danach bliebe der Task bis zum Reboot tot.
    while (-not $config) {
        Start-Sleep -Seconds 60
        $config = Get-VsConfig
    }
    Write-VsLog -Message 'Registry-Konfiguration gefunden - starte.'
}
Initialize-VsLog -Component 'packages-sync' -LogRoot $config.LogRoot
Write-VsLog -Message '=== Packages-Sync gestartet ==='

# Skript-Version fuer den Run-Report (script_version, <=32 Zeichen).
$SCRIPT_VERSION = 'packages-sync/2.0'

$folderName = $script:VsApplicationsFolderName   # SSoT in VirtuSphere-Common.ps1
$intervalSeconds = Resolve-VsInterval -Source 'packages-sync' -Configured $config.PackagesSyncInterval
$forceSyncEverySeconds = 3600

$siteCode = $null
$lastPayloadHash = ''
$lastFullSync = [datetime]::MinValue
$consecutiveErrors = 0

while ($true) {
    # Neuer Lauf: run_id minten, Start melden, Abschluss im finally garantieren.
    $runId = New-VsRunId
    $cycleStart = Get-Date
    Send-VsRunReport -Config $config -Source 'packages-sync' -RunEvent 'started' -RunId $runId -IntervalSeconds $intervalSeconds -ScriptVersion $SCRIPT_VERSION

    $outcome = 'ok'
    $category = $null
    $detail = $null
    $packagesCount = 0
    $tsCount = 0
    $sent = 0
    $unchanged = 0
    $sleepSeconds = $intervalSeconds

    try {
        if (-not $siteCode) {
            $siteCode = Initialize-VsCmSite -Config $config
            if (-not $siteCode) { throw 'MECM-Site nicht initialisierbar.' }
            Write-VsLog -Message ("Site-Drive {0} aktiv" -f $siteCode)
        }

        # --- Katalog einsammeln -------------------------------------------
        $folder = Get-CMFolder -Name $folderName -ErrorAction SilentlyContinue | Where-Object { $_.ObjectType -eq 5000 } | Select-Object -First 1
        if (-not $folder) {
            # Sende-Guard: ohne Ordner-Ergebnis niemals senden - ein leerer
            # Paket-Payload wuerde serverseitig den Katalog zurueckziehen.
            Write-VsLog -Level WARN -Message ("Ordner '{0}' nicht gefunden - Sync uebersprungen (Sende-Guard)." -f $folderName)
            $outcome = 'warning'
            $category = 'source_missing'
        } else {
            $collections = Get-CimInstance -Namespace ("ROOT\SMS\Site_{0}" -f $siteCode) -Query (
                "SELECT Name FROM SMS_Collection WHERE CollectionID IN (SELECT InstanceKey FROM SMS_ObjectContainerItem WHERE ObjectType='5000' AND ContainerNodeID='{0}') AND CollectionType='2'" -f $folder.ContainerNodeID
            ) -ErrorAction Stop

            $taskSequences = Get-CMTaskSequence -Fast -ErrorAction Stop | Select-Object -ExpandProperty Name

            $payload = New-Object System.Collections.Generic.List[object]
            foreach ($collection in @($collections)) {
                if (-not [string]::IsNullOrWhiteSpace($collection.Name)) {
                    $payload.Add(@{ type = 'Package'; name = [string]$collection.Name })
                    $packagesCount++
                }
            }
            foreach ($tsName in @($taskSequences)) {
                if (-not [string]::IsNullOrWhiteSpace($tsName)) {
                    $payload.Add(@{ type = 'TaskSequence'; name = [string]$tsName })
                    $tsCount++
                }
            }

            if ($payload.Count -eq 0) {
                Write-VsLog -Level WARN -Message 'Katalog leer (WMI-Aussetzer?) - Sync uebersprungen (Sende-Guard).'
                $outcome = 'warning'
                $category = 'source_missing'
            } else {
                # --- Change-Detection ----------------------------------------------
                $json = ($payload | Sort-Object { $_.type }, { $_.name } | ConvertTo-Json -Depth 4)
                $sha = [System.Security.Cryptography.SHA256]::Create()
                $hash = [BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($json))) -replace '-', ''
                $forceDue = ((Get-Date) - $lastFullSync).TotalSeconds -ge $forceSyncEverySeconds

                if ($hash -eq $lastPayloadHash -and -not $forceDue) {
                    Write-Host ("Katalog unveraendert ({0} Eintraege) - kein Sync noetig." -f $payload.Count) -ForegroundColor DarkGray
                    $unchanged = 1
                } else {
                    # --- Senden ---------------------------------------------------------
                    try {
                        $response = Invoke-VsApi -Config $config -Path '/mecm_packages.php' -Method POST -Body $payload
                        $lastPayloadHash = $hash
                        $lastFullSync = Get-Date
                        $sent = 1
                        Write-VsLog -Message ("Katalog gesendet: {0} Pakete, {1} Task Sequences." -f $response.packages, $response.task_sequences)
                    } catch {
                        if ((Get-VsErrorStatusCode -ErrorRecord $_) -eq 409) {
                            # Schwellwert-Bremse der WebApp: zu viele Eintraege wuerden
                            # zurueckgezogen. Laut und ohne Hash-Update, damit der
                            # naechste Durchlauf erneut versucht. Der Grund steht in der
                            # JSON-Envelope der WebApp, nicht in der Statuszeile.
                            $detail = Get-VsErrorDetail -ErrorRecord $_
                            Write-VsLog -Level WARN -Message ('WebApp hat den Sync abgelehnt (409, Schutzschwelle) - Katalogquelle pruefen (richtiger Ordner? WMI ok?). {0}' -f $detail)
                            $outcome = 'warning'
                            $category = 'catalog_conflict'
                        } else {
                            throw
                        }
                    }
                }
            }
        }

        # Bis hierher ohne throw: der Backoff-Zaehler ist zurueckgesetzt.
        $consecutiveErrors = 0
    } catch {
        $consecutiveErrors++
        $detail = Get-VsErrorDetail -ErrorRecord $_
        Write-VsLog -Level ERROR -Message ("Sync-Fehler (Versuch {0}): {1}" -f $consecutiveErrors, $detail)
        # SMS-Provider/WMI-Abfrage, Init oder ein unerwarteter Sendefehler.
        $outcome = 'fail'
        $category = 'mecm_unavailable'
        if ($consecutiveErrors -ge 3) {
            $siteCode = $null   # naechster Durchlauf initialisiert MECM neu (Site-Drive-Recovery)
            $sleepSeconds = 60
        } else {
            $sleepSeconds = 30
        }
    } finally {
        # Genau EINE Abschlussmeldung pro Iteration.
        $durationMs = [int]((Get-Date) - $cycleStart).TotalMilliseconds
        $summary = @{
            packages       = $packagesCount
            task_sequences = $tsCount
            sent           = $sent
            unchanged      = $unchanged
        }
        Send-VsRunReport -Config $config -Source 'packages-sync' -RunEvent 'completed' -RunId $runId `
            -IntervalSeconds $intervalSeconds -Outcome $outcome -ErrorCategory $category `
            -DurationMs $durationMs -Detail $detail -Summary $summary -ScriptVersion $SCRIPT_VERSION
    }

    Start-Sleep -Seconds $sleepSeconds
}
