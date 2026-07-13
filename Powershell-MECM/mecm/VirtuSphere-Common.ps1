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

# SSoT fuer den SCCM-Ordnernamen der Paket-Collections/-Applications.
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
        DeviceSyncInterval     = if ($raw.DeviceSyncIntervalSeconds) { [int]$raw.DeviceSyncIntervalSeconds } else { 10 }
        PackagesSyncInterval   = if ($raw.PackagesSyncIntervalSeconds) { [int]$raw.PackagesSyncIntervalSeconds } else { 60 }
        ImporterInterval       = if ($raw.ImporterIntervalSeconds) { [int]$raw.ImporterIntervalSeconds } else { 60 }
        LogRoot                = if ($raw.LogRoot) { [string]$raw.LogRoot } else { Join-Path $env:ProgramFiles 'VirtuSphere\Logs' }
    }
}

# ---------------------------------------------------------------------------
# Logging - einheitliches Format (Plan-Spezifikation):
#   ISO-8601 | LEVEL | Komponente | Kontext (Mission/VM/MAC/Phase) | Nachricht | Korrelations-ID
# Tagesdateien unter <LogRoot>\yyyy-MM-dd_<komponente>.log, Aufraeumen nach 30 Tagen.
# ---------------------------------------------------------------------------
$script:VsLogComponent = 'virtusphere'
$script:VsLogRoot = Join-Path $env:ProgramFiles 'VirtuSphere\Logs'
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
function Get-VsApiHeaders {
    param([Parameter(Mandatory)]$Config)
    $headers = @{}
    if (-not [string]::IsNullOrWhiteSpace($Config.ReportToken)) {
        $headers['X-VirtuSphere-Token'] = $Config.ReportToken
    }
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
        # (die Ampel auf der Integrationsseite wird rot). Der Grund bleibt aber
        # per -Debug abrufbar: ein Heartbeat, der wegen eines falschen Tokens
        # abgelehnt wird, sieht von aussen genauso aus wie ein Netzausfall.
        Write-Debug ('Heartbeat nicht zugestellt: {0}' -f (Get-VsErrorDetail -ErrorRecord $_))
    }
}

# ---------------------------------------------------------------------------
# MAC-Normalisierung (kanonisch: Grossbuchstaben, Doppelpunkte)
#
# Diese Funktion existiert dreimal: hier, in clients\VirtuSphere-Client-Common.ps1
# und als virtusphere_normalize_mac() in PHP. Die drei laufen auf verschiedenen
# Maschinen (SCCM-Server, Client, WebApp) und koennen sich keine Datei teilen. Sie
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
# SCCM Site-Code + Site-Drive
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
        Write-VsLog -Level ERROR -Message ("SCCM-Initialisierung fehlgeschlagen: {0}" -f $_.Exception.Message)
        return $null
    }
}
