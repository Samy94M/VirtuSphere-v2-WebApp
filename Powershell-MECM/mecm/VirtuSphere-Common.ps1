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

# SSoT der erlaubten InstallationBehaviorType-Werte einer config.json.
# Zwei Seiten beantworten dieselbe Frage: der Autoimporter legt die
# Detection-Klausel auf HKLM oder HKCU, und Package_Vorlage\install.ps1 schreibt
# den Schluessel dorthin. Sie rieten frueher entgegengesetzt (Autoimporter auf
# InstallForSystem, Vorlage auf InstallForUser), also griff die Erkennung bei
# einem fehlenden oder vertippten Wert nie und die App stand dauerhaft auf
# Fehler. Der erste Eintrag ist der Standard; die Vorlage fuehrt das Literal
# gespiegelt, weil sie allein in den Paketordner kopiert wird und Common dort
# nie existiert.
$script:VsInstallationBehaviorTypes = @('InstallForSystem', 'InstallForUser')

# SSoT der Schalter, mit denen VirtuSphere eine powershell.exe startet - in den
# geplanten Aufgaben, in den Deployment-Types der Client-Apps und in denen des
# Autoimporters. Alles davon laeuft als SYSTEM.
#
# -NoProfile: ein maschinenweites Profil (AllUsersAllHosts) ist Fremdcode im
#   Installations- bzw. Sync-Prozess und kann Kodierung, PSModulePath oder
#   $ErrorActionPreference setzen, die die Skripte nicht erwarten. Ein
#   unbeaufsichtigter Dienst laedt kein Profil.
# -NonInteractive: ohne den Schalter kann eine Rueckfrage die Bereitstellung
#   haengen lassen, bis MECM sie abbricht - unter SYSTEM sieht sie niemand.
#
# Die Paketvorlage fuehrt dieselbe Zeichenkette als Literal, weil sie einzeln in
# den Paketordner kopiert wird und diese Datei nie sieht; ein Test haelt sie
# gegen die Konstante.
$script:VsPowerShellArgs = '-NoProfile -ExecutionPolicy Bypass -NonInteractive'

# Vollstaendige Kommandozeile fuer ein Skript. Der Pfad wird in
# Anfuehrungszeichen gesetzt, weil er Leerzeichen enthalten kann
# (C:\Program Files\VirtuSphere\mecm).
function Get-VsPowerShellCommandLine {
    param([Parameter(Mandatory)][string]$ScriptPath)
    return ('powershell.exe {0} -File "{1}"' -f $script:VsPowerShellArgs, $ScriptPath)
}

function Get-VsDangerousFileSystemAclEntries {
    param([Parameter(Mandatory)]$Acl)
    $broadSids = @('S-1-1-0', 'S-1-5-11', 'S-1-5-32-545')
    $writeMask = [Security.AccessControl.FileSystemRights]::WriteData -bor
        [Security.AccessControl.FileSystemRights]::AppendData -bor
        [Security.AccessControl.FileSystemRights]::WriteAttributes -bor
        [Security.AccessControl.FileSystemRights]::WriteExtendedAttributes -bor
        [Security.AccessControl.FileSystemRights]::Delete -bor
        [Security.AccessControl.FileSystemRights]::ChangePermissions -bor
        [Security.AccessControl.FileSystemRights]::TakeOwnership
    return @($Acl.Access | Where-Object {
        $entry = $_
        if ($entry.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow) { return $false }
        try { $sid = $entry.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value } catch { $sid = [string]$entry.IdentityReference }
        return $broadSids -contains $sid -and (([int64]$entry.FileSystemRights -band [int64]$writeMask) -ne 0)
    })
}

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
        # SHA-1-Fingerabdruck des Portal-Zertifikats, ohne Trennzeichen. Nur fuer
        # Scheme=https relevant und dann die EINZIGE Art, einem selbstsignierten
        # Zertifikat zu vertrauen: hinterlegt statt Pruefung abgeschaltet. Leer
        # heisst normale Kettenpruefung (also ein Zertifikat aus einer PKI, der
        # der Rechner schon vertraut).
        CertThumbprint         = ([string]$raw.CertThumbprint) -replace '[^0-9A-Fa-f]', ''
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
# Logging-Fassade
# ---------------------------------------------------------------------------
# Die Logging-/Retention-Domaene ist seit Etappe 10D ein lokales, mit dem
# Serverpaket installiertes Modul. Common bleibt der oeffentliche Dot-Source-
# Pfad. Fehlende oder unpassende Dateien muessen hier sichtbar scheitern, bevor
# eine geplante Aufgabe ohne Diagnosekanal in ihre Endlosschleife geht.
$script:VsExpectedLoggingContractVersion = 1
$loggingModule = Join-Path $PSScriptRoot 'VirtuSphere-Logging.ps1'
if (-not (Test-Path $loggingModule)) {
    throw ('VirtuSphere-Logging-Modul fehlt: {0}. Installer erneut ausfuehren.' -f $loggingModule)
}
. $loggingModule
$loggingVersion = Get-VsLoggingContractVersion
if ($loggingVersion -ne $script:VsExpectedLoggingContractVersion) {
    throw ('VirtuSphere-Logging-Modul hat Version {0}, erwartet wird {1}. Serverpaket vollstaendig aktualisieren.' -f $loggingVersion, $script:VsExpectedLoggingContractVersion)
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
    $headers['X-VirtuSphere-Correlation'] = (Get-VsCorrelationId)
    $headers
}

function ConvertTo-VsUtf8JsonBytes {
    param([Parameter(Mandatory)]$Value, [int]$Depth = 6)
    $json = ConvertTo-Json -InputObject $Value -Depth $Depth
    # PowerShell enumerates collections written by a function. A byte[] written
    # directly therefore reaches Invoke-RestMethod as object[]; its body binder
    # no longer takes the binary branch and converts that object to text. The
    # unary comma is consumed by the function-output pipeline and emits the
    # byte[] itself as one object, preserving both its CLR type and exact bytes.
    return ,([Text.Encoding]::UTF8.GetBytes($json))
}

# Baut die Basis-URL. Das Schema kommt aus der Registry (Scheme=https), Default
# bleibt http (LAN-Projektziel). EINZIGE Schema-Stelle der Server-Skripte.
function Get-VsApiBaseUrl {
    param([Parameter(Mandatory)]$Config)
    $scheme = if ($Config.Scheme) { [string]$Config.Scheme } else { 'http' }
    return ('{0}://{1}' -f $scheme, $Config.WebApi)
}

# TLS-Vorbereitung fuer Windows PowerShell 5.1. Zwilling der gleichnamigen
# Funktion in clients\VirtuSphere-Client-Common.ps1 (ADR-0029 erlaubt Zwillinge
# ausdruecklich: die Client-Skripte kommen einzeln per MECM-Paket auf die VM und
# koennen diese Datei nicht laden).
#
# Warum es das hier ueberhaupt braucht: die Client-Seite machte es richtig, die
# Serverseite nicht. TLS 1.2 setzte AUSSCHLIESSLICH der Installer, in seinem
# eigenen Prozess; die vier Aufgaben laufen in eigenen Prozessen, und unter
# PS 5.1 ist der Vorgabewert von SecurityProtocol je nach Windows-Version zu alt
# (SSL3/TLS1). Ein Portal auf TLS 1.2+ haette die vier Aufgaben also mit einem
# Handshake-Fehler stillgelegt, waehrend Scheme=https laengst konfigurierbar war.
#
# Selbstsigniert wird ueber den HINTERLEGTEN FINGERABDRUCK vertraut, nicht durch
# Abschalten der Pruefung: ein Zertifikatswechsel bleibt damit eine bewusste
# Handlung (der Abruf schlaegt fehl, bis der neue Fingerabdruck eingetragen ist)
# statt einer dauerhaft blinden Verbindung, die jeder im Netz beantworten kann.
function Initialize-VsTls {
    param($Config)

    $scheme = if ($Config -and $Config.Scheme) { [string]$Config.Scheme } else { 'http' }
    if ($scheme -ne 'https') { return }

    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    } catch { Write-Debug $_ }

    $pinned = if ($Config) { [string]$Config.CertThumbprint } else { '' }
    if ([string]::IsNullOrWhiteSpace($pinned)) { return }

    $expected = $pinned.ToUpperInvariant()
    try {
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback = {
            param($senderObject, $certificate, $chain, $sslPolicyErrors)
            # Alle vier Parameter schreibt die Delegatensignatur von
            # RemoteCertificateValidationCallback vor, auch die zwei, die diese
            # Entscheidung nicht braucht: der Pin schaut auf das Zertifikat selbst,
            # nicht auf die Kette (die bei selbstsigniert ohnehin fehlschlaegt).
            $null = $senderObject, $chain
            if ($sslPolicyErrors -eq [System.Net.Security.SslPolicyErrors]::None) { return $true }
            if (-not $certificate) { return $false }
            # GetCertHashString() ist der SHA-1-Fingerabdruck ohne Trennzeichen,
            # dieselbe Form, die die MECM-Konsole und certlm.msc anzeigen. Wirft es
            # (kaputtes Handle, kein Zertifikat), ist die Antwort NEIN: ein
            # Fingerabdruck, den wir nicht lesen koennen, ist keiner, der passt.
            try {
                $actual = $certificate.GetCertHashString()
            } catch {
                Write-Debug $_
                return $false
            }
            return ($actual.ToUpperInvariant() -eq $expected)
        }.GetNewClosure()
    } catch { Write-Debug $_ }
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
    $body = $null
    try {
        if ($ErrorRecord.ErrorDetails -and -not [string]::IsNullOrWhiteSpace([string]$ErrorRecord.ErrorDetails.Message)) {
            $body = [string]$ErrorRecord.ErrorDetails.Message
        }
    } catch { Write-Debug $_ }
    if (-not $response -and [string]::IsNullOrWhiteSpace($body)) { return $detail }
    try {
        # PS 5.1 / .NET Framework: HttpWebResponse mit Stream. Kein Vorab-Check auf
        # die Methode: sie kann als ScriptMethod, Methode oder gar nicht da sein,
        # und der try/catch faengt jeden dieser Faelle ohnehin ab.
        $stream = if ($response -and [string]::IsNullOrWhiteSpace($body)) { $response.GetResponseStream() } else { $null }
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
                $message = Get-VsTruncatedUtf8 -Text ([string]$parsed.$field) -MaxBytes 512
                return ('{0} | WebApp: {1}' -f $detail, $message)
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
        # -InputObject statt Pipeline: Windows PowerShell 5.1 packt eine
        # einelementige Liste auf dem Weg durch die Pipeline aus und serialisiert
        # sie als Objekt statt als Array. Der Packages-Sync ist der einzige
        # Aufrufer, der eine Liste sendet; bei genau einem Katalogeintrag
        # (frische Site) antwortet mecm_packages.php dann dauerhaft 400, weil es
        # ueber die Werte des Objekts iteriert und Strings statt Eintraege
        # findet. Dieselbe 5.1-Eigenart ist in mecm_new-device-sync.ps1 fuer die
        # Empfangsrichtung dokumentiert; hier ist die Senderichtung.
        $params['Body'] = ConvertTo-VsUtf8JsonBytes -Value $Body -Depth 6
        $params['ContentType'] = 'application/json; charset=utf-8'
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
    'mac_conflict',              # Rolloutname mit fremder MAC ODER MAC mit fremdem Namen
    'device_import_failed',      # Import-CMComputerInformation fehlgeschlagen
    # --- Identitaet des Rolloutnamens (Etappe 14D, ADR-0043) ---------------
    #
    # Bewusst KEIN `device_name_conflict`: ein Name mit fremder MAC und eine MAC
    # mit fremdem Namen sind dieselbe Frage aus zwei Richtungen, und zwei Codes
    # dafuer haetten den Operator zwei verschiedene Zeilen suchen lassen. Der
    # eine bestehende `mac_conflict` traegt beide Richtungen weiter.
    'device_name_invalid',       # Rolloutname fehlt oder ist kein NetBIOS-Name: kein Import moeglich
    'device_identity_ambiguous', # mehrere MECM-Datensaetze passen auf Name/MAC/ResourceID
    'previous_resource_present', # Tombstone: das alte MECM-Geraet ist noch da und muss von Hand geloescht werden
    'resource_id_missing',       # gebundene ResourceID existiert in MECM nicht mehr
    'resource_mac_conflict',     # gebundene ResourceID traegt eine andere MAC als das Portal
    'stale_rollout_revision',    # Portal wies die Rueckmeldung als veraltete Rolloutrevision ab (409)
    'collection_missing',        # Zielcollection existiert nicht
    'collection_assign_failed',  # Add-CMDeviceCollectionDirectMembershipRule fehlgeschlagen
    'collection_update_failed',  # Invoke-CMCollectionUpdate fehlgeschlagen
    'collection_folder_failed',  # Ordner/Verschieben fehlgeschlagen
    'collection_remove_failed',  # Remove der EIGENEN Regel fehlgeschlagen (ADR-0034)
    'membership_provenance_invalid', # Owned-Regel ohne vollstaendige ID/Name/Typ-Provenienz
    'membership_identity_ambiguous', # Collectionname/Desired-Typ nicht eindeutig
    'membership_query_failed',  # Live-Membership konnte nicht sicher gelesen werden
    'membership_operation_uncertain', # Journal-Intent ohne sicher quittiertes Remote-Ergebnis
    'membership_report_pending', # Bestaetigtes Remote-Ergebnis wurde replayt; Portalbestand neu lesen
    'membership_report_failed',  # Provenienz-Meldung ans Portal fehlgeschlagen (ADR-0034)
    'resource_id_pending',       # MECM hat noch keine ResourceID vergeben
    'resource_update_failed',    # ResourceID-Rueckmeldung ans Portal fehlgeschlagen
    'package_config_invalid',    # config.json unlesbar oder Pflichtfeld fehlt
    'package_content_failed',    # Content-Verteilung fehlgeschlagen oder auf DPs mit Fehlern
    'package_content_in_progress', # Verteilung laeuft noch; Stamp wartet (B7)
    'package_content_unknown',   # Verteilstatus nicht abfragbar; Stamp wartet (B7)
    'package_definition_drift',  # Application/Deployment-Type nicht eindeutig oder vertragswidrig
    'package_source_missing',    # files-Pfad des Paketordners fehlt
    'package_deploy_failed',     # New-CMApplicationDeployment fehlgeschlagen
    'package_cleanup_failed',    # Alt-Version nicht vollstaendig entfernt
    'package_template_failed'    # Vorlagen-install.ps1 nicht ueberschrieben
)

function New-VsRunCauseList {
    return New-Object System.Collections.Generic.List[string]
}

# Verteilzustand des Application-Contents (B7, ADR-0034):
# not_started | in_progress | succeeded | failed | unknown.
#
# Die boolesche Vorgaengerfrage ("ist ueberhaupt verteilt?") las Targeted > 0
# als erledigt, und NumberErrors las niemand: eine Verteilung, die auf jedem DP
# scheiterte, galt als fertig, der Autoimporter merkte den Stamp, die Karte
# blieb gruen, und jede Client-Installation schlug fehl. Mehrwertig entscheidet
# der Aufrufer selbst; nur `succeeded` heisst "nichts zu tun UND der Stamp darf
# gemerkt werden".
#
# Adressierung ausschliesslich ueber das eindeutig aufgeloeste Application-
# Objekt (-InputObject). Get-CMDistributionStatus -Id erwartet eine PackageID,
# keine CI_ID; -Name kann bei Mehrdeutigkeit das falsche Objekt treffen.
# `unknown` bei jeder Schema-/Identitaetsunsicherheit.
#
# Bewusst weiterhin die Summe ueber alle Verteilungspunkte, nicht "liegt er auf
# genau dieser DP-Gruppe": Get-CMDistributionStatus kennt die Gruppe nicht, und
# fuer die Installation, die genau eine DP-Gruppe anlegt, ist beides dasselbe
# (benannte Grenze, unveraendert).
function Get-VsContentDistributionSnapshot {
    param(
        [Parameter(Mandatory)][string]$ApplicationName,
        $Application = $null,
        [AllowNull()]$ExpectedSourceVersion = $null
    )
    try {
        if (-not $Application) {
            $applicationMatches = @(Get-CMApplication -Name $ApplicationName -Fast -ErrorAction Stop)
            if ($applicationMatches.Count -ne 1) { return [pscustomobject]@{ State = 'unknown'; SourceVersion = $null } }
            $Application = $applicationMatches[0]
        }
        $appName = if ($Application.PSObject.Properties['LocalizedDisplayName']) { [string]$Application.LocalizedDisplayName } else { '' }
        $ciId = if ($Application.PSObject.Properties['CI_ID']) { $Application.CI_ID } else { $null }
        $packageId = if ($Application.PSObject.Properties['PackageID']) { [string]$Application.PackageID } else { '' }
        if ($appName -ne $ApplicationName -or $null -eq $ciId -or [string]::IsNullOrWhiteSpace($packageId)) { return [pscustomobject]@{ State = 'unknown'; SourceVersion = $null } }
        $status = @(Get-CMDistributionStatus -InputObject $Application -ErrorAction Stop)
    } catch {
        Write-Debug $_
        return [pscustomobject]@{ State = 'unknown'; SourceVersion = $null }
    }
    if (@($status).Count -eq 0) { return [pscustomobject]@{ State = 'not_started'; SourceVersion = $null } }

    $targeted = 0
    $success = 0
    $distErrors = 0
    $inProgress = 0
    $unknown = 0
    $sourceVersions = @{}
    foreach ($entry in $status) {
        foreach ($field in @('Targeted', 'NumberSuccess', 'NumberErrors', 'NumberInProgress', 'NumberUnknown', 'SourceVersion')) {
            if (-not $entry.PSObject.Properties[$field]) { return [pscustomobject]@{ State = 'unknown'; SourceVersion = $null } }
        }
        $values = @{}
        foreach ($field in @('Targeted', 'NumberSuccess', 'NumberErrors', 'NumberInProgress', 'NumberUnknown', 'SourceVersion')) {
            $parsed = 0
            if (-not [int]::TryParse([string]$entry.$field, [ref]$parsed) -or $parsed -lt 0) { return [pscustomobject]@{ State = 'unknown'; SourceVersion = $null } }
            $values[$field] = $parsed
        }
        $sourceVersions[[int]$values.SourceVersion] = $true
        if ($null -ne $ExpectedSourceVersion) {
            $expected = 0
            if (-not [int]::TryParse([string]$ExpectedSourceVersion, [ref]$expected) -or $expected -lt 0) { return [pscustomobject]@{ State = 'unknown'; SourceVersion = $null } }
        }
        $targeted += $values.Targeted
        $success += $values.NumberSuccess
        $distErrors += $values.NumberErrors
        $inProgress += $values.NumberInProgress
        $unknown += $values.NumberUnknown
    }
    $uniformSourceVersion = if ($sourceVersions.Count -eq 1) { [int]@($sourceVersions.Keys)[0] } else { $null }
    if ($null -ne $ExpectedSourceVersion -and ($null -eq $uniformSourceVersion -or $uniformSourceVersion -ne $expected)) {
        return [pscustomobject]@{ State = 'in_progress'; SourceVersion = $uniformSourceVersion }
    }
    if ($distErrors -gt 0) { return [pscustomobject]@{ State = 'failed'; SourceVersion = $uniformSourceVersion } }
    if ($sourceVersions.Count -gt 1) { return [pscustomobject]@{ State = 'in_progress'; SourceVersion = $null } }
    if ($targeted -le 0) { return [pscustomobject]@{ State = 'not_started'; SourceVersion = $uniformSourceVersion } }
    $classified = $success + $distErrors + $inProgress + $unknown
    if ($classified -gt $targeted) { return [pscustomobject]@{ State = 'unknown'; SourceVersion = $uniformSourceVersion } }
    if ($success -eq $targeted -and $inProgress -eq 0 -and $unknown -eq 0) { return [pscustomobject]@{ State = 'succeeded'; SourceVersion = $uniformSourceVersion } }
    return [pscustomobject]@{ State = 'in_progress'; SourceVersion = $uniformSourceVersion }
}

function Get-VsContentDistributionState {
    param(
        [Parameter(Mandatory)][string]$ApplicationName,
        $Application = $null,
        [AllowNull()]$ExpectedSourceVersion = $null
    )
    return (Get-VsContentDistributionSnapshot -ApplicationName $ApplicationName -Application $Application -ExpectedSourceVersion $ExpectedSourceVersion).State
}

# Stable provider identities for the Application package and its one managed
# Deployment Type. Application package/source versions are not a content
# revision for Applications: MECM keeps the package version at 1 and gives
# an updated Deployment Type a new ContentId. Callers therefore bind the
# distribution observation to these provider-owned identifiers instead of
# inferring completion from SourceVersion growth.
function Get-VsApplicationContentIdentity {
    param(
        [Parameter(Mandatory)]$Application,
        [Parameter(Mandatory)][string]$ExpectedName
    )
    foreach ($field in @('LocalizedDisplayName', 'ModelName', 'PackageID')) {
        if (-not $Application.PSObject.Properties[$field]) {
            return [pscustomobject]@{ State = 'unknown'; ModelName = ''; PackageId = '' }
        }
    }
    $name = [string]$Application.LocalizedDisplayName
    $modelName = [string]$Application.ModelName
    $packageId = [string]$Application.PackageID
    if ($name -ne $ExpectedName -or [string]::IsNullOrWhiteSpace($modelName) -or [string]::IsNullOrWhiteSpace($packageId)) {
        return [pscustomobject]@{ State = 'unknown'; ModelName = ''; PackageId = '' }
    }
    return [pscustomobject]@{ State = 'known'; ModelName = $modelName; PackageId = $packageId }
}

function Get-VsDeploymentTypeContentIdentity {
    param(
        [Parameter(Mandatory)]$DeploymentType,
        [Parameter(Mandatory)][string]$ExpectedName,
        [Parameter(Mandatory)][string]$ExpectedApplicationModelName
    )
    foreach ($field in @('AppModelName', 'ModelName', 'CI_UniqueID', 'ContentId')) {
        if (-not $DeploymentType.PSObject.Properties[$field]) {
            return [pscustomobject]@{ State = 'unknown'; DeploymentTypeModelName = ''; DeploymentTypeId = ''; ContentId = '' }
        }
    }
    $names = New-Object System.Collections.Generic.List[string]
    foreach ($field in @('LocalizedDisplayName', 'DeploymentTypeName')) {
        if ($DeploymentType.PSObject.Properties[$field] -and -not [string]::IsNullOrWhiteSpace([string]$DeploymentType.$field)) {
            [void]$names.Add([string]$DeploymentType.$field)
        }
    }
    $appModelName = [string]$DeploymentType.AppModelName
    $deploymentTypeModelName = [string]$DeploymentType.ModelName
    $deploymentTypeId = [string]$DeploymentType.CI_UniqueID
    $contentId = [string]$DeploymentType.ContentId
    if ($names.Count -eq 0 -or $ExpectedName -notin @($names) -or $appModelName -cne $ExpectedApplicationModelName -or
        [string]::IsNullOrWhiteSpace($deploymentTypeModelName) -or [string]::IsNullOrWhiteSpace($deploymentTypeId) -or
        [string]::IsNullOrWhiteSpace($contentId)) {
        return [pscustomobject]@{ State = 'unknown'; DeploymentTypeModelName = ''; DeploymentTypeId = ''; ContentId = '' }
    }
    return [pscustomobject]@{
        State = 'known'; DeploymentTypeModelName = $deploymentTypeModelName
        DeploymentTypeId = $deploymentTypeId; ContentId = $contentId
    }
}

function ConvertTo-VsProviderUtcTicks {
    param([AllowNull()]$Value)
    if ($null -eq $Value -or [string]::IsNullOrWhiteSpace([string]$Value)) { return 0L }
    try {
        $date = if ($Value -is [datetime]) { [datetime]$Value } else {
            [System.Management.ManagementDateTimeConverter]::ToDateTime([string]$Value)
        }
        return $date.ToUniversalTime().Ticks
    } catch {
        Write-Debug $_
        return -1L
    }
}

# Per-DP successful-copy evidence. Unlike SMS_ObjectContentExtraInfo's package
# aggregate, LastCopied identifies when the source files were last successfully
# copied to each concrete DP. A content request stores this whole baseline and
# completion requires every same target to report INSTALLED with a strictly
# newer LastCopied value.
function Get-VsDistributionCopySnapshot {
    param(
        [Parameter(Mandatory)][ValidatePattern('^[A-Za-z0-9]{8}$')][string]$PackageId,
        [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string]$ApplicationModelName,
        [Parameter(Mandatory)][ValidatePattern('^[A-Za-z0-9]{3}$')][string]$SiteCode,
        [string]$ProviderMachine = ''
    )
    try {
        $cimParams = @{
            Namespace = ('root\sms\site_{0}' -f $SiteCode)
            ClassName = 'SMS_PackageStatusDistPointsSummarizer'
            Filter = ("PackageID = '{0}'" -f $PackageId)
            ErrorAction = 'Stop'
        }
        if (-not [string]::IsNullOrWhiteSpace($ProviderMachine)) { $cimParams['ComputerName'] = $ProviderMachine.Trim() }
        $rows = @(Get-CimInstance @cimParams)
    } catch {
        Write-Debug $_
        return [pscustomobject]@{ State = 'unknown'; Targets = @() }
    }
    $targets = New-Object System.Collections.Generic.List[object]
    $seen = New-Object 'System.Collections.Generic.Dictionary[string,object]' ([StringComparer]::Ordinal)
    foreach ($row in $rows) {
        foreach ($field in @('PackageID', 'SecureObjectID', 'ServerNALPath', 'SiteCode', 'State', 'LastCopied')) {
            if (-not $row.PSObject.Properties[$field]) { return [pscustomobject]@{ State = 'unknown'; Targets = @() } }
        }
        $nalPath = [string]$row.ServerNALPath
        $rowSiteCode = [string]$row.SiteCode
        $state = -1
        $lastCopiedTicks = ConvertTo-VsProviderUtcTicks -Value $row.LastCopied
        $targetKey = $rowSiteCode + "`n" + $nalPath
        if ([string]$row.PackageID -cne $PackageId -or [string]$row.SecureObjectID -cne $ApplicationModelName -or
            $rowSiteCode -cne $SiteCode -or [string]::IsNullOrWhiteSpace($nalPath) -or $seen.ContainsKey($targetKey) -or
            -not [int]::TryParse([string]$row.State, [ref]$state) -or $state -lt 0 -or $state -gt 8 -or $lastCopiedTicks -lt 0) {
            return [pscustomobject]@{ State = 'unknown'; Targets = @() }
        }
        $seen[$targetKey] = $row
        [void]$targets.Add([pscustomobject]@{ SiteCode = $rowSiteCode; ServerNalPath = $nalPath; State = $state; LastCopiedTicks = $lastCopiedTicks })
    }
    return [pscustomobject]@{ State = 'known'; Targets = @($targets | Sort-Object SiteCode, ServerNalPath) }
}

function ConvertTo-VsDistributionBaselineJson {
    param([Parameter(Mandatory)]$Snapshot)
    if ([string]$Snapshot.State -ne 'known') { throw 'Verteilkopie-Baseline ist nicht sicher lesbar.' }
    $baseline = @($Snapshot.Targets | Sort-Object SiteCode, ServerNalPath | ForEach-Object {
        [ordered]@{ site_code = [string]$_.SiteCode; server_nal_path = [string]$_.ServerNalPath; last_copied_ticks = [long]$_.LastCopiedTicks }
    })
    $json = ConvertTo-Json -InputObject @($baseline) -Compress
    if ([Text.Encoding]::UTF8.GetByteCount($json) -gt 32768) { throw 'Verteilkopie-Baseline ist zu gross.' }
    return $json
}

function Test-VsDistributionCopyBaselineReady {
    param(
        [Parameter(Mandatory)][ValidateSet('initial', 'update')][string]$RequestKind,
        [Parameter(Mandatory)]$Snapshot
    )
    if ([string]$Snapshot.State -ne 'known') { return $false }
    $targets = @($Snapshot.Targets)
    if ($RequestKind -eq 'initial') { return $targets.Count -eq 0 }
    if ($targets.Count -eq 0) { return $false }
    return @($targets | Where-Object { [int]$_.State -ne 0 -or [long]$_.LastCopiedTicks -le 0 }).Count -eq 0
}

function Test-VsDistributionCopyAdvanced {
    param(
        [Parameter(Mandatory)][string]$BaselineJson,
        [Parameter(Mandatory)]$CurrentSnapshot
    )
    if ([string]$CurrentSnapshot.State -ne 'known') { return $false }
    if ($BaselineJson -notmatch '^\s*\[.*\]\s*$') { return $false }
    $isEmptyBaseline = $BaselineJson -match '^\s*\[\s*\]\s*$'
    try {
        # Windows PowerShell 5.1 emits a JSON root array as one array object.
        # Capturing that pipeline directly in @() nests it once, so a one-DP
        # baseline looks like one item whose value is itself an array. Assign
        # first, then materialize the parsed targets for the common PS5.1/PS7
        # shape consumed below.
        $parsedBaseline = $BaselineJson | ConvertFrom-Json -ErrorAction Stop
    } catch { return $false }
    if ($isEmptyBaseline) {
        $baseline = @()
    } else {
        # `null`, `[null]` and nested empty arrays can all collapse to no
        # pipeline value depending on the PowerShell engine. None of them is
        # the explicit empty baseline written by our serializer.
        if ($null -eq $parsedBaseline) { return $false }
        $baseline = @($parsedBaseline)
        if ($baseline.Count -eq 0) { return $false }
    }
    $current = @($CurrentSnapshot.Targets)
    # Erstverteilung: Vorher existiert noch kein DP-Ziel. Danach muss mindestens
    # ein wirklich installierter, erfolgreich kopierter Zielstand existieren.
    if ($baseline.Count -eq 0) {
        return $current.Count -gt 0 -and @($current | Where-Object { [int]$_.State -ne 0 -or [long]$_.LastCopiedTicks -le 0 }).Count -eq 0
    }
    if ($current.Count -ne $baseline.Count) { return $false }
    $currentByPath = New-Object 'System.Collections.Generic.Dictionary[string,object]' ([StringComparer]::Ordinal)
    foreach ($target in $current) {
        $path = [string]$target.ServerNalPath
        $site = [string]$target.SiteCode
        $key = $site + "`n" + $path
        if ([string]::IsNullOrWhiteSpace($site) -or [string]::IsNullOrWhiteSpace($path) -or $currentByPath.ContainsKey($key)) { return $false }
        $currentByPath[$key] = $target
    }
    $baselineKeys = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
    foreach ($before in $baseline) {
        if ($null -eq $before) { return $false }
        if (-not $before.PSObject.Properties['site_code'] -or -not $before.PSObject.Properties['server_nal_path'] -or
            -not $before.PSObject.Properties['last_copied_ticks']) { return $false }
        $site = [string]$before.site_code
        $path = [string]$before.server_nal_path
        $ticks = 0L
        $key = $site + "`n" + $path
        if ([string]::IsNullOrWhiteSpace($site) -or [string]::IsNullOrWhiteSpace($path) -or
            -not [long]::TryParse([string]$before.last_copied_ticks, [ref]$ticks) -or $ticks -lt 0 -or
            -not $baselineKeys.Add($key) -or -not $currentByPath.ContainsKey($key)) { return $false }
        $after = $currentByPath[$key]
        if ([int]$after.State -ne 0 -or [long]$after.LastCopiedTicks -le $ticks) { return $false }
    }
    return $true
}

# Liegt diese Collection schon im VirtuSphere-Ordner?
#
# Auch hier gilt Unsicherheit als "ja": ein wiederholtes Move-CMObject auf ein
# Objekt, das schon dort liegt, ist harmlos, aber eine Fehlmeldung pro Durchlauf
# ist es nicht. Der ObjectPath einer Collection traegt den Ordnerpfad OHNE
# Site-Drive-Praefix, Move-CMObject erwartet ihn MIT - deshalb wird nur der
# Ordnername verglichen.
function Test-VsInOrgFolder {
    param(
        [Parameter(Mandatory)]$Collection,
        [Parameter(Mandatory)][string]$FolderPath
    )
    try {
        $current = [string]$Collection.ObjectPath
    } catch {
        Write-Debug $_
        return $true
    }
    if ([string]::IsNullOrWhiteSpace($current)) { return $false }

    $leaf = ($FolderPath -split '\\')[-1]
    return ($current -like ('*' + $leaf))
}

# Traegt der Paketordner schon das Vorlagen-install.ps1?
#
# Das Self-Healing lief nur im $isNew-Zweig: schlug das Kopieren dort fehl (Datei
# gesperrt, Share kurz weg), existierte die Application danach, der naechste
# Durchlauf war also nicht mehr "neu", und die paketeigene install.ps1 blieb fuer
# immer stehen - unter einer gruenen Karte, obwohl die Vorlage laut Vertrag
# gewinnt. Verglichen wird der Inhalt, nicht das Datum: Copy-Item -Force setzt
# die Zeitstempel, ein Hash-Vergleich beantwortet dagegen genau die Frage, ob
# noch kopiert werden muss.
#
# Anders als bei den beiden Helfern oben gilt Unsicherheit hier als "nein":
# ein erneutes Kopieren ist idempotent, und ein unlesbarer Hash ist selbst ein
# offener Punkt, der gemeldet werden soll.
function Test-VsTemplateScriptCurrent {
    param(
        [Parameter(Mandatory)][string]$TemplateFile,
        [Parameter(Mandatory)][string]$PackageFile
    )
    # Eine vorhandene paketeigene install.ps1 ist auch ohne Vorlage ein
    # ausfuehrbares Bestandspaket. Fehlen beide Dateien, ist das Paket dagegen
    # unvollstaendig und darf nicht als aktuell in die MECM-Definition gelangen.
    if (-not (Test-Path -LiteralPath $TemplateFile -PathType Leaf)) {
        if (-not (Test-Path -LiteralPath $PackageFile -PathType Leaf)) { return $false }
        try {
            [void](Get-FileHash -LiteralPath $PackageFile -Algorithm SHA256 -ErrorAction Stop)
            return $true
        } catch {
            Write-Debug $_
            return $false
        }
    }
    if (-not (Test-Path -LiteralPath $PackageFile -PathType Leaf)) { return $false }   # erwartete generierte Datei fehlt

    try {
        $template = (Get-FileHash -LiteralPath $TemplateFile -Algorithm SHA256 -ErrorAction Stop).Hash
        $package = (Get-FileHash -LiteralPath $PackageFile -Algorithm SHA256 -ErrorAction Stop).Hash
    } catch {
        Write-Debug $_
        return $false
    }
    return ($template -eq $package)
}

function Get-VsFilesManifestStamp {
    param([Parameter(Mandatory)][string]$Path, [string]$TemplateScript = '')
    if (-not (Test-Path -LiteralPath $Path)) { return 'missing' }
    $root = (Get-Item -LiteralPath $Path -ErrorAction Stop).FullName.TrimEnd('\', '/')
    $files = @(Get-ChildItem -LiteralPath $root -Recurse -File -ErrorAction Stop |
        Where-Object { $_.Name -notmatch '^(\.~|~\$)' -and $_.Extension -notin @('.tmp', '.partial') } |
        Sort-Object FullName)
    if ($files.Count -eq 0) { return 'empty' }

    $parts = New-Object System.Collections.Generic.List[string]
    foreach ($file in $files) {
        $relative = $file.FullName.Substring($root.Length).TrimStart('\', '/').Replace('\', '/')
        $beforeLength = $file.Length
        $beforeTicks = $file.LastWriteTimeUtc.Ticks
        $hash = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256 -ErrorAction Stop).Hash
        $after = Get-Item -LiteralPath $file.FullName -ErrorAction Stop
        if ($after.Length -ne $beforeLength -or $after.LastWriteTimeUtc.Ticks -ne $beforeTicks) {
            throw ("Paketdatei hat sich waehrend des Scans geaendert: {0}" -f $relative)
        }
        $parts.Add(('{0}|{1}|{2}' -f $relative, $beforeLength, $hash))
    }
    $afterNames = @(Get-ChildItem -LiteralPath $root -Recurse -File -ErrorAction Stop |
        Where-Object { $_.Name -notmatch '^(\.~|~\$)' -and $_.Extension -notin @('.tmp', '.partial') } |
        Sort-Object FullName | ForEach-Object { $_.FullName })
    if ((@($files.FullName) -join "`n") -cne ($afterNames -join "`n")) { throw 'Paketdateiliste hat sich waehrend des Scans geaendert.' }

    if ($TemplateScript) {
        if (Test-Path -LiteralPath $TemplateScript) {
            $templateHash = (Get-FileHash -LiteralPath $TemplateScript -Algorithm SHA256 -ErrorAction Stop).Hash
            $parts.Add(('template|{0}' -f $templateHash))
        } else { $parts.Add('template|absent') }
    }
    $payload = $parts -join "`n"
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($payload))).Replace('-', '')) } finally { $sha.Dispose() }
}

function Get-VsPackageContentTrackingKey {
    param([Parameter(Mandatory)][string]$ApplicationName)
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        $hash = ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($ApplicationName))).Replace('-', ''))
    } finally { $sha.Dispose() }
    return (Join-Path (Join-Path $script:VsRegistryPath 'ContentTracking') $hash)
}

function Get-VsPackageContentTracking {
    param([Parameter(Mandatory)][string]$ApplicationName)
    $key = Get-VsPackageContentTrackingKey -ApplicationName $ApplicationName
    try {
        if (-not (Test-Path -LiteralPath $key -ErrorAction Stop)) { return $null }
        $raw = Get-ItemProperty -LiteralPath $key -ErrorAction Stop
    } catch {
        return [pscustomobject]@{ State = 'invalid'; Manifest = ''; BaselineSourceVersion = -1; SourceVersion = -1 }
    }
    $state = [string]$raw.State
    $manifest = [string]$raw.Manifest
    $baseline = -1
    $sourceVersion = -1
    if ([string]$raw.ApplicationName -cne $ApplicationName -or $state -notin @('intent', 'pending', 'complete') -or
        $manifest -notmatch '^[A-F0-9]{64}$' -or
        -not [int]::TryParse([string]$raw.BaselineSourceVersion, [ref]$baseline) -or $baseline -lt -1 -or
        -not [int]::TryParse([string]$raw.SourceVersion, [ref]$sourceVersion) -or $sourceVersion -lt -1) {
        return [pscustomobject]@{ State = 'invalid'; Manifest = ''; BaselineSourceVersion = -1; SourceVersion = -1 }
    }
    $schema = 0
    if (-not $raw.PSObject.Properties['Schema'] -or -not [int]::TryParse([string]$raw.Schema, [ref]$schema) -or $schema -ne 2) {
        return [pscustomobject]@{
            State = 'legacy'; LegacyState = $state; Manifest = $manifest
            BaselineSourceVersion = $baseline; SourceVersion = $sourceVersion
        }
    }
    $requestKind = [string]$raw.RequestKind
    $applicationModelName = [string]$raw.ApplicationModelName
    $applicationPackageId = [string]$raw.ApplicationPackageId
    $deploymentTypeId = [string]$raw.DeploymentTypeId
    $deploymentTypeModelName = [string]$raw.DeploymentTypeModelName
    $baselineContentId = [string]$raw.BaselineContentId
    $contentId = [string]$raw.ContentId
    $requestConfirmed = 0
    $distributionBaseline = [string]$raw.DistributionBaseline
    if ($requestKind -notin @('initial', 'update') -or
        [string]::IsNullOrWhiteSpace($applicationModelName) -or [string]::IsNullOrWhiteSpace($applicationPackageId) -or
        [string]::IsNullOrWhiteSpace($deploymentTypeModelName) -or [string]::IsNullOrWhiteSpace($deploymentTypeId) -or
        [string]::IsNullOrWhiteSpace($baselineContentId) -or
        ($state -in @('pending', 'complete') -and [string]::IsNullOrWhiteSpace($contentId)) -or
        -not [int]::TryParse([string]$raw.RequestConfirmed, [ref]$requestConfirmed) -or $requestConfirmed -notin @(0, 1) -or
        ($state -in @('pending', 'complete') -and $requestConfirmed -ne 1) -or
        [string]::IsNullOrWhiteSpace($distributionBaseline) -or [Text.Encoding]::UTF8.GetByteCount($distributionBaseline) -gt 32768) {
        return [pscustomobject]@{ State = 'invalid'; Manifest = ''; BaselineSourceVersion = -1; SourceVersion = -1 }
    }
    return [pscustomobject]@{
        State = $state; Manifest = $manifest; BaselineSourceVersion = $baseline; SourceVersion = $sourceVersion
        RequestKind = $requestKind; ApplicationModelName = $applicationModelName; ApplicationPackageId = $applicationPackageId
        DeploymentTypeModelName = $deploymentTypeModelName; DeploymentTypeId = $deploymentTypeId
        BaselineContentId = $baselineContentId; ContentId = $contentId
        RequestConfirmed = ($requestConfirmed -eq 1); DistributionBaseline = $distributionBaseline
    }
}

function Set-VsPackageContentTracking {
    param(
        [Parameter(Mandatory)][string]$ApplicationName,
        [Parameter(Mandatory)][ValidateSet('intent', 'pending', 'complete')][string]$State,
        [Parameter(Mandatory)][ValidatePattern('^[A-F0-9]{64}$')][string]$Manifest,
        [ValidateRange(-1, [int]::MaxValue)][int]$BaselineSourceVersion = -1,
        [ValidateRange(-1, [int]::MaxValue)][int]$SourceVersion = -1,
        [Parameter(Mandatory)][ValidateSet('initial', 'update')][string]$RequestKind,
        [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string]$ApplicationModelName,
        [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string]$ApplicationPackageId,
        [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string]$DeploymentTypeModelName,
        [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string]$DeploymentTypeId,
        [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string]$BaselineContentId,
        [AllowEmptyString()][string]$ContentId = '',
        [bool]$RequestConfirmed = $false,
        [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string]$DistributionBaseline
    )
    if ($State -in @('pending', 'complete') -and -not $RequestConfirmed) {
        throw ("Content-Tracking-State '{0}' erfordert einen bestaetigten MECM-Aufruf." -f $State)
    }
    $key = Get-VsPackageContentTrackingKey -ApplicationName $ApplicationName
    if (-not (Test-Path -LiteralPath $key)) { New-Item -Path $key -Force -ErrorAction Stop | Out-Null }
    # State ist der Commit-Marker. Ein Abbruch waehrend der Einzelwrites ist
    # unlesbar/unknown und darf nie einen Contentstand als komplett ausgeben.
    New-ItemProperty -LiteralPath $key -Name State -Value 'invalid' -PropertyType String -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -LiteralPath $key -Name ApplicationName -Value $ApplicationName -PropertyType String -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -LiteralPath $key -Name Schema -Value 2 -PropertyType DWord -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -LiteralPath $key -Name Manifest -Value $Manifest -PropertyType String -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -LiteralPath $key -Name BaselineSourceVersion -Value ([string]$BaselineSourceVersion) -PropertyType String -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -LiteralPath $key -Name SourceVersion -Value ([string]$SourceVersion) -PropertyType String -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -LiteralPath $key -Name RequestKind -Value $RequestKind -PropertyType String -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -LiteralPath $key -Name ApplicationModelName -Value $ApplicationModelName -PropertyType String -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -LiteralPath $key -Name ApplicationPackageId -Value $ApplicationPackageId -PropertyType String -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -LiteralPath $key -Name DeploymentTypeModelName -Value $DeploymentTypeModelName -PropertyType String -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -LiteralPath $key -Name DeploymentTypeId -Value $DeploymentTypeId -PropertyType String -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -LiteralPath $key -Name BaselineContentId -Value $BaselineContentId -PropertyType String -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -LiteralPath $key -Name ContentId -Value $ContentId -PropertyType String -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -LiteralPath $key -Name RequestConfirmed -Value ([int]$RequestConfirmed) -PropertyType DWord -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -LiteralPath $key -Name DistributionBaseline -Value $DistributionBaseline -PropertyType String -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -LiteralPath $key -Name State -Value $State -PropertyType String -Force -ErrorAction Stop | Out-Null
}

# ---------------------------------------------------------------------------
# MECM-Mitgliedschafts-Reconciliation (ADR-0034)
# ---------------------------------------------------------------------------
# Pure Planfunktion: desired (Portalwunsch, {name,type}), owned (Provenienz,
# {collection_id,collection_name}), present (aktuelle Direct-Rules) -> Buckets.
# Die IDENTISCHE Abbildung existiert auf der PHP-Seite (lib/mecm_plan.php);
# beide laufen die gemeinsamen Vektoren in
# Docker/WebAPI/tests/fixtures/mecm-plan-vectors.json, damit Portal-Vorschau
# und Sync-Apply nie auseinanderlaufen. Die eine Sicherheitsregel: `remove`
# enthaelt nur Regeln, die owned UND present UND nicht mehr desired sind - eine
# Hand-Regel hat keine Provenienz und ist konstruktionsbedingt unantastbar
# (preserve_manual/foreign werden nie angefasst, adoptiert wird nur im Portal).
# ---------------------------------------------------------------------------
# Identitaet eines MECM-Geraets (Etappe 14D, ADR-0043)
# ---------------------------------------------------------------------------
#
# Normalisierter Vergleichsschluessel eines Windows-Rolloutnamens. Spiegelt
# mecm_hostname_key() auf der PHP-Seite EXAKT: trimmen und ASCII-Case-Fold, nie
# kuerzen, nie reparieren. Bewusst ToLowerInvariant statt ToLower: unter einer
# tuerkischen Locale bildet ToLower() das I nicht auf i ab, und dann waere
# "BACKUP-1" auf diesem Server ein anderer Rechner als auf jedem anderen.
function ConvertTo-VsHostnameKey {
    param([string]$Hostname)
    if ([string]::IsNullOrWhiteSpace($Hostname)) { return '' }
    return $Hostname.Trim().ToLowerInvariant()
}

# Ist der Name als MECM-Geraetename ueberhaupt verwendbar? Spiegelt
# mecm_hostname_is_rollout_valid(): NetBIOS, hoechstens 15 Zeichen, kein Punkt,
# kein fuehrender/abschliessender Bindestrich.
function Test-VsRolloutHostname {
    param([string]$Hostname)
    if ([string]::IsNullOrWhiteSpace($Hostname)) { return $false }
    return ($Hostname.Trim() -cmatch '^[A-Za-z0-9]([A-Za-z0-9-]{0,13}[A-Za-z0-9])?$')
}

# Baut die drei Multimaps eines Scans aus der Get-CMDevice-Vollabfrage.
#
# MULTImaps, nicht Hashtabellen mit einem Wert: der Vorgaenger war
# `$mecmDevices[$d.Name] = $d`, also "last wins". Zwei Datensaetze mit demselben
# Namen (nach einer Windows-Umbenennung, nach einem manuellen Reimport) loeschten
# sich damit still gegenseitig aus, und welcher gewann, entschied die
# Reihenfolge der Providerantwort. Eine Mehrdeutigkeit MUSS ein Fehler sein und
# darf keine Auswahlheuristik werden; dafuer muss sie ueberhaupt erst sichtbar
# bleiben.
#
# Der Name wird normalisiert abgelegt (MECM vergleicht Geraetenamen selbst
# case-insensitiv), die MAC ueber ConvertTo-VsNormalizedMac. Ein Datensatz kann
# mehrere MACs tragen; jede zaehlt.
function New-VsMecmDeviceIndex {
    param($Devices)

    $index = @{
        ByResourceId = @{}
        ByName       = @{}
        ByMac        = @{}
    }
    foreach ($device in @($Devices)) {
        if ($null -eq $device) { continue }

        $resourceId = "$($device.ResourceID)"
        if (-not [string]::IsNullOrWhiteSpace($resourceId)) {
            if (-not $index.ByResourceId.ContainsKey($resourceId)) { $index.ByResourceId[$resourceId] = @() }
            $index.ByResourceId[$resourceId] += $device
        }

        $nameKey = ConvertTo-VsHostnameKey ([string]$device.Name)
        if ($nameKey -ne '') {
            if (-not $index.ByName.ContainsKey($nameKey)) { $index.ByName[$nameKey] = @() }
            $index.ByName[$nameKey] += $device
        }

        # MACAddress ist je nach Abfrage ein Einzelwert oder eine Liste. Beides
        # ueber denselben Pfad, damit ein Datensatz mit zwei NICs nicht nur unter
        # der ersten auffindbar ist.
        foreach ($rawMac in @($device.MACAddress)) {
            $mac = ConvertTo-VsNormalizedMac ([string]$rawMac)
            if (-not $mac) { continue }
            if (-not $index.ByMac.ContainsKey($mac)) { $index.ByMac[$mac] = @() }
            $index.ByMac[$mac] += $device
        }
    }

    return $index
}

# Treffer eines Multimaps als ECHTES Array.
#
# Ohne diese Funktion nicht zu haben: `@($map[$key])` auf einem fehlenden
# Schluessel liefert `$null`, und `@($null)` ist ein Array mit EINEM Element.
# Jede `Count -eq 0`-Pruefung lief damit ins Leere, und ein unbekannter Name
# plus eine unbekannte MAC sahen aus wie zwei Treffer mit derselben (leeren)
# ResourceID: die Aufloesung antwortete `use` mit leerer ResourceID, statt zu
# importieren. Gemessen, nicht vermutet - der Entscheidungstisch zeigte es.
#
# Die Rueckgabe wird bewusst NICHT mit `,` array-verpackt: PowerShell entrollt
# ein leeres Array zu "nichts", und `@(nichts)` beim Aufrufer ist genau das
# leere Array, das gebraucht wird. `,@()` haette stattdessen ein Array MIT einem
# leeren Array geliefert - Count 1 - und damit denselben Fehler eine Ebene
# hoeher wiederholt. Jeder Aufrufer klammert deshalb in `@(...)`.
function Get-VsIndexHits {
    param($Map, [string]$Key)
    if ([string]::IsNullOrWhiteSpace($Key) -or $null -eq $Map -or -not $Map.ContainsKey($Key)) { return @() }
    $hits = @($Map[$Key] | Where-Object { $null -ne $_ })
    return $hits
}

# Entscheidet, WELCHER MECM-Datensatz zu dieser VM gehoert, oder dass keiner
# gehoert und warum. Rein: keine Providerabfrage, kein Schreiben, damit die
# Pester-Suite jeden Zweig ohne MECM fahren kann.
#
# Rueckgabe: @{ Action = 'use'|'import'|'block'; ResourceId = <string>; Cause = <Code> }
#
# Die Reihenfolge ist die Aussage:
#
#  1. Eine gebundene ResourceID ist die Identitaet. Nur sie wird aufgeloest, und
#     ein ABWEICHENDER Anzeigename ist dann KEIN Befund: nach dem Rollout darf
#     Windows/Discovery den Namen des Datensatzes aendern (das ist der von
#     Microsoft vorgesehene Weg), und VirtuSphere bewertet das nicht als Fehler,
#     benennt nicht um und warnt nicht alle zehn Sekunden. Fehlt die ResourceID
#     oder traegt sie eine fremde MAC, wird blockiert: ein aehnlich benannter
#     Ersatzdatensatz wird NIE adoptiert.
#  2. Ohne Bindung blockiert ein noch vorhandenes Vorgaengergeraet (Tombstone).
#     Ist es in MECM wirklich weg, ist der Tombstone veraltet und der Weg frei;
#     das Portal raeumt ihn beim naechsten erfolgreichen Binden ab.
#  3. Im ERSTEN Rollout darf genau ein Objekt uebernommen werden, und nur wenn
#     Name UND MAC gemeinsam eindeutig auf denselben Datensatz zeigen. Das ist
#     der Import-/Cache-Race: der vorige Scan hat importiert, die Rueckmeldung
#     ging verloren. Alles andere bleibt in der Warteschlange.
function Resolve-VsDeviceIdentity {
    param(
        [Parameter(Mandatory)]$Index,
        [string]$RolloutHostname,
        [string]$Mac,
        [string]$BoundResourceId = '',
        [string]$PreviousResourceId = ''
    )

    $block = { param([string]$cause) return @{ Action = 'block'; ResourceId = ''; Cause = $cause } }

    if (-not (Test-VsRolloutHostname $RolloutHostname)) { return (& $block 'device_name_invalid') }
    if ([string]::IsNullOrWhiteSpace($Mac)) { return (& $block 'mac_missing') }

    if (-not [string]::IsNullOrWhiteSpace($BoundResourceId)) {
        $hits = @(Get-VsIndexHits -Map $Index.ByResourceId -Key $BoundResourceId)
        if ($hits.Count -eq 0) { return (& $block 'resource_id_missing') }
        if ($hits.Count -gt 1) { return (& $block 'device_identity_ambiguous') }

        $found = $false
        foreach ($rawMac in @($hits[0].MACAddress)) {
            if ((ConvertTo-VsNormalizedMac ([string]$rawMac)) -eq $Mac) { $found = $true; break }
        }
        if (-not $found) { return (& $block 'resource_mac_conflict') }

        return @{ Action = 'use'; ResourceId = "$($hits[0].ResourceID)"; Cause = '' }
    }

    if (-not [string]::IsNullOrWhiteSpace($PreviousResourceId) -and $Index.ByResourceId.ContainsKey($PreviousResourceId)) {
        return (& $block 'previous_resource_present')
    }

    $nameHits = @(Get-VsIndexHits -Map $Index.ByName -Key (ConvertTo-VsHostnameKey $RolloutHostname))
    $macHits = @(Get-VsIndexHits -Map $Index.ByMac -Key $Mac)

    if ($nameHits.Count -gt 1 -or $macHits.Count -gt 1) { return (& $block 'device_identity_ambiguous') }
    if ($nameHits.Count -eq 0 -and $macHits.Count -eq 0) { return @{ Action = 'import'; ResourceId = ''; Cause = '' } }
    if ($nameHits.Count -eq 1 -and $macHits.Count -eq 1 -and "$($nameHits[0].ResourceID)" -eq "$($macHits[0].ResourceID)") {
        return @{ Action = 'use'; ResourceId = "$($nameHits[0].ResourceID)"; Cause = '' }
    }

    # Name mit fremder MAC, MAC mit fremdem Namen, oder beide auf verschiedene
    # Datensaetze. Ein Code fuer alle drei: es ist dieselbe Frage aus drei
    # Richtungen, und ein zweiter Code haette den Operator zwei Zeilen suchen
    # lassen, die dieselbe Handarbeit verlangen.
    return (& $block 'mac_conflict')
}

function Get-VsMembershipPlan {
    param(
        [array]$Desired = @(),
        [array]$Owned = @(),
        [array]$Present = @()
    )

    $ownedById = [System.Collections.Generic.Dictionary[string,object]]::new([System.StringComparer]::Ordinal)
    foreach ($rule in @($Owned)) { if ($null -ne $rule) { $ownedById[[string]$rule.collection_id] = $rule } }
    $desiredByName = [System.Collections.Generic.Dictionary[string,object]]::new([System.StringComparer]::Ordinal)
    foreach ($target in @($Desired)) { if ($null -ne $target) { $desiredByName[[string]$target.name] = $target } }

    $plan = @{ add = @(); preserve = @(); preserve_manual = @(); remove = @(); stale_owned = @(); foreign = @() }
    $presentNames = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::Ordinal)
    $presentIds = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::Ordinal)
    foreach ($rule in @($Present)) {
        if ($null -eq $rule) { continue }
        $id = [string]$rule.collection_id
        $name = [string]$rule.collection_name
        [void]$presentIds.Add($id)
        [void]$presentNames.Add($name)
        if ($desiredByName.ContainsKey($name)) {
            if ($ownedById.ContainsKey($id)) { $plan.preserve += , $rule } else { $plan.preserve_manual += , $rule }
        } elseif ($ownedById.ContainsKey($id)) {
            # A remove is reported back with the authoritative provenance type
            # and name. The observed rule only proves presence and deliberately
            # carries no ownership metadata.
            $plan.remove += , $ownedById[$id]
        } else {
            $plan.foreign += , $rule
        }
    }
    $desiredNames = [string[]]@($desiredByName.Keys)
    [Array]::Sort($desiredNames, [System.StringComparer]::Ordinal)
    foreach ($name in $desiredNames) {
        if (-not $presentNames.Contains($name)) { $plan.add += , $desiredByName[$name] }
    }
    # Owned, aber nicht mehr vorhanden: jemand hat unsere Regel direkt in MECM
    # entfernt. Die Provenienz ist verfallen und wird zurueckgemeldet, nie
    # zurueckgekaempft - MECM bleibt die Wahrheit (Entscheidung 1).
    foreach ($id in @($ownedById.Keys)) {
        if (-not $presentIds.Contains([string]$id)) { $plan.stale_owned += , $ownedById[$id] }
    }

    return $plan
}

function Test-VsMembershipPlanOperations {
    param([Parameter(Mandatory)]$Plan)
    $validTypes = @('os', 'package', 'mission')
    foreach ($target in @($Plan.add)) {
        if ($null -eq $target -or [string]::IsNullOrWhiteSpace([string]$target.name) -or [string]$target.type -notin $validTypes) {
            return $false
        }
    }
    foreach ($rule in @($Plan.remove)) {
        if ($null -eq $rule -or [string]::IsNullOrWhiteSpace([string]$rule.collection_id) -or
            [string]::IsNullOrWhiteSpace([string]$rule.collection_name) -or [string]$rule.type -notin $validTypes) {
            return $false
        }
    }
    return $true
}

function Test-VsDesiredMembershipTargets {
    param([array]$Desired = @())
    $typesByName = [System.Collections.Generic.Dictionary[string,string]]::new([System.StringComparer]::Ordinal)
    foreach ($target in @($Desired)) {
        $name = [string]$target.name
        $type = [string]$target.type
        if ([string]::IsNullOrWhiteSpace($name) -or $type -notin @('os', 'package', 'mission')) { return $false }
        if ($typesByName.ContainsKey($name) -and $typesByName[$name] -ne $type) { return $false }
        $typesByName[$name] = $type
    }
    return $true
}

function Get-VsDirectMembershipState {
    param(
        [Parameter(Mandatory)][string]$CollectionId,
        [Parameter(Mandatory)]$ResourceId
    )
    try {
        $rules = @(Get-CMDeviceCollectionDirectMembershipRule -CollectionId $CollectionId -ResourceId $ResourceId -ErrorAction Stop)
        if ($rules.Count -eq 0) {
            return [pscustomobject]@{ State = 'absent'; Rule = $null }
        }
        return [pscustomobject]@{ State = 'present'; Rule = $rules[0] }
    } catch {
        Write-Debug $_
        return [pscustomobject]@{ State = 'unknown'; Rule = $null }
    }
}

# Haengt eine Ursache an die Liste eines Laufs. ValidateSet statt eines freien
# Strings, damit ein Tippfehler beim Aufruf sofort auffaellt;
# $script:VsRunCauseVocabulary weiter oben ist die SSoT und wird von der
# Pester-Suite in beide Richtungen dagegen gehalten.
function Add-VsRunCause {
    param(
        [Parameter(Mandatory)]$Causes,
        [Parameter(Mandatory)]
        [ValidateSet('mission_missing', 'mac_missing', 'mac_conflict', 'device_import_failed',
            'device_name_invalid', 'device_identity_ambiguous', 'previous_resource_present',
            'resource_id_missing', 'resource_mac_conflict', 'stale_rollout_revision',
            'collection_missing', 'collection_assign_failed', 'collection_update_failed',
            'collection_folder_failed', 'collection_remove_failed', 'membership_provenance_invalid', 'membership_identity_ambiguous',
            'membership_query_failed', 'membership_operation_uncertain', 'membership_report_pending', 'membership_report_failed',
            'resource_id_pending', 'resource_update_failed',
            'package_config_invalid', 'package_content_failed', 'package_content_in_progress',
            'package_content_unknown', 'package_definition_drift', 'package_source_missing',
            'package_deploy_failed', 'package_cleanup_failed', 'package_template_failed')]
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

# A run can legally outlive the report contract's one-day duration bound.
# Convert and clamp before casting to Int32; casting a larger TotalMilliseconds
# value directly would make the error reporter itself throw on a very long run.
function Get-VsRunDurationMilliseconds {
    param([Parameter(Mandatory)][datetime]$StartedAt, [datetime]$Now = (Get-Date))
    $milliseconds = ($Now - $StartedAt).TotalMilliseconds
    if ($milliseconds -lt 0) { return 0 }
    if ($milliseconds -gt $script:VsRunDurationMsMax) { return [int]$script:VsRunDurationMsMax }
    return [int][Math]::Floor($milliseconds)
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

    # Health is about the configured Site, never the first site namespace or
    # PSDrive that happens to be visible. Missing configuration is unknown.
    $siteCode = if ($Config -and -not [string]::IsNullOrWhiteSpace($Config.SiteCodeFallback)) {
        ([string]$Config.SiteCodeFallback).Trim()
    } else { $null }
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
        $matching = @($all | Where-Object { [string]$_.SiteCode -eq $siteCode })
        if ($matching.Count -ne 1) {
            $result.ErrorCategory = 'query_failed'
            return $result
        }
        $status = $matching[0]
        if (-not $status.PSObject.Properties['Status']) { return $result }

        $raw = 0
        if (-not [int]::TryParse([string]$status.Status, [ref]$raw)) { return $result }
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
    # InstallationBehaviorType: zwei getrennte Entscheidungen.
    #
    # Ein FEHLENDES Feld ist harmlos. Die Blaupause in der README nennt
    # InstallForSystem, das ist der Normalfall, und ein Bestandspaket ohne das
    # Feld darf nicht verschwinden. Es wird deshalb kanonisch gesetzt und
    # zurueckgeschrieben, damit der Autoimporter nur noch einen bekannten Wert
    # sieht; die Vorlage kommt fuer denselben Ordner zum selben Zweig.
    #
    # Ein UNBEKANNTER Wert ist laut. "InstalForSystem" ist keine
    # Meinungsaeusserung, sondern ein Tippfehler, und config.json wird von Hand
    # gepflegt. Ohne diese Pruefung legte MECM die Detection auf HKLM, waehrend
    # das Installationsskript nach HKCU schrieb: die App galt nie als
    # installiert und wurde bei jedem Re-Evaluierungszyklus erneut versucht,
    # ohne dass die Ursache irgendwo stand.
    #
    # -eq vergleicht case-insensitiv, 'installforsystem' wird also akzeptiert
    # und hier auf die kanonische Schreibweise gebracht.
    $behavior = [string]$cfg.InstallationBehaviorType
    if ([string]::IsNullOrWhiteSpace($behavior)) {
        $behavior = $script:VsInstallationBehaviorTypes[0]
    } else {
        $canonical = @($script:VsInstallationBehaviorTypes | Where-Object { $_ -eq $behavior })
        if ($canonical.Count -eq 0) {
            Write-VsLog -Level WARN -Context $context -Message ('InstallationBehaviorType "{0}" ist unbekannt (erlaubt: {1}) - uebersprungen.' -f $behavior, ($script:VsInstallationBehaviorTypes -join ', '))
            return $null
        }
        $behavior = $canonical[0]
    }
    $cfg | Add-Member -NotePropertyName 'InstallationBehaviorType' -NotePropertyValue $behavior -Force

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

# A14b deliberately uses a narrower version language than the portal catalog.
# The portal has to display imported legacy/free-form versions and therefore
# uses PHP version_compare().  Automatic MECM deletion needs a total,
# reviewable order without PHP/PowerShell drift, so it accepts canonical
# unsigned dotted decimals only.  Anything else remains visible and blocks the
# cleanup plan instead of being guessed into an order.
$script:VsManagedPackageApplicationMarker = 'VirtuSphere managed package application contract v1'
$script:VsManagedPackageCollectionMarker = 'VirtuSphere managed package collection contract v1'

function ConvertTo-VsPackageVersionParts {
    param([Parameter(Mandatory)][string]$Version)
    if ($Version -cnotmatch '^(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))*$') { return $null }
    return @($Version.Split('.') | ForEach-Object { [string]$_ })
}

function Compare-VsPackageVersion {
    param(
        [Parameter(Mandatory)][string]$Left,
        [Parameter(Mandatory)][string]$Right
    )
    $leftRaw = ConvertTo-VsPackageVersionParts -Version $Left
    $rightRaw = ConvertTo-VsPackageVersionParts -Version $Right
    if ($null -eq $leftRaw -or $null -eq $rightRaw) {
        throw "Nicht interpretierbare Paketversion: '$Left' / '$Right'."
    }
    $leftParts = @($leftRaw)
    $rightParts = @($rightRaw)
    $count = [Math]::Max($leftParts.Count, $rightParts.Count)
    for ($i = 0; $i -lt $count; $i++) {
        $l = if ($i -lt $leftParts.Count) { $leftParts[$i] } else { '0' }
        $r = if ($i -lt $rightParts.Count) { $rightParts[$i] } else { '0' }
        if ($l.Length -lt $r.Length) { return -1 }
        if ($l.Length -gt $r.Length) { return 1 }
        $cmp = [string]::CompareOrdinal($l, $r)
        if ($cmp -lt 0) { return -1 }
        if ($cmp -gt 0) { return 1 }
    }
    return 0
}

function Get-VsPackageSourceSelections {
    param([Parameter(Mandatory)]$Packages)
    $groups = New-Object 'System.Collections.Generic.Dictionary[string,System.Collections.Generic.List[object]]' ([StringComparer]::Ordinal)
    foreach ($package in @($Packages)) {
        $product = [string]$package.ProjectName
        if (-not $groups.ContainsKey($product)) { $groups[$product] = New-Object 'System.Collections.Generic.List[object]' }
        $groups[$product].Add($package)
    }
    $result = New-Object System.Collections.Generic.List[object]
    foreach ($product in @($groups.Keys | Sort-Object)) {
        # Der Dictionarywert ist eine List[object]. @($list) trifft in Windows
        # PowerShell 5.1 den fehlerhaften PSEnumerableBinder.
        $items = $groups[$product].ToArray()
        $versions = @($items | ForEach-Object { [string]$_.version })
        $unsupported = @($versions | Where-Object { $null -eq (ConvertTo-VsPackageVersionParts -Version $_) })
        $duplicates = @($versions | Group-Object | Where-Object Count -gt 1 | ForEach-Object Name)
        if ($unsupported.Count -gt 0 -or $duplicates.Count -gt 0) {
            $result.Add([pscustomobject]@{
                ProductName = $product; State = 'blocked'; TargetVersion = ''; TargetName = ''
                SourceVersions = $versions; Blockers = @(
                    @($unsupported | ForEach-Object { "unsupported_version:$($_)" }) +
                    @($duplicates | ForEach-Object { "duplicate_version:$($_)" })
                )
            })
            continue
        }
        $target = $versions[0]
        foreach ($candidate in $versions) {
            if ((Compare-VsPackageVersion -Left $candidate -Right $target) -gt 0) { $target = $candidate }
        }
        $result.Add([pscustomobject]@{
            ProductName = $product; State = 'ready'; TargetVersion = $target
            TargetName = ('{0}-{1}' -f $product, $target); SourceVersions = $versions; Blockers = @()
        })
    }
    return $result.ToArray()
}

function Get-VsPackageRetirementPlan {
    param(
        [Parameter(Mandatory)]$Selection,
        [Parameter(Mandatory)]$Applications,
        [Parameter(Mandatory)]$Collections,
        [Parameter(Mandatory)]$Replacement,
        [Parameter(Mandatory)]$References,
        [Parameter(Mandatory)][bool]$ReferenceScanComplete
    )
    $blockers = New-Object System.Collections.Generic.List[string]
    $items = New-Object System.Collections.Generic.List[object]
    if ([string]$Selection.State -ne 'ready') {
        foreach ($reason in @($Selection.Blockers)) { $blockers.Add([string]$reason) }
    }
    if (-not $ReferenceScanComplete) { $blockers.Add('reference_scan_incomplete') }
    if ([string]$Replacement.Name -cne [string]$Selection.TargetName -or
        -not [bool]$Replacement.Owned -or [int]$Replacement.DeploymentTypeCount -ne 1 -or
        [string]$Replacement.ContentState -cne 'complete' -or
        [string]$Replacement.DistributionState -cne 'succeeded' -or
        -not [bool]$Replacement.DeploymentReady) {
        $blockers.Add('replacement_not_ready')
    }
    $sourceVersions = @($Selection.SourceVersions)
    $prefix = ([string]$Selection.ProductName) + '-'
    foreach ($application in @($Applications)) {
        $name = [string]$application.LocalizedDisplayName
        if ([string]::IsNullOrWhiteSpace($name)) { $name = [string]$application.Name }
        if (-not $name.StartsWith($prefix, [StringComparison]::Ordinal)) { continue }
        $version = $name.Substring($prefix.Length)
        if ($null -eq (ConvertTo-VsPackageVersionParts -Version $version)) {
            $blockers.Add(('candidate_version_unsupported:{0}' -f $name)); continue
        }
        if ($sourceVersions -ccontains $version -or (Compare-VsPackageVersion -Left $version -Right ([string]$Selection.TargetVersion)) -ge 0) { continue }
        $id = [string]$application.CI_ID
        if ([string]::IsNullOrWhiteSpace($id) -or [string]$application.LocalizedDescription -cne $script:VsManagedPackageApplicationMarker) {
            $blockers.Add(('application_not_owned:{0}' -f $name)); continue
        }
        if (@($References | Where-Object { [string]$_.TargetType -ceq 'application' -and [string]$_.TargetId -ceq $id }).Count -gt 0) {
            $blockers.Add(('application_referenced:{0}' -f $name)); continue
        }
        $items.Add([pscustomobject]@{ Kind = 'application'; Id = $id; Name = $name; Version = $version })
    }
    foreach ($collection in @($Collections)) {
        $name = [string]$collection.Name
        if (-not $name.StartsWith($prefix, [StringComparison]::Ordinal)) { continue }
        $version = $name.Substring($prefix.Length)
        if ($null -eq (ConvertTo-VsPackageVersionParts -Version $version)) {
            $blockers.Add(('candidate_version_unsupported:{0}' -f $name)); continue
        }
        if ($sourceVersions -ccontains $version -or (Compare-VsPackageVersion -Left $version -Right ([string]$Selection.TargetVersion)) -ge 0) { continue }
        $id = [string]$collection.CollectionID
        if ([string]::IsNullOrWhiteSpace($id) -or [string]$collection.Comment -cne $script:VsManagedPackageCollectionMarker) {
            $blockers.Add(('collection_not_owned:{0}' -f $name)); continue
        }
        if (@($References | Where-Object { [string]$_.TargetType -ceq 'collection' -and [string]$_.TargetId -ceq $id }).Count -gt 0) {
            $blockers.Add(('collection_referenced:{0}' -f $name)); continue
        }
        $items.Add([pscustomobject]@{ Kind = 'collection'; Id = $id; Name = $name; Version = $version })
    }
    $orderedItems = @($items | Sort-Object Kind, Name, Id)
    $orderedBlockers = @($blockers | Sort-Object -Unique)
    $state = if ($orderedBlockers.Count -eq 0) { 'ready' } else { 'blocked' }
    $canonical = [ordered]@{ Schema = 1; ProductName = [string]$Selection.ProductName; TargetName = [string]$Selection.TargetName; State = $state; Items = $orderedItems; Blockers = $orderedBlockers }
    $json = ConvertTo-Json -InputObject $canonical -Depth 6 -Compress
    $sha = [Security.Cryptography.SHA256]::Create()
    try { $hash = ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($json))).Replace('-', '').ToLowerInvariant()) } finally { $sha.Dispose() }
    return [pscustomobject]@{ Schema = 1; ProductName = $canonical.ProductName; TargetName = $canonical.TargetName; State = $state; Items = $orderedItems; Blockers = $orderedBlockers; PlanHash = $hash }
}

function Invoke-VsPackageRetirementPlan {
    param(
        [Parameter(Mandatory)]$ApprovedPlan,
        [Parameter(Mandatory)]$CurrentPlan,
        [Parameter(Mandatory)][scriptblock]$RemoveApplication,
        [Parameter(Mandatory)][scriptblock]$RemoveCollection
    )
    if ([string]$ApprovedPlan.State -cne 'ready' -or [string]$CurrentPlan.State -cne 'ready' -or
        [string]$ApprovedPlan.PlanHash -cne [string]$CurrentPlan.PlanHash) {
        throw 'Bereinigungsplan ist blockiert oder veraltet; es wurde nichts entfernt.'
    }
    $results = New-Object System.Collections.Generic.List[object]
    $total = @($CurrentPlan.Items).Count
    $position = 0
    foreach ($item in @($CurrentPlan.Items)) {
        $position++
        Write-Host ("[{0}/{1}] RUN cleanup {2}:{3}" -f $position, $total, $item.Kind, $item.Id)
        try {
            if ([string]$item.Kind -ceq 'application') { & $RemoveApplication $item }
            elseif ([string]$item.Kind -ceq 'collection') { & $RemoveCollection $item }
            else { throw ("Unbekannter Bereinigungstyp: {0}" -f $item.Kind) }
            $results.Add([pscustomobject]@{ Kind = $item.Kind; Id = $item.Id; Name = $item.Name; State = 'removed' })
            Write-Host ("[{0}/{1}] pass cleanup {2}:{3}" -f $position, $total, $item.Kind, $item.Id)
        } catch {
            $results.Add([pscustomobject]@{ Kind = $item.Kind; Id = $item.Id; Name = $item.Name; State = 'failed'; Error = $_.Exception.Message })
            Write-Host ("[{0}/{1}] fail cleanup {2}:{3}" -f $position, $total, $item.Kind, $item.Id)
            break
        }
    }
    return $results.ToArray()
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
