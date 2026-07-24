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
    BeforeAll {
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
    }

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
