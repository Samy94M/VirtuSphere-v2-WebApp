#Requires -Version 5.1
# ============================================================================
# client_getinfo.ps1 (V22) - holt die Geraeteinformationen der VM von der
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
$macs = @(Get-CimInstance -ClassName Win32_NetworkAdapterConfiguration -Filter "IPEnabled='True'" | Select-Object -ExpandProperty MACAddress)
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
    # --- Stale-Fix: alten Zustand entfernen, dann neu schreiben -------------
    if (Test-Path $registryBase) {
        Remove-ItemProperty -Path $registryBase -Name 'SetupState' -ErrorAction SilentlyContinue
        $ifPath = Join-Path $registryBase 'Interfaces'
        if (Test-Path $ifPath) { Remove-Item -Path $ifPath -Recurse -Force -ErrorAction SilentlyContinue }
    } else {
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

    # --- Erfolgs-Marker ZULETZT --------------------------------------------
    Save-VsValue -Path $registryBase -Name 'SetupState' -Value 'complete'
    Write-VsClientLog "Registry geschrieben ($index Interface(s))."
    Send-VsPhase -Mac $usedMac -Phase 'getinfo' -PhaseEvent 'finished' -Detail "$index interfaces"
} catch {
    Write-VsClientLog -Level ERROR "Schreiben fehlgeschlagen: $($_.Exception.Message)"
    Send-VsPhase -Mac $usedMac -Phase 'getinfo' -PhaseEvent 'failed' -Detail $_.Exception.Message
    exit 1
}

exit 0
