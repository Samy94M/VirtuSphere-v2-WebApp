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
$failed = $false

# --- PSScriptAnalyzer -------------------------------------------------------
if (-not $SkipAnalyzer) {
    Write-Host '==> PSScriptAnalyzer (Powershell-MECM)' -ForegroundColor Cyan
    if (-not (Get-Module -ListAvailable PSScriptAnalyzer)) {
        Write-Host 'PSScriptAnalyzer fehlt. Install-Module PSScriptAnalyzer -Scope CurrentUser' -ForegroundColor Red
        exit 3
    }
    Import-Module PSScriptAnalyzer -ErrorAction Stop

    $settings = Join-Path $repoRoot 'PSScriptAnalyzerSettings.psd1'
    $findings = @(Invoke-ScriptAnalyzer -Path $scriptRoot -Recurse -Settings $settings)

    if ($findings.Count -gt 0) {
        $findings | Sort-Object Severity, ScriptName, Line | ForEach-Object {
            Write-Host ('    {0,-11} {1}:{2} {3} [{4}]' -f $_.Severity, (Split-Path $_.ScriptPath -Leaf), $_.Line, $_.Message, $_.RuleName) -ForegroundColor Yellow
        }
        Write-Host ('    {0} Befund(e)' -f $findings.Count) -ForegroundColor Red
        $failed = $true
    } else {
        Write-Host '    OK  keine Befunde' -ForegroundColor Green
    }
}

# --- Pester -----------------------------------------------------------------
if (-not $SkipTests) {
    Write-Host '==> Pester (tests\powershell)' -ForegroundColor Cyan
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
            exit 3
        }
    }
    if ($lockedPester) {
        $pester = Get-Module -ListAvailable Pester | Where-Object { "$($_.Version)" -eq $lockedPester } | Select-Object -First 1
        if (-not $pester) {
            Write-Host ('Pester {0} (tool-lock.json) fehlt. Install-Module Pester -RequiredVersion {0} -Scope CurrentUser -Force -SkipPublisherCheck' -f $lockedPester) -ForegroundColor Red
            exit 3
        }
    } else {
        # Ohne Lockdatei (Checkout-Fragment, Fixture-Root): dev-freundlicher
        # Minimalanspruch wie vor AP4.
        $pester = Get-Module -ListAvailable Pester | Where-Object { $_.Version.Major -ge 5 } | Sort-Object Version -Descending | Select-Object -First 1
        if (-not $pester) {
            Write-Host 'Pester 5+ fehlt (die Windows-Inbox-Version 3.4 reicht nicht). Install-Module Pester -MinimumVersion 5.5.0 -Scope CurrentUser -Force -SkipPublisherCheck' -ForegroundColor Red
            exit 3
        }
    }
    Import-Module $pester.Path -ErrorAction Stop

    $config = New-PesterConfiguration
    $config.Run.Path = $testRoot
    $config.Run.PassThru = $true
    $config.Output.Verbosity = 'Detailed'

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
        (Join-Path (Join-Path $scriptRoot 'mecm') 'VirtuSphere-ClientPackaging.ps1'),
        (Join-Path (Join-Path $scriptRoot 'clients') 'VirtuSphere-Client-Common.ps1')
    )
    if ($null -ne $coverageFloor) {
        $config.CodeCoverage.Enabled = $true
        $config.CodeCoverage.Path = $coveragePaths
        $config.CodeCoverage.OutputPath = Join-Path ([System.IO.Path]::GetTempPath()) 'vs-pester-coverage.xml'
    }

    $result = Invoke-Pester -Configuration $config

    if ($null -ne $coverageFloor -and $result.CodeCoverage) {
        $percent = [math]::Round([double]$result.CodeCoverage.CoveragePercent, 1)
        $enforce = (Test-Path 'HKCU:\')
        if ($percent -lt $coverageFloor) {
            if ($enforce) {
                Write-Host ('    Coverage-Ratchet verletzt: {0}% < Floor {1}% (Common-Module)' -f $percent, $coverageFloor) -ForegroundColor Red
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
        Write-Host ('    {0} Test(s) rot, {1} Container gescheitert' -f $result.FailedCount, $failedContainers.Count) -ForegroundColor Red
        $failed = $true
    } else {
        Write-Host ('    OK  {0} Test(s) gruen' -f $result.PassedCount) -ForegroundColor Green
    }
}

if ($failed) { exit 1 }
Write-Host 'PowerShell-Pruefungen gruen.' -ForegroundColor Green
exit 0
