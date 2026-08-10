#Requires -Version 5.1
# ============================================================================
# Set-VMDisksOnline.ps1 - schaltet Offline-Datentraeger online und formatiert
# NEUE (RAW) Datentraeger. Vierte/optionale Phase der Client-Kette.
#
# Datenschutz: bereits partitionierte (GPT/MBR) Datentraeger werden NUR online
# geschaltet, niemals formatiert. Nur RAW-Datentraeger werden eingerichtet.
#
# Verbesserungen:
#  - try/finally sichert den Registry-Status ab (bei Crash blieb frueher
#    "Running" stehen); @()-Wrapping fuer .Count; reportPhase (Phase 'disks')
# ============================================================================

. "$PSScriptRoot\VirtuSphere-Client-Common.ps1"
Initialize-VsClientLog -Component 'disks'
Write-VsClientLog 'Starte Set-VMDisksOnline'

$registryPath = 'HKLM:\SOFTWARE\VirtuSphere\VMDiskManagement'
$valueName = 'VMDisksOnlineStatus'
$reportMac = Get-VsReportMac
$exitCode = 0

function Set-DiskStatus {
    param([string]$Status, [hashtable]$Extra = @{})
    try {
        if (-not (Test-Path $registryPath)) { New-Item -Path $registryPath -Force | Out-Null }
        # Kein -Type: ein String-Value wird ohnehin REG_SZ, und -Type ist ein
        # dynamischer Provider-Parameter, den PSUseCompatibleCommands (5.1-
        # Profil) nicht aufloesen kann.
        Set-ItemProperty -Path $registryPath -Name $valueName -Value $Status
        Set-ItemProperty -Path $registryPath -Name 'LastRunDate' -Value (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
        foreach ($key in $Extra.Keys) { Set-ItemProperty -Path $registryPath -Name $key -Value ([string]$Extra[$key]) }
    } catch {
        Write-VsClientLog -Level WARN "Status-Registry fehlgeschlagen: $($_.Exception.Message)"
    }
}

Set-DiskStatus -Status 'Running'
if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'disks' -PhaseEvent 'started' }

try {
    # -ErrorAction Stop auf JEDEM Storage-Aufruf in dieser Datei, hier eingeschlossen.
    # Die Storage-Cmdlets melden ihre Fehler per Default nicht-terminierend, also
    # feuerte der catch nie: das Skript lief weiter, $exitCode blieb 0, die Phase
    # meldete "finished" und die MECM-Erkennung war erfuellt, waehrend kein
    # einziger Datentraeger online war. Eine gruene Phase muss heissen, dass die
    # Phase ihre Arbeit wirklich getan hat.
    $offline = @(Get-Disk -ErrorAction Stop | Where-Object { $_.OperationalStatus -eq 'Offline' })
    if ($offline.Count -eq 0) {
        Write-VsClientLog 'Keine Offline-Datentraeger.'
        Set-DiskStatus -Status 'Success' -Extra @{ ProcessedDisks = '0' }
        if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'disks' -PhaseEvent 'finished' -Detail 'no offline disks' }
        exit 0
    }

    $existing = @($offline | Where-Object { $_.PartitionStyle -ne 'RAW' })
    $raw = @($offline | Where-Object { $_.PartitionStyle -eq 'RAW' -and $_.Size -gt 0 })
    # Ein RAW-Datentraeger mit Groesse 0 fiel aus BEIDEN Listen und wurde
    # trotzdem in ProcessedDisks mitgezaehlt: die Zahl behauptete Arbeit, die
    # niemand geleistet hat. Er bekommt eine eigene Kategorie, wird benannt und
    # nicht mitgezaehlt. Formatieren laesst er sich nicht (es gibt nichts zu
    # partitionieren), ein Fehlschlag ist er aber auch nicht: die Ursache liegt
    # am Hypervisor, nicht am Gast.
    $sizeless = @($offline | Where-Object { $_.PartitionStyle -eq 'RAW' -and $_.Size -le 0 })
    Write-VsClientLog "Offline: $($offline.Count) (bestehend $($existing.Count), neu $($raw.Count), ohne Groesse $($sizeless.Count))"
    foreach ($disk in $sizeless) {
        Write-VsClientLog -Level WARN "Datentraeger $($disk.Number) ist RAW und meldet Groesse 0 - uebersprungen (am Host pruefen, ob die virtuelle Platte wirklich angehaengt ist)."
    }

    foreach ($disk in $existing) {
        try {
            $disk | Set-Disk -IsOffline $false -ErrorAction Stop
            # Nachlesen statt annehmen: der Aufruf kann ohne Fehler zurueckkommen
            # und der Datentraeger trotzdem offline bleiben (Richtlinie
            # "Offline shared bus", Wechselmedium). Ohne diese Pruefung meldete
            # die Phase Erfolg fuer einen Datentraeger, den niemand sieht.
            $after = Get-Disk -Number $disk.Number -ErrorAction Stop
            if ($after.OperationalStatus -eq 'Offline') {
                throw ("Datentraeger ist nach Set-Disk weiterhin offline (Status {0})." -f $after.OperationalStatus)
            }
            Write-VsClientLog "Datentraeger $($disk.Number) online (Daten erhalten)."
        } catch {
            Write-VsClientLog -Level ERROR "Datentraeger $($disk.Number) online fehlgeschlagen: $($_.Exception.Message)"
            $exitCode = 1
        }
    }

    foreach ($disk in $raw) {
        try {
            $disk | Set-Disk -IsOffline $false -ErrorAction Stop
            Start-Sleep -Seconds 2
            $disk | Initialize-Disk -PartitionStyle GPT -Confirm:$false -ErrorAction Stop
            $part = $disk | New-Partition -AssignDriveLetter -UseMaximumSize -ErrorAction Stop
            $part | Format-Volume -FileSystem NTFS -Confirm:$false -Force -NewFileSystemLabel ("VM_Disk_{0}" -f $disk.Number) -ErrorAction Stop | Out-Null
            # Ein Laufwerksbuchstabe ist das, was der Benutzer der VM sieht. Fehlt
            # er, ist die Platte nicht nutzbar, egal was die Cmdlets gemeldet haben.
            $volume = Get-Volume -Partition $part -ErrorAction Stop
            if (-not $part.DriveLetter -or $volume.FileSystem -ne 'NTFS') {
                throw ("Formatierung unvollstaendig (Laufwerk '{0}', Dateisystem '{1}')." -f $part.DriveLetter, $volume.FileSystem)
            }
            Write-VsClientLog "Neuer Datentraeger $($disk.Number) formatiert (Laufwerk $($part.DriveLetter):)."
        } catch {
            Write-VsClientLog -Level ERROR "Datentraeger $($disk.Number) formatieren fehlgeschlagen: $($_.Exception.Message)"
            $exitCode = 1
        }
    }

    $detail = "existing={0} new={1} sizeless={2}" -f $existing.Count, $raw.Count, $sizeless.Count
    if ($exitCode -eq 0) {
        # ProcessedDisks zaehlt, was das Skript angefasst hat, nicht was offline
        # war: die uebersprungenen groessenlosen Datentraeger gehoeren nicht dazu.
        Set-DiskStatus -Status 'Success' -Extra @{ ProcessedDisks = "$($existing.Count + $raw.Count)" }
        if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'disks' -PhaseEvent 'finished' -Detail $detail }
    } else {
        Set-DiskStatus -Status 'Error' -Extra @{ LastError = 'completed with errors' }
        if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'disks' -PhaseEvent 'failed' -Detail $detail }
    }
} catch {
    Write-VsClientLog -Level ERROR "Unerwarteter Fehler: $($_.Exception.Message)"
    $exitCode = 1
    Set-DiskStatus -Status 'Error' -Extra @{ LastError = $_.Exception.Message }
    if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'disks' -PhaseEvent 'failed' -Detail $_.Exception.Message }
} finally {
    # Absicherung: niemals im Status 'Running' haengen bleiben.
    $current = (Get-ItemProperty -Path $registryPath -Name $valueName -ErrorAction SilentlyContinue).$valueName
    if ($current -eq 'Running') { Set-DiskStatus -Status 'Error' -Extra @{ LastError = 'aborted' } }
}

exit $exitCode
