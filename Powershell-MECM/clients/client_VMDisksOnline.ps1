#Requires -Version 5.1
# ============================================================================
# client_VMDisksOnline.ps1 - bringt vorhandene Datenplatten online und richtet
# neue, eindeutig VirtuSphere-eigene RAW-Datentraeger wiederaufnehmbar ein.
# Vierte/optionale Phase der Client-Kette.
#
# Bereits partitionierte Datentraeger werden niemals formatiert. Eine neue RAW-
# Platte wird vor dem ersten Write mit stabiler Identitaet in einem dauerhaften
# Registry-Journal registriert. Nach einem Abbruch wird ausschliesslich diese
# Operation fortgesetzt; eine unbekannte online-RAW-Platte blockiert fail-closed.
# Neue Datenplatten erhalten ihren Portalnamen als Bezeichnung (DN1). Traegt
# die Systemplatte im Portal einen eigenen Namen, erhaelt das Windows-Laufwerk
# ihn danach als Bezeichnung (DN2); sonst bleibt die der Task Sequence.
# ============================================================================

. "$PSScriptRoot\VirtuSphere-Client-Common.ps1"
Initialize-VsClientLog -Component 'disks'
Write-VsClientLog 'Starte Set-VMDisksOnline'

$registryPath = 'HKLM:\SOFTWARE\VirtuSphere\VMDiskManagement'
$operationsPath = Join-Path $registryPath 'Operations'
$valueName = 'VMDisksOnlineStatus'
$journalSchema = 1
$basicDataGptType = '{EBD0A0A2-B9E5-4433-87C0-68B6B72699C7}'
# Spiegel von VIRTUSPHERE_DISK_VOLUME_LABEL_MAX (Docker/WebAPI/lib/vm_disk_layout.php);
# Pester liest beide Seiten. NTFS speichert hoechstens 32 UTF-16-Zeichen.
$volumeLabelMax = 32
# DN2: Rolloutrevision, fuer die das Windows-Laufwerk seine Portalbezeichnung
# erhalten hat. Wert am Statusschluessel, nie ein Eintrag unter Operations.
$systemLabelMarker = 'SystemVolumeLabelRevision'
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
    return $result.ToArray()
}

function New-DiskOperation {
    # Das Label wird hier im Journal festgeschrieben. Eine Wiederaufnahme nach
    # einem Abbruch formatiert mit genau diesem Label weiter und fragt das Portal
    # nicht erneut (DN1).
    param([Parameter(Mandatory)][object]$Disk, [Parameter(Mandatory)][string]$Identity, [Parameter(Mandatory)][string]$Label)

    $key = Get-VsSha256Hex -Value $Identity
    $path = Join-Path $operationsPath $key
    $label = $Label
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
    # Schreibschutz ist hier kein Blocker: Unter der SAN-Richtlinie von Windows
    # Server (OfflineShared, OfflineAll) erscheint jede neue VMDK offline UND
    # schreibgeschuetzt. Clear-DiskReadOnly hebt ihn vor dem ersten Write auf.
    param([Parameter(Mandatory)][object]$Disk)
    if ([bool]$Disk.IsBoot -or [bool]$Disk.IsSystem) { throw "Datentraeger $($Disk.Number) ist Boot/System-Datentraeger." }
    if ($Disk.PSObject.Properties.Name -contains 'IsClustered' -and [bool]$Disk.IsClustered) {
        throw "Datentraeger $($Disk.Number) ist als gemeinsam/Cluster-Datentraeger markiert."
    }
    if ([long]$Disk.Size -le 0) { throw "Datentraeger $($Disk.Number) meldet Groesse 0." }
}

function Clear-DiskReadOnly {
    # IsReadOnly und IsOffline liegen in getrennten Set-Disk-Parametersaetzen und
    # brauchen zwei Aufrufe. Reihenfolge nach Microsoft/VMware: erst Schreibschutz
    # aufheben, dann online schalten; die GPT-Initialisierung verlangt read-write.
    # Der Aufrufer liest den Datentraeger danach selbst neu.
    param([Parameter(Mandatory)][object]$Disk)
    if (-not [bool]$Disk.IsReadOnly) { return }
    Set-Disk -Number ([int]$Disk.Number) -IsReadOnly $false -ErrorAction Stop
    $after = Get-Disk -Number ([int]$Disk.Number) -ErrorAction Stop
    if ([bool]$after.IsReadOnly) {
        throw "Datentraeger $($Disk.Number) ist nach Set-Disk weiterhin schreibgeschuetzt."
    }
    Write-VsClientLog "Schreibschutz von Datentraeger $($Disk.Number) aufgehoben (SAN-Richtlinie)."
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

# --- Datentraegername aus dem Portal (DN1) -----------------------------------
# Eine NEUE Datenplatte bekommt den Namen, den sie im Portal traegt, als
# Laufwerksbezeichnung. Das Create-Playbook legt alle Platten an einem SCSI-
# Controller an; die Platte an Listenposition p bekommt die Unit, die das
# Portal als scsi_unit mitliefert (vm_disk_scsi_unit()), und Windows zeigt sie
# als SCSITargetId. Die Groesse muss zusaetzlich exakt passen. Jede
# Unsicherheit laesst das Vorgabelabel VM_Disk_<Nummer> stehen und nennt einen
# dieser Gruende; die Phase bleibt erfolgreich:
#   rollout_mismatch          kein publizierter Snapshot, oder das Portal kennt
#                             die MAC nicht mehr oder unter einer anderen Revision
#   portal_unreachable        kein Portal oder getDeviceInfos zweimal gescheitert
#   portal_without_disk_names Portal ohne Feld disks (Stand vor DN1)
#   disk_list_invalid         Feld disks leer, unvollstaendig oder doppelt belegt
#   scsi_layout_unknown       Plattenlayout nicht so, wie das Playbook es baut
#   no_portal_disk            keine Portalplatte auf diesem SCSI-Target
#   size_mismatch             Portalgroesse passt nicht zur Platte
#   no_usable_name            vom Namen bleibt nach der Portalregel nichts uebrig
#   label_invalid             Portal lieferte ein Label, das NTFS nicht nimmt
# Bereits eingerichtete Datenplatten werden nie umbenannt; nur das Windows-
# Laufwerk erhaelt im eigenen Block weiter unten eine Bezeichnung (DN2).

function Get-DefaultDiskLabel {
    param([Parameter(Mandatory)][object]$Disk)
    return 'VM_Disk_{0}' -f ([int]$Disk.Number)
}

function Test-DiskVolumeLabel {
    # Prueft nur; die Regel (Zeichen ersetzen, kuerzen) gehoert dem Portal.
    param([AllowNull()][AllowEmptyString()][string]$Label)
    if ([string]::IsNullOrWhiteSpace($Label)) { return $false }
    if ($Label.Length -gt $volumeLabelMax -or $Label -cne $Label.Trim()) { return $false }
    return $Label -notmatch '[\p{Cc}\p{Cf}\\/:*?"<>|]'
}

function Get-DiskScsiAddressMap {
    # Win32_DiskDrive.Index ist dieselbe Datentraegernummer wie MSFT_Disk.Number.
    $map = @{}
    foreach ($drive in @(Get-CimInstance -ClassName Win32_DiskDrive -ErrorAction Stop)) {
        $map[[int]$drive.Index] = [pscustomobject]@{
            Port = $drive.SCSIPort
            Bus = $drive.SCSIBus
            Target = $drive.SCSITargetId
            Lun = $drive.SCSILogicalUnit
        }
    }
    return $map
}

function Test-DiskScsiLayout {
    # Eine Zuordnung gilt nur fuer das Layout, das das Create-Playbook baut:
    # alle Platten an einem Controller (Port und Bus), LUN 0, jedes Target nur
    # einmal, die Boot-/Systemplatte auf Target 0.
    param([Parameter(Mandatory)][object[]]$Disks, [Parameter(Mandatory)][hashtable]$Addresses)
    $controllers = @{}
    $targets = @{}
    $bootOnZero = $false
    foreach ($disk in $Disks) {
        $address = $Addresses[[int]$disk.Number]
        if (-not $address -or $null -eq $address.Port -or $null -eq $address.Bus -or
            $null -eq $address.Target -or $null -eq $address.Lun -or [int]$address.Lun -ne 0) { return $false }
        $controllers['{0}:{1}' -f [int]$address.Port, [int]$address.Bus] = $true
        if ($targets.ContainsKey([int]$address.Target)) { return $false }
        $targets[[int]$address.Target] = $true
        if ([bool]$disk.IsBoot -or [bool]$disk.IsSystem) {
            if ([int]$address.Target -ne 0) { return $false }
            $bootOnZero = $true
        }
    }
    return ($controllers.Count -eq 1 -and $bootOnZero)
}

function Get-PortalDiskLayout {
    # getDeviceInfos mit der MAC und der Rolloutrevision des publizierten
    # Snapshots. Nur lesend; wirft nie.
    $none = @()
    $mac = $null
    $revision = 0
    $interfacesRoot = Get-VsSnapshotInterfacesRoot
    if ($interfacesRoot) {
        foreach ($entry in @(Get-ChildItem -Path $interfacesRoot -ErrorAction SilentlyContinue)) {
            $mac = ConvertTo-VsNormalizedMac ([string]$entry.GetValue('mac'))
            if ($mac) { break }
        }
    }
    if (-not $mac -or -not [int]::TryParse([string](Get-VsSnapshotValue -Name 'rollout_revision'), [ref]$revision) -or $revision -le 0) {
        return [pscustomobject]@{ Disks = $none; Reason = 'rollout_mismatch' }
    }
    $api = Resolve-VsApi
    if (-not $api) { return [pscustomobject]@{ Disks = $none; Reason = 'portal_unreachable' } }

    $response = $null
    $received = $false
    for ($attempt = 1; $attempt -le 2 -and -not $received; $attempt++) {
        try {
            $path = '/mecm-api.php?action=getDeviceInfos&mac={0}' -f [uri]::EscapeDataString($mac)
            $response = Invoke-RestMethod -Uri (Get-VsApiUrl -Api $api -Path $path) -TimeoutSec 10 -ErrorAction Stop
            $received = $true
        } catch {
            Write-VsClientLog -Level WARN ("getDeviceInfos fuer Datentraegernamen, Versuch {0}/2: {1}" -f $attempt, (Get-VsErrorDetail -ErrorRecord $_))
            if ($attempt -lt 2) { Start-Sleep -Seconds 5 }
        }
    }
    if (-not $received) { return [pscustomobject]@{ Disks = $none; Reason = 'portal_unreachable' } }

    $answered = 0
    if ($null -eq $response -or $response -is [array] -or -not $response.PSObject.Properties['rollout_revision'] -or
        -not [int]::TryParse([string]$response.rollout_revision, [ref]$answered) -or $answered -ne $revision) {
        return [pscustomobject]@{ Disks = $none; Reason = 'rollout_mismatch' }
    }
    if (-not $response.PSObject.Properties['disks']) {
        return [pscustomobject]@{ Disks = $none; Reason = 'portal_without_disk_names' }
    }
    $layout = @($response.disks | Where-Object { $null -ne $_ })
    $units = @{}
    foreach ($entry in $layout) {
        $size = 0L
        $unit = 0
        $complete = $entry.PSObject.Properties['scsi_unit'] -and $entry.PSObject.Properties['size_gb'] -and $entry.PSObject.Properties['volume_label']
        if (-not $complete -or -not [long]::TryParse([string]$entry.size_gb, [ref]$size) -or $size -le 0 -or
            ($null -ne $entry.scsi_unit -and -not [int]::TryParse([string]$entry.scsi_unit, [ref]$unit))) {
            return [pscustomobject]@{ Disks = $none; Reason = 'disk_list_invalid' }
        }
        if ($null -ne $entry.scsi_unit) {
            if ($units.ContainsKey($unit)) { return [pscustomobject]@{ Disks = $none; Reason = 'disk_list_invalid' } }
            $units[$unit] = $true
        }
    }
    if ($layout.Count -eq 0) { return [pscustomobject]@{ Disks = $none; Reason = 'disk_list_invalid' } }
    return [pscustomobject]@{ Disks = $layout; Reason = '' }
}

function Get-DiskLabelPlan {
    # Einmal je Lauf, erst wenn eine neue Platte eingerichtet wird. Wirft nie:
    # ein Name ist Komfort und darf die Plattenphase nicht scheitern lassen.
    param([Parameter(Mandatory)][object[]]$Disks)
    $addresses = @{}
    try {
        $addresses = Get-DiskScsiAddressMap
    } catch {
        Write-VsClientLog -Level WARN "SCSI-Adressen nicht lesbar: $($_.Exception.Message)"
        return [pscustomobject]@{ Reason = 'scsi_layout_unknown'; Disks = @(); Addresses = @{} }
    }
    if (-not (Test-DiskScsiLayout -Disks $Disks -Addresses $addresses)) {
        return [pscustomobject]@{ Reason = 'scsi_layout_unknown'; Disks = @(); Addresses = $addresses }
    }
    $portal = Get-PortalDiskLayoutSafe
    # Portal reicht die Antwort an den Block der Systemplatte weiter (DN2), damit
    # ein Lauf getDeviceInfos nur einmal fragt.
    return [pscustomobject]@{ Reason = [string]$portal.Reason; Disks = @($portal.Disks); Addresses = $addresses; Portal = $portal }
}

function Get-PortalDiskLayoutSafe {
    # Get-PortalDiskLayout, ein unerwarteter Fehler zaehlt als portal_unreachable.
    try {
        return Get-PortalDiskLayout
    } catch {
        Write-VsClientLog -Level WARN "Plattenliste des Portals nicht auswertbar: $($_.Exception.Message)"
        return [pscustomobject]@{ Disks = @(); Reason = 'portal_unreachable' }
    }
}

function Resolve-DiskVolumeLabel {
    param([Parameter(Mandatory)][object]$Disk, [Parameter(Mandatory)][object]$Plan)
    $fallback = Get-DefaultDiskLabel -Disk $Disk
    if ($Plan.Reason) { return [pscustomobject]@{ Label = $fallback; Reason = [string]$Plan.Reason } }

    $target = [int]$Plan.Addresses[[int]$Disk.Number].Target
    $hits = @($Plan.Disks | Where-Object { $null -ne $_.scsi_unit -and [int]$_.scsi_unit -eq $target })
    if ($hits.Count -ne 1) { return [pscustomobject]@{ Label = $fallback; Reason = 'no_portal_disk' } }
    if (([long]$hits[0].size_gb * 1GB) -ne [long]$Disk.Size) { return [pscustomobject]@{ Label = $fallback; Reason = 'size_mismatch' } }
    if ($null -eq $hits[0].volume_label) { return [pscustomobject]@{ Label = $fallback; Reason = 'no_usable_name' } }
    $label = [string]$hits[0].volume_label
    if (-not (Test-DiskVolumeLabel -Label $label)) { return [pscustomobject]@{ Label = $fallback; Reason = 'label_invalid' } }
    return [pscustomobject]@{ Label = $label; Reason = '' }
}

# --- Bezeichnung des Windows-Laufwerks (DN2) ---------------------------------
# Traegt die Systemplatte (Portalposition 0) im VM-Editor einen eigenen Namen,
# erhaelt das Windows-Laufwerk ihn nach der Task Sequence als Bezeichnung. Ohne
# eigenen Namen bleibt die Bezeichnung der Task Sequence. Der Block laeuft nach
# den Datenplatten, auch ohne jede Zusatzplatte; er formatiert nie, legt keine
# Partition an, schreibt kein Journal und nutzt Assert-DiskIsSafeTarget nicht.
# Ziel ist nur das Volume auf $env:SystemDrive, dessen Partition IsBoot meldet
# und auf der einen Bootplatte liegt; EFI, System Reserved und Recovery haben
# diesen Buchstaben nie. Die Bezeichnung wird vorher gelesen, nur bei
# Abweichung gesetzt und danach nachgelesen. Das Ergebnis steht als Token
# system=renamed|kept|skip:<Grund> im Detail; die Phase bleibt erfolgreich.
#   kept                        kein eigener Name (label_custom false), die
#                               Bezeichnung stimmt schon, oder sie wurde fuer
#                               diese Rolloutrevision schon gesetzt (Marker
#                               SystemVolumeLabelRevision); eine danach von Hand
#                               geaenderte Bezeichnung bleibt
#   portal_without_system_label Portal ohne label_custom (Stand vor DN2): nie
#                               umbenennen, auch wenn volume_label gesetzt ist
#   no_portal_disk              keine Portalplatte auf scsi_unit 0
#   scsi_layout_unknown         Bootplatte nicht auf SCSI-Target 0 oder Adressen
#                               nicht lesbar
#   system_volume_unknown       Windows-Laufwerk nicht sicher bestimmbar
#   system_label_failed         Setzen gescheitert oder Nachlesen weicht ab (WARN)
# Dazu die Gruende der Portalabfrage (rollout_mismatch, portal_unreachable,
# portal_without_disk_names, disk_list_invalid) und der Labelpruefung
# (no_usable_name, label_invalid) wie oben. Eine abweichende Portalgroesse ist
# nur eine Warnung im Log: die Groesse im Portal wirkt nur beim Erstellen.

function Get-SystemLabelMarker {
    $value = Get-ItemProperty -Path $registryPath -Name $systemLabelMarker -ErrorAction SilentlyContinue
    if ($null -eq $value) { return '' }
    return [string]$value.$systemLabelMarker
}

function Set-SystemLabelMarker {
    param([Parameter(Mandatory)][string]$Revision)
    Set-ItemProperty -Path $registryPath -Name $systemLabelMarker -Value $Revision -ErrorAction Stop
}

function Get-SystemVolumeTarget {
    # Das Volume des laufenden Windows oder ein Grund. Wirft nie.
    param([Parameter(Mandatory)][AllowEmptyCollection()][object[]]$Disks)
    $unknown = [pscustomobject]@{ Reason = 'system_volume_unknown'; Letter = $null; Disk = $null; Volume = $null }
    $drive = [string]$env:SystemDrive
    if ($drive -notmatch '^[A-Za-z]:$') { return $unknown }
    $letter = [char]$drive.Substring(0, 1).ToUpperInvariant()
    $bootDisks = @($Disks | Where-Object { [bool]$_.IsBoot })
    if ($bootDisks.Count -ne 1) { return $unknown }
    try {
        $partitions = @(Get-Partition -DriveLetter $letter -ErrorAction Stop)
        $volume = Get-Volume -DriveLetter $letter -ErrorAction Stop
    } catch {
        Write-VsClientLog -Level WARN "Windows-Laufwerk $drive nicht lesbar: $($_.Exception.Message)"
        return $unknown
    }
    if ($partitions.Count -ne 1 -or -not [bool]$partitions[0].IsBoot -or
        [int]$partitions[0].DiskNumber -ne [int]$bootDisks[0].Number -or
        $null -eq $volume -or [string]$volume.FileSystem -ne 'NTFS') { return $unknown }
    return [pscustomobject]@{ Reason = ''; Letter = $letter; Disk = $bootDisks[0]; Volume = $volume }
}

function Resolve-SystemVolumeLabel {
    # Entscheidet aus Portalliste, Ziel und SCSI-Adressen. Ergebnis Action =
    # set (mit Label), kept oder skip (mit Reason).
    param([Parameter(Mandatory)][object]$Portal, [Parameter(Mandatory)][object]$Target, [AllowNull()][hashtable]$Addresses)
    if ($Portal.Reason) { return [pscustomobject]@{ Action = 'skip'; Reason = [string]$Portal.Reason; Label = $null } }
    $hits = @($Portal.Disks | Where-Object { $null -ne $_.scsi_unit -and [int]$_.scsi_unit -eq 0 })
    if ($hits.Count -ne 1) { return [pscustomobject]@{ Action = 'skip'; Reason = 'no_portal_disk'; Label = $null } }
    $entry = $hits[0]
    if (-not $entry.PSObject.Properties['label_custom']) {
        return [pscustomobject]@{ Action = 'skip'; Reason = 'portal_without_system_label'; Label = $null }
    }
    if ($entry.label_custom -isnot [bool]) { return [pscustomobject]@{ Action = 'skip'; Reason = 'disk_list_invalid'; Label = $null } }
    if (-not $entry.label_custom) { return [pscustomobject]@{ Action = 'kept'; Reason = 'no_own_name'; Label = $null } }
    if ($Target.Reason) { return [pscustomobject]@{ Action = 'skip'; Reason = [string]$Target.Reason; Label = $null } }
    $address = $null
    if ($Addresses) { $address = $Addresses[[int]$Target.Disk.Number] }
    if (-not $address -or $null -eq $address.Target -or [int]$address.Target -ne 0) {
        return [pscustomobject]@{ Action = 'skip'; Reason = 'scsi_layout_unknown'; Label = $null }
    }
    if (([long]$entry.size_gb * 1GB) -ne [long]$Target.Disk.Size) {
        # F4: die Portalgroesse wirkt nur beim Erstellen und darf spaeter abweichen.
        Write-VsClientLog -Level WARN ("Systemplatte: Portalgroesse {0} GB weicht von der Platte ab ({1} Bytes); Bezeichnung trotzdem." -f $entry.size_gb, [long]$Target.Disk.Size)
    }
    if ($null -eq $entry.volume_label) { return [pscustomobject]@{ Action = 'skip'; Reason = 'no_usable_name'; Label = $null } }
    $label = [string]$entry.volume_label
    if (-not (Test-DiskVolumeLabel -Label $label)) { return [pscustomobject]@{ Action = 'skip'; Reason = 'label_invalid'; Label = $null } }
    return [pscustomobject]@{ Action = 'set'; Reason = ''; Label = $label }
}

function Set-SystemVolumeLabel {
    # Die einzige Stelle, die eine bestehende Bezeichnung aendert: nur das
    # Windows-Laufwerk, nur bei Abweichung, mit Nachlesen. Wirft bei Fehler.
    param([Parameter(Mandatory)][char]$Letter, [Parameter(Mandatory)][string]$Label)
    $before = Get-Volume -DriveLetter $Letter -ErrorAction Stop
    if ([string]$before.FileSystemLabel -ceq $Label) { return $false }
    Set-Volume -DriveLetter $Letter -NewFileSystemLabel $Label -ErrorAction Stop
    $after = Get-Volume -DriveLetter $Letter -ErrorAction Stop
    if ([string]$after.FileSystemLabel -cne $Label) {
        throw "Bezeichnung von ${Letter}: lautet nach dem Setzen '$($after.FileSystemLabel)' statt '$Label'."
    }
    Write-VsClientLog "Windows-Laufwerk ${Letter}: heisst jetzt '$Label' (vorher '$($before.FileSystemLabel)')."
    return $true
}

function Invoke-SystemVolumeLabel {
    # DN2-Block nach der Datenplattenschleife. Wirft nie; liefert das Token.
    param([Parameter(Mandatory)][AllowEmptyCollection()][object[]]$Disks, [AllowNull()][object]$Portal)
    try {
        $revision = [string](Get-VsSnapshotValue -Name 'rollout_revision')
        $marker = Get-SystemLabelMarker
        if ($revision -and $marker -ceq $revision) {
            Write-VsClientLog "Windows-Laufwerk: Bezeichnung fuer Rollout $revision schon gesetzt, bleibt."
            return [pscustomobject]@{ Token = 'kept'; Reason = 'already_applied' }
        }
        if ($null -eq $Portal) { $Portal = Get-PortalDiskLayoutSafe }
        $target = Get-SystemVolumeTarget -Disks $Disks
        $addresses = @{}
        if (-not $target.Reason -and -not $Portal.Reason) {
            try { $addresses = Get-DiskScsiAddressMap } catch {
                Write-VsClientLog -Level WARN "SCSI-Adressen nicht lesbar: $($_.Exception.Message)"
            }
        }
        $decision = Resolve-SystemVolumeLabel -Portal $Portal -Target $target -Addresses $addresses
        if ($decision.Action -eq 'kept') {
            Write-VsClientLog 'Systemplatte ohne eigenen Namen: Windows-Laufwerk behaelt die Bezeichnung der Task Sequence.'
            return [pscustomobject]@{ Token = 'kept'; Reason = [string]$decision.Reason }
        }
        if ($decision.Action -ne 'set') {
            Write-VsClientLog -Level WARN "Windows-Laufwerk behaelt seine Bezeichnung ($($decision.Reason))."
            return [pscustomobject]@{ Token = 'skip:' + [string]$decision.Reason; Reason = [string]$decision.Reason }
        }
    } catch {
        Write-VsClientLog -Level WARN "Bezeichnung des Windows-Laufwerks nicht bestimmbar: $($_.Exception.Message)"
        return [pscustomobject]@{ Token = 'skip:system_volume_unknown'; Reason = 'system_volume_unknown' }
    }

    try {
        $changed = Set-SystemVolumeLabel -Letter $target.Letter -Label ([string]$decision.Label)
    } catch {
        Write-VsClientLog -Level WARN "Bezeichnung des Windows-Laufwerks nicht gesetzt: $($_.Exception.Message)"
        return [pscustomobject]@{ Token = 'skip:system_label_failed'; Reason = 'system_label_failed' }
    }
    if ($revision) {
        try { Set-SystemLabelMarker -Revision $revision } catch {
            Write-VsClientLog -Level WARN "Marker der Systembezeichnung nicht gespeichert: $($_.Exception.Message)"
        }
    }
    if (-not $changed) {
        Write-VsClientLog "Windows-Laufwerk traegt die Portalbezeichnung '$($decision.Label)' bereits."
        return [pscustomobject]@{ Token = 'kept'; Reason = 'already_equal' }
    }
    return [pscustomobject]@{ Token = 'renamed'; Reason = '' }
}

function Invoke-OwnedRawDiskOperation {
    param([Parameter(Mandatory)][object]$Operation, [Parameter(Mandatory)][object]$Disk)

    Confirm-OperationDisk -Operation $Operation -Disk $Disk
    Clear-DiskReadOnly -Disk $Disk
    $Disk = Get-Disk -Number ([int]$Disk.Number) -ErrorAction Stop
    if ([string]$Disk.OperationalStatus -eq 'Offline') {
        $Disk | Set-Disk -IsOffline $false -ErrorAction Stop
    }
    $Disk = Get-Disk -Number ([int]$Disk.Number) -ErrorAction Stop
    Confirm-OperationDisk -Operation $Operation -Disk $Disk
    if ([string]$Disk.OperationalStatus -eq 'Offline') {
        throw "Datentraeger $($Disk.Number) ist nach Set-Disk weiterhin offline (Status $($Disk.OperationalStatus))."
    }
    # Manche Treiber melden den Schreibschutz erst nach dem Onlineschalten erneut.
    Clear-DiskReadOnly -Disk $Disk
    $Disk = Get-Disk -Number ([int]$Disk.Number) -ErrorAction Stop
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
    if ([string]$Disk.PartitionStyle -ne 'GPT' -or [bool]$Disk.IsReadOnly -or -not $verifiedPartition.DriveLetter -or
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
    $labelPlan = $null
    $named = 0
    $labelReasons = New-Object System.Collections.Generic.List[string]
    foreach ($disk in @($disks | Where-Object { [string]$_.OperationalStatus -eq 'Offline' })) {
        $identity = [string]$identityByNumber[[int]$disk.Number]
        if ($identity -cin $openIds) { continue }
        if ([string]$disk.PartitionStyle -eq 'RAW') {
            if ($null -eq $labelPlan) { $labelPlan = Get-DiskLabelPlan -Disks $disks }
            $resolved = Resolve-DiskVolumeLabel -Disk $disk -Plan $labelPlan
            if ($resolved.Reason) {
                [void]$labelReasons.Add([string]$resolved.Reason)
                Write-VsClientLog -Level WARN "Datentraeger $($disk.Number) erhaelt das Vorgabelabel '$($resolved.Label)' ($($resolved.Reason))."
            } else {
                $named++
                Write-VsClientLog "Datentraeger $($disk.Number) erhaelt den Portalnamen '$($resolved.Label)'."
            }
            $operation = New-DiskOperation -Disk $disk -Identity $identity -Label ([string]$resolved.Label)
            Invoke-OwnedRawDiskOperation -Operation $operation -Disk $disk
            $created++
        } else {
            # Schreibschutz vor dem Onlineschalten aufheben, sonst werden die
            # vorhandenen Volumes schreibgeschuetzt eingehaengt.
            Clear-DiskReadOnly -Disk $disk
            $disk | Set-Disk -IsOffline $false -ErrorAction Stop
            $after = Get-Disk -Number ([int]$disk.Number) -ErrorAction Stop
            if ([string]$after.OperationalStatus -eq 'Offline') {
                throw "Datentraeger $($disk.Number) ist nach Set-Disk weiterhin offline (Status $($after.OperationalStatus))."
            }
            Clear-DiskReadOnly -Disk $after
            Write-VsClientLog "Datentraeger $($disk.Number) online (bestehende Daten erhalten)."
            $existing++
        }
    }

    # DN2: Bezeichnung des Windows-Laufwerks, nach den Datenplatten und auch
    # ohne Zusatzplatte. Wirft nie; ein Fehlschlag ist WARN und ein Grund.
    $portalLayout = $null
    if ($null -ne $labelPlan -and $labelPlan.PSObject.Properties['Portal']) { $portalLayout = $labelPlan.Portal }
    $systemResult = Invoke-SystemVolumeLabel -Disks $disks -Portal $portalLayout
    $systemToken = 'system={0}' -f $systemResult.Token
    $systemRenamed = [int]([string]$systemResult.Token -eq 'renamed')

    # Das Systemtoken steht vor den DN1-Gruenden, damit die Kuerzung des
    # Details auf 255 Zeichen im Portal nie es trifft.
    $detail = "resumed={0} new={1} existing={2} {3}" -f $resumed, $created, $existing, $systemToken
    if ($created -gt 0) {
        # DN1: wie viele neue Platten ihren Portalnamen bekamen, und warum die
        # uebrigen beim Vorgabelabel blieben.
        $detail += " named={0}" -f $named
        if ($labelReasons.Count -gt 0) { $detail += " default={0} ({1})" -f $labelReasons.Count, ((@($labelReasons) | Select-Object -Unique) -join ',') }
    }
    if (($resumed + $created + $existing + $systemRenamed) -eq 0) { $detail = "optional: no disk work required $systemToken" }
    Set-DiskStatus -Status 'Success' -Extra @{ ProcessedDisks = [string]($resumed + $created + $existing + $systemRenamed); LastResult = $detail }
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
