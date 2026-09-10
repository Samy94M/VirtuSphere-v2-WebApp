#Requires -Version 5.1
# ============================================================================
# client_staticip.ps1 - benennt Netzwerkadapter um und wendet statische
# IP-Konfiguration an, anhand der von client_getinfo geschriebenen
# Interface-Registry. Dritte Phase der Client-Kette.
#
# Verbesserungen:
#  - idempotent: passende Werte bleiben stehen; nur exakt dokumentierte,
#    vorher selbst verwaltete IPv4-Werte werden ersetzt
#  - ehrlicher Status: Erfolg wird pro Adapter getrackt und real gemeldet
#    (Registry VirtuSphere\staticip + reportPhase), nicht mehr hart auf $true
#  - leeres Gateway / leere DNS-Liste sauber via Parameter-Splatting
#  - Rename-Kollisionen abgefangen; Get-CimInstance statt Get-WmiObject
#  - reportPhase 'started' VOR der Umstellung (IP-/Adapterkonfiguration kann den
#    Client danach offline nehmen), 'finished' best effort danach
# ============================================================================

. "$PSScriptRoot\VirtuSphere-Client-Common.ps1"
Initialize-VsClientLog -Component 'staticip'
Write-VsClientLog 'Starte staticip'

$interfacesRoot = Get-VsSnapshotInterfacesRoot
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
if (-not $interfacesRoot -or -not (Test-Path $interfacesRoot)) {
    Write-VsClientLog -Level ERROR 'Keine Interface-Registry (client_getinfo gelaufen?). Abbruch.'
    Set-StaticIpStatus -Success $false -Detail 'no interface registry'
    exit 1
}

$targets = @()
foreach ($entry in @(Get-ChildItem -Path $interfacesRoot)) {
    $mac = ConvertTo-VsNormalizedMac $entry.GetValue('mac')
    $targets += , [pscustomobject]@{
        Mac     = $mac
        Name    = [string]$entry.GetValue('vlan')
        Mode    = [string]$entry.GetValue('mode')
        Ip      = [string]$entry.GetValue('ip')
        Subnet  = [string]$entry.GetValue('subnet')
        Gateway = [string]$entry.GetValue('gateway')
        Dns1    = [string]$entry.GetValue('dns1')
        Dns2    = [string]$entry.GetValue('dns2')
    }
}

# Die Modusnamen sind eine PHP-SSoT: VIRTUSPHERE_INTERFACE_MODES in
# Docker\WebAPI\lib\defaults.php, dort KLEIN geschrieben ('dhcp', 'static').
# Getroffen werden sie hier nur, weil -eq in PowerShell case-insensitiv
# vergleicht; ein spaeterer Wechsel auf -ceq oder eine dritte Modusart braeche
# das lautlos. Ein Pester-Test haelt diese beiden Literale gegen die
# PHP-Konstante.
$modeStatic = 'Static'
$modeDhcp = 'DHCP'

try {
    $adapters = @(Get-NetAdapter -ErrorAction Stop)
    $plan = New-VsClientNetworkPlan -Targets $targets -Adapters $adapters
} catch {
    $plan = [pscustomobject]@{ Valid = $false; Errors = @($_.Exception.Message); Items = @() }
}

if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'staticip' -PhaseEvent 'started' -Detail "$($targets.Count) target(s)" }
if (-not $plan.Valid) {
    foreach ($problem in @($plan.Errors)) { Write-VsClientLog -Level ERROR $problem }
    $detail = 'validation failed: ' + (@($plan.Errors) -join ' | ')
    Set-StaticIpStatus -Success $false -Detail $detail
    if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'staticip' -PhaseEvent 'failed' -Detail $detail }
    exit 1
}

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

function Get-ManagedNetworkState {
    param([string]$Mac)
    $key = Join-Path (Join-Path $statusBase 'staticip\Managed') ($Mac -replace ':', '')
    if (-not (Test-Path -Path $key)) { return $null }
    return (Get-ItemProperty -Path $key -ErrorAction Stop)
}

function Set-ManagedNetworkState {
    param([string]$Mac, [string]$Mode, [string]$Ip = '', [int]$Prefix = 0, [string]$Gateway = '', [string[]]$Dns = @())
    $key = Join-Path (Join-Path $statusBase 'staticip\Managed') ($Mac -replace ':', '')
    New-Item -Path $key -Force -ErrorAction Stop | Out-Null
    foreach ($pair in @(
        @{ Name = 'Mode'; Value = $Mode },
        @{ Name = 'Ip'; Value = $Ip },
        @{ Name = 'Prefix'; Value = [string]$Prefix },
        @{ Name = 'Gateway'; Value = $Gateway },
        @{ Name = 'Dns'; Value = (@($Dns) -join ',') }
    )) {
        New-ItemProperty -Path $key -Name $pair.Name -Value $pair.Value -PropertyType String -Force -ErrorAction Stop | Out-Null
    }
}

function Test-StringArrayEqual {
    param([string[]]$Left = @(), [string[]]$Right = @())
    if (@($Left).Count -ne @($Right).Count) { return $false }
    for ($i = 0; $i -lt @($Left).Count; $i++) {
        if (-not [string]::Equals([string]$Left[$i], [string]$Right[$i], [System.StringComparison]::OrdinalIgnoreCase)) { return $false }
    }
    return $true
}

function Wait-UsableIpv4Address {
    param([int]$InterfaceIndex, [string]$Ip, [int]$Prefix)
    for ($attempt = 0; $attempt -lt 16; $attempt++) {
        $address = @(Get-NetIPAddress -InterfaceIndex $InterfaceIndex -AddressFamily IPv4 -IPAddress $Ip -ErrorAction SilentlyContinue |
            Where-Object { [int]$_.PrefixLength -eq $Prefix })
        if ($address.Count -eq 1) {
            $state = [string]$address[0].AddressState
            if ($state -in @('Duplicate', 'Invalid')) { throw "Adresse $Ip ist im Zustand $state (Adresskonflikt im Netz?)." }
            if ($state -notin @('Tentative')) { return $address[0] }
        } elseif ($address.Count -gt 1) { throw "Adresse $Ip liegt mehrfach auf derselben Schnittstelle." }
        if ($attempt -lt 15) { Start-Sleep -Seconds 1 }
    }
    throw "Adresse $Ip blieb 15 Sekunden Tentative oder wurde nicht sichtbar."
}

foreach ($item in @($plan.Items)) {
    $adapter = $item.Adapter
    $mac = $item.Mac
    $cfg = $item.Target

    $oldName = [string]$adapter.Name
    $renamed = $false
    $addedAddress = $false
    $addedRoute = $false
    $removedAddress = $false
    $removedRoute = $false
    $dhcpChanged = $false
    $dnsChanged = $false
    $oldDns = @()
    $writtenDns = @()
    $oldDhcp = ''
    $previous = $null

    try {
        $previous = Get-ManagedNetworkState -Mac $mac

        # Namenskollisionen wurden fuer den gesamten Plan bereits vor dem ersten
        # Write ausgeschlossen.
        if (-not [string]::IsNullOrWhiteSpace($cfg.Name) -and $adapter.Name -ne $cfg.Name) {
            Rename-NetAdapter -InputObject $adapter -NewName $cfg.Name -Confirm:$false -ErrorAction Stop
            $renamed = $true
        }

        if ($item.Mode -eq $modeStatic) {
            $prefix = [int]$item.Prefix
            $ipInterface = Get-NetIPInterface -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -ErrorAction Stop
            $oldDhcp = [string]$ipInterface.Dhcp
            $oldDns = @((Get-DnsClientServerAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -ErrorAction Stop).ServerAddresses)

            # Nur eine frueher von VirtuSphere bestaetigte Adresse/Route darf
            # entfernt werden. IPv6 und unbekannte manuelle Werte bleiben stehen.
            if ($previous -and [string]$previous.Mode -eq 'static' -and [string]$previous.Ip -and
                ([string]$previous.Ip -ne [string]$cfg.Ip -or [int]$previous.Prefix -ne $prefix)) {
                $oldAddress = @(Get-NetIPAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -IPAddress ([string]$previous.Ip) -ErrorAction SilentlyContinue |
                    Where-Object { [int]$_.PrefixLength -eq [int]$previous.Prefix })
                if ($oldAddress.Count -gt 0) {
                    Remove-NetIPAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -IPAddress ([string]$previous.Ip) -Confirm:$false -ErrorAction Stop
                    $removedAddress = $true
                }
            }
            if ($previous -and [string]$previous.Gateway -and [string]$previous.Gateway -ne [string]$cfg.Gateway) {
                $oldRoute = @(Get-NetRoute -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -NextHop ([string]$previous.Gateway) -ErrorAction SilentlyContinue)
                if ($oldRoute.Count -gt 0) {
                    Remove-NetRoute -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -NextHop ([string]$previous.Gateway) -Confirm:$false -ErrorAction Stop
                    $removedRoute = $true
                }
            }
            if ($ipInterface.Dhcp -ne 'Disabled') {
                Set-NetIPInterface -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -Dhcp Disabled -ErrorAction Stop
                $dhcpChanged = $true
            }

            $desiredAddress = @(Get-NetIPAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -IPAddress $cfg.Ip -ErrorAction SilentlyContinue)
            if ($desiredAddress.Count -gt 0 -and @($desiredAddress | Where-Object { [int]$_.PrefixLength -ne $prefix }).Count -gt 0) {
                throw "Adresse $($cfg.Ip) ist bereits mit einem anderen Praefix vorhanden; fremder Wert bleibt erhalten."
            }
            if (@($desiredAddress | Where-Object { [int]$_.PrefixLength -eq $prefix }).Count -eq 0) {
                New-NetIPAddress -InterfaceIndex $adapter.ifIndex -IPAddress $cfg.Ip -PrefixLength $prefix -Confirm:$false -ErrorAction Stop | Out-Null
                $addedAddress = $true
            }

            if (-not [string]::IsNullOrWhiteSpace($cfg.Gateway)) {
                $desiredRoute = @(Get-NetRoute -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -NextHop $cfg.Gateway -ErrorAction SilentlyContinue)
                if ($desiredRoute.Count -eq 0) {
                    New-NetRoute -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -NextHop $cfg.Gateway -ErrorAction Stop | Out-Null
                    $addedRoute = $true
                }
            }

            # Leeres DNS bedeutet fuer statische Ziele bewusst "bestehenden
            # DNS-Stand erhalten". Ein nichtleeres Soll ersetzt ihn exakt.
            if ($item.Dns.Count -gt 0 -and -not (Test-StringArrayEqual -Left $oldDns -Right $item.Dns)) {
                Set-DnsClientServerAddress -InterfaceIndex $adapter.ifIndex -ServerAddresses $item.Dns -ErrorAction Stop
                $dnsChanged = $true
                $writtenDns = @($item.Dns)
            }

            [void](Wait-UsableIpv4Address -InterfaceIndex $adapter.ifIndex -Ip $cfg.Ip -Prefix $prefix)
            $liveInterface = Get-NetIPInterface -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -ErrorAction Stop
            if ($liveInterface.Dhcp -ne 'Disabled') { throw "Schnittstelle steht nach der Umstellung weiterhin auf Dhcp=$($liveInterface.Dhcp)." }
            if ($cfg.Gateway -and @(Get-NetRoute -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -NextHop $cfg.Gateway -ErrorAction SilentlyContinue).Count -eq 0) {
                throw "Gateway $($cfg.Gateway) fehlt nach der Umstellung."
            }
            if ($item.Dns.Count -gt 0) {
                $liveDns = @((Get-DnsClientServerAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -ErrorAction Stop).ServerAddresses)
                if (-not (Test-StringArrayEqual -Left $liveDns -Right $item.Dns)) { throw 'DNS-Server entsprechen nach der Umstellung nicht dem Soll.' }
            }
            Set-ManagedNetworkState -Mac $mac -Mode 'static' -Ip $cfg.Ip -Prefix $prefix -Gateway $cfg.Gateway -Dns $item.Dns
            Write-VsClientLog "Adapter '$($cfg.Name)' ($mac), Ziel $modeStatic : IP $($cfg.Ip)/$prefix gesetzt und geprueft."
            $appliedStatic++
            $applied++
        } elseif ($item.Mode -eq $modeDhcp) {
            # Der Client spricht moeglicherweise ueber genau diese Karte, und die
            # Umstellung kann die Verbindung kappen. Das ist gedeckt: 'started'
            # geht vor der ersten IP-/Adapterkonfiguration raus, die terminale
            # Meldung ist best effort.
            #
            # Idempotent: erst nachsehen, dann nur bei Bedarf umstellen. Danach
            # in jedem Fall nachlesen statt annehmen, wie im statischen Zweig.
            $ipInterface = Get-NetIPInterface -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -ErrorAction Stop
            $oldDhcp = [string]$ipInterface.Dhcp
            $oldDns = @((Get-DnsClientServerAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -ErrorAction Stop).ServerAddresses)
            if ($previous -and [string]$previous.Mode -eq 'static' -and [string]$previous.Ip) {
                $oldAddress = @(Get-NetIPAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -IPAddress ([string]$previous.Ip) -ErrorAction SilentlyContinue |
                    Where-Object { [int]$_.PrefixLength -eq [int]$previous.Prefix })
                if ($oldAddress.Count -gt 0) {
                    Remove-NetIPAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -IPAddress ([string]$previous.Ip) -Confirm:$false -ErrorAction Stop
                    $removedAddress = $true
                }
                if ([string]$previous.Gateway) {
                    $oldRoute = @(Get-NetRoute -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -NextHop ([string]$previous.Gateway) -ErrorAction SilentlyContinue)
                    if ($oldRoute.Count -gt 0) {
                        Remove-NetRoute -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -NextHop ([string]$previous.Gateway) -Confirm:$false -ErrorAction Stop
                        $removedRoute = $true
                    }
                }
            }
            if ($ipInterface.Dhcp -ne 'Enabled') {
                Set-NetIPInterface -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -Dhcp Enabled -ErrorAction Stop
                $dhcpChanged = $true
            }
            # DNS ebenfalls zurueck an DHCP: eine haendisch gesetzte
            # Serveradresse ueberlebt die Umstellung sonst und zeigt weiter ins
            # alte Netz. Das Gateway kommt vom DHCP-Server und wird nicht aus
            # der statischen Soll-Gatewayregel abgeleitet.
            Set-DnsClientServerAddress -InterfaceIndex $adapter.ifIndex -ResetServerAddresses -ErrorAction Stop
            $dnsChanged = $true
            $writtenDns = @((Get-DnsClientServerAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -ErrorAction Stop).ServerAddresses)

            $liveInterface = Get-NetIPInterface -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -ErrorAction Stop
            if ($liveInterface.Dhcp -ne 'Enabled') {
                throw "Schnittstelle steht nach der Umstellung weiterhin auf Dhcp=$($liveInterface.Dhcp)."
            }
            Set-ManagedNetworkState -Mac $mac -Mode 'dhcp'
            Write-VsClientLog "Adapter '$($cfg.Name)' ($mac), Ziel $modeDhcp : auf DHCP zurueckgestellt und geprueft."
            $appliedDhcp++
            $applied++
        }
    } catch {
        $failed++
        Write-VsClientLog -Level ERROR "Adapter $mac fehlgeschlagen: $($_.Exception.Message)"

        # Best effort in umgekehrter Reihenfolge. Jeder Rueckfall greift nur,
        # wenn der aktuelle Wert noch dem in diesem Lauf gesetzten Wert
        # entspricht; zwischenzeitliche Fremdaenderungen werden nicht entfernt.
        try {
            if ($dnsChanged) {
                $currentDns = @((Get-DnsClientServerAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -ErrorAction Stop).ServerAddresses)
                if (Test-StringArrayEqual -Left $currentDns -Right $writtenDns) {
                    if ($oldDns.Count -gt 0) { Set-DnsClientServerAddress -InterfaceIndex $adapter.ifIndex -ServerAddresses $oldDns -ErrorAction Stop }
                    else { Set-DnsClientServerAddress -InterfaceIndex $adapter.ifIndex -ResetServerAddresses -ErrorAction Stop }
                }
            }
            if ($addedRoute -and @(Get-NetRoute -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -NextHop $cfg.Gateway -ErrorAction SilentlyContinue).Count -gt 0) {
                Remove-NetRoute -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -NextHop $cfg.Gateway -Confirm:$false -ErrorAction Stop
            }
            if ($addedAddress -and @(Get-NetIPAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -IPAddress $cfg.Ip -ErrorAction SilentlyContinue).Count -gt 0) {
                Remove-NetIPAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -IPAddress $cfg.Ip -Confirm:$false -ErrorAction Stop
            }
            if ($removedAddress -and $previous -and @(Get-NetIPAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -IPAddress ([string]$previous.Ip) -ErrorAction SilentlyContinue).Count -eq 0) {
                New-NetIPAddress -InterfaceIndex $adapter.ifIndex -IPAddress ([string]$previous.Ip) -PrefixLength ([int]$previous.Prefix) -Confirm:$false -ErrorAction Stop | Out-Null
            }
            if ($removedRoute -and $previous -and [string]$previous.Gateway -and @(Get-NetRoute -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -NextHop ([string]$previous.Gateway) -ErrorAction SilentlyContinue).Count -eq 0) {
                New-NetRoute -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -NextHop ([string]$previous.Gateway) -ErrorAction Stop | Out-Null
            }
            if ($dhcpChanged) {
                $currentDhcp = [string](Get-NetIPInterface -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -ErrorAction Stop).Dhcp
                $ourDhcp = if ($item.Mode -eq $modeStatic) { 'Disabled' } else { 'Enabled' }
                if ($currentDhcp -eq $ourDhcp -and $oldDhcp -in @('Enabled', 'Disabled')) {
                    Set-NetIPInterface -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -Dhcp $oldDhcp -ErrorAction Stop
                }
            }
            if ($renamed) {
                $currentAdapter = Get-NetAdapter -InterfaceIndex $adapter.ifIndex -ErrorAction Stop
                if ([string]$currentAdapter.Name -eq [string]$cfg.Name) {
                    Rename-NetAdapter -InputObject $currentAdapter -NewName $oldName -Confirm:$false -ErrorAction Stop
                }
            }
        } catch {
            Write-VsClientLog -Level WARN "Begrenzter Rueckfall fuer Adapter $mac unvollstaendig: $($_.Exception.Message)"
        }
    }
}

# Null konfigurierte Adapter ist ein FEHLSCHLAG, kein Erfolg. Vorher galt
# `$failed -eq 0`, und bei keiner passenden Karte war beides 0: das Skript meldete
# Erfolg fuer null geleistete Arbeit, die MECM-Erkennung war erfuellt, und die VM
# blieb mit DHCP oder ohne Adresse in einer gruenen Phase zurueck. Die
# Registry-Konfiguration nennt Ziele; findet sich zu keinem davon eine Karte,
# stimmt eine Annahme nicht (MAC-Abweichung oder Karte nicht Up), und
# das muss jemand sehen.
$success = ($failed -eq 0 -and $applied -eq $targets.Count -and $targets.Count -gt 0)
# Die Modusverteilung steht mit im Detail, damit die Portalkarte "3 Ziele, 2
# statisch, 1 DHCP" zeigen kann statt nur einer Zahl ohne Aussage.
$detail = "applied={0} (static={1} dhcp={2}) failed={3} targets={4}" -f $applied, $appliedStatic, $appliedDhcp, $failed, $targets.Count
if ($applied -eq 0 -and $failed -eq 0) {
    $detail += ' (no matching adapter)'
    Write-VsClientLog -Level ERROR "Keiner der $($targets.Count) konfigurierten Adapter wurde gefunden: MAC-Adressen, Adapterstatus und VLAN pruefen."
}
Set-StaticIpStatus -Success $success -Detail $detail
if ($reportMac) {
    # Nicht $event: das ist eine automatische PowerShell-Variable.
    $phaseEvent = if ($success) { 'finished' } else { 'failed' }
    Send-VsPhase -Mac $reportMac -Phase 'staticip' -PhaseEvent $phaseEvent -Detail $detail
}

if ($success) { Write-VsClientLog "Fertig: $applied Adapter konfiguriert." ; exit 0 }
Write-VsClientLog -Level ERROR "Mit Fehlern beendet: $applied ok, $failed Fehler." ; exit 1
