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
        Set-ItemProperty -Path $registryPath -Name $valueName -Value $Status -Type String
        Set-ItemProperty -Path $registryPath -Name 'LastRunDate' -Value (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') -Type String
        foreach ($key in $Extra.Keys) { Set-ItemProperty -Path $registryPath -Name $key -Value $Extra[$key] -Type String }
    } catch {
        Write-VsClientLog -Level WARN "Status-Registry fehlgeschlagen: $($_.Exception.Message)"
    }
}

Set-DiskStatus -Status 'Running'
if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'disks' -PhaseEvent 'started' }

try {
    $offline = @(Get-Disk | Where-Object { $_.OperationalStatus -eq 'Offline' })
    if ($offline.Count -eq 0) {
        Write-VsClientLog 'Keine Offline-Datentraeger.'
        Set-DiskStatus -Status 'Success' -Extra @{ ProcessedDisks = '0' }
        if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'disks' -PhaseEvent 'finished' -Detail 'no offline disks' }
        exit 0
    }

    $existing = @($offline | Where-Object { $_.PartitionStyle -ne 'RAW' })
    $raw = @($offline | Where-Object { $_.PartitionStyle -eq 'RAW' -and $_.Size -gt 0 })
    Write-VsClientLog "Offline: $($offline.Count) (bestehend $($existing.Count), neu $($raw.Count))"

    foreach ($disk in $existing) {
        try {
            $disk | Set-Disk -IsOffline $false
            Write-VsClientLog "Datentraeger $($disk.Number) online (Daten erhalten)."
        } catch {
            Write-VsClientLog -Level ERROR "Datentraeger $($disk.Number) online fehlgeschlagen: $($_.Exception.Message)"
            $exitCode = 1
        }
    }

    foreach ($disk in $raw) {
        try {
            $disk | Set-Disk -IsOffline $false
            Start-Sleep -Seconds 2
            $disk | Initialize-Disk -PartitionStyle GPT -Confirm:$false
            $part = $disk | New-Partition -AssignDriveLetter -UseMaximumSize
            $part | Format-Volume -FileSystem NTFS -Confirm:$false -Force -NewFileSystemLabel ("VM_Disk_{0}" -f $disk.Number) | Out-Null
            Write-VsClientLog "Neuer Datentraeger $($disk.Number) formatiert (Laufwerk $($part.DriveLetter):)."
        } catch {
            Write-VsClientLog -Level ERROR "Datentraeger $($disk.Number) formatieren fehlgeschlagen: $($_.Exception.Message)"
            $exitCode = 1
        }
    }

    $detail = "existing={0} new={1}" -f $existing.Count, $raw.Count
    if ($exitCode -eq 0) {
        Set-DiskStatus -Status 'Success' -Extra @{ ProcessedDisks = "$($offline.Count)" }
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
