#Requires -Version 5.1
# ============================================================================
# mecm_new-device-sync.ps1 - synchronisiert VMs aus der VirtuSphere-Datenbank
# nach SCCM: importiert Geraete, approved sie, weist OS-/Paket-/Mission-
# Collections zu und meldet die ResourceID zurueck.
# Laeuft als geplante Aufgabe "VirtuSphere MECM Devices Sync".
#
# Haertung/Optimierung gegenueber V2:
#  - Konfiguration aus Registry (keine harten IPs/Site-Codes)
#  - Leerlauf-Abkuerzung: bei 0 Devices werden die teuren SCCM-Vollabfragen
#    uebersprungen (Normalfall alle 10s ist damit fast kostenlos)
#  - Collection-Cache je Scan statt Get-CMDeviceCollection pro Device/Paket
#  - Task-Sequence-Collections werden EINMAL pro Scan geprueft, nicht je Device
#  - MAC-Vergleich normalisiert (keine False-Positive-Konfliktwarnungen mehr)
#  - eingebettetes mission-Objekt aus getDeviceList statt N+1 getMissionName
#  - Heartbeat je Scan; Site-Drive-Recovery nach wiederholten Fehlern
# ============================================================================

. "$PSScriptRoot\VirtuSphere-Common.ps1"

$config = Get-VsConfig
if (-not $config) {
    # Auch ohne Registry-Konfiguration ins Dateilog schreiben (Default-LogRoot),
    # damit der Fehler bei einem SYSTEM-Task ohne Konsole sichtbar bleibt.
    Initialize-VsLog -Component 'device-sync'
    Write-VsLog -Level ERROR -Message 'Registry-Konfiguration fehlt (HKLM:\SOFTWARE\VirtuSphere\MECM). install-VirtuSphere-MECM.ps1 ausfuehren - warte auf Konfiguration.'
    # Selbstheilung statt exit 1: die 3 Taskplaner-Neustarts waeren nach
    # wenigen Minuten aufgebraucht, danach bliebe der Task bis zum Reboot tot.
    while (-not $config) {
        Start-Sleep -Seconds 60
        $config = Get-VsConfig
    }
    Write-VsLog -Message 'Registry-Konfiguration gefunden - starte.'
}
Initialize-VsLog -Component 'device-sync' -LogRoot $config.LogRoot
Write-VsLog -Message '=== Device-Sync gestartet ==='

$intervalSeconds = [Math]::Max(5, $config.DeviceSyncInterval)
$siteCode = $null
$consecutiveErrors = 0
$loop = 0

# Legt eine Device Collection an (falls noetig) und verschiebt sie in den
# VirtuSphere-Ordner. Liefert die Collection zurueck.
function New-VsDeviceCollection {
    param([string]$Name, [string]$FolderPath)
    $existing = Get-CMDeviceCollection -Name $Name -ErrorAction SilentlyContinue
    if ($existing) { return $existing }

    New-CMDeviceCollection -Name $Name -LimitingCollectionName 'All Systems' -Comment 'Autogeneriert by VirtuSphere' -ErrorAction Stop | Out-Null
    Start-Sleep -Seconds 3
    $created = Get-CMDeviceCollection -Name $Name -ErrorAction SilentlyContinue
    if ($created -and $FolderPath) {
        try { $created | Move-CMObject -FolderPath $FolderPath -ErrorAction Stop | Out-Null } catch {
            Write-VsLog -Level WARN -Context $Name -Message ("Collection angelegt, aber Verschieben nach '{0}' fehlgeschlagen: {1}" -f $FolderPath, $_.Exception.Message)
        }
    }
    return $created
}

while ($true) {
    $loop++
    $scanStart = Get-Date
    Send-VsHeartbeat -Config $config -Source 'device-sync' -IntervalSeconds $intervalSeconds

    try {
        # --- VirtuSphere-Geraeteliste laden --------------------------------
        $devices = @(Invoke-VsApi -Config $config -Path '/mecm-api.php?action=getDeviceList' -TimeoutSec 20)
        $consecutiveErrors = 0

        if ($devices.Count -eq 0) {
            # Leerlauf-Abkuerzung: keine SCCM-Vollabfragen ausloesen.
            Start-Sleep -Seconds $intervalSeconds
            continue
        }

        if (-not $siteCode) {
            $siteCode = Initialize-VsCmSite -Config $config
            if (-not $siteCode) { throw 'SCCM-Site nicht initialisierbar.' }
        }
        $osFolder = "{0}:\DeviceCollection\VirtuSphere_OS" -f $siteCode
        $missionFolder = "{0}:\DeviceCollection\VirtuSphere_Missions" -f $siteCode

        Write-VsLog -Message ("Scan #{0}: {1} Devices von VirtuSphere geladen." -f $loop, $devices.Count)

        # --- SCCM-Daten EINMAL je Scan cachen ------------------------------
        $mecmDevices = @{}
        foreach ($d in @(Get-CMDevice -Fast | Select-Object Name, MACAddress, ResourceID)) {
            if ($d.Name) { $mecmDevices[$d.Name] = $d }
        }
        $taskSequences = @(Get-CMTaskSequence -Fast | Select-Object -ExpandProperty Name)
        $collectionCache = @{}
        foreach ($c in @(Get-CMDeviceCollection | Select-Object Name, CollectionID)) {
            if ($c.Name) { $collectionCache[$c.Name] = $c.CollectionID }
        }
        $collectionsToUpdate = @{}

        # Ordner sicherstellen. Get-/New-CMFolder erwarten laut MS-Doku
        # RELATIVE Pfade ohne Site-Drive-Praefix (anders als Move-CMObject).
        foreach ($folderName in @('VirtuSphere_OS', 'VirtuSphere_Missions')) {
            $fp = "DeviceCollection\{0}" -f $folderName
            if (-not (Get-CMFolder -FolderPath $fp -ErrorAction SilentlyContinue)) {
                try { New-CMFolder -Name $folderName -ParentFolderPath 'DeviceCollection' -ErrorAction Stop | Out-Null } catch {
                    Write-VsLog -Level WARN -Context $folderName -Message ("Collection-Ordner konnte nicht angelegt werden: {0}" -f $_.Exception.Message)
                }
            }
        }

        # --- Task-Sequence-Collections EINMAL je Scan ----------------------
        foreach ($tsName in $taskSequences) {
            if (-not $collectionCache.ContainsKey($tsName)) {
                try {
                    $c = New-VsDeviceCollection -Name $tsName -FolderPath $osFolder
                    if ($c) { $collectionCache[$tsName] = $c.CollectionID }
                } catch {
                    Write-VsLog -Level WARN -Context $tsName -Message ("Task-Sequence-Collection konnte nicht angelegt werden: {0}" -f $_.Exception.Message)
                }
            }
        }

        $imported = 0
        $errors = 0

        foreach ($device in $devices) {
            $deviceName = [string]$device.vm_name
            $deviceOS = [string]$device.vm_os
            $missionName = if ($device.mission) { [string]$device.mission.mission_name } else { '' }

            # DHCP-MAC ermitteln (PXE-NIC)
            $dhcpMacs = @($device.interfaces | Where-Object { $_.mode -eq 'DHCP' } | Select-Object -ExpandProperty mac)
            if ($dhcpMacs.Count -gt 1) {
                Write-VsLog -Level WARN -Context $deviceName -Message ("Mehrere DHCP-Interfaces ({0}) - nutze die erste MAC. Falls PXE ueber eine andere NIC laeuft, Interfaces im Portal pruefen." -f $dhcpMacs.Count)
            }
            $deviceMac = if ($dhcpMacs.Count -gt 0) { ConvertTo-VsNormalizedMac $dhcpMacs[0] } else { $null }

            if ([string]::IsNullOrWhiteSpace($missionName)) {
                Write-VsLog -Level WARN -Context $deviceName -Message ("Mission fehlt (Id {0}) - Device uebersprungen." -f $device.mission_id)
                continue
            }
            if (-not $deviceMac) {
                Write-VsLog -Level WARN -Context $deviceName -Message 'Keine MAC-Adresse - Device uebersprungen.'
                continue
            }

            # Mission-Collection sicherstellen
            if (-not $collectionCache.ContainsKey($missionName)) {
                try {
                    $c = New-VsDeviceCollection -Name $missionName -FolderPath $missionFolder
                    if ($c) { $collectionCache[$missionName] = $c.CollectionID }
                } catch {
                    Write-VsLog -Level WARN -Context $missionName -Message ("Mission-Collection fehlgeschlagen: {0}" -f $_.Exception.Message)
                }
            }

            # MAC-Konflikt (normalisiert) nur melden, nie automatisch handeln
            if ($mecmDevices.ContainsKey($deviceName)) {
                $mecmMac = ConvertTo-VsNormalizedMac ([string]$mecmDevices[$deviceName].MACAddress)
                if ($mecmMac -and $mecmMac -ne $deviceMac) {
                    Write-VsLog -Level WARN -Context $deviceName -Message ("MAC-Konflikt: SCCM={0} ESXi={1} - manuelle Pruefung noetig." -f $mecmMac, $deviceMac)
                }
            }

            # Import (falls neu) - Existenz aus dem Scan-Cache statt Einzelabfrage
            if (-not $mecmDevices.ContainsKey($deviceName)) {
                try {
                    Import-CMComputerInformation -ComputerName $deviceName -MacAddress $deviceMac -CollectionName 'All Systems' -ErrorAction Stop | Out-Null
                    Write-VsLog -Context $deviceName -Message ("Device importiert (MAC {0})." -f $deviceMac)
                    $imported++
                    Start-Sleep -Seconds 2
                } catch {
                    # Kein Fehlertext-Parsing (Texte variieren je SCCM-Version und
                    # -Sprache): Import-Race liegt vor, wenn das Device trotz
                    # Fehler inzwischen existiert - dann normal weitermachen.
                    if (Get-CMDevice -Name $deviceName -Fast -ErrorAction SilentlyContinue) {
                        # Race condition - Device wurde parallel angelegt
                    } else {
                        Write-VsLog -Level ERROR -Context $deviceName -Message ("Import fehlgeschlagen: {0}" -f $_.Exception.Message)
                        $errors++
                        continue
                    }
                }
            }

            # ResourceID: erst aus dem Scan-Cache (Normalfall fuer bestehende
            # Devices), Einzelabfrage nur fuer frisch importierte
            $resourceId = if ($mecmDevices.ContainsKey($deviceName)) { $mecmDevices[$deviceName].ResourceID } else { $null }
            if (-not $resourceId) {
                $resourceId = (Get-CMDevice -Name $deviceName -Fast -ErrorAction SilentlyContinue).ResourceID
            }
            if (-not $resourceId) {
                try { Approve-CMDevice -Name $deviceName -ErrorAction Stop; Start-Sleep -Seconds 5 } catch {
                    Write-VsLog -Level DEBUG -Context $deviceName -Message ("Auto-Approve nicht moeglich: {0}" -f $_.Exception.Message)
                }
                $resourceId = (Get-CMDevice -Name $deviceName -Fast -ErrorAction SilentlyContinue).ResourceID
            }
            if (-not $resourceId) {
                Write-VsLog -Level WARN -Context $deviceName -Message 'Noch keine ResourceID - naechster Scan.'
                continue
            }

            # Collection-Zuweisungen (OS, Pakete, Mission) ueber den Cache
            $targets = New-Object System.Collections.Generic.List[string]
            if ($deviceOS) { $targets.Add($deviceOS) }
            foreach ($pkg in @($device.packages)) { if ($pkg.package_name) { $targets.Add([string]$pkg.package_name) } }
            $targets.Add($missionName)

            foreach ($target in $targets) {
                if (-not $collectionCache.ContainsKey($target)) {
                    Write-VsLog -Level WARN -Context $deviceName -Message ("Collection '{0}' existiert nicht - uebersprungen." -f $target)
                    continue
                }
                $collectionId = $collectionCache[$target]
                $member = Get-CMDeviceCollectionDirectMembershipRule -CollectionId $collectionId -ResourceId $resourceId -ErrorAction SilentlyContinue
                if (-not $member) {
                    try {
                        Add-CMDeviceCollectionDirectMembershipRule -CollectionId $collectionId -ResourceId $resourceId -ErrorAction Stop | Out-Null
                        $collectionsToUpdate[$target] = $true
                    } catch {
                        Write-VsLog -Level ERROR -Context $deviceName -Message ("Zuweisung zu '{0}' fehlgeschlagen: {1}" -f $target, $_.Exception.Message)
                        $errors++
                    }
                }
            }

            # ResourceID zurueckmelden
            try {
                Invoke-VsApi -Config $config -Path '/mecm_updateid.php?action=updateDevice' -Method POST -Body @{
                    deviceName       = $deviceName
                    deviceResourceID = "$resourceId"
                    deviceid         = $device.id
                } | Out-Null
            } catch {
                Write-VsLog -Level WARN -Context $deviceName -Message ("ResourceID-Update fehlgeschlagen: {0}" -f (Get-VsErrorDetail -ErrorRecord $_))
            }
        }

        # Collection-Updates gesammelt anstossen (einmal je geaenderter Collection)
        foreach ($name in $collectionsToUpdate.Keys) {
            try { Invoke-CMCollectionUpdate -Name $name -ErrorAction Stop | Out-Null } catch {
                Write-VsLog -Level WARN -Context $name -Message ("Collection-Update nicht angestossen (Mitgliedschaft greift erst beim naechsten SCCM-Zyklus): {0}" -f $_.Exception.Message)
            }
        }

        if ($imported -gt 0 -or $errors -gt 0) {
            Write-VsLog -Message ("Scan #{0} fertig: {1} importiert, {2} Fehler, {3:N1}s." -f $loop, $imported, $errors, ((Get-Date) - $scanStart).TotalSeconds)
        }
    } catch {
        $consecutiveErrors++
        # Get-VsErrorDetail statt .Exception.Message: bei einem API-Fehler steht der
        # Grund in der JSON-Envelope der WebApp, die Statuszeile sagt nur "400".
        Write-VsLog -Level ERROR -Message ("Scan-Fehler (Versuch {0}): {1}" -f $consecutiveErrors, (Get-VsErrorDetail -ErrorRecord $_))
        if ($consecutiveErrors -ge 3) {
            $siteCode = $null
            Start-Sleep -Seconds 60
        } else {
            Start-Sleep -Seconds 30
        }
        continue
    }

    if ($loop % 100 -eq 0) { [System.GC]::Collect() }
    Start-Sleep -Seconds $intervalSeconds
}
