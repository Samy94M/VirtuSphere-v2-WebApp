#Requires -Version 5.1
# ============================================================================
# client_staticip.ps1 - benennt Netzwerkadapter um und wendet statische
# IP-Konfiguration an, anhand der von client_getinfo geschriebenen
# Interface-Registry. Dritte Phase der Client-Kette.
#
# Verbesserungen:
#  - idempotent: vorhandene IP/Gateway werden vor dem Setzen entfernt, sodass
#    ein Re-Run nicht mehr an "bereits vorhanden" stirbt (try/catch je Adapter)
#  - ehrlicher Status: Erfolg wird pro Adapter getrackt und real gemeldet
#    (Registry VirtuSphere\staticip + reportPhase), nicht mehr hart auf $true
#  - leeres Gateway / leere DNS-Liste sauber via Parameter-Splatting
#  - Rename-Kollisionen abgefangen; Get-CimInstance statt Get-WmiObject
#  - reportPhase 'started' VOR der Umstellung (VLAN-Wechsel kann den Client
#    danach offline nehmen), 'finished' best effort danach
# ============================================================================

. "$PSScriptRoot\VirtuSphere-Client-Common.ps1"
Initialize-VsClientLog -Component 'staticip'
Write-VsClientLog 'Starte staticip'

$interfacesRoot = 'HKLM:\SOFTWARE\VirtuSphere\Interfaces'
$statusBase = 'HKLM:\SOFTWARE\VirtuSphere'
$reportMac = Get-VsReportMac

function Set-StaticIpStatus {
    param([bool]$Success, [string]$Detail = '')
    try {
        $path = Join-Path $statusBase 'staticip'
        New-Item -Path $path -Force | Out-Null
        New-ItemProperty -Path $path -Name 'installed' -Value ([int]$Success) -PropertyType DWORD -Force | Out-Null
        $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
        if ($Success) {
            New-ItemProperty -Path $path -Name 'installdate' -Value $stamp -PropertyType String -Force | Out-Null
        } else {
            $count = (@(Get-Item -Path $path).Property | Where-Object { $_ -match '^installfaildate' }).Count + 1
            New-ItemProperty -Path $path -Name ("installfaildate[{0}]" -f $count) -Value $stamp -PropertyType String -Force | Out-Null
        }
        if ($Detail) { New-ItemProperty -Path $path -Name 'lastDetail' -Value $Detail -PropertyType String -Force | Out-Null }
    } catch {
        Write-VsClientLog -Level WARN "Status-Registry fehlgeschlagen: $($_.Exception.Message)"
    }
}

# Convert-VsSubnetMaskToPrefix liegt in VirtuSphere-Client-Common.ps1 (dort
# getestet: Pester deckt Praefix-Grenzen und nicht zusammenhaengende Masken ab).

# --- Interface-Konfiguration aus Registry laden -----------------------------
if (-not (Test-Path $interfacesRoot)) {
    Write-VsClientLog -Level ERROR 'Keine Interface-Registry (client_getinfo gelaufen?). Abbruch.'
    Set-StaticIpStatus -Success $false -Detail 'no interface registry'
    exit 1
}

$configByMac = @{}
foreach ($entry in @(Get-ChildItem -Path $interfacesRoot)) {
    $mac = ConvertTo-VsNormalizedMac $entry.GetValue('mac')
    if (-not $mac) { continue }
    $configByMac[$mac] = [pscustomobject]@{
        Name    = [string]$entry.GetValue('vlan')
        Mode    = [string]$entry.GetValue('mode')
        Ip      = [string]$entry.GetValue('ip')
        Subnet  = [string]$entry.GetValue('subnet')
        Gateway = [string]$entry.GetValue('gateway')
        Dns1    = [string]$entry.GetValue('dns1')
        Dns2    = [string]$entry.GetValue('dns2')
    }
}

if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'staticip' -PhaseEvent 'started' -Detail "$($configByMac.Count) target(s)" }

$applied = 0
$failed = 0

foreach ($adapter in @(Get-NetAdapter | Where-Object { $_.Status -eq 'Up' -and $_.PhysicalMediaType -ne 'Wireless' })) {
    $mac = ConvertTo-VsNormalizedMac $adapter.MacAddress
    if (-not $mac -or -not $configByMac.ContainsKey($mac)) { continue }
    $cfg = $configByMac[$mac]

    try {
        # Adapter umbenennen (Kollision abfangen)
        if (-not [string]::IsNullOrWhiteSpace($cfg.Name) -and $adapter.Name -ne $cfg.Name) {
            $clash = Get-NetAdapter -Name $cfg.Name -ErrorAction SilentlyContinue
            if ($clash -and $clash.ifIndex -ne $adapter.ifIndex) {
                Write-VsClientLog -Level WARN "Adaptername '$($cfg.Name)' bereits vergeben - Umbenennung uebersprungen."
            } else {
                Rename-NetAdapter -InputObject $adapter -NewName $cfg.Name -Confirm:$false -ErrorAction Stop
            }
        }

        if ($cfg.Mode -eq 'Static') {
            $prefix = Convert-VsSubnetMaskToPrefix $cfg.Subnet
            # -eq $null statt -not: /0 ist eine gueltige Praefixlaenge und faellt
            # sonst faelschlich in den Fehlerzweig.
            if ($null -eq $prefix) { throw "ungueltige Subnetzmaske '$($cfg.Subnet)'" }

            # Idempotenz: bestehende IP/Gateway entfernen, Fehler ignorieren
            Remove-NetIPAddress -InterfaceIndex $adapter.ifIndex -Confirm:$false -ErrorAction SilentlyContinue
            Remove-NetRoute -InterfaceIndex $adapter.ifIndex -DestinationPrefix '0.0.0.0/0' -Confirm:$false -ErrorAction SilentlyContinue

            $ipParams = @{
                InterfaceIndex = $adapter.ifIndex
                IPAddress      = $cfg.Ip
                PrefixLength   = $prefix
                Confirm        = $false
                ErrorAction    = 'Stop'
            }
            if (-not [string]::IsNullOrWhiteSpace($cfg.Gateway)) { $ipParams['DefaultGateway'] = $cfg.Gateway }
            New-NetIPAddress @ipParams | Out-Null

            $dns = @($cfg.Dns1, $cfg.Dns2) | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
            if ($dns.Count -gt 0) {
                Set-DnsClientServerAddress -InterfaceIndex $adapter.ifIndex -ServerAddresses $dns -ErrorAction Stop
            }
            Write-VsClientLog "Statische IP $($cfg.Ip)/$prefix auf '$($cfg.Name)' gesetzt."
        }
        $applied++
    } catch {
        $failed++
        Write-VsClientLog -Level ERROR "Adapter $mac fehlgeschlagen: $($_.Exception.Message)"
    }
}

$success = ($failed -eq 0)
Set-StaticIpStatus -Success $success -Detail ("applied={0} failed={1}" -f $applied, $failed)
if ($reportMac) {
    # Nicht $event: das ist eine automatische PowerShell-Variable.
    $phaseEvent = if ($success) { 'finished' } else { 'failed' }
    Send-VsPhase -Mac $reportMac -Phase 'staticip' -PhaseEvent $phaseEvent -Detail ("applied={0} failed={1}" -f $applied, $failed)
}

if ($success) { Write-VsClientLog "Fertig: $applied Adapter konfiguriert." ; exit 0 }
Write-VsClientLog -Level ERROR "Mit Fehlern beendet: $applied ok, $failed Fehler." ; exit 1
