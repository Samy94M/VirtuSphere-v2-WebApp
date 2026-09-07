#Requires -Version 5.1
# ============================================================================
# Set-VMDisksOnline.ps1 - bringt vorhandene Datenplatten online und richtet
# neue, eindeutig VirtuSphere-eigene RAW-Datentraeger wiederaufnehmbar ein.
# Vierte/optionale Phase der Client-Kette.
#
# Bereits partitionierte Datentraeger werden niemals formatiert. Eine neue RAW-
# Platte wird vor dem ersten Write mit stabiler Identitaet in einem dauerhaften
# Registry-Journal registriert. Nach einem Abbruch wird ausschliesslich diese
# Operation fortgesetzt; eine unbekannte online-RAW-Platte blockiert fail-closed.
# ============================================================================

. "$PSScriptRoot\VirtuSphere-Client-Common.ps1"
Initialize-VsClientLog -Component 'disks'
Write-VsClientLog 'Starte Set-VMDisksOnline'

$registryPath = 'HKLM:\SOFTWARE\VirtuSphere\VMDiskManagement'
$operationsPath = Join-Path $registryPath 'Operations'
$valueName = 'VMDisksOnlineStatus'
$journalSchema = 1
$basicDataGptType = '{EBD0A0A2-B9E5-4433-87C0-68B6B72699C7}'
$reportMac = Get-VsReportMac
$exitCode = 0
$diskMutex = $null
$diskMutexHeld = $false

function Set-DiskStatus {
    param([Parameter(Mandatory)][string]$Status, [hashtable]$Extra = @{})
    if (-not (Test-Path -Path $registryPath)) {
        New-Item -Path $registryPath -Force -ErrorAction Stop | Out-Null
    }
    Set-ItemProperty -Path $registryPath -Name $valueName -Value $Status -ErrorAction Stop
    Set-ItemProperty -Path $registryPath -Name 'LastRunDate' -Value (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') -ErrorAction Stop
    foreach ($key in $Extra.Keys) {
        Set-ItemProperty -Path $registryPath -Name $key -Value ([string]$Extra[$key]) -ErrorAction Stop
    }
}

function Set-DiskStatusBestEffort {
    param([Parameter(Mandatory)][string]$Status, [hashtable]$Extra = @{})
    try { Set-DiskStatus -Status $Status -Extra $Extra } catch {
        Write-VsClientLog -Level WARN "Status-Registry fehlgeschlagen: $($_.Exception.Message)"
    }
}

function ConvertTo-DiskOperation {
    param([Parameter(Mandatory)][object]$RegistryValue, [Parameter(Mandatory)][string]$Path)

    if ([int]$RegistryValue.SchemaVersion -ne $journalSchema -or
        [string]::IsNullOrWhiteSpace([string]$RegistryValue.Identity) -or
        [string]::IsNullOrWhiteSpace([string]$RegistryValue.State) -or
        [string]::IsNullOrWhiteSpace([string]$RegistryValue.Label) -or
        [long]$RegistryValue.ExpectedSize -le 0) {
        throw "Ungueltiger Disk-Journaleintrag '$Path'."
    }
    $allowedStates = @('intent', 'online', 'initialized', 'partitioned', 'formatted', 'complete')
    if ([string]$RegistryValue.State -notin $allowedStates) {
        throw "Unbekannter Disk-Journalstatus '$($RegistryValue.State)' in '$Path'."
    }
    return [pscustomobject]@{
        Path = $Path
        Identity = [string]$RegistryValue.Identity
        ExpectedSize = [long]$RegistryValue.ExpectedSize
        State = [string]$RegistryValue.State
        Label = [string]$RegistryValue.Label
        PartitionNumber = [int]$RegistryValue.PartitionNumber
        PartitionOffset = [long]$RegistryValue.PartitionOffset
    }
}

function Get-DiskOperations {
    if (-not (Test-Path -Path $operationsPath)) { return @() }
    $result = New-Object System.Collections.Generic.List[object]
    foreach ($entry in @(Get-ChildItem -Path $operationsPath -ErrorAction Stop)) {
        $raw = Get-ItemProperty -Path $entry.PSPath -ErrorAction Stop
        [void]$result.Add((ConvertTo-DiskOperation -RegistryValue $raw -Path $entry.PSPath))
    }
    return @($result)
}

function New-DiskOperation {
    param([Parameter(Mandatory)][object]$Disk, [Parameter(Mandatory)][string]$Identity)

    $key = Get-VsSha256Hex -Value $Identity
    $path = Join-Path $operationsPath $key
    $label = 'VM_Disk_{0}' -f ([int]$Disk.Number)
    if (-not (Test-Path -Path $operationsPath)) {
        New-Item -Path $operationsPath -Force -ErrorAction Stop | Out-Null
    }
    if (Test-Path -Path $path) {
        throw "Disk-Journal '$key' existiert bereits und darf nicht ueberschrieben werden."
    }
    New-Item -Path $path -Force -ErrorAction Stop | Out-Null
    $values = [ordered]@{
        SchemaVersion = $journalSchema
        Identity = $Identity
        ExpectedSize = [string]([long]$Disk.Size)
        State = 'intent'
        Label = $label
        PartitionNumber = '0'
        PartitionOffset = '0'
        CreatedAt = (Get-Date).ToUniversalTime().ToString('o')
        UpdatedAt = (Get-Date).ToUniversalTime().ToString('o')
    }
    foreach ($name in $values.Keys) {
        Set-ItemProperty -Path $path -Name $name -Value $values[$name] -ErrorAction Stop
    }
    return ConvertTo-DiskOperation -RegistryValue (Get-ItemProperty -Path $path -ErrorAction Stop) -Path $path
}

function Set-DiskOperationState {
    param(
        [Parameter(Mandatory)][object]$Operation,
        [Parameter(Mandatory)][ValidateSet('intent', 'online', 'initialized', 'partitioned', 'formatted', 'complete')][string]$State,
        [object]$Partition
    )
    Set-ItemProperty -Path $Operation.Path -Name 'State' -Value $State -ErrorAction Stop
    if ($Partition) {
        Set-ItemProperty -Path $Operation.Path -Name 'PartitionNumber' -Value ([string][int]$Partition.PartitionNumber) -ErrorAction Stop
        Set-ItemProperty -Path $Operation.Path -Name 'PartitionOffset' -Value ([string][long]$Partition.Offset) -ErrorAction Stop
        $Operation.PartitionNumber = [int]$Partition.PartitionNumber
        $Operation.PartitionOffset = [long]$Partition.Offset
    }
    Set-ItemProperty -Path $Operation.Path -Name 'UpdatedAt' -Value (Get-Date).ToUniversalTime().ToString('o') -ErrorAction Stop
    $Operation.State = $State
}

function Assert-DiskIsSafeTarget {
    param([Parameter(Mandatory)][object]$Disk)
    if ([bool]$Disk.IsBoot -or [bool]$Disk.IsSystem) { throw "Datentraeger $($Disk.Number) ist Boot/System-Datentraeger." }
    if ([bool]$Disk.IsReadOnly) { throw "Datentraeger $($Disk.Number) ist schreibgeschuetzt." }
    if ($Disk.PSObject.Properties.Name -contains 'IsClustered' -and [bool]$Disk.IsClustered) {
        throw "Datentraeger $($Disk.Number) ist als gemeinsam/Cluster-Datentraeger markiert."
    }
    if ([long]$Disk.Size -le 0) { throw "Datentraeger $($Disk.Number) meldet Groesse 0." }
}

function Get-OwnedDataPartition {
    param([Parameter(Mandatory)][int]$DiskNumber)
    $partitions = @(Get-Partition -DiskNumber $DiskNumber -ErrorAction Stop)
    $data = @($partitions | Where-Object { [string]$_.GptType -eq $basicDataGptType })
    $unexpected = @($partitions | Where-Object {
        [string]$_.GptType -ne $basicDataGptType -and [string]$_.Type -notin @('Reserved', 'System', 'Recovery')
    })
    if ($data.Count -gt 1 -or $unexpected.Count -gt 0) {
        throw "Datentraeger $DiskNumber besitzt eine mehrdeutige oder fremde Partitionierung."
    }
    if ($data.Count -eq 1) { return $data[0] }
    return $null
}

function Confirm-OperationDisk {
    param([Parameter(Mandatory)][object]$Operation, [Parameter(Mandatory)][object]$Disk)
    $identity = Get-VsDiskStableIdentity -Disk $Disk
    if (-not $identity.Valid -or [string]$identity.Identity -cne [string]$Operation.Identity) {
        throw "Disk-Journal konnte Datentraeger $($Disk.Number) nicht stabil bestaetigen."
    }
    if ([long]$Disk.Size -ne [long]$Operation.ExpectedSize) {
        throw "Groesse von Datentraeger $($Disk.Number) weicht vom Disk-Journal ab."
    }
    Assert-DiskIsSafeTarget -Disk $Disk
}

function Invoke-OwnedRawDiskOperation {
    param([Parameter(Mandatory)][object]$Operation, [Parameter(Mandatory)][object]$Disk)

    Confirm-OperationDisk -Operation $Operation -Disk $Disk
    if ([string]$Disk.OperationalStatus -eq 'Offline') {
        $Disk | Set-Disk -IsOffline $false -ErrorAction Stop
    }
    $Disk = Get-Disk -Number ([int]$Disk.Number) -ErrorAction Stop
    Confirm-OperationDisk -Operation $Operation -Disk $Disk
    if ([string]$Disk.OperationalStatus -eq 'Offline') {
        throw "Datentraeger $($Disk.Number) ist nach Set-Disk weiterhin offline (Status $($Disk.OperationalStatus))."
    }
    Set-DiskOperationState -Operation $Operation -State 'online'

    if ([string]$Disk.PartitionStyle -eq 'RAW') {
        $Disk | Initialize-Disk -PartitionStyle GPT -Confirm:$false -ErrorAction Stop
        $Disk = Get-Disk -Number ([int]$Disk.Number) -ErrorAction Stop
    }
    if ([string]$Disk.PartitionStyle -ne 'GPT') {
        throw "Eigene Disk-Operation erwartet RAW/GPT, fand aber '$($Disk.PartitionStyle)'."
    }
    Set-DiskOperationState -Operation $Operation -State 'initialized'

    $partition = Get-OwnedDataPartition -DiskNumber ([int]$Disk.Number)
    if (-not $partition) {
        $partition = $Disk | New-Partition -AssignDriveLetter -UseMaximumSize -ErrorAction Stop
    }
    $partition = Get-Partition -DiskNumber ([int]$Disk.Number) -PartitionNumber ([int]$partition.PartitionNumber) -ErrorAction Stop
    if ([string]$partition.GptType -ne $basicDataGptType) {
        throw "Erstellte Partition auf Datentraeger $($Disk.Number) ist keine Basic-Data-Partition."
    }
    Set-DiskOperationState -Operation $Operation -State 'partitioned' -Partition $partition

    if (-not $partition.DriveLetter) {
        $partition | Add-PartitionAccessPath -AssignDriveLetter -ErrorAction Stop
        $partition = Get-Partition -DiskNumber ([int]$Disk.Number) -PartitionNumber ([int]$partition.PartitionNumber) -ErrorAction Stop
    }
    if (-not $partition.DriveLetter) { throw "Partition auf Datentraeger $($Disk.Number) hat keinen Laufwerksbuchstaben." }

    $volume = Get-Volume -Partition $partition -ErrorAction Stop
    if ([string]$volume.FileSystem -in @('', 'RAW')) {
        $partition | Format-Volume -FileSystem NTFS -Confirm:$false -Force -NewFileSystemLabel $Operation.Label -ErrorAction Stop | Out-Null
        $volume = Get-Volume -Partition $partition -ErrorAction Stop
    }
    if ([string]$volume.FileSystem -ne 'NTFS' -or [string]$volume.FileSystemLabel -cne [string]$Operation.Label) {
        throw "Formatierung unvollstaendig oder fremd (Laufwerk '$($partition.DriveLetter)', Dateisystem '$($volume.FileSystem)', Label '$($volume.FileSystemLabel)')."
    }
    Set-DiskOperationState -Operation $Operation -State 'formatted' -Partition $partition

    $Disk = Get-Disk -Number ([int]$Disk.Number) -ErrorAction Stop
    Confirm-OperationDisk -Operation $Operation -Disk $Disk
    $verifiedPartition = Get-Partition -DiskNumber ([int]$Disk.Number) -PartitionNumber ([int]$partition.PartitionNumber) -ErrorAction Stop
    $verifiedVolume = Get-Volume -Partition $verifiedPartition -ErrorAction Stop
    if ([string]$Disk.PartitionStyle -ne 'GPT' -or -not $verifiedPartition.DriveLetter -or
        [string]$verifiedVolume.FileSystem -ne 'NTFS' -or [string]$verifiedVolume.FileSystemLabel -cne [string]$Operation.Label) {
        throw "Abschlusspruefung von Datentraeger $($Disk.Number) ist fehlgeschlagen."
    }
    Set-DiskOperationState -Operation $Operation -State 'complete' -Partition $verifiedPartition
    Write-VsClientLog "Neuer Datentraeger $($Disk.Number) wiederaufnehmbar abgeschlossen (Laufwerk $($verifiedPartition.DriveLetter):)."
}

try {
    $diskMutex = New-Object System.Threading.Mutex($false, 'Global\VirtuSphere.VMDiskManagement')
    try { $diskMutexHeld = $diskMutex.WaitOne(0) } catch [System.Threading.AbandonedMutexException] { $diskMutexHeld = $true }
    if (-not $diskMutexHeld) {
        Write-VsClientLog -Level ERROR 'Eine andere Disk-Phase haelt bereits den exklusiven Wiederanlauf-Lock.'
        if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'disks' -PhaseEvent 'failed' -Detail 'disk operation already running' }
        $diskMutex.Dispose()
        exit 1
    }
} catch {
    Write-VsClientLog -Level ERROR "Disk-Wiederanlauf-Lock konnte nicht geoeffnet werden: $($_.Exception.Message)"
    if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'disks' -PhaseEvent 'failed' -Detail 'disk operation lock unavailable' }
    if ($diskMutex) { $diskMutex.Dispose() }
    exit 1
}

if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'disks' -PhaseEvent 'started' }

try {
    # Der Running-Marker ist Teil des Vertrags. Schlaegt er fehl, darf vor allem
    # bei neuen RAW-Platten kein Storage-Write folgen.
    Set-DiskStatus -Status 'Running'

    $disks = @(Get-Disk -ErrorAction Stop)
    $operations = @(Get-DiskOperations)
    $openOperations = @($operations | Where-Object { $_.State -ne 'complete' })
    $identityMap = @{}
    $identityByNumber = @{}
    $blockers = New-Object System.Collections.Generic.List[string]

    foreach ($disk in $disks) {
        $identity = Get-VsDiskStableIdentity -Disk $disk
        if ($identity.Valid) {
            $identityByNumber[[int]$disk.Number] = [string]$identity.Identity
            if (-not $identityMap.ContainsKey([string]$identity.Identity)) { $identityMap[[string]$identity.Identity] = @() }
            $identityMap[[string]$identity.Identity] = @($identityMap[[string]$identity.Identity]) + @($disk)
        }
    }

    foreach ($operation in $openOperations) {
        $operationMatches = @($identityMap[[string]$operation.Identity])
        if ($operationMatches.Count -ne 1) {
            [void]$blockers.Add("Offene Disk-Operation '$($operation.Identity)' trifft $($operationMatches.Count) Datentraeger.")
        } elseif ([long]$operationMatches[0].Size -ne [long]$operation.ExpectedSize) {
            [void]$blockers.Add("Offene Disk-Operation '$($operation.Identity)' hat eine abweichende Groesse.")
        } else {
            try { Assert-DiskIsSafeTarget -Disk $operationMatches[0] } catch { [void]$blockers.Add($_.Exception.Message) }
        }
    }

    foreach ($disk in $disks) {
        $identity = [string]$identityByNumber[[int]$disk.Number]
        $owned = @($openOperations | Where-Object { -not [string]::IsNullOrWhiteSpace($identity) -and [string]$_.Identity -ceq $identity })
        if ($owned.Count -gt 1) { [void]$blockers.Add("Mehrere offene Journaleintraege beanspruchen Datentraeger $($disk.Number).") }

        $isOffline = [string]$disk.OperationalStatus -eq 'Offline'
        $isRaw = [string]$disk.PartitionStyle -eq 'RAW'
        if ($isOffline -or ($isRaw -and $owned.Count -gt 0)) {
            try { Assert-DiskIsSafeTarget -Disk $disk } catch { [void]$blockers.Add($_.Exception.Message) }
        }
        if ($isRaw -and [string]::IsNullOrWhiteSpace($identity)) {
            [void]$blockers.Add("RAW-Datentraeger $($disk.Number) besitzt keine stabile Identitaet.")
        } elseif ($isRaw -and -not $isOffline -and $owned.Count -eq 0) {
            [void]$blockers.Add("Online-RAW-Datentraeger $($disk.Number) ist keiner VirtuSphere-Operation zugeordnet und wird nicht formatiert.")
        }
    }

    foreach ($key in @($identityMap.Keys)) {
        if (@($identityMap[$key]).Count -gt 1) { [void]$blockers.Add("Stabile Disk-Identitaet '$key' ist nicht eindeutig.") }
    }
    if ($blockers.Count -gt 0) { throw (($blockers | Select-Object -Unique) -join ' | ') }

    $resumed = 0
    $created = 0
    $existing = 0
    foreach ($operation in $openOperations) {
        $disk = @($identityMap[[string]$operation.Identity])[0]
        Invoke-OwnedRawDiskOperation -Operation $operation -Disk $disk
        $resumed++
    }

    $openIds = @($openOperations | ForEach-Object { [string]$_.Identity })
    foreach ($disk in @($disks | Where-Object { [string]$_.OperationalStatus -eq 'Offline' })) {
        $identity = [string]$identityByNumber[[int]$disk.Number]
        if ($identity -cin $openIds) { continue }
        if ([string]$disk.PartitionStyle -eq 'RAW') {
            $operation = New-DiskOperation -Disk $disk -Identity $identity
            Invoke-OwnedRawDiskOperation -Operation $operation -Disk $disk
            $created++
        } else {
            $disk | Set-Disk -IsOffline $false -ErrorAction Stop
            $after = Get-Disk -Number ([int]$disk.Number) -ErrorAction Stop
            if ([string]$after.OperationalStatus -eq 'Offline') {
                throw "Datentraeger $($disk.Number) ist nach Set-Disk weiterhin offline (Status $($after.OperationalStatus))."
            }
            Write-VsClientLog "Datentraeger $($disk.Number) online (bestehende Daten erhalten)."
            $existing++
        }
    }

    $detail = "resumed={0} new={1} existing={2}" -f $resumed, $created, $existing
    if (($resumed + $created + $existing) -eq 0) { $detail = 'optional: no disk work required' }
    Set-DiskStatus -Status 'Success' -Extra @{ ProcessedDisks = [string]($resumed + $created + $existing); LastResult = $detail }
    if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'disks' -PhaseEvent 'finished' -Detail $detail }
} catch {
    Write-VsClientLog -Level ERROR "Disk-Phase fehlgeschlagen: $($_.Exception.Message)"
    $exitCode = 1
    Set-DiskStatusBestEffort -Status 'Error' -Extra @{ LastError = $_.Exception.Message }
    if ($reportMac) { Send-VsPhase -Mac $reportMac -Phase 'disks' -PhaseEvent 'failed' -Detail $_.Exception.Message }
} finally {
    try {
        $current = (Get-ItemProperty -Path $registryPath -Name $valueName -ErrorAction Stop).$valueName
        if ($current -eq 'Running') { Set-DiskStatusBestEffort -Status 'Error' -Extra @{ LastError = 'aborted' } }
    } catch { Write-Debug $_ }
    if ($diskMutexHeld -and $diskMutex) {
        try { $diskMutex.ReleaseMutex() } catch { Write-Debug $_ }
    }
    if ($diskMutex) { $diskMutex.Dispose() }
}

exit $exitCode
