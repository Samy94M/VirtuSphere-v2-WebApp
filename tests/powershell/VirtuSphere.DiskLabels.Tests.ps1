# DN1: Eine neue Datenplatte bekommt ihren Portalnamen als Laufwerksbezeichnung.
# Die Funktionen kommen per AST aus client_VMDisksOnline.ps1 (das Skript selbst
# laeuft beim Laden sofort los), die Platten sind synthetisch. Zuordnung ueber
# das SCSI-Target, Gegenprobe ueber die Groesse; jede Unsicherheit laesst das
# Vorgabelabel VM_Disk_<n> stehen und nennt einen Grund.

BeforeDiscovery {
    # Die -ForEach-Daten unten entstehen schon in der Discovery-Phase.
    function New-TestAddress {
        param([int]$Target, [int]$Port = 0, [int]$Bus = 0, [int]$Lun = 0)
        [pscustomobject]@{ Port = $Port; Bus = $Bus; Target = $Target; Lun = $Lun }
    }
}

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:ClientScript = Join-Path (Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'clients') 'client_VMDisksOnline.ps1'
    $script:LayoutPhp = Join-Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'Docker') 'WebAPI') 'lib') 'vm_disk_layout.php'
    $script:ClientText = Get-Content -Path $script:ClientScript -Raw

    $tokens = $null
    $errors = $null
    $ast = [System.Management.Automation.Language.Parser]::ParseInput($script:ClientText, [ref]$tokens, [ref]$errors)
    $names = @('Get-DefaultDiskLabel', 'Test-DiskVolumeLabel', 'Get-DiskScsiAddressMap', 'Test-DiskScsiLayout',
        'Get-PortalDiskLayout', 'Get-PortalDiskLayoutSafe', 'Get-DiskLabelPlan', 'Resolve-DiskVolumeLabel',
        'Get-SystemLabelMarker', 'Set-SystemLabelMarker', 'Get-SystemVolumeTarget', 'Resolve-SystemVolumeLabel',
        'Set-SystemVolumeLabel', 'Invoke-SystemVolumeLabel')
    $script:FunctionText = @{}
    foreach ($name in $names) {
        $fn = $ast.Find({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq $name }, $true)
        $script:FunctionText[$name] = $fn.Extent.Text
        . ([scriptblock]::Create($fn.Extent.Text))
    }
    $fn = $ast.Find({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'Invoke-OwnedRawDiskOperation' }, $true)
    $script:FunctionText['Invoke-OwnedRawDiskOperation'] = $fn.Extent.Text

    # Stubs, damit Mock ohne Client-Common, CIM, Storage-Modul und Portal greift.
    function Write-VsClientLog { param([Parameter(ValueFromRemainingArguments)]$Rest) }
    function Get-VsSnapshotInterfacesRoot { }
    function Get-VsSnapshotValue { param($Name) }
    function Resolve-VsApi { }
    function Get-VsApiUrl { param($Api, $Path) return "https://$Api$Path" }
    function Get-VsErrorDetail { param($ErrorRecord) return 'boom' }
    function ConvertTo-VsNormalizedMac { param($Mac) if ($Mac) { return ([string]$Mac).ToUpperInvariant() } }
    function Get-CimInstance { param($ClassName, $ErrorAction) }
    function Get-Partition { param($DriveLetter, $ErrorAction) }
    function Get-Volume { param($DriveLetter, $ErrorAction) }
    function Set-Volume { param($DriveLetter, $NewFileSystemLabel, $ErrorAction) }
    $registryPath = 'HKLM:\SOFTWARE\VirtuSphere\VMDiskManagement'
    $systemLabelMarker = 'SystemVolumeLabelRevision'

    $volumeLabelMax = 32
    $script:volumeLabelMax = 32
    $script:Gb = 1073741824L

    function New-TestDisk {
        param([int]$Number, [long]$SizeGb, [switch]$Boot)
        [pscustomobject]@{ Number = $Number; Size = $SizeGb * $script:Gb; IsBoot = [bool]$Boot; IsSystem = [bool]$Boot }
    }
    function New-TestAddress {
        param([int]$Target, [int]$Port = 0, [int]$Bus = 0, [int]$Lun = 0)
        [pscustomobject]@{ Port = $Port; Bus = $Bus; Target = $Target; Lun = $Lun }
    }
    function New-TestInterfaceEntry {
        param([string]$Mac)
        $entry = [pscustomobject]@{ Mac = $Mac }
        $entry | Add-Member -MemberType ScriptMethod -Name GetValue -Value { param($n) if ($n -eq 'mac') { return $this.Mac } }
        return $entry
    }
}

Describe 'DN1 Datentraegername: Spiegel und Grenzen' {
    It 'haelt die Labelgrenze gleich mit VIRTUSPHERE_DISK_VOLUME_LABEL_MAX' {
        $php = Get-Content -Path $script:LayoutPhp -Raw
        $php | Should -Match 'const VIRTUSPHERE_DISK_VOLUME_LABEL_MAX = (\d+);'
        $phpMax = [int]([regex]::Match($php, 'const VIRTUSPHERE_DISK_VOLUME_LABEL_MAX = (\d+);').Groups[1].Value)
        $script:ClientText | Should -Match ('(?m)^\$volumeLabelMax = {0}$' -f $phpMax)
    }

    It 'prueft dieselbe Zeichenklasse, die vm_disk_volume_label() ersetzt' {
        # Weicht sie ab, faellt der Client sicher auf VM_Disk_<n> zurueck
        # (label_invalid), aber still. Beide Seiten auf die Regex normalisieren.
        $php = Get-Content -Path $script:LayoutPhp -Raw
        $phpMatch = [regex]::Match($php, "preg_replace\('/\[(?<class>[^\]]+)\]\+/u'")
        $phpMatch.Success | Should -BeTrue
        $phpClass = $phpMatch.Groups['class'].Value.Replace('\\\\', '\\').Replace('\/', '/')
        $clientMatch = [regex]::Match($script:ClientText, "-notmatch '\[(?<class>[^\]]+)\]'")
        $clientMatch.Success | Should -BeTrue
        $clientMatch.Groups['class'].Value | Should -BeExactly $phpClass
    }

    It 'nimmt gueltige Labels bis zur Grenze an' -ForEach @(
        @{ Label = 'Daten' }, @{ Label = "Gr$([char]0xF6)$([char]0xDF)e $([char]0xDC)bung" }, @{ Label = ('a' * 32) }, @{ Label = 'Disk 2' }
    ) {
        Test-DiskVolumeLabel -Label $Label | Should -BeTrue
    }

    It 'lehnt <Why> ab' -ForEach @(
        @{ Label = $null; Why = 'null' }, @{ Label = ''; Why = 'leer' }, @{ Label = '   '; Why = 'nur Leerraum' },
        @{ Label = ('a' * 33); Why = '33 Zeichen' }, @{ Label = ' Daten'; Why = 'Leerraum am Rand' },
        @{ Label = 'Daten/Logs'; Why = 'Schraegstrich' }, @{ Label = 'a:b'; Why = 'Doppelpunkt' },
        @{ Label = "a`tb"; Why = 'Tabulator' }, @{ Label = "a$([char]0x200B)b"; Why = 'Nullbreitenzeichen' }
    ) {
        Test-DiskVolumeLabel -Label $Label | Should -BeFalse
    }

    It 'faellt auf VM_Disk_<Nummer> zurueck' {
        Get-DefaultDiskLabel -Disk (New-TestDisk -Number 3 -SizeGb 10) | Should -Be 'VM_Disk_3'
    }
}

Describe 'DN1 Test-DiskScsiLayout' {
    BeforeAll {
        $script:Disks = @((New-TestDisk -Number 0 -SizeGb 60 -Boot), (New-TestDisk -Number 1 -SizeGb 100), (New-TestDisk -Number 2 -SizeGb 20))
    }

    It 'akzeptiert das Layout des Create-Playbooks' {
        $addresses = @{ 0 = (New-TestAddress 0); 1 = (New-TestAddress 1); 2 = (New-TestAddress 2) }
        Test-DiskScsiLayout -Disks $script:Disks -Addresses $addresses | Should -BeTrue
    }

    It 'lehnt <Why> ab' -ForEach @(
        @{ Why = 'einen zweiten Controller'; Addresses = @{ 0 = (New-TestAddress 0); 1 = (New-TestAddress 1); 2 = (New-TestAddress 0 -Port 1) } }
        @{ Why = 'einen zweiten Bus'; Addresses = @{ 0 = (New-TestAddress 0); 1 = (New-TestAddress 1); 2 = (New-TestAddress 2 -Bus 1) } }
        @{ Why = 'eine LUN ungleich 0'; Addresses = @{ 0 = (New-TestAddress 0); 1 = (New-TestAddress 1); 2 = (New-TestAddress 2 -Lun 1) } }
        @{ Why = 'ein doppeltes Target'; Addresses = @{ 0 = (New-TestAddress 0); 1 = (New-TestAddress 1); 2 = (New-TestAddress 1) } }
        @{ Why = 'eine Bootplatte ausserhalb Target 0'; Addresses = @{ 0 = (New-TestAddress 3); 1 = (New-TestAddress 1); 2 = (New-TestAddress 2) } }
        @{ Why = 'eine Platte ohne Adresse'; Addresses = @{ 0 = (New-TestAddress 0); 1 = (New-TestAddress 1) } }
    ) {
        Test-DiskScsiLayout -Disks $script:Disks -Addresses $Addresses | Should -BeFalse
    }

    It 'lehnt ein Layout ohne Bootplatte ab' {
        $disks = @((New-TestDisk -Number 1 -SizeGb 100), (New-TestDisk -Number 2 -SizeGb 20))
        Test-DiskScsiLayout -Disks $disks -Addresses @{ 1 = (New-TestAddress 1); 2 = (New-TestAddress 2) } | Should -BeFalse
    }
}

Describe 'DN1 Resolve-DiskVolumeLabel' {
    BeforeAll {
        # Position 7 liegt auf Unit 8: Unit 7 gehoert dem Controller.
        $script:Plan = [pscustomobject]@{
            Reason = ''
            Addresses = @{ 0 = (New-TestAddress 0); 1 = (New-TestAddress 1); 2 = (New-TestAddress 2); 3 = (New-TestAddress 8); 4 = (New-TestAddress 3); 5 = (New-TestAddress 4); 6 = (New-TestAddress 5) }
            Disks = @(
                [pscustomobject]@{ position = 0; scsi_unit = 0; size_gb = 60; volume_label = 'System' }
                [pscustomobject]@{ position = 1; scsi_unit = 1; size_gb = 100; volume_label = 'Daten Logs' }
                [pscustomobject]@{ position = 2; scsi_unit = 2; size_gb = 20; volume_label = $null }
                [pscustomobject]@{ position = 3; scsi_unit = 3; size_gb = 30; volume_label = 'bad/label' }
                [pscustomobject]@{ position = 4; scsi_unit = 4; size_gb = 40; volume_label = 'Archiv' }
                [pscustomobject]@{ position = 7; scsi_unit = 8; size_gb = 80; volume_label = 'Achte' }
            )
        }
    }

    It 'nimmt den Portalnamen bei passendem Target und passender Groesse' {
        $result = Resolve-DiskVolumeLabel -Disk (New-TestDisk -Number 1 -SizeGb 100) -Plan $script:Plan
        $result.Label | Should -Be 'Daten Logs'
        $result.Reason | Should -Be ''
    }

    It 'ordnet Position 7 ueber Unit 8 zu' {
        (Resolve-DiskVolumeLabel -Disk (New-TestDisk -Number 3 -SizeGb 80) -Plan $script:Plan).Label | Should -Be 'Achte'
    }

    It 'behaelt das Vorgabelabel mit Grund <Reason>' -ForEach @(
        @{ Number = 1; SizeGb = 101; Reason = 'size_mismatch' }
        @{ Number = 2; SizeGb = 20; Reason = 'no_usable_name' }
        @{ Number = 4; SizeGb = 30; Reason = 'label_invalid' }
        @{ Number = 6; SizeGb = 50; Reason = 'no_portal_disk' }
    ) {
        $result = Resolve-DiskVolumeLabel -Disk (New-TestDisk -Number $Number -SizeGb $SizeGb) -Plan $script:Plan
        $result.Label | Should -Be ('VM_Disk_{0}' -f $Number)
        $result.Reason | Should -Be $Reason
    }

    It 'reicht den Grund des Plans unveraendert durch' {
        $plan = [pscustomobject]@{ Reason = 'portal_unreachable'; Disks = @(); Addresses = @{} }
        $result = Resolve-DiskVolumeLabel -Disk (New-TestDisk -Number 1 -SizeGb 100) -Plan $plan
        $result.Label | Should -Be 'VM_Disk_1'
        $result.Reason | Should -Be 'portal_unreachable'
    }
}

Describe 'DN1 Get-PortalDiskLayout' {
    BeforeEach {
        Mock Get-VsSnapshotInterfacesRoot { 'HKLM:\Fake\Interfaces' }
        Mock Get-ChildItem { New-TestInterfaceEntry -Mac '00:50:56:aa:bb:01' }
        Mock Get-VsSnapshotValue { '4' } -ParameterFilter { $Name -eq 'rollout_revision' }
        Mock Resolve-VsApi { 'portal.test' }
        Mock Start-Sleep { }
    }

    It 'liefert die Plattenliste bei passender Revision' {
        Mock Invoke-RestMethod { [pscustomobject]@{ rollout_revision = 4; disks = @(
            [pscustomobject]@{ position = 0; scsi_unit = 0; size_gb = 60; volume_label = 'System' },
            [pscustomobject]@{ position = 1; scsi_unit = 1; size_gb = 100; volume_label = 'Daten' }) } }
        $result = Get-PortalDiskLayout
        $result.Reason | Should -Be ''
        @($result.Disks).Count | Should -Be 2
        Should -Invoke Invoke-RestMethod -Times 1 -Exactly -ParameterFilter { $Uri -like '*action=getDeviceInfos&mac=00*50*56*AA*BB*01' }
    }

    It 'meldet rollout_mismatch ohne Snapshot und fragt nicht' {
        Mock Get-VsSnapshotInterfacesRoot { $null }
        Mock Invoke-RestMethod { throw 'nie' }
        (Get-PortalDiskLayout).Reason | Should -Be 'rollout_mismatch'
        Should -Invoke Invoke-RestMethod -Times 0 -Exactly
    }

    It 'meldet rollout_mismatch bei anderer Revision' {
        Mock Invoke-RestMethod { [pscustomobject]@{ rollout_revision = 5; disks = @() } }
        (Get-PortalDiskLayout).Reason | Should -Be 'rollout_mismatch'
    }

    It 'meldet rollout_mismatch, wenn das Portal die MAC nicht mehr kennt' {
        Mock Invoke-RestMethod { $null }
        (Get-PortalDiskLayout).Reason | Should -Be 'rollout_mismatch'
    }

    It 'meldet portal_without_disk_names bei einem Portal vor DN1' {
        Mock Invoke-RestMethod { [pscustomobject]@{ rollout_revision = 4; vm_name = 'VM-TEST-01' } }
        (Get-PortalDiskLayout).Reason | Should -Be 'portal_without_disk_names'
    }

    It 'meldet portal_unreachable nach zwei Fehlversuchen' {
        Mock Invoke-RestMethod { throw 'timeout' }
        (Get-PortalDiskLayout).Reason | Should -Be 'portal_unreachable'
        Should -Invoke Invoke-RestMethod -Times 2 -Exactly
    }

    It 'meldet portal_unreachable ohne erreichbares Portal' {
        Mock Resolve-VsApi { $null }
        (Get-PortalDiskLayout).Reason | Should -Be 'portal_unreachable'
    }

    It 'meldet disk_list_invalid fuer <Why>' -ForEach @(
        @{ Why = 'eine leere Liste'; Disks = @() }
        @{ Why = 'eine doppelte Unit'; Disks = @(@{ scsi_unit = 1; size_gb = 1; volume_label = 'a' }, @{ scsi_unit = 1; size_gb = 2; volume_label = 'b' }) }
        @{ Why = 'eine fehlende Groesse'; Disks = @(@{ scsi_unit = 1; volume_label = 'a' }) }
        @{ Why = 'eine Groesse 0'; Disks = @(@{ scsi_unit = 1; size_gb = 0; volume_label = 'a' }) }
    ) {
        $script:Payload = [pscustomobject]@{ rollout_revision = 4; disks = @($Disks | ForEach-Object { [pscustomobject]$_ }) }
        Mock Invoke-RestMethod { $script:Payload }
        (Get-PortalDiskLayout).Reason | Should -Be 'disk_list_invalid'
    }
}

Describe 'DN1 Get-DiskLabelPlan' {
    BeforeAll {
        $script:Disks = @((New-TestDisk -Number 0 -SizeGb 60 -Boot), (New-TestDisk -Number 1 -SizeGb 100))
    }

    It 'wertet unlesbare SCSI-Adressen als scsi_layout_unknown und fragt das Portal nicht' {
        Mock Get-CimInstance { throw 'WMI' }
        Mock Get-PortalDiskLayout { throw 'nie' }
        (Get-DiskLabelPlan -Disks $script:Disks).Reason | Should -Be 'scsi_layout_unknown'
        Should -Invoke Get-PortalDiskLayout -Times 0 -Exactly
    }

    It 'verbindet Adressen und Portalliste' {
        Mock Get-CimInstance { @(
            [pscustomobject]@{ Index = 0; SCSIPort = 2; SCSIBus = 0; SCSITargetId = 0; SCSILogicalUnit = 0 },
            [pscustomobject]@{ Index = 1; SCSIPort = 2; SCSIBus = 0; SCSITargetId = 1; SCSILogicalUnit = 0 }) }
        Mock Get-PortalDiskLayout { [pscustomobject]@{ Reason = ''; Disks = @([pscustomobject]@{ scsi_unit = 1; size_gb = 100; volume_label = 'Daten' }) } }
        $plan = Get-DiskLabelPlan -Disks $script:Disks
        $plan.Reason | Should -Be ''
        (Resolve-DiskVolumeLabel -Disk $script:Disks[1] -Plan $plan).Label | Should -Be 'Daten'
    }

    It 'faengt einen Fehler der Portalabfrage als portal_unreachable' {
        Mock Get-CimInstance { @(
            [pscustomobject]@{ Index = 0; SCSIPort = 2; SCSIBus = 0; SCSITargetId = 0; SCSILogicalUnit = 0 },
            [pscustomobject]@{ Index = 1; SCSIPort = 2; SCSIBus = 0; SCSITargetId = 1; SCSILogicalUnit = 0 }) }
        Mock Get-PortalDiskLayout { throw 'kaputt' }
        (Get-DiskLabelPlan -Disks $script:Disks).Reason | Should -Be 'portal_unreachable'
    }
}

Describe 'DN1 Ablauf im Skript' {
    It 'holt Namen nur fuer neue RAW-Platten und schreibt das Label ins Journal' {
        $raw = $script:ClientText.IndexOf("if ([string]`$disk.PartitionStyle -eq 'RAW') {")
        $plan = $script:ClientText.IndexOf('Get-DiskLabelPlan -Disks $disks')
        $create = $script:ClientText.IndexOf('New-DiskOperation -Disk $disk -Identity $identity -Label')
        $raw | Should -BeGreaterThan -1
        $plan | Should -BeGreaterThan $raw
        $create | Should -BeGreaterThan $plan
        # Die Wiederaufnahme formatiert mit dem Journal-Label, nie mit einem neu geholten.
        $script:ClientText | Should -Match '-NewFileSystemLabel \$Operation\.Label'
    }

    It 'benennt nie eine bestehende Datenplatte um' {
        # DN2 erlaubt genau einen Umbenennungsaufruf, und nur fuer das
        # Windows-Laufwerk (Describe "DN2 Ablauf im Skript"). Die Datenplatten-
        # strecke kennt ihn nicht.
        [regex]::Matches($script:ClientText, 'Set-Volume').Count | Should -Be 1
        $script:FunctionText['Invoke-OwnedRawDiskOperation'] | Should -Not -Match 'Set-Volume'
        $script:FunctionText['Resolve-DiskVolumeLabel'] | Should -Not -Match 'Set-Volume'
        $script:ClientText | Should -Not -Match 'Set-Partition[^\r\n]*-NewDriveLetter'
    }

    It 'nennt jeden Grund im Kopfkommentar' {
        foreach ($reason in @('rollout_mismatch', 'portal_unreachable', 'portal_without_disk_names', 'disk_list_invalid',
                'scsi_layout_unknown', 'no_portal_disk', 'size_mismatch', 'no_usable_name', 'label_invalid')) {
            $script:ClientText | Should -Match ('#\s+{0}\s' -f $reason)
        }
    }
}

# DN2: Traegt die Systemplatte im Portal einen eigenen Namen (label_custom),
# erhaelt das Windows-Laufwerk ihn als Bezeichnung. Alles andere laesst die
# Bezeichnung der Task Sequence stehen; ein Fehlschlag ist nur WARN.
Describe 'DN2 Get-SystemVolumeTarget' {
    BeforeAll {
        $script:BootDisks = @((New-TestDisk -Number 0 -SizeGb 60 -Boot), (New-TestDisk -Number 1 -SizeGb 100))
    }
    BeforeEach {
        $script:SavedSystemDrive = $env:SystemDrive
        $env:SystemDrive = 'C:'
        Mock Get-Partition { [pscustomobject]@{ DiskNumber = 0; IsBoot = $true; IsSystem = $false } }
        Mock Get-Volume { [pscustomobject]@{ FileSystem = 'NTFS'; FileSystemLabel = 'Windows' } }
    }
    AfterEach { $env:SystemDrive = $script:SavedSystemDrive }

    It 'findet das NTFS-Bootvolume auf der Bootplatte' {
        $target = Get-SystemVolumeTarget -Disks $script:BootDisks
        $target.Reason | Should -Be ''
        $target.Letter | Should -Be ([char]'C')
        $target.Disk.Number | Should -Be 0
    }

    It 'nimmt den Buchstaben aus SystemDrive, nicht fest C:' {
        $env:SystemDrive = 'd:'
        (Get-SystemVolumeTarget -Disks $script:BootDisks).Letter | Should -Be ([char]'D')
        Should -Invoke Get-Partition -Times 1 -Exactly -ParameterFilter { [string]$DriveLetter -eq 'D' }
        Should -Invoke Get-Volume -Times 1 -Exactly -ParameterFilter { [string]$DriveLetter -eq 'D' }
    }

    It 'meldet system_volume_unknown fuer <Why>' -ForEach @(
        @{ Why = 'ein leeres SystemDrive'; Drive = ''; Partition = $null; Volume = $null; Throw = $false; TwoBoot = $false }
        @{ Why = 'eine Partition ohne IsBoot (EFI, System Reserved)'; Drive = 'C:'; Partition = @{ DiskNumber = 0; IsBoot = $false }; Volume = $null; Throw = $false; TwoBoot = $false }
        @{ Why = 'eine Partition auf einer anderen Platte'; Drive = 'C:'; Partition = @{ DiskNumber = 1; IsBoot = $true }; Volume = $null; Throw = $false; TwoBoot = $false }
        @{ Why = 'ein Volume ohne NTFS'; Drive = 'C:'; Partition = $null; Volume = @{ FileSystem = 'FAT32'; FileSystemLabel = 'SYSTEM' }; Throw = $false; TwoBoot = $false }
        @{ Why = 'einen Lesefehler'; Drive = 'C:'; Partition = $null; Volume = $null; Throw = $true; TwoBoot = $false }
        @{ Why = 'zwei Bootplatten'; Drive = 'C:'; Partition = $null; Volume = $null; Throw = $false; TwoBoot = $true }
    ) {
        $env:SystemDrive = $Drive
        if ($Partition) { $script:P = [pscustomobject]$Partition; Mock Get-Partition { $script:P } }
        if ($Volume) { $script:V = [pscustomobject]$Volume; Mock Get-Volume { $script:V } }
        if ($Throw) { Mock Get-Partition { throw 'nicht lesbar' } }
        $disks = $script:BootDisks
        if ($TwoBoot) { $disks = @((New-TestDisk -Number 0 -SizeGb 60 -Boot), (New-TestDisk -Number 1 -SizeGb 60 -Boot)) }
        $target = Get-SystemVolumeTarget -Disks $disks
        $target.Reason | Should -Be 'system_volume_unknown'
        $target.Letter | Should -BeNullOrEmpty
    }
}

Describe 'DN2 Resolve-SystemVolumeLabel' {
    BeforeAll {
        $script:Target = [pscustomobject]@{ Reason = ''; Letter = [char]'C'; Disk = (New-TestDisk -Number 0 -SizeGb 60 -Boot); Volume = $null }
        $script:Addresses = @{ 0 = (New-TestAddress 0) }
        function New-SystemPortal {
            param([hashtable]$Entry = @{}, [string]$Reason = '', [string]$Drop = '')
            $disk = [pscustomobject]@{ position = 0; scsi_unit = 0; size_gb = 60; volume_label = 'Ablage'; label_custom = $true }
            foreach ($key in $Entry.Keys) {
                $disk | Add-Member -NotePropertyName $key -NotePropertyValue $Entry[$key] -Force
            }
            if ($Drop) { $disk.PSObject.Properties.Remove($Drop) }
            $data = [pscustomobject]@{ position = 1; scsi_unit = 1; size_gb = 20; volume_label = 'Daten'; label_custom = $true }
            return [pscustomobject]@{ Reason = $Reason; Disks = @($disk, $data) }
        }
    }
    BeforeEach { Mock Write-VsClientLog { } }

    It 'setzt einen eigenen Namen' {
        $decision = Resolve-SystemVolumeLabel -Portal (New-SystemPortal) -Target $script:Target -Addresses $script:Addresses
        $decision.Action | Should -Be 'set'
        $decision.Label | Should -BeExactly 'Ablage'
    }

    It 'reicht ein Umlaut-Label unveraendert durch' {
        $label = "$([char]0xD6)lstand Pr$([char]0xFC)fung"
        (Resolve-SystemVolumeLabel -Portal (New-SystemPortal -Entry @{ volume_label = $label }) -Target $script:Target -Addresses $script:Addresses).Label | Should -BeExactly $label
    }

    It 'laesst die Bezeichnung ohne eigenen Namen stehen' {
        $decision = Resolve-SystemVolumeLabel -Portal (New-SystemPortal -Entry @{ label_custom = $false; volume_label = $null }) -Target $script:Target -Addresses $script:Addresses
        $decision.Action | Should -Be 'kept'
    }

    It 'benennt gegen ein Portal ohne label_custom nie um, auch wenn es System liefert (H1)' {
        $decision = Resolve-SystemVolumeLabel -Portal (New-SystemPortal -Entry @{ volume_label = 'System' } -Drop 'label_custom') -Target $script:Target -Addresses $script:Addresses
        $decision.Action | Should -Be 'skip'
        $decision.Reason | Should -Be 'portal_without_system_label'
    }

    It 'meldet <Reason> fuer <Why>' -ForEach @(
        @{ Why = 'ein label_custom als Text'; Entry = @{ label_custom = 'false' }; PortalReason = ''; Reason = 'disk_list_invalid' }
        @{ Why = 'kein Label trotz eigenem Namen'; Entry = @{ volume_label = $null }; PortalReason = ''; Reason = 'no_usable_name' }
        @{ Why = 'ein Label, das NTFS nicht nimmt'; Entry = @{ volume_label = 'a/b' }; PortalReason = ''; Reason = 'label_invalid' }
        @{ Why = 'keine Portalplatte auf Unit 0'; Entry = @{ scsi_unit = 3 }; PortalReason = ''; Reason = 'no_portal_disk' }
        @{ Why = 'einen Grund der Portalabfrage'; Entry = @{}; PortalReason = 'portal_unreachable'; Reason = 'portal_unreachable' }
    ) {
        $decision = Resolve-SystemVolumeLabel -Portal (New-SystemPortal -Entry $Entry -Reason $PortalReason) -Target $script:Target -Addresses $script:Addresses
        $decision.Action | Should -Be 'skip'
        $decision.Reason | Should -Be $Reason
    }

    It 'meldet system_volume_unknown ohne sicheres Ziel' {
        $target = [pscustomobject]@{ Reason = 'system_volume_unknown'; Letter = $null; Disk = $null; Volume = $null }
        (Resolve-SystemVolumeLabel -Portal (New-SystemPortal) -Target $target -Addresses @{}).Reason | Should -Be 'system_volume_unknown'
    }

    It 'meldet scsi_layout_unknown, wenn die Bootplatte nicht auf Target 0 liegt' {
        (Resolve-SystemVolumeLabel -Portal (New-SystemPortal) -Target $script:Target -Addresses @{ 0 = (New-TestAddress 2) }).Reason | Should -Be 'scsi_layout_unknown'
        (Resolve-SystemVolumeLabel -Portal (New-SystemPortal) -Target $script:Target -Addresses @{}).Reason | Should -Be 'scsi_layout_unknown'
    }

    It 'benennt bei einem zweiten Controller trotzdem um (nur die Datenplatten brauchen das Layout)' {
        $addresses = @{ 0 = (New-TestAddress 0); 1 = (New-TestAddress 0 -Port 1) }
        (Resolve-SystemVolumeLabel -Portal (New-SystemPortal) -Target $script:Target -Addresses $addresses).Action | Should -Be 'set'
    }

    It 'benennt bei abweichender Portalgroesse um und warnt nur (F4)' {
        $decision = Resolve-SystemVolumeLabel -Portal (New-SystemPortal -Entry @{ size_gb = 80 }) -Target $script:Target -Addresses $script:Addresses
        $decision.Action | Should -Be 'set'
        Should -Invoke Write-VsClientLog -Times 1 -Exactly -ParameterFilter { ($Rest -join ' ') -match 'WARN' -and ($Rest -join ' ') -match 'Portalgroesse' }
    }
}

Describe 'DN2 Set-SystemVolumeLabel' {
    BeforeEach {
        Mock Write-VsClientLog { }
        $script:Written = $null
        Mock Set-Volume { $script:Written = [string]$NewFileSystemLabel }
        Mock Get-Volume {
            $current = 'Windows'
            if ($null -ne $script:Written) { $current = $script:Written }
            [pscustomobject]@{ FileSystem = 'NTFS'; FileSystemLabel = $current }
        }
    }

    It 'setzt bei Abweichung und liest nach' {
        Set-SystemVolumeLabel -Letter 'C' -Label 'Ablage' | Should -BeTrue
        Should -Invoke Set-Volume -Times 1 -Exactly -ParameterFilter { [string]$DriveLetter -eq 'C' -and $NewFileSystemLabel -ceq 'Ablage' }
        Should -Invoke Get-Volume -Times 2 -Exactly
    }

    It 'schreibt nicht, wenn die Bezeichnung schon stimmt' {
        Set-SystemVolumeLabel -Letter 'C' -Label 'Windows' | Should -BeFalse
        Should -Invoke Set-Volume -Times 0 -Exactly
    }

    It 'vergleicht mit Gross- und Kleinschreibung' {
        Set-SystemVolumeLabel -Letter 'C' -Label 'WINDOWS' | Should -BeTrue
        Should -Invoke Set-Volume -Times 1 -Exactly
    }

    It 'wirft, wenn das Nachlesen abweicht' {
        Mock Set-Volume { }
        { Set-SystemVolumeLabel -Letter 'C' -Label 'Ablage' } | Should -Throw '*statt*'
    }
}

Describe 'DN2 Invoke-SystemVolumeLabel' {
    BeforeAll {
        $script:OkTarget = [pscustomobject]@{ Reason = ''; Letter = [char]'C'; Disk = (New-TestDisk -Number 0 -SizeGb 60 -Boot); Volume = $null }
        $script:Disks = @((New-TestDisk -Number 0 -SizeGb 60 -Boot))
        function New-OwnPortal {
            param([object]$Custom = $true, [AllowNull()][object]$Label = 'Ablage', [switch]$NoFlag)
            $entry = [pscustomobject]@{ position = 0; scsi_unit = 0; size_gb = 60; volume_label = $Label }
            if (-not $NoFlag) { $entry | Add-Member -NotePropertyName label_custom -NotePropertyValue $Custom }
            return [pscustomobject]@{ Reason = ''; Disks = @($entry) }
        }
    }
    BeforeEach {
        Mock Write-VsClientLog { }
        Mock Get-VsSnapshotValue { '4' } -ParameterFilter { $Name -eq 'rollout_revision' }
        Mock Get-SystemLabelMarker { '' }
        Mock Set-SystemLabelMarker { }
        Mock Get-PortalDiskLayoutSafe { New-OwnPortal }
        Mock Get-SystemVolumeTarget { $script:OkTarget }
        Mock Get-DiskScsiAddressMap { @{ 0 = (New-TestAddress 0) } }
        Mock Set-SystemVolumeLabel { $true }
    }

    It 'benennt um und merkt sich die Rolloutrevision' {
        (Invoke-SystemVolumeLabel -Disks $script:Disks -Portal $null).Token | Should -Be 'renamed'
        Should -Invoke Set-SystemVolumeLabel -Times 1 -Exactly -ParameterFilter { [string]$Letter -eq 'C' -and $Label -ceq 'Ablage' }
        Should -Invoke Set-SystemLabelMarker -Times 1 -Exactly -ParameterFilter { $Revision -eq '4' }
    }

    It 'fasst das Laufwerk im Wiederlauf derselben Revision nicht an und fragt das Portal nicht' {
        Mock Get-SystemLabelMarker { '4' }
        (Invoke-SystemVolumeLabel -Disks $script:Disks -Portal $null).Token | Should -Be 'kept'
        Should -Invoke Get-PortalDiskLayoutSafe -Times 0 -Exactly
        Should -Invoke Set-SystemVolumeLabel -Times 0 -Exactly
    }

    It 'benennt nach einer neuen Revision wieder um' {
        Mock Get-SystemLabelMarker { '3' }
        (Invoke-SystemVolumeLabel -Disks $script:Disks -Portal $null).Token | Should -Be 'renamed'
    }

    It 'nutzt die Portalantwort der Datenplatten und fragt nicht erneut' {
        (Invoke-SystemVolumeLabel -Disks $script:Disks -Portal (New-OwnPortal -Label 'Andere')).Token | Should -Be 'renamed'
        Should -Invoke Get-PortalDiskLayoutSafe -Times 0 -Exactly
        Should -Invoke Set-SystemVolumeLabel -Times 1 -Exactly -ParameterFilter { $Label -ceq 'Andere' }
    }

    It 'laesst die Bezeichnung ohne eigenen Namen stehen und setzt keinen Marker' {
        Mock Get-PortalDiskLayoutSafe { New-OwnPortal -Custom $false -Label $null }
        (Invoke-SystemVolumeLabel -Disks $script:Disks -Portal $null).Token | Should -Be 'kept'
        Should -Invoke Set-SystemVolumeLabel -Times 0 -Exactly
        Should -Invoke Set-SystemLabelMarker -Times 0 -Exactly
    }

    It 'benennt gegen ein altes Portal nie um' {
        Mock Get-PortalDiskLayoutSafe { New-OwnPortal -NoFlag -Label 'System' }
        (Invoke-SystemVolumeLabel -Disks $script:Disks -Portal $null).Token | Should -Be 'skip:portal_without_system_label'
        Should -Invoke Set-SystemVolumeLabel -Times 0 -Exactly
    }

    It 'meldet ein gleiches Label als kept und merkt es sich' {
        Mock Set-SystemVolumeLabel { $false }
        (Invoke-SystemVolumeLabel -Disks $script:Disks -Portal $null).Token | Should -Be 'kept'
        Should -Invoke Set-SystemLabelMarker -Times 1 -Exactly
    }

    It 'bleibt bei einem Fehler des Setzens erfolgreich, warnt und setzt keinen Marker (F5)' {
        Mock Set-SystemVolumeLabel { throw 'Zugriff verweigert' }
        (Invoke-SystemVolumeLabel -Disks $script:Disks -Portal $null).Token | Should -Be 'skip:system_label_failed'
        Should -Invoke Set-SystemLabelMarker -Times 0 -Exactly
        Should -Invoke Write-VsClientLog -Times 1 -Exactly -ParameterFilter { ($Rest -join ' ') -match 'WARN' -and ($Rest -join ' ') -match 'Zugriff verweigert' }
    }

    It 'meldet renamed, auch wenn der Marker nicht geschrieben werden kann' {
        Mock Set-SystemLabelMarker { throw 'Registry' }
        (Invoke-SystemVolumeLabel -Disks $script:Disks -Portal $null).Token | Should -Be 'renamed'
    }

    It 'wirft nie, auch wenn die Zielbestimmung unerwartet scheitert' {
        Mock Get-SystemVolumeTarget { throw 'unerwartet' }
        (Invoke-SystemVolumeLabel -Disks $script:Disks -Portal $null).Token | Should -Be 'skip:system_volume_unknown'
    }

    It 'laeuft auch ohne Snapshot-Revision und meldet den Grund der Portalabfrage' {
        Mock Get-VsSnapshotValue { $null } -ParameterFilter { $Name -eq 'rollout_revision' }
        Mock Get-PortalDiskLayoutSafe { [pscustomobject]@{ Reason = 'rollout_mismatch'; Disks = @() } }
        (Invoke-SystemVolumeLabel -Disks $script:Disks -Portal $null).Token | Should -Be 'skip:rollout_mismatch'
        Should -Invoke Set-SystemVolumeLabel -Times 0 -Exactly
    }
}

Describe 'DN2 Ablauf im Skript' {
    It 'setzt eine Bezeichnung nur in Set-SystemVolumeLabel' {
        $script:FunctionText['Set-SystemVolumeLabel'] | Should -Match 'Set-Volume -DriveLetter \$Letter -NewFileSystemLabel \$Label -ErrorAction Stop'
        foreach ($name in @($script:FunctionText.Keys | Where-Object { $_ -ne 'Set-SystemVolumeLabel' })) {
            $script:FunctionText[$name] | Should -Not -Match 'Set-Volume' -Because $name
        }
    }

    It 'formatiert, partitioniert und journalisiert im Systemblock nie' {
        foreach ($name in @('Get-SystemVolumeTarget', 'Resolve-SystemVolumeLabel', 'Set-SystemVolumeLabel', 'Invoke-SystemVolumeLabel', 'Set-SystemLabelMarker')) {
            $text = $script:FunctionText[$name]
            foreach ($forbidden in @('Format-Volume', 'New-Partition', 'Set-Partition', 'Initialize-Disk', 'Set-Disk\b', 'Clear-Disk',
                    'Remove-Partition', 'Assert-DiskIsSafeTarget', 'New-DiskOperation', 'operationsPath', 'Suspend-BitLocker')) {
                $text | Should -Not -Match $forbidden -Because "$name darf $forbidden nicht nutzen"
            }
        }
        $script:FunctionText['Set-SystemLabelMarker'] | Should -Match '\$registryPath'
    }

    It 'nimmt den Laufwerksbuchstaben aus SystemDrive und nie fest C:' {
        $script:FunctionText['Get-SystemVolumeTarget'] | Should -Match '\$env:SystemDrive'
        $script:ClientText | Should -Not -Match "DriveLetter\s+'?C'?(\s|$)"
    }

    It 'laeuft nach der Datenplattenschleife und vor der Detailzeile, mit dem Token vor den DN1-Gruenden' {
        $loop = $script:ClientText.IndexOf("foreach (`$disk in @(`$disks | Where-Object { [string]`$_.OperationalStatus -eq 'Offline' }))")
        $call = $script:ClientText.IndexOf('Invoke-SystemVolumeLabel -Disks $disks -Portal $portalLayout')
        $detail = $script:ClientText.IndexOf('$detail = "resumed=')
        $loop | Should -BeGreaterThan -1
        $call | Should -BeGreaterThan $loop
        $detail | Should -BeGreaterThan $call
        $script:ClientText | Should -Match '\$detail = "resumed=\{0\} new=\{1\} existing=\{2\} \{3\}" -f \$resumed, \$created, \$existing, \$systemToken'
        $script:ClientText | Should -Match '"optional: no disk work required \$systemToken"'
    }

    It 'nennt jeden Grund der Systemplatte im Kopfkommentar' {
        $header = $script:ClientText.Substring(0, $script:ClientText.IndexOf('function Get-SystemLabelMarker'))
        $produced = @([regex]::Matches(
            $script:FunctionText['Resolve-SystemVolumeLabel'] + $script:FunctionText['Invoke-SystemVolumeLabel'] + $script:FunctionText['Get-SystemVolumeTarget'],
            "Reason = '(?<r>[a-z_]+)'") | ForEach-Object { $_.Groups['r'].Value } | Sort-Object -Unique)
        $produced.Count | Should -BeGreaterThan 5
        # no_own_name, already_applied und already_equal sind kept, kein Grund.
        foreach ($reason in @($produced | Where-Object { $_ -notin @('no_own_name', 'already_applied', 'already_equal') })) {
            $header | Should -Match ('#[^\r\n]*\b{0}\b' -f $reason) -Because "$reason muss im Kommentar stehen"
        }
        $header | Should -Match '#\s+kept\s'
    }
}
