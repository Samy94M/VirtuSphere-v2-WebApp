#Requires -Version 5.1
# ============================================================================
# VirtuSphere-Common.ps1 - gemeinsame Bausteine der MECM-Server-Skripte
# ----------------------------------------------------------------------------
# Wird von den Sync-Skripten dot-gesourct:  . "$PSScriptRoot\VirtuSphere-Common.ps1"
# Konfiguration kommt ausschliesslich aus der Registry
# (HKLM:\SOFTWARE\VirtuSphere\MECM, geschrieben von install-VirtuSphere-MECM.ps1)
# - keine IPs, DNS-Namen, UNC-Pfade oder Site-Codes im Code.
# ============================================================================

# Set-StrictMode faengt den Fehler, der in einer Endlosschleife als SYSTEM am
# teuersten ist: ein vertippter Variablenname ist sonst ein stilles $null, der
# Loop laeuft weiter und tut nichts - ohne Log, ohne Absturz, ohne Hinweis.
#
# Bewusst Version 1.0 und nicht Latest: ab 2.0 wirft PowerShell auch beim Zugriff
# auf eine nicht vorhandene *Property*, und diese Skripte lesen JSON-Antworten,
# in denen optionale Felder legitim fehlen ($device.mission, $cfg.DeployTo). Eine
# strengere Stufe kann "optionales Feld fehlt" nicht von "Tippfehler" unterscheiden
# und wuerde die Sync-Skripte in Produktion zum Absturz bringen. Version 1.0 deckt
# genau die Variablen ab, um die es geht.
Set-StrictMode -Version 1.0

$script:VsRegistryPath = 'HKLM:\SOFTWARE\VirtuSphere\MECM'

# SSoT fuer den MECM-Ordnernamen der Paket-Collections/-Applications.
# Packages-Sync (liest den Ordner) und Autoimporter (befuellt ihn) muessen
# denselben Namen nutzen, sonst warnt der Sende-Guard dauerhaft.
$script:VsApplicationsFolderName = 'VirtuSphere_Applications'

# ---------------------------------------------------------------------------
# Konfiguration
# ---------------------------------------------------------------------------
function Get-VsConfig {
    try {
        $raw = Get-ItemProperty -Path $script:VsRegistryPath -ErrorAction Stop
    } catch {
        return $null
    }

    if ([string]::IsNullOrWhiteSpace($raw.VirtuSphere_WebAPI)) {
        return $null
    }

    # Die Fallback-Werte spiegeln die Parameter-Defaults von
    # install-VirtuSphere-MECM.ps1 - dort ist die SSoT, hier nur Notnagel.
    [pscustomobject]@{
        WebApi                 = [string]$raw.VirtuSphere_WebAPI            # z.B. virtusphere.lan:8021
        # http (LAN-Default) oder https, sobald das Portal auf TLS umgestellt ist.
        # Ohne diesen Schalter waere ein HTTPS-Umstieg des Portals das Ende der
        # MECM-Integration: die Maschinen-API ist zwar redirect-exempt, aber wer
        # HTTP abschaltet, schaltet die Sync-Skripte mit ab.
        Scheme                 = if ($raw.Scheme) { [string]$raw.Scheme } else { 'http' }
        ReportToken            = [string]$raw.ReportToken                    # optional
        PackagesRoot           = if ($raw.PackagesRoot) { [string]$raw.PackagesRoot } else { 'D:\VirtuSphere\Packages' }
        PackagesShare          = [string]$raw.PackagesShare                  # UNC fuer ContentLocation
        DpGroupName            = if ($raw.DpGroupName) { [string]$raw.DpGroupName } else { 'DP Group - VirtuSphere-Applications' }
        SiteCodeFallback       = [string]$raw.MECM_SiteCode
        # Optionaler SMS-Provider-Rechner (Installer-Param -ProviderMachine).
        # Leer = Get-VsProviderMachine erkennt lokal per WMI/PSDrive.
        ProviderMachine        = [string]$raw.MECM_ProviderMachine
        DeviceSyncInterval     = if ($raw.DeviceSyncIntervalSeconds) { [int]$raw.DeviceSyncIntervalSeconds } else { 10 }
        PackagesSyncInterval   = if ($raw.PackagesSyncIntervalSeconds) { [int]$raw.PackagesSyncIntervalSeconds } else { 60 }
        ImporterInterval       = if ($raw.ImporterIntervalSeconds) { [int]$raw.ImporterIntervalSeconds } else { 60 }
        SiteHealthInterval     = if ($raw.SiteHealthIntervalSeconds) { [int]$raw.SiteHealthIntervalSeconds } else { 300 }
        LogRoot                = if ($raw.LogRoot) { [string]$raw.LogRoot } elseif ($env:ProgramFiles) { Join-Path $env:ProgramFiles 'VirtuSphere\Logs' } else { Join-Path ([System.IO.Path]::GetTempPath()) 'VirtuSphere-Logs' }
    }
}

# ---------------------------------------------------------------------------
# Logging - einheitliches Format (Plan-Spezifikation):
#   ISO-8601 | LEVEL | Komponente | Kontext (Mission/VM/MAC/Phase) | Nachricht | Korrelations-ID
# Tagesdateien unter <LogRoot>\yyyy-MM-dd_<komponente>.log, Aufraeumen nach 30 Tagen.
# ---------------------------------------------------------------------------
$script:VsLogComponent = 'virtusphere'
# $env:ProgramFiles existiert nur auf Windows. Diese Zeile laeuft beim
# Dot-Sourcen, und die Pester-Suite sourct die Datei auch unter pwsh auf
# Linux (CI): ein Join-Path mit $null wirft dort und riss 44 Tests mit
# (CI-Lauf 2026-07-16). Auf dem echten Ziel (MECM-Server) unveraendert;
# anderswo zaehlt nur, dass der Dot-Source nicht wirft, denn jedes Skript
# setzt sein LogRoot ohnehin per Initialize-VsLog.
$script:VsLogRoot = if ($env:ProgramFiles) {
    Join-Path $env:ProgramFiles 'VirtuSphere\Logs'
} else {
    Join-Path ([System.IO.Path]::GetTempPath()) 'VirtuSphere-Logs'
}
$script:VsCorrelationId = [guid]::NewGuid().ToString('N').Substring(0, 8)

function Initialize-VsLog {
    param(
        [Parameter(Mandatory)][string]$Component,
        [string]$LogRoot
    )
    $script:VsLogComponent = $Component
    if ($LogRoot) { $script:VsLogRoot = $LogRoot }
    if (-not (Test-Path $script:VsLogRoot)) {
        New-Item -ItemType Directory -Path $script:VsLogRoot -Force | Out-Null
    }
}

function Write-VsLog {
    param(
        [Parameter(Mandatory)][string]$Message,
        [ValidateSet('DEBUG', 'INFO', 'WARN', 'ERROR')][string]$Level = 'INFO',
        [string]$Context = '-',
        [string]$Color
    )

    if (-not $Color) {
        $Color = switch ($Level) { 'ERROR' { 'Red' } 'WARN' { 'Yellow' } 'DEBUG' { 'DarkGray' } default { 'Gray' } }
    }
    Write-Host $Message -ForegroundColor $Color

    try {
        $line = '{0} | {1,-5} | {2} | {3} | {4} | {5}' -f (Get-Date -Format 'o'), $Level, $script:VsLogComponent, $Context, $Message, $script:VsCorrelationId
        $file = Join-Path $script:VsLogRoot ('{0}_{1}.log' -f (Get-Date -Format 'yyyy-MM-dd'), $script:VsLogComponent)
        Add-Content -Path $file -Value $line -Encoding UTF8
        Invoke-VsLogRetention
    } catch {
        # Logging darf den Hauptprozess nie stoppen. Der verschluckte Fehler ist
        # per -Debug sichtbar; ohne diese Zeile waere ein dauerhaft nicht
        # schreibbares Logverzeichnis von aussen gar nicht erkennbar.
        Write-Debug ('Logschreiben fehlgeschlagen: {0}' -f $_)
    }
}

function Invoke-VsLogRetention {
    $marker = Join-Path $script:VsLogRoot 'last_cleanup.txt'
    $due = $true
    if (Test-Path $marker) {
        try {
            $last = [datetime](Get-Content $marker -ErrorAction Stop | Select-Object -First 1)
            if (((Get-Date) - $last).TotalDays -lt 1) { $due = $false }
        } catch { Write-Debug $_ }
    }
    if (-not $due) { return }

    try {
        $cutoff = (Get-Date).AddDays(-30)
        Get-ChildItem -Path $script:VsLogRoot -Filter '*.log' -ErrorAction SilentlyContinue |
            Where-Object { $_.LastWriteTime -lt $cutoff } |
            Remove-Item -Force -ErrorAction SilentlyContinue
        Get-Date -Format 'o' | Set-Content -Path $marker -Encoding UTF8
    } catch { Write-Debug $_ }
}

# ---------------------------------------------------------------------------
# WebAPI-Aufrufe (inkl. optionalem Token-Header)
# ---------------------------------------------------------------------------
# Korrelations-ID pro Prozesslauf (ADR-0032): 16 Hex aus einer GUID, beim
# ersten Header-Bau gemintet und dann konstant. Bewusst KEIN Registry-Wert:
# ein Neustart des Skripts ist eine neue Spur. Rein diagnostisch, kein Secret
# und kein Token; die Redaction laesst sie deshalb sichtbar.
$script:VsCorrelationId = $null
function Get-VsCorrelationId {
    if (-not $script:VsCorrelationId) {
        $script:VsCorrelationId = ([guid]::NewGuid().ToString('N')).Substring(0, 16).ToLowerInvariant()
    }
    $script:VsCorrelationId
}

function Get-VsApiHeaders {
    param([Parameter(Mandatory)]$Config)
    $headers = @{}
    if (-not [string]::IsNullOrWhiteSpace($Config.ReportToken)) {
        $headers['X-VirtuSphere-Token'] = $Config.ReportToken
    }
    $headers['X-VirtuSphere-Correlation'] = (Get-VsCorrelationId)
    $headers
}

# Baut die Basis-URL. Das Schema kommt aus der Registry (Scheme=https), Default
# bleibt http (LAN-Projektziel). EINZIGE Schema-Stelle der Server-Skripte.
function Get-VsApiBaseUrl {
    param([Parameter(Mandatory)]$Config)
    $scheme = if ($Config.Scheme) { [string]$Config.Scheme } else { 'http' }
    return ('{0}://{1}' -f $scheme, $Config.WebApi)
}

# Normalisiert und validiert eine WebApi-Adresse auf die kanonische host:port-Form
# (kein Schema, kein Pfad, kein Trailing-Slash) und wirft bei klar kaputter
# Eingabe. Der Installer schreibt darueber in die Registry: Get-VsApiBaseUrl setzt
# das Schema selbst davor, ein mitgegebenes "http://" ergaebe sonst
# "http://http://...". Als reine Funktion in Common, damit Pester sie deckt.
function Convert-VsWebApi {
    param([Parameter(Mandatory)][string]$WebApi)
    $value = ($WebApi -replace '^\s*https?://', '').Trim().TrimEnd('/')
    if ($value -match '/') {
        throw "WebApi darf nur host:port sein, keinen Pfad: '$value' (Beispiel: virtusphere.lan:8021)."
    }
    if ($value -notmatch '^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?(:\d+)?$') {
        throw "WebApi ist kein gueltiges host:port: '$value' (Beispiel: virtusphere.lan:8021 oder 10.0.0.5:8021)."
    }
    return $value
}

# Liest den Antwort-Body aus einer fehlgeschlagenen Invoke-RestMethod-Exception.
#
# Das ist der Windows-PowerShell-5.1-Fallstrick, wegen dem die WebApp bisher ins
# Leere sprach: Invoke-RestMethod wirft bei 4xx/5xx eine WebException und wirft
# den Body dabei weg. Die WebApp baut aber genau dort ihre JSON-Envelope
# ({"error":"..."}) - und das Skript loggte nur "(400) Bad Request", nie den
# Grund. Wer den Fehler liest, will den Grund.
function Get-VsErrorDetail {
    param([Parameter(Mandatory)]$ErrorRecord)

    $detail = [string]$ErrorRecord.Exception.Message

    $response = $null
    if ($ErrorRecord.Exception.PSObject.Properties['Response']) {
        $response = $ErrorRecord.Exception.Response
    }
    if (-not $response) { return $detail }

    $body = $null
    try {
        # PS 5.1 / .NET Framework: HttpWebResponse mit Stream. Kein Vorab-Check auf
        # die Methode: sie kann als ScriptMethod, Methode oder gar nicht da sein,
        # und der try/catch faengt jeden dieser Faelle ohnehin ab.
        $stream = $response.GetResponseStream()
        if ($stream) {
            $reader = New-Object System.IO.StreamReader($stream)
            try { $body = $reader.ReadToEnd() } finally { $reader.Dispose() }
        }
    } catch {
        # Der Body ist ein Bonus, kein Muss: die Statuszeile bleibt in jedem Fall.
        $body = $null
    }

    if ([string]::IsNullOrWhiteSpace($body)) { return $detail }

    # Die Envelope der WebApp ist {"error": "..."} bzw. {"message": "..."}.
    try {
        $parsed = $body | ConvertFrom-Json -ErrorAction Stop
        foreach ($field in 'error', 'message') {
            if ($parsed.PSObject.Properties[$field] -and -not [string]::IsNullOrWhiteSpace([string]$parsed.$field)) {
                return ('{0} | WebApp: {1}' -f $detail, [string]$parsed.$field)
            }
        }
    } catch {
        # Kein JSON (z.B. eine nginx-Fehlerseite): gekuerzt anhaengen, damit der
        # Operator sieht, dass die Antwort gar nicht von der WebApp kam.
        $snippet = ($body -replace '\s+', ' ').Trim()
        if ($snippet.Length -gt 200) { $snippet = $snippet.Substring(0, 200) + '...' }
        if ($snippet) { return ('{0} | Antwort: {1}' -f $detail, $snippet) }
    }

    return $detail
}

# HTTP-Statuscode einer fehlgeschlagenen Anfrage, oder $null.
function Get-VsErrorStatusCode {
    param([Parameter(Mandatory)]$ErrorRecord)
    try {
        $response = $ErrorRecord.Exception.Response
        if ($response -and $response.StatusCode) { return [int]$response.StatusCode }
    } catch { Write-Debug $_ }
    return $null
}

function Invoke-VsApi {
    param(
        [Parameter(Mandatory)]$Config,
        [Parameter(Mandatory)][string]$Path,          # z.B. /mecm-api.php?action=getDeviceList
        [ValidateSet('GET', 'POST')][string]$Method = 'GET',
        $Body = $null,
        [int]$TimeoutSec = 15
    )

    $params = @{
        Uri        = ('{0}{1}' -f (Get-VsApiBaseUrl -Config $Config), $Path)
        Method     = $Method
        TimeoutSec = $TimeoutSec
        Headers    = (Get-VsApiHeaders -Config $Config)
    }
    if ($null -ne $Body) {
        $params['Body'] = ($Body | ConvertTo-Json -Depth 6)
        $params['ContentType'] = 'application/json'
    }

    Invoke-RestMethod @params
}

# Fire-and-forget Heartbeat (ADR-0018): darf den Sync nie ausbremsen.
function Send-VsHeartbeat {
    param(
        [Parameter(Mandatory)]$Config,
        # ValidateSet spiegelt den Wire-Contract von mecm_report.php
        # (gueltige source-Werte) - die PHP-Seite ist die SSoT.
        [Parameter(Mandatory)][ValidateSet('device-sync', 'packages-sync', 'autoimporter')][string]$Source,
        [Parameter(Mandatory)][int]$IntervalSeconds,
        [string]$Detail = ''
    )

    try {
        $body = @{ source = $Source; interval_seconds = $IntervalSeconds }
        if ($Detail) { $body['detail'] = $Detail }
        Invoke-VsApi -Config $Config -Path '/mecm_report.php?action=heartbeat' -Method POST -Body $body -TimeoutSec 5 | Out-Null
    } catch {
        # Bewusst still - Sichtbarkeit entsteht serverseitig durch das Ausbleiben
        # (die Ampel im Systemstatus wird rot). Der Grund bleibt aber
        # per -Debug abrufbar: ein Heartbeat, der wegen eines falschen Tokens
        # abgelehnt wird, sieht von aussen genauso aus wie ein Netzausfall.
        Write-Debug ('Heartbeat nicht zugestellt: {0}' -f (Get-VsErrorDetail -ErrorRecord $_))
    }
}

# ===========================================================================
# Run-Report-Kanal (ADR-0018): mecm_report.php?action=reportRun
# ----------------------------------------------------------------------------
# Loest die Legacy-Heartbeats ab. Die drei Sync-Tasks melden Start UND Ergebnis
# jedes Laufs, der neue Site-Health-Task nur das Ergebnis. Die PHP-Seite
# (lib/run_report.php, lib/constants.php) ist die SSoT des Wire-Contracts; die
# Tabellen und Muster hier sind order-exakte Spiegel und duerfen nicht driften.
# ===========================================================================

# 32 Hex (klein) pro Lauf, neu je Iteration. Spiegelt VIRTUSPHERE_RUN_ID_PATTERN
# (/\A[0-9a-f]{32}\z/). Guid('N') ist bereits klein; ToLowerInvariant sichert es.
function New-VsRunId {
    return ([guid]::NewGuid().ToString('N')).ToLowerInvariant()
}

# Wire-Bounds (Spiegel von lib/constants.php).
$script:VsRunIntervalMinSeconds = 5       # VIRTUSPHERE_HEARTBEAT_INTERVAL_MIN_SECONDS
$script:VsRunIntervalMaxSeconds = 3600    # VIRTUSPHERE_HEARTBEAT_INTERVAL_MAX_SECONDS
$script:VsRunDurationMsMax       = 86400000 # VIRTUSPHERE_RUN_DURATION_MS_MAX
$script:VsRunScriptVersionMaxChars = 32   # VIRTUSPHERE_RUN_SCRIPT_VERSION_MAX_CHARS
# Detail wird VOR dem Senden in BYTES gekuerzt, damit das 8-KB-Body-Limit
# (VIRTUSPHERE_CLIENT_EVENT_MAX_BODY_BYTES = 8192) nie bricht. 2048 Bytes lassen
# auch bei \uXXXX-Expansion (PS 5.1 escaped Nicht-ASCII) reichlich Luft; der
# Server kappt zusaetzlich bei 1024 Zeichen.
$script:VsReportDetailMaxBytes = 2048

# Untergrenze je Aufgabe: darunter kostet ein Lauf mehr als er einbringt (der
# Device-Sync fragt eine Liste ab, der Autoimporter kopiert Dateien und legt
# Applications an). Die Obergrenze ist fuer alle die des Wire-Contracts, denn
# ein Intervall, das der Report nicht tragen kann, wird beim Melden geklemmt -
# die Statusseite nennt dann einen Takt, in dem die Aufgabe gar nicht laeuft.
# Diese Tabelle ist die SSoT: die ValidateRange-Attribute von
# install-VirtuSphere-MECM.ps1 spiegeln sie, VirtuSphere.RunReport.Tests.ps1
# pinnt beide Seiten gegeneinander.
$script:VsIntervalBounds = [ordered]@{
    'device-sync'      = @{ Floor = 5;  Setting = 'DeviceSyncIntervalSeconds' }
    'packages-sync'    = @{ Floor = 10; Setting = 'PackagesSyncIntervalSeconds' }
    'autoimporter'     = @{ Floor = 30; Setting = 'ImporterIntervalSeconds' }
    'mecm-site-health' = @{ Floor = 60; Setting = 'SiteHealthIntervalSeconds' }
}

# Liefert den Takt, in dem die Aufgabe wirklich laeuft UND den sie meldet: eine
# Zahl, nie zwei. Ein Wert ausserhalb der Spanne wird geklemmt und die Korrektur
# protokolliert. Still zu klemmen hiesse, den Sleep und den Report auseinander
# laufen zu lassen: die Statusseite verspricht ("welcher Wert tatsaechlich
# gilt", help.stack_a6_p1) genau diesen einen Wert, und ab dem Dreifachen der
# gemeldeten Zahl faerbt sie die Zeile ein - eine Aufgabe stuende dauerhaft auf
# "Verzoegert", waehrend sie exakt das tut, was eingestellt wurde.
function Resolve-VsInterval {
    param(
        [Parameter(Mandatory)][string]$Source,
        [Parameter(Mandatory)][int]$Configured
    )

    if (-not $script:VsIntervalBounds.Contains($Source)) {
        throw ('Resolve-VsInterval: unbekannte Quelle {0}' -f $Source)
    }
    $floor = [int]$script:VsIntervalBounds[$Source].Floor
    $ceiling = $script:VsRunIntervalMaxSeconds

    $effective = $Configured
    if ($effective -lt $floor) { $effective = $floor }
    if ($effective -gt $ceiling) { $effective = $ceiling }

    if ($effective -ne $Configured) {
        Write-VsLog -Level WARN -Message (
            'Eingestelltes Intervall {0}s liegt ausserhalb der erlaubten Spanne {1}..{2}s - es gilt {3}s. Registry-Wert {4} korrigieren.' -f
                $Configured, $floor, $ceiling, $effective, $script:VsIntervalBounds[$Source].Setting)
    }

    return $effective
}

# Zustellfehler des Run-Reports werden hoechstens einmal pro Fenster ins
# Tageslog geschrieben (fire-and-forget: der Sync darf nie daran haengen).
$script:VsReportThrottleSeconds = 300
$script:VsReportLastFailureLog  = $null

# Erlaubte Summary-Schluessel je Quelle (Spiegel VIRTUSPHERE_RUN_SUMMARY_FIELDS).
$script:VsRunSummaryFields = @{
    'device-sync'      = @('received', 'imported', 'item_failures', 'data_warnings', 'resource_update_failures')
    'packages-sync'    = @('packages', 'task_sequences', 'sent', 'unchanged')
    'autoimporter'     = @('folders', 'created', 'removed', 'open_points', 'unchanged')
    'mecm-site-health' = @('site_code', 'provider', 'raw_status')
}
# Summary-Schluessel, deren Wert ein kurzer String ist statt eines Zaehlers
# (Spiegel VIRTUSPHERE_RUN_SUMMARY_STRING_FIELDS).
$script:VsRunSummaryStringFields = @('site_code', 'provider')

# Fehlerkategorien der Sync-Quellen (Spiegel VIRTUSPHERE_RUN_SYNC_ERROR_CATEGORIES).
$script:VsRunSyncErrorCategories = @('portal_unreachable', 'mecm_unavailable', 'partial_failure', 'source_missing', 'catalog_conflict')
# Site-Health-Kategorien mit fester Outcome-Bindung
# (Spiegel VIRTUSPHERE_RUN_SITE_ERROR_OUTCOME): ein Providerfehler ist grau
# (unknown), nie "MECM kritisch".
$script:VsRunSiteErrorOutcome = @{
    'site_warning'           = 'warning'
    'site_critical'          = 'fail'
    'provider_access_denied' = 'unknown'
    'provider_unreachable'   = 'unknown'
    'query_failed'           = 'unknown'
}

# --- Ursachen eines Warnlaufs ----------------------------------------------
#
# Die Systemstatus-Karte zeigt "Datenwarnungen: 3" und klappt darunter das
# `detail` auf. Device-Sync und Autoimporter sendeten dort `-Detail $null`: die
# Zahl nannte keine VM, der Aufklappblock wurde gar nicht gerendert, und der
# naechste saubere Lauf ueberschrieb die 3 mit 0. Damit war die Warnung
# vollstaendig wertlos, obwohl VM- und Collection-Name schon auf der Leitung
# lagen.
#
# Das Vokabular ist GESCHLOSSEN. Der Text landet unveraendert auf einer
# Portalseite, also darf er nicht aus einer Exception-Message stammen: die traegt
# je MECM-Version und -Sprache anderen Wortlaut und irgendwann einen Pfad oder
# einen Kontonamen. Ein Code plus die Namen, um die es geht, ist alles, was der
# Operator braucht, um die Zeile zu finden.
$script:VsRunCauseVocabulary = @(
    'mission_missing',           # VM ohne Mission: nichts zuzuweisen
    'mac_missing',               # VM ohne DHCP-MAC: PXE kann nie greifen
    'mac_conflict',              # MECM kennt eine andere MAC als das Portal
    'device_import_failed',      # Import-CMComputerInformation fehlgeschlagen
    'collection_missing',        # Zielcollection existiert nicht
    'collection_assign_failed',  # Add-CMDeviceCollectionDirectMembershipRule fehlgeschlagen
    'collection_update_failed',  # Invoke-CMCollectionUpdate fehlgeschlagen
    'collection_folder_failed',  # Ordner/Verschieben fehlgeschlagen
    'resource_id_pending',       # MECM hat noch keine ResourceID vergeben
    'resource_update_failed',    # ResourceID-Rueckmeldung ans Portal fehlgeschlagen
    'package_config_invalid',    # config.json unlesbar oder Pflichtfeld fehlt
    'package_content_failed',    # Content-Verteilung fehlgeschlagen
    'package_source_missing'     # files-Pfad des Paketordners fehlt
)

function New-VsRunCauseList {
    return New-Object System.Collections.Generic.List[string]
}

# Haengt eine Ursache an die Liste eines Laufs. ValidateSet statt eines freien
# Strings, damit ein Tippfehler beim Aufruf sofort auffaellt; die Liste oben ist
# die SSoT und wird von der Pester-Suite in beide Richtungen dagegen gehalten.
function Add-VsRunCause {
    param(
        [Parameter(Mandatory)]$Causes,
        [Parameter(Mandatory)]
        [ValidateSet('mission_missing', 'mac_missing', 'mac_conflict', 'device_import_failed',
            'collection_missing', 'collection_assign_failed', 'collection_update_failed',
            'collection_folder_failed', 'resource_id_pending', 'resource_update_failed',
            'package_config_invalid', 'package_content_failed', 'package_source_missing')]
        [string]$Cause,
        [string]$Target = '',
        [string]$Collection = ''
    )
    if ($null -eq $Causes) { return }

    $parts = @($Cause)
    if (-not [string]::IsNullOrWhiteSpace($Target)) { $parts += ('target={0}' -f $Target.Trim()) }
    if (-not [string]::IsNullOrWhiteSpace($Collection)) { $parts += ('collection={0}' -f $Collection.Trim()) }
    $Causes.Add(($parts -join ' '))
}

# Fasst die Ursachen eines Laufs zu EINER Detailzeile zusammen. Gedeckelt, weil
# ein Lauf ueber hundert VMs sonst eine Detailzeile erzeugt, die der Server
# ohnehin abschneidet: dann besser ehrlich sagen, wie viele nicht dastehen.
# Liefert $null bei leerer Liste, damit ein sauberer Lauf kein leeres Detail
# sendet.
function Format-VsRunDetail {
    param($Causes, [int]$MaxCauses = 10)
    if ($null -eq $Causes -or @($Causes).Count -eq 0) { return $null }

    $all = @($Causes)
    $shown = @($all | Select-Object -First $MaxCauses)
    $line = $shown -join '; '
    if ($all.Count -gt $shown.Count) {
        $line += ('; (+{0} weitere)' -f ($all.Count - $shown.Count))
    }
    return $line
}

# Kuerzt einen String auf hoechstens $MaxBytes UTF-8-Bytes, ohne eine
# Mehrbyte-Sequenz zu zerschneiden (sonst waere der Body kein gueltiges UTF-8).
function Get-VsTruncatedUtf8 {
    param([string]$Text, [int]$MaxBytes)
    if ([string]::IsNullOrEmpty($Text)) { return $Text }
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($Text)
    if ($bytes.Length -le $MaxBytes) { return $Text }
    $count = $MaxBytes
    # Ein Fortsetzungsbyte ist 10xxxxxx (0x80..0xBF). Solange das erste
    # ABGESCHNITTENE Byte ein solches ist, stehen wir mitten in einer Sequenz
    # und muessen zurueck bis zur naechsten Zeichengrenze.
    while ($count -gt 0 -and ($bytes[$count] -band 0xC0) -eq 0x80) { $count-- }
    return [System.Text.Encoding]::UTF8.GetString($bytes, 0, $count)
}

# Bereinigt Detail-Text fuer den Run-Report: Steuerzeichen raus (wie der Server,
# [\x00-\x1F\x7F] -> Leerzeichen), den Rueckkanal-Token redigieren, falls er je
# im Text auftaucht, und in BYTES kuerzen. Liefert $null bei leerem Ergebnis.
function Get-VsReportDetail {
    param([string]$Text, [string]$Token)
    if ([string]::IsNullOrEmpty($Text)) { return $null }
    $clean = [regex]::Replace($Text, '[\x00-\x1F\x7F]+', ' ')
    if (-not [string]::IsNullOrWhiteSpace($Token)) {
        $clean = $clean.Replace($Token, '[redacted]')
    }
    $clean = $clean.Trim()
    if ($clean -eq '') { return $null }
    return (Get-VsTruncatedUtf8 -Text $clean -MaxBytes $script:VsReportDetailMaxBytes)
}

# Baut das Summary-Objekt einer Quelle aus einer Hashtable: nur Whitelist-
# Schluessel, Zaehler als nicht-negative Ganzzahl, die zwei String-Felder als
# String. Liefert $null, wenn nichts uebrig bleibt (ein leeres Objekt lehnt der
# Server als Liste ab, deshalb nie senden).
function New-VsRunSummary {
    param(
        [Parameter(Mandatory)][ValidateSet('device-sync', 'packages-sync', 'autoimporter', 'mecm-site-health')][string]$Source,
        [hashtable]$Values
    )
    if (-not $Values) { return $null }
    $allowed = $script:VsRunSummaryFields[$Source]
    $clean = @{}
    foreach ($key in @($Values.Keys)) {
        if ($allowed -notcontains $key) { continue }
        $val = $Values[$key]
        if ($null -eq $val) { continue }
        if ($script:VsRunSummaryStringFields -contains $key) {
            $clean[$key] = [string]$val
            continue
        }
        $n = 0
        if ([int]::TryParse([string]$val, [ref]$n)) {
            if ($n -lt 0) { $n = 0 }
            $clean[$key] = $n
        }
    }
    if ($clean.Count -eq 0) { return $null }
    return $clean
}

# Zentrale Kategorisierung: gilt eine Fehlerkategorie fuer diese Quelle und
# dieses Outcome? Site-Health-Kategorien sind an ein festes Outcome gebunden,
# die Sync-Quellen teilen einen Satz. Spiegelt run_report_validate_error_category().
function Test-VsRunErrorCategory {
    param([string]$Source, [string]$Outcome, [string]$Category)
    if ([string]::IsNullOrWhiteSpace($Category)) { return $false }
    if ($Source -eq 'mecm-site-health') {
        if (-not $script:VsRunSiteErrorOutcome.ContainsKey($Category)) { return $false }
        return ($script:VsRunSiteErrorOutcome[$Category] -eq $Outcome)
    }
    return ($script:VsRunSyncErrorCategories -contains $Category)
}

# Loggt einen Zustellfehler des Run-Reports hoechstens einmal pro Throttle-
# Fenster ins Tageslog; dazwischen bleibt es bei Write-Debug (Opt-in-Konsole).
function Write-VsRunReportFailure {
    param([Parameter(Mandatory)]$ErrorRecord)
    $msg = 'Run-Report nicht zugestellt: {0}' -f (Get-VsErrorDetail -ErrorRecord $ErrorRecord)
    $now = Get-Date
    $due = $true
    if ($script:VsReportLastFailureLog) {
        if (($now - $script:VsReportLastFailureLog).TotalSeconds -lt $script:VsReportThrottleSeconds) { $due = $false }
    }
    if ($due) {
        # WARN, nicht DEBUG. Ein verlorener Run-Report heisst: die Statusseite
        # weiss von diesem Lauf nichts und faerbt die Zeile mit der Zeit gelb bis
        # rot, waehrend die Aufgabe tadellos laeuft. Auf DEBUG war das im
        # Tageslog nicht zu sehen, und die Doku behauptete ohnehin WARN. Gedrosselt
        # bleibt es: ein dauerhaft unerreichbares Portal soll das Log nicht fluten.
        Write-VsLog -Level WARN -Message $msg
        $script:VsReportLastFailureLog = $now
    } else {
        Write-Debug $msg
    }
}

# Fire-and-forget Run-Report (ADR-0018): baut den Body nach dem Wire-Contract,
# kuerzt Detail VOR dem Senden in Bytes und POSTet mit 5s-Timeout ueber
# Invoke-VsApi (gleicher Token-/Korrelations-Header wie der Heartbeat). Ein
# Zustellfehler wird NUR gedrosselt geloggt und NIE geworfen: der eigentliche
# MECM-Lauf darf daran nicht haengen. Kein Replay-Queue - der Server dedupt einen
# identisch wiederholten completed-run_id, die Ankunftsreihenfolge ist die Wahrheit.
function Send-VsRunReport {
    param(
        [Parameter(Mandatory)]$Config,
        [Parameter(Mandatory)][ValidateSet('device-sync', 'packages-sync', 'autoimporter', 'mecm-site-health')][string]$Source,
        # Nicht $Event: das ist eine automatische PowerShell-Variable. Das
        # Wire-Feld heisst weiterhin "event".
        [Parameter(Mandatory)][ValidateSet('started', 'completed')][string]$RunEvent,
        [Parameter(Mandatory)][string]$RunId,
        [Parameter(Mandatory)][int]$IntervalSeconds,
        [ValidateSet('ok', 'warning', 'fail', 'unknown')][string]$Outcome,
        [string]$ErrorCategory,
        [int]$DurationMs,
        [string]$Detail,
        [hashtable]$Summary,
        [string]$ScriptVersion
    )

    # interval_seconds in die gueltige Spanne klemmen, damit ein exotisch
    # konfiguriertes Intervall den Report nicht garantiert mit 400 verwirft.
    $interval = [int]$IntervalSeconds
    if ($interval -lt $script:VsRunIntervalMinSeconds) { $interval = $script:VsRunIntervalMinSeconds }
    if ($interval -gt $script:VsRunIntervalMaxSeconds) { $interval = $script:VsRunIntervalMaxSeconds }

    $body = [ordered]@{
        source           = $Source
        event            = $RunEvent
        run_id           = $RunId
        interval_seconds = $interval
    }
    if (-not [string]::IsNullOrWhiteSpace($ScriptVersion)) {
        $sv = $ScriptVersion
        if ($sv.Length -gt $script:VsRunScriptVersionMaxChars) { $sv = $sv.Substring(0, $script:VsRunScriptVersionMaxChars) }
        $body['script_version'] = $sv
    }

    if ($RunEvent -eq 'completed') {
        # outcome ist bei completed Pflicht; ein fehlender Wert waere ein
        # Programmierfehler im Aufrufer - dann ehrlich unknown melden.
        $effectiveOutcome = if ([string]::IsNullOrWhiteSpace($Outcome)) { 'unknown' } else { $Outcome }
        $body['outcome'] = $effectiveOutcome

        if ($effectiveOutcome -ne 'ok' -and -not [string]::IsNullOrWhiteSpace($ErrorCategory)) {
            $body['error_category'] = $ErrorCategory
        }

        if ($PSBoundParameters.ContainsKey('DurationMs')) {
            $d = [int]$DurationMs
            if ($d -lt 0) { $d = 0 }
            if ($d -gt $script:VsRunDurationMsMax) { $d = $script:VsRunDurationMsMax }
            $body['duration_ms'] = $d
        }

        $detailClean = Get-VsReportDetail -Text $Detail -Token $Config.ReportToken
        if ($null -ne $detailClean) { $body['detail'] = $detailClean }

        if ($Summary) {
            $summaryClean = New-VsRunSummary -Source $Source -Values $Summary
            if ($null -ne $summaryClean) { $body['summary'] = $summaryClean }
        }
    }

    try {
        Invoke-VsApi -Config $Config -Path '/mecm_report.php?action=reportRun' -Method POST -Body $body -TimeoutSec 5 | Out-Null
    } catch {
        Write-VsRunReportFailure -ErrorRecord $_
    }
}

# ---------------------------------------------------------------------------
# MECM Site-Health (Provider-Aufloesung + SMS_SummarizerSiteStatus)
# ---------------------------------------------------------------------------

# Reine Status-Abbildung (SMS_SummarizerSiteStatus.Status -> Outcome/Kategorie):
# 0=OK, 1=Warnung, 2=Kritisch, alles andere unbekannt. Als reine Funktion, damit
# Pester sie ohne MECM prueft. Spiegelt VIRTUSPHERE_RUN_SITE_ERROR_OUTCOME.
function Get-VsSiteHealthOutcome {
    param([Parameter(Mandatory)][int]$RawStatus)
    switch ($RawStatus) {
        0 { return [pscustomobject]@{ Outcome = 'ok';      ErrorCategory = $null } }
        1 { return [pscustomobject]@{ Outcome = 'warning'; ErrorCategory = 'site_warning' } }
        2 { return [pscustomobject]@{ Outcome = 'fail';    ErrorCategory = 'site_critical' } }
        default { return [pscustomobject]@{ Outcome = 'unknown'; ErrorCategory = 'query_failed' } }
    }
}

# provider_unreachable wird erst nach dem ZWEITEN aufeinanderfolgenden Fehlzyklus
# gemeldet: der erste kann ein MECM-Neustart sein, den man nicht sofort als
# Fehler schreiben will. Vorher wird die Kategorie auf query_failed gedaempft.
# Reine Funktion, damit Pester die Zwei-Zyklen-Regel ohne die Endlosschleife deckt.
function Get-VsSiteHealthReportCategory {
    param([string]$Category, [int]$ConsecutiveFailures)
    if ($Category -eq 'provider_unreachable' -and $ConsecutiveFailures -lt 2) {
        return 'query_failed'
    }
    return $Category
}

# Kategorisiert einen Provider-Fehler sprachunabhaengig ueber den HRESULT und
# best-effort ueber den Meldungstext. NIE wird die volle MECM-Meldung uebernommen;
# der Aufrufer meldet nur die Kategorie.
function Get-VsProviderFaultCategory {
    param([Parameter(Mandatory)]$ErrorRecord)
    $msg = ''
    $hres = 0
    try { $msg = [string]$ErrorRecord.Exception.Message } catch { Write-Debug $_ }
    try { if ($ErrorRecord.Exception.HResult) { $hres = [int]$ErrorRecord.Exception.HResult } } catch { Write-Debug $_ }

    # 0x80070005 = Zugriff verweigert; -2147024891 als signed int.
    if ($hres -eq -2147024891 -or $msg -match '(?i)access is denied|access denied|zugriff verweigert|E_ACCESSDENIED|0x80070005') {
        return 'provider_access_denied'
    }
    # 0x800706BA = RPC-Server nicht verfuegbar; -2147023174 signed.
    if ($hres -eq -2147023174 -or $msg -match '(?i)RPC server is unavailable|server is unavailable|cannot connect|network path|could not be resolved|not reachable|nicht erreichbar|timed out|0x800706BA') {
        return 'provider_unreachable'
    }
    return 'query_failed'
}

# Loest die SMS-Provider-Maschine in fester Reihenfolge auf:
#  1. -ProviderMachine (Installer-Param) bzw. Registry MECM_ProviderMachine,
#  2. lokale WMI-Erkennung: antwortet root\SMS __NAMESPACE lokal, ist der lokale
#     Rechner der Provider,
#  3. Root des initialisierten CMSite-PSDrive,
#  4. lokaler Rechnername als letzter Notnagel.
function Get-VsProviderMachine {
    param($Config, [string]$ProviderMachine)

    if (-not [string]::IsNullOrWhiteSpace($ProviderMachine)) { return $ProviderMachine.Trim() }
    if ($Config -and -not [string]::IsNullOrWhiteSpace($Config.ProviderMachine)) { return ([string]$Config.ProviderMachine).Trim() }

    try {
        $ns = Get-CimInstance -Namespace 'root\SMS' -ClassName '__NAMESPACE' -ErrorAction Stop |
            Where-Object { $_.Name -like 'site_*' } | Select-Object -First 1
        if ($ns) { return $env:COMPUTERNAME }
    } catch { Write-Debug $_ }

    try {
        $drive = Get-PSDrive -PSProvider CMSite -ErrorAction Stop | Select-Object -First 1
        if ($drive -and -not [string]::IsNullOrWhiteSpace($drive.Root)) { return [string]$drive.Root }
    } catch { Write-Debug $_ }

    return $env:COMPUTERNAME
}

# Fragt SMS_SummarizerSiteStatus fuer den konfigurierten Site-Code per CIM ab.
# Kein ConfigurationManager-Modul noetig und keines wird geladen: der PSDrive-
# Fallback in Get-VsProviderMachine liest hoechstens ein bereits vorhandenes
# CMSite-PSDrive, importiert aber selbst kein Modul (in einem SYSTEM-Prozess
# waere ein Modul-Load unnoetig schwer und fehleranfaellig). Liefert immer ein
# Objekt (wirft nie), mit Site-Code, Provider, numerischem Rohstatus und
# abgeleitetem Outcome/Kategorie. Reports kopieren nie die MECM-Statusmeldung -
# nur diese vier Werte.
function Get-VsMecmSiteHealth {
    param($Config, [string]$ProviderMachine)

    $siteCode = Get-VsSiteCode -Config $Config
    $provider = Get-VsProviderMachine -Config $Config -ProviderMachine $ProviderMachine

    $result = [pscustomobject]@{
        SiteCode      = $siteCode
        Provider      = $provider
        RawStatus     = $null
        Outcome       = 'unknown'
        ErrorCategory = 'query_failed'
    }

    if ([string]::IsNullOrWhiteSpace($siteCode)) {
        return $result
    }

    try {
        $namespace = 'root\SMS\site_{0}' -f $siteCode
        $cimParams = @{
            Namespace   = $namespace
            ClassName   = 'SMS_SummarizerSiteStatus'
            ErrorAction = 'Stop'
        }
        if (-not [string]::IsNullOrWhiteSpace($provider) -and $provider -ne $env:COMPUTERNAME) {
            $cimParams['ComputerName'] = $provider
        }

        $all = @(Get-CimInstance @cimParams)
        $status = $all | Where-Object { [string]$_.SiteCode -eq $siteCode } | Select-Object -First 1
        if (-not $status) { $status = $all | Select-Object -First 1 }
        if (-not $status) {
            $result.ErrorCategory = 'query_failed'
            return $result
        }

        $raw = [int]$status.Status
        $mapped = Get-VsSiteHealthOutcome -RawStatus $raw
        $result.RawStatus     = $raw
        $result.Outcome       = $mapped.Outcome
        $result.ErrorCategory = $mapped.ErrorCategory
        return $result
    } catch {
        $result.Outcome       = 'unknown'
        $result.ErrorCategory = Get-VsProviderFaultCategory -ErrorRecord $_
        return $result
    }
}

# ---------------------------------------------------------------------------
# MAC-Normalisierung (kanonisch: Grossbuchstaben, Doppelpunkte)
#
# Diese Funktion existiert dreimal: hier, in clients\VirtuSphere-Client-Common.ps1
# und als virtusphere_normalize_mac() in PHP. Die drei laufen auf verschiedenen
# Maschinen (MECM-Server, Client, WebApp) und koennen sich keine Datei teilen. Sie
# duerfen aber nicht auseinanderlaufen: das Portal schreibt die MAC, MECM sucht sie
# per exaktem Match: eine abweichende Schreibweise macht eine VM fuer MECM
# unauffindbar, ohne jede Fehlermeldung (TESTPLAN-Befund 2.2).
#
# Gemeinsame Wahrheit ist Docker\WebAPI\tests\fixtures\mac-vectors.json; PHPUnit
# und Pester pruefen beide Seiten dagegen. Wer diese Funktion aendert, aendert
# alle drei oder bricht den Build.
# ---------------------------------------------------------------------------
function ConvertTo-VsNormalizedMac {
    param([string]$Mac)
    if ([string]::IsNullOrWhiteSpace($Mac)) { return $null }
    $hex = ($Mac -replace '[^0-9A-Fa-f]', '').ToUpperInvariant()
    if ($hex.Length -ne 12) { return $null }
    return ($hex -split '(?<=\G..)(?=.)') -join ':'
}

# ---------------------------------------------------------------------------
# Paket-Konfiguration (config.json der Paketordner)
# ---------------------------------------------------------------------------
# Liegt hier und nicht im Autoimporter, weil der Autoimporter eine Endlosschleife
# ist: was in ihm steht, kann kein Test aufrufen, ohne sie zu starten.
#
# Liefert $null bei Pflichtfeldfehlern (Aufrufer ueberspringt den Ordner).
function Read-VsPackageConfig {
    param([Parameter(Mandatory)][string]$Folder)

    $configPath = Join-Path $Folder 'config.json'
    if (-not (Test-Path $configPath)) { return $null }

    $context = Split-Path $Folder -Leaf
    try {
        $cfg = Get-Content -Path $configPath -Raw | ConvertFrom-Json
    } catch {
        Write-VsLog -Level WARN -Context $context -Message 'config.json ist kein gueltiges JSON - uebersprungen.'
        return $null
    }
    if (-not $cfg) {
        Write-VsLog -Level WARN -Context $context -Message 'config.json ist leer - uebersprungen.'
        return $null
    }
    if ([string]::IsNullOrWhiteSpace($cfg.ProjectName) -or [string]::IsNullOrWhiteSpace($cfg.version)) {
        Write-VsLog -Level WARN -Context $context -Message 'config.json ohne ProjectName/version - uebersprungen.'
        return $null
    }
    # Der Katalog trennt "Name-Version" am LETZTEN Bindestrich (lib/repo/catalog.php).
    # Eine version mit Bindestrich (z.B. "1.0-beta") wuerde die Basisnamen-Gruppierung
    # fuer Retire/Relink verschieben, daher hier hart ablehnen.
    if ([string]$cfg.version -match '-') {
        Write-VsLog -Level WARN -Context $context -Message ('version "{0}" enthaelt einen Bindestrich - nicht erlaubt (verschiebt die Katalog-Gruppierung). Uebersprungen.' -f $cfg.version)
        return $null
    }
    # Ein Bindestrich im ProjectName ist erlaubt (Firefox-ESR), er landet links
    # vom letzten Bindestrich und damit im Basisnamen.
    $cfg | Add-Member -NotePropertyName 'FolderName' -NotePropertyValue $context -Force
    return $cfg
}

# Muster fuer die Alt-Versions-Bereinigung: EXAKT 'Name-<version ohne Bindestrich>'.
#
# Der Regex ist der Fix eines echten Datenverlust-Bugs: frueher wurde mit dem
# Wildcard 'Name*' geloescht, so dass ein Firefox-Update auch 'Firefox-ESR-115'
# mitnahm. Der Anker und das [^-]+ sind die ganze Verteidigung, deshalb hat das
# Muster eine eigene Funktion und einen eigenen Test.
function Get-VsSupersededNamePattern {
    param([Parameter(Mandatory)][string]$AppName)
    return ('^{0}-[^-]+$' -f [Regex]::Escape($AppName))
}

# ---------------------------------------------------------------------------
# MECM Site-Code + Site-Drive
# ---------------------------------------------------------------------------
function Get-VsSiteCode {
    param($Config)

    try {
        $ns = Get-CimInstance -Namespace 'root\SMS' -ClassName '__NAMESPACE' -ErrorAction Stop |
            Where-Object { $_.Name -like 'site_*' } | Select-Object -First 1
        if ($ns) { return ($ns.Name -replace '^site_', '') }
    } catch { Write-Debug $_ }

    try {
        $drive = Get-PSDrive -PSProvider CMSite -ErrorAction Stop | Select-Object -First 1
        if ($drive) { return $drive.Name }
    } catch { Write-Debug $_ }

    if ($Config -and -not [string]::IsNullOrWhiteSpace($Config.SiteCodeFallback)) {
        return $Config.SiteCodeFallback
    }

    return $null
}

# Importiert das ConfigurationManager-Modul und wechselt ins Site-Drive.
# Liefert den Site-Code oder $null (Aufrufer entscheidet ueber Retry/Abbruch).
function Initialize-VsCmSite {
    param($Config)

    $siteCode = Get-VsSiteCode -Config $Config
    if (-not $siteCode) {
        Write-VsLog -Level ERROR -Message 'Site-Code nicht ermittelbar (WMI, PSDrive, Registry).'
        return $null
    }

    try {
        if (-not (Get-Module ConfigurationManager)) {
            $modulePath = Join-Path (Split-Path $env:SMS_ADMIN_UI_PATH -Parent) 'ConfigurationManager.psd1'
            Import-Module $modulePath -ErrorAction Stop
        }
        Set-Location ("{0}:" -f $siteCode) -ErrorAction Stop
        return $siteCode
    } catch {
        Write-VsLog -Level ERROR -Message ("MECM-Initialisierung fehlgeschlagen: {0}" -f $_.Exception.Message)
        return $null
    }
}
