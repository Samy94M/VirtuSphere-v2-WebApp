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

# Die Modusnamen sind eine PHP-SSoT: VIRTUSPHERE_INTERFACE_MODES in
# Docker\WebAPI\lib\defaults.php, dort KLEIN geschrieben ('dhcp', 'static').
# Getroffen werden sie hier nur, weil -eq in PowerShell case-insensitiv
# vergleicht; ein spaeterer Wechsel auf -ceq oder eine dritte Modusart braeche
# das lautlos. Ein Pester-Test haelt diese beiden Literale gegen die
# PHP-Konstante.
$modeStatic = 'Static'
$modeDhcp = 'DHCP'

# $applied zaehlt nur Adapter, deren Sollzustand danach VERIFIZIERT wurde. Ein
# Interface mit einem anderen Modus als 'Static' durchlief den Block vorher ohne
# jede Aktion und erhoehte den Zaehler trotzdem: eine Karte, die vorher statisch
# war und laut Portal jetzt DHCP sein soll, behielt ihre alte Adresse, und der
# Lauf meldete Erfolg. Genau der Fehlertyp, gegen den der Kommentar am Ende
# dieser Datei ausdruecklich argumentiert.
$applied = 0
$failed = 0
$appliedStatic = 0
$appliedDhcp = 0
# Eine Standardroute pro VM, nicht eine pro statischer Schnittstelle. Zwei
# Default-Gateways auf einer Maschine sind kein Ausfall, aber eine Wette: Windows
# waehlt nach Metrik, und welche Schnittstelle den Verkehr traegt, haengt dann an
# Werten, die niemand hier gesetzt hat. Die erste statische Karte mit Gateway
# gewinnt, die naechsten bekommen ihre Adresse ohne Route und sagen das.
$gatewaySet = $false

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

        if ($cfg.Mode -eq $modeStatic) {
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
            if (-not [string]::IsNullOrWhiteSpace($cfg.Gateway)) {
                if ($gatewaySet) {
                    Write-VsClientLog -Level WARN "Adapter '$($cfg.Name)': Gateway $($cfg.Gateway) uebersprungen, diese VM hat schon eine Standardroute."
                } else {
                    $ipParams['DefaultGateway'] = $cfg.Gateway
                }
            }
            New-NetIPAddress @ipParams | Out-Null
            if ($ipParams.ContainsKey('DefaultGateway')) { $gatewaySet = $true }

            $dns = @($cfg.Dns1, $cfg.Dns2) | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
            if ($dns.Count -gt 0) {
                Set-DnsClientServerAddress -InterfaceIndex $adapter.ifIndex -ServerAddresses $dns -ErrorAction Stop
            }

            # Nachlesen statt annehmen. New-NetIPAddress kann zurueckkommen, ohne
            # dass die Adresse traegt (DHCP schreibt sie kurz darauf zurueck, ein
            # Duplikat setzt sie auf "Tentative"/"Duplicate"). Ohne diese Pruefung
            # meldete die Phase Erfolg und die VM war danach nicht erreichbar -
            # unter gruener Phase, also niemandes Aufgabe.
            $liveAddress = Get-NetIPAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue |
                Where-Object { $_.IPAddress -eq $cfg.Ip }
            if (-not $liveAddress) {
                throw "Adresse $($cfg.Ip) liegt nach dem Setzen nicht auf der Schnittstelle."
            }
            if ($liveAddress.AddressState -in @('Duplicate', 'Invalid')) {
                throw "Adresse $($cfg.Ip) ist im Zustand $($liveAddress.AddressState) (Adresskonflikt im Netz?)."
            }
            Write-VsClientLog "Adapter '$($cfg.Name)' ($mac), Ziel $modeStatic : IP $($cfg.Ip)/$prefix gesetzt und geprueft."
            $appliedStatic++
            $applied++
        } elseif ($cfg.Mode -eq $modeDhcp) {
            # Der Client spricht moeglicherweise ueber genau diese Karte, und die
            # Umstellung kann die Verbindung kappen. Das ist gedeckt: 'started'
            # geht vor der ersten Umstellung raus (wie beim VLAN-Wechsel), die
            # terminale Meldung ist best effort.
            #
            # Idempotent: erst nachsehen, dann nur bei Bedarf umstellen. Danach
            # in jedem Fall nachlesen statt annehmen, wie im statischen Zweig.
            $ipInterface = Get-NetIPInterface -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -ErrorAction Stop
            if ($ipInterface.Dhcp -ne 'Enabled') {
                # Die statische Adresse und ihre Standardroute muessen weg, sonst
                # bleibt die alte Adresse neben der geleasten liegen.
                Remove-NetIPAddress -InterfaceIndex $adapter.ifIndex -Confirm:$false -ErrorAction SilentlyContinue
                Remove-NetRoute -InterfaceIndex $adapter.ifIndex -DestinationPrefix '0.0.0.0/0' -Confirm:$false -ErrorAction SilentlyContinue
                Set-NetIPInterface -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -Dhcp Enabled -ErrorAction Stop
            }
            # DNS ebenfalls zurueck an DHCP: eine haendisch gesetzte
            # Serveradresse ueberlebt die Umstellung sonst und zeigt weiter ins
            # alte VLAN. Das Gateway kommt vom DHCP-Server, $gatewaySet bleibt
            # deshalb unberuehrt.
            Set-DnsClientServerAddress -InterfaceIndex $adapter.ifIndex -ResetServerAddresses -ErrorAction Stop

            $liveInterface = Get-NetIPInterface -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -ErrorAction Stop
            if ($liveInterface.Dhcp -ne 'Enabled') {
                throw "Schnittstelle steht nach der Umstellung weiterhin auf Dhcp=$($liveInterface.Dhcp)."
            }
            Write-VsClientLog "Adapter '$($cfg.Name)' ($mac), Ziel $modeDhcp : auf DHCP zurueckgestellt und geprueft."
            $appliedDhcp++
            $applied++
        } else {
            # Weder Static noch DHCP: die Registry traegt einen Wert, den dieses
            # Skript nicht kennt. Frueher lief der Adapter hier ohne jede Aktion
            # durch und wurde trotzdem als erfolgreich gezaehlt.
            throw "unbekannter Modus '$($cfg.Mode)' (erwartet: $modeStatic oder $modeDhcp)"
        }
    } catch {
        $failed++
        Write-VsClientLog -Level ERROR "Adapter $mac fehlgeschlagen: $($_.Exception.Message)"
    }
}

# Null konfigurierte Adapter ist ein FEHLSCHLAG, kein Erfolg. Vorher galt
# `$failed -eq 0`, und bei keiner passenden Karte war beides 0: das Skript meldete
# Erfolg fuer null geleistete Arbeit, die MECM-Erkennung war erfuellt, und die VM
# blieb mit DHCP oder ohne Adresse in einer gruenen Phase zurueck. Die
# Registry-Konfiguration nennt Ziele; findet sich zu keinem davon eine Karte,
# stimmt eine Annahme nicht (MAC-Abweichung, Karte nicht Up, falsches VLAN), und
# das muss jemand sehen.
$success = ($failed -eq 0 -and $applied -gt 0)
# Die Modusverteilung steht mit im Detail, damit die Portalkarte "3 Ziele, 2
# statisch, 1 DHCP" zeigen kann statt nur einer Zahl ohne Aussage.
$detail = "applied={0} (static={1} dhcp={2}) failed={3} targets={4}" -f $applied, $appliedStatic, $appliedDhcp, $failed, $configByMac.Count
if ($applied -eq 0 -and $failed -eq 0) {
    $detail += ' (no matching adapter)'
    Write-VsClientLog -Level ERROR "Keiner der $($configByMac.Count) konfigurierten Adapter wurde gefunden: MAC-Adressen, Adapterstatus und VLAN pruefen."
}
Set-StaticIpStatus -Success $success -Detail $detail
if ($reportMac) {
    # Nicht $event: das ist eine automatische PowerShell-Variable.
    $phaseEvent = if ($success) { 'finished' } else { 'failed' }
    Send-VsPhase -Mac $reportMac -Phase 'staticip' -PhaseEvent $phaseEvent -Detail $detail
}

if ($success) { Write-VsClientLog "Fertig: $applied Adapter konfiguriert." ; exit 0 }
Write-VsClientLog -Level ERROR "Mit Fehlern beendet: $applied ok, $failed Fehler." ; exit 1
