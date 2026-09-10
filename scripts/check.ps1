#Requires -Version 5.1
<#
.SYNOPSIS
    Kanonischer VirtuSphere-Pruef-Runner (ADR-0031): eine ausfuehrbare SSoT
    fuer alle Pruef-Lanes (Fast, Integration, Release).

.DESCRIPTION
    Jedes Gate deklariert seine Ausfuehrungsform (native | container |
    windows-only) und ob es Netz braucht. Containerisierte Gates laufen auf
    Windows-Hosts ueber Docker; fehlt Docker oder ein Tool-Image, ist das
    Ergebnis infrastructure_error, niemals Skip (Plan v2, L8).

    Ergebnisklassen je Gate: pass, fail, skip, not_applicable,
    infrastructure_error. not_applicable ist ausschliesslich fuer per
    Plattform/Flag bewusst nicht anwendbare Gates zulaessig (mit Grund),
    ein fehlendes Tool ist infrastructure_error.

    Exitcodes des Runners:
      0  alle verpflichtenden Gates bestanden
      1  mindestens ein Qualitaetsgate rot (dominiert 2)
      2  Pruefumgebung unvollstaendig oder fehlerhaft
      3  ungueltiger Aufruf

    Muss unter Windows PowerShell 5.1 und PowerShell 7 (pwsh, auch Linux)
    identisch laufen. Nur ASCII in dieser Datei (PS 5.1 liest UTF-8 ohne BOM
    als ANSI).

    VIRTUSPHERE_CHECK_ROOT uebersteuert das Repo-Root; der Guard-Harness
    (scripts/test-guards.ps1) nutzt das, um Gates gegen mutierte Fixtures zu
    beweisen. Datei-scannende Gates behandeln null Treffer als
    infrastructure_error (Zero-Match darf nie leer gruen werden).

.PARAMETER Lane
    Fast (jeder PR, lokaler Vorabcheck), Integration (Merge/Nightly),
    Release (vor Auslieferung). Default: Fast.

.PARAMETER Gate
    Nur die genannten Gates ausfuehren (Namen wie in -List).

.PARAMETER List
    Gates der gewaehlten Lane auflisten, nichts ausfuehren.

.PARAMETER Json
    Pfad fuer das maschinenlesbare Ergebnisartefakt (UTF-8, ohne Secrets).

.PARAMETER KeepArtifacts
    Gate-Logs im Artefaktverzeichnis behalten (Pfad wird ausgegeben).

.PARAMETER FailFast
    Nach dem ersten fail/infrastructure_error abbrechen.

.PARAMETER NoNetwork
    Netzabhaengige Gates als not_applicable markieren; fehlende Tool-Images
    werden nicht nachgezogen.

.EXAMPLE
    powershell -NoProfile -File scripts\check.ps1 -Lane Fast -Json qa-artifacts/qa-fast.json
#>
[CmdletBinding()]
param(
    [string]$Lane = 'Fast',
    [string[]]$Gate,
    [switch]$List,
    [string]$Json,
    [switch]$KeepArtifacts,
    [switch]$FailFast,
    [switch]$NoNetwork
)

Set-StrictMode -Version 1.0
$ErrorActionPreference = 'Stop'

# --- Aufruf-Validierung (Exit 3) --------------------------------------------
# powershell.exe -File uebergibt Arrays als einen String: Kommas selbst splitten.
if ($Gate) { $Gate = @($Gate | ForEach-Object { $_ -split ',' } | Where-Object { $_ -ne '' }) }

$validLanes = @('Fast', 'Integration', 'Release')
if ($validLanes -notcontains $Lane) {
    Write-Host ("check.ps1: unbekannte Lane '{0}' (gueltig: {1})" -f $Lane, ($validLanes -join ', ')) -ForegroundColor Red
    exit 3
}

# --- Umgebung -----------------------------------------------------------------
$scriptDir = $PSScriptRoot
$repoRoot = $env:VIRTUSPHERE_CHECK_ROOT
if (-not $repoRoot) { $repoRoot = Split-Path $scriptDir -Parent }
$repoRoot = ($repoRoot -replace '\\', '/').TrimEnd('/')
$isWindowsHost = ($env:OS -eq 'Windows_NT')

# QA evidence belongs below one ignored directory. Keep explicit paths usable,
# but route the old bare qa-*.json spelling there as well so a copied command
# cannot litter the repository root. Relative paths are rooted at the repo,
# independent of the caller's current directory.
if ($Json) {
    $jsonLeaf = Split-Path -Leaf $Json
    $jsonParent = Split-Path -Parent $Json
    if ((-not $jsonParent) -and $jsonLeaf -like 'qa-*.json') {
        $Json = Join-Path (Join-Path $repoRoot 'qa-artifacts') $jsonLeaf
    } elseif (-not [System.IO.Path]::IsPathRooted($Json)) {
        $Json = Join-Path $repoRoot $Json
    }
    $jsonDirectory = Split-Path -Parent $Json
    if ($jsonDirectory -and -not (Test-Path $jsonDirectory)) {
        New-Item -ItemType Directory -Force -Path $jsonDirectory | Out-Null
    }
}

# Tool-Images fuer containerisierte Gates kommen aus der Tool-Lockdatei
# (AP4-SSoT): scripts/tool-lock.json pinnt Registry-Images per Digest und
# verweist fuer lokal gebaute Images auf ihr Dockerfile. Eine fehlende oder
# kaputte Lockdatei ist eine unvollstaendige Pruefumgebung (Exit 2), denn ohne
# Pins wuerde jedes Gate gegen eine unbestimmte Toolversion pruefen.
$toolLockPath = Join-Path $scriptDir 'tool-lock.json'
$requiredToolImages = @('yamllint', 'actionlint', 'shellcheck', 'hadolint', 'ansible', 'python', 'php', 'gitleaks', 'trivy')
if (-not (Test-Path $toolLockPath)) {
    Write-Host ('check.ps1: Tool-Lockdatei fehlt: {0}' -f $toolLockPath) -ForegroundColor Yellow
    exit 2
}
try {
    $toolLock = (Get-Content -Raw -Path $toolLockPath) | ConvertFrom-Json
} catch {
    Write-Host ('check.ps1: Tool-Lockdatei unlesbar ({0}): {1}' -f $toolLockPath, $_.Exception.Message) -ForegroundColor Yellow
    exit 2
}
$toolImages = @{}
foreach ($name in $requiredToolImages) {
    $entry = $toolLock.dockerImages.PSObject.Properties[$name]
    if ((-not $entry) -or (-not $entry.Value.ref)) {
        Write-Host ('check.ps1: Tool-Lockdatei ohne dockerImages.{0}.ref' -f $name) -ForegroundColor Yellow
        exit 2
    }
    $toolImages[$name] = [string]$entry.Value.ref
}
# Der App-Toolpfad mountet immer den aktuellen Pruef-Root, damit ein Clean
# Checkout nie versehentlich den Code eines parallel laufenden Dev-Stacks
# prueft. Ein fehlendes Image fuehrt nie zu einer Ausfuehrung im Dev-Stack.
# Alle Integration-Gates laufen gegen den QA-Stack.
$phpContainer = 'virtusphere-v2-webapp-php-1'

# --- QA-Wegwerf-Stack (Integration-/Release-Lane) ------------------------------
# Die Werte gehoeren lib/check/qa-identity.ps1, damit der getrennte
# Baseline-Updatebefehl exakt denselben Stack trifft; hier werden sie einmal
# aufgeloest, weil der Runner die Initialisierung besitzt.
. (Join-Path (Join-Path $scriptDir 'lib/check') 'qa-identity.ps1')
$qaIdentity = Get-QaStackIdentity $repoRoot
$qaProject = $qaIdentity.Project
$qaPhpContainer = $qaIdentity.PhpContainer
$qaWebContainer = $qaIdentity.WebContainer
$qaMysqlContainer = $qaIdentity.MysqlContainer
$qaNetwork = $qaIdentity.Network
$qaPortalBase = $qaIdentity.PortalBase
$qaDir = $qaIdentity.Dir
$qaEnvFile = $qaIdentity.EnvFile
$qaComposeOverride = $qaIdentity.ComposeOverride
$qaServices = $qaIdentity.Services
$script:qaStackStarted = $false

# --- Fokussierte Runner-Module ----------------------------------------------
$checkModuleDir = Join-Path $scriptDir 'lib/check'
foreach ($module in @('runtime.ps1', 'registry.ps1', 'phpmyadmin.ps1', 'gates-fast.ps1', 'gates-integration.ps1', 'gates-release.ps1')) {
    . (Join-Path $checkModuleDir $module)
}

$gates = New-Object System.Collections.ArrayList
$allLanes = @('Fast', 'Integration', 'Release')
$intRel = @('Integration', 'Release')
$shExe = Find-Sh

Register-FastCheckGates
Register-IntegrationCheckGates
Register-ReleaseCheckGates

# --- Auswahl ------------------------------------------------------------------
$selected = @($gates | Where-Object { $_.Lanes -contains $Lane })
if ($Gate) {
    $known = @($gates | ForEach-Object { $_.Name })
    foreach ($g in $Gate) {
        if ($known -notcontains $g) {
            Write-Host ("check.ps1: unbekanntes Gate '{0}' (siehe -List)" -f $g) -ForegroundColor Red
            exit 3
        }
    }
    $selected = @($gates | Where-Object { $Gate -contains $_.Name })
}

if ($List) {
    Write-Host ("Lane {0}: {1} Gate(s)" -f $Lane, $selected.Count)
    foreach ($g in $selected) {
        $net = ''
        if ($g.Network) { $net = ' [netz]' }
        Write-Host ('  {0,-22} {1,-13} lanes={2}{3}' -f $g.Name, $g.Kind, ($g.Lanes -join ','), $net)
    }
    exit 0
}

# --- Ausfuehrung --------------------------------------------------------------
$originalCheckRoot = $env:VIRTUSPHERE_CHECK_ROOT
$env:VIRTUSPHERE_CHECK_ROOT = $repoRoot

$runStamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$artifactDir = Join-Path ([System.IO.Path]::GetTempPath()) ('virtusphere-qa-' + $runStamp)
$dockerAvailable = Test-Command 'docker'
$results = @()
$startedAt = Get-Date

try {
    New-Item -ItemType Directory -Force -Path $artifactDir | Out-Null

    Write-Host ('==> VirtuSphere check.ps1 - Lane {0}, Root {1}' -f $Lane, $repoRoot) -ForegroundColor Cyan

    $gateNumber = 0
    $gateTotal = $selected.Count
    foreach ($g in $selected) {
        $gateNumber++
        # Emit before the body starts so long-running container/E2E gates have
        # an immediately visible position in the lane instead of looking
        # stalled until their captured tool output is available.
        Write-Host ('[{0}/{1}] RUN  {2}' -f $gateNumber, $gateTotal, $g.Name) -ForegroundColor Cyan
        $sw = [System.Diagnostics.Stopwatch]::StartNew()
        $outcome = $null

        if ($g.Network -and $NoNetwork) {
            $outcome = New-NaResult 'netzabhaengig, Lauf mit -NoNetwork'
        } elseif (($g.Kind -eq 'container') -and (-not $dockerAvailable)) {
            $outcome = New-InfraResult 'Docker fehlt; containerisierte Gates setzen Docker voraus (L8), kein Skip'
        } else {
            try {
                $outcome = & $g.Body
            } catch {
                $outcome = New-InfraResult ('Gate warf eine Exception: ' + $_.Exception.Message)
            }
        }
        $sw.Stop()

        $entry = @{
            name = $g.Name
            kind = $g.Kind
            network = [bool]$g.Network
            class = $outcome.class
            detail = [string]$outcome.detail
            durationSeconds = [math]::Round($sw.Elapsed.TotalSeconds, 1)
        }
        $results += $entry

        $gateOutput = @()
        if ($outcome.ContainsKey('output')) { $gateOutput = @($outcome.output) }
        if ($gateOutput.Count -gt 0) {
            $logPath = Join-Path $artifactDir ($g.Name + '.log')
            [System.IO.File]::WriteAllLines($logPath, [string[]]$gateOutput)
        }

        $color = 'Green'
        switch ($outcome.class) {
            'fail' { $color = 'Red' }
            'infrastructure_error' { $color = 'Yellow' }
            'not_applicable' { $color = 'DarkGray' }
            'skip' { $color = 'DarkGray' }
        }
        Write-Host ('[{0}/{1}] {2} {3} ({4}s) {5}' -f $gateNumber, $gateTotal, $outcome.class, $g.Name, $entry.durationSeconds, $outcome.detail) -ForegroundColor $color
        if ($outcome.class -eq 'fail' -or $outcome.class -eq 'infrastructure_error') {
            foreach ($line in ($gateOutput | Select-Object -Last 25)) { Write-Host ('    ' + $line) }
            if ($FailFast) { break }
        }
    }
} finally {
    $env:VIRTUSPHERE_CHECK_ROOT = $originalCheckRoot
    if ($script:qaStackStarted) {
        if ($KeepArtifacts) {
            Write-Host ('QA-Stack bleibt stehen (-KeepArtifacts); aufraeumen mit: docker compose -p {0} down -v --remove-orphans' -f $qaProject) -ForegroundColor Yellow
        } else {
            Write-Host ('==> QA-Stack abraeumen ({0})' -f $qaProject) -ForegroundColor Cyan
            [void](Invoke-QaCompose @('down', '-v', '--remove-orphans'))
        }
    }
}

# --- Summe, Artefakt, Exitcode -------------------------------------------------
$summary = @{ pass = 0; fail = 0; skip = 0; not_applicable = 0; infrastructure_error = 0 }
foreach ($r in $results) { $summary[$r.class] = $summary[$r.class] + 1 }

$exitCode = 0
if ($summary.infrastructure_error -gt 0) { $exitCode = 2 }
if ($summary.fail -gt 0) { $exitCode = 1 }

$commit = ''
$gitInfo = Invoke-Tool 'git' @('-C', $repoRoot, 'rev-parse', '--short', 'HEAD')
if ($gitInfo.ExitCode -eq 0) { $commit = (@($gitInfo.Output) -join '').Trim() }

Write-Host ''
Write-Host ('Lane {0}: {1} pass, {2} fail, {3} infrastructure_error, {4} not_applicable, {5} skip => Exit {6}' -f `
    $Lane, $summary.pass, $summary.fail, $summary.infrastructure_error, $summary.not_applicable, $summary.skip, $exitCode) `
    -ForegroundColor $(if ($exitCode -eq 0) { 'Green' } elseif ($exitCode -eq 1) { 'Red' } else { 'Yellow' })

if ($Json) {
    $artifact = @{
        version = 1
        lane = $Lane
        commit = $commit
        started = $startedAt.ToString('s')
        durationSeconds = [math]::Round(((Get-Date) - $startedAt).TotalSeconds, 1)
        noNetwork = [bool]$NoNetwork
        results = $results
        summary = $summary
        exitCode = $exitCode
    }
    $jsonText = $artifact | ConvertTo-Json -Depth 6
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Json, $jsonText, $utf8NoBom)
    Write-Host ('QA-Artefakt: ' + $Json)
}

if ($KeepArtifacts) {
    Write-Host ('Gate-Logs: ' + $artifactDir)
} else {
    Remove-Item -Recurse -Force -Path $artifactDir -ErrorAction SilentlyContinue
}

exit $exitCode
