# Waechter fuer docs/operations/client-flows.md (FC4). Nichts ist hier
# aufgezaehlt: Anwendungen, Rollen, Abhaengigkeiten und Erkennungswerte kommen
# aus Get-VsClientAppSpecs, Phasen und Ereignisse aus dem ValidateSet von
# Send-VsPhase, die Zuordnung Phase -> Skript aus den Send-VsPhase-Aufrufen der
# Clientskripte. Nur ASCII in dieser Datei (PS 5.1 liest UTF-8 ohne BOM als ANSI).

BeforeAll {
    $script:RepoRoot = $PSScriptRoot
    while ($script:RepoRoot -and -not (Test-Path -LiteralPath (Join-Path $script:RepoRoot 'AGENTS.md'))) {
        $parent = Split-Path $script:RepoRoot -Parent
        if ($parent -eq $script:RepoRoot) { break }
        $script:RepoRoot = $parent
    }
    if (-not (Test-Path -LiteralPath (Join-Path $script:RepoRoot 'AGENTS.md'))) { throw 'Repository root not found.' }
    $script:ClientsDir = Join-Path $script:RepoRoot 'Powershell-MECM/clients'
    . (Join-Path $script:RepoRoot 'Powershell-MECM/mecm/VirtuSphere-ClientPackaging.ps1')

    function Get-DocSection {
        param([string]$Doc, [string]$Heading)
        $parts = [regex]::Split($Doc, '(?m)^## ')
        foreach ($part in ($parts | Select-Object -Skip 1)) {
            $newline = $part.IndexOf("`n")
            $title = if ($newline -lt 0) { $part.Trim() } else { $part.Substring(0, $newline).Trim() }
            if ($title -ceq $Heading) {
                if ($newline -lt 0) { return '' }
                return $part.Substring($newline + 1)
            }
        }
        return ''
    }

    function Get-MermaidBlocks {
        param([string]$Text)
        @([regex]::Matches($Text, '(?s)```mermaid\s*\n(.*?)```') | ForEach-Object { $_.Groups[1].Value })
    }

    # Zeilen aller Tabellen ohne Kopf und Trennzeile; erste Zeile jeder Tabelle ist der Kopf.
    function Get-TableRows {
        param([string]$Text, [switch]$Header)
        $rows = New-Object System.Collections.Generic.List[object]
        $isHeader = $true
        foreach ($line in ($Text -split "`n")) {
            if (-not $line.StartsWith('|')) { $isHeader = $true; continue }
            $cells = @($line.Trim().Trim('|').Split('|') | ForEach-Object { $_.Trim() })
            if ($isHeader) {
                $isHeader = $false
                if ($Header) { $rows.Add($cells) }
                continue
            }
            if ($line -match '^\|[\s:|-]+\|$') { continue }
            if (-not $Header) { $rows.Add($cells) }
        }
        return $rows.ToArray()
    }

    function Get-Ticks {
        param([string]$Cell)
        @([regex]::Matches($Cell, '`([^`]+)`') | ForEach-Object { $_.Groups[1].Value })
    }

    function Get-SendPhaseSets {
        $common = Join-Path $script:ClientsDir 'VirtuSphere-Client-Common.ps1'
        $tokens = $null; $errors = $null
        $ast = [System.Management.Automation.Language.Parser]::ParseFile($common, [ref]$tokens, [ref]$errors)
        $fn = $ast.Find({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'Send-VsPhase' }, $true)
        if ($null -eq $fn) { throw 'Send-VsPhase not found.' }
        $sets = @{}
        foreach ($p in $fn.Body.ParamBlock.Parameters) {
            foreach ($a in $p.Attributes) {
                if ($a.TypeName.Name -eq 'ValidateSet') {
                    $sets[$p.Name.VariablePath.UserPath] = @($a.PositionalArguments | ForEach-Object { $_.Value })
                }
            }
        }
        return $sets
    }

    # Phase -> Skriptname aus den Aufrufen `Send-VsPhase ... -Phase '<phase>'`.
    function Get-PhaseScripts {
        $map = @{}
        foreach ($file in Get-ChildItem -LiteralPath $script:ClientsDir -Filter 'client_*.ps1') {
            $text = [IO.File]::ReadAllText($file.FullName)
            foreach ($m in [regex]::Matches($text, "Send-VsPhase\b[^\r\n]*?-Phase '([a-z]+)'")) {
                $phase = $m.Groups[1].Value
                if ($map.ContainsKey($phase) -and $map[$phase] -ne $file.BaseName) { throw "Phase $phase is sent by two scripts." }
                $map[$phase] = $file.BaseName
            }
        }
        return $map
    }

    function Get-ClientFlowsDocProblems {
        param([string]$Doc, [object[]]$Specs, [string[]]$Phases, [string[]]$Events, [hashtable]$PhaseScripts)
        $problems = New-Object System.Collections.Generic.List[string]
        if (@($Specs).Count -eq 0) { $problems.Add('No client applications derived from code (zero-match must not pass).'); return $problems.ToArray() }
        if (@($Phases).Count -eq 0) { $problems.Add('No phases derived from code (zero-match must not pass).'); return $problems.ToArray() }

        $order = Get-DocSection -Doc $Doc -Heading 'Anwendungen und Reihenfolge'
        $charts = @(Get-MermaidBlocks -Text $order)
        if ($charts.Count -ne 1) { $problems.Add("The order section must contain 1 mermaid block, found $($charts.Count).") }
        $chart = ($charts -join "`n")
        $rows = @(Get-TableRows -Text $order)
        foreach ($spec in $Specs) {
            $name = [string]$spec.AppName
            if ($chart -notmatch ('(?m)(?<![A-Za-z0-9_])' + [regex]::Escape($name) + '\["' + [regex]::Escape($name) + '"\]')) {
                $problems.Add("Application $name has no node.")
            }
            if ($spec.DependsOn) {
                $edge = '(?m)^\s*' + [regex]::Escape([string]$spec.DependsOn) + '(\["[^"]*"\])?\s*-->\s*' + [regex]::Escape($name) + '\b'
                if ($chart -notmatch $edge) { $problems.Add("Dependency $($spec.DependsOn) --> $name is not drawn.") }
            }
            $matching = @($rows | Where-Object { (@(Get-Ticks -Cell $_[0]))[0] -ceq $name })
            if ($matching.Count -ne 1) { $problems.Add("Application $name has $($matching.Count) table rows."); continue }
            $row = $matching[0]
            if ((@(Get-Ticks -Cell $row[1]) -join ',') -cne [string]$spec.Role) { $problems.Add("Application $name names role $($row[1]) instead of $($spec.Role).") }
            $dependsOn = @(Get-Ticks -Cell $row[2]) -join ','
            $expectedDepends = if ($spec.DependsOn) { [string]$spec.DependsOn } else { '' }
            if ($dependsOn -cne $expectedDepends) { $problems.Add("Application $name names dependency '$dependsOn' instead of '$expectedDepends'.") }
            $detection = @(Get-Ticks -Cell $row[3])
            foreach ($value in @([string]$spec.DetectionKey, [string]$spec.DetectionName) + @($spec.DetectionValues | ForEach-Object { [string]$_ })) {
                if ($detection -cnotcontains $value) { $problems.Add("Application $name detection misses $value.") }
            }
            $section = Get-DocSection -Doc $Doc -Heading $name
            $sectionCharts = @(Get-MermaidBlocks -Text $section)
            if ($sectionCharts.Count -ne 1) { $problems.Add("Section $name must contain 1 mermaid block, found $($sectionCharts.Count).") }
        }
        $known = @($Specs | ForEach-Object { [string]$_.AppName })
        foreach ($row in $rows) {
            $name = (@(Get-Ticks -Cell $row[0]))[0]
            if ($name -and $known -cnotcontains $name) { $problems.Add("Table row $name is not a client application.") }
        }

        $phaseSection = Get-DocSection -Doc $Doc -Heading 'Phasenmeldungen'
        $header = @(Get-TableRows -Text $phaseSection -Header)
        $headerEvents = if ($header.Count -gt 0) { @($header[0] | Select-Object -Skip 2 | ForEach-Object { (@(Get-Ticks -Cell $_))[0] }) } else { @() }
        if (($headerEvents -join ',') -cne ($Events -join ',')) { $problems.Add("The phase table columns name events '$($headerEvents -join ',')' instead of '$($Events -join ',')'.") }
        $phaseRows = @(Get-TableRows -Text $phaseSection)
        foreach ($phase in $Phases) {
            $matching = @($phaseRows | Where-Object { (@(Get-Ticks -Cell $_[0]))[0] -ceq $phase })
            if ($matching.Count -ne 1) { $problems.Add("Phase $phase has $($matching.Count) table rows."); continue }
            $script = (@(Get-Ticks -Cell $matching[0][1]))[0]
            if ($script -cne [string]$PhaseScripts[$phase]) { $problems.Add("Phase $phase names script $script instead of $($PhaseScripts[$phase]).") }
        }
        foreach ($row in $phaseRows) {
            $phase = (@(Get-Ticks -Cell $row[0]))[0]
            if ($phase -and $Phases -cnotcontains $phase) { $problems.Add("Table row $phase is not a phase.") }
        }
        return $problems.ToArray()
    }

    $script:DocPath = Join-Path $script:RepoRoot 'docs/operations/client-flows.md'
}

Describe 'docs/operations/client-flows.md' {
    It 'derives its vocabulary from code' {
        $sets = Get-SendPhaseSets
        @($sets['Phase']).Count | Should -BeGreaterThan 0
        @($sets['PhaseEvent']).Count | Should -BeGreaterThan 0
        $phaseScripts = Get-PhaseScripts
        foreach ($phase in $sets['Phase']) { $phaseScripts.ContainsKey($phase) | Should -BeTrue -Because "phase $phase must be sent by a client script" }
        @(Get-VsClientAppSpecs).Count | Should -BeGreaterThan 0
    }

    It 'names every client application, dependency, detection value and phase' {
        Test-Path -LiteralPath $script:DocPath | Should -BeTrue
        $doc = [IO.File]::ReadAllText($script:DocPath, [Text.Encoding]::UTF8).Replace("`r`n", "`n")
        $sets = Get-SendPhaseSets
        $problems = @(Get-ClientFlowsDocProblems -Doc $doc -Specs @(Get-VsClientAppSpecs) -Phases $sets['Phase'] -Events $sets['PhaseEvent'] -PhaseScripts (Get-PhaseScripts))
        ($problems -join "`n") | Should -Be ''
    }

    It 'is linked from the flows index' {
        $flows = [IO.File]::ReadAllText((Join-Path $script:RepoRoot 'docs/operations/flows.md'), [Text.Encoding]::UTF8)
        $flows | Should -Match '\(client-flows\.md\)'
    }

    Context 'fixture negatives' {
        BeforeAll {
            $script:Specs = @(
                [pscustomobject]@{ AppName = 'app_a'; Role = 'internal_dependency'; DependsOn = $null; DetectionKey = 'K\A'; DetectionName = 'N'; DetectionValues = @('ok') }
                [pscustomobject]@{ AppName = 'app_b'; Role = 'deployable_entry'; DependsOn = 'app_a'; DetectionKey = 'K\B'; DetectionName = 'M'; DetectionValues = @('1', '2') }
            )
            $nl = "`n"
            $script:Good = '## Anwendungen und Reihenfolge' + $nl + $nl +
                '```mermaid' + $nl + 'flowchart TD' + $nl + '    app_a["app_a"] --> app_b["app_b"]' + $nl + '```' + $nl + $nl +
                '| Anwendung | Rolle | Haengt ab von | Erkennung |' + $nl + '|---|---|---|---|' + $nl +
                '| `app_a` | `internal_dependency` | - | `K\A`, `N` = `ok` |' + $nl +
                '| `app_b` | `deployable_entry` | `app_a` | `K\B`, `M` = `1` oder `2` |' + $nl + $nl +
                '## app_a' + $nl + '```mermaid' + $nl + 'flowchart TD' + $nl + '    x["x"]' + $nl + '```' + $nl +
                '## app_b' + $nl + '```mermaid' + $nl + 'flowchart TD' + $nl + '    y["y"]' + $nl + '```' + $nl +
                '## Phasenmeldungen' + $nl + $nl +
                '| Phase | Skript | `started` | `failed` |' + $nl + '|---|---|---|---|' + $nl +
                '| `pa` | `app_a` | a | b |' + $nl
            $script:Check = {
                param([string]$Doc)
                @(Get-ClientFlowsDocProblems -Doc $Doc -Specs $script:Specs -Phases @('pa') -Events @('started', 'failed') -PhaseScripts @{ pa = 'app_a' })
            }
        }

        It 'accepts the matching fixture' {
            (& $script:Check $script:Good) -join "`n" | Should -Be ''
        }

        It 'rejects a missing dependency edge' {
            (& $script:Check ($script:Good.Replace('app_a["app_a"] --> app_b["app_b"]', 'app_a["app_a"]' + "`n" + '    app_b["app_b"]'))) | Should -Contain 'Dependency app_a --> app_b is not drawn.'
        }

        It 'rejects a missing detection value' {
            (& $script:Check ($script:Good.Replace(' oder `2`', ''))) | Should -Contain 'Application app_b detection misses 2.'
        }

        It 'rejects a wrong role and an extra application row' {
            $doc = $script:Good.Replace('| `app_a` | `internal_dependency` |', '| `app_a` | `deployable_entry` |') + ''
            $problems = & $script:Check $doc
            $problems | Should -Contain 'Application app_a names role `deployable_entry` instead of internal_dependency.'
            $extra = $script:Good.Replace('| `app_b` | `deployable_entry`', '| `app_c` | `x` | - | - |' + "`n" + '| `app_b` | `deployable_entry`')
            (& $script:Check $extra) | Should -Contain 'Table row app_c is not a client application.'
        }

        It 'rejects a wrong phase script and event columns' {
            (& $script:Check ($script:Good.Replace('| `pa` | `app_a` |', '| `pa` | `app_b` |'))) | Should -Contain 'Phase pa names script app_b instead of app_a.'
            (& $script:Check ($script:Good.Replace('| `started` | `failed` |', '| `started` | `finished` |'))) | Should -Contain "The phase table columns name events 'started,finished' instead of 'started,failed'."
        }

        It 'rejects a missing application section chart' {
            (& $script:Check ($script:Good.Replace('## app_b' + "`n" + '```mermaid' + "`n" + 'flowchart TD' + "`n" + '    y["y"]' + "`n" + '```', '## app_b'))) | Should -Contain 'Section app_b must contain 1 mermaid block, found 0.'
        }

        It 'fails on zero derived values' {
            @(Get-ClientFlowsDocProblems -Doc $script:Good -Specs @() -Phases @('pa') -Events @() -PhaseScripts @{}) | Should -Contain 'No client applications derived from code (zero-match must not pass).'
        }
    }
}
