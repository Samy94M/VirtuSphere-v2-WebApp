# Pester-Suite fuer die Device-Sync-Haertung (AV-F16, AV-F03, AV-F20, PS-23, PS-24,
# PS-21/PS-26, AV-F30, PS-34, AV-F31):
#  - gesperrte VMs werden nur bei geaenderter Portalzeile oder nach 5 Minuten
#    erneut geprueft, und ihre Zustandswechsel werden einzeln gemeldet;
#  - die Import-MAC ist die eine Karte auf der WDS-Portgruppe (Portalregel);
#  - eine unbekannte Owned-Collection-ID wird ohne Providerabfrage als nicht
#    vorhanden behandelt;
#  - das Nachlesen nach einem Import wirft bei einem Providerfehler;
#  - jede Laufkategorie stammt aus Phase oder Markierung, und der Detailtext
#    ist geschwaerzt und gekuerzt.
#
# Die Funktionen des Device-Syncs werden aus dem Quelltext per AST uebernommen, damit
# die Suite die Produktionsfassung prueft und kein Duplikat pflegt.
#
# Ausfuehren:  Invoke-Pester tests/powershell/VirtuSphere.DeviceSyncBlocks.Tests.ps1

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:PsRoot = Join-Path $script:RepoRoot 'Powershell-MECM'
    $script:MecmDir = Join-Path $script:PsRoot 'mecm'
    $script:DeviceSyncPath = Join-Path $script:MecmDir 'mecm_new-device-sync.ps1'
    $script:AutoimporterPath = Join-Path $script:MecmDir 'mecm_autoimporter.ps1'
    $script:PackagesSyncPath = Join-Path $script:MecmDir 'mecm_Packages-TaskSeq-sync.ps1'
    $script:CommonPath = Join-Path $script:MecmDir 'VirtuSphere-Common.ps1'

    # Common laedt beim Import nichts Externes; danach ersetzt die Testfassung von
    # Write-VsLog den Dateisink, damit die Zeilen gesammelt werden koennen.
    . $script:CommonPath

    $script:CapturedLog = New-Object System.Collections.Generic.List[object]
    function Write-VsLog {
        param([string]$Message, [string]$Level = 'INFO', [string]$Context = '-')
        $script:CapturedLog.Add([pscustomobject]@{ Level = $Level; Context = $Context; Message = $Message })
    }

    $tokens = $null
    $parseErrors = $null
    $script:DeviceSyncAst = [System.Management.Automation.Language.Parser]::ParseFile($script:DeviceSyncPath, [ref]$tokens, [ref]$parseErrors)
    $script:DeviceSyncParseErrors = @($parseErrors)
    foreach ($name in @(
        'Get-VsDeviceFingerprint', 'Test-VsDeviceBlockDue', 'Read-VsDeviceBlockState', 'Write-VsDeviceBlockState',
        'Get-VsPxeImportMac', 'Get-VsKnownCollectionMembershipState', 'Get-VsPostImportIdentity',
        'Write-VsDeviceLog', 'Add-VsReplayedDeviceBlock', 'Complete-VsPendingDeviceBlock')) {
        $fn = $script:DeviceSyncAst.Find({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq $name }, $true)
        if (-not $fn) { throw "Funktion fehlt im Device-Sync: $name" }
        . ([scriptblock]::Create($fn.Extent.Text))
    }
    foreach ($constant in @('VsDeviceRecheckSeconds', 'VsDeviceBlockStateSchema', 'VsDeviceGatedCauses')) {
        $assign = $script:DeviceSyncAst.Find({ param($n) $n -is [System.Management.Automation.Language.AssignmentStatementAst] -and $n.Left.Extent.Text -eq ('$script:' + $constant) }, $true)
        if (-not $assign) { throw "Konstante fehlt im Device-Sync: $constant" }
        . ([scriptblock]::Create($assign.Extent.Text))
    }

    # Stubs, damit Pester die MECM-Cmdlets mocken kann (ohne das MECM-PowerShell-Modul).
    function Get-CMDevice { param($Name, [switch]$Fast, $ErrorAction) }
    function Get-CMDeviceCollectionDirectMembershipRule { param($CollectionId, $ResourceId, $ErrorAction) }

    function New-TestInterface {
        param([string]$Vlan, [string]$Mac, [string]$Mode = 'DHCP')
        [pscustomobject]@{ vlan = $Vlan; mac = $Mac; mode = $Mode }
    }
    function New-TestDevice {
        param([string]$WdsVlan = 'WDS-VLAN', [object[]]$Interfaces, [int]$Id = 17)
        [pscustomobject]@{
            id         = $Id
            vm_name    = 'VM-A'
            vm_hostname = 'Backup-12345'
            mission    = [pscustomobject]@{ mission_name = 'Mission A'; wds_vlan = $WdsVlan }
            interfaces = $Interfaces
        }
    }
    function New-TestPendingDevice {
        param([string]$PreviousSignature = '', [int]$CauseStart = 0, [int]$ItemStart = 0, [int]$DataStart = 0, [string]$Reason = 'changed', [string]$PreviousSince = '')
        @{
            Id = '17'; Name = 'VM-A'; Fingerprint = 'fp-new'; Reason = $Reason
            PreviousSignature = $PreviousSignature; PreviousSince = $PreviousSince
            CauseStart = $CauseStart; ItemStart = $ItemStart; DataStart = $DataStart
        }
    }
    function New-TestRunError {
        param([string]$Message, [int]$StatusCode = 0)
        $exception = New-Object System.Exception $Message
        if ($StatusCode -gt 0) {
            $exception | Add-Member -NotePropertyName Response -NotePropertyValue ([pscustomobject]@{ StatusCode = $StatusCode }) -Force
        }
        return (New-Object System.Management.Automation.ErrorRecord $exception, 'TestError', 'InvalidOperation', $null)
    }
}

Describe 'Import-MAC: die eine Karte auf der WDS-Portgruppe (AV-F20)' {

    It 'nimmt die MAC der genau einen Karte auf der WDS-Portgruppe, DHCP-Karten zaehlen nicht' {
        $device = New-TestDevice -Interfaces @(
            (New-TestInterface -Vlan 'VM Network' -Mac '00:50:56:AA:BB:01'),
            (New-TestInterface -Vlan 'WDS-VLAN' -Mac '00-50-56-AA-BB-02' -Mode 'static')
        )
        $result = Get-VsPxeImportMac -Device $device
        $result.Mac | Should -Be '00:50:56:AA:BB:02'
        $result.Cause | Should -Be ''
    }

    It 'ist mac_missing, wenn keine Karte exakt auf der WDS-Portgruppe liegt (Schreibweise zaehlt)' {
        $device = New-TestDevice -WdsVlan 'WDS-VLAN' -Interfaces @(
            (New-TestInterface -Vlan 'wds-vlan' -Mac '00:50:56:AA:BB:03'),
            (New-TestInterface -Vlan 'VM Network' -Mac '00:50:56:AA:BB:04')
        )
        $result = Get-VsPxeImportMac -Device $device
        $result.Mac | Should -BeNullOrEmpty
        $result.Cause | Should -Be 'mac_missing'
    }

    It 'ist pxe_nic_ambiguous bei mehreren Karten auf der WDS-Portgruppe' {
        $device = New-TestDevice -Interfaces @(
            (New-TestInterface -Vlan 'WDS-VLAN' -Mac '00:50:56:AA:BB:05'),
            (New-TestInterface -Vlan 'WDS-VLAN' -Mac '00:50:56:AA:BB:06')
        )
        $result = Get-VsPxeImportMac -Device $device
        $result.Mac | Should -BeNullOrEmpty
        $result.Cause | Should -Be 'pxe_nic_ambiguous'
    }

    It 'ist mac_missing, wenn die Mission keine WDS-Portgruppe hat' {
        $device = New-TestDevice -WdsVlan '' -Interfaces @((New-TestInterface -Vlan '' -Mac '00:50:56:AA:BB:07'))
        (Get-VsPxeImportMac -Device $device).Cause | Should -Be 'mac_missing'
    }

    It 'ist mac_missing, wenn die einzige PXE-Karte keine gueltige MAC traegt' {
        $device = New-TestDevice -Interfaces @((New-TestInterface -Vlan 'WDS-VLAN' -Mac 'not-a-mac'))
        (Get-VsPxeImportMac -Device $device).Cause | Should -Be 'mac_missing'
    }
}

Describe 'Wiederpruefung gesperrter VMs (AV-F16)' {

    It 'ist neu oder geaendert faellig, und eine frische Sperre wird nur nach 5 Minuten wieder geprueft' {
        $now = Get-Date
        (Test-VsDeviceBlockDue -Record $null -Fingerprint 'a' -Now $now).Reason | Should -Be 'new'
        $record = @{ fingerprint = 'a'; checked_at = $now.AddSeconds(-60).ToString('o') }
        (Test-VsDeviceBlockDue -Record $record -Fingerprint 'b' -Now $now).Reason | Should -Be 'changed'
        (Test-VsDeviceBlockDue -Record $record -Fingerprint 'a' -Now $now).Due | Should -BeFalse
    }

    It 'wird nach genau 300 Sekunden ohne Aenderung wieder faellig, bei 299 noch nicht' {
        $now = Get-Date
        $held = @{ fingerprint = 'a'; checked_at = $now.AddSeconds(-299).ToString('o') }
        (Test-VsDeviceBlockDue -Record $held -Fingerprint 'a' -Now $now -RecheckSeconds 300).Due | Should -BeFalse
        $due = @{ fingerprint = 'a'; checked_at = $now.AddSeconds(-300).ToString('o') }
        $decision = Test-VsDeviceBlockDue -Record $due -Fingerprint 'a' -Now $now -RecheckSeconds 300
        $decision.Due | Should -BeTrue
        $decision.Reason | Should -Be 'timer'
    }

    It 'nutzt die Frist des Device-Syncs (300 Sekunden)' {
        $script:VsDeviceRecheckSeconds | Should -Be 300
    }

    It 'ist die Fingerprint-Zeile stabil bei gleichem Inhalt und kippt bei einer Aenderung' {
        $a = New-TestDevice -Interfaces @((New-TestInterface -Vlan 'WDS-VLAN' -Mac '00:50:56:AA:BB:08'))
        $b = New-TestDevice -Interfaces @((New-TestInterface -Vlan 'WDS-VLAN' -Mac '00:50:56:AA:BB:08'))
        $c = New-TestDevice -Interfaces @((New-TestInterface -Vlan 'WDS-VLAN' -Mac '00:50:56:AA:BB:09'))
        $fa = Get-VsDeviceFingerprint -Device $a
        $fa | Should -Match '^[0-9A-F]{64}$'
        (Get-VsDeviceFingerprint -Device $b) | Should -Be $fa
        (Get-VsDeviceFingerprint -Device $c) | Should -Not -Be $fa
    }
}

Describe 'Sperrzustand lesen und schreiben (AV-F16)' {

    It 'schreibt und liest denselben Zustand, ohne Ausgabe' {
        $path = Join-Path $TestDrive 'device-block-state.json'
        $states = @{ '17' = [ordered]@{ fingerprint = 'fp'; checked_at = '2026-10-17T10:00:00.0000000+02:00'; since = '2026-10-17T10:00:00.0000000+02:00'; signature = 'mac_conflict target=VM-A'; name = 'VM-A'; causes = @('mac_conflict target=VM-A'); item_failures = 1; data_failures = 0 } }
        Write-VsDeviceBlockState -Path $path -States $states | Should -BeTrue
        $read = Read-VsDeviceBlockState -Path $path
        $read.ReadFailed | Should -BeFalse
        $read.States.ContainsKey('17') | Should -BeTrue
        @($read.States['17'].causes) | Should -Be @('mac_conflict target=VM-A')
        $read.States['17'].item_failures | Should -Be 1
    }

    It 'behandelt eine fehlende Datei als leeren Zustand ohne Fehler' {
        $read = Read-VsDeviceBlockState -Path (Join-Path $TestDrive 'missing.json')
        $read.ReadFailed | Should -BeFalse
        $read.States.Count | Should -Be 0
    }

    It 'verwirft eine unlesbare Datei und meldet das, damit jede VM einmal vollstaendig geprueft wird' {
        $path = Join-Path $TestDrive 'broken.json'
        Set-Content -LiteralPath $path -Value '{ kaputt' -Encoding UTF8
        $read = Read-VsDeviceBlockState -Path $path
        $read.ReadFailed | Should -BeTrue
        $read.States.Count | Should -Be 0
    }

    It 'verwirft eine Datei mit fremdem Schema' {
        $path = Join-Path $TestDrive 'schema.json'
        Set-Content -LiteralPath $path -Value '{"schema":99,"devices":{}}' -Encoding UTF8
        (Read-VsDeviceBlockState -Path $path).ReadFailed | Should -BeTrue
    }
}

Describe 'Abschluss einer VM: Zustandswechsel statt Dauerfeuer (AV-F16)' {

    BeforeEach {
        $script:CapturedLog.Clear()
        $script:VsPendingDevice = $null
        $script:VsDeviceLogBuffer = $null
    }

    It 'legt bei einer neuen dauerhaften Sperre den Zustand an, mit Ursachen und Zaehlern dieses Durchgangs' {
        $path = Join-Path $TestDrive 'new-block.json'
        $states = @{}
        $causes = New-VsRunCauseList
        Add-VsRunCause -Causes $causes -Cause 'mac_conflict' -Target 'VM-A'
        $script:VsPendingDevice = New-TestPendingDevice
        Complete-VsPendingDeviceBlock -Causes $causes -ItemFailures 1 -DataWarnings 0 -BlockStates $states -StatePath $path
        $states.ContainsKey('17') | Should -BeTrue
        $states['17'].signature | Should -Be 'mac_conflict target=VM-A'
        $states['17'].item_failures | Should -Be 1
        $states['17'].data_failures | Should -Be 0
        (Test-Path -LiteralPath $path) | Should -BeTrue
    }

    It 'schweigt bei unveraenderter Sperre (kein ERROR, kein INFO ausserhalb des Fristablaufs)' {
        $path = Join-Path $TestDrive 'unchanged.json'
        $states = @{ '17' = @{ fingerprint = 'fp-old'; checked_at = (Get-Date).AddMinutes(-1).ToString('o'); since = (Get-Date).AddHours(-2).ToString('o'); signature = 'mac_conflict target=VM-A'; name = 'VM-A'; causes = @('mac_conflict target=VM-A'); item_failures = 1; data_failures = 0 } }
        $causes = New-VsRunCauseList
        Add-VsRunCause -Causes $causes -Cause 'mac_conflict' -Target 'VM-A'
        $since = (Get-Date).AddHours(-2).ToString('o')
        $states['17'].since = $since
        $script:VsPendingDevice = New-TestPendingDevice -PreviousSignature 'mac_conflict target=VM-A' -Reason 'changed' -PreviousSince $since
        $script:VsDeviceLogBuffer = New-Object System.Collections.Generic.List[object]
        $script:VsDeviceLogBuffer.Add([pscustomobject]@{ Message = 'Identitaet nicht aufloesbar'; Level = 'ERROR'; Context = 'VM-A' })
        Complete-VsPendingDeviceBlock -Causes $causes -ItemFailures 1 -DataWarnings 0 -BlockStates $states -StatePath $path
        @($script:CapturedLog | Where-Object { $_.Level -eq 'ERROR' }).Count | Should -Be 0
        @($script:CapturedLog | Where-Object { $_.Level -eq 'INFO' }).Count | Should -Be 0
        $states['17'].fingerprint | Should -Be 'fp-new'
        $states['17'].since | Should -Be $since -Because 'eine unveraenderte Sperre behaelt ihren Beginn'
    }

    It 'meldet bei unveraenderter Sperre im 5-Minuten-Takt genau eine INFO-Zeile' {
        $path = Join-Path $TestDrive 'timer.json'
        $since = (Get-Date).AddHours(-1).ToString('o')
        $states = @{ '17' = @{ fingerprint = 'fp-new'; checked_at = (Get-Date).AddMinutes(-5).ToString('o'); since = $since; signature = 'mac_conflict target=VM-A'; name = 'VM-A'; causes = @('mac_conflict target=VM-A'); item_failures = 1; data_failures = 0 } }
        $causes = New-VsRunCauseList
        Add-VsRunCause -Causes $causes -Cause 'mac_conflict' -Target 'VM-A'
        $script:VsPendingDevice = New-TestPendingDevice -PreviousSignature 'mac_conflict target=VM-A' -Reason 'timer' -PreviousSince $since
        $script:VsDeviceLogBuffer = New-Object System.Collections.Generic.List[object]
        Complete-VsPendingDeviceBlock -Causes $causes -ItemFailures 1 -DataWarnings 0 -BlockStates $states -StatePath $path
        $info = @($script:CapturedLog | Where-Object { $_.Level -eq 'INFO' })
        $info.Count | Should -Be 1
        $info[0].Message | Should -Match 'Weiterhin gesperrt'
        $states['17'].since | Should -Be $since
    }

    It 'schreibt bei einem Ursachenwechsel die gepufferten Zeilen und ersetzt den Zustand' {
        $path = Join-Path $TestDrive 'changed.json'
        $states = @{ '17' = @{ fingerprint = 'fp'; checked_at = (Get-Date).ToString('o'); since = (Get-Date).ToString('o'); signature = 'mac_conflict target=VM-A'; name = 'VM-A'; causes = @('mac_conflict target=VM-A'); item_failures = 1; data_failures = 0 } }
        $causes = New-VsRunCauseList
        Add-VsRunCause -Causes $causes -Cause 'previous_resource_present' -Target 'VM-A'
        $script:VsPendingDevice = New-TestPendingDevice -PreviousSignature 'mac_conflict target=VM-A' -Reason 'changed'
        $script:VsDeviceLogBuffer = New-Object System.Collections.Generic.List[object]
        $script:VsDeviceLogBuffer.Add([pscustomobject]@{ Message = 'Tombstone'; Level = 'ERROR'; Context = 'VM-A' })
        Complete-VsPendingDeviceBlock -Causes $causes -ItemFailures 1 -DataWarnings 0 -BlockStates $states -StatePath $path
        @($script:CapturedLog | Where-Object { $_.Message -eq 'Tombstone' -and $_.Level -eq 'ERROR' }).Count | Should -Be 1
        $states['17'].signature | Should -Be 'previous_resource_present target=VM-A'
    }

    It 'loescht den Zustand und meldet einmal INFO, wenn die VM wieder frei ist' {
        $path = Join-Path $TestDrive 'cleared.json'
        $states = @{ '17' = @{ fingerprint = 'fp'; checked_at = (Get-Date).ToString('o'); since = (Get-Date).ToString('o'); signature = 'mac_missing target=VM-A'; name = 'VM-A'; causes = @('mac_missing target=VM-A'); item_failures = 0; data_failures = 1 } }
        $causes = New-VsRunCauseList
        $script:VsPendingDevice = New-TestPendingDevice -PreviousSignature 'mac_missing target=VM-A' -Reason 'changed'
        $script:VsDeviceLogBuffer = New-Object System.Collections.Generic.List[object]
        Complete-VsPendingDeviceBlock -Causes $causes -ItemFailures 0 -DataWarnings 0 -BlockStates $states -StatePath $path
        $states.ContainsKey('17') | Should -BeFalse
        @($script:CapturedLog | Where-Object { $_.Message -match 'Sperrgrund entfallen' }).Count | Should -Be 1
    }

    It 'speichert eine voruebergehende Ursache (ResourceID steht aus) nicht als Sperre' {
        $path = Join-Path $TestDrive 'transient.json'
        $states = @{}
        $causes = New-VsRunCauseList
        Add-VsRunCause -Causes $causes -Cause 'resource_id_pending' -Target 'VM-A'
        $script:VsPendingDevice = New-TestPendingDevice
        Complete-VsPendingDeviceBlock -Causes $causes -ItemFailures 0 -DataWarnings 1 -BlockStates $states -StatePath $path
        $states.Count | Should -Be 0
    }

    It 'schreibt bei einem Abbruch im Durchgang nur den Puffer und laesst den Zustand stehen' {
        $path = Join-Path $TestDrive 'abort.json'
        $states = @{ '17' = @{ fingerprint = 'fp'; checked_at = (Get-Date).ToString('o'); since = (Get-Date).ToString('o'); signature = 'mac_missing target=VM-A'; name = 'VM-A'; causes = @('mac_missing target=VM-A'); item_failures = 0; data_failures = 1 } }
        $script:VsPendingDevice = New-TestPendingDevice -PreviousSignature 'mac_missing target=VM-A'
        $script:VsDeviceLogBuffer = New-Object System.Collections.Generic.List[object]
        $script:VsDeviceLogBuffer.Add([pscustomobject]@{ Message = 'Puffer vor Abbruch'; Level = 'WARN'; Context = 'VM-A' })
        Complete-VsPendingDeviceBlock -Causes (New-VsRunCauseList) -BlockStates $states -StatePath $path -Abort
        @($script:CapturedLog | Where-Object { $_.Message -eq 'Puffer vor Abbruch' }).Count | Should -Be 1
        $states['17'].signature | Should -Be 'mac_missing target=VM-A'
        $script:VsPendingDevice | Should -BeNullOrEmpty
    }

    It 'schreibt ohne Puffer direkt ueber Write-VsLog' {
        $script:VsDeviceLogBuffer | Should -BeNullOrEmpty
        Write-VsDeviceLog -Message 'direkt' -Level 'ERROR' -Context 'VM-A'
        @($script:CapturedLog | Where-Object { $_.Message -eq 'direkt' -and $_.Level -eq 'ERROR' }).Count | Should -Be 1
    }

    It 'uebernimmt die gespeicherten Ursachen und Zaehler einer nicht faelligen VM in den Lauf' {
        $causes = New-VsRunCauseList
        $record = @{ causes = @('collection_missing target=VM-A collection=Mission A'); item_failures = 0; data_failures = 2 }
        $replayed = Add-VsReplayedDeviceBlock -Record $record -Causes $causes
        $causes.Count | Should -Be 1
        $replayed.DataWarnings | Should -Be 2
        $replayed.ItemFailures | Should -Be 0
    }
}

Describe 'Nachlesen nach dem Import wirft bei einem Providerfehler (PS-23)' {

    It 'laesst den Providerfehler bis zum Aufrufer durch, statt eine leere Sicht zu liefern' {
        Mock Get-CMDevice { throw 'SMS-Provider nicht erreichbar' }
        { Get-VsPostImportIdentity -RolloutName 'VM-A' -Mac '00:50:56:AA:BB:10' } | Should -Throw
    }

    It 'liefert bei einem leeren, aber fehlerfreien Bestand den Import-Fall' {
        Mock Get-CMDevice { @() }
        (Get-VsPostImportIdentity -RolloutName 'VM-A' -Mac '00:50:56:AA:BB:10').Action | Should -Be 'import'
    }

    It 'erkennt das eindeutig eingetragene Geraet nach dem Import' {
        Mock Get-CMDevice { [pscustomobject]@{ Name = 'VM-A'; MACAddress = '00:50:56:AA:BB:10'; ResourceID = 16777042 } }
        $result = Get-VsPostImportIdentity -RolloutName 'VM-A' -Mac '00:50:56:AA:BB:10'
        $result.Action | Should -Be 'use'
        $result.ResourceId | Should -Be '16777042'
    }

    It 'enthaelt die Abfrage -ErrorAction Stop und keinen SilentlyContinue-Pfad mehr' {
        $fn = $script:DeviceSyncAst.Find({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'Get-VsPostImportIdentity' }, $true)
        $fn.Extent.Text | Should -Match 'Get-CMDevice -Name \$RolloutName -Fast -ErrorAction Stop'
        $fn.Extent.Text | Should -Not -Match 'SilentlyContinue'
    }
}

Describe 'Owned-Regeln mit unbekannter Collection-ID (AV-F03)' {

    It 'behandelt eine ID, die der Collection-Cache nicht kennt, als nicht vorhanden ohne Providerabfrage' {
        Mock Get-CMDeviceCollectionDirectMembershipRule { throw 'provider must not be asked' }
        $known = New-Object System.Collections.Generic.HashSet[string]([System.StringComparer]::Ordinal)
        [void]$known.Add('SMS00001')
        $result = Get-VsKnownCollectionMembershipState -CollectionId 'SMS00999' -ResourceId '16777042' -KnownCollectionIds $known
        $result.State | Should -Be 'absent'
        Should -Invoke Get-CMDeviceCollectionDirectMembershipRule -Times 0 -Exactly
    }

    It 'fragt den Provider nur fuer eine bekannte ID' {
        Mock Get-CMDeviceCollectionDirectMembershipRule { [pscustomobject]@{ RuleName = 'VM-A' } }
        $known = New-Object System.Collections.Generic.HashSet[string]([System.StringComparer]::Ordinal)
        [void]$known.Add('SMS00001')
        $result = Get-VsKnownCollectionMembershipState -CollectionId 'SMS00001' -ResourceId '16777042' -KnownCollectionIds $known
        $result.State | Should -Be 'present'
        Should -Invoke Get-CMDeviceCollectionDirectMembershipRule -Times 1 -Exactly
    }

    It 'bleibt bei einem Providerfehler fuer eine bekannte ID unbekannt (kein stiller Erfolg)' {
        Mock Get-CMDeviceCollectionDirectMembershipRule { throw 'provider down' }
        $known = New-Object System.Collections.Generic.HashSet[string]([System.StringComparer]::Ordinal)
        [void]$known.Add('SMS00001')
        (Get-VsKnownCollectionMembershipState -CollectionId 'SMS00001' -ResourceId '16777042' -KnownCollectionIds $known).State | Should -Be 'unknown'
    }

    It 'fuellt der Device-Sync die Menge der bekannten IDs aus allen Device-Collections' {
        $text = Get-Content -LiteralPath $script:DeviceSyncPath -Raw
        $text | Should -Match '\$knownCollectionIds\s*=\s*\[System\.Collections\.Generic\.HashSet\[string\]\]::new'
        $text | Should -Match '\[void\]\$knownCollectionIds\.Add\(\[string\]\$c\.CollectionID\)'
        $text | Should -Match '-KnownCollectionIds \$knownCollectionIds'
    }
}

Describe 'Laufkategorie nach Phase oder Markierung (AV-F30, PS-21, PS-26)' {

    It 'meldet portal_rejected, wenn das Portal mit einem HTTP-Status geantwortet hat' {
        Get-VsSyncFailureCategory -Phase 'portal' -ErrorRecord (New-TestRunError -Message 'x' -StatusCode 400) | Should -Be 'portal_rejected'
        Get-VsSyncFailureCategory -Phase 'portal' -ErrorRecord (New-TestRunError -Message 'x' -StatusCode 500) | Should -Be 'portal_rejected'
    }

    It 'meldet portal_unreachable nur ohne Antwort' {
        Get-VsSyncFailureCategory -Phase 'portal' -ErrorRecord (New-TestRunError -Message 'connection refused') | Should -Be 'portal_unreachable'
    }

    It 'meldet journal_blocked fuer die Journal-Phase und mecm_unavailable fuer alle anderen' {
        Get-VsSyncFailureCategory -Phase 'journal' -ErrorRecord (New-TestRunError -Message 'gesperrt') | Should -Be 'journal_blocked'
        Get-VsSyncFailureCategory -Phase 'mecm' -ErrorRecord (New-TestRunError -Message 'provider') | Should -Be 'mecm_unavailable'
        Get-VsSyncFailureCategory -Phase 'scan' -ErrorRecord (New-TestRunError -Message 'provider') | Should -Be 'mecm_unavailable'
    }

    It 'meldet source_changing fuer eine markierte Paketdatei, auch aus einer MECM-Phase' {
        $marked = New-TestRunError -Message 'Paketdatei hat sich geaendert'
        $marked.Exception.Data['VsRunCategory'] = 'source_changing'
        Get-VsSyncFailureCategory -Phase 'scan' -ErrorRecord $marked | Should -Be 'source_changing'
    }

    It 'erzeugt Markierungen nur fuer geschlossene Kategorien und liest unbekannte Werte nicht' {
        { New-VsRunCategoryError -Message 'x' -Category 'nonsense' } | Should -Throw
        $unknown = New-TestRunError -Message 'x'
        $unknown.Exception.Data['VsRunCategory'] = 'nonsense'
        Get-VsExceptionRunCategory -ErrorRecord $unknown | Should -BeNullOrEmpty
        Get-VsSyncFailureCategory -Phase 'scan' -ErrorRecord $unknown | Should -Be 'mecm_unavailable'
    }

    It 'markiert eine gesperrte Paketdatei beim Hashen als source_changing' {
        $file = Join-Path $TestDrive 'locked-package.txt'
        Set-Content -LiteralPath $file -Value 'payload' -Encoding UTF8
        $stream = [System.IO.File]::Open($file, [System.IO.FileMode]::Open, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
        try {
            $thrown = $null
            try { [void](Get-VsStampFileHash -Path $file -Relative 'locked-package.txt') } catch { $thrown = $_ }
            $thrown | Should -Not -BeNullOrEmpty
            Get-VsExceptionRunCategory -ErrorRecord $thrown | Should -Be 'source_changing'
        } finally {
            $stream.Dispose()
        }
    }

    It 'laesst eine nicht gesperrte Paketdatei unveraendert hashen' {
        $file = Join-Path $TestDrive 'free-package.txt'
        Set-Content -LiteralPath $file -Value 'payload' -Encoding UTF8
        Get-VsStampFileHash -Path $file -Relative 'free-package.txt' | Should -Match '^[0-9A-F]{64}$'
    }
}

Describe 'Detailtext fuer das Portal (PS-34)' {

    It 'beginnt mit der Kategorie und bleibt unter 512 Bytes' {
        $record = New-TestRunError -Message ('Fehler ' * 400)
        $detail = Get-VsRunFailureDetail -Category 'mecm_unavailable' -ErrorRecord $record
        $detail | Should -Match '^mecm_unavailable: '
        ([System.Text.Encoding]::UTF8.GetByteCount($detail)) | Should -BeLessOrEqual (512 + 'mecm_unavailable: '.Length)
    }

    It 'schwaerzt Geheimnisse und Laufwerks- sowie UNC-Pfade' {
        $record = New-TestRunError -Message 'token=HUNTER2 fehlt unter C:\Program Files\VirtuSphere\secret.txt und \\srv01\share\x'
        $detail = Get-VsRunFailureDetail -Category 'portal_rejected' -ErrorRecord $record
        $detail | Should -Not -Match 'HUNTER2'
        $detail | Should -Not -Match 'C:\\Program'
        $detail | Should -Not -Match 'srv01'
        $detail | Should -Match '<Pfad>'
    }
}

Describe 'Quelltext-Pins der Device-Sync-Haertung (AV-F31, PS-23, PS-24, AV-F30)' {

    It 'enthaelt keinen Approve-CMDevice-Zweig mehr (AV-F31)' {
        Get-Content -LiteralPath $script:DeviceSyncPath -Raw | Should -Not -Match 'Approve-CMDevice'
    }

    It 'loggt jede erfolgreiche Mitgliedschaftszuweisung (PS-24)' {
        Get-Content -LiteralPath $script:DeviceSyncPath -Raw | Should -Match 'Write-VsDeviceLog -Context \$deviceName -Message \("Mitgliedschaft gesetzt'
    }

    It 'faellt in den Journalphasen auf journal_blocked zurueck und setzt danach wieder mecm' {
        $text = Get-Content -LiteralPath $script:DeviceSyncPath -Raw
        ([regex]::Matches($text, "\`$phase = 'journal'")).Count | Should -BeGreaterOrEqual 4
        ([regex]::Matches($text, "\`$phase = 'mecm'")).Count | Should -BeGreaterOrEqual 5
    }

    It 'nutzt im aeusseren Catch die Kategorie- und Detailfunktionen' {
        $text = Get-Content -LiteralPath $script:DeviceSyncPath -Raw
        $text | Should -Match '\$category = Get-VsSyncFailureCategory -Phase \$phase -ErrorRecord \$_'
        $text | Should -Match '\$detail = Get-VsRunFailureDetail -Category \$category -ErrorRecord \$_'
    }

    It 'nutzt Packages-Sync und Autoimporter dieselben Funktionen fuer Kategorie und Detail' {
        $packages = Get-Content -LiteralPath $script:PackagesSyncPath -Raw
        $packages | Should -Match '\$category = Get-VsSyncFailureCategory -Phase \$phase -ErrorRecord \$_'
        $packages | Should -Match '\$detail = Get-VsRunFailureDetail -Category \$category -ErrorRecord \$_'
        $importer = Get-Content -LiteralPath $script:AutoimporterPath -Raw
        $importer | Should -Match '\$category = Get-VsSyncFailureCategory -Phase ''scan'' -ErrorRecord \$_'
        $importer | Should -Match '\$detail = Get-VsRunFailureDetail -Category \$category -ErrorRecord \$_'
    }

    It 'setzt beim Paketquellen-Wandel weder Verbindung noch Pause zurueck' {
        $importer = Get-Content -LiteralPath $script:AutoimporterPath -Raw
        $match = [regex]::Match($importer, "(?s)if \(\`$category -eq 'source_changing'\) \{(.*?)\} else \{")
        $match.Success | Should -BeTrue
        $match.Groups[1].Value | Should -Not -Match 'providerMachine|siteCode|sleepSeconds'
        $match.Groups[1].Value | Should -Match "\`$outcome = 'warning'"
    }

    It 'hat jeder gesperrte-VM-Grund einen Eintrag im geschlossenen Vokabular' {
        $vocab = $script:VsRunCauseVocabulary
        foreach ($code in $script:VsDeviceGatedCauses) {
            $vocab | Should -Contain $code -Because "Sperrgrund $code muss im Vokabular stehen"
        }
    }

    It 'kennt das Vokabular die neue PXE-Ursache in beiden Listen' {
        $script:VsRunCauseVocabulary | Should -Contain 'pxe_nic_ambiguous'
        (Get-Command Add-VsRunCause).Parameters['Cause'].Attributes |
            Where-Object { $_ -is [System.Management.Automation.ValidateSetAttribute] } |
            ForEach-Object { $_.ValidValues } | Should -Contain 'pxe_nic_ambiguous'
    }
}
