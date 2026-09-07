#Requires -Version 5.1
# ============================================================================
# VirtuSphere-Client-Common.ps1 - gemeinsame Bausteine der Client-Skripte
# (getinfo/hostname/staticip/disks), die per MECM-Software-Center auf die
# PXE-installierten Windows-Clients verteilt werden.
#
# Dot-Source:  . "$PSScriptRoot\VirtuSphere-Client-Common.ps1"
#
# Adressfindung (Fallback-Kette):
#   1) Registry-SSoT      HKLM:\SOFTWARE\VirtuSphere\WebAPI
#   2) Paket-Notfall-DNS  $script:VsDefaultDnsApi
#   3) Paket-Notfall-IP   $script:VsFallbackIpApi
# Standortwerte werden durch bootstrap.json/Installer in die Registry gelegt;
# ausgelieferter Quelltext wird nicht angepasst.
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

# --- Eingebaute Notfalladressen (Laufzeitwerte kommen aus der Registry) ------
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

$script:VsRegistryBase = 'HKLM:\SOFTWARE\VirtuSphere'
$script:VsClientSnapshotSchema = 1
$script:VsResolvedApi = $null

# Die Client-Loggingdomaene wird gemeinsam mit jeder Phase paketiert. Common
# bleibt der oeffentliche Dot-Source-Pfad, verweigert aber einen unvollstaendigen
# oder versionsgemischten Paketordner sichtbar vor der ersten Phasenaktion.
$script:VsExpectedClientLoggingContractVersion = 1
$clientLoggingModule = Join-Path $PSScriptRoot 'VirtuSphere-Client-Logging.ps1'
if (-not (Test-Path $clientLoggingModule)) {
    throw ('VirtuSphere-Client-Logging-Modul fehlt: {0}. Clientpaket neu verteilen.' -f $clientLoggingModule)
}
. $clientLoggingModule
$clientLoggingVersion = Get-VsClientLoggingContractVersion
if ($clientLoggingVersion -ne $script:VsExpectedClientLoggingContractVersion) {
    throw ('VirtuSphere-Client-Logging-Modul hat Version {0}, erwartet wird {1}. Clientpaket vollstaendig aktualisieren.' -f $clientLoggingVersion, $script:VsExpectedClientLoggingContractVersion)
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

function Initialize-VsClientBootstrap {
    param([Parameter(Mandatory)][string]$ManifestPath)
    try {
        $current = Get-ItemProperty -Path $script:VsRegistryBase -Name 'WebAPI' -ErrorAction Stop
        if (-not [string]::IsNullOrWhiteSpace([string]$current.WebAPI)) { return }
    } catch { Write-Debug $_ }

    if (-not (Test-Path -LiteralPath $ManifestPath)) { return }
    try {
        $manifest = Get-Content -LiteralPath $ManifestPath -Raw -ErrorAction Stop | ConvertFrom-Json -ErrorAction Stop
        $webApi = Convert-VsWebApi ([string]$manifest.WebAPI)
        $scheme = ([string]$manifest.Scheme).ToLowerInvariant()
        $thumbprint = ([string]$manifest.CertThumbprint -replace '\s', '').ToUpperInvariant()
        if ($scheme -notin @('http', 'https')) { throw 'ungueltiges Scheme' }
        if ($thumbprint -and $thumbprint -notmatch '^[0-9A-F]{40}$') { throw 'ungueltiger Zertifikatfingerabdruck' }
        if (-not (Test-Path $script:VsRegistryBase)) { New-Item -Path $script:VsRegistryBase -Force -ErrorAction Stop | Out-Null }
        New-ItemProperty -Path $script:VsRegistryBase -Name 'WebAPI' -Value $webApi -PropertyType String -Force -ErrorAction Stop | Out-Null
        $raw = Get-ItemProperty -Path $script:VsRegistryBase -ErrorAction Stop
        if (-not $raw.PSObject.Properties['Scheme']) {
            New-ItemProperty -Path $script:VsRegistryBase -Name 'Scheme' -Value $scheme -PropertyType String -ErrorAction Stop | Out-Null
        }
        if ($thumbprint -and -not $raw.PSObject.Properties['CertThumbprint']) {
            New-ItemProperty -Path $script:VsRegistryBase -Name 'CertThumbprint' -Value $thumbprint -PropertyType String -ErrorAction Stop | Out-Null
        }
    } catch {
        throw ("Client-Bootstrapmanifest ist ungueltig oder nicht schreibbar: {0}" -f $_.Exception.Message)
    }
}

# Additiver Diagnoseheader (ADR-0032). Er aendert kein JSON-Feld und keine
# Authentisierung: der Client bleibt ueber seine bekannte MAC autorisiert.
function Get-VsClientApiHeaders {
    return @{ 'X-VirtuSphere-Correlation' = (Get-VsClientCorrelationId) }
}

function ConvertTo-VsUtf8JsonBytes {
    param([Parameter(Mandatory)]$Value, [int]$Depth = 6)
    $json = ConvertTo-Json -InputObject $Value -Depth $Depth
    return [Text.Encoding]::UTF8.GetBytes($json)
}

# TLS-Vorbereitung fuer PS 5.1. Ein leerer Fingerabdruck bedeutet normale PKI-
# Pruefung. Ein gesetzter Fingerabdruck ist ausschliesslich eine enge Trust-
# Ausnahme fuer genau dieses Zertifikat; ein bereits PKI-gueltiges Zertifikat
# bleibt gueltig und wird nicht zusaetzlich gepinnt.
function Initialize-VsTls {
    if ((Get-VsApiScheme) -ne 'https') { return }

    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    } catch { Write-Debug $_ }

    $pinned = ''
    try { $pinned = ([string](Get-ItemProperty -Path $script:VsRegistryBase -Name 'CertThumbprint' -ErrorAction Stop).CertThumbprint -replace '[^0-9A-Fa-f]', '').ToUpperInvariant() } catch { Write-Debug $_ }
    if ([string]::IsNullOrWhiteSpace($pinned)) { return }
    try {
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback = {
            param($senderObject, $certificate, $chain, $sslPolicyErrors)
            $null = $senderObject, $chain
            if ($sslPolicyErrors -eq [Net.Security.SslPolicyErrors]::None) { return $true }
            if (-not $certificate) { return $false }
            try { return $certificate.GetCertHashString().ToUpperInvariant() -eq $pinned } catch { return $false }
        }.GetNewClosure()
    } catch { Write-Debug $_ }
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
            $health = Invoke-RestMethod -Uri (Get-VsApiUrl -Api $candidate -Path '/portal/health.php') -TimeoutSec 5
            if (-not (Test-VsHealthDocument -Document $health)) {
                Write-VsClientLog -Level WARN -Context $candidate -Message 'Adresse antwortet, liefert aber nicht das VirtuSphere-Health-Schema.'
                continue
            }
            Set-VsResolvedApi -Api $candidate
            return $candidate
        } catch {
            $statusCode = Get-VsErrorStatusCode -ErrorRecord $_
            $health = Get-VsHealthDocumentFromError -ErrorRecord $_
            if ((Test-VsApiAnswered -ErrorRecord $_) -and (Test-VsHealthDocument -Document $health)) {
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

function Test-VsHealthDocument {
    param($Document)
    if (-not $Document) { return $false }
    return $Document.PSObject.Properties['status'] -and [string]$Document.status -in @('ok', 'degraded', 'error') -and
        $Document.PSObject.Properties['db'] -and [string]$Document.db -in @('ok', 'error') -and
        $Document.PSObject.Properties['php'] -and [string]$Document.php -match '^\d+\.\d+$'
}

function Get-VsHealthDocumentFromError {
    param([Parameter(Mandatory)]$ErrorRecord)
    $body = ''
    try { if ($ErrorRecord.ErrorDetails) { $body = [string]$ErrorRecord.ErrorDetails.Message } } catch { Write-Debug $_ }
    if ([string]::IsNullOrWhiteSpace($body)) {
        try {
            $stream = $ErrorRecord.Exception.Response.GetResponseStream()
            if ($stream) {
                $reader = New-Object IO.StreamReader($stream)
                try { $body = $reader.ReadToEnd() } finally { $reader.Dispose() }
            }
        } catch { Write-Debug $_ }
    }
    if ([string]::IsNullOrWhiteSpace($body)) { return $null }
    try { return ($body | ConvertFrom-Json -ErrorAction Stop) } catch { return $null }
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
    $body = $null
    try {
        if ($ErrorRecord.ErrorDetails -and -not [string]::IsNullOrWhiteSpace([string]$ErrorRecord.ErrorDetails.Message)) {
            $body = [string]$ErrorRecord.ErrorDetails.Message
        }
    } catch { Write-Debug $_ }
    if (-not $response -and [string]::IsNullOrWhiteSpace($body)) { return $detail }
    try {
        $stream = if ($response -and [string]::IsNullOrWhiteSpace($body)) { $response.GetResponseStream() } else { $null }
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
                $message = [string]$parsed.$field
                if ([Text.Encoding]::UTF8.GetByteCount($message) -gt 512) {
                    $bytes = [Text.Encoding]::UTF8.GetBytes($message)
                    $count = 512
                    while ($count -gt 0 -and ($bytes[$count] -band 0xC0) -eq 0x80) { $count-- }
                    $message = [Text.Encoding]::UTF8.GetString($bytes, 0, $count)
                }
                return ('{0} | WebApp: {1}' -f $detail, $message)
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
        [Parameter(Mandatory)][string]$Mac,
        # Additiv (Etappe 14D, ADR-0019-Amendment). Fehlt sie, bleibt der Body
        # exakt der bisherige: ein Client-Paket vor dem Cutover meldet weiter
        # ohne Revision, und der Server nimmt das nur fuer Revision 1 ohne
        # Tombstone an. Ein leerer oder nicht numerischer Wert wird gar nicht
        # erst gesendet, statt als "0" eine Revision zu erfinden, die es nicht
        # gibt: das Feld weglassen heisst "ich kenne keine", und genau das ist
        # dann wahr.
        $RolloutRevision = $null
    )

    $payload = @{ mac = $Mac }
    if ($null -ne $RolloutRevision -and "$RolloutRevision" -match '^\d+$' -and [int]$RolloutRevision -gt 0) {
        $payload['rollout_revision'] = [int]$RolloutRevision
    }
    $body = ConvertTo-VsUtf8JsonBytes -Value $payload
    $response = Invoke-RestMethod -Uri (Get-VsApiUrl -Api $Api -Path '/mecm_client_ack.php') -Method Post `
        -ContentType 'application/json; charset=utf-8' -Body $body -Headers (Get-VsClientApiHeaders) -TimeoutSec 10
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
            -ContentType 'application/json; charset=utf-8' -Body (ConvertTo-VsUtf8JsonBytes -Value $body) -Headers (Get-VsClientApiHeaders) -TimeoutSec 5 | Out-Null
    } catch {
        # Rueckkanal ist best effort - Client kann durch VLAN-Wechsel offline sein.
        # Trotzdem ins Dateilog, sonst ist ein dauerhaft stiller Rueckkanal
        # (falscher Token, IP nicht freigegeben, Portal auf HTTPS umgestellt)
        # auf dem Client nicht diagnostizierbar.
        Write-VsClientLog -Level WARN -Context ("{0}/{1}" -f $Phase, $PhaseEvent) `
            -Message ("reportPhase nicht zugestellt: {0}" -f (Get-VsErrorDetail -ErrorRecord $_))
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

function Test-VsIpv4Literal {
    param([string]$Value)
    if ([string]::IsNullOrWhiteSpace($Value)) { return $false }
    $parsed = [ipaddress]::Any
    return ([ipaddress]::TryParse($Value.Trim(), [ref]$parsed) -and
        $parsed.AddressFamily -eq [System.Net.Sockets.AddressFamily]::InterNetwork)
}

# Builds the complete desired/actual mapping without changing Windows. The
# caller must refuse every write when Valid is false, so one missing second NIC
# cannot leave the first NIC half configured under a green phase.
function New-VsClientNetworkPlan {
    param(
        [Parameter(Mandatory)][AllowEmptyCollection()][array]$Targets,
        [Parameter(Mandatory)][AllowEmptyCollection()][array]$Adapters
    )

    $errors = New-Object System.Collections.ArrayList
    $items = New-Object System.Collections.ArrayList
    $targetByMac = New-Object 'System.Collections.Generic.Dictionary[string,object]' ([System.StringComparer]::Ordinal)
    $adapterByMac = New-Object 'System.Collections.Generic.Dictionary[string,System.Collections.ArrayList]' ([System.StringComparer]::Ordinal)
    $gatewayTargets = New-Object System.Collections.ArrayList

    foreach ($adapter in @($Adapters)) {
        $mac = ConvertTo-VsNormalizedMac ([string]$adapter.MacAddress)
        if (-not $mac) { continue }
        if (-not $adapterByMac.ContainsKey($mac)) { $adapterByMac[$mac] = New-Object System.Collections.ArrayList }
        [void]$adapterByMac[$mac].Add($adapter)
    }

    if (@($Targets).Count -eq 0) { [void]$errors.Add('Der publizierte Snapshot enthaelt keine Sollschnittstelle.') }
    foreach ($target in @($Targets)) {
        $mac = ConvertTo-VsNormalizedMac ([string]$target.Mac)
        if (-not $mac) {
            [void]$errors.Add('Eine Sollschnittstelle hat keine gueltige MAC-Adresse.')
            continue
        }
        if ($targetByMac.ContainsKey($mac)) {
            [void]$errors.Add("Soll-MAC $mac ist mehrfach vorhanden.")
            continue
        }
        $targetByMac[$mac] = $target
    }

    foreach ($mac in @($targetByMac.Keys | Sort-Object)) {
        $target = $targetByMac[$mac]
        $mode = ([string]$target.Mode).Trim().ToLowerInvariant()
        $prefix = $null
        $dns = @([string]$target.Dns1, [string]$target.Dns2) |
            ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' }

        if ($mode -notin @('static', 'dhcp')) {
            [void]$errors.Add("Soll-MAC $mac hat den unbekannten Modus '$($target.Mode)'.")
        }
        if ($mode -eq 'static') {
            if (-not (Test-VsIpv4Literal ([string]$target.Ip))) {
                [void]$errors.Add("Soll-MAC $mac hat keine gueltige statische IPv4-Adresse.")
            }
            $prefix = Convert-VsSubnetMaskToPrefix ([string]$target.Subnet)
            if ($null -eq $prefix) { [void]$errors.Add("Soll-MAC $mac hat keine gueltige Subnetzmaske.") }
            if (-not [string]::IsNullOrWhiteSpace([string]$target.Gateway)) {
                if (-not (Test-VsIpv4Literal ([string]$target.Gateway))) {
                    [void]$errors.Add("Soll-MAC $mac hat kein gueltiges IPv4-Gateway.")
                } else { [void]$gatewayTargets.Add($mac) }
            }
            foreach ($server in $dns) {
                if (-not (Test-VsIpv4Literal $server)) { [void]$errors.Add("Soll-MAC $mac hat einen ungueltigen IPv4-DNS-Server.") }
            }
        }

        if (-not $adapterByMac.ContainsKey($mac)) {
            [void]$errors.Add("Soll-MAC $mac fehlt auf diesem Client.")
            continue
        }
        $adapterMatches = @($adapterByMac[$mac])
        if ($adapterMatches.Count -ne 1) {
            [void]$errors.Add("Soll-MAC $mac ist auf diesem Client mehrdeutig ($($adapterMatches.Count) Adapter).")
            continue
        }
        $adapter = $adapterMatches[0]
        if ([string]$adapter.PhysicalMediaType -eq 'Wireless') {
            [void]$errors.Add("Soll-MAC $mac gehoert zu einem nicht verwalteten Wireless-Adapter.")
        }
        if ([string]$adapter.Status -ne 'Up') {
            [void]$errors.Add("Soll-MAC $mac ist nicht nutzbar (Status $($adapter.Status)).")
        }
        [void]$items.Add([pscustomobject]@{
            Mac = $mac
            Target = $target
            Adapter = $adapter
            Mode = $mode
            Prefix = $prefix
            Dns = @($dns)
        })
    }

    if ($gatewayTargets.Count -gt 1) {
        [void]$errors.Add("Mehrere Sollschnittstellen definieren ein Default-Gateway ($($gatewayTargets -join ', ')); vor Aenderungen blockiert.")
    }

    $desiredNames = @{}
    foreach ($item in @($items)) {
        $desiredName = ([string]$item.Target.Name).Trim()
        if ($desiredName -eq '') { continue }
        if ($desiredNames.ContainsKey($desiredName)) {
            [void]$errors.Add("Adaptername '$desiredName' ist fuer mehrere Sollschnittstellen vorgesehen.")
        } else { $desiredNames[$desiredName] = [int]$item.Adapter.ifIndex }
        foreach ($adapter in @($Adapters)) {
            if ([string]$adapter.Name -eq $desiredName -and [int]$adapter.ifIndex -ne [int]$item.Adapter.ifIndex) {
                [void]$errors.Add("Adaptername '$desiredName' ist bereits einer anderen Schnittstelle zugeordnet.")
            }
        }
    }
    return [pscustomobject]@{ Valid = ($errors.Count -eq 0); Errors = @($errors); Items = @($items) }
}

# Eine Disknummer ist nur die aktuelle Buszuordnung und kann sich nach einem
# Neustart aendern. Fuer das Disk-Journal wird deshalb zuerst die vom Storage-
# Stack gelieferte UniqueId verwendet. Fehlt sie, ist nur die Kombination aus
# Seriennummer, LocationPath und Groesse ausreichend. Freundlicher Name und
# Nummer sind bewusst keine Fallbacks: beide koennen fuer mehrere VMDKs/VHDXs
# gleich sein oder sich zwischen zwei Laeufen aendern.
function Get-VsDiskStableIdentity {
    [CmdletBinding()]
    param([Parameter(Mandatory)][object]$Disk)

    $uniqueId = [string]$Disk.UniqueId
    if (-not [string]::IsNullOrWhiteSpace($uniqueId) -and $uniqueId.Trim() -notmatch '^0+$') {
        return [pscustomobject]@{ Valid = $true; Identity = ('unique:{0}' -f $uniqueId.Trim()); Reason = '' }
    }

    $serial = [string]$Disk.SerialNumber
    $location = [string]$Disk.LocationPath
    $size = 0L
    try { $size = [long]$Disk.Size } catch { $size = 0L }
    if (-not [string]::IsNullOrWhiteSpace($serial) -and
        -not [string]::IsNullOrWhiteSpace($location) -and $size -gt 0) {
        return [pscustomobject]@{
            Valid = $true
            Identity = ('serial-location-size:{0}|{1}|{2}' -f $serial.Trim(), $location.Trim(), $size)
            Reason = ''
        }
    }

    return [pscustomobject]@{
        Valid = $false
        Identity = ''
        Reason = 'Keine stabile Datentraegeridentitaet (UniqueId oder Seriennummer + LocationPath + Groesse).'
    }
}

function Get-VsSha256Hex {
    [CmdletBinding()]
    param([Parameter(Mandatory)][string]$Value)

    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($Value)
        return (($sha.ComputeHash($bytes) | ForEach-Object { $_.ToString('x2') }) -join '')
    } finally {
        $sha.Dispose()
    }
}

# Erste MAC der Registry-Interfaces (von client_getinfo geschrieben) fuer die
# reportPhase-Aufrufe der Folge-Skripte.
function Get-VsActiveSnapshotRoot {
    try {
        $base = Get-ItemProperty -Path $script:VsRegistryBase -Name 'ActiveSnapshot', 'SetupState' -ErrorAction Stop
        if ([string]$base.SetupState -ne 'complete' -or [string]::IsNullOrWhiteSpace([string]$base.ActiveSnapshot)) { return $null }
        $root = Join-Path (Join-Path $script:VsRegistryBase 'Snapshots') ([string]$base.ActiveSnapshot)
        $meta = Get-ItemProperty -Path $root -Name 'SnapshotSchema', 'SnapshotState', 'InterfaceCount' -ErrorAction Stop
        if ([int]$meta.SnapshotSchema -ne $script:VsClientSnapshotSchema -or [string]$meta.SnapshotState -ne 'published' -or [int]$meta.InterfaceCount -lt 0) { return $null }
        return $root
    } catch {
        Write-Debug $_
        return $null
    }
}

function Get-VsSnapshotValue {
    param([Parameter(Mandatory)][string]$Name)
    $root = Get-VsActiveSnapshotRoot
    if (-not $root) { return $null }
    try { return (Get-ItemProperty -Path $root -Name $Name -ErrorAction Stop).$Name } catch { Write-Debug $_; return $null }
}

function Get-VsSnapshotInterfacesRoot {
    $root = Get-VsActiveSnapshotRoot
    if (-not $root) { return $null }
    $interfaces = Join-Path $root 'Interfaces'
    if (-not (Test-Path -Path $interfaces)) { return $null }
    return $interfaces
}

function Get-VsReportMac {
    try {
        $ifRoot = Get-VsSnapshotInterfacesRoot
        if (-not $ifRoot) { throw 'Kein vollstaendig publizierter Client-Snapshot.' }
        foreach ($entry in @(Get-ChildItem -Path $ifRoot -ErrorAction Stop)) {
            $mac = $entry.GetValue('mac')
            if (-not [string]::IsNullOrWhiteSpace($mac)) { return [string]$mac }
        }
    } catch { Write-Debug $_ }
    # Fallback: erste aktive Nicht-Loopback-MAC. Normalisiert wie der Wert aus
    # der Registry-Interfaces, den client_getinfo dort bereits normalisiert
    # ablegt: reportPhase authentisiert ueber die bekannte MAC, und zwei
    # Schreibweisen desselben Adapters sind fuer das Portal zwei Adapter.
    try {
        $nic = Get-CimInstance Win32_NetworkAdapterConfiguration -Filter "IPEnabled='True'" | Select-Object -First 1
        if ($nic) { return (ConvertTo-VsNormalizedMac $nic.MACAddress) }
    } catch { Write-Debug $_ }
    return $null
}
