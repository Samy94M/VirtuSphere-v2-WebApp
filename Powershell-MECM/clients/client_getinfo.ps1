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
Initialize-VsClientBootstrap -ManifestPath (Join-Path $PSScriptRoot 'bootstrap.json')

$registryBase = 'HKLM:\SOFTWARE\VirtuSphere'
# Nur diese Felder werden aus der API-Antwort persistiert.
#
# `vm_hostname` traegt seit Etappe 14D den EINGEFRORENEN Rolloutnamen, nicht den
# aktuellen Portal-Sollwert: client_hostname.ps1 benennt Windows nach dem, was
# hier steht, und muss deshalb den Namen bekommen, mit dem dieser Rollout an MECM
# gegeben wurde. Eine spaetere Korrektur im Portal darf einen laufenden Rollout
# nicht umdeuten.
#
# `rollout_revision` ist additiv (ADR-0019-Amendment) und rein zum Zurueckmelden:
# der ACK traegt sie, damit ein Client eines VORIGEN Rollouts den Lebenszyklus
# des neuen nicht abschliessen kann. Kein Skript der Kette trifft eine
# Entscheidung anhand ihres Werts.
$allowedFields = @('vm_name', 'vm_hostname', 'vm_domain', 'vm_os', 'mission_id', 'rollout_revision')

function Save-VsValue {
    param([string]$Path, [string]$Name, [string]$Value)
    if (-not (Test-Path $Path)) { New-Item -Path $Path -Force -ErrorAction Stop | Out-Null }
    New-ItemProperty -Path $Path -Name $Name -Value $Value -PropertyType String -Force -ErrorAction Stop | Out-Null
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

# Validate the complete response before the first snapshot write. Optional
# strings may be empty, but the fenced identity and every interface must be
# structurally usable by the following phases.
$revision = 0
if ([string]::IsNullOrWhiteSpace([string]$data.vm_name) -or
    [string]::IsNullOrWhiteSpace([string]$data.vm_hostname) -or
    -not [int]::TryParse([string]$data.rollout_revision, [ref]$revision) -or $revision -le 0 -or
    $null -eq $data.interfaces) {
    Write-VsClientLog -Level ERROR 'getDeviceInfos-Antwort ist unvollstaendig; kein Snapshot wird publiziert.'
    Send-VsPhase -Mac $usedMac -Phase 'getinfo' -PhaseEvent 'failed' -Detail 'invalid response schema'
    exit 1
}
$seenMacs = @{}
foreach ($iface in @($data.interfaces)) {
    $normalizedMac = ConvertTo-VsNormalizedMac ([string]$iface.mac)
    if (-not $normalizedMac -or $seenMacs.ContainsKey($normalizedMac) -or [string]$iface.mode -notin @('dhcp', 'static')) {
        Write-VsClientLog -Level ERROR 'getDeviceInfos-Antwort enthaelt eine ungueltige oder doppelte Interface-Identitaet; kein Snapshot wird publiziert.'
        Send-VsPhase -Mac $usedMac -Phase 'getinfo' -PhaseEvent 'failed' -Detail 'invalid interface schema'
        exit 1
    }
    $seenMacs[$normalizedMac] = $true
}

try {
    # Der Stale-Fix ist oben schon gelaufen, vor jeder Abbruchmoeglichkeit; hier
    # bleibt nur, den Schluessel anzulegen, falls es ihn noch nicht gibt.
    if (-not (Test-Path $registryBase)) {
        New-Item -Path $registryBase -Force | Out-Null
    }

    # --- Versionierten Snapshot vorbereiten --------------------------------
    $snapshotId = [guid]::NewGuid().ToString('N')
    $snapshotsRoot = Join-Path $registryBase 'Snapshots'
    $snapshotRoot = Join-Path $snapshotsRoot $snapshotId
    New-Item -Path $snapshotRoot -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -Path $snapshotRoot -Name 'SnapshotSchema' -Value $script:VsClientSnapshotSchema -PropertyType DWORD -Force -ErrorAction Stop | Out-Null
    Save-VsValue -Path $snapshotRoot -Name 'SnapshotState' -Value 'preparing'

    # --- Whitelist-Felder schreiben ----------------------------------------
    foreach ($field in $allowedFields) {
        $value = $data.$field
        Save-VsValue -Path $snapshotRoot -Name $field -Value $(if ($null -eq $value) { '' } else { [string]$value })
    }

    # --- Interfaces schreiben ----------------------------------------------
    $ifPath = Join-Path $snapshotRoot 'Interfaces'
    New-Item -Path $ifPath -Force -ErrorAction Stop | Out-Null
    $index = 0
    foreach ($iface in @($data.interfaces)) {
        $entryPath = Join-Path $ifPath ("Interface{0}" -f $index)
        New-Item -Path $entryPath -Force -ErrorAction Stop | Out-Null
        foreach ($prop in 'vlan', 'mac', 'mode', 'ip', 'subnet', 'gateway', 'dns1', 'dns2', 'type') {
            $val = $iface.$prop
            Save-VsValue -Path $entryPath -Name $prop -Value $(if ($null -eq $val) { '' } else { [string]$val })
        }
        $index++
    }

    New-ItemProperty -Path $snapshotRoot -Name 'InterfaceCount' -Value $index -PropertyType DWORD -Force -ErrorAction Stop | Out-Null

    # Read back the material identity and the exact interface cardinality.
    $stored = Get-ItemProperty -Path $snapshotRoot -Name 'SnapshotSchema', 'vm_name', 'vm_hostname', 'rollout_revision', 'InterfaceCount' -ErrorAction Stop
    $storedInterfaces = @(Get-ChildItem -Path $ifPath -ErrorAction Stop)
    if ([int]$stored.SnapshotSchema -ne $script:VsClientSnapshotSchema -or
        [string]$stored.vm_name -ne [string]$data.vm_name -or
        [string]$stored.vm_hostname -ne [string]$data.vm_hostname -or
        [int]$stored.rollout_revision -ne $revision -or
        [int]$stored.InterfaceCount -ne $index -or $storedInterfaces.Count -ne $index) {
        throw 'Nachlesen des vorbereiteten Client-Snapshots ist fehlgeschlagen.'
    }
    Save-VsValue -Path $snapshotRoot -Name 'SnapshotState' -Value 'published'
    Save-VsValue -Path $registryBase -Name 'ActiveSnapshot' -Value $snapshotId
    Write-VsClientLog "Snapshot $snapshotId publiziert ($index Interface(s))."
    # Verbindlich nach allen Nutzdaten: ein GET beweist nur, dass Daten gelesen
    # wurden. Erst dieser POST darf die VM im Portal auf 5/5 setzen.
    # Die Rolloutrevision faehrt mit (Etappe 14D). Sie stammt aus DIESER Antwort
    # und nicht aus der Registry: was hier bestaetigt wird, ist der Rollout, der
    # gerade gelesen und geschrieben wurde.
    Confirm-VsClientReady -Api $api -Mac $usedMac -RolloutRevision $data.rollout_revision

    # --- MECM-Erfolgs-Marker wirklich ZULETZT ------------------------------
    # Nach dem ACK, damit ein Prozessabbruch/Power-Loss waehrend des POSTs nie
    # einen gruenen Detection-State ohne bestaetigten Serverzustand hinterlaesst.
    # ACK erfolgreich + Marker-Schreibfehler ist sicher: der Retry dedupliziert.
    Save-VsValue -Path $registryBase -Name 'SetupState' -Value 'complete'

    # Only the selected snapshot belongs to this rollout. Remove older
    # snapshots after success; bootstrap, logging and foreign registry values
    # live outside Snapshots and are never touched.
    foreach ($oldSnapshot in @(Get-ChildItem -Path $snapshotsRoot -ErrorAction SilentlyContinue)) {
        if ($oldSnapshot.PSChildName -ne $snapshotId) { Remove-Item -Path $oldSnapshot.PSPath -Recurse -Force -ErrorAction SilentlyContinue }
    }
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
