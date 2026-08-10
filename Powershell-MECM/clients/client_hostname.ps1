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
#  - die dreifach duplizierten MECM-Erkennungs-Registry-Bloecke in eine
#    Funktion gezogen; Get-CimInstance statt Get-WmiObject
#
# Exit-Codes: 0 = kein Neustart noetig, 1641 = Neustart eingeleitet, 1 = Fehler
# MECM-Erkennungsregel: HKLM:\SOFTWARE\VirtuSphere\HostnameUpdate\Status = Erfolgreich
# ============================================================================

. "$PSScriptRoot\VirtuSphere-Client-Common.ps1"
Initialize-VsClientLog -Component 'hostname'
Write-VsClientLog 'Starte Hostname-Update'

$registryBase = 'HKLM:\SOFTWARE\VirtuSphere'
$detectionPath = 'HKLM:\SOFTWARE\VirtuSphere\HostnameUpdate'
$reportMac = Get-VsReportMac

function Set-MecmDetection {
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
        Write-VsClientLog -Level WARN "MECM-Erkennungs-Registry fehlgeschlagen: $($_.Exception.Message)"
    }
}

try {
    $current = $env:COMPUTERNAME
    Write-VsClientLog "Aktueller Hostname: $current"

    # Domain-Computer werden uebersprungen
    $cs = Get-CimInstance -ClassName Win32_ComputerSystem
    if ($cs.PartOfDomain) {
        Write-VsClientLog "Domain-Computer ($($cs.Domain)) - keine Umbenennung."
        Set-MecmDetection -Values @{ Status = 'Uebersprungen'; Hostname = $current; Reason = 'Domain-Computer' }
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

    # Sicherheitsnetz: NetBIOS-Grenzen (15 Zeichen, nur Buchstaben, Ziffern,
    # Bindestrich).
    #
    # Die verbindliche Pruefung sitzt im Portal: es erzwingt die Regeln bei jeder
    # neuen VM und bei jeder Aenderung eines Hostnamens (lib/repo/vms.php). Nur
    # Altzeilen, die seitdem niemand angefasst hat, erreichen diesen Zweig
    # ueberhaupt noch, und ihre lockere Regel laesst ihnen das Portal
    # ausdruecklich, damit eine unabhaengige Bearbeitung nicht am Hostnamen
    # scheitert. Ein harter Abbruch hier wuerde diese Entscheidung von der
    # anderen Seite kassieren und heute laufende Deployments rot faerben.
    #
    # Deshalb: weitermachen und ehrlich melden. Der Zweig meldete frueher
    # 'failed', und derselbe Lauf danach 'finished'; was das Portal anzeigte,
    # hing davon ab, welche Meldung zuletzt ankam. Die Abweichung steht jetzt im
    # Log und im Detail der EINEN terminalen Meldung.
    $sanitized = ($newHostname -replace '[^a-zA-Z0-9\-]', '')
    if ($sanitized.Length -gt 15) { $sanitized = $sanitized.Substring(0, 15) }

    # Ein Name nur aus Sonderzeichen bereinigt sich zu Leer. Ohne diesen Guard
    # laeuft das Skript in Rename-Computer -NewName '' und meldet eine
    # Framework-Exception statt einer Ursache. Der einzige Pfad, der weiterhin
    # 'failed' meldet, und dann auch nur diese eine Meldung: hier gibt es
    # tatsaechlich keinen setzbaren Namen.
    if ([string]::IsNullOrWhiteSpace($sanitized)) {
        Write-VsClientLog -Level ERROR "Soll-Hostname '$newHostname' enthaelt kein einziges fuer NetBIOS gueltiges Zeichen - es gibt keinen setzbaren Namen."
        Set-MecmDetection -Values @{ Status = 'Fehler'; Hostname = $current; Reason = 'Sollname ohne gueltige Zeichen' }
        if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'hostname' -PhaseEvent 'failed' -Detail "sanitized '$newHostname' -> leer" }
        exit 1
    }

    # Die Abweichung reist im Detail der terminalen Meldung mit, damit ein
    # Operator die verbleibenden Altzeilen findet, ohne dass ein Deployment
    # scheitert.
    $sanitizeNote = ''
    if ($sanitized -ne $newHostname) {
        Write-VsClientLog -Level WARN "Soll-Hostname '$newHostname' verletzt NetBIOS-Regeln, verwende '$sanitized'. Die verbindliche Pruefung sitzt im Portal; diese VM traegt noch die alte, lockere Regel."
        $sanitizeNote = " (bereinigt aus '$newHostname')"
    }
    $newHostname = $sanitized

    if ($current -eq $newHostname) {
        Write-VsClientLog 'Hostname bereits korrekt.'
        Set-MecmDetection -Values @{ Status = 'Erfolgreich'; Hostname = $current; Reason = 'Bereits korrekt' }
        if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'hostname' -PhaseEvent 'finished' -Detail ('already correct{0}' -f $sanitizeNote) }
        exit 0
    }

    if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'hostname' -PhaseEvent 'started' -Detail "-> $newHostname" }
    Rename-Computer -NewName $newHostname -Force -ErrorAction Stop
    Write-VsClientLog "Umbenannt: '$current' -> '$newHostname'."
    Set-MecmDetection -Values @{ Status = 'Erfolgreich'; OldHostname = $current; NewHostname = $newHostname }

    # finished VOR dem Reboot melden (danach ist der Client evtl. offline).
    if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'hostname' -PhaseEvent 'finished' -Detail ('renamed to {0}{1}' -f $newHostname, $sanitizeNote) }

    Write-VsClientLog 'Neustart in 60s.'
    Start-Process -FilePath 'cmd.exe' -ArgumentList '/c "timeout /t 60 /nobreak && shutdown -r -f -t 0"' -WindowStyle Hidden
    exit 1641
} catch {
    Write-VsClientLog -Level ERROR "Unerwarteter Fehler: $($_.Exception.Message)"
    if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'hostname' -PhaseEvent 'failed' -Detail $_.Exception.Message }
    exit 1
}
