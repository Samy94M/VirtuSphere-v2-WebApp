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
        'Get-PortalDiskLayout', 'Get-DiskLabelPlan', 'Resolve-DiskVolumeLabel')
    foreach ($name in $names) {
        $fn = $ast.Find({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq $name }, $true)
        . ([scriptblock]::Create($fn.Extent.Text))
    }

    # Stubs, damit Mock ohne Client-Common, CIM und Portal greift.
    function Write-VsClientLog { param([Parameter(ValueFromRemainingArguments)]$Rest) }
    function Get-VsSnapshotInterfacesRoot { }
    function Get-VsSnapshotValue { param($Name) }
    function Resolve-VsApi { }
    function Get-VsApiUrl { param($Api, $Path) return "https://$Api$Path" }
    function Get-VsErrorDetail { param($ErrorRecord) return 'boom' }
    function ConvertTo-VsNormalizedMac { param($Mac) if ($Mac) { return ([string]$Mac).ToUpperInvariant() } }
    function Get-CimInstance { param($ClassName, $ErrorAction) }

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

    It 'benennt nie eine bestehende Platte um' {
        $script:ClientText | Should -Not -Match 'Set-Volume'
        $script:ClientText | Should -Not -Match 'Set-Partition[^\r\n]*-NewDriveLetter'
    }

    It 'nennt jeden Grund im Kopfkommentar' {
        foreach ($reason in @('rollout_mismatch', 'portal_unreachable', 'portal_without_disk_names', 'disk_list_invalid',
                'scsi_layout_unknown', 'no_portal_disk', 'size_mismatch', 'no_usable_name', 'label_invalid')) {
            $script:ClientText | Should -Match ('#\s+{0}\s' -f $reason)
        }
    }
}
