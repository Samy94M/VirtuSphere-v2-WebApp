# Pester-Suite fuer den Run-Report-Kanal (ADR-0018): die reinen Bausteine in
# VirtuSphere-Common.ps1 (New-VsRunId, Detail-Sanitize/Byte-Kuerzung, Summary-
# Whitelist, Fehlerkategorisierung, Site-Health-Abbildung, Provider-Aufloesung)
# plus das Sende-Verhalten von Send-VsRunReport und die Schleifenstruktur der
# vier Report-Skripte.
#
# Die PHP-Seite (Docker/WebAPI/lib/run_report.php, lib/constants.php) ist die
# SSoT des Wire-Contracts; diese Tests pinnen den PowerShell-Spiegel dagegen.
#
# Dot-Source im Kindscope (Invoke-InFileScope), damit sich die Common-Dateien
# nicht gegenseitig ueberschreiben und Stubs (Invoke-VsApi, Get-CimInstance)
# nur im jeweiligen Testscope leben.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $psRoot = Join-Path $script:RepoRoot 'Powershell-MECM'
    $script:MecmCommon = Join-Path (Join-Path $psRoot 'mecm') 'VirtuSphere-Common.ps1'
    $script:PsRoot = $psRoot

    function Invoke-InFileScope {
        param([string]$Path, [scriptblock]$Body, [object[]]$Arguments = @())
        & {
            param($p, $b, $a)
            . $p
            & $b @a
        } $Path $Body $Arguments
    }

    # Quelltext und AST der vier Report-Skripte. Auf Dateiebene, weil zwei
    # Describe-Bloecke sie lesen (Schleifenstruktur und Intervall-Aufloesung).
    function Get-ScriptText {
        param([string]$Name)
        Get-Content -Path (Join-Path (Join-Path $script:PsRoot 'mecm') $Name) -Raw
    }
    function Get-ScriptAst {
        param([string]$Name)
        $tokens = $null; $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile(
            (Join-Path (Join-Path $script:PsRoot 'mecm') $Name), [ref]$tokens, [ref]$errors)
    }

    # Fuehrt Send-VsRunReport mit einem gestubbten Invoke-VsApi aus und liefert
    # den erfassten Body zurueck (die Zustellung wird abgefangen, nicht gesendet).
    function Get-SentBody {
        param([hashtable]$Params)
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($Params) -Body {
            param($p)
            Initialize-VsLog -Component 'pester-runreport' -LogRoot ([System.IO.Path]::GetTempPath())
            $script:captured = $null
            function Invoke-VsApi { param($Config, $Path, $Method, $Body, $TimeoutSec) $script:captured = $Body }
            Send-VsRunReport @p
            $script:captured
        }
    }
}

Describe 'New-VsRunId' {
    It 'liefert 32 kleingeschriebene Hex-Zeichen (VIRTUSPHERE_RUN_ID_PATTERN)' {
        $id = Invoke-InFileScope -Path $script:MecmCommon -Body { New-VsRunId }
        $id | Should -Match '^[0-9a-f]{32}$'
    }

    It 'mintet je Aufruf eine neue Id' {
        $ids = Invoke-InFileScope -Path $script:MecmCommon -Body {
            , @((New-VsRunId), (New-VsRunId), (New-VsRunId))
        }
        ($ids | Select-Object -Unique).Count | Should -Be 3
    }
}

Describe 'Get-VsTruncatedUtf8 (Byte-Kuerzung ohne Zerschneiden)' {
    It 'laesst einen kurzen String unveraendert' {
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            Get-VsTruncatedUtf8 -Text 'kurz' -MaxBytes 100
        } | Should -Be 'kurz'
    }

    It 'kuerzt auf hoechstens <max> Bytes und nie mitten in einem Mehrbyte-Zeichen' -ForEach @(
        @{ max = 101 }   # ae = 2 Bytes: 101 ist ungerade, muss auf 100 zurueck
        @{ max = 50 }
        @{ max = 7 }
    ) {
        $out = Invoke-InFileScope -Path $script:MecmCommon -Arguments @($max) -Body {
            param($m) Get-VsTruncatedUtf8 -Text ('ä' * 500) -MaxBytes $m
        }
        $bytes = [System.Text.Encoding]::UTF8.GetByteCount($out)
        $bytes | Should -BeLessOrEqual $max
        # Kein Ersatzzeichen / kaputte Sequenz: reencodieren ergibt denselben Text.
        [System.Text.Encoding]::UTF8.GetString([System.Text.Encoding]::UTF8.GetBytes($out)) | Should -Be $out
    }
}

Describe 'Get-VsReportDetail (Sanitize + Token-Redaction + Byte-Kuerzung)' {
    It 'streift Steuerzeichen und trimmt' {
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            Get-VsReportDetail -Text "  a`tb`r`nc  " -Token ''
        } | Should -Be 'a b c'
    }

    It 'redigiert den Rueckkanal-Token, falls er im Text auftaucht' {
        $out = Invoke-InFileScope -Path $script:MecmCommon -Body {
            Get-VsReportDetail -Text 'prefix SECRETTOKEN suffix' -Token 'SECRETTOKEN'
        }
        $out | Should -Not -Match 'SECRETTOKEN'
        $out | Should -Match '\[redacted\]'
    }

    It 'liefert $null fuer leeren oder reinen Whitespace-Text' -ForEach @(
        @{ text = '' }
        @{ text = '    ' }
        @{ text = $null }
    ) {
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($text) -Body {
            param($t) Get-VsReportDetail -Text $t -Token ''
        } | Should -BeNullOrEmpty
    }

    It 'kuerzt langen Detailtext in Bytes' {
        $out = Invoke-InFileScope -Path $script:MecmCommon -Body {
            Get-VsReportDetail -Text ('ü' * 5000) -Token ''
        }
        [System.Text.Encoding]::UTF8.GetByteCount($out) | Should -BeLessOrEqual 2048
    }
}

Describe 'New-VsRunSummary (Whitelist je Quelle)' {
    It 'device-sync: behaelt nur die erlaubten Zaehler, verwirft Unbekanntes' {
        $sum = Invoke-InFileScope -Path $script:MecmCommon -Body {
            New-VsRunSummary -Source 'device-sync' -Values @{ received = 5; imported = 2; bogus = 9; provider = 'x' }
        }
        ($sum.Keys | Sort-Object) -join ',' | Should -Be 'imported,received'
    }

    It 'mecm-site-health: site_code/provider bleiben String, raw_status wird Int' {
        $sum = Invoke-InFileScope -Path $script:MecmCommon -Body {
            New-VsRunSummary -Source 'mecm-site-health' -Values @{ site_code = 'P01'; provider = 'CM01'; raw_status = '1' }
        }
        $sum['site_code'] | Should -BeOfType [string]
        $sum['raw_status'] | Should -BeOfType [int]
        $sum['raw_status'] | Should -Be 1
    }

    It 'klemmt negative Zaehler auf 0' {
        $sum = Invoke-InFileScope -Path $script:MecmCommon -Body {
            New-VsRunSummary -Source 'device-sync' -Values @{ item_failures = -4 }
        }
        $sum['item_failures'] | Should -Be 0
    }

    It 'liefert $null, wenn nichts Erlaubtes uebrig bleibt (leeres Objekt lehnt der Server ab)' {
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            New-VsRunSummary -Source 'packages-sync' -Values @{ nichterlaubt = 1 }
        } | Should -BeNullOrEmpty
    }

    It 'akzeptiert je Quelle genau ihre Felder: <source>' -ForEach @(
        @{ source = 'device-sync';      keys = @('received', 'imported', 'item_failures', 'data_warnings', 'resource_update_failures') }
        @{ source = 'packages-sync';    keys = @('packages', 'task_sequences', 'sent', 'unchanged') }
        @{ source = 'autoimporter';     keys = @('folders', 'created', 'removed', 'open_points', 'unchanged') }
    ) {
        $values = @{}
        foreach ($k in $keys) { $values[$k] = 1 }
        $sum = Invoke-InFileScope -Path $script:MecmCommon -Arguments @($source, $values) -Body {
            param($s, $v) New-VsRunSummary -Source $s -Values $v
        }
        ($sum.Keys | Sort-Object) -join ',' | Should -Be (($keys | Sort-Object) -join ',')
    }
}

Describe 'Test-VsRunErrorCategory (zentrale Kategorisierung)' {
    It 'akzeptiert eine Sync-Kategorie: <cat>' -ForEach @(
        @{ cat = 'portal_unreachable' }
        @{ cat = 'mecm_unavailable' }
        @{ cat = 'partial_failure' }
        @{ cat = 'source_missing' }
        @{ cat = 'catalog_conflict' }
    ) {
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($cat) -Body {
            param($c) Test-VsRunErrorCategory -Source 'device-sync' -Outcome 'fail' -Category $c
        } | Should -BeTrue
    }

    It 'lehnt eine unbekannte Sync-Kategorie ab' {
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            Test-VsRunErrorCategory -Source 'device-sync' -Outcome 'fail' -Category 'nonsense'
        } | Should -BeFalse
    }

    It 'bindet Site-Kategorie an ihr Outcome: <cat> -> <outcome>' -ForEach @(
        @{ cat = 'site_warning';           outcome = 'warning' }
        @{ cat = 'site_critical';          outcome = 'fail' }
        @{ cat = 'provider_access_denied'; outcome = 'unknown' }
        @{ cat = 'provider_unreachable';   outcome = 'unknown' }
        @{ cat = 'query_failed';           outcome = 'unknown' }
    ) {
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($cat, $outcome) -Body {
            param($c, $o) Test-VsRunErrorCategory -Source 'mecm-site-health' -Outcome $o -Category $c
        } | Should -BeTrue
    }

    It 'lehnt eine Site-Kategorie mit falschem Outcome ab (site_warning != fail)' {
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            Test-VsRunErrorCategory -Source 'mecm-site-health' -Outcome 'fail' -Category 'site_warning'
        } | Should -BeFalse
    }
}

Describe 'Get-VsSiteHealthOutcome (reine Statusabbildung 0/1/2/other)' {
    It 'bildet Rohstatus <raw> auf <outcome>/<cat> ab' -ForEach @(
        @{ raw = 0;  outcome = 'ok';      cat = $null }
        @{ raw = 1;  outcome = 'warning'; cat = 'site_warning' }
        @{ raw = 2;  outcome = 'fail';    cat = 'site_critical' }
        @{ raw = 7;  outcome = 'unknown'; cat = 'query_failed' }
        @{ raw = -1; outcome = 'unknown'; cat = 'query_failed' }
    ) {
        $o = Invoke-InFileScope -Path $script:MecmCommon -Arguments @($raw) -Body {
            param($r) Get-VsSiteHealthOutcome -RawStatus $r
        }
        $o.Outcome | Should -Be $outcome
        if ($null -eq $cat) { $o.ErrorCategory | Should -BeNullOrEmpty } else { $o.ErrorCategory | Should -Be $cat }
    }
}

Describe 'Get-VsSiteHealthReportCategory (provider_unreachable erst nach 2 Zyklen)' {
    It 'daempft provider_unreachable im ERSTEN Fehlzyklus auf query_failed' {
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            Get-VsSiteHealthReportCategory -Category 'provider_unreachable' -ConsecutiveFailures 1
        } | Should -Be 'query_failed'
    }

    It 'meldet provider_unreachable ab dem ZWEITEN Fehlzyklus' {
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            Get-VsSiteHealthReportCategory -Category 'provider_unreachable' -ConsecutiveFailures 2
        } | Should -Be 'provider_unreachable'
    }

    It 'laesst andere Kategorien unveraendert (auch im ersten Zyklus)' {
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            Get-VsSiteHealthReportCategory -Category 'provider_access_denied' -ConsecutiveFailures 1
        } | Should -Be 'provider_access_denied'
    }
}

Describe 'Get-VsProviderMachine (feste Aufloesungsreihenfolge)' {
    It '1. der -ProviderMachine-Parameter gewinnt' {
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            Get-VsProviderMachine -Config ([pscustomobject]@{ ProviderMachine = 'FROMREG' }) -ProviderMachine 'EXPLICIT'
        } | Should -Be 'EXPLICIT'
    }

    It '2. sonst der Registry-Wert aus der Config' {
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            Get-VsProviderMachine -Config ([pscustomobject]@{ ProviderMachine = 'FROMREG' }) -ProviderMachine ''
        } | Should -Be 'FROMREG'
    }

    It '3. lokale WMI-Erkennung -> der lokale Rechner ist der Provider' {
        $expected = $env:COMPUTERNAME
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($expected) -Body {
            param($cn)
            function Get-CimInstance { param($Namespace, $ClassName, $Query, $ComputerName, $ErrorAction) [pscustomobject]@{ Name = 'site_P01' } }
            Get-VsProviderMachine -Config $null -ProviderMachine ''
        } | Should -Be $expected
    }

    It '4. sonst der Root des CMSite-PSDrive' {
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            function Get-CimInstance { throw 'no wmi' }
            function Get-PSDrive { param($PSProvider, $ErrorAction) [pscustomobject]@{ Root = 'CM-PROVIDER.contoso.com' } }
            Get-VsProviderMachine -Config $null -ProviderMachine ''
        } | Should -Be 'CM-PROVIDER.contoso.com'
    }

    It '5. Notnagel: der lokale Rechnername' {
        $expected = $env:COMPUTERNAME
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($expected) -Body {
            param($cn)
            function Get-CimInstance { throw 'no wmi' }
            function Get-PSDrive { throw 'no drive' }
            Get-VsProviderMachine -Config $null -ProviderMachine ''
        } | Should -Be $expected
    }
}

Describe 'Get-VsMecmSiteHealth (Providerfehler -> unknown)' {
    It 'ohne Site-Code sofort unknown/query_failed (keine Abfrage moeglich)' {
        $h = Invoke-InFileScope -Path $script:MecmCommon -Body {
            function Get-VsSiteCode { param($Config) $null }
            Get-VsMecmSiteHealth -Config ([pscustomobject]@{ ProviderMachine = 'CM01' }) -ProviderMachine 'CM01'
        }
        $h.Outcome | Should -Be 'unknown'
        $h.ErrorCategory | Should -Be 'query_failed'
    }

    It 'Zugriff verweigert -> unknown/provider_access_denied' {
        $h = Invoke-InFileScope -Path $script:MecmCommon -Body {
            function Get-VsSiteCode { param($Config) 'P01' }
            function Get-CimInstance { throw 'Access is denied' }
            Get-VsMecmSiteHealth -Config ([pscustomobject]@{ ProviderMachine = 'CM01' }) -ProviderMachine 'CM01'
        }
        $h.Outcome | Should -Be 'unknown'
        $h.ErrorCategory | Should -Be 'provider_access_denied'
    }

    It 'RPC-Server nicht verfuegbar -> unknown/provider_unreachable' {
        $h = Invoke-InFileScope -Path $script:MecmCommon -Body {
            function Get-VsSiteCode { param($Config) 'P01' }
            function Get-CimInstance { throw 'The RPC server is unavailable' }
            Get-VsMecmSiteHealth -Config ([pscustomobject]@{ ProviderMachine = 'CM01' }) -ProviderMachine 'CM01'
        }
        $h.Outcome | Should -Be 'unknown'
        $h.ErrorCategory | Should -Be 'provider_unreachable'
    }

    It 'sonstiger Fehler -> unknown/query_failed' {
        $h = Invoke-InFileScope -Path $script:MecmCommon -Body {
            function Get-VsSiteCode { param($Config) 'P01' }
            function Get-CimInstance { throw 'weird boom' }
            Get-VsMecmSiteHealth -Config ([pscustomobject]@{ ProviderMachine = 'CM01' }) -ProviderMachine 'CM01'
        }
        $h.Outcome | Should -Be 'unknown'
        $h.ErrorCategory | Should -Be 'query_failed'
    }

    It 'gesunder Status 0 -> ok, mit Rohstatus und Provider im Ergebnis' {
        $h = Invoke-InFileScope -Path $script:MecmCommon -Body {
            function Get-VsSiteCode { param($Config) 'P01' }
            function Get-CimInstance { param($Namespace, $ClassName, $Query, $ComputerName, $ErrorAction) [pscustomobject]@{ SiteCode = 'P01'; Status = 0 } }
            Get-VsMecmSiteHealth -Config ([pscustomobject]@{ ProviderMachine = 'CM01' }) -ProviderMachine 'CM01'
        }
        $h.Outcome | Should -Be 'ok'
        $h.RawStatus | Should -Be 0
        $h.SiteCode | Should -Be 'P01'
        $h.Provider | Should -Be 'CM01'
    }
}

Describe 'Send-VsRunReport (Payload-Form und Byte-Kuerzung vor dem Senden)' {
    It 'started traegt nur die Kopf-Felder, kein outcome/summary/detail' {
        $body = Get-SentBody -Params @{
            Config = [pscustomobject]@{ ReportToken = 'T' }; Source = 'device-sync'; RunEvent = 'started'
            RunId = '0123456789abcdef0123456789abcdef'; IntervalSeconds = 10; ScriptVersion = 'device-sync/2.0'
        }
        $body['source'] | Should -Be 'device-sync'
        $body['event'] | Should -Be 'started'
        $body['run_id'] | Should -Be '0123456789abcdef0123456789abcdef'
        $body.Contains('outcome') | Should -BeFalse
        $body.Contains('summary') | Should -BeFalse
        $body.Contains('detail') | Should -BeFalse
    }

    It 'completed/ok setzt outcome=ok und KEIN error_category' {
        $body = Get-SentBody -Params @{
            Config = [pscustomobject]@{ ReportToken = 'T' }; Source = 'device-sync'; RunEvent = 'completed'
            RunId = '0123456789abcdef0123456789abcdef'; IntervalSeconds = 10; Outcome = 'ok'
            DurationMs = 1234; Summary = @{ received = 3; imported = 1 }; ScriptVersion = 'device-sync/2.0'
        }
        $body['outcome'] | Should -Be 'ok'
        $body.Contains('error_category') | Should -BeFalse
        $body['duration_ms'] | Should -Be 1234
        $body['summary']['received'] | Should -Be 3
    }

    It 'completed/warning traegt die passende error_category' {
        $body = Get-SentBody -Params @{
            Config = [pscustomobject]@{ ReportToken = 'T' }; Source = 'packages-sync'; RunEvent = 'completed'
            RunId = '0123456789abcdef0123456789abcdef'; IntervalSeconds = 60; Outcome = 'warning'
            ErrorCategory = 'catalog_conflict'; Summary = @{ packages = 2; sent = 1 }
        }
        $body['outcome'] | Should -Be 'warning'
        $body['error_category'] | Should -Be 'catalog_conflict'
    }

    It 'kuerzt Detail VOR dem Senden in Bytes (der Body-Cap bricht nie)' {
        $body = Get-SentBody -Params @{
            Config = [pscustomobject]@{ ReportToken = 'T' }; Source = 'device-sync'; RunEvent = 'completed'
            RunId = '0123456789abcdef0123456789abcdef'; IntervalSeconds = 10; Outcome = 'fail'
            ErrorCategory = 'portal_unreachable'; Detail = ('ü' * 5000)
        }
        [System.Text.Encoding]::UTF8.GetByteCount([string]$body['detail']) | Should -BeLessOrEqual 2048
        # Der vollstaendige serialisierte Body bleibt unter dem 8-KB-Limit.
        [System.Text.Encoding]::UTF8.GetByteCount(($body | ConvertTo-Json -Depth 6)) | Should -BeLessThan 8192
    }

    It 'redigiert den Token, falls er je in den Detailtext geraet' {
        $body = Get-SentBody -Params @{
            Config = [pscustomobject]@{ ReportToken = 'HUNTER2' }; Source = 'device-sync'; RunEvent = 'completed'
            RunId = '0123456789abcdef0123456789abcdef'; IntervalSeconds = 10; Outcome = 'fail'
            ErrorCategory = 'portal_unreachable'; Detail = 'token HUNTER2 leaked'
        }
        [string]$body['detail'] | Should -Not -Match 'HUNTER2'
    }

    It 'klemmt ein exotisches interval_seconds in die gueltige Spanne (<=3600)' {
        $body = Get-SentBody -Params @{
            Config = [pscustomobject]@{ ReportToken = 'T' }; Source = 'autoimporter'; RunEvent = 'completed'
            RunId = '0123456789abcdef0123456789abcdef'; IntervalSeconds = 99999; Outcome = 'ok'
        }
        $body['interval_seconds'] | Should -Be 3600
    }

    It 'ein Zustellfehler beendet die Funktion NICHT (fire-and-forget)' {
        $threw = Invoke-InFileScope -Path $script:MecmCommon -Body {
            Initialize-VsLog -Component 'pester-runreport' -LogRoot ([System.IO.Path]::GetTempPath())
            function Invoke-VsApi { throw 'network down' }
            $t = $false
            try {
                Send-VsRunReport -Config ([pscustomobject]@{ ReportToken = 'T' }) -Source 'device-sync' -RunEvent 'completed' `
                    -RunId '0123456789abcdef0123456789abcdef' -IntervalSeconds 10 -Outcome 'fail' -ErrorCategory 'portal_unreachable'
            } catch { $t = $true }
            $t
        }
        $threw | Should -BeFalse
    }

    It 'loggt einen Zustellfehler nur EINMAL pro Throttle-Fenster' {
        $count = Invoke-InFileScope -Path $script:MecmCommon -Body {
            Initialize-VsLog -Component 'pester-runreport' -LogRoot ([System.IO.Path]::GetTempPath())
            $script:VsReportLastFailureLog = $null
            $script:VsReportThrottleSeconds = 9999
            $script:logCalls = 0
            function Invoke-VsApi { throw 'boom' }
            function Write-VsLog { param($Message, $Level, $Context, $Color) $script:logCalls++ }
            1..3 | ForEach-Object {
                Send-VsRunReport -Config ([pscustomobject]@{ ReportToken = 'T' }) -Source 'device-sync' -RunEvent 'completed' `
                    -RunId '0123456789abcdef0123456789abcdef' -IntervalSeconds 10 -Outcome 'fail' -ErrorCategory 'portal_unreachable'
            }
            $script:logCalls
        }
        $count | Should -Be 1
    }
}

Describe 'Throttle-Konstante ist gepinnt' {
    It '$script:VsReportThrottleSeconds ist gesetzt und positiv' {
        Invoke-InFileScope -Path $script:MecmCommon -Body { $script:VsReportThrottleSeconds } | Should -BeGreaterThan 0
    }
}

Describe 'Schleifenstruktur der vier Report-Skripte' {
    It '<name> sendet je Iteration <started> started und <completed> completed' -ForEach @(
        @{ name = 'mecm_new-device-sync.ps1';      started = 1; completed = 1 }
        @{ name = 'mecm_Packages-TaskSeq-sync.ps1'; started = 1; completed = 1 }
        @{ name = 'mecm_autoimporter.ps1';          started = 1; completed = 1 }
        @{ name = 'mecm_site-health.ps1';           started = 0; completed = 1 }
    ) {
        # Site-Health meldet NUR completed (kein started). Die Sync-Skripte je
        # genau eines von beiden pro Iteration.
        $text = Get-ScriptText -Name $name
        ([regex]::Matches($text, "'started'")).Count | Should -Be $started
        ([regex]::Matches($text, "'completed'")).Count | Should -Be $completed
    }

    It '<name>: die Abschlussmeldung steht in einem finally (kein Zweig ueberspringt sie)' -ForEach @(
        @{ name = 'mecm_new-device-sync.ps1' }
        @{ name = 'mecm_Packages-TaskSeq-sync.ps1' }
        @{ name = 'mecm_autoimporter.ps1' }
        @{ name = 'mecm_site-health.ps1' }
    ) {
        $ast = Get-ScriptAst -Name $name
        $tries = $ast.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.TryStatementAst] -and
            $n.Finally -and $n.Finally.Extent.Text -match 'Send-VsRunReport'
        }, $true)
        @($tries).Count | Should -BeGreaterThan 0
    }

    It '<name> nutzt Send-VsRunReport statt des Legacy-Send-VsHeartbeat' -ForEach @(
        @{ name = 'mecm_new-device-sync.ps1' }
        @{ name = 'mecm_Packages-TaskSeq-sync.ps1' }
        @{ name = 'mecm_autoimporter.ps1' }
        @{ name = 'mecm_site-health.ps1' }
    ) {
        $text = Get-ScriptText -Name $name
        $text | Should -Match 'Send-VsRunReport'
        $text | Should -Not -Match 'Send-VsHeartbeat'
    }
}

# Eine VM verlaesst die MECM-Warteschlange ausschliesslich durch die
# ResourceID-Rueckmeldung an mecm_updateid.php, das sie auf `registered` setzt;
# getDeviceList liefert sie danach nicht mehr. Die Meldung lief unbedingt, also
# fiel eine VM dauerhaft aus der Warteschlange, obwohl ihre OS-, Paket- oder
# Mission-Collection fehlte: auf dem Client ein PXE-Boot ohne Task Sequence, im
# Portal eine fertig registrierte VM, und niemand schob mehr nach.
Describe 'Device-Sync entlaesst keine VM mit unvollstaendiger Zuweisung' {
    BeforeAll {
        $script:DeviceSyncText = Get-ScriptText -Name 'mecm_new-device-sync.ps1'
        $script:DeviceSyncAst = Get-ScriptAst -Name 'mecm_new-device-sync.ps1'
    }

    It 'zaehlt fehlgeschlagene Zuweisungen je Device' {
        # Ein Zaehler pro Device, nicht pro Lauf: ein Lauf ueber zehn VMs darf
        # nicht neun gute Meldungen wegen der zehnten unterdruecken.
        $script:DeviceSyncText | Should -Match '\$targetsSkipped\s*=\s*0'
        ([regex]::Matches($script:DeviceSyncText, '\$targetsSkipped\+\+')).Count | Should -BeGreaterOrEqual 2
    }

    It 'meldet die ResourceID nur, wenn keine Zuweisung uebersprungen wurde' {
        # Der Aufruf muss HINTER einer Bedingung liegen, die auf den Zaehler
        # schaut. Als Reihenfolgepruefung im Text, weil genau die Reihenfolge der
        # Befund war: die Meldung stand vorher unbedingt danach.
        $script:DeviceSyncText | Should -Match '(?s)\$targetsSkipped -gt 0.*?continue.*?mecm_updateid\.php'
    }

    It 'haelt die VM auch bei verlorener Provenienz in der Warteschlange' {
        # updateDevice ist der einzige Weg aus getDeviceList. Lief es trotz
        # fehlgeschlagenem Provenienz-Report, fehlte dem Portal der
        # Eigentumsnachweis, den ADR-0034 fuer jedes Entfernen verlangt: die VM
        # behielt ihre alten Collections fuer immer und bekam nach einem
        # Missionswechsel weiter das alte Image, waehrend MECM korrekt aussah.
        # Ein "naechster Lauf", auf den sich das vertagen liesse, existiert fuer
        # diese VM nicht mehr.
        $catches = @($script:DeviceSyncAst.FindAll({
            param($n) $n -is [System.Management.Automation.Language.CatchClauseAst] -and
                      $n.Extent.Text -match 'membership_report_failed'
        }, $true))
        $catches.Count | Should -Be 1 -Because 'sonst prueft dieser Test die falsche Stelle'

        @($catches[0].Body.FindAll({
            param($n) $n -is [System.Management.Automation.Language.ContinueStatementAst]
        }, $true)).Count | Should -BeGreaterThan 0 -Because 'ohne continue laeuft updateDevice und nimmt die VM aus der Warteschlange'

        # Der Guard darauf, dass ueberhaupt etwas gemeldet werden muss, ist
        # tragend: ohne ihn bliebe jede VM ohne Aenderung dauerhaft haengen.
        $script:DeviceSyncText | Should -Match '\$membershipReport\.Count -gt 0'

        # Zaehler statt Datenhinweis: es ist ein Fehlschlag fuer diese VM.
        $catches[0].Body.Extent.Text | Should -Match '\$itemFailures\+\+'
        $catches[0].Body.Extent.Text | Should -Not -Match '\$dataWarnings\+\+'
    }

    It 'nennt in der Unvollstaendigkeits-Meldung einen Nenner, den das Skript auch setzt' {
        # Der Nenner stand als $targets.Count da, und $targets existiert im
        # ganzen Skript nicht: unter Set-StrictMode 1.0 wirft schon das Lesen,
        # der Wurf landet im aeusseren Catch, und eine reine Datenlage wird als
        # mecm_unavailable gemeldet - Scan abgebrochen, Site-Drive nach drei
        # Malen weggeworfen. Die Meldung, die einen Fehler benennen wollte,
        # erzeugte einen groesseren.
        # Innerstes if: jedes umschliessende enthaelt denselben Text, sortiert
        # wird deshalb nach Laenge des Extents.
        $ifs = @($script:DeviceSyncAst.FindAll({
            param($n) $n -is [System.Management.Automation.Language.IfStatementAst] -and
                      $n.Extent.Text -match 'Mitgliedschaftsoperationen unvollstaendig'
        }, $true) | Sort-Object { $_.Extent.Text.Length })
        $ifs.Count | Should -BeGreaterThan 0 -Because 'sonst prueft dieser Test die falsche Stelle'

        $assigned = @{}
        foreach ($a in @($script:DeviceSyncAst.FindAll({
            param($n) $n -is [System.Management.Automation.Language.AssignmentStatementAst]
        }, $true))) {
            $left = $a.Left
            if ($left -is [System.Management.Automation.Language.ConvertExpressionAst]) { $left = $left.Child }
            if ($left -is [System.Management.Automation.Language.VariableExpressionAst]) {
                $assigned[$left.VariablePath.UserPath.ToLowerInvariant()] = $true
            }
        }

        foreach ($v in @($ifs[0].Clauses[0].Item2.FindAll({
            param($n) $n -is [System.Management.Automation.Language.VariableExpressionAst]
        }, $true))) {
            $name = $v.VariablePath.UserPath
            if ($name -match ':') { continue }
            $assigned.ContainsKey($name.ToLowerInvariant()) | Should -BeTrue -Because "`$$name wird in diesem Skript nie zugewiesen"
        }
    }

    It 'zaehlt geplante Entfernungen in den Nenner mit' {
        # $targetsSkipped zaehlt auch fehlgeschlagene Entfernungen, und die
        # stehen nicht in $desired. Mit $desired.Count allein meldet die Zeile
        # bei einem Missionswechsel "4 von 3 Zuweisungen unvollstaendig".
        $script:DeviceSyncText | Should -Match '\$targetsPlanned\s*=\s*\$desired\.Count\s*\+\s*@\(\$plan\.remove\)\.Count'
        $script:DeviceSyncText | Should -Match 'Mitgliedschaftsoperationen unvollstaendig[^"]*"\s*-f\s*\$targetsSkipped,\s*\$targetsPlanned'
    }

    It 'behandelt einen MAC-Konflikt als Fehlschlag des Devices, nicht als Notiz' {
        # MECM wartet dann auf eine MAC, die beim PXE-Boot nie kommt; die VM aus
        # der Warteschlange zu nehmen macht das unbehebbar.
        $script:DeviceSyncText | Should -Match "(?s)MAC-Konflikt.*?\`$itemFailures\+\+"
    }

    It 'laesst die drei MECM-Vollabfragen bei einem Providerfehler werfen' -ForEach @(
        @{ cmdlet = 'Get-CMDevice -Fast' }
        @{ cmdlet = 'Get-CMTaskSequence -Fast' }
        @{ cmdlet = 'Get-CMDeviceCollection' }
    ) {
        # Ohne -ErrorAction Stop ist ein Providerfehler nicht von "MECM ist leer"
        # zu unterscheiden: der Lauf laeuft mit leeren Caches weiter, importiert
        # jedes Device erneut und meldet jede Zielcollection als fehlend.
        $pattern = [regex]::Escape($cmdlet) + ' -ErrorAction Stop'
        $script:DeviceSyncText | Should -Match $pattern
    }

    It 'sendet auf dem Warnpfad ein Detail statt $null' {
        # "Datenwarnungen: 3" ohne Detail nennt keine VM, rendert den
        # Aufklappblock nicht, und der naechste saubere Lauf ueberschreibt die 3.
        $script:DeviceSyncText | Should -Match '\$detail\s*=\s*Format-VsRunDetail -Causes \$causes'
        $script:DeviceSyncText | Should -Match 'New-VsRunCauseList'
    }

    It 'haengt an jeden Zaehler-Hochzaehler auch eine Ursache' {
        # Ein Zaehler ohne Ursache ist die Zahl ohne Namen, also der Befund
        # selbst. Geprueft wird das Verhaeltnis: jeder ++ eines gemeldeten
        # Zaehlers braucht ein Add-VsRunCause in Reichweite.
        $counted = ([regex]::Matches($script:DeviceSyncText, '\$(dataWarnings|itemFailures|resourceUpdateFailures)\+\+')).Count
        $causes = ([regex]::Matches($script:DeviceSyncText, 'Add-VsRunCause')).Count
        $causes | Should -BeGreaterOrEqual ($counted - 1)  # der Sammelfall am Ende zaehlt ohne eigene Ursache
    }
}

# Beide Zuweisungswege (Portal und MECM-Konsole) bestehen nebeneinander, WEIL
# ein Entfernen den Provenienz-Beweis braucht (ADR-0034): der Device-Sync darf
# eine Direct-Rule ausschliesslich aus dem Plan-Bucket `remove` nehmen, und der
# enthaelt nur Regeln, die owned UND vorhanden UND nicht mehr gewuenscht sind.
# Eine in der Konsole gesetzte Mitgliedschaft hat keine Provenienzzeile und ist
# damit konstruktionsbedingt unantastbar; adoptiert wird nie im Sync, sondern
# nur ausdruecklich im Portal. Der alte Vertrag ("es gibt gar kein Remove") ist
# hier bewusst neu geschnitten worden.
Describe 'Mitgliedschaften entfernt nur der Device-Sync, und nur mit Provenienz-Beweis' {

    It '<name> ruft kein Remove-Cmdlet auf einer Mitgliedschaft oder einem Geraet auf' -ForEach @(
        @{ name = 'mecm_Packages-TaskSeq-sync.ps1' }
        @{ name = 'mecm_autoimporter.ps1' }
        @{ name = 'mecm_site-health.ps1' }
    ) {
        # Dass der Autoimporter die Collection einer ueberholten Paketversion
        # abraeumt, ist seine dokumentierte Aufgabe und eine andere Frage: die
        # Collection verschwindet mit der Version, zu der sie gehoert.
        $text = Get-ScriptText -Name $name
        foreach ($forbidden in @(
            'Remove-CMDeviceCollectionDirectMembershipRule',
            'Remove-CMDeviceCollectionMembershipRule',
            'Remove-CMDeviceCollectionQueryMembershipRule',
            'Remove-CMDeviceCollectionExcludeMembershipRule',
            'Remove-CMDevice ',
            'Remove-CMDevice$',
            'Clear-CMDeviceCollection'
        )) {
            $text | Should -Not -Match $forbidden
        }
    }

    It 'der Device-Sync entfernt Direct-Rules genau einmal, und nur aus $plan.remove' {
        $text = Get-ScriptText -Name 'mecm_new-device-sync.ps1'
        # Zero-Match-Schutz: das Remove MUSS existieren (der Sync reconciliert
        # seit ADR-0034), sonst prueft der Positivpfad nichts.
        $text | Should -Match 'Remove-CMDeviceCollectionDirectMembershipRule'
        ([regex]::Matches($text, 'Remove-CMDeviceCollectionDirectMembershipRule')).Count | Should -Be 1
        # Die eine Aufrufstelle konsumiert den Plan-Bucket: die Schleife ueber
        # $plan.remove ist der Provenienz-Beweis, den die Vektoren pinnen.
        $text | Should -Match '(?s)foreach \(\$rule in @\(\$plan\.remove\)\).{0,600}Remove-CMDeviceCollectionDirectMembershipRule'
        # Die uebrigen Formen bleiben auch im Device-Sync verboten.
        foreach ($forbidden in @(
            'Remove-CMDeviceCollectionMembershipRule',
            'Remove-CMDeviceCollectionQueryMembershipRule',
            'Remove-CMDeviceCollectionExcludeMembershipRule',
            'Remove-CMDevice ',
            'Remove-CMDevice$',
            'Clear-CMDeviceCollection'
        )) {
            $text | Should -Not -Match $forbidden
        }
    }

    It 'benutzt zum Zuweisen nur Add-CMDeviceCollectionDirectMembershipRule' {
        # Zero-Match-Schutz: findet der Test das Add nicht, prueft er nichts.
        $text = Get-ScriptText -Name 'mecm_new-device-sync.ps1'
        $text | Should -Match 'Add-CMDeviceCollectionDirectMembershipRule'
    }

    It 'der Device-Sync loescht keine Collection' {
        # Er legt sie an (OS, Mission) und weist zu. Eine Collection zu entfernen
        # ist nicht seine Aufgabe, und eine geloeschte Mission-Collection wuerde
        # jede Zuweisung darin mitnehmen.
        Get-ScriptText -Name 'mecm_new-device-sync.ps1' | Should -Not -Match 'Remove-CMDeviceCollection\b'
    }
}

# TLS 1.2 setzte AUSSCHLIESSLICH der Installer, in seinem eigenen Prozess. Die
# vier Aufgaben laufen in eigenen Prozessen, und unter Windows PowerShell 5.1 ist
# der Vorgabewert von SecurityProtocol je nach Windows-Version zu alt (SSL3/TLS1).
# Ein Portal auf TLS 1.2+ haette die vier Aufgaben also mit einem Handshake-Fehler
# stillgelegt, obwohl Scheme=https laengst konfigurierbar war und der Installer
# gruen meldete: sein Probelauf bewies etwas, das seine Aufgaben nicht konnten.
Describe 'TLS-Vorbereitung der MECM-Aufgaben' {
    BeforeAll {
        # Ein echtes, kurzlebiges selbstsigniertes Zertifikat im Speicher. Kein
        # New-SelfSignedCertificate: das braucht Windows und schreibt in den
        # Zertifikatsspeicher; CertificateRequest laeuft auch unter pwsh auf Linux.
        function New-SelfSignedTestCertificate {
            param([string]$Subject)
            $key = [System.Security.Cryptography.RSA]::Create(2048)
            $request = [System.Security.Cryptography.X509Certificates.CertificateRequest]::new(
                $Subject,
                $key,
                [System.Security.Cryptography.HashAlgorithmName]::SHA256,
                [System.Security.Cryptography.RSASignaturePadding]::Pkcs1)
            return $request.CreateSelfSigned([DateTimeOffset]::UtcNow.AddDays(-1), [DateTimeOffset]::UtcNow.AddDays(1))
        }
    }

    BeforeEach {
        $script:SavedCallback = [System.Net.ServicePointManager]::ServerCertificateValidationCallback
        $script:SavedProtocol = [System.Net.ServicePointManager]::SecurityProtocol
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback = $null
    }
    AfterEach {
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback = $script:SavedCallback
        [System.Net.ServicePointManager]::SecurityProtocol = $script:SavedProtocol
    }

    It '<name> ruft Initialize-VsTls im eigenen Prozess auf' -ForEach @(
        @{ name = 'mecm_new-device-sync.ps1' }
        @{ name = 'mecm_Packages-TaskSeq-sync.ps1' }
        @{ name = 'mecm_autoimporter.ps1' }
        @{ name = 'mecm_site-health.ps1' }
    ) {
        Get-ScriptText -Name $name | Should -Match 'Initialize-VsTls -Config \$config'
    }

    It 'tut bei http nichts (kein Callback, keine Protokollaenderung noetig)' {
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            Initialize-VsTls -Config ([pscustomobject]@{ Scheme = 'http'; CertThumbprint = 'A' * 40 })
        }
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback | Should -BeNullOrEmpty
    }

    It 'setzt bei https TLS 1.2' {
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            Initialize-VsTls -Config ([pscustomobject]@{ Scheme = 'https'; CertThumbprint = '' })
        }
        ([System.Net.ServicePointManager]::SecurityProtocol -band [Net.SecurityProtocolType]::Tls12) | Should -Not -Be 0
    }

    It 'setzt OHNE Fingerabdruck keinen Callback (normale Kettenpruefung bleibt)' {
        # Ohne hinterlegten Fingerabdruck ist die normale Pruefung die staerkere
        # Antwort; ein Callback waere hier nur eine Gelegenheit, sie zu verlieren.
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            Initialize-VsTls -Config ([pscustomobject]@{ Scheme = 'https'; CertThumbprint = '' })
        }
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback | Should -BeNullOrEmpty
    }

    It 'akzeptiert MIT Fingerabdruck genau das hinterlegte, selbstsignierte Zertifikat' {
        # Der Kern der Entscheidung: hinterlegt statt Pruefung abgeschaltet. Ein
        # Zertifikatswechsel schlaegt damit fehl, bis jemand den neuen Abdruck
        # eintraegt, statt still vertraut zu werden.
        #
        # Zwei ECHTE selbstsignierte Zertifikate, kein Stub: der Callback-Parameter
        # ist als X509Certificate typisiert, PowerShell wandelt ein pscustomobject
        # also in ein leeres Zertifikat um und GetCertHashString() wirft. Genau die
        # Lage, die hier geprueft werden soll, waere damit nicht pruefbar.
        $trusted = New-SelfSignedTestCertificate -Subject 'CN=vs-pester-tls-a'
        $rogue = New-SelfSignedTestCertificate -Subject 'CN=vs-pester-tls-b'
        $expected = $trusted.GetCertHashString()

        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($expected) -Body {
            param($thumb)
            Initialize-VsTls -Config ([pscustomobject]@{ Scheme = 'https'; CertThumbprint = $thumb })
        }
        $callback = [System.Net.ServicePointManager]::ServerCertificateValidationCallback
        $callback | Should -Not -BeNullOrEmpty

        # Policy-Fehler, also genau die Lage bei einem selbstsignierten Zertifikat.
        $untrusted = [System.Net.Security.SslPolicyErrors]::RemoteCertificateChainErrors

        $callback.Invoke($null, $trusted, $null, $untrusted) | Should -BeTrue
        $callback.Invoke($null, $rogue, $null, $untrusted) | Should -BeFalse
        $callback.Invoke($null, $null, $null, $untrusted) | Should -BeFalse
    }

    It 'nimmt ein Zertifikat ohne Policy-Fehler ohnehin an' {
        # Ein gueltiges PKI-Zertifikat darf der Pin nicht aussperren.
        Invoke-InFileScope -Path $script:MecmCommon -Body {
            Initialize-VsTls -Config ([pscustomobject]@{ Scheme = 'https'; CertThumbprint = 'B' * 40 })
        }
        $callback = [System.Net.ServicePointManager]::ServerCertificateValidationCallback
        $callback.Invoke($null, $null, $null, [System.Net.Security.SslPolicyErrors]::None) | Should -BeTrue
    }

    It 'liest den Fingerabdruck aus der Registry-Konfiguration' {
        # Get-VsConfig normalisiert Trennzeichen weg, damit ein aus certlm.msc
        # kopierter Abdruck mit Leerzeichen genauso funktioniert.
        $probe = 'HKCU:\Software\_vs_tls_' + [guid]::NewGuid().ToString('N')
        New-Item -Path $probe -Force | Out-Null
        try {
            New-ItemProperty -Path $probe -Name 'VirtuSphere_WebAPI' -Value 'portal.lan:8443' -PropertyType String -Force | Out-Null
            New-ItemProperty -Path $probe -Name 'CertThumbprint' -Value 'a1 b2:c3-d4 e5f6 0718293a4b5c6d7e8f9012345678' -PropertyType String -Force | Out-Null
            $thumb = Invoke-InFileScope -Path $script:MecmCommon -Arguments @($probe) -Body {
                param($path)
                $script:VsRegistryPath = $path
                (Get-VsConfig).CertThumbprint
            }
            $thumb | Should -Be 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678'
        } finally {
            Remove-Item -Path $probe -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}

Describe 'Ursachenvokabular der Warnlaeufe' {
    It 'ValidateSet von Add-VsRunCause und $VsRunCauseVocabulary sind deckungsgleich' {
        # Zwei Spiegel derselben Liste: ohne diesen Walk kann ein neuer Code im
        # ValidateSet landen, ohne im Vokabular zu stehen (oder umgekehrt), und
        # der Aufruf schlaegt erst auf dem MECM-Server fehl.
        $vocabulary = Invoke-InFileScope -Path $script:MecmCommon -Body { $script:VsRunCauseVocabulary }

        $ast = [System.Management.Automation.Language.Parser]::ParseFile($script:MecmCommon, [ref]$null, [ref]$null)
        $function = $ast.FindAll({ param($n)
            $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'Add-VsRunCause'
        }, $true) | Select-Object -First 1
        $function | Should -Not -BeNullOrEmpty

        $validateSet = $function.Body.ParamBlock.Parameters |
            Where-Object { $_.Name.VariablePath.UserPath -eq 'Cause' } |
            ForEach-Object { $_.Attributes } |
            Where-Object { $_.TypeName.Name -eq 'ValidateSet' } |
            ForEach-Object { $_.PositionalArguments.Value }

        @($validateSet) | Should -Not -BeNullOrEmpty
        (@($validateSet) | Sort-Object) -join ',' | Should -Be ((@($vocabulary) | Sort-Object) -join ',')
    }

    It 'nennt Ziel und Collection im Detail' {
        $detail = Invoke-InFileScope -Path $script:MecmCommon -Body {
            $causes = New-VsRunCauseList
            Add-VsRunCause -Causes $causes -Cause 'collection_missing' -Target 'WEB01' -Collection 'Firefox-115.0'
            Format-VsRunDetail -Causes $causes
        }

        $detail | Should -Be 'collection_missing target=WEB01 collection=Firefox-115.0'
    }

    It 'deckelt die Liste und sagt, wie viele fehlen' {
        $detail = Invoke-InFileScope -Path $script:MecmCommon -Body {
            $causes = New-VsRunCauseList
            1..15 | ForEach-Object { Add-VsRunCause -Causes $causes -Cause 'mac_missing' -Target ('VM{0:D2}' -f $_) }
            Format-VsRunDetail -Causes $causes
        }

        ([regex]::Matches($detail, 'mac_missing')).Count | Should -Be 10
        $detail | Should -Match '\(\+5 weitere\)'
    }

    It 'liefert $null fuer einen Lauf ohne Ursachen' {
        # Sonst sendet ein sauberer Lauf ein leeres Detail, und die Karte klappt
        # einen leeren Block auf.
        $detail = Invoke-InFileScope -Path $script:MecmCommon -Body {
            Format-VsRunDetail -Causes (New-VsRunCauseList)
        }

        $detail | Should -BeNullOrEmpty
    }
}

# Der Takt, in dem eine Aufgabe laeuft, und der Takt, den sie meldet, muessen
# dieselbe Zahl sein: die Statusseite faerbt die Zeile ab dem Dreifachen der
# GEMELDETEN Zahl ein, und die Hilfe verspricht, dass dort der Wert steht, der
# "tatsaechlich gilt". Drei der vier Skripte klemmten frueher nur nach unten,
# waehrend Send-VsRunReport beidseitig klemmt - ein Intervall ueber 3600 s liess
# die Aufgabe langsamer laufen als die Seite behauptete, ab 3 h dauerhaft gelb.
Describe 'Intervall-Aufloesung (Resolve-VsInterval und ihre Spiegel)' {
    BeforeAll {
        $script:Installer = Join-Path $script:PsRoot 'install-VirtuSphere-MECM.ps1'

        function Get-InstallerParameter {
            param([string]$Name)
            $tokens = $null; $errors = $null
            $ast = [System.Management.Automation.Language.Parser]::ParseFile(
                $script:Installer, [ref]$tokens, [ref]$errors)
            $ast.ParamBlock.Parameters | Where-Object { $_.Name.VariablePath.UserPath -eq $Name }
        }

        function Get-ValidateRange {
            param([string]$Name)
            $attribute = (Get-InstallerParameter -Name $Name).Attributes |
                Where-Object { $_.TypeName.Name -eq 'ValidateRange' }
            if (-not $attribute) { return $null }
            @{
                Min = [int]$attribute.PositionalArguments[0].Value
                Max = [int]$attribute.PositionalArguments[1].Value
            }
        }

        function Get-Bounds {
            Invoke-InFileScope -Path $script:MecmCommon -Body {
                $out = @{}
                foreach ($key in $script:VsIntervalBounds.Keys) {
                    $out[$key] = @{
                        Floor   = [int]$script:VsIntervalBounds[$key].Floor
                        Setting = [string]$script:VsIntervalBounds[$key].Setting
                        Ceiling = [int]$script:VsRunIntervalMaxSeconds
                    }
                }
                $out
            }
        }

        function Invoke-Resolve {
            param([string]$Source, [int]$Configured)
            Invoke-InFileScope -Path $script:MecmCommon -Arguments @($Source, $Configured) -Body {
                param($s, $c)
                Initialize-VsLog -Component 'pester-interval' -LogRoot ([System.IO.Path]::GetTempPath())
                Resolve-VsInterval -Source $s -Configured $c
            }
        }

        # Faengt ab, was Resolve-VsInterval protokolliert, statt es in die
        # Tagesdatei zu schreiben.
        function Get-ClampLog {
            param([int]$Configured)
            Invoke-InFileScope -Path $script:MecmCommon -Arguments @($Configured) -Body {
                param($c)
                Initialize-VsLog -Component 'pester-interval' -LogRoot ([System.IO.Path]::GetTempPath())
                $script:lines = @()
                function Write-VsLog { param($Message, $Level) $script:lines += ('{0}|{1}' -f $Level, $Message) }
                Resolve-VsInterval -Source 'autoimporter' -Configured $c | Out-Null
                [pscustomobject]@{ Lines = @($script:lines) }
            }
        }
    }

    It 'laesst einen Wert innerhalb der Spanne unveraendert: <source> <value>' -ForEach @(
        @{ source = 'device-sync';      value = 10 }
        @{ source = 'packages-sync';    value = 60 }
        @{ source = 'autoimporter';     value = 60 }
        @{ source = 'mecm-site-health'; value = 300 }
        @{ source = 'device-sync';      value = 3600 }
    ) {
        Invoke-Resolve -Source $source -Configured $value | Should -Be $value
    }

    It 'hebt einen Wert unter der Untergrenze auf die Untergrenze: <source>' -ForEach @(
        @{ source = 'device-sync';      floor = 5 }
        @{ source = 'packages-sync';    floor = 10 }
        @{ source = 'autoimporter';     floor = 30 }
        @{ source = 'mecm-site-health'; floor = 60 }
    ) {
        Invoke-Resolve -Source $source -Configured 1 | Should -Be $floor
    }

    It 'senkt einen Wert ueber der Obergrenze auf 3600: <source>' -ForEach @(
        @{ source = 'device-sync' }
        @{ source = 'packages-sync' }
        @{ source = 'autoimporter' }
        @{ source = 'mecm-site-health' }
    ) {
        # Der Fall, der die Statusseite luegen liess: 7200 geschlafen, 3600 gemeldet.
        Invoke-Resolve -Source $source -Configured 7200 | Should -Be 3600
    }

    It 'protokolliert die Korrektur samt Registry-Wert (kein stilles Klemmen)' {
        # Ein pscustomobject als Huelle: eine nackte Liste wuerde die Pipeline
        # entrollen, und eine leere Liste kaeme als $null zurueck - der Test
        # "schweigt" darunter waere dann nicht falsifizierbar.
        $logged = Get-ClampLog -Configured 7200
        @($logged.Lines).Count | Should -Be 1
        # WARN, nicht INFO: eine stillschweigend geaenderte Einstellung soll im
        # Tageslog auffallen, nicht zwischen den Laufmeldungen verschwinden.
        $logged.Lines[0] | Should -Match '^WARN\|'
        $logged.Lines[0] | Should -Match '7200'
        $logged.Lines[0] | Should -Match '3600'
        $logged.Lines[0] | Should -Match 'ImporterIntervalSeconds'
    }

    It 'schweigt, wenn nichts zu korrigieren war' {
        @((Get-ClampLog -Configured 60).Lines).Count | Should -Be 0
    }

    It 'wirft bei einer unbekannten Quelle (Tippfehler faellt im Test auf, nicht nachts)' {
        { Invoke-Resolve -Source 'device_sync' -Configured 10 } | Should -Throw
    }

    It '<name> holt sein Intervall ueber Resolve-VsInterval, nicht ueber eine eigene Klammer' -ForEach @(
        @{ name = 'mecm_new-device-sync.ps1' }
        @{ name = 'mecm_Packages-TaskSeq-sync.ps1' }
        @{ name = 'mecm_autoimporter.ps1' }
        @{ name = 'mecm_site-health.ps1' }
    ) {
        $text = Get-ScriptText -Name $name
        $text | Should -Match 'Resolve-VsInterval'
        # Eine eigene Math-Klammer waere wieder eine zweite Spanne neben der Tabelle.
        $text | Should -Not -Match '\$intervalSeconds\s*=\s*\[Math\]'
    }

    It '<name> meldet genau den Takt, in dem es schlaeft' -ForEach @(
        @{ name = 'mecm_new-device-sync.ps1' }
        @{ name = 'mecm_Packages-TaskSeq-sync.ps1' }
        @{ name = 'mecm_autoimporter.ps1' }
        @{ name = 'mecm_site-health.ps1' }
    ) {
        $text = Get-ScriptText -Name $name
        # Jede Uebergabe von IntervalSeconds - als Parameter (-IntervalSeconds)
        # oder als Hashtable-Schluessel am Zeilenanfang - traegt die Variable,
        # die auch den Sleep speist. Das Muster nimmt bewusst jedes Argument
        # ($\S+), nicht nur eine Variable: ein Literal waere sonst kein Treffer
        # und der Test schwiege genau zu dem Fall, den er verhindern soll.
        # Erwaehnungen in Kommentaren (SiteHealthIntervalSeconds) faengt es
        # nicht, weil dort weder ein '-' davor noch ein '=' dahinter steht.
        $passed = [regex]::Matches($text, '(?m)(?:^\s*|-)IntervalSeconds\s*=?\s*(\S+)')
        @($passed).Count | Should -BeGreaterThan 0
        foreach ($match in $passed) {
            $match.Groups[1].Value | Should -Be '$intervalSeconds'
        }
        $text | Should -Match '\$sleepSeconds\s*=\s*\$intervalSeconds|Start-Sleep -Seconds \$intervalSeconds'
    }

    It 'der Installer lehnt ausserhalb der Spanne ab, statt sie spaeter still zu klemmen: <source>' -ForEach @(
        @{ source = 'device-sync' }
        @{ source = 'packages-sync' }
        @{ source = 'autoimporter' }
        @{ source = 'mecm-site-health' }
    ) {
        $bounds = (Get-Bounds)[$source]
        $range = Get-ValidateRange -Name $bounds.Setting
        $range | Should -Not -BeNullOrEmpty -Because "$($bounds.Setting) braucht ein ValidateRange"
        $range.Min | Should -Be $bounds.Floor
        $range.Max | Should -Be $bounds.Ceiling
    }

    It 'die Standardwerte von Installer und Get-VsConfig sind dieselben: <setting>' -ForEach @(
        @{ setting = 'DeviceSyncIntervalSeconds';   property = 'DeviceSyncInterval';   expected = 10 }
        @{ setting = 'PackagesSyncIntervalSeconds'; property = 'PackagesSyncInterval'; expected = 60 }
        @{ setting = 'ImporterIntervalSeconds';     property = 'ImporterInterval';     expected = 60 }
        @{ setting = 'SiteHealthIntervalSeconds';   property = 'SiteHealthInterval';   expected = 300 }
    ) {
        # Die Doku-Tabelle (docs/operations/mecm-integration.md) nennt dieselben
        # Zahlen; der Installer ist die SSoT, Get-VsConfig nur der Notnagel.
        $default = (Get-InstallerParameter -Name $setting).DefaultValue.Extent.Text
        [int]$default | Should -Be $expected

        $fallback = Invoke-InFileScope -Path $script:MecmCommon -Arguments @($property) -Body {
            param($prop)
            $script:VsRegistryPath = 'HKCU:\Software\_vs_interval_absent_' + [guid]::NewGuid().ToString('N')
            # Ohne VirtuSphere_WebAPI liefert Get-VsConfig $null; der Fallback
            # wird deshalb ueber einen Schluessel MIT Adresse und OHNE Intervall
            # gelesen.
            New-Item -Path $script:VsRegistryPath -Force | Out-Null
            New-ItemProperty -Path $script:VsRegistryPath -Name 'VirtuSphere_WebAPI' `
                -Value 'virtusphere.lan:8021' -PropertyType String -Force | Out-Null
            $value = (Get-VsConfig).$prop
            Remove-Item -Path $script:VsRegistryPath -Recurse -Force -ErrorAction SilentlyContinue
            $value
        }
        $fallback | Should -Be $expected
    }

    It 'jeder Setting-Name der Tabelle ist ein echter Installer-Parameter' {
        foreach ($entry in (Get-Bounds).Values) {
            Get-InstallerParameter -Name $entry.Setting | Should -Not -BeNullOrEmpty
        }
    }
}
