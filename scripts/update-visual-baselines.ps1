#Requires -Version 5.1
<#
.SYNOPSIS
    Der getrennte, ausdrueckliche Updatebefehl fuer die reviewten visuellen
    Sollbaselines (Etappe 17). KEIN Gate und in keiner Lane enthalten.

.DESCRIPTION
    check.ps1 loescht die Update-Variablen vor jedem Harnesslauf, und der
    Harness verweigert den Start, solange eine davon gesetzt ist. Sollbilder
    entstehen deshalb nur hier, von Hand, mit einem Grund.

    Die Aufnahmebedingungen muessen exakt die des pruefenden Harness sein,
    sonst waere jedes Sollbild eines, das kein Gate reproduzieren kann: derselbe
    QA-Wegwerfstack, dieselben pausierten Worker, dieselben Umgebungswerte. Das
    ist der Grund, warum dieser Befehl den Stack nicht selbst startet, sondern
    das Gate dafuer benennt; die Stackidentitaet teilt er sich mit dem Runner
    ueber lib/check/qa-identity.ps1.

    Vorher:
      powershell -NoProfile -File scripts\check.ps1 -Lane Integration -Gate qa-stack -KeepArtifacts

    Danach werden Diffbild, Vorherbild und Runner-Metadaten im Reviewordner
    geprueft und die geaenderten PNGs bewusst committet. Vorherbilder sind
    Auditartefakte und niemals eine zweite Sollbaseline.

.PARAMETER Reason
    Der fachliche Grund, der mit der Baseline gespeichert wird. Ohne ihn ist
    spaeter nicht mehr entscheidbar, ob ein Bild einer Absicht oder einem
    unbemerkten Nebeneffekt folgte.

.EXAMPLE
    powershell -NoProfile -File scripts\update-visual-baselines.ps1 -Reason "Etappe 16: Slate-/Indigo-Refresh"
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$Reason
)

Set-StrictMode -Version 1.0
$ErrorActionPreference = 'Stop'

$scriptDir = $PSScriptRoot
$repoRoot = $env:VIRTUSPHERE_CHECK_ROOT
if (-not $repoRoot) { $repoRoot = Split-Path $scriptDir -Parent }
$repoRoot = ($repoRoot -replace '\\', '/').TrimEnd('/')

. (Join-Path (Join-Path $scriptDir 'lib/check') 'qa-identity.ps1')
. (Join-Path (Join-Path $scriptDir 'lib/check') 'runtime.ps1')

# Dieselbe Aufloesung wie in check.ps1; runtime.ps1 liest genau diese Namen.
$qaIdentity = Get-QaStackIdentity $repoRoot
$qaProject = $qaIdentity.Project
$qaPhpContainer = $qaIdentity.PhpContainer
$qaMysqlContainer = $qaIdentity.MysqlContainer
$qaPortalBase = $qaIdentity.PortalBase
$qaEnvFile = $qaIdentity.EnvFile
$qaComposeOverride = $qaIdentity.ComposeOverride

if (-not (Test-Command 'node')) {
    Write-Host 'update-visual-baselines: node nicht gefunden' -ForegroundColor Red
    exit 2
}
if (-not (Test-Container $qaPhpContainer)) {
    Write-Host 'update-visual-baselines: QA-Stack laeuft nicht.' -ForegroundColor Red
    Write-Host '  powershell -NoProfile -File scripts\check.ps1 -Lane Integration -Gate qa-stack -KeepArtifacts' -ForegroundColor Yellow
    exit 2
}

$e2eDir = Join-Path (Join-Path $repoRoot 'tests') 'e2e'
$updater = Join-Path (Join-Path $e2eDir 'visual') 'update-baselines.js'
if (-not (Test-Path $updater)) {
    Write-Host ('update-visual-baselines: {0} fehlt' -f $updater) -ForegroundColor Red
    exit 2
}
if (-not (Test-Path (Join-Path $e2eDir 'node_modules'))) {
    Write-Host 'update-visual-baselines: tests/e2e/node_modules fehlt (npm ci in tests/e2e)' -ForegroundColor Red
    exit 2
}

$reviewDir = Join-Path (Join-Path $repoRoot 'qa-artifacts') ('visual-baseline-review-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
$previous = @{}
$updateEnv = @{
    VIRTUSPHERE_BASE_URL               = ($qaPortalBase + '/portal/')
    VIRTUSPHERE_PHP_CONTAINER          = $qaPhpContainer
    VIRTUSPHERE_MYSQL_CONTAINER        = $qaMysqlContainer
    VIRTUSPHERE_ADMIN_USER             = (Get-QaEnvValue 'SEED_ADMIN_USER')
    VIRTUSPHERE_ADMIN_PASS             = (Get-QaEnvValue 'SEED_ADMIN_PASSWORD')
    VIRTUSPHERE_QA_PROJECT             = $qaProject
    VIRTUSPHERE_VISUAL_QA_ALLOWED      = '1'
    VIRTUSPHERE_VISUAL_ARTIFACT_DIR    = $reviewDir
    VIRTUSPHERE_VISUAL_BASELINE_UPDATE = '1'
    DB_NAME                            = (Get-QaEnvValue 'DB_NAME')
}
foreach ($key in $updateEnv.Keys) {
    $previous[$key] = [Environment]::GetEnvironmentVariable($key)
    [Environment]::SetEnvironmentVariable($key, [string]$updateEnv[$key])
}

try {
    $outcome = Invoke-WithPausedQaWorkers {
        Push-Location $e2eDir
        try { $run = Invoke-Tool 'node' @($updater, '--reason', $Reason) } finally { Pop-Location }
        if ($run.ExitCode -eq 0) { return New-PassResult 'Sollbaselines aktualisiert' $run.Output }
        return New-FailResult 'Baseline-Update abgelehnt oder fehlgeschlagen' $run.Output
    }
} finally {
    foreach ($key in $previous.Keys) { [Environment]::SetEnvironmentVariable($key, $previous[$key]) }
}

foreach ($line in @($outcome.output)) { if ($line) { Write-Host $line } }
Write-Host ('==> {0}: {1}' -f $outcome.class, $outcome.detail)
if ($outcome.class -eq 'pass') {
    Write-Host ('Review: {0}' -f $reviewDir) -ForegroundColor Cyan
    Write-Host 'Jedes Diffbild pruefen, dann tests/e2e/visual/baselines/ bewusst committen.' -ForegroundColor Cyan
    exit 0
}
if ($outcome.class -eq 'fail') { exit 1 }
exit 2
