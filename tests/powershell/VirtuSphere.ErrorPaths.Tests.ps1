# Pester-Suite fuer die Fehlerpfade der PowerShell-Integrationsclients (AP5):
# HTTP-Statuscodes, verlorene/kaputte Registry-Werte, TLS-Kontrakt, Token-
# Redaction, Doppelstart-Schutz und Backoff-Vertrag der Endlosschleifen.
#
# Ergaenzt VirtuSphere.Common.Tests.ps1 (reine Funktionslogik); hier geht es um
# das Verhalten, wenn die Umgebung kaputt ist - genau die Faelle, die als
# SYSTEM in einer Endlosschleife sonst niemand sieht.
#
# Pfade als Join-Path-Ketten, keine Backslash-Literale: die Suite laeuft auch
# unter pwsh auf Linux (CI). Registry-Faelle sind in einen $HasRegistry-Block
# gekapselt und laufen nur auf Windows (der PS-5.1-CI-Job deckt sie ab).

# Auf Dateiebene, weil -ForEach schon in der Discovery-Phase gebraucht wird
# (gleiches Muster wie die MAC-Vektoren in VirtuSphere.Common.Tests.ps1). Die
# Vektoren sind DIESELBE Datei, die MecmPlanVectorsTest auf der PHP-Seite
# laeuft: Portal-Vorschau und Device-Sync-Apply koennen nicht auseinanderlaufen.
$PlanRepoRootDiscovery = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$PlanVectorFileDiscovery = Join-Path (Join-Path (Join-Path (Join-Path (Join-Path $PlanRepoRootDiscovery 'Docker') 'WebAPI') 'tests') 'fixtures') 'mecm-plan-vectors.json'
$PlanVectorCases = (Get-Content -Path $PlanVectorFileDiscovery -Raw | ConvertFrom-Json).vectors |
    ForEach-Object { @{ name = $_.name; why = $_.why; desired = @($_.desired); owned = @($_.owned); present = @($_.present); expected = $_.expected } }

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $psRoot = Join-Path $script:RepoRoot 'Powershell-MECM'
    $script:MecmCommon = Join-Path (Join-Path $psRoot 'mecm') 'VirtuSphere-Common.ps1'
    $script:DeviceSync = Join-Path (Join-Path $psRoot 'mecm') 'mecm_new-device-sync.ps1'
    $script:ClientCommon = Join-Path (Join-Path $psRoot 'clients') 'VirtuSphere-Client-Common.ps1'
    $script:Installer = Join-Path $psRoot 'install-VirtuSphere-MECM.ps1'
    $script:PsRoot = $psRoot

    # Gleiche Begruendung wie in VirtuSphere.Common.Tests.ps1: Dot-Source im
    # Kindscope, damit die beiden Common-Dateien sich nicht gegenseitig
    # ueberschreiben.
    function Invoke-InFileScope {
        param([string]$Path, [scriptblock]$Body, [object[]]$Arguments = @())
        & {
            param($p, $b, $a)
            . $p
            & $b @a
        } $Path $Body $Arguments
    }

    # Fehlerrecord im Format von Invoke-RestMethod unter PS 5.1 nachbauen.
    function New-FakeHttpError {
        param([int]$StatusCode, [string]$Body = '')
        $response = [pscustomobject]@{ StatusCode = $StatusCode }
        if ($Body -ne '') {
            $stream = New-Object System.IO.MemoryStream (, [Text.Encoding]::UTF8.GetBytes($Body))
            $response | Add-Member -MemberType ScriptMethod -Name GetResponseStream -Value { $stream }.GetNewClosure()
        }
        return [pscustomobject]@{
            Exception = [pscustomobject]@{
                Message  = ('The remote server returned an error: ({0}).' -f $StatusCode)
                Response = $response
            }
        }
    }
}

Describe 'Get-VsErrorStatusCode' {

    It 'liefert den Statuscode <code> als Zahl' -ForEach @(
        @{ code = 400 }
        @{ code = 401 }
        @{ code = 403 }
        @{ code = 409 }
        @{ code = 429 }
        @{ code = 500 }
    ) {
        $record = New-FakeHttpError -StatusCode $code
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($record) -Body {
            param($r) Get-VsErrorStatusCode -ErrorRecord $r
        } | Should -Be $code
    }

    It 'liefert $null ohne Response (DNS, Timeout, Connection refused)' {
        $record = [pscustomobject]@{
            Exception = [pscustomobject]@{ Message = 'Der Remotename konnte nicht aufgeloest werden.' }
        }
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($record) -Body {
            param($r) Get-VsErrorStatusCode -ErrorRecord $r
        } | Should -BeNullOrEmpty
    }

    It 'liefert $null bei einer Response ohne StatusCode' {
        $record = [pscustomobject]@{
            Exception = [pscustomobject]@{
                Message  = 'kaputt'
                Response = [pscustomobject]@{ NotAStatus = 1 }
            }
        }
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($record) -Body {
            param($r) Get-VsErrorStatusCode -ErrorRecord $r
        } | Should -BeNullOrEmpty
    }
}

Describe 'Get-VsErrorDetail mit Envelope je Statuscode' {

    It 'traegt die WebApp-Envelope auch bei <code> in den Log-Text' -ForEach @(
        @{ code = 401; msg = 'Ungueltiger Token' }
        @{ code = 409; msg = 'Job ist bereits abgeschlossen' }
        @{ code = 429; msg = 'Zu viele Anfragen' }
    ) {
        $record = New-FakeHttpError -StatusCode $code -Body ('{"error":"' + $msg + '"}')
        $detail = Invoke-InFileScope -Path $script:MecmCommon -Arguments @($record) -Body {
            param($r) Get-VsErrorDetail -ErrorRecord $r
        }
        $detail | Should -BeLike ('*WebApp: ' + $msg + '*')
    }
}

Describe 'Get-VsApiHeaders (Token-Handhabung)' {

    It 'setzt den Token-Header, wenn ein ReportToken konfiguriert ist' {
        $headers = Invoke-InFileScope -Path $script:MecmCommon -Body {
            Get-VsApiHeaders -Config ([pscustomobject]@{ ReportToken = 'tok-123' })
        }
        $headers['X-VirtuSphere-Token'] | Should -Be 'tok-123'
    }

    It 'sendet keinen Token-Header bei leerem oder Whitespace-Token' -ForEach @(
        @{ token = '' }
        @{ token = '   ' }
        @{ token = $null }
    ) {
        $headers = Invoke-InFileScope -Path $script:MecmCommon -Arguments @($token) -Body {
            param($t) Get-VsApiHeaders -Config ([pscustomobject]@{ ReportToken = $t })
        }
        $headers.ContainsKey('X-VirtuSphere-Token') | Should -BeFalse
        # ADR-0032: die Korrelations-ID ist kein Secret und haengt nicht am Token.
        $headers['X-VirtuSphere-Correlation'] | Should -Match '^[0-9a-f]{16}$'
    }

    It 'traegt die Korrelations-ID schon in der ersten Logzeile, vor jedem API-Aufruf (B11)' {
        # Die Datei deklarierte die ID doppelt: beim Laden 8 Zeichen, spaeter
        # $null. Jede Logzeile vor dem ersten Header-Bau trug deshalb einen
        # leeren ID-Feldwert, und genau die fruehen Zeilen (Config gelesen,
        # Site-Code erkannt) sind die, die man beim Debuggen zuerst sucht.
        $result = Invoke-InFileScope -Path $script:MecmCommon -Body {
            $root = Join-Path ([System.IO.Path]::GetTempPath()) ('vs-corr-' + [guid]::NewGuid().ToString('N'))
            Initialize-VsLog -Component 'PesterCorr' -LogRoot $root
            Write-VsLog -Message 'erste Zeile' | Out-Null
            $file = Get-ChildItem -Path $root -Filter '*_PesterCorr.log' | Select-Object -First 1
            $line = Get-Content -Path $file.FullName | Select-Object -First 1
            $headerId = (Get-VsApiHeaders -Config ([pscustomobject]@{ ReportToken = '' }))['X-VirtuSphere-Correlation']
            Remove-Item -Recurse -Force $root
            , @($line, $headerId)
        }
        $logId = ($result[0] -split '\|')[-1].Trim()
        $logId | Should -Match '^[0-9a-f]{16}$'
        # Und es ist DIESELBE ID, die der API-Header traegt: eine Spur pro
        # Prozesslauf, nicht eine fuers Log und eine fuer die Wire.
        $result[1] | Should -Be $logId
    }

    It 'mintet die Korrelations-ID einmal pro Lauf und haelt sie dann konstant (ADR-0032)' {
        $ids = Invoke-InFileScope -Path $script:MecmCommon -Body {
            $a = (Get-VsApiHeaders -Config ([pscustomobject]@{ ReportToken = 'tok' }))['X-VirtuSphere-Correlation']
            $b = (Get-VsApiHeaders -Config ([pscustomobject]@{ ReportToken = 'tok' }))['X-VirtuSphere-Correlation']
            , @($a, $b)
        }
        $ids[0] | Should -Match '^[0-9a-f]{16}$'
        $ids[1] | Should -Be $ids[0]
    }
}

Describe 'Get-VsMembershipPlan (gemeinsame Vektoren mit PHP, ADR-0034)' {

    It 'vector <name>' -ForEach $PlanVectorCases {
        $plan = Invoke-InFileScope -Path $script:MecmCommon -Arguments @($desired, $owned, $present) -Body {
            param($d, $o, $p) Get-VsMembershipPlan -Desired $d -Owned $o -Present $p
        }

        ((@($plan.add) | ForEach-Object { [string]$_.name } | Sort-Object) -join '|') |
            Should -Be ((@($expected.add) | Sort-Object) -join '|') -Because $why
        foreach ($bucket in @('preserve', 'preserve_manual', 'remove', 'stale_owned', 'foreign')) {
            ((@($plan.$bucket) | ForEach-Object { [string]$_.collection_id } | Sort-Object) -join '|') |
                Should -Be ((@($expected.$bucket) | Sort-Object) -join '|') -Because ($bucket + ': ' + $why)
        }
    }
}

Describe 'Get-VsContentDistributionState (B7: mehrwertig statt Ja/Nein)' {

    It '<name>' -ForEach @(
        @{ name = 'keine Statuszeile -> not_started'; entries = @(); expected = 'not_started' }
        @{ name = 'Targeted 0 -> not_started'; entries = @(@{ Targeted = 0; NumberInstalled = 0; NumberErrors = 0 }); expected = 'not_started' }
        @{ name = 'Fehler auf einem DP -> failed, egal wie viel installiert ist'; entries = @(@{ Targeted = 2; NumberInstalled = 2; NumberErrors = 1 }); expected = 'failed' }
        @{ name = 'angestossen, unfertig -> in_progress'; entries = @(@{ Targeted = 3; NumberInstalled = 1; NumberErrors = 0 }); expected = 'in_progress' }
        @{ name = 'vollstaendige Zielverteilung -> succeeded'; entries = @(@{ Targeted = 2; NumberInstalled = 2; NumberErrors = 0 }); expected = 'succeeded' }
        @{ name = 'mehrere Eintraege werden aggregiert'; entries = @(@{ Targeted = 1; NumberInstalled = 1; NumberErrors = 0 }, @{ Targeted = 1; NumberInstalled = 0; NumberErrors = 0 }); expected = 'in_progress' }
    ) {
        # Die alte boolesche Frage las Targeted > 0 als erledigt und NumberErrors
        # las niemand: eine ueberall gescheiterte Verteilung galt als fertig.
        $state = Invoke-InFileScope -Path $script:MecmCommon -Arguments @(, $entries) -Body {
            param($e)
            function Get-CMDistributionStatus { param($Name, $Id, [string]$ErrorAction) $e }
            Get-VsContentDistributionState -ApplicationName 'App'
        }
        $state | Should -Be $expected
    }

    It 'eine werfende Abfrage ist unknown, nie ein erfundener Zustand' {
        $state = Invoke-InFileScope -Path $script:MecmCommon -Body {
            function Get-CMDistributionStatus { param($Name, $Id, [string]$ErrorAction) throw 'kein Site-Drive' }
            Get-VsContentDistributionState -ApplicationName 'App'
        }
        $state | Should -Be 'unknown'
    }

    It 'mit ApplicationId fragt sie per -Id ab, nicht per -Name' {
        # -Name trifft bei Namensgleichheit das falsche Objekt (B7); wo der
        # Aufrufer das Application-Objekt hat, gewinnt die CI_ID.
        $probe = Invoke-InFileScope -Path $script:MecmCommon -Body {
            $captured = @{}
            function Get-CMDistributionStatus { param($Name, $Id, [string]$ErrorAction) $captured.Name = $Name; $captured.Id = $Id; @() }
            Get-VsContentDistributionState -ApplicationName 'App' -ApplicationId 4711 | Out-Null
            $captured
        }
        $probe.Id | Should -Be 4711
        $probe.Name | Should -BeNullOrEmpty
    }
}

Describe 'Token-Redaction (statischer Vertrag ueber alle Quellen)' {

    It 'keine Log-/Ausgabezeile referenziert den ReportToken-Wert' {
        # Der Rueckkanal-Token liegt als Klartext in der Registry und in
        # $Config. Er darf konfiguriert, gelesen und als Header gesetzt werden,
        # aber nie in eine Log- oder Konsolenausgabe fliessen: die Tageslogs
        # liegen fuer Operatoren lesbar auf dem MECM-Server.
        $sources = Get-ChildItem -Path $script:PsRoot -Recurse -Include '*.ps1', '*.psm1' -File
        @($sources).Count | Should -BeGreaterThan 0

        # Write-Debug/-Verbose fehlen bewusst: beides ist Opt-in-Konsole und
        # landet nie in den Tagesdateien; die Catch-Bloecke loggen dort den
        # ErrorRecord eines GESCHEITERTEN Zugriffs, keinen Tokenwert.
        $sink = '(Write-VsLog|Write-VsClientLog|Write-Host|Write-Output|Write-Warning|Write-Ok|Write-Warn|Write-Err)'
        $tokenValue = '(\$ReportToken|\.ReportToken|X-VirtuSphere-Token)'
        $violations = @()
        foreach ($file in $sources) {
            $lineNo = 0
            foreach ($line in (Get-Content -Path $file.FullName)) {
                $lineNo++
                if ($line -match $sink -and $line -match $tokenValue) {
                    $violations += ('{0}:{1} {2}' -f $file.Name, $lineNo, $line.Trim())
                }
            }
        }
        $violations -join "`n" | Should -BeNullOrEmpty
    }
}

Describe 'Initialize-VsTls (TLS-Kontrakt des Clients)' {

    BeforeEach {
        $script:SavedCallback = [System.Net.ServicePointManager]::ServerCertificateValidationCallback
        $script:SavedProtocol = [System.Net.ServicePointManager]::SecurityProtocol
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback = $null
    }
    AfterEach {
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback = $script:SavedCallback
        [System.Net.ServicePointManager]::SecurityProtocol = $script:SavedProtocol
    }

    It 'tut bei http nichts (kein Callback, keine Protokollaenderung noetig)' {
        Invoke-InFileScope -Path $script:ClientCommon -Body {
            # Registry-Override ausschalten, Default-Schema http erzwingen.
            $script:VsRegistryBase = 'HKCU:\Software\_vs_pester_missing_' + [guid]::NewGuid().ToString('N')
            $script:VsDefaultScheme = 'http'
            Initialize-VsTls
        }
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback | Should -BeNullOrEmpty
    }

    It 'ueberbrueckt die Zertifikatspruefung auch bei https NICHT ohne Opt-in' {
        # $VsAllowSelfSignedTls ist bewusst ein Schalter und kein Default:
        # eine dauerhaft blinde TLS-Pruefung waere schlechter als ehrliches
        # HTTP. Dieser Test pinnt das Opt-in.
        Invoke-InFileScope -Path $script:ClientCommon -Body {
            $script:VsRegistryBase = 'HKCU:\Software\_vs_pester_missing_' + [guid]::NewGuid().ToString('N')
            $script:VsDefaultScheme = 'https'
            $script:VsAllowSelfSignedTls = $false
            Initialize-VsTls
        }
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback | Should -BeNullOrEmpty
    }
}

Describe 'Resolve-VsApi trennt Adresswahl von Gesundheit' {

    # Der Befund: health.php antwortete bei "degraded" mit 503, Invoke-RestMethod
    # wirft unter PS 5.1 bei 5xx, und damit galt das Portal fuer JEDES
    # Client-Skript auf JEDER VM als unerreichbar. Ein einzelner haengender
    # Bereitstellungsauftrag konnte die ganze Client-Kette stilllegen.
    #
    # Die Regel, die das dauerhaft verhindert, liegt auf der Client-Seite und gilt
    # unabhaengig davon, was health.php kuenftig sendet: ein Statuscode beweist,
    # dass die Adresse stimmt. Nur ein Transportfehler (kein Statuscode) ist ein
    # Grund, die naechste Adresse zu probieren.

    It 'wertet HTTP <code> als "Adresse stimmt"' -ForEach @(
        @{ code = 503 }   # health.php "degraded" vor dem Fix: der Ausgangsbefund
        @{ code = 500 }
        @{ code = 403 }   # IP nicht freigegeben: die Adresse ist trotzdem richtig
        @{ code = 404 }
    ) {
        $answered = Invoke-InFileScope -Path $script:ClientCommon -Arguments @((New-FakeHttpError -StatusCode $code)) -Body {
            param($record)
            Test-VsApiAnswered -ErrorRecord $record
        }

        $answered | Should -BeTrue
    }

    It 'wertet einen Transportfehler ohne Statuscode als "Adresse stimmt nicht"' {
        # DNS-Fehler, Verbindung abgelehnt, Timeout, TLS-Handshake: kein Response,
        # also kein Beweis, dass dort ueberhaupt etwas horcht.
        $transportError = [pscustomobject]@{ Exception = [pscustomobject]@{ Message = 'Der Remotename konnte nicht aufgeloest werden.' } }

        $answered = Invoke-InFileScope -Path $script:ClientCommon -Arguments @($transportError) -Body {
            param($record)
            Test-VsApiAnswered -ErrorRecord $record
        }

        $answered | Should -BeFalse
    }

    It 'benutzt genau dieses Praedikat in Resolve-VsApi' {
        # Ohne diesen Pin lebt die Entscheidung wieder als Inline-Bedingung in der
        # Schleife, und der Test oben prueft eine Funktion, die niemand aufruft.
        $source = Get-Content -Path $script:ClientCommon -Raw
        $source | Should -Match 'function Resolve-VsApi[\s\S]*?Test-VsApiAnswered -ErrorRecord \$_'
    }

    It 'hat Get-VsErrorStatusCode als Zwilling der MECM-Seite (ADR-0029)' {
        # Beide Seiten teilen keinen Code, also muss der Zwilling existieren und
        # dasselbe liefern; ohne ihn faellt die Regel oben lautlos auf "jeder
        # Fehler ist unerreichbar" zurueck.
        foreach ($file in @($script:ClientCommon, $script:MecmCommon)) {
            $code = Invoke-InFileScope -Path $file -Arguments @((New-FakeHttpError -StatusCode 503)) -Body {
                param($record)
                Get-VsErrorStatusCode -ErrorRecord $record
            }
            $code | Should -Be 503
        }
    }
}

Describe 'Device-Sync: leere JSON-Geraeteliste unter Windows PowerShell 5.1' {

    It 'normalisiert das von Invoke-RestMethod verschachtelte leere Array auf 0 Devices' {
        # PS 5.1 liefert [] aus Invoke-RestMethod in einem direkten @(...)-Aufruf
        # als ein skalares, leeres Object[]: der aeussere Collector zaehlt 1.
        $emptyRestResponse = ,([object[]]@())
        @($emptyRestResponse).Count | Should -Be 1

        $devices = @($emptyRestResponse | ForEach-Object { $_ })
        $devices.Count | Should -Be 0
    }

    It 'pinnt die Pipeline-Normalisierung im produktiven Device-Sync' {
        $source = Get-Content -Path $script:DeviceSync -Raw
        $source | Should -Match '\$deviceResponse\s*=\s*Invoke-VsApi'
        $source | Should -Match '\$devices\s*=\s*@\(\$deviceResponse\s*\|\s*ForEach-Object'
        $source | Should -Not -Match '\$devices\s*=\s*@\(Invoke-VsApi'
    }
}

Describe 'Installer: Doppelstart- und Laufzeit-Vertrag der geplanten Aufgaben' {

    It 'registriert die Endlosschleifen mit MultipleInstances IgnoreNew' {
        # Ein zweiter Start desselben Sync (manuell oder durch einen Trigger)
        # wuerde doppelte Imports und konkurrierende Registry-Writes fahren.
        (Get-Content -Path $script:Installer -Raw) |
            Should -Match '-MultipleInstances\s+IgnoreNew'
    }

    It 'hebt das 72h-Laufzeitlimit auf (PT0S), sonst killt der Scheduler die Loops' {
        (Get-Content -Path $script:Installer -Raw) |
            Should -Match "ExecutionTimeLimit\s*=\s*'PT0S'"
    }

    It 'laeuft als SYSTEM mit hoechsten Rechten' {
        (Get-Content -Path $script:Installer -Raw) |
            Should -Match "New-ScheduledTaskPrincipal\s+-UserId\s+'S-1-5-18'\s+-RunLevel\s+Highest"
    }

    It 'verwendet fuer die Registry-ACL sprachunabhaengige Well-Known-SIDs' {
        $installerText = Get-Content -Path $script:Installer -Raw
        $installerText | Should -Match "'S-1-5-18'"
        $installerText | Should -Match "'S-1-5-32-544'"
        $installerText | Should -Not -Match 'BUILTIN'
    }

    It 'startet die Tasks beim Systemstart (AtStartup)' {
        (Get-Content -Path $script:Installer -Raw) |
            Should -Match 'New-ScheduledTaskTrigger\s+-AtStartup'
    }
}

Describe 'Installer: die vier geplanten Aufgaben' {

    BeforeAll {
        $script:InstallerText = Get-Content -Path $script:Installer -Raw
    }

    It 'definiert GENAU vier Task-Eintraege im $tasks-Array' {
        # Jede Aufgabe ist ein '@{ Name = ...; Script = ... }'-Eintrag. Genau
        # vier: eine dritte oder fuenfte waere ein unbeabsichtigter Task.
        $taskMatches = [regex]::Matches($script:InstallerText, "@\{\s*Name\s*=\s*'VirtuSphere MECM")
        $taskMatches.Count | Should -Be 4
    }

    It 'nennt jeden der vier Task-Namen und sein Skript: <TaskName>' -ForEach @(
        @{ TaskName = 'VirtuSphere MECM Devices Sync';   Script = 'mecm_new-device-sync.ps1' }
        @{ TaskName = 'VirtuSphere MECM Packages Sync';  Script = 'mecm_Packages-TaskSeq-sync.ps1' }
        @{ TaskName = 'VirtuSphere MECM Package Import'; Script = 'mecm_autoimporter.ps1' }
        @{ TaskName = 'VirtuSphere MECM Site Health';    Script = 'mecm_site-health.ps1' }
    ) {
        $script:InstallerText | Should -Match ([regex]::Escape($TaskName))
        $script:InstallerText | Should -Match ([regex]::Escape($Script))
    }

    It 'das Site-Health-Skript existiert im mecm-Ordner' {
        Test-Path (Join-Path (Join-Path $script:PsRoot 'mecm') 'mecm_site-health.ps1') | Should -BeTrue
    }

    It 'schreibt die beiden neuen Registry-Werte (create-if-missing, kein New-Item -Force auf den Key)' {
        # SiteHealthIntervalSeconds und MECM_ProviderMachine sind die EINZIGEN
        # neuen Schluessel. Der Provider wird nur bei Vorhandensein geschrieben
        # (Preserve-on-empty wie der ReportToken), damit ein Re-Run ohne
        # -ProviderMachine den bestehenden Wert nicht kappt.
        $script:InstallerText | Should -Match 'SiteHealthIntervalSeconds'
        $script:InstallerText | Should -Match 'MECM_ProviderMachine'
        $script:InstallerText | Should -Match "IsNullOrWhiteSpace\(\`$ProviderMachine\)"
        # Der Konfig-Key wird nie per New-Item -Force angelegt (das wische Werte).
        $script:InstallerText | Should -Not -Match 'New-Item\s+-Path\s+\$registryPath\s+-Force[^\r\n]*# *wipe'
    }

    It 'prueft die DP-Gruppe vor der Installation, statt den Namen nur entgegenzunehmen' {
        # Im Feld gesehen: konfiguriert war 'DP Group - VirtuSphere-Applications',
        # tatsaechlich trug die Gruppe zusaetzlich ein Organisationskuerzel im
        # Namen. Die Installation lief gruen durch, der Autoimporter haette jede App
        # mit Deployment und ohne Content erzeugt (Start-CMContentDistribution
        # wirft, der Autoimporter faengt und loggt WARN). Der Installer muss den
        # Namen daher gegen SMS_DistributionPointGroup pruefen und die
        # vorhandenen Gruppennamen nennen, damit die richtige Schreibweise
        # sofort sichtbar ist.
        $script:InstallerText | Should -Match 'SMS_DistributionPointGroup'
        $script:InstallerText | Should -Match '\$DpGroupName'
        $script:InstallerText | Should -Match 'MemberCount'
    }

    It 'bricht bei fehlender DP-Gruppe NICHT ab (sie darf spaeter entstehen)' {
        # Die Pruefung ist eine Warnung, kein throw: eine Umgebung darf die
        # Gruppe nach der Installation anlegen. Ein Abbruch wuerde eine
        # funktionierende Installation an einer nachholbaren Kleinigkeit
        # scheitern lassen.
        $dpBlock = [regex]::Match($script:InstallerText,
            'SMS_DistributionPointGroup(?s).{0,1200}')
        $dpBlock.Success | Should -BeTrue
        $dpBlock.Value | Should -Not -Match '\bthrow\b'
    }
}

Describe 'Kein Organisationskuerzel in Registry- und Programmpfaden' {

    # Die Skripte laufen in fremden Umgebungen, deren Organisationskuerzel wir
    # nicht kennen und nie erraten koennen. Ein Kuerzel in einem Registry- oder
    # Logpfad ist damit keine Kosmetik, sondern eine falsche Zusicherung: der
    # Pfad gehoert VirtuSphere, nicht dem Kunden, bei dem er zuerst entstanden
    # ist. Zwei Client-Phasen trugen ihre Erkennungsschluessel jahrelang unter
    # dem Kuerzel der Erstumgebung, waehrend die beiden anderen Phasen schon
    # unter SOFTWARE\VirtuSphere lagen. Der Vertrag prueft die Klasse, nicht die
    # einzelnen Kuerzel: eine Denyliste kennt immer nur die schon gesehenen.
    BeforeAll {
        $script:AllPsFiles = @(Get-ChildItem -Path $script:PsRoot -Filter '*.ps1' -Recurse -File)
    }

    It 'jeder SOFTWARE-Registry-Zweig liegt unter VirtuSphere' {
        $offenders = foreach ($file in $script:AllPsFiles) {
            $text = Get-Content -Path $file.FullName -Raw
            foreach ($m in [regex]::Matches($text, '(?i)HK(?:LM|CU):?\\Software\\([A-Za-z0-9_.-]+)')) {
                if ($m.Groups[1].Value -ne 'VirtuSphere') {
                    "{0}: {1}" -f $file.Name, $m.Value
                }
            }
        }
        $offenders | Should -BeNullOrEmpty
    }

    It 'jeder Programmpfad liegt unter VirtuSphere' {
        $patterns = @(
            '(?i)Program ?Files\)?[''"]?\\([A-Za-z0-9_.-]+)'
            '(?i)Join-Path\s+\$env:ProgramFiles\s+[''"]([A-Za-z0-9_.-]+)'
        )
        $offenders = foreach ($file in $script:AllPsFiles) {
            $text = Get-Content -Path $file.FullName -Raw
            foreach ($pattern in $patterns) {
                foreach ($m in [regex]::Matches($text, $pattern)) {
                    if ($m.Groups[1].Value -ne 'VirtuSphere') {
                        "{0}: {1}" -f $file.Name, $m.Value
                    }
                }
            }
        }
        $offenders | Should -BeNullOrEmpty
    }

    It 'die vier Client-Erkennungsschluessel der Spec liegen unter VirtuSphere' {
        # Die Spec ist die SSoT fuer die MECM-Erkennungsregeln. Ein Kuerzel hier
        # wandert in die Application am Server und ist danach nur noch dort zu
        # korrigieren, nicht mehr im Repository.
        $specs = Invoke-InFileScope -Path (Join-Path (Join-Path $script:PsRoot 'mecm') 'VirtuSphere-ClientPackaging.ps1') -Body {
            Get-VsClientAppSpecs
        }
        $specs.Count | Should -Be 4
        foreach ($spec in $specs) {
            $spec.DetectionKey | Should -Match '^SOFTWARE\\VirtuSphere(\\|$)'
        }
    }
}

Describe 'Backoff-Vertrag der Sync-Endlosschleifen' {

    It '<name> laeuft endlos, faengt Fehler ab und schlaeft je Iteration (Backoff)' -ForEach @(
        @{ name = 'mecm_new-device-sync.ps1' }
        @{ name = 'mecm_Packages-TaskSeq-sync.ps1' }
        @{ name = 'mecm_autoimporter.ps1' }
        @{ name = 'mecm_site-health.ps1' }
    ) {
        # Frueher beendete der erste Fehler den Sync bis zum naechsten Reboot.
        # Der Vertrag: die Schleife laeuft endlos, ein Fehler fuehrt in einen
        # Catch, und die Iteration schlaeft statt heiss zu drehen (Backoff). Seit
        # dem Run-Report-Umbau steht der Sleep am Schleifenende ($sleepSeconds
        # wird im Catch gesetzt), damit die Abschlussmeldung im finally VOR dem
        # Backoff rausgeht - deshalb wird der Sleep im Schleifenrumpf geprueft,
        # nicht mehr direkt im Catch.
        $path = Join-Path (Join-Path $script:PsRoot 'mecm') $name
        $tokens = $null
        $errors = $null
        $ast = [System.Management.Automation.Language.Parser]::ParseFile($path, [ref]$tokens, [ref]$errors)
        @($errors).Count | Should -Be 0

        $loops = $ast.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.WhileStatementAst] -and
            $n.Condition.Extent.Text -match '\$true'
        }, $true)
        @($loops).Count | Should -BeGreaterThan 0

        $catches = $ast.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.CatchClauseAst]
        }, $true)
        @($catches).Count | Should -BeGreaterThan 0

        # Der Schleifenrumpf schlaeft (Intervall/Backoff), kein heisses Drehen.
        @($loops)[0].Extent.Text | Should -Match 'Start-Sleep'
    }
}

# Registry-Faelle: nur auf Windows definiert (Registry-Provider). Der
# PS-5.1-CI-Job (windows-latest) beweist sie; auf dem Linux-Runner existiert
# der Block nicht.
$HasRegistry = Test-Path 'HKCU:\'
if ($HasRegistry) {

    Describe 'Get-VsConfig (verlorene und kaputte Registry-Werte)' {

        BeforeEach {
            $script:probeKey = 'HKCU:\Software\_vs_errorpaths_pester_' + [guid]::NewGuid().ToString('N')
        }
        AfterEach {
            Remove-Item -Path $script:probeKey -Recurse -Force -ErrorAction SilentlyContinue
        }

        It 'liefert $null, wenn der Konfigurationsschluessel fehlt' {
            Invoke-InFileScope -Path $script:MecmCommon -Arguments @($script:probeKey) -Body {
                param($probe)
                $script:VsRegistryPath = $probe
                Get-VsConfig
            } | Should -BeNullOrEmpty
        }

        It 'liefert $null nach einem Registry-Wipe (Key da, WebAPI-Wert weg)' {
            # Genau der Zustand, den New-Item -Force hinterlaesst (der
            # historische Installer-Bug): der Sync darf dann warten statt mit
            # leerer Adresse loszulaufen.
            New-Item -Path $script:probeKey -Force | Out-Null
            New-ItemProperty -Path $script:probeKey -Name 'ReportToken' -Value 'tok' -PropertyType String -Force | Out-Null
            Invoke-InFileScope -Path $script:MecmCommon -Arguments @($script:probeKey) -Body {
                param($probe)
                $script:VsRegistryPath = $probe
                Get-VsConfig
            } | Should -BeNullOrEmpty
        }

        It 'fuellt fehlende optionale Werte mit den Installer-Defaults' {
            New-Item -Path $script:probeKey -Force | Out-Null
            New-ItemProperty -Path $script:probeKey -Name 'VirtuSphere_WebAPI' -Value 'virtusphere.lan:8021' -PropertyType String -Force | Out-Null
            $cfg = Invoke-InFileScope -Path $script:MecmCommon -Arguments @($script:probeKey) -Body {
                param($probe)
                $script:VsRegistryPath = $probe
                Get-VsConfig
            }
            $cfg.WebApi | Should -Be 'virtusphere.lan:8021'
            $cfg.Scheme | Should -Be 'http'
            $cfg.DeviceSyncInterval | Should -Be 10
            $cfg.PackagesSyncInterval | Should -Be 60
        }
    }

    Describe 'Client-Adresskette (Registry-Override und Schema)' {

        BeforeEach {
            $script:probeBase = 'HKCU:\Software\_vs_errorpaths_client_' + [guid]::NewGuid().ToString('N')
            New-Item -Path $script:probeBase -Force | Out-Null
        }
        AfterEach {
            Remove-Item -Path $script:probeBase -Recurse -Force -ErrorAction SilentlyContinue
        }

        It 'probiert den Registry-Override zuerst, dann den DNS-Default' {
            New-ItemProperty -Path $script:probeBase -Name 'WebAPI' -Value 'override.lan:9999' -PropertyType String -Force | Out-Null
            $candidates = Invoke-InFileScope -Path $script:ClientCommon -Arguments @($script:probeBase) -Body {
                param($probe)
                $script:VsRegistryBase = $probe
                Get-VsApiCandidates
            }
            @($candidates)[0] | Should -Be 'override.lan:9999'
            @($candidates) | Should -Contain 'virtusphere.lan:8021'
        }

        It 'ueberspringt einen leeren Override (verlorener Registry-Wert)' {
            New-ItemProperty -Path $script:probeBase -Name 'WebAPI' -Value '   ' -PropertyType String -Force | Out-Null
            $candidates = Invoke-InFileScope -Path $script:ClientCommon -Arguments @($script:probeBase) -Body {
                param($probe)
                $script:VsRegistryBase = $probe
                Get-VsApiCandidates
            }
            @($candidates)[0] | Should -Be 'virtusphere.lan:8021'
        }

        It 'akzeptiert https aus der Registry, ignoriert Muell (<value>)' -ForEach @(
            @{ value = 'https'; expected = 'https' }
            @{ value = 'ftp';   expected = 'http' }
            @{ value = 'HTTPS'; expected = 'https' }
        ) {
            # http/https werden case-insensitiv akzeptiert und kanonisch klein
            # zurueckgegeben; alles andere faellt auf den sicheren Default
            # zurueck, statt eine kaputte URL zu bauen.
            New-ItemProperty -Path $script:probeBase -Name 'Scheme' -Value $value -PropertyType String -Force | Out-Null
            Invoke-InFileScope -Path $script:ClientCommon -Arguments @($script:probeBase) -Body {
                param($probe)
                $script:VsRegistryBase = $probe
                Get-VsApiScheme
            } | Should -Be $expected
        }

        It 'baut die URL aus Schema, Adresse und Pfad zusammen' {
            Invoke-InFileScope -Path $script:ClientCommon -Arguments @($script:probeBase) -Body {
                param($probe)
                $script:VsRegistryBase = $probe
                Get-VsApiUrl -Api 'virtusphere.lan:8021' -Path '/mecm_report.php?action=reportPhase'
            } | Should -Be 'http://virtusphere.lan:8021/mecm_report.php?action=reportPhase'
        }
    }
}

# Eine gruene Client-Phase muss heissen, dass die Phase ihre Arbeit wirklich
# erledigt hat. Drei Skripte meldeten Erfolg fuer null geleistete Arbeit, und die
# MECM-Erkennung bestaetigte es jedes Mal:
#
#  - Set-VMDisksOnline rief vier Storage-Cmdlets ohne -ErrorAction Stop, also
#    feuerte der catch nie und der Exitcode blieb 0,
#  - client_staticip wertete "null passende Adapter" als Erfolg, weil $failed 0 war,
#  - die Paketvorlage schrieb ihren Erkennungswert auch bei leerem Skriptordner.
#
# In allen drei Faellen war die Erkennung erfuellt, die Phase gruen, und die VM
# funktionierte nicht.
Describe 'Client-Skripte melden keinen Erfolg fuer nicht geleistete Arbeit' {
    BeforeAll {
        $script:ClientsDir = Join-Path $script:PsRoot 'clients'
        function Get-ClientText {
            param([string]$Name)
            Get-Content -Path (Join-Path $script:ClientsDir $Name) -Raw
        }
    }

    It 'Set-VMDisksOnline ruft kein Storage-Cmdlet ohne -ErrorAction Stop' {
        # Die Storage-Cmdlets melden ihre Fehler per Default nicht-terminierend.
        # Ohne -ErrorAction Stop laeuft das Skript nach einem Fehler weiter,
        # $exitCode bleibt 0 und die Phase meldet "finished".
        # Stringliterale vorher entfernen: eine Fehlermeldung, die den Cmdlet-Namen
        # nennt ("... nach Set-Disk weiterhin offline"), ist kein Aufruf, und ein
        # Test, der darauf anspringt, prueft seinen eigenen Suchausdruck.
        $text = (Get-ClientText -Name 'Set-VMDisksOnline.ps1') -replace '"[^"\r\n]*"', '""' -replace "'[^'\r\n]*'", "''"
        foreach ($cmdlet in @('Get-Disk', 'Set-Disk', 'Initialize-Disk', 'New-Partition', 'Format-Volume', 'Get-Volume')) {
            # (?![\w-]) sonst matcht "Set-Disk" die skripteigene Hilfsfunktion
            # Set-DiskStatus, und der Test prueft seinen eigenen Suchfehler.
            $calls = [regex]::Matches($text, [regex]::Escape($cmdlet) + '(?![\w-])[^\r\n|]*')
            @($calls).Count | Should -BeGreaterThan 0 -Because "$cmdlet muss vorkommen, sonst prueft dieser Test nichts"
            foreach ($call in $calls) {
                # -ErrorAction Stop steht im selben Aufruf ODER die Zeile ist ein
                # bewusst tolerierter Lesezugriff mit SilentlyContinue.
                $line = $call.Value
                ($line -match '-ErrorAction (Stop|SilentlyContinue)') | Should -BeTrue -Because "unklassifizierter Aufruf: $line"
            }
        }
    }

    It 'Set-VMDisksOnline prueft nach dem Schalten nach' {
        # Ein Aufruf kann ohne Fehler zurueckkommen und der Datentraeger trotzdem
        # offline bleiben (Richtlinie, Wechselmedium). Der Erfolgspfad braucht
        # deshalb eine Verifikation, keine Annahme.
        $text = Get-ClientText -Name 'Set-VMDisksOnline.ps1'
        $text | Should -Match 'weiterhin offline'
        $text | Should -Match 'Formatierung unvollstaendig'
    }

    It 'client_staticip wertet null konfigurierte Adapter als Fehlschlag' {
        $text = Get-ClientText -Name 'client_staticip.ps1'
        $text | Should -Match '\$success = \(\$failed -eq 0 -and \$applied -gt 0\)'
        $text | Should -Match 'no matching adapter'
    }

    It 'client_staticip prueft die gesetzte Adresse nach' {
        $text = Get-ClientText -Name 'client_staticip.ps1'
        $text | Should -Match 'Get-NetIPAddress -InterfaceIndex'
        $text | Should -Match 'liegt nach dem Setzen nicht auf der Schnittstelle'
    }

    It 'client_staticip setzt genau eine Standardroute pro VM' {
        # Zwei Default-Gateways sind kein Ausfall, aber eine Wette darauf, welche
        # Schnittstelle Windows nach Metrik waehlt.
        $text = Get-ClientText -Name 'client_staticip.ps1'
        $text | Should -Match '\$gatewaySet'
        $text | Should -Match 'schon eine Standardroute'
    }

    It 'client_getinfo raeumt den Erfolgs-Marker vor jeder Abbruchmoeglichkeit weg' {
        # Der Stale-Fix muss VOR dem API-Aufruf laufen: sonst ueberlebt ein
        # SetupState=complete des Vorlaufs einen Abbruch, und client_staticip
        # arbeitet mit den Interfaces der vorigen VM.
        $text = Get-ClientText -Name 'client_getinfo.ps1'
        $text | Should -Match "(?s)Remove-ItemProperty -Path \`$registryBase -Name 'SetupState'.*Resolve-VsApi"
    }

    It 'client_getinfo bestaetigt Client-Ready explizit und macht einen fehlgeschlagenen ACK wiederholbar' {
        # Das GET liefert nur Konfiguration. Nach vollstaendig geschriebenen
        # Nutzdaten bestaetigt der Client 5/5 per POST und setzt erst danach den
        # MECM-Marker; so hinterlaesst auch ein harter Abbruch im POST kein gruen.
        $text = Get-ClientText -Name 'client_getinfo.ps1'
        $text | Should -Match "(?s)Confirm-VsClientReady -Api \`$api -Mac \`$usedMac.*Save-VsValue -Path \`$registryBase -Name 'SetupState' -Value 'complete'.*Send-VsPhase -Mac \`$usedMac -Phase 'getinfo' -PhaseEvent 'finished'"
        $text | Should -Match "(?s)catch \{.*Remove-ItemProperty -Path \`$registryBase -Name 'SetupState'.*PhaseEvent 'failed'"
    }

    It 'der zwingende Client-Ready-ACK benutzt auch im HTTP-Modus den gemeinsamen URL-Bauer' {
        $text = Get-ClientText -Name 'VirtuSphere-Client-Common.ps1'
        $text | Should -Match "function Confirm-VsClientReady"
        $text | Should -Match "(?s)Confirm-VsClientReady.*Get-VsApiUrl -Api \`$Api -Path '/mecm_client_ack.php'.*-Method Post"

        Invoke-InFileScope -Path $script:ClientCommon -Body {
            $script:VsDefaultScheme = 'http'
            Mock Get-ItemProperty { throw 'kein Override' }
            (Get-VsApiUrl -Api 'virtusphere.lan:8021' -Path '/mecm_client_ack.php') |
                Should -Be 'http://virtusphere.lan:8021/mecm_client_ack.php'
        }
    }

    It 'kein ausgeliefertes PowerShell-Skript ruft die pensionierte MissionName-Action auf' {
        $offenders = @(Get-ChildItem -Path $script:PsRoot -Filter '*.ps1' -Recurse |
            Where-Object { (Get-Content -Path $_.FullName -Raw) -match 'action=getMissionName' } |
            Select-Object -ExpandProperty FullName)
        $offenders | Should -BeNullOrEmpty
    }

    It 'die Paketvorlage schreibt den Erkennungswert nicht bei leerem Skriptordner' {
        # Sonst meldet MECM das Paket als installiert, obwohl nichts installiert
        # wurde, und versucht es nie erneut.
        $text = Get-Content -Path (Join-Path $script:PsRoot 'Package_Vorlage\install.ps1') -Raw
        $text | Should -Match "(?s)Keine Skripte im Ordner.*\`$Fullsuccess = \`$false"
    }
}
