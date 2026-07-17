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
        # liegen fuer Operatoren lesbar auf dem SCCM-Server.
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
}

Describe 'Backoff-Vertrag der Sync-Endlosschleifen' {

    It '<name> hat eine Endlosschleife und mindestens einen Catch mit Backoff-Sleep' -ForEach @(
        @{ name = 'mecm_new-device-sync.ps1' }
        @{ name = 'mecm_Packages-TaskSeq-sync.ps1' }
        @{ name = 'mecm_autoimporter.ps1' }
    ) {
        # Frueher beendete der erste Fehler den Sync bis zum naechsten Reboot.
        # Der Vertrag: die Schleife laeuft endlos, und ein Fehler fuehrt in
        # einen Catch, der schlaeft statt heiss zu drehen (Backoff).
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

        $catchesWithSleep = $ast.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.CatchClauseAst] -and
            $n.Extent.Text -match 'Start-Sleep'
        }, $true)
        @($catchesWithSleep).Count | Should -BeGreaterThan 0
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
