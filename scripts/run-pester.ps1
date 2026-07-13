#Requires -Version 5.1
<#
.SYNOPSIS
    Faehrt die PowerShell-Pruefungen der Integrationsclients: PSScriptAnalyzer
    ueber Powershell-MECM und die Pester-Suite unter tests\powershell.

.DESCRIPTION
    Dev-Host- und CI-Tooling (ADR-0028-Familie): nichts davon wird ausgeliefert.
    Die PowerShell-Skripte laufen als SYSTEM in Endlosschleifen auf dem
    SCCM-Server und auf frisch ausgerollten Clients; bis 2026-07 hat sie nichts
    geprueft. Dieses Skript ist der Einstiegspunkt fuer beides, lokal wie in CI.

    Module (Pester >= 5, PSScriptAnalyzer) kommen aus der PSGallery und werden
    NICHT eingecheckt - dasselbe Muster wie Playwright und Infection.

.PARAMETER SkipAnalyzer
    Nur die Pester-Suite fahren.

.PARAMETER SkipTests
    Nur PSScriptAnalyzer fahren.

.EXAMPLE
    powershell -NoProfile -File scripts\run-pester.ps1
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
        throw 'PSScriptAnalyzer fehlt. Install-Module PSScriptAnalyzer -Scope CurrentUser'
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
    $pester = Get-Module -ListAvailable Pester | Where-Object { $_.Version.Major -ge 5 } | Sort-Object Version -Descending | Select-Object -First 1
    if (-not $pester) {
        throw 'Pester 5+ fehlt (die Windows-Inbox-Version 3.4 reicht nicht). Install-Module Pester -MinimumVersion 5.5.0 -Scope CurrentUser -Force -SkipPublisherCheck'
    }
    Import-Module $pester.Path -ErrorAction Stop

    $config = New-PesterConfiguration
    $config.Run.Path = $testRoot
    $config.Run.PassThru = $true
    $config.Output.Verbosity = 'Detailed'
    $result = Invoke-Pester -Configuration $config

    if ($result.FailedCount -gt 0) {
        Write-Host ('    {0} Test(s) rot' -f $result.FailedCount) -ForegroundColor Red
        $failed = $true
    } else {
        Write-Host ('    OK  {0} Test(s) gruen' -f $result.PassedCount) -ForegroundColor Green
    }
}

if ($failed) { exit 1 }
Write-Host 'PowerShell-Pruefungen gruen.' -ForegroundColor Green
exit 0
