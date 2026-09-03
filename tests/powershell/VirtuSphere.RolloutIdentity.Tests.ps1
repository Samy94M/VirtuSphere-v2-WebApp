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
    $script:ClientGetInfoPath = Join-Path (Join-Path $script:PsRoot 'clients') 'client_getinfo.ps1'

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

    It 'sendet die Rolloutrevision in beiden mutierenden Rueckmeldungen' {
        ([regex]::Matches($script:SyncText, "rollout_revision'\]\s*=\s*\[int\]\`$rolloutRevision")).Count |
            Should -BeGreaterOrEqual 2
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
