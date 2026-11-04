# Pester-Suite fuer die MECM-Geraeteidentitaet des Windows-Rolloutnamens
# (Etappe 14D, ADR-0043).
#
# Warum diese Suite getrennt von VirtuSphere.Common.Tests.ps1 steht: sie prueft
# EINE Entscheidung, und die hat einen vollstaendig aufzaehlbaren Zustandsraum.
# Der Vorgaenger war `$mecmDevices[$d.Name] = $d`, also eine Hashtabelle mit dem
# Anzeigenamen als Einzelschluessel: zwei gleichnamige Datensaetze loeschten sich
# still gegenseitig aus, und welcher gewann, entschied die Reihenfolge der
# Providerantwort. Ein solcher Fehler ist an keiner einzelnen Zeile zu sehen; er
# ist nur an der Tabelle zu sehen, die hier steht.
#
# Ausfuehren:  Invoke-Pester tests/powershell/VirtuSphere.RolloutIdentity.Tests.ps1

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:PsRoot = Join-Path $script:RepoRoot 'Powershell-MECM'
    $script:MecmCommon = Join-Path (Join-Path $script:PsRoot 'mecm') 'VirtuSphere-Common.ps1'
    $script:ClientCommon = Join-Path (Join-Path $script:PsRoot 'clients') 'VirtuSphere-Client-Common.ps1'
    $script:DeviceSyncPath = Join-Path (Join-Path $script:PsRoot 'mecm') 'mecm_new-device-sync.ps1'
    $script:ClientGetInfoPath = Join-Path (Join-Path $script:PsRoot 'clients') 'client_getInfos.ps1'

    # Dot-Source in EINEM Scope: VirtuSphere-Common.ps1 laedt beim Laden nichts
    # Externes nach, und die vier gepruften Funktionen sind rein.
    . $script:MecmCommon

    function New-TestDevice {
        param([string]$Name, $Mac, $ResourceId)
        return [pscustomobject]@{ Name = $Name; MACAddress = $Mac; ResourceID = $ResourceId }
    }

    # Ein Bestand, der jede Sorte Mehrdeutigkeit enthaelt, die real vorkommt:
    # ein normales Geraet, eines mit abweichendem Anzeigenamen (nach einer
    # Windows-/DDR-Umbenennung), zwei nur durch Gross-/Kleinschreibung
    # unterschiedene Namen, ein Geraet mit zwei NICs und eine doppelt vergebene
    # ResourceID.
    $script:Fleet = @(
        (New-TestDevice -Name 'Backup-12345' -Mac 'AA:BB:CC:DD:EE:01' -ResourceId 16777001),
        (New-TestDevice -Name 'RENAMED-BY-WINDOWS' -Mac 'AA:BB:CC:DD:EE:02' -ResourceId 16777002),
        (New-TestDevice -Name 'DUP' -Mac 'AA:BB:CC:DD:EE:03' -ResourceId 16777003),
        (New-TestDevice -Name 'dup' -Mac 'AA:BB:CC:DD:EE:04' -ResourceId 16777004),
        (New-TestDevice -Name 'MULTINIC' -Mac @('AA:BB:CC:DD:EE:05', 'AA:BB:CC:DD:EE:06') -ResourceId 16777005),
        (New-TestDevice -Name 'TWINID' -Mac 'AA:BB:CC:DD:EE:07' -ResourceId 16777001)
    )
    $script:Index = New-VsMecmDeviceIndex -Devices $script:Fleet

    function Get-Decision {
        param([hashtable]$Arguments)
        $withIndex = @{ Index = $script:Index }
        foreach ($key in $Arguments.Keys) { $withIndex[$key] = $Arguments[$key] }
        $decision = Resolve-VsDeviceIdentity @withIndex
        return (("{0} {1} {2}" -f $decision.Action, $decision.ResourceId, $decision.Cause) -replace '\s+', ' ').Trim()
    }
}

Describe 'Rolloutnamen-Schluessel und Gueltigkeit' {
    It 'faltet nur Gross-/Kleinschreibung und trimmt, kuerzt aber nie' {
        ConvertTo-VsHostnameKey '  Backup-12345 ' | Should -BeExactly 'backup-12345'
        # 20 Zeichen bleiben 20: ein Normalisierer, der repariert, laesst zwei
        # verschiedene Sollnamen auf einen Claim zusammenfallen.
        (ConvertTo-VsHostnameKey 'ABCDEFGHIJKLMNOPQRST').Length | Should -Be 20
        ConvertTo-VsHostnameKey '' | Should -BeExactly ''
        ConvertTo-VsHostnameKey $null | Should -BeExactly ''
    }

    It 'akzeptiert genau die NetBIOS-Namen, die auch das Portal aktiviert' {
        Test-VsRolloutHostname 'Backup-12345' | Should -BeTrue
        Test-VsRolloutHostname 'A' | Should -BeTrue
        Test-VsRolloutHostname 'ABCDEFGHIJKLMNO' | Should -BeTrue
        Test-VsRolloutHostname 'ABCDEFGHIJKLMNOP' | Should -BeFalse   # 16 Zeichen
        Test-VsRolloutHostname 'host.local' | Should -BeFalse
        Test-VsRolloutHostname '-host' | Should -BeFalse
        Test-VsRolloutHostname 'host-' | Should -BeFalse
        Test-VsRolloutHostname '' | Should -BeFalse
    }
}

Describe 'Multimap-Index statt last-wins' {
    It 'behaelt beide gleichnamigen Datensaetze statt einen zu ueberschreiben' {
        # Genau der Vorgaengerfehler: mit `$map[$name] = $device` waere hier 1.
        @(Get-VsIndexHits -Map $script:Index.ByName -Key 'dup') | Should -HaveCount 2
    }

    It 'findet ein Geraet unter JEDER seiner MACs' {
        @(Get-VsIndexHits -Map $script:Index.ByMac -Key 'AA:BB:CC:DD:EE:05') | Should -HaveCount 1
        @(Get-VsIndexHits -Map $script:Index.ByMac -Key 'AA:BB:CC:DD:EE:06') | Should -HaveCount 1
    }

    It 'liefert fuer einen unbekannten Schluessel ein LEERES Array' {
        # `@($map[$fehlt])` waere `@($null)` und damit Count 1. Genau daran
        # antwortete die Aufloesung einmal `use` mit leerer ResourceID statt zu
        # importieren, und der erste Rollout waere nie passiert.
        @(Get-VsIndexHits -Map $script:Index.ByName -Key 'gibtesnicht') | Should -HaveCount 0
        @(Get-VsIndexHits -Map $script:Index.ByMac -Key 'AA:BB:CC:DD:EE:FF') | Should -HaveCount 0
        @(Get-VsIndexHits -Map $script:Index.ByResourceId -Key '') | Should -HaveCount 0
    }
}

Describe 'Identitaetsentscheidung' {
    # Der vollstaendige Entscheidungstisch. Jede Zeile ist ein Fall, den der
    # Standort wirklich erzeugt; die Erwartung steht als EIN String da, damit ein
    # falscher Zweig nicht hinter einer halb passenden Assertion verschwindet.
    It '<why>' -ForEach @(
        @{ why = 'gebundene ResourceID gewinnt, auch wenn MECM einen anderen Namen anzeigt'
           given = @{ RolloutHostname = 'Backup-12345'; Mac = 'AA:BB:CC:DD:EE:02'; BoundResourceId = '16777002' }
           expect = 'use 16777002' }
        @{ why = 'gebundene ResourceID nicht mehr in MECM: blockiert, adoptiert keinen Ersatz'
           given = @{ RolloutHostname = 'Backup-12345'; Mac = 'AA:BB:CC:DD:EE:01'; BoundResourceId = '99999999' }
           expect = 'block resource_id_missing' }
        @{ why = 'gebundene ResourceID mit fremder MAC blockiert'
           given = @{ RolloutHostname = 'Backup-12345'; Mac = 'AA:BB:CC:DD:EE:09'; BoundResourceId = '16777002' }
           expect = 'block resource_mac_conflict' }
        @{ why = 'gebundene ResourceID passt auf die zweite NIC desselben Datensatzes'
           given = @{ RolloutHostname = 'MULTINIC'; Mac = 'AA:BB:CC:DD:EE:06'; BoundResourceId = '16777005' }
           expect = 'use 16777005' }
        @{ why = 'MECM-ID1: DHCP-Karte abgeschaltet, Datensatz traegt nur noch die im Portal bekannte statische MAC'
           given = @{ RolloutHostname = 'Backup-12345'; Mac = 'AA:BB:CC:DD:EE:09'; BoundResourceId = '16777002'; KnownMacs = @('AA:BB:CC:DD:EE:09', 'aa-bb-cc-dd-ee-02') }
           expect = 'use 16777002' }
        @{ why = 'MECM-ID1: keine der Portal-MACs der VM passt, bleibt resource_mac_conflict'
           given = @{ RolloutHostname = 'Backup-12345'; Mac = 'AA:BB:CC:DD:EE:09'; BoundResourceId = '16777002'; KnownMacs = @('AA:BB:CC:DD:EE:09', 'AA:BB:CC:DD:EE:0A', '', 'kaputt') }
           expect = 'block resource_mac_conflict' }
        @{ why = 'MECM-ID1: Portal-MACs gelten nicht fuer die Erstbindung, Name mit fremder DHCP-MAC bleibt mac_conflict'
           given = @{ RolloutHostname = 'Backup-12345'; Mac = 'AA:BB:CC:DD:EE:02'; KnownMacs = @('AA:BB:CC:DD:EE:01') }
           expect = 'block mac_conflict' }
        @{ why = 'MECM-ID1: Portal-MACs fuehren ohne Bindung nicht zur Uebernahme eines fremden Datensatzes'
           given = @{ RolloutHostname = 'FRESH-NAME'; Mac = 'AA:BB:CC:DD:EE:77'; KnownMacs = @('AA:BB:CC:DD:EE:01') }
           expect = 'import' }
        @{ why = 'dieselbe ResourceID doppelt in MECM ist mehrdeutig, keine Auswahl'
           given = @{ RolloutHostname = 'Backup-12345'; Mac = 'AA:BB:CC:DD:EE:01'; BoundResourceId = '16777001' }
           expect = 'block device_identity_ambiguous' }
        @{ why = 'erster Rollout: Name UND MAC eindeutig auf denselben Datensatz uebernimmt ihn'
           given = @{ RolloutHostname = 'Backup-12345'; Mac = 'AA:BB:CC:DD:EE:01' }
           expect = 'use 16777001' }
        @{ why = 'erster Rollout: Name mit fremder MAC bleibt in der Warteschlange'
           given = @{ RolloutHostname = 'Backup-12345'; Mac = 'AA:BB:CC:DD:EE:02' }
           expect = 'block mac_conflict' }
        @{ why = 'erster Rollout: MAC mit fremdem Namen bleibt in der Warteschlange'
           given = @{ RolloutHostname = 'FRESH-NAME'; Mac = 'AA:BB:CC:DD:EE:02' }
           expect = 'block mac_conflict' }
        @{ why = 'erster Rollout: nichts bekannt fuehrt zum Import'
           given = @{ RolloutHostname = 'FRESH-NAME'; Mac = 'AA:BB:CC:DD:EE:77' }
           expect = 'import' }
        @{ why = 'zwei nur nach Schreibweise verschiedene Namen sind mehrdeutig'
           given = @{ RolloutHostname = 'DUP'; Mac = 'AA:BB:CC:DD:EE:03' }
           expect = 'block device_identity_ambiguous' }
        @{ why = 'noch vorhandenes Vorgaengergeraet blockiert den Neuimport'
           given = @{ RolloutHostname = 'FRESH-NAME'; Mac = 'AA:BB:CC:DD:EE:77'; PreviousResourceId = '16777002' }
           expect = 'block previous_resource_present' }
        @{ why = 'geloeschtes Vorgaengergeraet gibt den Weg frei'
           given = @{ RolloutHostname = 'FRESH-NAME'; Mac = 'AA:BB:CC:DD:EE:77'; PreviousResourceId = '55555555' }
           expect = 'import' }
        @{ why = 'zu langer Rolloutname wird nicht gekuerzt, sondern abgewiesen'
           given = @{ RolloutHostname = 'ABCDEFGHIJKLMNOP'; Mac = 'AA:BB:CC:DD:EE:77' }
           expect = 'block device_name_invalid' }
        @{ why = 'Rolloutname mit Punkt wird abgewiesen'
           given = @{ RolloutHostname = 'host.local'; Mac = 'AA:BB:CC:DD:EE:77' }
           expect = 'block device_name_invalid' }
        @{ why = 'leerer Rolloutname wird abgewiesen'
           given = @{ RolloutHostname = ''; Mac = 'AA:BB:CC:DD:EE:77' }
           expect = 'block device_name_invalid' }
        @{ why = 'reiner Schreibweisenwechsel ist dieselbe Maschine'
           given = @{ RolloutHostname = 'backup-12345'; Mac = 'AA:BB:CC:DD:EE:01' }
           expect = 'use 16777001' }
        @{ why = 'fehlende MAC blockiert vor jeder Namenssuche'
           given = @{ RolloutHostname = 'FRESH-NAME'; Mac = '' }
           expect = 'block mac_missing' }
    ) {
        Get-Decision -Arguments $given | Should -BeExactly $expect
    }

    It 'ist idempotent: ein zweiter Scan mit demselben Bestand entscheidet gleich' {
        $first = Get-Decision -Arguments @{ RolloutHostname = 'Backup-12345'; Mac = 'AA:BB:CC:DD:EE:02'; BoundResourceId = '16777002' }
        $rebuilt = New-VsMecmDeviceIndex -Devices $script:Fleet
        $second = Resolve-VsDeviceIdentity -Index $rebuilt -RolloutHostname 'Backup-12345' -Mac 'AA:BB:CC:DD:EE:02' -BoundResourceId '16777002'
        (("{0} {1} {2}" -f $second.Action, $second.ResourceId, $second.Cause) -replace '\s+', ' ').Trim() | Should -BeExactly $first
    }

    It 'entscheidet niemals `use` ohne ResourceID' {
        # Die Zusicherung hinter dem gemessenen Fehler: eine Uebernahme ohne
        # ResourceID haette der Aufrufer als gueltige Bindung weitergereicht.
        foreach ($name in @('Backup-12345', 'FRESH-NAME', 'DUP', 'MULTINIC', '')) {
            foreach ($mac in @('AA:BB:CC:DD:EE:01', 'AA:BB:CC:DD:EE:77', '')) {
                $decision = Resolve-VsDeviceIdentity -Index $script:Index -RolloutHostname $name -Mac $mac
                if ($decision.Action -eq 'use') {
                    $decision.ResourceId | Should -Not -BeNullOrEmpty
                }
            }
        }
    }
}

Describe 'MAC-Angaben aus MECM: Liste oder Text (MC-MAC1)' {
    # Gemessen am 04.11.2026 auf dem MECM-Server: `Get-CMDevice -Fast` liefert
    # MACAddress als EINEN Text, Komma und Leerzeichen zwischen den MACs;
    # SMS_R_System.MACAddresses dieselben MACs als Liste. Der Index iterierte den
    # Text als ein Element, ConvertTo-VsNormalizedMac verwarf ihn (24 Hexzeichen),
    # und zwei VMs mit zwei NICs hingen in mac_conflict bzw.
    # resource_mac_conflict, obwohl ihr MECM-Geraet genau die Portal-MACs trug.
    # Die MACs hier sind synthetisch, das Format ist das gemessene.
    BeforeAll {
        $script:MeasuredText = '00:50:56:AA:BB:21, 00:50:56:AA:BB:22'
        $script:MacFleet = @(
            (New-TestDevice -Name 'TEXT-NIC' -Mac $script:MeasuredText -ResourceId 16777101),
            (New-TestDevice -Name 'LIST-NIC' -Mac @('00:50:56:AA:BB:31', '00:50:56:AA:BB:32') -ResourceId 16777102),
            (New-TestDevice -Name 'TWICE-NIC' -Mac '00:50:56:AA:BB:41; 00-50-56-aa-bb-41' -ResourceId 16777103),
            (New-TestDevice -Name 'THREE-NIC' -Mac '00:50:56:AA:BB:61, 00-50-56-aa-bb-62 00:50:56:AA:BB:63' -ResourceId 16777104),
            (New-TestDevice -Name 'HALF-NIC' -Mac '00:50:56:AA:BB:71, kaputt' -ResourceId 16777105),
            (New-TestDevice -Name 'NO-NIC' -Mac '' -ResourceId 16777106)
        )
        $script:MacIndex = New-VsMecmDeviceIndex -Devices $script:MacFleet

        function Get-MacDecision {
            param([hashtable]$Arguments)
            $withIndex = @{ Index = $script:MacIndex }
            foreach ($key in $Arguments.Keys) { $withIndex[$key] = $Arguments[$key] }
            $decision = Resolve-VsDeviceIdentity @withIndex
            return (("{0} {1} {2}" -f $decision.Action, $decision.ResourceId, $decision.Cause) -replace '\s+', ' ').Trim()
        }
    }

    Context 'Zerlegung an einer Stelle' {
        It '<why>' -ForEach @(
            @{ why = 'gemessenes Format: Text mit Komma und Leerzeichen'; value = '00:50:56:AA:BB:21, 00:50:56:AA:BB:22'; expect = @('00:50:56:AA:BB:21', '00:50:56:AA:BB:22') }
            @{ why = 'Liste wie SMS_R_System.MACAddresses'; value = @('00:50:56:AA:BB:21', '00-50-56-aa-bb-22'); expect = @('00:50:56:AA:BB:21', '00:50:56:AA:BB:22') }
            @{ why = 'Semikolon, Leerraum und Zeilenumbruch trennen ebenfalls'; value = "00:50:56:AA:BB:21;00:50:56:AA:BB:22 00:50:56:AA:BB:23`r`n00:50:56:AA:BB:24"; expect = @('00:50:56:AA:BB:21', '00:50:56:AA:BB:22', '00:50:56:AA:BB:23', '00:50:56:AA:BB:24') }
            @{ why = 'Einzelwert bleibt ein Element'; value = '00:50:56:AA:BB:21'; expect = @('00:50:56:AA:BB:21') }
            @{ why = 'Liste, deren Element selbst ein Text mit zwei MACs ist'; value = @('00:50:56:AA:BB:21, 00:50:56:AA:BB:22', '00:50:56:AA:BB:23'); expect = @('00:50:56:AA:BB:21', '00:50:56:AA:BB:22', '00:50:56:AA:BB:23') }
            @{ why = 'unbrauchbares Teil faellt weg, die gueltigen bleiben'; value = '00:50:56:AA:BB:21, kaputt, 00:50:56:AA:BB'; expect = @('00:50:56:AA:BB:21') }
            @{ why = 'doppelt genannte MAC zaehlt einmal'; value = '00:50:56:AA:BB:21, 00-50-56-aa-bb-21'; expect = @('00:50:56:AA:BB:21') }
        ) {
            $actual = @(ConvertTo-VsNormalizedMacList $value)
            ($actual -join '|') | Should -BeExactly ($expect -join '|')
        }

        It 'liefert fuer leere Angaben ein LEERES Array' {
            @(ConvertTo-VsNormalizedMacList $null) | Should -HaveCount 0
            @(ConvertTo-VsNormalizedMacList '') | Should -HaveCount 0
            @(ConvertTo-VsNormalizedMacList @()) | Should -HaveCount 0
            @(ConvertTo-VsNormalizedMacList @($null, ' ')) | Should -HaveCount 0
        }

        It 'zerlegt nicht ohne Trenner: 24 Hexzeichen am Stueck bleiben unbrauchbar' {
            @(ConvertTo-VsNormalizedMacList '005056AABB21005056AABB22') | Should -HaveCount 0
        }

        It 'laesst ConvertTo-VsNormalizedMac fuer Einzelwerte streng' {
            ConvertTo-VsNormalizedMac $script:MeasuredText | Should -BeNullOrEmpty
        }
    }

    Context 'Index' {
        It 'findet das Geraet mit Kommatext unter jeder seiner MACs' {
            @(Get-VsIndexHits -Map $script:MacIndex.ByMac -Key '00:50:56:AA:BB:21') | Should -HaveCount 1
            @(Get-VsIndexHits -Map $script:MacIndex.ByMac -Key '00:50:56:AA:BB:22') | Should -HaveCount 1
        }

        It 'findet das Geraet mit Liste unter jeder seiner MACs' {
            @(Get-VsIndexHits -Map $script:MacIndex.ByMac -Key '00:50:56:AA:BB:31') | Should -HaveCount 1
            @(Get-VsIndexHits -Map $script:MacIndex.ByMac -Key '00:50:56:AA:BB:32') | Should -HaveCount 1
        }

        It 'findet das Geraet mit drei NICs und gemischten Trennern unter jeder seiner MACs' {
            foreach ($mac in @('00:50:56:AA:BB:61', '00:50:56:AA:BB:62', '00:50:56:AA:BB:63')) {
                @(Get-VsIndexHits -Map $script:MacIndex.ByMac -Key $mac) | Should -HaveCount 1
            }
        }

        It 'legt den Kommatext nicht als eigenen Schluessel ab' {
            $script:MacIndex.ByMac.ContainsKey($script:MeasuredText) | Should -BeFalse
            @($script:MacIndex.ByMac.Keys | Where-Object { $_.Length -ne 17 }) | Should -HaveCount 0
        }

        It 'macht einen Datensatz, der dieselbe MAC zweimal nennt, nicht mit sich selbst mehrdeutig' {
            @(Get-VsIndexHits -Map $script:MacIndex.ByMac -Key '00:50:56:AA:BB:41') | Should -HaveCount 1
            Get-MacDecision -Arguments @{ RolloutHostname = 'TWICE-NIC'; Mac = '00:50:56:AA:BB:41' } | Should -BeExactly 'use 16777103'
        }
    }

    Context 'Identitaet' {
        It '<why>' -ForEach @(
            @{ why = 'gebundene ResourceID, Kommatext, DHCP-MAC ist die erste'
               given = @{ RolloutHostname = 'TEXT-NIC'; Mac = '00:50:56:AA:BB:21'; BoundResourceId = '16777101' }
               expect = 'use 16777101' }
            @{ why = 'gebundene ResourceID, Kommatext, DHCP-MAC ist die zweite'
               given = @{ RolloutHostname = 'TEXT-NIC'; Mac = '00:50:56:AA:BB:22'; BoundResourceId = '16777101' }
               expect = 'use 16777101' }
            @{ why = 'gebundene ResourceID, Kommatext, nur eine bekannte statische MAC passt (MECM-ID1)'
               given = @{ RolloutHostname = 'TEXT-NIC'; Mac = '00:50:56:AA:BB:99'; BoundResourceId = '16777101'; KnownMacs = @('00:50:56:AA:BB:99', '00-50-56-aa-bb-22') }
               expect = 'use 16777101' }
            @{ why = 'gebundene ResourceID, Kommatext, keine Portal-MAC passt'
               given = @{ RolloutHostname = 'TEXT-NIC'; Mac = '00:50:56:AA:BB:99'; BoundResourceId = '16777101'; KnownMacs = @('00:50:56:AA:BB:98') }
               expect = 'block resource_mac_conflict' }
            @{ why = 'gebundene ResourceID, Liste, DHCP-MAC ist die zweite'
               given = @{ RolloutHostname = 'LIST-NIC'; Mac = '00:50:56:AA:BB:32'; BoundResourceId = '16777102' }
               expect = 'use 16777102' }
            @{ why = 'gebundene ResourceID, Liste, keine Portal-MAC passt'
               given = @{ RolloutHostname = 'LIST-NIC'; Mac = '00:50:56:AA:BB:99'; BoundResourceId = '16777102' }
               expect = 'block resource_mac_conflict' }
            @{ why = 'ungebunden, Kommatext: Name und DHCP-MAC auf denselben Datensatz'
               given = @{ RolloutHostname = 'TEXT-NIC'; Mac = '00:50:56:AA:BB:22' }
               expect = 'use 16777101' }
            @{ why = 'ungebunden, Liste: Name und DHCP-MAC auf denselben Datensatz'
               given = @{ RolloutHostname = 'LIST-NIC'; Mac = '00:50:56:AA:BB:31' }
               expect = 'use 16777102' }
            @{ why = 'ungebunden, Kommatext: DHCP-MAC eines fremden Datensatzes unter neuem Namen bleibt mac_conflict'
               given = @{ RolloutHostname = 'FRESH-NAME'; Mac = '00:50:56:AA:BB:22' }
               expect = 'block mac_conflict' }
            @{ why = 'ungebunden, Kommatext: Name passt, DHCP-MAC fehlt im Datensatz, Portal-MACs binden nicht'
               given = @{ RolloutHostname = 'TEXT-NIC'; Mac = '00:50:56:AA:BB:99'; KnownMacs = @('00:50:56:AA:BB:21', '00:50:56:AA:BB:22') }
               expect = 'block mac_conflict' }
            @{ why = 'drei NICs, gemischte Trenner und Schreibweisen: ungebunden ueber die dritte MAC'
               given = @{ RolloutHostname = 'THREE-NIC'; Mac = '00:50:56:AA:BB:63' }
               expect = 'use 16777104' }
            @{ why = 'drei NICs: gebunden, nur die mittlere (klein mit Bindestrich notierte) MAC ist dem Portal bekannt'
               given = @{ RolloutHostname = 'THREE-NIC'; Mac = '00:50:56:AA:BB:99'; BoundResourceId = '16777104'; KnownMacs = @('00:50:56:AA:BB:62') }
               expect = 'use 16777104' }
            @{ why = 'ein ungueltiges Teil im Text: die gueltige MAC traegt die Erstbindung weiter'
               given = @{ RolloutHostname = 'HALF-NIC'; Mac = '00:50:56:AA:BB:71' }
               expect = 'use 16777105' }
            @{ why = 'leerer MAC-Text: gebundener Datensatz traegt keine MAC und blockiert'
               given = @{ RolloutHostname = 'NO-NIC'; Mac = '00:50:56:AA:BB:99'; BoundResourceId = '16777106'; KnownMacs = @('00:50:56:AA:BB:99') }
               expect = 'block resource_mac_conflict' }
            @{ why = 'leerer MAC-Text: Name passt, aber ohne MAC keine Erstbindung'
               given = @{ RolloutHostname = 'NO-NIC'; Mac = '00:50:56:AA:BB:99' }
               expect = 'block mac_conflict' }
        ) {
            Get-MacDecision -Arguments $given | Should -BeExactly $expect
        }

        It 'Regression: gemessenes Get-CMDevice-Format blockiert weder gebunden noch bei der Erstbindung' {
            # Genau die Form, die Select-Object Name, MACAddress, ResourceID aus
            # `Get-CMDevice -Fast` liefert: ein Objekt, MACAddress als ein Text.
            $record = [pscustomobject]@{ Name = 'MAC1-VM'; MACAddress = '00:50:56:AA:BB:51, 00:50:56:AA:BB:52'; ResourceID = 16777151 }
            $index = New-VsMecmDeviceIndex -Devices @($record)

            $bound = Resolve-VsDeviceIdentity -Index $index -RolloutHostname 'MAC1-VM' -Mac '00:50:56:AA:BB:51' -BoundResourceId '16777151' -KnownMacs @('00:50:56:AA:BB:51', '00:50:56:AA:BB:52')
            $bound.Action | Should -BeExactly 'use'
            $bound.ResourceId | Should -BeExactly '16777151'

            $unbound = Resolve-VsDeviceIdentity -Index $index -RolloutHostname 'MAC1-VM' -Mac '00:50:56:AA:BB:51'
            $unbound.Action | Should -BeExactly 'use'
            $unbound.ResourceId | Should -BeExactly '16777151'
        }
    }

    Context 'Quelltext' {
        It 'liest jede MAC-Eigenschaft eines MECM-Datensatzes nur ueber ConvertTo-VsNormalizedMacList' {
            # Abgeleitet statt aufgelistet: jeder Zugriff `.MACAddress` oder
            # `.MACAddresses` in einem MECM-Serverskript muss das direkte Argument
            # der Zerlegung sein. Ein neuer Leser, der den Text selbst iteriert,
            # wiederholt den MC-MAC1-Fehler und faellt hier auf.
            $mecmDir = Join-Path $script:PsRoot 'mecm'
            $offenders = @()
            $readers = 0
            foreach ($file in @(Get-ChildItem -Path $mecmDir -Filter '*.ps1' -File)) {
                $tokens = $null
                $errors = $null
                $ast = [System.Management.Automation.Language.Parser]::ParseFile($file.FullName, [ref]$tokens, [ref]$errors)
                $members = $ast.FindAll({
                        param($node)
                        $node -is [System.Management.Automation.Language.MemberExpressionAst] -and
                        $node.Member -is [System.Management.Automation.Language.StringConstantExpressionAst] -and
                        @('MACAddress', 'MACAddresses') -contains $node.Member.Value
                    }, $true)
                foreach ($member in @($members)) {
                    $readers++
                    $parent = $member.Parent
                    $ok = $parent -is [System.Management.Automation.Language.CommandAst] -and
                        $parent.GetCommandName() -eq 'ConvertTo-VsNormalizedMacList'
                    if (-not $ok) { $offenders += ('{0}:{1} {2}' -f $file.Name, $member.Extent.StartLineNumber, $member.Extent.Text) }
                }
            }
            $readers | Should -BeGreaterThan 0
            $offenders | Should -BeNullOrEmpty
        }
    }
}

Describe 'Geschlossene Ursachensprache' {
    BeforeAll {
        # Das Vokabular und der ValidateSet werden aus dem QUELLTEXT gelesen, in
        # beide Richtungen: eine Liste, die sich selbst prueft, prueft nichts.
        $script:CommonText = Get-Content -Path $script:MecmCommon -Raw
        $script:Vocabulary = & { . $script:MecmCommon; $script:VsRunCauseVocabulary }

        $ast = [System.Management.Automation.Language.Parser]::ParseInput($script:CommonText, [ref]$null, [ref]$null)
        $addCause = $ast.FindAll({
            param($node)
            $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Add-VsRunCause'
        }, $true)[0]
        $causeParam = $addCause.Body.ParamBlock.Parameters |
            Where-Object { $_.Name.VariablePath.UserPath -eq 'Cause' }
        $validateSet = $causeParam.Attributes |
            Where-Object { $_.TypeName.Name -eq 'ValidateSet' }
        $script:ValidateSetValues = @($validateSet.PositionalArguments | ForEach-Object { $_.Value })
    }

    It 'traegt die sechs Identitaetscodes der Etappe 14D' {
        foreach ($code in @('device_name_invalid', 'device_identity_ambiguous', 'previous_resource_present',
                'resource_id_missing', 'resource_mac_conflict', 'stale_rollout_revision')) {
            $script:Vocabulary | Should -Contain $code
        }
    }

    It 'fuehrt kein synonymes device_name_conflict neben mac_conflict' {
        # Ein Name mit fremder MAC und eine MAC mit fremdem Namen sind dieselbe
        # Frage aus zwei Richtungen; zwei Codes haetten den Operator zwei Zeilen
        # suchen lassen, die dieselbe Handarbeit verlangen.
        $script:Vocabulary | Should -Contain 'mac_conflict'
        $script:Vocabulary | Should -Not -Contain 'device_name_conflict'
        $script:ValidateSetValues | Should -Not -Contain 'device_name_conflict'
    }

    It 'haelt Vokabular und ValidateSet in BEIDE Richtungen deckungsgleich' {
        foreach ($code in $script:Vocabulary) { $script:ValidateSetValues | Should -Contain $code }
        foreach ($code in $script:ValidateSetValues) { $script:Vocabulary | Should -Contain $code }
    }

    It 'benutzt jeden Identitaetscode auch wirklich im Device-Sync' {
        # Eine geschlossene Sprache, deren Woerter niemand spricht, ist keine
        # Sprache: der Code muss an einem Add-VsRunCause haengen oder aus der
        # Aufloesung kommen, die der Sync durchreicht.
        $syncText = Get-Content -Path $script:DeviceSyncPath -Raw
        $syncText | Should -Match 'Resolve-VsDeviceIdentity'
        $syncText | Should -Match "Add-VsRunCause -Causes \`$causes -Cause \`$identity\.Cause"
        $syncText | Should -Match 'stale_rollout_revision'
    }
}

Describe 'Wire-Vertrag des Device-Sync' {
    BeforeAll {
        $script:SyncText = Get-Content -Path $script:DeviceSyncPath -Raw
        # Der Quelltext OHNE Kommentarzeilen. Zwei Verbote weiter unten nennen
        # genau das, was das Skript nicht mehr tut, und im Skript steht daneben
        # die Begruendung, warum nicht: eine Textsuche ueber die ganze Datei
        # haette also die Erklaerung des Fehlers als den Fehler gelesen und die
        # Nachwelt gezwungen, den Grund zu loeschen, um den Test gruen zu halten.
        $script:SyncCode = (Get-Content -Path $script:DeviceSyncPath |
            Where-Object { $_ -notmatch '^\s*#' }) -join "`n"
    }

    It 'importiert den Rolloutnamen, nicht den ESXi-Namen' {
        # Der ganze Zweck der Etappe. `-ComputerName $deviceName` war das, was
        # MECM den ESXi-Namen tragen liess.
        $script:SyncText | Should -Match 'Import-CMComputerInformation -ComputerName \$rolloutName'
        $script:SyncText | Should -Not -Match 'Import-CMComputerInformation -ComputerName \$deviceName'
    }

    It 'liest den Rolloutnamen aus dem Wire-Feld vm_hostname' {
        $script:SyncText | Should -Match '\$rolloutName\s*=\s*\[string\]\$device\.vm_hostname'
    }

    It 'loest niemals ueber den Namen allein auf und kennt kein -MergeIfExist' {
        $script:SyncCode | Should -Not -Match '-MergeIfExist'
        # Kein name-keyed Cache mehr; der Index ist die einzige Quelle.
        $script:SyncCode | Should -Not -Match '\$mecmDevices\['
    }

    It 'gibt die Portal-MACs nur der Pruefung der gebundenen ResourceID mit (MECM-ID1)' {
        $script:SyncCode | Should -Match '\$knownMacs\s*=\s*@\(\$device\.interfaces \| Select-Object -ExpandProperty mac'
        $joined = $script:SyncCode -replace '`\r?\n\s*', ' '
        $calls = @([regex]::Matches($joined, 'Resolve-VsDeviceIdentity [^\r\n]*'))
        # Zwei Aufrufe: die Hauptaufloesung und das Nachlesen in
        # Get-VsPostImportIdentity (das Nachlesen traegt keine Portal-MACs).
        $calls.Count | Should -Be 2
        @($calls | Where-Object { $_.Value -match '-KnownMacs \$knownMacs' }).Count | Should -Be 1
        $main = @($calls | Where-Object { $_.Value -match '-BoundResourceId \$boundResourceId' })
        $main.Count | Should -Be 1
        $main[0].Value | Should -Match '-KnownMacs \$knownMacs'
        # Import und Nachlesen bleiben bei der PXE-MAC allein.
        $script:SyncCode | Should -Match 'Import-CMComputerInformation -ComputerName \$rolloutName -MacAddress \$deviceMac'
    }

    It 'sendet die Rolloutrevision in beiden mutierenden Rueckmeldungen' {
        ([regex]::Matches($script:SyncText, "rollout_revision'\]\s*=\s*\[int\]\`$rolloutRevision")).Count |
            Should -BeGreaterOrEqual 2
    }
}

# MX1 (FM-05): Eine Ursache gilt im Portal nur fuer die gemeldete Rolloutrevision
# (ADR-0043-Fence). Der Sync nimmt sie deshalb je Geraet aus derselben
# getDeviceList-Zeile, nie aus dem gespeicherten Sperrzustand und nie geraten.
Describe 'Rolloutrevision je Geraet im Ursachenbericht (MX1)' {
    BeforeAll {
        $script:SyncText = Get-Content -Path $script:DeviceSyncPath -Raw
        $syncAst = [System.Management.Automation.Language.Parser]::ParseFile($script:DeviceSyncPath, [ref]$null, [ref]$null)
        foreach ($name in @('Add-VsReplayedDeviceBlock', 'Get-VsDeviceFingerprint')) {
            $fn = $syncAst.Find({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq $name }, $true)
            if (-not $fn) { throw "Funktion fehlt im Device-Sync: $name" }
            . ([scriptblock]::Create($fn.Extent.Text))
        }
    }

    It 'traegt jedes Paar die Revision seines eigenen Geraets' {
        $set = New-VsDeviceCauseSet
        $record = @{ causes = @('mac_missing target=VM'); item_failures = 0; data_failures = 1 }
        foreach ($device in @(
            [pscustomobject]@{ id = 17; rollout_revision = 3 },
            [pscustomobject]@{ id = 18; rollout_revision = 7 },
            [pscustomobject]@{ id = 19; rollout_revision = 1 }
        )) {
            [void](Add-VsReplayedDeviceBlock -Record $record -Causes (New-VsRunCauseList) -DeviceCauses $set -VmId $device.id -RolloutRevision $device.rollout_revision)
        }
        (@($set.Values | ForEach-Object { '{0}:{1}' -f $_['vm_id'], $_['rollout_revision'] }) -join ',') | Should -Be '17:3,18:7,19:1'
    }

    It 'erfindet keine Revision, wenn die Zeile keine traegt' {
        # Portal vor dem Cutover: ohne Revision kein Paar. Eine gesendete 0 oder 1
        # waere eine Behauptung ueber einen Rollout, den es nicht gibt.
        $set = New-VsDeviceCauseSet
        $record = @{ causes = @('mac_missing target=VM'); item_failures = 0; data_failures = 1 }
        [void](Add-VsReplayedDeviceBlock -Record $record -Causes (New-VsRunCauseList) -DeviceCauses $set -VmId 17 -RolloutRevision $null)
        $set.Count | Should -Be 0
    }

    It 'macht eine neue Revision die gesperrte VM sofort faellig (kein Replay mit alter Revision)' {
        $before = [pscustomobject]@{ id = 17; vm_name = 'VM-A'; rollout_revision = 3 }
        $after = [pscustomobject]@{ id = 17; vm_name = 'VM-A'; rollout_revision = 4 }
        (Get-VsDeviceFingerprint -Device $before) | Should -Not -Be (Get-VsDeviceFingerprint -Device $after)
    }

    It 'liest der Sync die Revision je Geraet aus der aktuellen Zeile' {
        $script:SyncText | Should -Match 'RolloutRevision\s+=\s+\$device\.rollout_revision'
        $script:SyncText | Should -Match '-VmId \$pending\.Id -RolloutRevision \$pending\.RolloutRevision'
        ([regex]::Matches($script:SyncText, '-VmId \$device\.id -RolloutRevision \$device\.rollout_revision')).Count | Should -Be 2
    }
}

Describe 'Client-Kette traegt die Rolloutrevision' {
    BeforeAll {
        $script:GetInfoText = Get-Content -Path $script:ClientGetInfoPath -Raw
        $script:ClientCommonText = Get-Content -Path $script:ClientCommon -Raw
    }

    It 'whitelistet rollout_revision, damit sie ueberhaupt gespeichert wird' {
        $script:GetInfoText | Should -Match "\`$allowedFields = @\([^)]*'rollout_revision'"
    }

    It 'validiert und publiziert beide Package-Report-Generationen' {
        $script:GetInfoText | Should -Match "\`$allowedFields = @\([^)]*'device_generation'[^)]*'acceptance_generation'"
        $script:GetInfoText | Should -Match '\$data\.device_generation -cnotmatch \$uuidPattern'
        $script:GetInfoText | Should -Match '\$data\.acceptance_generation -cnotmatch \$uuidPattern'
        $script:GetInfoText | Should -Match "Get-ItemProperty -Path \`$snapshotRoot -Name [^\r\n]*'device_generation', 'acceptance_generation'"
    }

    It 'sendet sie im ACK zurueck' {
        $script:GetInfoText | Should -Match 'Confirm-VsClientReady .*-RolloutRevision \$data\.rollout_revision'
        $script:ClientCommonText | Should -Match '\$RolloutRevision'
    }

    It 'laesst das Feld weg, statt eine Revision zu erfinden' {
        # Ein Client vor dem Cutover meldet ohne Revision, und der Server nimmt
        # das nur fuer Revision 1 ohne Tombstone an. Eine gesendete 0 waere eine
        # Behauptung ueber einen Rollout, den es nicht gibt.
        $script:ClientCommonText | Should -Match "\`$payload\['rollout_revision'\] = \[int\]\`$RolloutRevision"
        # Der Wert wird geprueft, bevor er in den Body geht: nur Ziffern und
        # groesser 0. Ein leerer oder nicht numerischer Wert erzeugt kein Feld.
        $script:ClientCommonText | Should -Match '\[int\]\$RolloutRevision -gt 0'
        $script:ClientCommonText | Should -Match '\$payload = @\{ mac = \$Mac \}'
    }
}
