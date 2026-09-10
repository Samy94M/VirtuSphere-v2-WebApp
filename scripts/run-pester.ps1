#Requires -Version 5.1
<#
.SYNOPSIS
    Faehrt die PowerShell-Pruefungen der Integrationsclients: PSScriptAnalyzer
    ueber Powershell-MECM und die Pester-Suite unter tests\powershell.

.DESCRIPTION
    Dev-Host- und CI-Tooling (ADR-0028-Familie): nichts davon wird ausgeliefert.
    Die PowerShell-Skripte laufen als SYSTEM in Endlosschleifen auf dem
    MECM-Server und auf frisch ausgerollten Clients; bis 2026-07 hat sie nichts
    geprueft. Dieses Skript ist der Einstiegspunkt fuer beides, lokal wie in CI.

    Module (Pester >= 5, PSScriptAnalyzer) kommen aus der PSGallery und werden
    NICHT eingecheckt - dasselbe Muster wie Playwright und Infection.

.PARAMETER SkipAnalyzer
    Nur die Pester-Suite fahren.

.PARAMETER SkipTests
    Nur PSScriptAnalyzer fahren.

.EXAMPLE
    powershell -NoProfile -File scripts\run-pester.ps1

.NOTES
    Exitcodes: 0 alles gruen; 1 Analyzer-Befunde oder rote Tests; 3 ein
    Toolmodul fehlt (Infrastruktur, nicht Befund). check.ps1 unterscheidet
    fail/infrastructure_error ausschliesslich ueber diesen Code, nie ueber
    Textmuster im Output: Testnamen duerfen jedes Wort enthalten.
#>
[CmdletBinding()]
param(
    [switch]$SkipAnalyzer,
    [switch]$SkipTests
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
if (-not $SkipAnalyzer) { $unitTotal++ }
if (-not $SkipTests) {
    $unitTotal++
    if ($registerPresent) { $unitTotal++ }
}
$unitPosition = 0

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
    $config.Filter.ExcludeTag = 'Haertung'

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

    try { $result = Invoke-Pester -Configuration $config } catch {
        Write-Host ('[{0}/{1}] infrastructure_error {2}: {3}' -f $unitPosition, $unitTotal, $unitName, $_.Exception.Message) -ForegroundColor Red
        exit 3
    }

    $pesterFailed = $false
    if ($null -ne $coverageFloor -and $result.CodeCoverage) {
        $percent = [math]::Round([double]$result.CodeCoverage.CoveragePercent, 1)
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

    # Nicht nur FailedCount: ein Container, der schon in der Discovery stirbt
    # (Parse-/Setup-Fehler), hat 0 rote Tests und waere sonst still gruen
    # (so geschehen 2026-07-16 mit einem Parse-Fehler in ErrorPaths).
    $failedContainers = @($result.Containers | Where-Object { $_.Result -eq 'Failed' })
    if ($result.FailedCount -gt 0 -or $failedContainers.Count -gt 0) {
        $pesterFailed = $true
        $failed = $true
    }
    if ($pesterFailed) {
        Write-Host ('[{0}/{1}] fail {2}: {3} Test(s) rot, {4} Container gescheitert' -f $unitPosition, $unitTotal, $unitName, $result.FailedCount, $failedContainers.Count) -ForegroundColor Red
    } else {
        Write-Host ('[{0}/{1}] pass {2}: {3} Test(s) gruen' -f $unitPosition, $unitTotal, $unitName, $result.PassedCount) -ForegroundColor Green
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
