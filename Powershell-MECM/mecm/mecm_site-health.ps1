#Requires -Version 5.1
# ============================================================================
# mecm_site-health.ps1 - meldet den Gesundheitszustand der MECM-Site an die
# VirtuSphere WebAPI (mecm_report.php?action=reportRun, source mecm-site-health).
# Laeuft als geplante Aufgabe "VirtuSphere MECM Site Health" auf dem MECM-Server.
#
# Anders als die drei Sync-Tasks meldet dieser Reporter NUR das Ergebnis
# (event=completed, nie started): eine Site-Health-Abfrage hat keinen relevanten
# Startzeitpunkt, nur einen Zustand. Der Zustand ist immer eine der vier Ampeln
# ok/warning/fail/unknown; ein Providerfehler ist grau (unknown), nie
# "MECM kritisch" (das waere fail).
#
# Haertung/Design (wie die Sync-Skripte):
#  - Konfiguration aus Registry (keine harten IPs/Site-Codes/Provider)
#  - Selbstheilung statt exit 1, falls die Registry (noch) fehlt
#  - fire-and-forget: eine nicht zugestellte Meldung stoppt die Schleife nie
#  - Reports kopieren NIE die volle MECM-Statusmeldung, nur Site-Code, Provider,
#    numerischen Rohstatus und den abgeleiteten Zustand
#  - provider_unreachable wird erst nach ZWEI aufeinanderfolgenden Fehlzyklen
#    gemeldet, damit ein MECM-Neustart nicht sofort einen Fehler schreibt
# ============================================================================

. "$PSScriptRoot\VirtuSphere-Common.ps1"

$config = Get-VsConfig
if (-not $config) {
    # Auch ohne Registry-Konfiguration ins Dateilog schreiben (Default-LogRoot),
    # damit der Fehler bei einem SYSTEM-Task ohne Konsole sichtbar bleibt.
    Initialize-VsLog -Component 'site-health'
    Write-VsLog -Level ERROR -Message 'Registry-Konfiguration fehlt (HKLM:\SOFTWARE\VirtuSphere\MECM). install-VirtuSphere-MECM.ps1 ausfuehren - warte auf Konfiguration.'
    # Selbstheilung statt exit 1: die 3 Taskplaner-Neustarts waeren nach
    # wenigen Minuten aufgebraucht, danach bliebe der Task bis zum Reboot tot.
    while (-not $config) {
        Start-Sleep -Seconds 60
        $config = Get-VsConfig
    }
    Write-VsLog -Message 'Registry-Konfiguration gefunden - starte.'
}
Initialize-VsLog -Component 'site-health' -LogRoot $config.LogRoot
Write-VsLog -Message '=== Site-Health gestartet ==='

# Skript-Version fuer den Run-Report (script_version, <=32 Zeichen).
$SCRIPT_VERSION = 'site-health/1.0'

# Intervall aus der Registry (SiteHealthIntervalSeconds), Default 300s; die
# Spanne und das Protokollieren einer Korrektur liegen in Resolve-VsInterval.
$intervalSeconds = Resolve-VsInterval -Source 'mecm-site-health' -Configured $config.SiteHealthInterval

# provider_unreachable erst nach zwei Fehlzyklen: der Zaehler ueberlebt die
# Iteration.
$consecutiveProviderFailures = 0

while ($true) {
    # Neuer Lauf: run_id minten, Abschluss (nur completed) im finally garantieren.
    $runId = New-VsRunId
    $cycleStart = Get-Date

    $outcome = 'unknown'
    $category = 'query_failed'
    $summary = @{}

    try {
        $health = Get-VsMecmSiteHealth -Config $config
        $outcome = $health.Outcome
        $category = $health.ErrorCategory

        if ($outcome -eq 'unknown') {
            # Ein Provider-/Abfragefehler. provider_unreachable erst nach dem
            # ZWEITEN Fehlzyklus melden - der erste kann ein MECM-Neustart sein.
            $consecutiveProviderFailures++
            $category = Get-VsSiteHealthReportCategory -Category $category -ConsecutiveFailures $consecutiveProviderFailures
        } else {
            $consecutiveProviderFailures = 0
        }

        # Nur vorhandene Werte in die Summary aufnehmen (ein leeres Objekt lehnt
        # der Server ab; New-VsRunSummary faengt das zusaetzlich ab).
        if (-not [string]::IsNullOrWhiteSpace($health.SiteCode)) { $summary['site_code'] = [string]$health.SiteCode }
        if (-not [string]::IsNullOrWhiteSpace($health.Provider)) { $summary['provider'] = [string]$health.Provider }
        if ($null -ne $health.RawStatus) { $summary['raw_status'] = [int]$health.RawStatus }

        Write-VsLog -Message ("Site {0} (Provider {1}): Status {2} -> {3}." -f `
            $health.SiteCode, $health.Provider, $health.RawStatus, $outcome)
    } catch {
        # Get-VsMecmSiteHealth faengt selbst; dieser Catch ist der Notnagel,
        # damit eine unerwartete Ausnahme die Schleife nie beendet.
        $outcome = 'unknown'
        $category = 'query_failed'
        $consecutiveProviderFailures++
        Write-VsLog -Level ERROR -Message ("Site-Health-Abfrage fehlgeschlagen: {0}" -f (Get-VsErrorDetail -ErrorRecord $_))
    } finally {
        # Genau EINE Abschlussmeldung pro Iteration (nur completed).
        $durationMs = [int]((Get-Date) - $cycleStart).TotalMilliseconds
        $reportParams = @{
            Config          = $config
            Source          = 'mecm-site-health'
            RunEvent        = 'completed'
            RunId           = $runId
            IntervalSeconds = $intervalSeconds
            Outcome         = $outcome
            DurationMs      = $durationMs
            ScriptVersion   = $SCRIPT_VERSION
        }
        if ($outcome -ne 'ok') { $reportParams['ErrorCategory'] = $category }
        if ($summary.Count -gt 0) { $reportParams['Summary'] = $summary }
        Send-VsRunReport @reportParams
    }

    Start-Sleep -Seconds $intervalSeconds
}
