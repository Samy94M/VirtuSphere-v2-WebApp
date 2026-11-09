# Pester-Suite fuer die Standortteile des Site-Health-Reporters (ADR-0018,
# Amendment 2026-11-09): Zaehlung der Rollen und Komponenten, die MECM als
# Warnung oder kritisch fuehrt, je Typname und Status. Der Reporter bewertet
# nicht; die Tabelle Kernteil/unterstuetzend liegt allein im Portal
# (Docker/WebAPI/lib/mecm_site_assessment.php). Diese Tests pinnen die Zaehlung,
# den Spiegel der Wire-Grenzen aus lib/mecm_site_parts.php und die Form im Body.
# Testdaten sind synthetisch (Site P01, Provider CM01).

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:MecmCommon = Join-Path (Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'mecm') 'VirtuSphere-Common.ps1'
    $script:SiteHealthScript = Join-Path (Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'mecm') 'mecm_site-health.ps1'
    $script:Constants = Get-Content -Raw -Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'Docker') 'WebAPI') 'lib/mecm_site_parts.php')

    function Invoke-InFileScope {
        param([string]$Path, [scriptblock]$Body, [object[]]$Arguments = @())
        & {
            param($p, $b, $a)
            . $p
            & $b @a
        } $Path $Body $Arguments
    }

    # Eine Summarizer-Zeile. Ein Servername steht in echten Zeilen daneben; er
    # fasst Zeilen zusammen, darf aber nie im Ergebnis ankommen. Ohne -Instance
    # bekommt jede Zeile einen eigenen Server.
    $script:RowSeq = 0
    function New-Row {
        param([string]$Name, [int]$Status, [string]$Site = 'P01', [string]$Property = 'Role', [string]$Instance)
        $script:RowSeq++
        if (-not $Instance) { $Instance = 'host-must-not-leak-{0}.example.test' -f $script:RowSeq }
        $row = [ordered]@{ SiteCode = $Site; Status = $Status }
        $row[$Property] = $Name
        $row['SiteSystem'] = $Instance
        $row['MachineName'] = $Instance
        return [pscustomobject]$row
    }

    function Invoke-PartList {
        param([object[]]$Rows, [string]$Property = 'Role')
        $instance = if ($Property -eq 'Role') { 'SiteSystem' } else { 'MachineName' }
        Invoke-InFileScope -Path $script:MecmCommon -Arguments @($Rows, $Property, $instance) -Body {
            param($items, $nameProperty, $instanceProperty)
            ConvertTo-VsSitePartList -Rows $items -NameProperty $nameProperty -InstanceProperty $instanceProperty -SiteCode 'P01'
        }
    }
}

Describe 'Spiegel der Wire-Grenzen (lib/mecm_site_parts.php)' {
    It 'Namensmuster, Hoechstzahl und Status entsprechen VIRTUSPHERE_SITE_PART_*' {
        $phpPattern = [regex]::Match($script:Constants, "VIRTUSPHERE_SITE_PART_NAME_PATTERN\s*=\s*'/(.+?)/';").Groups[1].Value
        $phpMax = [int]([regex]::Match($script:Constants, 'VIRTUSPHERE_SITE_PART_MAX_ENTRIES\s*=\s*(\d+)').Groups[1].Value)
        $phpPattern | Should -Not -BeNullOrEmpty
        $phpMax | Should -BeGreaterThan 0
        $ps = Invoke-InFileScope -Path $script:MecmCommon -Body {
            [pscustomobject]@{ Pattern = $script:VsSitePartNamePattern; Max = $script:VsSitePartMaxEntries; Statuses = $script:VsSitePartStatuses }
        }
        $ps.Pattern | Should -BeExactly $phpPattern
        $ps.Max | Should -Be $phpMax
        ($ps.Statuses -join ',') | Should -Be '1,2'
        $script:Constants | Should -Match 'VIRTUSPHERE_SITE_PART_STATUS_WARNING\s*=\s*1;'
        $script:Constants | Should -Match 'VIRTUSPHERE_SITE_PART_STATUS_CRITICAL\s*=\s*2;'
    }

    It 'das Namensmuster laesst keinen FQDN, Pfad, Account oder Zeilenumbruch durch: <name>' -ForEach @(
        @{ name = 'FQDN'; value = 'cm01.example.test' }
        @{ name = 'Pfad'; value = 'SERVER\share' }
        @{ name = 'Account'; value = 'svc@example' }
        @{ name = 'Zeilenumbruch am Ende'; value = "SMS Distribution Point`n" }
        @{ name = 'leer'; value = '' }
        @{ name = 'zu lang'; value = ('A' * 65) }
    ) {
        $pattern = Invoke-InFileScope -Path $script:MecmCommon -Body { $script:VsSitePartNamePattern }
        $value -match $pattern | Should -BeFalse
    }
}

Describe 'ConvertTo-VsSitePartList (reine Zaehlung)' {
    It 'zaehlt je Typname und Status, kritisch zuerst, nur die konfigurierte Site' {
        $rows = @(
            (New-Row 'SMS Distribution Point' 2), (New-Row 'SMS Distribution Point' 2),
            (New-Row 'SMS Distribution Point' 2), (New-Row 'SMS Distribution Point' 2),
            (New-Row 'SMS Distribution Point' 1),
            (New-Row 'SMS Management Point' 0),
            (New-Row 'SMS Distribution Point' 2 -Site 'X99')
        )
        $r = Invoke-PartList -Rows $rows
        $r.Omitted | Should -Be 0
        @($r.Entries).Count | Should -Be 2
        $r.Entries[0].name | Should -Be 'SMS Distribution Point'
        $r.Entries[0].status | Should -Be 2
        $r.Entries[0].count | Should -Be 4
        $r.Entries[1].status | Should -Be 1
        $r.Entries[1].count | Should -Be 1
        ($r | ConvertTo-Json -Depth 5) | Should -Not -Match 'must-not-leak'
    }

    It 'sendet einen Namen ausserhalb des Musters nie und zaehlt ihn in Omitted' {
        $rows = @((New-Row 'srv01.example.test' 2), (New-Row 'SMS Provider' 2))
        $r = Invoke-PartList -Rows $rows
        @($r.Entries).Count | Should -Be 1
        $r.Entries[0].name | Should -Be 'SMS Provider'
        $r.Omitted | Should -Be 1
    }

    It 'kappt bei der Hoechstzahl und zaehlt die abgeschnittenen Zeilen in Omitted' {
        $rows = @()
        for ($i = 0; $i -lt 30; $i++) { $rows += New-Row ('SMS_COMPONENT_{0:D2}' -f $i) 1 -Property 'ComponentName' }
        $rows += New-Row 'SMS_EXECUTIVE' 2 -Property 'ComponentName'
        $max = Invoke-InFileScope -Path $script:MecmCommon -Body { $script:VsSitePartMaxEntries }
        $list = Invoke-PartList -Rows $rows -Property 'ComponentName'
        @($list.Entries).Count | Should -Be $max
        $list.Entries[0].name | Should -Be 'SMS_EXECUTIVE'
        $list.Omitted | Should -Be (31 - $max)
    }

    It 'ueberspringt Status 0, unlesbaren Status und leere Zeilen' {
        $rows = @((New-Row 'SMS Provider' 0), [pscustomobject]@{ SiteCode = 'P01'; Status = 'rot'; Role = 'SMS Provider' }, $null)
        $r = Invoke-PartList -Rows $rows
        @($r.Entries).Count | Should -Be 0
        $r.Omitted | Should -Be 0
    }

    It 'zaehlt je Rolle und Server einmal mit dem schlechtesten Status (mehrere Speicherobjekte)' {
        # Zwei DPs; der erste hat zwei Laufwerke (Warnung und kritisch), der zweite
        # zwei kritische Laufwerke. Ergebnis: 2 Verteilungspunkte kritisch, keine Warnung.
        $rows = @(
            (New-Row 'SMS Distribution Point' 1 -Instance 'dp-a'),
            (New-Row 'SMS Distribution Point' 2 -Instance 'dp-a'),
            (New-Row 'SMS Distribution Point' 2 -Instance 'dp-b'),
            (New-Row 'SMS Distribution Point' 2 -Instance 'dp-b')
        )
        $r = Invoke-PartList -Rows $rows
        @($r.Entries).Count | Should -Be 1
        $r.Entries[0].status | Should -Be 2
        $r.Entries[0].count | Should -Be 2
        ($r | ConvertTo-Json -Depth 5) | Should -Not -Match 'dp-a|dp-b'
    }

    It 'zaehlt eine Komponente je Rechner einmal, auf zwei Rechnern zweimal' {
        $rows = @(
            (New-Row 'SMS_EXECUTIVE' 2 -Property 'ComponentName' -Instance 'mp-1'),
            (New-Row 'SMS_EXECUTIVE' 1 -Property 'ComponentName' -Instance 'mp-1'),
            (New-Row 'SMS_EXECUTIVE' 2 -Property 'ComponentName' -Instance 'mp-2')
        )
        $r = Invoke-PartList -Rows $rows -Property 'ComponentName'
        @($r.Entries).Count | Should -Be 1
        $r.Entries[0].count | Should -Be 2
    }

    It 'zaehlt Zeilen ohne Instanz einzeln' {
        $rows = @(
            [pscustomobject]@{ SiteCode = 'P01'; Status = 2; Role = 'SMS Provider' },
            [pscustomobject]@{ SiteCode = 'P01'; Status = 2; Role = 'SMS Provider' }
        )
        (Invoke-PartList -Rows $rows).Entries[0].count | Should -Be 2
    }
}

Describe 'Get-VsMecmSiteParts (Abfrage ohne Servernamen)' {
    It 'fragt beide Summarizer mit Status<>0 ab, Komponenten in der Konsolenansicht' {
        $r = Invoke-InFileScope -Path $script:MecmCommon -Body {
            Initialize-VsLog -Component 'pester-siteparts' -LogRoot ([System.IO.Path]::GetTempPath())
            $script:calls = @()
            function Get-CimInstance {
                param($Namespace, $ClassName, $Filter, $ComputerName, $ErrorAction)
                $script:calls += [pscustomobject]@{ Class = $ClassName; Filter = $Filter; Namespace = $Namespace; Computer = $ComputerName }
                if ($ClassName -eq 'SMS_SiteSystemSummarizer') {
                    return @([pscustomobject]@{ SiteCode = 'P01'; Status = 2; Role = 'SMS Distribution Point'; SiteSystem = 'x' })
                }
                return @([pscustomobject]@{ SiteCode = 'P01'; Status = 2; ComponentName = 'SMS_DISTRIBUTION_MANAGER'; MachineName = 'x' })
            }
            $parts = Get-VsMecmSiteParts -SiteCode 'P01' -Provider 'CM01'
            [pscustomobject]@{ Parts = $parts; Calls = $script:calls; Tally = $script:VsComponentTallyInterval }
        }
        $r.Tally | Should -Be '0001128000100008'
        @($r.Calls).Count | Should -Be 2
        ($r.Calls | Where-Object Class -eq 'SMS_SiteSystemSummarizer').Filter | Should -Be 'Status <> 0'
        ($r.Calls | Where-Object Class -eq 'SMS_ComponentSummarizer').Filter | Should -Be "TallyInterval = '0001128000100008' AND Status <> 0"
        ($r.Calls | ForEach-Object Namespace | Select-Object -Unique) | Should -Be 'root\SMS\site_P01'
        ($r.Calls | ForEach-Object Computer | Select-Object -Unique) | Should -Be 'CM01'
        $r.Parts['roles'][0].name | Should -Be 'SMS Distribution Point'
        $r.Parts['components'][0].name | Should -Be 'SMS_DISTRIBUTION_MANAGER'
        $r.Parts['omitted'] | Should -Be 0
    }

    It 'liefert bei einem Abfragefehler $null und genau eine WARN-Zeile' {
        $logRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('vs-siteparts-' + [guid]::NewGuid().ToString('N'))
        $parts = Invoke-InFileScope -Path $script:MecmCommon -Arguments @($logRoot) -Body {
            param($root)
            Initialize-VsLog -Component 'pester-siteparts' -LogRoot $root
            function Get-CimInstance { throw 'Access is denied' }
            Get-VsMecmSiteParts -SiteCode 'P01' -Provider 'CM01'
        }
        $parts | Should -BeNullOrEmpty
        $lines = @(Get-ChildItem -Path $logRoot -Filter '*.log' -File | Get-Content | Where-Object { $_ -match 'WARN' -and $_ -match 'Rollen-/Komponentenstatus' })
        $lines.Count | Should -Be 1
        Remove-Item -Path $logRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}

Describe 'Send-VsRunReport mit site_parts' {
    BeforeAll {
        function Get-SentSiteBody {
            param([hashtable]$Params)
            Invoke-InFileScope -Path $script:MecmCommon -Arguments @($Params) -Body {
                param($p)
                Initialize-VsLog -Component 'pester-siteparts' -LogRoot ([System.IO.Path]::GetTempPath())
                $script:captured = $null
                function Invoke-VsApi { param($Config, $Path, $Method, $Body, $TimeoutSec) $script:captured = $Body }
                Send-VsRunReport @p
                [pscustomobject]@{ Body = $script:captured; Bytes = (ConvertTo-VsUtf8JsonBytes -Value $script:captured -Depth 6).Length; Limit = $script:VsClientEventBodyMaxBytes }
            }
        }
        $script:Base = @{
            Config = [pscustomobject]@{ ReportToken = 'T' }; RunEvent = 'completed'
            RunId = '0123456789abcdef0123456789abcdef'; IntervalSeconds = 300; Outcome = 'fail'; ErrorCategory = 'site_critical'
        }
    }

    It 'legt site_parts als eigenes Top-Level-Feld neben summary (nie in summary)' {
        $p = $script:Base.Clone()
        $p['Source'] = 'mecm-site-health'
        $p['Summary'] = @{ site_code = 'P01'; raw_status = 2 }
        $p['SiteParts'] = [ordered]@{ roles = @([ordered]@{ name = 'SMS Distribution Point'; status = 2; count = 4 }); components = @(); omitted = 0 }
        $r = Get-SentSiteBody -Params $p
        $r.Body.Contains('site_parts') | Should -BeTrue
        $r.Body['summary'].ContainsKey('site_parts') | Should -BeFalse
        $json = [Text.Encoding]::UTF8.GetString((Invoke-InFileScope -Path $script:MecmCommon -Arguments @($r.Body) -Body { param($b) ConvertTo-VsUtf8JsonBytes -Value $b -Depth 6 }))
        $decoded = $json | ConvertFrom-Json
        @($decoded.site_parts.roles).Count | Should -Be 1
        $decoded.site_parts.roles[0].count | Should -Be 4
        # Eine Liste mit einem Eintrag bleibt eine JSON-Liste (kein einzelnes
        # Objekt), eine leere bleibt [] (kein null): PHP verlangt beide als Liste.
        $json | Should -Match '"roles":\s*\[\s*\{'
        $json | Should -Match '"components":\s*\[\s*\]'
    }

    It 'sendet site_parts nie fuer eine Sync-Quelle' {
        $p = $script:Base.Clone()
        $p['Source'] = 'device-sync'
        $p['ErrorCategory'] = 'mecm_unavailable'
        $p['SiteParts'] = [ordered]@{ roles = @(); components = @(); omitted = 0 }
        (Get-SentSiteBody -Params $p).Body.Contains('site_parts') | Should -BeFalse
    }

    It 'haelt den Body auch bei 48 langen Eintraegen im Portallimit und zaehlt Entferntes in omitted' {
        $roles = @(); $components = @()
        for ($i = 0; $i -lt 24; $i++) {
            $roles += [ordered]@{ name = ('R' + ('x' * 58) + ('{0:D2}' -f $i)); status = 2; count = 1000000 }
            $components += [ordered]@{ name = ('C' + ('y' * 58) + ('{0:D2}' -f $i)); status = 1; count = 1000000 }
        }
        $p = $script:Base.Clone()
        $p['Source'] = 'mecm-site-health'
        $p['SiteParts'] = [ordered]@{ roles = $roles; components = $components; omitted = 0 }
        $r = Get-SentSiteBody -Params $p
        $r.Bytes | Should -BeLessOrEqual $r.Limit
        $sent = @($r.Body['site_parts']['roles']).Count + @($r.Body['site_parts']['components']).Count
        if ($sent -lt 48) { [int]$r.Body['site_parts']['omitted'] | Should -Be ((48 - $sent) * 1000000) }
    }
}

Describe 'Send-VsRunReport Rueckfall bei 400 (F7)' {
    BeforeAll {
        function Invoke-RejectedReport {
            param([hashtable]$Params, [int]$Status, [int]$FailCalls = 1)
            $logRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('vs-siteparts-' + [guid]::NewGuid().ToString('N'))
            $r = Invoke-InFileScope -Path $script:MecmCommon -Arguments @($Params, $Status, $FailCalls, $logRoot) -Body {
                param($p, $code, $failCalls, $root)
                Initialize-VsLog -Component 'pester-siteparts' -LogRoot $root
                $script:calls = @()
                $script:rejectStatus = $code
                $script:failCalls = $failCalls
                function Get-VsErrorStatusCode { param($ErrorRecord) $script:rejectStatus }
                function Invoke-VsApi {
                    param($Config, $Path, $Method, $Body, $TimeoutSec)
                    $script:calls += [pscustomobject]@{ HasParts = $Body.Contains('site_parts'); Category = $Body['error_category']; Outcome = $Body['outcome'] }
                    if ($script:calls.Count -le $script:failCalls) { throw 'Bad Request' }
                }
                Send-VsRunReport @p
                [pscustomobject]@{ Calls = $script:calls }
            }
            $warn = @(Get-ChildItem -Path $logRoot -Filter '*.log' -File | Get-Content | Where-Object { $_ -match 'WARN' -and $_ -match 'site_parts abgelehnt' })
            Remove-Item -Path $logRoot -Recurse -Force -ErrorAction SilentlyContinue
            [pscustomobject]@{ Calls = $r.Calls; WarnCount = $warn.Count }
        }
        $script:SiteParams = @{
            Config = [pscustomobject]@{ ReportToken = 'T' }; RunEvent = 'completed'; Source = 'mecm-site-health'
            RunId = '0123456789abcdef0123456789abcdef'; IntervalSeconds = 300; Outcome = 'fail'; ErrorCategory = 'site_critical'
            SiteParts = [ordered]@{ roles = @([ordered]@{ name = 'SMS Distribution Point'; status = 2; count = 2 }); components = @(); omitted = 0 }
        }
    }

    It 'sendet nach 400 genau einmal die Basismeldung ohne site_parts, mit demselben Urteil' {
        $r = Invoke-RejectedReport -Params $script:SiteParams.Clone() -Status 400
        @($r.Calls).Count | Should -Be 2
        $r.Calls[0].HasParts | Should -BeTrue
        $r.Calls[1].HasParts | Should -BeFalse
        $r.Calls[1].Outcome | Should -Be 'fail'
        $r.Calls[1].Category | Should -Be 'site_critical'
        $r.WarnCount | Should -Be 1
    }

    It 'wiederholt nicht bei anderem Fehlerstatus' {
        $r = Invoke-RejectedReport -Params $script:SiteParams.Clone() -Status 503
        @($r.Calls).Count | Should -Be 1
        $r.WarnCount | Should -Be 0
    }

    It 'wiederholt nicht, wenn der Bericht keine site_parts trug' {
        $p = $script:SiteParams.Clone()
        $p.Remove('SiteParts')
        $r = Invoke-RejectedReport -Params $p -Status 400
        @($r.Calls).Count | Should -Be 1
    }

    It 'versucht die Basismeldung nur einmal, auch wenn sie ebenfalls scheitert' {
        $r = Invoke-RejectedReport -Params $script:SiteParams.Clone() -Status 400 -FailCalls 2
        @($r.Calls).Count | Should -Be 2
    }
}

Describe 'Limit-VsSitePartsToBody (Kuerzung bei knapper Grenze)' {
    It 'entfernt vom Ende der laengeren Liste, bis der Body passt, und addiert die Zaehler in omitted' {
        $r = Invoke-InFileScope -Path $script:MecmCommon -Body {
            $script:VsClientEventBodyMaxBytes = 900
            $roles = @(); $components = @()
            for ($i = 0; $i -lt 6; $i++) { $roles += [ordered]@{ name = ('SMS Role {0:D2}' -f $i); status = 2; count = 1 } }
            for ($i = 0; $i -lt 6; $i++) { $components += [ordered]@{ name = ('SMS_COMPONENT_{0:D2}' -f $i); status = 1; count = 3 } }
            $body = [ordered]@{ source = 'mecm-site-health'; event = 'completed'; site_parts = [ordered]@{ roles = $roles; components = $components; omitted = 0 } }
            Limit-VsSitePartsToBody -Body $body
            [pscustomobject]@{ Body = $body; Bytes = (Get-VsRunReportBodyBytes -Body $body -Detail $null) }
        }
        $r.Bytes | Should -BeLessOrEqual 900
        $parts = $r.Body['site_parts']
        $roles = @($parts['roles']); $components = @($parts['components'])
        ($roles.Count + $components.Count) | Should -BeLessThan 12
        $expectedOmitted = (6 - $roles.Count) * 1 + (6 - $components.Count) * 3
        [int]$parts['omitted'] | Should -Be $expectedOmitted
        # Die kritischen Rollen am Listenanfang bleiben am laengsten erhalten.
        if ($roles.Count -gt 0) { $roles[0].name | Should -Be 'SMS Role 00' }
    }
}

Describe 'mecm_site-health.ps1 meldet die Teile nur bei Warnung oder kritisch' {
    It 'ruft Get-VsMecmSiteParts nur im warning/fail-Zweig und reicht SiteParts an den Report' {
        $text = Get-Content -Raw -Path $script:SiteHealthScript
        $text | Should -Match "if \(\`$outcome -eq 'warning' -or \`$outcome -eq 'fail'\) \{\s+\`$siteParts = Get-VsMecmSiteParts"
        $text | Should -Match "\`$reportParams\['SiteParts'\] = \`$siteParts"
        $text | Should -Match "\`$siteParts = \`$null"
    }
}
