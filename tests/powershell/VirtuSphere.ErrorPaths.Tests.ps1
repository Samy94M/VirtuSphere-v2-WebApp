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

Describe 'Invoke-VsApi serialisiert eine Liste immer als JSON-Array' {

    # Gegenstueck zur Empfangsrichtung eine Describe weiter unten: PS 5.1 packt
    # ein einelementiges Array auch auf dem WEG NACH DRAUSSEN aus, sobald es per
    # Pipeline in ConvertTo-Json geht. Der Packages-Sync sendet als einziger
    # Aufrufer eine Liste; bei genau einem Katalogeintrag antwortet
    # mecm_packages.php dann 400, der Hash wird nicht gemerkt, und der Fehler
    # wiederholt sich jede Minute - gemeldet als mecm_unavailable.
    BeforeAll {
        function Get-SentBody {
            param([object]$Body)
            # @(,$Body): ohne das Komma zerlegt @() die Liste in ihre Eintraege
            # und der Splat im Kindscope bindet eine Hashtable als benannte
            # Parameter statt als Wert.
            Invoke-InFileScope -Path $script:MecmCommon -Arguments @(, $Body) -Body {
                param($b)
                $script:captured = $null
                function Invoke-RestMethod {
                    param($Uri, $Method, $TimeoutSec, $Headers, $Body, $ContentType)
                    $script:captured = [string]$Body
                }
                $cfg = [pscustomobject]@{ WebApi = 'host:1'; Scheme = 'http'; ReportToken = '' }
                Invoke-VsApi -Config $cfg -Path '/p' -Method POST -Body $b | Out-Null
                $script:captured
            }
        }
    }

    It 'sendet <count> Eintraege als Array' -ForEach @(
        @{ count = 0 }
        @{ count = 1 }
        @{ count = 2 }
    ) {
        $list = New-Object System.Collections.Generic.List[object]
        for ($i = 0; $i -lt $count; $i++) { $list.Add(@{ type = 'Package'; name = ('P{0}' -f $i) }) }
        (Get-SentBody -Body $list).TrimStart() | Should -Match '^\['
    }

    It 'laesst eine Hashtable ein Objekt bleiben' {
        # Der Device-Sync sendet Hashtables. Der Fix darf sie nicht umdrehen.
        (Get-SentBody -Body @{ deviceid = 1 }).TrimStart() | Should -Match '^\{'
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

Describe 'Jede powershell.exe-Aufrufstelle laeuft ohne Profil und nicht interaktiv' {

    # Alles, was VirtuSphere startet, laeuft als SYSTEM. Ein maschinenweites
    # Profil (AllUsersAllHosts) ist damit Fremdcode im Sync- bzw.
    # Installationsprozess und kann Kodierung, PSModulePath oder
    # $ErrorActionPreference setzen; ohne -NonInteractive kann eine Rueckfrage
    # die Bereitstellung haengen lassen, bis MECM sie abbricht. Die
    # Aufgaben-Zeile im Installer begruendete das in fuenf Kommentarzeilen,
    # waehrend drei andere Aufrufstellen beides nicht setzten.
    BeforeAll {
        # Ausnahmen: hier benannt, mit Grund, damit die Liste nicht
        # stillschweigend waechst. Heute leer.
        $script:NoProfileExempt = @{}

        function Remove-PsComments {
            param([string]$Path)
            $tokens = $null; $errors = $null
            $null = [System.Management.Automation.Language.Parser]::ParseFile($Path, [ref]$tokens, [ref]$errors)
            $chars = (Get-Content -Path $Path -Raw).ToCharArray()
            foreach ($t in $tokens) {
                if ([string]$t.Kind -ne 'Comment') { continue }
                for ($i = $t.Extent.StartOffset; $i -lt $t.Extent.EndOffset -and $i -lt $chars.Length; $i++) {
                    if ($chars[$i] -ne "`n" -and $chars[$i] -ne "`r") { $chars[$i] = ' ' }
                }
            }
            -join $chars
        }
    }

    It 'Common fuehrt die Schalter als SSoT' {
        $switches = [string](Invoke-InFileScope -Path $script:MecmCommon -Body { $script:VsPowerShellArgs })
        $switches | Should -Match '(?i)-NoProfile'
        $switches | Should -Match '(?i)-NonInteractive'
        $switches | Should -Match '(?i)-ExecutionPolicy Bypass'

        # Der Helfer setzt den Pfad in Anfuehrungszeichen: der Installationspfad
        # ist C:\Program Files\VirtuSphere\mecm und enthaelt ein Leerzeichen.
        $line = [string](Invoke-InFileScope -Path $script:MecmCommon -Body {
            Get-VsPowerShellCommandLine -ScriptPath 'C:\Program Files\x\y.ps1'
        })
        $line | Should -Match '(?i)^powershell\.exe .*-NoProfile'
        $line | Should -Match '-File "C:\\Program Files\\x\\y\.ps1"'
    }

    It 'keine Aufrufstelle im Baum startet powershell.exe mit Profil' {
        # Eine Zeile, die $script:VsPowerShellArgs einsetzt, traegt die Schalter
        # per Konstruktion; was in der Konstante steht, pinnt das It darueber.
        # Die Paketvorlage fuehrt ihr eigenes Literal, weil sie einzeln in den
        # Paketordner kopiert wird und Common nie sieht.
        $offenders = @()
        $seen = 0
        foreach ($file in @(Get-ChildItem -Path $script:PsRoot -Filter '*.ps1' -Recurse -File)) {
            if ($script:NoProfileExempt.ContainsKey($file.Name)) { continue }
            foreach ($line in ((Remove-PsComments -Path $file.FullName) -split "`r?`n")) {
                if ($line -notmatch '(?i)powershell\.exe') { continue }
                $seen++
                if ($line -match '(?i)-NoProfile' -and $line -match '(?i)-NonInteractive') { continue }
                if ($line -match 'VsPowerShellArgs' -or $line -match 'Get-VsPowerShellCommandLine') { continue }
                $offenders += ('{0}: {1}' -f $file.Name, $line.Trim())
            }
        }
        # Ohne diese Zeile waere der Test nach einer Umbenennung dauerhaft still
        # gruen - genau der Fehler, den dieses Projekt in der i18n-Regel schon
        # einmal hatte.
        $seen | Should -BeGreaterThan 0 -Because 'ohne Fundstellen prueft dieser Test nichts'
        $offenders -join ' | ' | Should -BeNullOrEmpty
    }

    It 'die Paketvorlage nennt dieselben Schalter wie die Konstante' {
        $switches = [string](Invoke-InFileScope -Path $script:MecmCommon -Body { $script:VsPowerShellArgs })
        $template = Remove-PsComments -Path (Join-Path (Join-Path $script:PsRoot 'Package_Vorlage') 'install.ps1')
        foreach ($switch in ($switches -split '\s+(?=-)')) {
            $template | Should -Match ('(?i)' + [regex]::Escape($switch.Trim()))
        }
    }
}

Describe 'Installer melden keinen Erfolg fuer nicht geleistete Arbeit' {

    # Beide Installer schrieben ihre gruene Schlusszeile, ohne auf das Ergebnis
    # zu schauen: der Clients-Installer unbedingt (jeder App-Fehler war nur eine
    # Warnung), der MECM-Installer allein an $allRunning, also an der Frage, ob
    # die vier Aufgaben laufen. Vier laufende Aufgaben plus ein Portal, das mit
    # 403 antwortet, ergaben eine gruene Erstinstallation - und genau dieser 403
    # ist der Naechste-Schritte-Punkt 1 desselben Skripts.
    #
    # Blindes Mitzaehlen ALLER Warnungen waere die falsche Korrektur gewesen:
    # ein korrekter Erstlauf ginge gelb, weil die DP-Gruppe legitim erst spaeter
    # entsteht. Deshalb zwei Ausgabefunktionen, deren Wahl an der Aufrufstelle
    # steht und hier geprueft wird.
    BeforeAll {
        $script:InstallerFiles = @(
            @{ name = 'install-VirtuSphere-MECM.ps1';    path = (Join-Path $script:PsRoot 'install-VirtuSphere-MECM.ps1');    success = 'Erstinstallation abgeschlossen' }
            @{ name = 'install-VirtuSphere-Clients.ps1'; path = (Join-Path $script:PsRoot 'install-VirtuSphere-Clients.ps1'); success = 'Client-Applikationen bereit' }
        )

        function Get-InstallerAst {
            param([string]$Path)
            $t = $null; $e = $null
            [System.Management.Automation.Language.Parser]::ParseFile($Path, [ref]$t, [ref]$e)
        }
    }

    It '<name>: die Erfolgszeile haengt an einer Bedingung, die den Blockerzaehler liest' -ForEach @(
        @{ name = 'install-VirtuSphere-MECM.ps1';    success = 'Erstinstallation abgeschlossen' }
        @{ name = 'install-VirtuSphere-Clients.ps1'; success = 'Client-Applikationen bereit' }
    ) {
        $ast = Get-InstallerAst -Path (Join-Path $script:PsRoot $name)
        $ifs = @($ast.FindAll({
            param($n) $n -is [System.Management.Automation.Language.IfStatementAst]
        }, $true) | Where-Object { $_.Extent.Text -match [regex]::Escape($success) } |
            Sort-Object { $_.Extent.Text.Length })
        $ifs.Count | Should -BeGreaterThan 0 -Because 'sonst prueft dieser Test die falsche Stelle'
        $ifs[0].Clauses[0].Item1.Extent.Text | Should -Match 'VsInstallBlockers'
    }

    It '<name>: kann mit einem Fehlercode enden' -ForEach @(
        @{ name = 'install-VirtuSphere-MECM.ps1' }
        @{ name = 'install-VirtuSphere-Clients.ps1' }
    ) {
        # Der Exit-Code ist die maschinenlesbare Fassung der Schlusszeile und
        # Voraussetzung dafuer, dass ein Rollout-Skript den Installer je pruefen
        # kann. Beide endeten immer mit 0.
        $ast = Get-InstallerAst -Path (Join-Path $script:PsRoot $name)
        $exits = @($ast.FindAll({
            param($n) $n -is [System.Management.Automation.Language.ExitStatementAst]
        }, $true) | Where-Object { $_.Extent.Text -notmatch '^exit\s+0\s*$' })
        $exits.Count | Should -BeGreaterThan 0
    }

    It '<name>: nur Write-Warn zaehlt, Write-Hint nicht' -ForEach @(
        @{ name = 'install-VirtuSphere-MECM.ps1' }
        @{ name = 'install-VirtuSphere-Clients.ps1' }
    ) {
        # Die Trennung ist der ganze Wert dieser Etappe: waere sie in einer
        # zentralen Liste versteckt, muesste man beim Lesen einer Warnung
        # anderswo nachschlagen, und ein neuer Warnungstext koennte sich
        # unbemerkt in die falsche Klasse legen.
        $ast = Get-InstallerAst -Path (Join-Path $script:PsRoot $name)
        $funcs = @{}
        foreach ($f in @($ast.FindAll({
            param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst]
        }, $true))) { $funcs[$f.Name] = $f }

        $funcs.ContainsKey('Write-Warn') | Should -BeTrue
        $funcs.ContainsKey('Write-Hint') | Should -BeTrue
        $funcs['Write-Warn'].Body.Extent.Text | Should -Match '\$script:VsInstallBlockers\+\+'
        $funcs['Write-Hint'].Body.Extent.Text | Should -Not -Match 'VsInstallBlockers'

        # Beide Klassen muessen tatsaechlich benutzt werden. Ohne diese Pruefung
        # waere eine Datei, in der jemand alle Hinweise wieder zu Blockern macht,
        # still gruen.
        foreach ($fn in @('Write-Warn', 'Write-Hint')) {
            $calls = @($ast.FindAll({
                param($n) $n -is [System.Management.Automation.Language.CommandAst]
            }, $true) | Where-Object { $_.GetCommandName() -eq $fn })
            $calls.Count | Should -BeGreaterThan 0 -Because "$fn ohne Aufrufstelle ist eine Klassifizierung, die niemand trifft"
        }
    }

    It '<name>: schreibt seine Blocker auch ins Tageslog' -ForEach @(
        @{ name = 'install-VirtuSphere-MECM.ps1' }
        @{ name = 'install-VirtuSphere-Clients.ps1' }
    ) {
        # Das Konsolenfenster ueberlebt den Feierabend nicht, und eine
        # Erstinbetriebnahme wird oft erst am naechsten Tag nachvollzogen.
        $text = Get-Content -Raw -Path (Join-Path $script:PsRoot $name)
        $text | Should -Match 'Initialize-VsLog'
        $ast = Get-InstallerAst -Path (Join-Path $script:PsRoot $name)
        $warn = @($ast.FindAll({
            param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'Write-Warn'
        }, $true))
        $warn[0].Body.Extent.Text | Should -Match 'Write-VsLog'
    }
}

# ===========================================================================
# Die drei systemischen Waechter der Haertungskampagne 2026-08
# ---------------------------------------------------------------------------
# Alle 30 Befunde der Einzelpruefung vom 2026-08-09 stammen aus genau drei
# Mustern, und jedes davon ist eine Klasse, keine Einzelstelle:
#
#   1. Ein Fehlerpfad ohne Zaehler. Der Fehler wird protokolliert, erreicht aber
#      den Run-Report nicht, also meldet der Lauf `ok`.
#   2. Eine gelesene Variable, die niemand zuweist. Unter Set-StrictMode wirft
#      das, und der Wurf landet im aeusseren Catch: eine Datenlage wird als
#      Infrastrukturausfall gemeldet.
#   3. Ein Erfolgssatz ohne Bedingung. Die Schlusszeile schaut nicht auf das
#      Ergebnis.
#
# Die Einzelfaelle sind behoben; diese drei Tests fangen den naechsten am Build
# statt in Produktion. Sie stehen bewusst hier und nicht im Register: das
# Register war die To-do-Liste der Kampagne, diese hier sind Vertrag.
# ===========================================================================

Describe 'Waechter 1: kein Catch ohne Konsequenz' {

    BeforeAll {
        # Die vier Endlosschleifen. Ein Fehler, den hier niemand zaehlt, ist als
        # SYSTEM unsichtbar: kein Fenster, kein Anwender, nur die Ampel im
        # Systemstatus, die dann faelschlich gruen bleibt.
        $script:LoopScripts = @(
            'mecm_new-device-sync.ps1'
            'mecm_Packages-TaskSeq-sync.ps1'
            'mecm_autoimporter.ps1'
            'mecm_site-health.ps1'
        )

        # Benannte Ausnahmen, jede mit ihrem Grund. Die Liste ist der ehrliche
        # Preis dieses Waechters und darf nicht stillschweigend wachsen: sie ist
        # nach Datei UND Startzeile geschluesselt, damit ein zweiter tolerierter
        # Catch in derselben Datei nicht mitgedeckt wird.
        $script:CatchExempt = @{
            'mecm_new-device-sync.ps1|Auto-Approve nicht moeglich' =
                'Auto-Approve ist best effort: die Genehmigung kann bereits gesetzt sein oder in dieser Site gar nicht verlangt werden. Ein Fehlschlag hindert den Import nicht, und die Zuweisung danach zaehlt ihren eigenen.'
        }
    }

    It '<name>: jeder Catch zaehlt, wirft oder meldet das Ergebnis' -ForEach @(
        @{ name = 'mecm_new-device-sync.ps1' }
        @{ name = 'mecm_Packages-TaskSeq-sync.ps1' }
        @{ name = 'mecm_autoimporter.ps1' }
        @{ name = 'mecm_site-health.ps1' }
    ) {
        $path = Join-Path (Join-Path $script:PsRoot 'mecm') $name
        $ast = [System.Management.Automation.Language.Parser]::ParseFile($path, [ref]$null, [ref]$null)

        $catches = @($ast.FindAll({
            param($n) $n -is [System.Management.Automation.Language.CatchClauseAst]
        }, $true))
        $catches.Count | Should -BeGreaterThan 0 -Because 'ohne Fundstellen prueft dieser Test nichts'

        $offenders = @()
        foreach ($catch in $catches) {
            $body = $catch.Body.Extent.Text

            # (a) Ein Zaehler, der den Run-Report erreicht.
            if ($body -match '\+\+') { continue }
            # (b) Weiterwerfen: der naechste Rahmen entscheidet.
            if ($body -match '\bthrow\b') { continue }
            # (c) Das Ergebnis des Laufs selbst setzen (die aeusseren Catches).
            if ($body -match "\`$outcome\s*=\s*'(fail|warning)'") { continue }
            # (d) Den Fehlschlag an den Aufrufer zurueckgeben: eine Variable
            #     setzen, die die umschliessende Funktion auch zurueckliefert.
            #     So macht es New-VsDeviceCollection mit FolderFailed, und der
            #     Aufrufer zaehlt.
            $fn = $catch.Parent
            while ($fn -and -not ($fn -is [System.Management.Automation.Language.FunctionDefinitionAst])) { $fn = $fn.Parent }
            if ($fn) {
                $returned = ($fn.Body.FindAll({
                    param($n) $n -is [System.Management.Automation.Language.ReturnStatementAst]
                }, $true) | ForEach-Object { $_.Extent.Text }) -join ' '
                $assignedHere = @($catch.Body.FindAll({
                    param($n) $n -is [System.Management.Automation.Language.AssignmentStatementAst]
                }, $true) | ForEach-Object { $_.Left.Extent.Text })
                $reaches = $false
                foreach ($v in $assignedHere) {
                    if ($v -and $returned -match [regex]::Escape($v.TrimStart('$'))) { $reaches = $true }
                }
                if ($reaches) { continue }
            }

            # (e) Benannte Ausnahme mit Grund.
            $exempt = $false
            foreach ($key in $script:CatchExempt.Keys) {
                $parts = $key -split '\|', 2
                if ($parts[0] -eq $name -and $body -match [regex]::Escape($parts[1])) { $exempt = $true }
            }
            if ($exempt) { continue }

            $offenders += ('{0}:{1} {2}' -f $name, $catch.Extent.StartLineNumber, (($body -replace '\s+', ' ')))
        }

        $offenders -join ' || ' | Should -BeNullOrEmpty
    }

    It 'die Ausnahmeliste zeigt auf keinen Catch, den es nicht mehr gibt' {
        # Andersherum gelesen: eine Ausnahme, die ins Leere zeigt, deckt kuenftig
        # den falschen Fall. Genau der Fehler, an dem in diesem Projekt schon
        # einmal ein Spec vier Kataloge verloren hat, ohne rot zu werden.
        foreach ($key in $script:CatchExempt.Keys) {
            $parts = $key -split '\|', 2
            $path = Join-Path (Join-Path $script:PsRoot 'mecm') $parts[0]
            Test-Path $path | Should -BeTrue -Because "$($parts[0]) existiert nicht mehr"
            (Get-Content -Raw -Path $path) | Should -Match ([regex]::Escape($parts[1]))
            [string]$script:CatchExempt[$key] | Should -Not -BeNullOrEmpty -Because 'eine Ausnahme ohne Grund ist keine Entscheidung'
        }
    }
}

Describe 'Waechter 2: keine gelesene Variable ohne Zuweisung' {

    It '<name>: liest keine Variable, die das Skript nie zuweist' -ForEach @(
        @{ name = 'mecm_new-device-sync.ps1' }
        @{ name = 'mecm_Packages-TaskSeq-sync.ps1' }
        @{ name = 'mecm_autoimporter.ps1' }
        @{ name = 'mecm_site-health.ps1' }
    ) {
        # Set-StrictMode 1.0 wirft beim Lesen einer nicht gesetzten Variablen.
        # In einer Endlosschleife als SYSTEM landet der Wurf im aeusseren Catch:
        # $targets.Count stand ausgerechnet in dem Zweig, der eine unvollstaendige
        # Zuweisung melden wollte, und machte daraus einen Scan-Abbruch samt
        # weggeworfenem Site-Drive - eine Datenlage, gemeldet als
        # Infrastrukturausfall.
        $path = Join-Path (Join-Path $script:PsRoot 'mecm') $name
        $ast = [System.Management.Automation.Language.Parser]::ParseFile($path, [ref]$null, [ref]$null)

        $assigned = @{}
        foreach ($a in @($ast.FindAll({
            param($n) $n -is [System.Management.Automation.Language.AssignmentStatementAst]
        }, $true))) {
            $left = $a.Left
            if ($left -is [System.Management.Automation.Language.ConvertExpressionAst]) { $left = $left.Child }
            if ($left -is [System.Management.Automation.Language.VariableExpressionAst]) {
                $assigned[$left.VariablePath.UserPath.ToLowerInvariant()] = $true
            }
        }
        foreach ($f in @($ast.FindAll({
            param($n) $n -is [System.Management.Automation.Language.ForEachStatementAst]
        }, $true))) { $assigned[$f.Variable.VariablePath.UserPath.ToLowerInvariant()] = $true }
        foreach ($p in @($ast.FindAll({
            param($n) $n -is [System.Management.Automation.Language.ParameterAst]
        }, $true))) { $assigned[$p.Name.VariablePath.UserPath.ToLowerInvariant()] = $true }

        # Automatik-Variablen und alles mit Namensraum ($env:, $script:) sind
        # keine Zuweisung dieses Skripts. Die Liste steht ausgeschrieben, damit
        # sie nicht stillschweigend waechst.
        $allow = @('_', 'psitem', 'psscriptroot', 'pscmdlet', 'psboundparameters',
                   'true', 'false', 'null', 'args', 'error', 'lastexitcode',
                   'matches', 'host', 'pwd', 'input', 'foreach', 'switch')

        $seen = 0
        $unassigned = @()
        foreach ($v in @($ast.FindAll({
            param($n) $n -is [System.Management.Automation.Language.VariableExpressionAst]
        }, $true))) {
            $varName = $v.VariablePath.UserPath
            if ($varName -match ':') { continue }
            $seen++
            $lower = $varName.ToLowerInvariant()
            if ($allow -contains $lower) { continue }
            if (-not $assigned.ContainsKey($lower)) { $unassigned += $varName }
        }
        $seen | Should -BeGreaterThan 0 -Because 'ohne Fundstellen prueft dieser Test nichts'

        @($unassigned | Select-Object -Unique) -join ', ' | Should -BeNullOrEmpty
    }
}

Describe 'Waechter 3: kein unbedingter Erfolgssatz' {

    It '<name>: die abschliessende Erfolgsmeldung haengt an einer Bedingung' -ForEach @(
        @{ name = 'install-VirtuSphere-MECM.ps1';    success = 'Erstinstallation abgeschlossen' }
        @{ name = 'install-VirtuSphere-Clients.ps1'; success = 'Client-Applikationen bereit' }
    ) {
        # Dieselbe Frage wie in der Installer-Describe oben, hier aber als
        # Klasse formuliert: die Erfolgsausgabe muss ueberhaupt in einem
        # IfStatementAst liegen. Ein neuer Installer, der seine Schlusszeile
        # unbedingt schreibt, faellt damit auf, auch wenn er den Blockerzaehler
        # nie kennengelernt hat.
        $path = Join-Path $script:PsRoot $name
        $ast = [System.Management.Automation.Language.Parser]::ParseFile($path, [ref]$null, [ref]$null)

        $calls = @($ast.FindAll({
            param($n) $n -is [System.Management.Automation.Language.CommandAst]
        }, $true) | Where-Object { $_.Extent.Text -match [regex]::Escape($success) })
        $calls.Count | Should -BeGreaterThan 0 -Because 'ohne Fundstelle prueft dieser Test nichts'

        foreach ($call in $calls) {
            $p = $call.Parent
            $inIf = $false
            while ($p) {
                if ($p -is [System.Management.Automation.Language.IfStatementAst]) { $inIf = $true; break }
                $p = $p.Parent
            }
            $inIf | Should -BeTrue -Because "die Erfolgszeile '$success' steht unbedingt"
        }
    }

    It 'die Paketvorlage schreibt ihren Detection-Wert nur nach vollstaendigem Erfolg' {
        # Die Vorlage laedt keine Bibliothek und hat keinen Blockerzaehler; ihr
        # Aequivalent des Erfolgssatzes ist der Registry-Wert, den MECM als
        # Erkennung liest. Textpruefung, weil die Datei allein ausgeliefert wird.
        $text = Get-Content -Raw -Path (Join-Path (Join-Path $script:PsRoot 'Package_Vorlage') 'install.ps1')
        $text | Should -Match '(?s)if\s*\(\s*\$Fullsuccess\s*\)\s*\{\s*Set-ItemProperty[^\r\n]*Version'
        # Und der Schluss-Exit liest dasselbe Ergebnis, statt immer 0 zu liefern.
        $text | Should -Match '(?s)if \(-not \$Fullsuccess\) \{[^}]*exit 1'
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

    It 'client_staticip stellt eine Karte auch auf DHCP zurueck' {
        # Ein Interface mit Mode != Static durchlief den Block ohne jede Aktion
        # und erhoehte trotzdem $applied: eine Karte, die vorher statisch war und
        # laut Portal jetzt DHCP sein soll, behielt ihre alte Adresse, und der
        # Lauf meldete Erfolg. Derselbe Fehlertyp, gegen den der Kommentar
        # zwanzig Zeilen tiefer ausdruecklich argumentiert.
        $text = Get-ClientText -Name 'client_staticip.ps1'
        $text | Should -Match 'Set-NetIPInterface -InterfaceIndex[^\r\n]*-Dhcp Enabled'
        # DNS zurueck an DHCP: eine haendisch gesetzte Serveradresse ueberlebt
        # die Umstellung sonst und zeigt weiter ins alte VLAN.
        $text | Should -Match 'Set-DnsClientServerAddress[^\r\n]*-ResetServerAddresses'
        # Nachlesen statt annehmen, wie im statischen Zweig.
        $text | Should -Match 'steht nach der Umstellung weiterhin auf Dhcp'
    }

    It 'client_staticip macht einen unbekannten Modus zum Fehlschlag' {
        # Kein stilles $applied++ mehr fuer einen Adapter, an dem nichts
        # geschehen ist.
        $text = Get-ClientText -Name 'client_staticip.ps1'
        $text | Should -Match "unbekannter Modus"

        # $applied darf nur in einem Zweig hochgezaehlt werden, der auch etwas
        # verifiziert hat: kein $applied++ ausserhalb der beiden Modus-Zweige.
        $ast = [System.Management.Automation.Language.Parser]::ParseFile(
            (Join-Path $script:ClientsDir 'client_staticip.ps1'), [ref]$null, [ref]$null)
        $increments = @($ast.FindAll({
            param($n) $n -is [System.Management.Automation.Language.UnaryExpressionAst] -and
                      $n.Child.Extent.Text -eq '$applied'
        }, $true))
        $increments.Count | Should -Be 2 -Because 'genau ein Zweig fuer Static, einer fuer DHCP'
    }

    It 'client_staticip vergleicht dieselben Modi, die das Portal kennt' {
        # Erste Stelle, an der eine PHP-Konstante gegen die PowerShell-Clientseite
        # gepinnt wird. VIRTUSPHERE_INTERFACE_MODES ist KLEIN geschrieben; das
        # Skript trifft die Werte nur, weil -eq case-insensitiv vergleicht. Ein
        # spaeterer Wechsel auf -ceq oder eine dritte Modusart braeche das
        # lautlos.
        $defaults = Get-Content -Raw -Path (Join-Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'Docker') 'WebAPI') 'lib') 'defaults.php')
        $match = [regex]::Match($defaults, "const VIRTUSPHERE_INTERFACE_MODES\s*=\s*\[([^\]]*)\]")
        $match.Success | Should -BeTrue -Because 'ohne Fundstelle prueft dieser Test nichts'

        # 'dhcp' steht dort als benannte Konstante, 'static' als Literal.
        $phpModes = @($match.Groups[1].Value -split ',' | ForEach-Object {
            $token = $_.Trim().Trim("'")
            if ($token -eq 'VIRTUSPHERE_INTERFACE_MODE_DHCP') { 'dhcp' } else { $token }
        } | Where-Object { $_ })
        $phpModes.Count | Should -Be 2

        $text = Get-ClientText -Name 'client_staticip.ps1'
        $psModes = @([regex]::Matches($text, "^\`$mode[A-Za-z]+\s*=\s*'([^']+)'", 'Multiline') |
            ForEach-Object { $_.Groups[1].Value })
        $psModes.Count | Should -Be 2 -Because 'die beiden Modusliterale stehen benannt am Kopf des Skripts'

        foreach ($mode in $psModes) {
            $phpModes | Should -Contain $mode.ToLowerInvariant()
        }
        foreach ($mode in $phpModes) {
            @($psModes | ForEach-Object { $_.ToLowerInvariant() }) | Should -Contain $mode
        }
    }

    It 'client_staticip nennt die Modusverteilung im Detail' {
        # Die Portalkarte zeigte eine Zahl ohne Aussage; "3 Ziele, 2 statisch,
        # 1 DHCP" beantwortet die Frage, die davor stand.
        $text = Get-ClientText -Name 'client_staticip.ps1'
        $text | Should -Match 'static=\{1\} dhcp=\{2\}'
        $text | Should -Match '\$appliedStatic, \$appliedDhcp'
    }

    It '<name> normalisiert jede MAC, bevor sie den Rechner verlaesst' -ForEach @(
        @{ name = 'client_getinfo.ps1' }
        @{ name = 'client_staticip.ps1' }
    ) {
        # Das WMI-Format passt heute zufaellig zu dem, was das Portal speichert.
        # ConvertTo-VsNormalizedMac ist die gemeinsame Wahrheit (mac-vectors,
        # drei Implementierungen) und macht aus dem Zufall eine Zusage; ohne sie
        # sind zwei Schreibweisen desselben Adapters fuer das Portal zwei
        # Adapter, und reportPhase authentisiert ueber genau diesen Wert.
        $text = Get-ClientText -Name $name
        $text | Should -Match 'ConvertTo-VsNormalizedMac'
        # Kein roher MACAddress-Wert, der ohne Normalisierung weitergereicht wird.
        $text | Should -Not -Match '(?m)^\s*\$macs\s*=\s*@\(Get-CimInstance[^\r\n]*MACAddress\)\s*$'
    }

    It 'Get-VsReportMac normalisiert auch die Fallback-MAC' {
        # Der Registry-Wert ist normalisiert (client_getinfo schreibt ihn so),
        # der WMI-Fallback war es nicht: derselbe Adapter kam je nach Pfad in
        # zwei Schreibweisen beim Portal an.
        $ast = [System.Management.Automation.Language.Parser]::ParseFile($script:ClientCommon, [ref]$null, [ref]$null)
        $fn = @($ast.FindAll({
            param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'Get-VsReportMac'
        }, $true))
        $fn.Count | Should -Be 1 -Because 'sonst prueft dieser Test die falsche Stelle'
        $fn[0].Body.Extent.Text | Should -Match 'ConvertTo-VsNormalizedMac'
    }

    It 'der Client-Logpfad kommt aus der Umgebung, nicht aus einem Literal' {
        # Hart auf C:\Program Files verdrahtet, waehrend die Serverseite
        # $env:ProgramFiles benutzt: auf einem System mit verschobenem
        # Programmverzeichnis schrieb der Client neben alles andere.
        $text = Get-ClientText -Name 'VirtuSphere-Client-Common.ps1'
        $text | Should -Match '\$script:VsLogDir\s*=\s*if \(\$env:ProgramFiles\)'
        $text | Should -Not -Match "VsLogDir\s*=\s*'C:\\Program Files"
    }

    It 'client_hostname meldet je Lauf hoechstens ein terminales Phasenereignis' {
        # Der Bereinigungszweig meldete 'failed', das Skript benannte die
        # Maschine trotzdem um, und derselbe Lauf meldete danach 'finished'. Was
        # das Portal anzeigt, hing davon ab, welche Meldung zuletzt ankam.
        #
        # Geprueft wird pro Pfad: zwischen einem terminalen Send-VsPhase und dem
        # naechsten muss das Skript die Ausfuehrung verlassen (exit oder throw).
        $path = Join-Path $script:ClientsDir 'client_hostname.ps1'
        $ast = [System.Management.Automation.Language.Parser]::ParseFile($path, [ref]$null, [ref]$null)

        $terminal = @($ast.FindAll({
            param($n) $n -is [System.Management.Automation.Language.CommandAst] -and
                      $n.GetCommandName() -eq 'Send-VsPhase' -and
                      $n.Extent.Text -match "PhaseEvent '(finished|failed)'"
        }, $true) | Sort-Object { $_.Extent.StartOffset })
        $terminal.Count | Should -BeGreaterThan 1 -Because 'sonst prueft dieser Test die falsche Stelle'

        $source = Get-ClientText -Name 'client_hostname.ps1'
        for ($i = 0; $i -lt $terminal.Count - 1; $i++) {
            $from = $terminal[$i].Extent.EndOffset
            $to = $terminal[$i + 1].Extent.StartOffset
            $between = $source.Substring($from, $to - $from)
            ($between -match '(?m)^\s*(exit\b|throw\b)') | Should -BeTrue -Because (
                "zwischen '{0}' und der naechsten terminalen Meldung verlaesst kein Pfad das Skript" -f $terminal[$i].Extent.Text)
        }
    }

    It 'client_hostname traegt die Namensabweichung im Detail statt in einer zweiten Meldung' {
        # Der Operator soll die verbleibenden Altzeilen finden koennen, ohne dass
        # ein Deployment scheitert: die verbindliche NetBIOS-Pruefung sitzt im
        # Portal, das sie bei jeder neuen VM und jeder Aenderung erzwingt.
        $text = Get-ClientText -Name 'client_hostname.ps1'
        $text | Should -Match '\$sanitizeNote'
        $text | Should -Match "verletzt NetBIOS-Regeln"
        # Und der Bereinigungszweig selbst meldet nichts mehr.
        $branch = [regex]::Match($text, "(?s)if \(\`$sanitized -ne \`$newHostname\) \{.*?\n    \}")
        $branch.Success | Should -BeTrue -Because 'sonst prueft dieser Test die falsche Stelle'
        $branch.Value | Should -Not -Match 'Send-VsPhase'
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
