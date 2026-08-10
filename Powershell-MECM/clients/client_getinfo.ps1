#Requires -Version 5.1
# ============================================================================
# client_getinfo.ps1 (V23) - holt die Geraeteinformationen der VM von der
# VirtuSphere-WebAPI (per MAC) und legt sie in der Registry
# HKLM:\SOFTWARE\VirtuSphere ab. Erste Phase der Client-Kette.
#
# Verbesserungen gegenueber V21:
#  - Adress-Fallback-Kette (Registry-Override / DNS / IP), Ergebnis wird fuer
#    die Folge-Skripte in die Registry geschrieben
#  - Invoke-RestMethod mit Timeout statt WebClient
#  - Stale-Fix: der Interfaces-Zweig wird VOR dem Schreiben geloescht (sonst
#    ueberleben Interface-Eintraege frueherer Ausrollungen -> client_staticip
#    wendet veraltete Netzconfig an)
#  - Erfolgs-Marker (SetupState=complete) erst NACH vollstaendigem Schreiben
#  - Whitelist der geschriebenen Felder (kein Datenmuell/Notizen auf dem Client)
#  - expliziter, wiederholbarer Client-Ready-ACK statt Lifecycle-Seiteneffekt
#    des Konfigurations-GETs
#  - Retry (3x/10s) + Datei-Logging + reportPhase
# ============================================================================

. "$PSScriptRoot\VirtuSphere-Client-Common.ps1"
Initialize-VsClientLog -Component 'getinfo'
Write-VsClientLog 'Starte getinfo'

$registryBase = 'HKLM:\SOFTWARE\VirtuSphere'
# Nur diese Felder werden aus der API-Antwort persistiert.
$allowedFields = @('vm_name', 'vm_hostname', 'vm_domain', 'vm_os', 'mission_id')

function Save-VsValue {
    param([string]$Path, [string]$Name, [string]$Value)
    if ([string]::IsNullOrWhiteSpace($Value)) { return }
    if (-not (Test-Path $Path)) { New-Item -Path $Path -Force | Out-Null }
    New-ItemProperty -Path $Path -Name $Name -Value $Value -PropertyType String -Force | Out-Null
}

# --- Erfolgs-Marker eines Vorlaufs SOFORT entfernen -------------------------
#
# Vor jeder Abbruchmoeglichkeit, nicht erst im Schreib-try weiter unten: das lag
# hinter dem API-Aufruf, also blieb bei einem Abbruch davor (WebAPI nicht
# erreichbar, keine passende MAC) ein `SetupState=complete` samt Interfaces-
# Unterbaum des VORIGEN Laufs stehen. client_staticip.ps1 liest genau diesen
# Unterbaum und haette die Adressen der vorigen VM auf diese gesetzt, unter einer
# gruenen Phase. Ein Marker darf nur eine Aussage ueber DIESEN Lauf sein.
if (Test-Path $registryBase) {
    Remove-ItemProperty -Path $registryBase -Name 'SetupState' -ErrorAction SilentlyContinue
    $stalePath = Join-Path $registryBase 'Interfaces'
    if (Test-Path $stalePath) { Remove-Item -Path $stalePath -Recurse -Force -ErrorAction SilentlyContinue }
}

# --- API-Adresse aufloesen (mit Retry) --------------------------------------
$api = $null
for ($attempt = 1; $attempt -le 3 -and -not $api; $attempt++) {
    $api = Resolve-VsApi
    if (-not $api) {
        Write-VsClientLog -Level WARN "Keine WebAPI erreichbar (Versuch $attempt/3), warte 10s."
        Start-Sleep -Seconds 10
    }
}
if (-not $api) {
    Write-VsClientLog -Level ERROR 'Keine WebAPI-Adresse erreichbar. Abbruch.'
    exit 1
}
Write-VsClientLog "WebAPI: $api"

# --- Alle IP-aktiven NICs durchprobieren ------------------------------------
# Normalisiert, bevor die MAC den Rechner verlaesst: das WMI-Format
# (Grossbuchstaben, Doppelpunkte) passt heute zufaellig zu dem, was das Portal
# speichert. ConvertTo-VsNormalizedMac ist die gemeinsame Wahrheit (mac-vectors,
# ADR-0029) und macht aus dem Zufall eine Zusage; client_staticip tut dasselbe.
# Eine MAC, die sich nicht normalisieren laesst, hat kein 12-stelliges Hex und
# kann in keiner Abfrage treffen.
$macs = @(Get-CimInstance -ClassName Win32_NetworkAdapterConfiguration -Filter "IPEnabled='True'" |
    Select-Object -ExpandProperty MACAddress |
    ForEach-Object { ConvertTo-VsNormalizedMac $_ } |
    Where-Object { $_ })
$data = $null
$usedMac = $null
foreach ($mac in $macs) {
    for ($attempt = 1; $attempt -le 3 -and -not $data; $attempt++) {
        try {
            $path = '/mecm-api.php?action=getDeviceInfos&mac={0}' -f [uri]::EscapeDataString($mac)
            $response = Invoke-RestMethod -Uri (Get-VsApiUrl -Api $api -Path $path) -TimeoutSec 10
            if ($response -and -not $response.PSObject.Properties['error'] -and $response.vm_name) {
                $data = $response
                $usedMac = $mac
                break
            } else {
                break   # gueltige Antwort ohne Treffer -> naechste NIC
            }
        } catch {
            Write-VsClientLog -Level WARN "MAC $mac Versuch $attempt/3 fehlgeschlagen: $(Get-VsErrorDetail -ErrorRecord $_)"
            Start-Sleep -Seconds 10
        }
    }
    if ($data) { break }
}

if (-not $data) {
    Write-VsClientLog -Level ERROR 'Keine passende MAC in der VirtuSphere-Datenbank. Abbruch.'
    exit 1
}
Write-VsClientLog "Treffer mit MAC $usedMac (VM $($data.vm_name))"
Send-VsPhase -Mac $usedMac -Phase 'getinfo' -PhaseEvent 'started' -Detail "match $usedMac"

try {
    # Der Stale-Fix ist oben schon gelaufen, vor jeder Abbruchmoeglichkeit; hier
    # bleibt nur, den Schluessel anzulegen, falls es ihn noch nicht gibt.
    if (-not (Test-Path $registryBase)) {
        New-Item -Path $registryBase -Force | Out-Null
    }

    # --- Whitelist-Felder schreiben ----------------------------------------
    foreach ($field in $allowedFields) {
        $value = $data.$field
        if ($null -ne $value) { Save-VsValue -Path $registryBase -Name $field -Value ([string]$value) }
    }

    # --- Interfaces schreiben ----------------------------------------------
    $ifPath = Join-Path $registryBase 'Interfaces'
    New-Item -Path $ifPath -Force | Out-Null
    $index = 0
    foreach ($iface in @($data.interfaces)) {
        $entryPath = Join-Path $ifPath ("Interface{0}" -f $index)
        New-Item -Path $entryPath -Force | Out-Null
        foreach ($prop in 'vlan', 'mac', 'mode', 'ip', 'subnet', 'gateway', 'dns1', 'dns2', 'type') {
            $val = $iface.$prop
            if ($null -ne $val) { Save-VsValue -Path $entryPath -Name $prop -Value ([string]$val) }
        }
        $index++
    }

    Write-VsClientLog "Registry geschrieben ($index Interface(s))."
    # Verbindlich nach allen Nutzdaten: ein GET beweist nur, dass Daten gelesen
    # wurden. Erst dieser POST darf die VM im Portal auf 5/5 setzen.
    Confirm-VsClientReady -Api $api -Mac $usedMac

    # --- MECM-Erfolgs-Marker wirklich ZULETZT ------------------------------
    # Nach dem ACK, damit ein Prozessabbruch/Power-Loss waehrend des POSTs nie
    # einen gruenen Detection-State ohne bestaetigten Serverzustand hinterlaesst.
    # ACK erfolgreich + Marker-Schreibfehler ist sicher: der Retry dedupliziert.
    Save-VsValue -Path $registryBase -Name 'SetupState' -Value 'complete'
    Send-VsPhase -Mac $usedMac -Phase 'getinfo' -PhaseEvent 'finished' -Detail "$index interfaces"
} catch {
    # ACK kann serverseitig angekommen sein, waehrend die Antwort verloren ging.
    # Marker entfernen -> MECM wiederholt; der Server dedupliziert den POST.
    Remove-ItemProperty -Path $registryBase -Name 'SetupState' -ErrorAction SilentlyContinue
    Write-VsClientLog -Level ERROR "Schreiben oder Client-Ready-ACK fehlgeschlagen: $($_.Exception.Message)"
    Send-VsPhase -Mac $usedMac -Phase 'getinfo' -PhaseEvent 'failed' -Detail $_.Exception.Message
    exit 1
}

exit 0
