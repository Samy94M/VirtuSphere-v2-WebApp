#Requires -Version 5.1
# ============================================================================
# VirtuSphere-Client-Common.ps1 - gemeinsame Bausteine der Client-Skripte
# (getinfo/hostname/staticip/disks), die per MECM-Software-Center auf die
# PXE-installierten Windows-Clients verteilt werden.
#
# Dot-Source:  . "$PSScriptRoot\VirtuSphere-Client-Common.ps1"
#
# Adressfindung (Fallback-Kette):
#   1) Registry-Override  HKLM:\SOFTWARE\VirtuSphere\WebAPI
#   2) DNS-Name           $script:VsDefaultDnsApi  (unten anpassen/DNS anlegen)
#   3) hartkodierte IP    $script:VsFallbackIpApi  (letzte Rettung)
# client_getinfo schreibt die funktionierende Adresse in die Registry, sodass
# die Folge-Skripte die Kette nicht erneut durchprobieren muessen.
# ============================================================================

# Set-StrictMode: ein vertippter Variablenname ist sonst ein stilles $null, und
# diese Skripte laufen unbeaufsichtigt auf einer frisch ausgerollten VM.
#
# Bewusst Version 1.0 und nicht Latest: ab 2.0 wirft PowerShell auch beim Zugriff
# auf eine nicht vorhandene Property, und die Client-Skripte lesen JSON-Antworten
# und Registry-Werte, in denen optionale Felder legitim fehlen. Siehe die gleiche
# Begruendung in mecm\VirtuSphere-Common.ps1.
Set-StrictMode -Version 1.0

# --- Standardadressen (beim Ausrollen an die Umgebung anpassen) -------------
$script:VsDefaultDnsApi = 'virtusphere.lan:8021'   # DNS-Alias im Deploy-Netz
$script:VsFallbackIpApi = ''                        # z. B. '10.0.0.5:8021' (optional)

# Schema der WebAPI. 'http' ist der LAN-Default (Projektziel); auf 'https'
# stellen, sobald das Portal auf TLS laeuft. Ueberschreibbar per Registry
# (HKLM:\SOFTWARE\VirtuSphere\Scheme), damit ein Umstieg keine Skriptaenderung
# im Paket braucht.
#
# Warum das ueberhaupt ein Schalter ist: die Maschinen-API ist vom
# HTTP->HTTPS-Redirect ausgenommen, ein reines Einschalten von HTTPS bricht die
# Clients also nicht. Wer aber HTTP *abschaltet*, schaltet die ganze Client-Kette
# mit ab - und das faellt erst beim naechsten PXE-Deploy auf.
$script:VsDefaultScheme = 'http'

# Selbstsignierte Zertifikate sind im LAN der Normalfall, und Windows
# PowerShell 5.1 kennt kein -SkipCertificateCheck. Auf $true setzen, wenn das
# Portal ein selbstsigniertes Zertifikat traegt; dann akzeptiert der Client es.
# Bewusst ein Schalter und kein Default: eine dauerhaft blinde TLS-Pruefung ist
# schlechter als ehrliches HTTP.
$script:VsAllowSelfSignedTls = $false

$script:VsRegistryBase = 'HKLM:\SOFTWARE\VirtuSphere'
$script:VsLogDir = 'C:\Program Files\VirtuSphere\Logs'
$script:VsLogComponent = 'client'
$script:VsResolvedApi = $null

function Initialize-VsClientLog {
    param([Parameter(Mandatory)][string]$Component)
    $script:VsLogComponent = $Component
    if (-not (Test-Path $script:VsLogDir)) {
        New-Item -ItemType Directory -Path $script:VsLogDir -Force | Out-Null
    }
}

function Write-VsClientLog {
    param(
        [Parameter(Mandatory)][string]$Message,
        [ValidateSet('INFO', 'WARN', 'ERROR')][string]$Level = 'INFO'
    )
    $line = '{0} | {1,-5} | {2} | {3}' -f (Get-Date -Format 'o'), $Level, $script:VsLogComponent, $Message
    Write-Output $line
    try {
        $file = Join-Path $script:VsLogDir ('{0}_{1}.log' -f (Get-Date -Format 'yyyyMMdd'), $script:VsLogComponent)
        Add-Content -Path $file -Value $line -Encoding UTF8
        Invoke-VsClientLogRetention
    } catch { Write-Debug $_ }
}

function Invoke-VsClientLogRetention {
    $marker = Join-Path $script:VsLogDir ('.cleanup_{0}' -f $script:VsLogComponent)
    if ((Test-Path $marker) -and ((Get-Date) - (Get-Item $marker).LastWriteTime).TotalDays -lt 1) { return }
    try {
        $cutoff = (Get-Date).AddDays(-30)
        Get-ChildItem -Path $script:VsLogDir -Filter '*.log' -ErrorAction SilentlyContinue |
            Where-Object { $_.LastWriteTime -lt $cutoff } | Remove-Item -Force -ErrorAction SilentlyContinue
        Set-Content -Path $marker -Value (Get-Date -Format 'o') -Encoding UTF8
    } catch { Write-Debug $_ }
}

# --- WebAPI-Adresse aufloesen (Fallback-Kette) ------------------------------
function Get-VsApiCandidates {
    $candidates = New-Object System.Collections.Generic.List[string]
    try {
        $override = (Get-ItemProperty -Path $script:VsRegistryBase -Name 'WebAPI' -ErrorAction Stop).WebAPI
        if (-not [string]::IsNullOrWhiteSpace($override)) { $candidates.Add([string]$override) }
    } catch { Write-Debug $_ }
    if (-not [string]::IsNullOrWhiteSpace($script:VsDefaultDnsApi)) { $candidates.Add($script:VsDefaultDnsApi) }
    if (-not [string]::IsNullOrWhiteSpace($script:VsFallbackIpApi)) { $candidates.Add($script:VsFallbackIpApi) }
    return $candidates
}

# Speichert die funktionierende Adresse fuer nachfolgende Phasen.
function Set-VsResolvedApi {
    param([Parameter(Mandatory)][string]$Api)
    $script:VsResolvedApi = $Api
    try {
        if (-not (Test-Path $script:VsRegistryBase)) { New-Item -Path $script:VsRegistryBase -Force | Out-Null }
        New-ItemProperty -Path $script:VsRegistryBase -Name 'WebAPI' -Value $Api -PropertyType String -Force | Out-Null
    } catch { Write-Debug $_ }
}

# Schema der WebAPI: Registry schlaegt Default. EINZIGE Schema-Stelle der
# Client-Skripte - alle URLs werden ueber Get-VsApiUrl gebaut.
function Get-VsApiScheme {
    try {
        $override = (Get-ItemProperty -Path $script:VsRegistryBase -Name 'Scheme' -ErrorAction Stop).Scheme
        # -in vergleicht case-insensitiv; kanonisch klein zurueckgeben, damit
        # URL-Bau und Schema-Vergleiche eine Schreibweise sehen.
        if ($override -in @('http', 'https')) { return ([string]$override).ToLowerInvariant() }
    } catch { Write-Debug $_ }
    return $script:VsDefaultScheme
}

function Get-VsApiUrl {
    param(
        [Parameter(Mandatory)][string]$Api,     # host:port
        [Parameter(Mandatory)][string]$Path     # z.B. /mecm-api.php?action=...
    )
    return ('{0}://{1}{2}' -f (Get-VsApiScheme), $Api, $Path)
}

# TLS-Vorbereitung fuer PS 5.1: das Framework spricht per Default noch SSL3/TLS1,
# viele Server nicht mehr. Und bei selbstsignierten Zertifikaten muss die
# Validierung explizit ueberbrueckt werden, weil 5.1 kein -SkipCertificateCheck hat.
function Initialize-VsTls {
    if ((Get-VsApiScheme) -ne 'https') { return }

    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    } catch { Write-Debug $_ }

    if ($script:VsAllowSelfSignedTls) {
        try {
            [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
        } catch { Write-Debug $_ }
    }
}

# Ermittelt eine erreichbare API-Adresse (health-Probe). $null wenn keine geht.
#
# Das ist eine ADRESSWAHL, keine Gesundheitspruefung: die Frage ist "antwortet
# unter dieser Adresse die WebApp", nicht "ist ihr gerade wohl". Jede HTTP-Antwort
# beantwortet die erste Frage mit ja, auch eine mit Statuscode 4xx oder 5xx, denn
# einen Statuscode kann nur liefern, wer erreichbar ist.
#
# Das war der Unterschied zwischen einem gedrosselten Portal und einem
# abgeschalteten Client: Invoke-RestMethod wirft unter PS 5.1 bei 5xx, health.php
# antwortete bei "degraded" mit 503, und damit galt das Portal fuer JEDES
# Client-Skript auf JEDER VM als unerreichbar. Ein einzelner haengender
# Bereitstellungsauftrag konnte so die ganze Kette stilllegen. health.php
# antwortet inzwischen 200 fuer "degraded"; diese Seite haelt dieselbe Regel
# unabhaengig davon ein, weil sie fuer jede kuenftige Fehlerantwort gilt.
function Resolve-VsApi {
    if ($script:VsResolvedApi) { return $script:VsResolvedApi }
    Initialize-VsTls
    foreach ($candidate in Get-VsApiCandidates) {
        try {
            Invoke-RestMethod -Uri (Get-VsApiUrl -Api $candidate -Path '/portal/health.php') -TimeoutSec 5 | Out-Null
            Set-VsResolvedApi -Api $candidate
            return $candidate
        } catch {
            $statusCode = Get-VsErrorStatusCode -ErrorRecord $_
            if (Test-VsApiAnswered -ErrorRecord $_) {
                Write-VsClientLog -Level WARN ("Portal unter {0} antwortet mit HTTP {1}; Adresse wird trotzdem benutzt: {2}" -f $candidate, $statusCode, (Get-VsErrorDetail -ErrorRecord $_))
                Set-VsResolvedApi -Api $candidate
                return $candidate
            }
            # Der Grund bleibt per -Debug abrufbar: "alle Kandidaten
            # unerreichbar" ist sonst nicht davon zu unterscheiden, dass das
            # Portal auf HTTPS steht und der Client noch http spricht.
            Write-Debug ('Kandidat {0} nicht erreichbar: {1}' -f $candidate, $_)
        }
    }
    return $null
}

# HTTP-Statuscode einer fehlgeschlagenen Anfrage, oder $null wenn die Anfrage den
# Server nie erreicht hat. Zwilling der gleichnamigen Funktion in
# mecm\VirtuSphere-Common.ps1 (ADR-0029: die beiden Seiten teilen keinen Code,
# weil die Client-Skripte einzeln per MECM-Paket auf die VM kommen).
function Get-VsErrorStatusCode {
    param([Parameter(Mandatory)]$ErrorRecord)
    try {
        $response = $ErrorRecord.Exception.Response
        if ($response -and $response.StatusCode) { return [int]$response.StatusCode }
    } catch { Write-Debug $_ }
    return $null
}

# Ob eine fehlgeschlagene Probe die ADRESSE bestaetigt hat: einen Statuscode kann
# nur liefern, wer erreichbar ist, auch bei 4xx und 5xx. Nur ein Transportfehler
# (DNS, Verbindung abgelehnt, Timeout, TLS-Handshake) hat keinen, und nur der ist
# ein Grund, die naechste Adresse zu probieren. Eigene Funktion, damit genau diese
# Entscheidung pruefbar ist, ohne Invoke-RestMethod zu ersetzen.
function Test-VsApiAnswered {
    param([Parameter(Mandatory)]$ErrorRecord)
    return $null -ne (Get-VsErrorStatusCode -ErrorRecord $ErrorRecord)
}

# Liest den Antwort-Body aus einer fehlgeschlagenen Anfrage (siehe die
# gleichnamige Funktion in mecm\VirtuSphere-Common.ps1): Invoke-RestMethod wirft
# in PS 5.1 bei 4xx/5xx und verwirft dabei den Body, in dem die WebApp ihren
# Grund nennt ({"error":"..."}). Ohne das steht im Client-Log nur "(400) Bad Request".
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
        $stream = $response.GetResponseStream()
        if ($stream) {
            $reader = New-Object System.IO.StreamReader($stream)
            try { $body = $reader.ReadToEnd() } finally { $reader.Dispose() }
        }
    } catch {
        $body = $null
    }
    if ([string]::IsNullOrWhiteSpace($body)) { return $detail }

    try {
        $parsed = $body | ConvertFrom-Json -ErrorAction Stop
        foreach ($field in 'error', 'message') {
            if ($parsed.PSObject.Properties[$field] -and -not [string]::IsNullOrWhiteSpace([string]$parsed.$field)) {
                return ('{0} | WebApp: {1}' -f $detail, [string]$parsed.$field)
            }
        }
    } catch {
        $snippet = ($body -replace '\s+', ' ').Trim()
        if ($snippet.Length -gt 200) { $snippet = $snippet.Substring(0, 200) + '...' }
        if ($snippet) { return ('{0} | Antwort: {1}' -f $detail, $snippet) }
    }

    return $detail
}

# --- Verbindlicher Rueckkanal: Client ist bereit ----------------------------
# Anders als reportPhase ist dieser POST nicht best effort: erst sein Erfolg
# setzt serverseitig 5/5. Get-VsApiUrl haelt HTTP und HTTPS gleichwertig; im
# HTTP-Default werden weder CA noch Zertifikat noch Thumbprint gebraucht.
function Confirm-VsClientReady {
    param(
        [Parameter(Mandatory)][string]$Api,
        [Parameter(Mandatory)][string]$Mac
    )

    $body = @{ mac = $Mac } | ConvertTo-Json
    $response = Invoke-RestMethod -Uri (Get-VsApiUrl -Api $Api -Path '/mecm_client_ack.php') -Method Post `
        -ContentType 'application/json' -Body $body -TimeoutSec 10
    if (-not $response -or -not $response.success) {
        throw 'Client-Ready-ACK wurde von der WebApp nicht bestaetigt.'
    }
}

# --- Rueckkanal: Client-Phase melden (best effort) --------------------------
# Meldet eine Phase. $Mac muss die in der VirtuSphere-DB hinterlegte MAC sein.
# Auth erfolgt serverseitig ueber die bereits bekannte MAC; der Rueckkanal-Token
# gilt nur fuer die Server-Heartbeats, daher sendet der Client keinen Token.
function Send-VsPhase {
    param(
        [Parameter(Mandatory)][string]$Mac,
        [Parameter(Mandatory)][ValidateSet('getinfo', 'hostname', 'staticip', 'disks')][string]$Phase,
        # Nicht -Event: $Event ist eine automatische PowerShell-Variable, ein
        # Parameter dieses Namens ueberdeckt sie. Das Wire-Feld heisst weiter
        # "event" (Contract von mecm_report.php?action=reportPhase).
        [Parameter(Mandatory)][ValidateSet('started', 'finished', 'failed')][string]$PhaseEvent,
        [string]$Detail = ''
    )
    $api = Resolve-VsApi
    if (-not $api) { return }
    try {
        $body = @{ mac = $Mac; phase = $Phase; event = $PhaseEvent }
        if ($Detail) { $body['detail'] = $Detail }
        Invoke-RestMethod -Uri (Get-VsApiUrl -Api $api -Path '/mecm_report.php?action=reportPhase') -Method Post `
            -ContentType 'application/json' -Body ($body | ConvertTo-Json) -TimeoutSec 5 | Out-Null
    } catch {
        # Rueckkanal ist best effort - Client kann durch VLAN-Wechsel offline sein.
        # Trotzdem ins Dateilog, sonst ist ein dauerhaft stiller Rueckkanal
        # (falscher Token, IP nicht freigegeben, Portal auf HTTPS umgestellt)
        # auf dem Client nicht diagnostizierbar.
        Write-VsClientLog -Level WARN ("reportPhase '{0}/{1}' nicht zugestellt: {2}" -f $Phase, $PhaseEvent, (Get-VsErrorDetail -ErrorRecord $_))
    }
}

# MAC-Normalisierung (kanonisch: Grossbuchstaben, Doppelpunkte).
#
# Diese Funktion existiert dreimal: hier, in mecm\VirtuSphere-Common.ps1 und als
# virtusphere_normalize_mac() in PHP. Die drei laufen auf verschiedenen Maschinen
# (Client, MECM-Server, WebApp) und koennen sich keine Datei teilen. Sie duerfen
# aber nicht auseinanderlaufen: das Portal schreibt die MAC, MECM sucht sie per
# exaktem Match: eine abweichende Schreibweise macht eine VM fuer MECM unauffindbar,
# ohne jede Fehlermeldung (TESTPLAN-Befund 2.2).
#
# Gemeinsame Wahrheit ist Docker\WebAPI\tests\fixtures\mac-vectors.json; PHPUnit
# und Pester pruefen beide Seiten dagegen. Wer diese Funktion aendert, aendert
# alle drei oder bricht den Build.
function ConvertTo-VsNormalizedMac {
    param([string]$Mac)
    if ([string]::IsNullOrWhiteSpace($Mac)) { return $null }
    $hex = ($Mac -replace '[^0-9A-Fa-f]', '').ToUpperInvariant()
    if ($hex.Length -ne 12) { return $null }
    return ($hex -split '(?<=\G..)(?=.)') -join ':'
}

# --- Subnetzmaske -> Praefixlaenge ------------------------------------------
# Nimmt die Punktnotation (255.255.255.0) oder eine bereits fertige
# Praefixlaenge ("24") und liefert die Laenge, sonst $null.
#
# Validiert, statt nur zu zaehlen: eine Maske muss zusammenhaengend sein
# (Einsen von links, dann nur Nullen). 255.0.255.0 hat 16 gesetzte Bits, ist
# aber keine gueltige Maske; blosses Bitzaehlen haette daraus /16 gemacht und
# client_staticip haette dem Client stillschweigend das falsche Netz gegeben.
# Der Praefix-Zweig deckelt ebenso: "999" ist keine Laenge.
function Convert-VsSubnetMaskToPrefix {
    param([string]$Mask)

    if ([string]::IsNullOrWhiteSpace($Mask)) { return $null }
    $Mask = $Mask.Trim()

    # Bereits eine Praefixlaenge? (nur Ziffern, 0..32)
    if ($Mask -match '^\d+$') {
        $prefix = [int]$Mask
        if ($prefix -ge 0 -and $prefix -le 32) { return $prefix }
        return $null
    }

    # Punktnotation: vier Oktette, sonst nichts.
    if ($Mask -notmatch '^\d{1,3}(\.\d{1,3}){3}$') { return $null }

    $address = [ipaddress]::Any
    if (-not [ipaddress]::TryParse($Mask, [ref]$address)) { return $null }
    if ($address.AddressFamily -ne [System.Net.Sockets.AddressFamily]::InterNetwork) { return $null }

    $bits = ($address.GetAddressBytes() | ForEach-Object { [Convert]::ToString($_, 2).PadLeft(8, '0') }) -join ''

    # Zusammenhaengend: 1* gefolgt von 0*, nichts dazwischen.
    if ($bits -notmatch '^(1*)(0*)$') { return $null }

    return ($bits.ToCharArray() | Where-Object { $_ -eq '1' }).Count
}

# Erste MAC der Registry-Interfaces (von client_getinfo geschrieben) fuer die
# reportPhase-Aufrufe der Folge-Skripte.
function Get-VsReportMac {
    try {
        $ifRoot = Join-Path $script:VsRegistryBase 'Interfaces'
        foreach ($entry in @(Get-ChildItem -Path $ifRoot -ErrorAction Stop)) {
            $mac = $entry.GetValue('mac')
            if (-not [string]::IsNullOrWhiteSpace($mac)) { return [string]$mac }
        }
    } catch { Write-Debug $_ }
    # Fallback: erste aktive Nicht-Loopback-MAC
    try {
        $nic = Get-CimInstance Win32_NetworkAdapterConfiguration -Filter "IPEnabled='True'" | Select-Object -First 1
        if ($nic) { return [string]$nic.MACAddress }
    } catch { Write-Debug $_ }
    return $null
}
