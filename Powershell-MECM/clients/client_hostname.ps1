#Requires -Version 5.1
# ============================================================================
# client_hostname.ps1 - liest vm_hostname aus der Registry und benennt den
# Computer um (nur Workgroup-Computer). Zweite Phase der Client-Kette.
#
# Verbesserungen:
#  - reportPhase (finished wird VOR dem Reboot gesendet)
#  - Sanitisierung bleibt als Sicherheitsnetz, weicht der bereinigte Name aber
#    vom Soll ab, wird das als 'failed' gemeldet (nach Portal-E2 sollte das
#    Portal solche Namen gar nicht mehr liefern)
#  - die dreifach duplizierten SCCM-Erkennungs-Registry-Bloecke in eine
#    Funktion gezogen; Get-CimInstance statt Get-WmiObject
#
# Exit-Codes: 0 = kein Neustart noetig, 1641 = Neustart eingeleitet, 1 = Fehler
# SCCM-Erkennungsregel: HKLM:\SOFTWARE\aplw-cgn\HostnameUpdate\Status = Erfolgreich
# ============================================================================

. "$PSScriptRoot\VirtuSphere-Client-Common.ps1"
Initialize-VsClientLog -Component 'hostname'
Write-VsClientLog 'Starte Hostname-Update'

$registryBase = 'HKLM:\SOFTWARE\VirtuSphere'
$detectionPath = 'HKLM:\SOFTWARE\aplw-cgn\HostnameUpdate'
$reportMac = Get-VsReportMac

function Set-SccmDetection {
    param([hashtable]$Values)
    try {
        if (-not (Test-Path $detectionPath)) { New-Item -Path $detectionPath -Force | Out-Null }
        # Kein -Type: ein String-Value wird ohnehin REG_SZ, und -Type ist ein
        # dynamischer Provider-Parameter, den PSUseCompatibleCommands (5.1-
        # Profil) nicht aufloesen kann.
        Set-ItemProperty -Path $detectionPath -Name 'LastUpdate' -Value (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
        Set-ItemProperty -Path $detectionPath -Name 'Version' -Value '2.0'
        foreach ($key in $Values.Keys) { Set-ItemProperty -Path $detectionPath -Name $key -Value ([string]$Values[$key]) }
    } catch {
        Write-VsClientLog -Level WARN "SCCM-Erkennungs-Registry fehlgeschlagen: $($_.Exception.Message)"
    }
}

try {
    $current = $env:COMPUTERNAME
    Write-VsClientLog "Aktueller Hostname: $current"

    # Domain-Computer werden uebersprungen
    $cs = Get-CimInstance -ClassName Win32_ComputerSystem
    if ($cs.PartOfDomain) {
        Write-VsClientLog "Domain-Computer ($($cs.Domain)) - keine Umbenennung."
        Set-SccmDetection -Values @{ Status = 'Uebersprungen'; Hostname = $current; Reason = 'Domain-Computer' }
        exit 0
    }

    # Soll-Hostname aus Registry (bis zu 60s auf getinfo warten)
    $newHostname = $null
    for ($waited = 0; $waited -lt 60; $waited += 5) {
        try {
            $val = (Get-ItemProperty -Path $registryBase -Name 'vm_hostname' -ErrorAction Stop).vm_hostname
            if (-not [string]::IsNullOrWhiteSpace($val)) { $newHostname = [string]$val; break }
        } catch { Write-Debug $_ }
        Start-Sleep -Seconds 5
    }
    if ([string]::IsNullOrWhiteSpace($newHostname)) {
        Write-VsClientLog -Level ERROR 'vm_hostname in Registry nicht verfuegbar (getinfo gelaufen?).'
        exit 1
    }

    # Sicherheitsnetz: NetBIOS-Grenzen. Weicht das Ergebnis ab -> melden.
    $sanitized = ($newHostname -replace '[^a-zA-Z0-9\-]', '')
    if ($sanitized.Length -gt 15) { $sanitized = $sanitized.Substring(0, 15) }
    if ($sanitized -ne $newHostname) {
        Write-VsClientLog -Level WARN "Soll-Hostname '$newHostname' verletzt NetBIOS-Regeln, verwende '$sanitized'."
        if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'hostname' -PhaseEvent 'failed' -Detail "sanitized '$newHostname' -> '$sanitized'" }
    }
    $newHostname = $sanitized

    if ($current -eq $newHostname) {
        Write-VsClientLog 'Hostname bereits korrekt.'
        Set-SccmDetection -Values @{ Status = 'Erfolgreich'; Hostname = $current; Reason = 'Bereits korrekt' }
        if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'hostname' -PhaseEvent 'finished' -Detail 'already correct' }
        exit 0
    }

    if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'hostname' -PhaseEvent 'started' -Detail "-> $newHostname" }
    Rename-Computer -NewName $newHostname -Force -ErrorAction Stop
    Write-VsClientLog "Umbenannt: '$current' -> '$newHostname'."
    Set-SccmDetection -Values @{ Status = 'Erfolgreich'; OldHostname = $current; NewHostname = $newHostname }

    # finished VOR dem Reboot melden (danach ist der Client evtl. offline).
    if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'hostname' -PhaseEvent 'finished' -Detail "renamed to $newHostname" }

    Write-VsClientLog 'Neustart in 60s.'
    Start-Process -FilePath 'cmd.exe' -ArgumentList '/c "timeout /t 60 /nobreak && shutdown -r -f -t 0"' -WindowStyle Hidden
    exit 1641
} catch {
    Write-VsClientLog -Level ERROR "Unerwarteter Fehler: $($_.Exception.Message)"
    if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'hostname' -PhaseEvent 'failed' -Detail $_.Exception.Message }
    exit 1
}
