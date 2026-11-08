#Requires -Version 5.1
<#
.SYNOPSIS
    Faehrt die PowerShell-Pruefungen: PSScriptAnalyzer ueber Powershell-MECM
    (Server-Regelsatz PSScriptAnalyzerSettings.psd1), PSScriptAnalyzer ueber
    scripts und tests\powershell (Tooling-Regelsatz
    PSScriptAnalyzerSettings.Tooling.psd1) und die Pester-Suite unter
    tests\powershell.

.DESCRIPTION
    Dev-Host- und CI-Tooling (ADR-0028-Familie): nichts davon wird ausgeliefert.
    Die PowerShell-Skripte laufen als SYSTEM in Endlosschleifen auf dem
    MECM-Server und auf frisch ausgerollten Clients; bis 2026-07 hat sie nichts
    geprueft. Dieses Skript ist der Einstiegspunkt fuer beides, lokal wie in CI.

    Module (Pester >= 5, PSScriptAnalyzer) kommen aus der PSGallery und werden
    NICHT eingecheckt - dasselbe Muster wie Playwright und Infection.

.PARAMETER SkipAnalyzer
    Nur die Pester-Suite fahren. Entfaellt damit beide PSScriptAnalyzer-Laeufe
    (Powershell-MECM und scripts/tests-Tooling).

.PARAMETER SkipTests
    Nur PSScriptAnalyzer fahren.

.PARAMETER Partitions
    Die Pester-Suite in so vielen Kindprozessen gleichzeitig fahren (Standard
    3, hoechstens so viele wie Testdateien; 1 = ein Prozess wie bis C2). Die
    Dateien werden nach ihrem Gewicht verteilt (siehe Get-PesterFileWeight),
    nie nach einer Namensliste. Zaehler, gescheiterte Container und die
    Coverage der Kinder werden zu einem Ergebnis vereinigt; Floor, Ausgabe
    und Exitcodes sind dieselben wie im Einzelprozess.

.PARAMETER PartitionSpec
    Intern: Pfad der Auftragsdatei eines Kindprozesses. Nicht von Hand setzen.

.EXAMPLE
    powershell -NoProfile -File scripts\run-pester.ps1

.NOTES
    Exitcodes: 0 alles gruen; 1 Analyzer-Befunde oder rote Tests; 3 ein
    Toolmodul fehlt (Infrastruktur, nicht Befund). check.ps1 unterscheidet
    fail/infrastructure_error ausschliesslich ueber diesen Code, nie ueber
    Textmuster im Output: Testnamen duerfen jedes Wort enthalten. Ein
    Kindprozess ohne Ergebnisdatei gilt wie ein fehlendes Ergebnisobjekt als
    Infrastruktur (3), nie als gruen.
#>
[CmdletBinding()]
param(
    [switch]$SkipAnalyzer,
    [switch]$SkipTests,
    [ValidateRange(1, 16)][int]$Partitions = 3,
    [string]$PartitionSpec = ''
)

Set-StrictMode -Version 1.0
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path $PSScriptRoot -Parent
$scriptRoot = Join-Path $repoRoot 'Powershell-MECM'
# Join-Path statt eines Literals mit Backslash: dieses Skript laeuft auch unter
# pwsh auf Linux (CI), wo '\' kein Pfadtrenner ist.
$testRoot = Join-Path (Join-Path $repoRoot 'tests') 'powershell'
$registerPath = Join-Path $testRoot 'VirtuSphere.Haertung2026-08.Tests.ps1'
$registerPresent = Test-Path $registerPath
$failed = $false
$unitTotal = 0
# Zwei Analyzer-Laeufe: Powershell-MECM (Server-Regelsatz) und scripts/tests (Tooling-Regelsatz).
if (-not $SkipAnalyzer) { $unitTotal += 2 }
if (-not $SkipTests) {
    $unitTotal++
    if ($registerPresent) { $unitTotal++ }
}
$unitPosition = 0

# Liest eine Eigenschaft defensiv: fehlt sie (andere Pester-Version, Testdouble),
# ist das Ergebnis $null statt eines Abbruchs.
function Get-PesterRunValue {
    param($Object, [string]$Name)
    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}

# Erste Zeile der ersten Fehlermeldung eines Containers; leer, wenn keine da ist.
function Get-PesterContainerErrorLine {
    param($Container)
    try {
        $first = @(Get-PesterRunValue $Container 'ErrorRecord') | Where-Object { $null -ne $_ } | Select-Object -First 1
        if ($null -eq $first) { return '' }
        $message = ''
        if ($first -is [System.Management.Automation.ErrorRecord]) {
            $message = [string]$first.Exception.Message
        } else {
            $message = [string]$first
        }
        return [string](@($message -split '\r?\n') | Select-Object -First 1)
    } catch {
        Write-Debug $_
        return ''
    }
}

# Das Haertungsregister (Tag 'Haertung') ist absichtlich rot, bis der jeweilige
# Fix steht; Begruendung im Pester-Abschnitt. Eine Quelle fuer Einzel- und
# Kindprozess.
$excludedTag = 'Haertung'

# Coverage-Befehle eines Laufs, gezaehlt je Position (Datei, Start, Ende): die
# Position ist die Identitaet, ueber die sich die Kinder exakt vereinigen
# lassen. Kompakt fuer die Ergebnisdatei: eine Dateitabelle und je Position
# @(Dateiindex, Startzeile, Startspalte, Endzeile, Endspalte, analysiert, ausgefuehrt).
function ConvertTo-PesterCoverageTally {
    param($Coverage)
    $files = New-Object System.Collections.Generic.List[string]
    $tally = @{}
    foreach ($pair in @(@{ List = $Coverage.CommandsExecuted; Hit = 1 }, @{ List = $Coverage.CommandsMissed; Hit = 0 })) {
        foreach ($command in @($pair.List)) {
            if ($null -eq $command) { continue }
            $fileIndex = $files.IndexOf([string]$command.File)
            if ($fileIndex -lt 0) { $files.Add([string]$command.File); $fileIndex = $files.Count - 1 }
            $key = ('{0}|{1}|{2}|{3}|{4}' -f $fileIndex, $command.StartLine, $command.StartColumn, $command.EndLine, $command.EndColumn)
            if (-not $tally.ContainsKey($key)) {
                $tally[$key] = @($fileIndex, [int]$command.StartLine, [int]$command.StartColumn, [int]$command.EndLine, [int]$command.EndColumn, 0, 0)
            }
            $tally[$key][5] = $tally[$key][5] + 1
            $tally[$key][6] = $tally[$key][6] + $pair.Hit
        }
    }
    return [ordered]@{ files = @($files); entries = @(foreach ($entry in $tally.Values) { , $entry }) }
}

# --- Kindprozess einer Partition --------------------------------------------
# Faehrt genau die Dateien seines Auftrags mit derselben Konfiguration wie der
# Einzelprozess und schreibt das Run-Objekt als JSON. Klassifiziert wird nur im
# Elternprozess; hier zaehlt allein, ob ein Ergebnis entstanden ist.
if ($PartitionSpec -ne '') {
    try {
        $spec = Get-Content -Raw -LiteralPath $PartitionSpec | ConvertFrom-Json
        Import-Module $spec.pesterPath -ErrorAction Stop
        $config = New-PesterConfiguration
        $config.Run.Path = [string[]]@($spec.files)
        $config.Run.PassThru = $true
        $config.Output.Verbosity = 'Detailed'
        $config.Filter.ExcludeTag = $excludedTag
        if ($null -ne $spec.coverage) {
            $config.CodeCoverage.Enabled = $true
            $config.CodeCoverage.Path = [string[]]@($spec.coverage.paths)
            $config.CodeCoverage.OutputPath = [string]$spec.coverage.outputPath
        }
        $result = Invoke-Pester -Configuration $config
    } catch {
        Write-Host ('Pester-Teil: infrastructure_error: {0}' -f $_.Exception.Message) -ForegroundColor Red
        exit 3
    }
    $run = @($result) | Where-Object { $null -ne $_ -and $_.PSObject.Properties['Result'] -and $_.PSObject.Properties['Containers'] } | Select-Object -Last 1
    if ($null -eq $run) {
        Write-Host 'Pester-Teil: infrastructure_error: Invoke-Pester lieferte kein Ergebnisobjekt' -ForegroundColor Red
        exit 3
    }
    $coverageOut = $null
    $runCoverage = Get-PesterRunValue $run 'CodeCoverage'
    if ($null -ne $runCoverage) { $coverageOut = ConvertTo-PesterCoverageTally $runCoverage }
    $partResult = [ordered]@{
        result                = [string](Get-PesterRunValue $run 'Result')
        totalCount            = [int](Get-PesterRunValue $run 'TotalCount')
        passedCount           = [int](Get-PesterRunValue $run 'PassedCount')
        failedCount           = [int](Get-PesterRunValue $run 'FailedCount')
        failedContainersCount = [int](Get-PesterRunValue $run 'FailedContainersCount')
        failedBlocksCount     = [int](Get-PesterRunValue $run 'FailedBlocksCount')
        containers            = @(foreach ($container in @($run.Containers)) {
                if ($null -eq $container) { continue }
                [ordered]@{ item = [string]$container.Item; result = [string]$container.Result; error = (Get-PesterContainerErrorLine $container) }
            })
        coverage              = $coverageOut
    }
    # Erst vollstaendig schreiben, dann umbenennen: der Elternprozess liest nie
    # eine halbe Datei.
    $partial = [string]$spec.resultPath + '.partial'
    [System.IO.File]::WriteAllText($partial, (ConvertTo-Json -InputObject $partResult -Depth 6 -Compress), (New-Object System.Text.UTF8Encoding($false)))
    Move-Item -LiteralPath $partial -Destination ([string]$spec.resultPath) -Force
    exit 0
}

# Gewicht einer Testdatei fuer die Verteilung auf Partitionen: ihre Groesse in
# Bytes. Abgeleitet, nicht gelistet: eine neue Datei wird ohne Pflege verteilt.
function Get-PesterFileWeight {
    param([System.IO.FileInfo]$File)
    return [double]$File.Length
}

# Verteilung nach der LPT-Regel: schwerste Datei zuerst in die leichteste
# Partition. Leere Partitionen entstehen nicht (Anzahl <= Dateien).
function Get-PesterPartitionPlan {
    param([System.IO.FileInfo[]]$Files, [int]$Count)
    $parts = @(for ($i = 0; $i -lt $Count; $i++) { @{ Files = New-Object System.Collections.Generic.List[string]; Weight = 0.0 } })
    foreach ($file in @($Files | Sort-Object @{ Expression = { Get-PesterFileWeight $_ }; Descending = $true }, FullName)) {
        $target = $parts | Sort-Object { $_.Weight } | Select-Object -First 1
        $target.Files.Add($file.FullName)
        $target.Weight += (Get-PesterFileWeight $file)
    }
    return $parts
}

# Vereinigt die Ergebnisse der Kinder zu einem Objekt mit den Eigenschaften, die
# die Klassifikation unten vom Run-Objekt liest. Gesamtergebnis wie bei Pester:
# Failed vor Passed vor NotRun. Coverage: je Position die analysierte Anzahl
# und die groesste ausgefuehrte Anzahl eines Kindes, begrenzt auf die analysierte.
function Merge-PesterPartitionResult {
    param([object[]]$Parts, [bool]$CoverageConfigured)
    $results = @($Parts | ForEach-Object { $_.result })
    $merged = 'NotRun'
    if ($results -contains 'Failed') { $merged = 'Failed' } elseif ($results -contains 'Passed') { $merged = 'Passed' }
    $coverage = $null
    if ($CoverageConfigured -and @($Parts | Where-Object { $null -eq $_.coverage }).Count -eq 0) {
        $analyzed = @{}
        $hit = @{}
        foreach ($part in $Parts) {
            $files = @($part.coverage.files)
            foreach ($entry in @($part.coverage.entries)) {
                if ($null -eq $entry) { continue }
                $key = ('{0}|{1}|{2}|{3}|{4}' -f $files[[int]$entry[0]], $entry[1], $entry[2], $entry[3], $entry[4])
                $analyzed[$key] = [Math]::Max([int]$analyzed[$key], [int]$entry[5])
                $hit[$key] = [Math]::Max([int]$hit[$key], [int]$entry[6])
            }
        }
        $analyzedTotal = 0
        $hitTotal = 0
        foreach ($key in $analyzed.Keys) {
            $analyzedTotal += $analyzed[$key]
            $hitTotal += [Math]::Min($analyzed[$key], $hit[$key])
        }
        $percent = 0.0
        if ($analyzedTotal -gt 0) { $percent = ($hitTotal / $analyzedTotal) * 100 }
        $coverage = [pscustomobject]@{ CoveragePercent = $percent; CommandsAnalyzedCount = $analyzedTotal; CommandsExecutedCount = $hitTotal }
    }
    $sum = { param($Name) $total = 0; foreach ($part in $Parts) { $total += [int]$part.$Name }; $total }
    return [pscustomobject]@{
        Result                = $merged
        TotalCount            = (& $sum 'totalCount')
        PassedCount           = (& $sum 'passedCount')
        FailedCount           = (& $sum 'failedCount')
        FailedContainersCount = (& $sum 'failedContainersCount')
        FailedBlocksCount     = (& $sum 'failedBlocksCount')
        Containers            = @(foreach ($part in $Parts) {
                foreach ($container in @($part.containers)) {
                    if ($null -eq $container) { continue }
                    $errors = @()
                    if ([string]$container.error -ne '') { $errors = @([string]$container.error) }
                    [pscustomobject]@{ Item = [string]$container.item; Result = [string]$container.result; ErrorRecord = $errors }
                }
            })
        CodeCoverage          = $coverage
    }
}

# --- PSScriptAnalyzer -------------------------------------------------------
if (-not $SkipAnalyzer) {
    $unitPosition++
    $unitName = 'PSScriptAnalyzer (Powershell-MECM)'
    Write-Host ('[{0}/{1}] RUN  {2}' -f $unitPosition, $unitTotal, $unitName) -ForegroundColor Cyan
    if (-not (Get-Module -ListAvailable PSScriptAnalyzer)) {
        Write-Host 'PSScriptAnalyzer fehlt. Install-Module PSScriptAnalyzer -Scope CurrentUser' -ForegroundColor Red
        Write-Host ('[{0}/{1}] infrastructure_error {2}: Modul fehlt' -f $unitPosition, $unitTotal, $unitName) -ForegroundColor Red
        exit 3
    }
    try {
        Import-Module PSScriptAnalyzer -ErrorAction Stop
        $settings = Join-Path $repoRoot 'PSScriptAnalyzerSettings.psd1'
        $findings = @(Invoke-ScriptAnalyzer -Path $scriptRoot -Recurse -Settings $settings)
    } catch {
        Write-Host ('[{0}/{1}] infrastructure_error {2}: {3}' -f $unitPosition, $unitTotal, $unitName, $_.Exception.Message) -ForegroundColor Red
        exit 3
    }

    if ($findings.Count -gt 0) {
        $findings | Sort-Object Severity, ScriptName, Line | ForEach-Object {
            Write-Host ('    {0,-11} {1}:{2} {3} [{4}]' -f $_.Severity, (Split-Path $_.ScriptPath -Leaf), $_.Line, $_.Message, $_.RuleName) -ForegroundColor Yellow
        }
        Write-Host ('[{0}/{1}] fail {2}: {3} Befund(e)' -f $unitPosition, $unitTotal, $unitName, $findings.Count) -ForegroundColor Red
        $failed = $true
    } else {
        Write-Host ('[{0}/{1}] pass {2}: keine Befunde' -f $unitPosition, $unitTotal, $unitName) -ForegroundColor Green
    }
}

# --- PSScriptAnalyzer (Tooling) ---------------------------------------------
# Eigener Regelsatz fuer scripts/ und tests/powershell: dieselben Befundtexte
# wie oben, nur Pfade und Settings-Datei sind andere.
if (-not $SkipAnalyzer) {
    $unitPosition++
    $unitName = 'PSScriptAnalyzer (scripts, tests\powershell)'
    Write-Host ('[{0}/{1}] RUN  {2}' -f $unitPosition, $unitTotal, $unitName) -ForegroundColor Cyan
    if (-not (Get-Module -ListAvailable PSScriptAnalyzer)) {
        Write-Host 'PSScriptAnalyzer fehlt. Install-Module PSScriptAnalyzer -Scope CurrentUser' -ForegroundColor Red
        Write-Host ('[{0}/{1}] infrastructure_error {2}: Modul fehlt' -f $unitPosition, $unitTotal, $unitName) -ForegroundColor Red
        exit 3
    }
    try {
        Import-Module PSScriptAnalyzer -ErrorAction Stop
        $toolingSettings = Join-Path $repoRoot 'PSScriptAnalyzerSettings.Tooling.psd1'
        $toolingPaths = @((Join-Path $repoRoot 'scripts'), $testRoot)
        $findings = @(foreach ($toolingPath in $toolingPaths) { Invoke-ScriptAnalyzer -Path $toolingPath -Recurse -Settings $toolingSettings })
    } catch {
        Write-Host ('[{0}/{1}] infrastructure_error {2}: {3}' -f $unitPosition, $unitTotal, $unitName, $_.Exception.Message) -ForegroundColor Red
        exit 3
    }

    if ($findings.Count -gt 0) {
        $findings | Sort-Object Severity, ScriptName, Line | ForEach-Object {
            Write-Host ('    {0,-11} {1}:{2} {3} [{4}]' -f $_.Severity, (Split-Path $_.ScriptPath -Leaf), $_.Line, $_.Message, $_.RuleName) -ForegroundColor Yellow
        }
        Write-Host ('[{0}/{1}] fail {2}: {3} Befund(e)' -f $unitPosition, $unitTotal, $unitName, $findings.Count) -ForegroundColor Red
        $failed = $true
    } else {
        Write-Host ('[{0}/{1}] pass {2}: keine Befunde' -f $unitPosition, $unitTotal, $unitName) -ForegroundColor Green
    }
}

# --- Pester -----------------------------------------------------------------
if (-not $SkipTests) {
    $unitPosition++
    $unitName = 'Pester (tests\powershell)'
    Write-Host ('[{0}/{1}] RUN  {2}' -f $unitPosition, $unitTotal, $unitName) -ForegroundColor Cyan
    # Exakte Version aus der Tool-Lockdatei (AP4-SSoT, dieselbe Datei lesen
    # check.ps1 und ci.yml). Kein Fallback auf "irgendein Pester >= 5": ein
    # anderes Major hat andere Semantik (6.0.0 brach 2026-07-16 unter Linux,
    # waehrend es unter Windows gruen war), und ein stiller Fallback waere
    # genau der Versions-Drift, den die Lockdatei verhindern soll.
    $lockPath = Join-Path $PSScriptRoot 'tool-lock.json'
    $lockedPester = $null
    if (Test-Path $lockPath) {
        try {
            $lockedPester = [string](Get-Content -Raw -Path $lockPath | ConvertFrom-Json).powershellModules.Pester
        } catch {
            Write-Host ('tool-lock.json unlesbar: {0}' -f $_.Exception.Message) -ForegroundColor Red
            Write-Host ('[{0}/{1}] infrastructure_error {2}: tool-lock.json unlesbar' -f $unitPosition, $unitTotal, $unitName) -ForegroundColor Red
            exit 3
        }
    }
    if ($lockedPester) {
        $pester = Get-Module -ListAvailable Pester | Where-Object { "$($_.Version)" -eq $lockedPester } | Select-Object -First 1
        if (-not $pester) {
            Write-Host ('Pester {0} (tool-lock.json) fehlt. Install-Module Pester -RequiredVersion {0} -Scope CurrentUser -Force -SkipPublisherCheck' -f $lockedPester) -ForegroundColor Red
            Write-Host ('[{0}/{1}] infrastructure_error {2}: Modul fehlt' -f $unitPosition, $unitTotal, $unitName) -ForegroundColor Red
            exit 3
        }
    } else {
        # Ohne Lockdatei (Checkout-Fragment, Fixture-Root): dev-freundlicher
        # Minimalanspruch wie vor AP4.
        $pester = Get-Module -ListAvailable Pester | Where-Object { $_.Version.Major -ge 5 } | Sort-Object Version -Descending | Select-Object -First 1
        if (-not $pester) {
            Write-Host 'Pester 5+ fehlt (die Windows-Inbox-Version 3.4 reicht nicht). Install-Module Pester -MinimumVersion 5.5.0 -Scope CurrentUser -Force -SkipPublisherCheck' -ForegroundColor Red
            Write-Host ('[{0}/{1}] infrastructure_error {2}: Modul fehlt' -f $unitPosition, $unitTotal, $unitName) -ForegroundColor Red
            exit 3
        }
    }
    try { Import-Module $pester.Path -ErrorAction Stop } catch {
        Write-Host ('[{0}/{1}] infrastructure_error {2}: {3}' -f $unitPosition, $unitTotal, $unitName, $_.Exception.Message) -ForegroundColor Red
        exit 3
    }

    $config = New-PesterConfiguration
    $config.Run.Path = $testRoot
    $config.Run.PassThru = $true
    $config.Output.Verbosity = 'Detailed'
    # Das Haertungsregister (Tag 'Haertung') ist absichtlich rot, bis der
    # jeweilige Fix steht: seine roten Tests sind die To-do-Liste der Kampagne
    # 2026-08, keine Regression. Es darf das Gate deshalb nicht blockieren,
    # sonst haengt jeder unabhaengige Hotfix hinter einer Aufraeumaktion. Die
    # offene Zahl wird unten trotzdem gedruckt - siehe die Begruendung dort.
    $config.Filter.ExcludeTag = $excludedTag

    # Coverage-Ratchet (AP5): die Common-Module tragen die ganze wiederver-
    # wendete Logik der SYSTEM-Skripte; ihr Deckungsgrad darf nur steigen.
    # Der Floor steht in tool-lock.json (pesterCoverageFloorPercent) und wird
    # nur auf Windows durchgesetzt: die Registry-Testbloecke existieren unter
    # pwsh/Linux nicht, dort waere derselbe Floor unerreichbar. Die Loop-
    # Skripte selbst sind bewusst nicht vermessen (Endlosschleifen laedt kein
    # Test); ihre Logik lebt genau deshalb in den Common-Dateien.
    $coverageFloor = $null
    if ($lockedPester -and (Test-Path $lockPath)) {
        try {
            $lockData = Get-Content -Raw -Path $lockPath | ConvertFrom-Json
            if ($lockData.PSObject.Properties['pesterCoverageFloorPercent']) {
                $coverageFloor = [double]$lockData.pesterCoverageFloorPercent
            }
        } catch { Write-Debug $_ }
    }
    $coveragePaths = @(
        (Join-Path (Join-Path $scriptRoot 'mecm') 'VirtuSphere-Common.ps1'),
        (Join-Path (Join-Path $scriptRoot 'mecm') 'VirtuSphere-Logging.ps1'),
        (Join-Path (Join-Path $scriptRoot 'mecm') 'VirtuSphere-ClientPackaging.ps1'),
        (Join-Path (Join-Path $scriptRoot 'clients') 'VirtuSphere-Client-Common.ps1'),
        (Join-Path (Join-Path $scriptRoot 'clients') 'VirtuSphere-Client-Logging.ps1')
    )
    if ($null -ne $coverageFloor) {
        $config.CodeCoverage.Enabled = $true
        $config.CodeCoverage.Path = $coveragePaths
        $config.CodeCoverage.OutputPath = Join-Path ([System.IO.Path]::GetTempPath()) 'vs-pester-coverage.xml'
    }

    $testFiles = @(Get-ChildItem -LiteralPath $testRoot -Recurse -File -Filter '*.Tests.ps1' | Sort-Object FullName)
    $partitionCount = [Math]::Min($Partitions, $testFiles.Count)
    if ($partitionCount -le 1) {
        try { $result = Invoke-Pester -Configuration $config } catch {
            Write-Host ('[{0}/{1}] infrastructure_error {2}: {3}' -f $unitPosition, $unitTotal, $unitName, $_.Exception.Message) -ForegroundColor Red
            exit 3
        }
    } else {
        # --- Partitionen: K Kindprozesse, Zeilen live mit Praefix [Teil k/K] ---
        . (Join-Path (Join-Path (Join-Path $PSScriptRoot 'lib') 'check') 'child-streams.ps1')
        $partRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('vs-pester-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $partRoot -ErrorAction Stop | Out-Null
        $engine = [System.Diagnostics.Process]::GetCurrentProcess().MainModule.FileName
        $plan = @(Get-PesterPartitionPlan -Files $testFiles -Count $partitionCount)
        $children = @()
        $parts = @()
        $partMissing = @()
        try {
            for ($k = 1; $k -le $partitionCount; $k++) {
                $resultPath = Join-Path $partRoot ('part-' + $k + '.json')
                $specData = [ordered]@{ pesterPath = [string]$pester.Path; files = @($plan[$k - 1].Files); resultPath = $resultPath; coverage = $null }
                if ($null -ne $coverageFloor) {
                    $specData.coverage = [ordered]@{ paths = $coveragePaths; outputPath = (Join-Path $partRoot ('coverage-' + $k + '.xml')) }
                }
                $specPath = Join-Path $partRoot ('part-' + $k + '.spec.json')
                [System.IO.File]::WriteAllText($specPath, (ConvertTo-Json -InputObject $specData -Depth 4), (New-Object System.Text.UTF8Encoding($false)))
                Write-Host ('[{0}/{1}] RUN  Pester-Teil {0}/{1} ({2} Dateien)' -f $k, $partitionCount, $plan[$k - 1].Files.Count) -ForegroundColor Cyan
                $child = Start-StreamedChild -Exe $engine `
                    -ArgumentLine ('-NoProfile -ExecutionPolicy Bypass -File "' + $PSCommandPath + '" -PartitionSpec "' + $specPath + '"') `
                    -OutPath (Join-Path $partRoot ('part-' + $k + '.out')) -ErrPath (Join-Path $partRoot ('part-' + $k + '.err'))
                $child.Index = $k
                $child.ResultPath = $resultPath
                $children += $child
            }
            while (@($children | Where-Object { -not $_.Finished }).Count -gt 0) {
                foreach ($child in @($children | Where-Object { -not $_.Finished })) {
                    foreach ($line in @(Read-StreamedChildLines $child)) { Write-Host ('[Teil {0}/{1}] {2}' -f $child.Index, $partitionCount, $line) }
                    if (-not (Test-StreamedChildExited $child)) { continue }
                    foreach ($line in @(Read-StreamedChildLines $child -Final)) { Write-Host ('[Teil {0}/{1}] {2}' -f $child.Index, $partitionCount, $line) }
                    foreach ($line in @(Close-StreamedChild $child)) { Write-Host ('[Teil {0}/{1}] stderr: {2}' -f $child.Index, $partitionCount, $line) -ForegroundColor Yellow }
                    $seconds = $child.Watch.Elapsed.TotalSeconds.ToString('0.0', [System.Globalization.CultureInfo]::InvariantCulture)
                    $part = $null
                    if ((Test-Path -LiteralPath $child.ResultPath) -and $child.ExitCode -eq 0) {
                        try { $part = Get-Content -Raw -LiteralPath $child.ResultPath | ConvertFrom-Json } catch { $part = $null }
                    }
                    if ($null -eq $part) {
                        $partMissing += $child.Index
                        Write-Host ('[{0}/{1}] infrastructure_error Pester-Teil {0}/{1}: kein Ergebnis (Exit {2}, {3}s)' -f $child.Index, $partitionCount, $child.ExitCode, $seconds) -ForegroundColor Red
                        continue
                    }
                    $parts += $part
                    $partWord = 'pass'
                    $partColor = 'Green'
                    if ($part.result -eq 'Failed' -or $part.failedCount -gt 0 -or $part.failedContainersCount -gt 0 -or $part.failedBlocksCount -gt 0) { $partWord = 'fail'; $partColor = 'Red' }
                    Write-Host ('[{0}/{1}] {2} Pester-Teil {0}/{1}: {3} Test(s), {4} rot ({5}s)' -f $child.Index, $partitionCount, $partWord, $part.totalCount, $part.failedCount, $seconds) -ForegroundColor $partColor
                }
                if (@($children | Where-Object { -not $_.Finished }).Count -gt 0) { Start-Sleep -Milliseconds 250 }
            }
        } finally {
            # Nur bei Abbruch wirksam: die eigenen Kinder samt Baum beenden.
            Stop-StreamedChildren $children
            Remove-Item -Recurse -Force -LiteralPath $partRoot -ErrorAction SilentlyContinue
        }
        if ($partMissing.Count -gt 0) {
            Write-Host ('[{0}/{1}] infrastructure_error {2}: Pester-Teil(e) {3} ohne Ergebnis' -f $unitPosition, $unitTotal, $unitName, ($partMissing -join ', ')) -ForegroundColor Red
            exit 3
        }
        $result = Merge-PesterPartitionResult -Parts $parts -CoverageConfigured ($null -ne $coverageFloor)
    }

    # Nur das Run-Objekt klassifizieren. Invoke-Pester kann neben dem Ergebnis
    # weitere Objekte in die Pipeline geben; ein Array ohne Result/Containers
    # liefert leere Zaehler. So lief 2026-09-28 "Failed: 874" durch, und die
    # Ausgabe meldete trotzdem "Test(s) gruen" ohne Zahl.
    $run = @($result) | Where-Object { $null -ne $_ -and $_.PSObject.Properties['Result'] -and $_.PSObject.Properties['Containers'] } | Select-Object -Last 1
    if ($null -eq $run) {
        Write-Host ('[{0}/{1}] infrastructure_error {2}: Invoke-Pester lieferte kein Ergebnisobjekt' -f $unitPosition, $unitTotal, $unitName) -ForegroundColor Red
        exit 3
    }

    $pesterFailed = $false
    $coverage = Get-PesterRunValue $run 'CodeCoverage'
    if ($null -ne $coverageFloor) {
        # Coverage ist konfiguriert: fehlt das Ergebnis, ist der Lauf rot und
        # wird nicht still uebersprungen.
        if ($null -eq $coverage) {
            Write-Host '    Coverage-Ergebnis fehlt: Coverage ist konfiguriert, der Lauf liefert kein CodeCoverage-Objekt' -ForegroundColor Red
            $pesterFailed = $true
            $failed = $true
        } else {
            $percent = [math]::Round([double]$coverage.CoveragePercent, 1)
            $enforce = (Test-Path 'HKCU:\')
            if ($percent -lt $coverageFloor) {
                if ($enforce) {
                    Write-Host ('    Coverage-Ratchet verletzt: {0}% < Floor {1}% (Common-Module)' -f $percent, $coverageFloor) -ForegroundColor Red
                    $pesterFailed = $true
                    $failed = $true
                } else {
                    Write-Host ('    Coverage {0}% unter Floor {1}% - nur informativ (kein Registry-Provider, Windows-Job setzt durch)' -f $percent, $coverageFloor) -ForegroundColor Yellow
                }
            } else {
                Write-Host ('    Coverage {0}% (Floor {1}%)' -f $percent, $coverageFloor) -ForegroundColor Green
            }
        }
    }

    # Nicht nur FailedCount: ein Container, der schon in der Discovery stirbt
    # (Parse-/Setup-Fehler), hat 0 rote Tests und waere sonst still gruen
    # (so geschehen 2026-07-16 mit einem Parse-Fehler in ErrorPaths). Dasselbe
    # gilt fuer gescheiterte Setup-/Teardown-Bloecke (FailedBlocksCount) und fuer
    # ein Gesamtergebnis, das nicht 'Passed' ist.
    $pesterResult = [string](Get-PesterRunValue $run 'Result')
    $failedCount = [int](Get-PesterRunValue $run 'FailedCount')
    $failedContainersCount = [int](Get-PesterRunValue $run 'FailedContainersCount')
    $failedBlocksCount = [int](Get-PesterRunValue $run 'FailedBlocksCount')
    $totalCount = [int](Get-PesterRunValue $run 'TotalCount')
    $passedCount = [int](Get-PesterRunValue $run 'PassedCount')
    $failedContainers = @($run.Containers | Where-Object { $null -ne $_ -and $_.Result -eq 'Failed' })
    $pesterRunFailed = ($pesterResult -ne 'Passed') -or ($failedCount -gt 0) -or ($failedContainersCount -gt 0) -or ($failedBlocksCount -gt 0) -or ($failedContainers.Count -gt 0)

    if ($pesterRunFailed -or $pesterFailed) {
        if ($pesterResult -ne 'Passed') {
            Write-Host ('    Pester-Gesamtergebnis: "{0}"' -f $pesterResult) -ForegroundColor Red
        }
        foreach ($container in @($failedContainers | Select-Object -First 10)) {
            Write-Host ('    Container gescheitert: {0}: {1}' -f [string]$container.Item, (Get-PesterContainerErrorLine $container)) -ForegroundColor Red
        }
        Write-Host ('[{0}/{1}] fail {2}: {3} Test(s) rot, {4} Container gescheitert, {5} Setup/Teardown-Bloecke gescheitert' -f $unitPosition, $unitTotal, $unitName, $failedCount, $failedContainers.Count, $failedBlocksCount) -ForegroundColor Red
        $failed = $true
    } elseif ($totalCount -eq 0) {
        # Nichts gescheitert, aber auch nichts geprueft: ein leerer Lauf ist kein Gruen.
        Write-Host ('[{0}/{1}] infrastructure_error {2}: 0 Tests entdeckt' -f $unitPosition, $unitTotal, $unitName) -ForegroundColor Red
        exit 3
    } else {
        Write-Host ('[{0}/{1}] pass {2}: {3} Test(s) gruen' -f $unitPosition, $unitTotal, $unitName, $passedCount) -ForegroundColor Green
    }

    # --- Haertungsregister: ausgeklammert, aber nicht unsichtbar --------------
    #
    # Ausklammern allein waere genau die Falle, die dieses Projekt schon einmal
    # getroffen hat: ein Spec, der vier Kataloge verloren hatte und dadurch
    # dauerhaft still gruen war. Ein ausgeklammerter Test ist ein unsichtbarer
    # Test, also wird die offene Zahl hier genannt - ohne den Exit-Code zu
    # beeinflussen, denn es ist geplante Arbeit und kein Fehler dieses Laufs.
    #
    # Handproben zaehlen getrennt und nie als bestanden: eine Zahl, die eine
    # Sichtpruefung an einer echten Maschine mitzaehlt, waere dieselbe
    # Unehrlichkeit, gegen die die Kampagne antritt.
    #
    # Bei 0 offenen Punkten koennen Datei und Tag gemeinsam entfallen; die
    # Tests, die dauerhaft Wert haben, wandern vorher in die festen Suites.
    if ($registerPresent) {
        $unitPosition++
        $unitName = 'Haertungsregister 2026-08 (nicht Teil des Gates)'
        Write-Host ('[{0}/{1}] RUN  {2}' -f $unitPosition, $unitTotal, $unitName) -ForegroundColor Cyan
        $regConfig = New-PesterConfiguration
        $regConfig.Run.Path = $registerPath
        $regConfig.Run.PassThru = $true
        # Detailed macht jeden historischen Testnamen (unter anderem E1/E7/E9)
        # sowie Discoveryfehler sichtbar. Das Register bleibt trotzdem rein
        # informativ und veraendert weder $failed noch den Exitcode.
        $regConfig.Output.Verbosity = 'Detailed'
        $reg = $null
        try {
            $reg = Invoke-Pester -Configuration $regConfig
        } catch {
            Write-Host ('[{0}/{1}] infrastructure_error {2}: {3} (nicht blockierend)' -f $unitPosition, $unitTotal, $unitName, $_.Exception.Message) -ForegroundColor Yellow
        }
        if ($null -ne $reg) {
            $registerFailedContainers = @($reg.FailedContainers)
            if ($reg.FailedContainersCount -gt 0 -or $reg.FailedBlocksCount -gt 0) {
                foreach ($container in $registerFailedContainers) {
                    Write-Host ('    Discovery/Containerfehler: {0}' -f [string]$container.Item) -ForegroundColor Yellow
                }
                Write-Host ('[{0}/{1}] infrastructure_error {2}: {3} Container, {4} Setup/Teardown-Bloecke gescheitert, {5} Test(s) rot (nicht blockierend)' -f $unitPosition, $unitTotal, $unitName, $reg.FailedContainersCount, $reg.FailedBlocksCount, $reg.FailedCount) -ForegroundColor Yellow
            } elseif ($reg.TotalCount -eq 0) {
                Write-Host ('[{0}/{1}] infrastructure_error {2}: 0 Tests entdeckt (nicht blockierend)' -f $unitPosition, $unitTotal, $unitName) -ForegroundColor Yellow
            } elseif ($reg.FailedCount -eq 0) {
                Write-Host ('[{0}/{1}] pass {2}: alle Befunde behoben ({3} gruen, {4} Handprobe(n) offen)' -f $unitPosition, $unitTotal, $unitName, $reg.PassedCount, $reg.SkippedCount) -ForegroundColor Green
            } else {
                Write-Host ('[{0}/{1}] open {2}: {3} Befund(e) offen, {4} behoben, {5} Handprobe(n) (nicht blockierend)' -f $unitPosition, $unitTotal, $unitName, $reg.FailedCount, $reg.PassedCount, $reg.SkippedCount) -ForegroundColor Yellow
            }
        }
    }
}

if ($failed) { exit 1 }
Write-Host 'PowerShell-Pruefungen gruen.' -ForegroundColor Green
exit 0
