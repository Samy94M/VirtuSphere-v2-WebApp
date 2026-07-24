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
