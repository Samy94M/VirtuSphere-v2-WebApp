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
    powershell -NoProfile -File scripts\check.ps1 -Lane Fast -Json qa.json
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

# Tool-Images fuer containerisierte Gates kommen aus der Tool-Lockdatei
# (AP4-SSoT): scripts/tool-lock.json pinnt Registry-Images per Digest und
# verweist fuer lokal gebaute Images auf ihr Dockerfile. Eine fehlende oder
# kaputte Lockdatei ist eine unvollstaendige Pruefumgebung (Exit 2), denn ohne
# Pins wuerde jedes Gate gegen eine unbestimmte Toolversion pruefen.
$toolLockPath = Join-Path $scriptDir 'tool-lock.json'
$requiredToolImages = @('yamllint', 'actionlint', 'shellcheck', 'hadolint', 'ansible', 'python', 'php')
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
$phpContainer = 'virtusphere-v2-webapp-php-1'
$webContainer = 'virtusphere-v2-webapp-webserver-1'
$portalBase = 'http://127.0.0.1:8021'

# --- Ergebnis-Helfer ----------------------------------------------------------
function New-GateOutcome {
    param([string]$Class, [string]$Detail = '', [string[]]$Output = @())
    return @{ class = $Class; detail = $Detail; output = $Output }
}
function New-PassResult  { param([string]$Detail = '', [string[]]$Output = @()) New-GateOutcome 'pass' $Detail $Output }
function New-FailResult  { param([string]$Detail = '', [string[]]$Output = @()) New-GateOutcome 'fail' $Detail $Output }
function New-InfraResult { param([string]$Detail = '', [string[]]$Output = @()) New-GateOutcome 'infrastructure_error' $Detail $Output }
function New-NaResult    { param([string]$Detail = '') New-GateOutcome 'not_applicable' $Detail }

# Native Kommandos so ausfuehren, dass stderr unter PS 5.1 nicht als
# NativeCommandError terminiert und der Exitcode erhalten bleibt.
function Invoke-Tool {
    param([string]$Exe, [string[]]$Arguments = @())
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $lines = @()
    try {
        $lines = @(& $Exe @Arguments 2>&1 | ForEach-Object { "$_" })
        $code = $LASTEXITCODE
    } catch {
        $lines = @("$($_.Exception.Message)")
        $code = 127
    } finally {
        $ErrorActionPreference = $prev
    }
    return @{ ExitCode = $code; Output = $lines }
}

function Test-Command { param([string]$Name) return [bool](Get-Command $Name -ErrorAction SilentlyContinue) }

function Test-DockerImage {
    param([string]$Image)
    $r = Invoke-Tool 'docker' @('image', 'inspect', '--format', 'ok', $Image)
    return ($r.ExitCode -eq 0)
}

function Test-Container {
    param([string]$Name)
    $r = Invoke-Tool 'docker' @('exec', $Name, 'true')
    return ($r.ExitCode -eq 0)
}

# Git-Bash-sh finden (Windows) bzw. sh vom PATH (Linux/pwsh).
function Find-Sh {
    if (Test-Command 'sh') { return (Get-Command 'sh').Source }
    $git = Get-Command 'git' -ErrorAction SilentlyContinue
    if ($git) {
        $gitRoot = Split-Path (Split-Path $git.Source -Parent) -Parent
        foreach ($candidate in @('usr/bin/sh.exe', 'bin/sh.exe')) {
            $probe = Join-Path $gitRoot $candidate
            if (Test-Path $probe) { return $probe }
        }
    }
    return $null
}
$shExe = Find-Sh

# Ein Repo-Shellskript ausfuehren: Host-sh, sonst Projekt-PHP-Image (enthaelt sh).
function Invoke-CheckShell {
    param([string]$ScriptName, [string[]]$Arguments = @())
    $scriptPath = (Join-Path $scriptDir $ScriptName) -replace '\\', '/'
    if ($shExe) {
        # sh.exe direkt gestartet hat kein usr/bin im PATH (grep/sed/wc fehlen
        # sonst und Checks werden falsch gruen oder rot). Fuer den Kindprozess
        # das Verzeichnis von sh.exe voranstellen.
        $prevPath = $env:PATH
        $env:PATH = (Split-Path $shExe -Parent) + [System.IO.Path]::PathSeparator + $env:PATH
        try {
            return Invoke-Tool $shExe (@($scriptPath) + $Arguments)
        } finally {
            $env:PATH = $prevPath
        }
    }
    if ((Test-Command 'docker') -and (Test-DockerImage $toolImages.php)) {
        $dockerArgs = @('run', '--rm',
            '-v', ($scriptDir + ':/checker:ro'),
            '-v', ($repoRoot + ':/checkroot'),
            '-e', 'VIRTUSPHERE_CHECK_ROOT=/checkroot',
            $toolImages.php, 'sh', ('/checker/' + $ScriptName)) + $Arguments
        return Invoke-Tool 'docker' $dockerArgs
    }
    return $null
}

# Ein Repo-PHP-Skript ausfuehren: Host-php, sonst Projekt-PHP-Image.
function Invoke-CheckPhp {
    param([string]$ScriptName, [string[]]$Arguments = @())
    if (Test-Command 'php') {
        return Invoke-Tool 'php' (@((Join-Path $scriptDir $ScriptName)) + $Arguments)
    }
    if ((Test-Command 'docker') -and (Test-DockerImage $toolImages.php)) {
        $dockerArgs = @('run', '--rm',
            '-v', ($scriptDir + ':/checker:ro'),
            '-v', ($repoRoot + ':/checkroot'),
            '-e', 'VIRTUSPHERE_CHECK_ROOT=/checkroot',
            $toolImages.php, 'php', ('/checker/' + $ScriptName)) + $Arguments
        return Invoke-Tool 'docker' $dockerArgs
    }
    return $null
}

# Composer im App-Kontext: bevorzugt der laufende Compose-Container (dessen
# Bind-Mount /var/www/html ist Docker/WebAPI), sonst ein frisches docker run
# mit Repo-Mount. Der zweite Pfad traegt CI, wo der Stack nicht laeuft: das
# Projekt-Image bringt composer mit, vendor/ kommt aus dem Mount.
function Invoke-AppComposer {
    param([string[]]$Arguments)
    if (Test-Container $phpContainer) {
        return Invoke-Tool 'docker' (@('exec', '-w', '/var/www/html', $phpContainer, 'composer') + $Arguments)
    }
    if (Test-DockerImage $toolImages.php) {
        return Invoke-Tool 'docker' (@('run', '--rm',
            '-v', ($repoRoot + ':/repo'), '-w', '/repo/Docker/WebAPI',
            '-e', 'COMPOSER_CACHE_DIR=/tmp/composer-cache', '-e', 'COMPOSER_ALLOW_SUPERUSER=1',
            $toolImages.php, 'composer') + $Arguments)
    }
    return $null
}

# Dateien unter dem Pruef-Root sammeln; vendor/node_modules/.git/var und den
# C#-Build-Output (bin/obj/.vs kopiert die Playbooks nach bin/Debug) ausnehmen.
function Get-CheckFiles {
    param([string[]]$Patterns)
    $excludeRe = '[\\/](vendor|node_modules|\.git|\.vs|var|bin|obj)[\\/]'
    $found = @()
    foreach ($pattern in $Patterns) {
        $found += @(Get-ChildItem -Path $repoRoot -Recurse -File -Filter $pattern -ErrorAction SilentlyContinue |
            Where-Object { $_.FullName -notmatch $excludeRe } |
            ForEach-Object { $_.FullName })
    }
    return @($found | Sort-Object -Unique)
}

function ConvertTo-RepoRelative {
    param([string[]]$Paths)
    return @($Paths | ForEach-Object { (($_ -replace '\\', '/') -replace [regex]::Escape($repoRoot + '/'), '') })
}

function Format-ToolResult {
    param($Result, [string]$OkDetail, [string]$FailDetail)
    if ($Result.ExitCode -eq 0) { return New-PassResult $OkDetail $Result.Output }
    return New-FailResult ("{0} (exit {1})" -f $FailDetail, $Result.ExitCode) $Result.Output
}

# --- Gate-Registry ------------------------------------------------------------
$gates = New-Object System.Collections.ArrayList
function Add-Gate {
    param(
        [string]$Name,
        [string[]]$Lanes,
        [ValidateSet('native', 'container', 'windows-only')] [string]$Kind,
        [bool]$Network = $false,
        [scriptblock]$Body
    )
    [void]$gates.Add(@{ Name = $Name; Lanes = $Lanes; Kind = $Kind; Network = $Network; Body = $Body })
}

$allLanes = @('Fast', 'Integration', 'Release')
$intRel = @('Integration', 'Release')

Add-Gate -Name 'compose-config' -Lanes $allLanes -Kind 'container' -Body {
    $r = Invoke-Tool 'docker' @('compose', '--project-directory', $repoRoot, 'config', '--quiet')
    Format-ToolResult $r 'docker-compose.yml valide' 'docker compose config meldet Fehler'
}

Add-Gate -Name 'php-lint' -Lanes $allLanes -Kind 'container' -Body {
    $r = $null
    if ((Test-Command 'php') -and $shExe) {
        $r = Invoke-CheckShell 'php-lint-all.sh' @()
    } elseif ((Test-Command 'docker') -and (Test-DockerImage $toolImages.php)) {
        $r = Invoke-Tool 'docker' @('run', '--rm',
            '-v', ($scriptDir + ':/checker:ro'),
            '-v', ($repoRoot + ':/checkroot:ro'),
            '-e', 'VIRTUSPHERE_CHECK_ROOT=/checkroot',
            $toolImages.php, 'sh', '/checker/php-lint-all.sh')
    } else {
        return New-InfraResult 'weder Host-PHP+sh noch Projekt-Image verfuegbar'
    }
    if ($r.ExitCode -eq 9) { return New-InfraResult 'php-lint fand keine Dateien oder kein php (Zero-Match)' $r.Output }
    Format-ToolResult $r (@($r.Output) -join '; ') 'php -l meldet Syntaxfehler'
}

Add-Gate -Name 'phpunit-unit' -Lanes $allLanes -Kind 'container' -Body {
    if (-not (Test-DockerImage $toolImages.php)) { return New-InfraResult ('Projekt-Image {0} fehlt' -f $toolImages.php) }
    # docker run mit vollem Repo-Mount statt exec in den App-Container: die
    # Repo-Level-Contract-Tests (nginx/php-Config) sehen sonst ihre Dateien
    # nicht und wuerden skippen; die Fast-Lane laeuft ohne Skips.
    $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo'), '-w', '/repo/Docker/WebAPI',
        $toolImages.php, 'php', 'vendor/bin/phpunit', '--testsuite', 'unit', '--fail-on-skipped')
    Format-ToolResult $r 'Unit/Static-Suite gruen (ohne Skips)' 'PHPUnit Unit/Static rot oder geskippt'
}

Add-Gate -Name 'phpstan' -Lanes $allLanes -Kind 'container' -Body {
    $r = Invoke-AppComposer @('run', 'stan')
    if ($null -eq $r) { return New-InfraResult ('weder Container {0} noch Image {1} verfuegbar' -f $phpContainer, $toolImages.php) }
    Format-ToolResult $r 'PHPStan gruen (Baseline-Ratchet)' 'PHPStan meldet neue Befunde'
}

Add-Gate -Name 'composer-validate' -Lanes $allLanes -Kind 'container' -Body {
    $v = Invoke-AppComposer @('validate', '--strict', '--no-check-publish')
    if ($null -eq $v) { return New-InfraResult ('weder Container {0} noch Image {1} verfuegbar' -f $phpContainer, $toolImages.php) }
    if ($v.ExitCode -ne 0) { return New-FailResult 'composer validate --strict rot' $v.Output }
    $p = Invoke-AppComposer @('check-platform-reqs')
    Format-ToolResult $p 'composer.json valide, Plattform-Anforderungen erfuellt' 'composer check-platform-reqs rot'
}

Add-Gate -Name 'composer-audit' -Lanes $allLanes -Kind 'container' -Network $true -Body {
    $r = Invoke-AppComposer @('audit', '--locked')
    if ($null -eq $r) { return New-InfraResult ('weder Container {0} noch Image {1} verfuegbar' -f $phpContainer, $toolImages.php) }
    Format-ToolResult $r 'keine bekannten Advisories' 'composer audit meldet Advisories'
}

Add-Gate -Name 'lang-parity' -Lanes $allLanes -Kind 'native' -Body {
    $r = Invoke-CheckPhp 'lang-audit.php' @('--ci')
    if ($null -eq $r) { return New-InfraResult 'weder Host-PHP noch Projekt-Image verfuegbar' }
    Format-ToolResult $r 'DE/EN-Paritaet und Placeholder synchron' 'Lang-Audit meldet Paritaets-/Placeholder-Luecken'
}

Add-Gate -Name 'enum-sync' -Lanes $allLanes -Kind 'native' -Body {
    $r = Invoke-CheckShell 'check-enum-sync.sh' @('--ci')
    if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar (Git Bash oder Docker noetig)' }
    Format-ToolResult $r 'ENUM-Spiegel synchron' 'ENUM-SSoT-Drift'
}

Add-Gate -Name 'php-version-sync' -Lanes $allLanes -Kind 'native' -Body {
    $r = Invoke-CheckShell 'check-php-version-sync.sh' @('--ci')
    if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar (Git Bash oder Docker noetig)' }
    Format-ToolResult $r 'PHP-Version ueberall synchron' 'PHP-Version-Drift'
}

Add-Gate -Name 'bounds-sync' -Lanes $allLanes -Kind 'native' -Body {
    $r = Invoke-CheckPhp 'check-bounds-sync.php' @('--ci')
    if ($null -eq $r) { return New-InfraResult 'weder Host-PHP noch Projekt-Image verfuegbar' }
    Format-ToolResult $r 'keine ausgeschriebenen Grenzwerte' 'Grenzwert-Drift in Portal-Texten'
}

Add-Gate -Name 'doc-hygiene' -Lanes $allLanes -Kind 'native' -Body {
    $r = Invoke-CheckShell 'check-doc-hygiene.sh' @('--ci')
    if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar (Git Bash oder Docker noetig)' }
    Format-ToolResult $r 'Agenten-Dokus sauber' 'Doku-Hygiene verletzt'
}

Add-Gate -Name 'csp-patterns' -Lanes $allLanes -Kind 'native' -Body {
    $r = Invoke-CheckShell 'lint-csp-patterns.sh' @('--worktree')
    if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar (Git Bash oder Docker noetig)' }
    if ((@($r.Output) -join ' ') -match '\[csp\.no-git\]') { return New-InfraResult 'CSP-Scan ohne Git-Repo/git im PATH' $r.Output }
    if ($r.ExitCode -eq 0) {
        $warnCount = @($r.Output | Where-Object { $_ -match '^WARN:' }).Count
        return New-PassResult ("keine harten CSP-Befunde ({0} Warnung(en))" -f $warnCount) $r.Output
    }
    return New-FailResult 'harte CSP-Pattern-Befunde im Worktree' $r.Output
}

Add-Gate -Name 'js-syntax' -Lanes $allLanes -Kind 'native' -Body {
    if (-not (Test-Command 'node')) { return New-InfraResult 'node nicht gefunden' }
    $assets = Join-Path (Join-Path (Join-Path (Join-Path $repoRoot 'Docker') 'WebAPI') 'portal') 'assets'
    $files = @(Get-ChildItem -Path $assets -Filter '*.js' -File -ErrorAction SilentlyContinue | ForEach-Object { $_.FullName })
    if ($files.Count -eq 0) { return New-InfraResult 'keine Portal-JS-Dateien gefunden (Zero-Match)' }
    $bad = @()
    foreach ($f in $files) {
        $r = Invoke-Tool 'node' @('--check', $f)
        if ($r.ExitCode -ne 0) { $bad += $r.Output }
    }
    if ($bad.Count -gt 0) { return New-FailResult 'node --check meldet Syntaxfehler' $bad }
    New-PassResult ("{0} Portal-Skript(e) syntaktisch sauber" -f $files.Count)
}

Add-Gate -Name 'powershell-syntax' -Lanes $allLanes -Kind 'native' -Body {
    $files = Get-CheckFiles @('*.ps1', '*.psm1', '*.psd1')
    if ($files.Count -eq 0) { return New-InfraResult 'keine PowerShell-Dateien gefunden (Zero-Match)' }
    $bad = @()
    foreach ($f in $files) {
        $tokens = $null
        $parseErrors = $null
        [void][System.Management.Automation.Language.Parser]::ParseFile($f, [ref]$tokens, [ref]$parseErrors)
        if ($parseErrors -and $parseErrors.Count -gt 0) {
            foreach ($e in $parseErrors) { $bad += ('{0}:{1} {2}' -f $f, $e.Extent.StartLineNumber, $e.Message) }
        }
    }
    if ($bad.Count -gt 0) { return New-FailResult 'PowerShell-Parserfehler' $bad }
    New-PassResult ("{0} PowerShell-Datei(en) geparst" -f $files.Count)
}

Add-Gate -Name 'powershell-tests' -Lanes $allLanes -Kind 'native' -Body {
    $hostExe = 'powershell'
    if ($PSVersionTable.PSEdition -eq 'Core') { $hostExe = 'pwsh' }
    $r = Invoke-Tool $hostExe @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $scriptDir 'run-pester.ps1'))
    if ($r.ExitCode -ne 0 -and (@($r.Output) -join ' ') -match 'fehlt') {
        return New-InfraResult 'Pester/PSScriptAnalyzer-Module fehlen (PSGallery)' $r.Output
    }
    Format-ToolResult $r 'PSScriptAnalyzer und Pester gruen' 'PowerShell-Pruefungen rot'
}

Add-Gate -Name 'yaml-lint' -Lanes $allLanes -Kind 'container' -Body {
    $files = Get-CheckFiles @('*.yml', '*.yaml')
    if ($files.Count -eq 0) { return New-InfraResult 'keine YAML-Dateien gefunden (Zero-Match)' }
    if (-not (Test-DockerImage $toolImages.yamllint)) {
        if ($NoNetwork) { return New-InfraResult ('Tool-Image {0} fehlt lokal (NoNetwork: kein Pull)' -f $toolImages.yamllint) }
    }
    $rel = ConvertTo-RepoRelative $files
    # new-lines deaktiviert: die Zeilenenden haengen am Checkout (CRLF unter
    # Windows via .gitattributes/autocrlf), nicht am Dateiinhalt.
    $dockerArgs = @('run', '--rm', '-v', ($repoRoot + ':/data:ro'), '-w', '/data', $toolImages.yamllint,
        '-s', '-d', '{extends: relaxed, rules: {line-length: disable, new-lines: disable}}') + $rel
    $r = Invoke-Tool 'docker' $dockerArgs
    if ($r.ExitCode -gt 1 -and (@($r.Output) -join ' ') -match 'pull|not found|no such') {
        return New-InfraResult ('Tool-Image {0} nicht verfuegbar' -f $toolImages.yamllint) $r.Output
    }
    Format-ToolResult $r ("{0} YAML-Datei(en) sauber" -f $rel.Count) 'yamllint meldet Befunde'
}

Add-Gate -Name 'actionlint' -Lanes $allLanes -Kind 'container' -Body {
    $wfDir = Join-Path (Join-Path $repoRoot '.github') 'workflows'
    if (-not (Test-Path $wfDir)) { return New-InfraResult 'kein .github/workflows unter dem Pruef-Root (Zero-Match)' }
    if (-not (Test-DockerImage $toolImages.actionlint)) {
        if ($NoNetwork) { return New-InfraResult ('Tool-Image {0} fehlt lokal (NoNetwork: kein Pull)' -f $toolImages.actionlint) }
    }
    $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo:ro'), '-w', '/repo', $toolImages.actionlint)
    if ($r.ExitCode -gt 1 -and (@($r.Output) -join ' ') -match 'pull|not found|no such') {
        return New-InfraResult ('Tool-Image {0} nicht verfuegbar' -f $toolImages.actionlint) $r.Output
    }
    Format-ToolResult $r 'GitHub-Workflows sauber' 'actionlint meldet Befunde'
}

Add-Gate -Name 'ansible-syntax' -Lanes $allLanes -Kind 'container' -Body {
    $playbooks = @(Get-ChildItem -Path (Join-Path $repoRoot 'Ansible') -Filter '*_playbook.yml' -File -ErrorAction SilentlyContinue | ForEach-Object { $_.Name })
    if ($playbooks.Count -eq 0) { return New-InfraResult 'keine Playbooks unter Ansible/ gefunden (Zero-Match)' }
    if (-not (Test-DockerImage $toolImages.ansible)) {
        return New-InfraResult ('QA-Ansible-Image {0} fehlt (docker build -f Docker/qa-ansible/Dockerfile -t virtusphere-qa-ansible:latest .)' -f $toolImages.ansible)
    }
    $bad = @()
    foreach ($pb in $playbooks) {
        $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo:ro'), '-w', '/repo/Ansible', $toolImages.ansible, 'ansible-playbook', '--syntax-check', $pb)
        if ($r.ExitCode -ne 0) { $bad += $r.Output }
    }
    if ($bad.Count -gt 0) { return New-FailResult 'Playbook-Syntaxcheck rot' $bad }
    New-PassResult ("{0} Playbook(s) syntaktisch sauber" -f $playbooks.Count)
}

Add-Gate -Name 'ansible-lint' -Lanes $allLanes -Kind 'container' -Body {
    if (-not (Test-Path (Join-Path $repoRoot 'Ansible'))) { return New-InfraResult 'kein Ansible/ unter dem Pruef-Root (Zero-Match)' }
    if (-not (Test-DockerImage $toolImages.ansible)) {
        return New-InfraResult ('QA-Ansible-Image {0} fehlt (docker build -f Docker/qa-ansible/Dockerfile -t virtusphere-qa-ansible:latest .)' -f $toolImages.ansible)
    }
    $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo:ro'), '-w', '/repo', $toolImages.ansible, 'ansible-lint', '--strict', 'Ansible/')
    Format-ToolResult $r 'ansible-lint --strict sauber' 'ansible-lint meldet Befunde'
}

Add-Gate -Name 'shellcheck' -Lanes $allLanes -Kind 'container' -Body {
    $files = Get-CheckFiles @('*.sh')
    if ($files.Count -eq 0) { return New-InfraResult 'keine Shellskripte gefunden (Zero-Match)' }
    if (-not (Test-DockerImage $toolImages.shellcheck)) {
        if ($NoNetwork) { return New-InfraResult ('Tool-Image {0} fehlt lokal (NoNetwork: kein Pull)' -f $toolImages.shellcheck) }
    }
    $rel = ConvertTo-RepoRelative $files
    $r = Invoke-Tool 'docker' (@('run', '--rm', '-v', ($repoRoot + ':/mnt:ro'), $toolImages.shellcheck, '-S', 'warning') + $rel)
    if ($r.ExitCode -gt 1 -and (@($r.Output) -join ' ') -match 'pull|not found|no such') {
        return New-InfraResult ('Tool-Image {0} nicht verfuegbar' -f $toolImages.shellcheck) $r.Output
    }
    Format-ToolResult $r ("{0} Shellskript(e) sauber" -f $rel.Count) 'ShellCheck meldet Befunde'
}

Add-Gate -Name 'hadolint' -Lanes $allLanes -Kind 'container' -Body {
    $files = Get-CheckFiles @('Dockerfile')
    if ($files.Count -eq 0) { return New-InfraResult 'keine Dockerfiles gefunden (Zero-Match)' }
    if (-not (Test-DockerImage $toolImages.hadolint)) {
        if ($NoNetwork) { return New-InfraResult ('Tool-Image {0} fehlt lokal (NoNetwork: kein Pull)' -f $toolImages.hadolint) }
    }
    $rel = ConvertTo-RepoRelative $files
    $r = Invoke-Tool 'docker' (@('run', '--rm', '-v', ($repoRoot + ':/repo:ro'), '-w', '/repo', $toolImages.hadolint, 'hadolint') + $rel)
    if ($r.ExitCode -gt 1 -and (@($r.Output) -join ' ') -match 'pull|not found|no such') {
        return New-InfraResult ('Tool-Image {0} nicht verfuegbar' -f $toolImages.hadolint) $r.Output
    }
    Format-ToolResult $r ("{0} Dockerfile(s) sauber" -f $rel.Count) 'Hadolint meldet Befunde'
}

Add-Gate -Name 'python-client-tests' -Lanes $allLanes -Kind 'container' -Body {
    if (-not (Test-Path (Join-Path (Join-Path $repoRoot 'Ansible') 'tests'))) {
        return New-InfraResult 'Ansible/tests fehlt unter dem Pruef-Root (Zero-Match)'
    }
    if (-not (Test-DockerImage $toolImages.python)) {
        if ($NoNetwork) { return New-InfraResult ('Tool-Image {0} fehlt lokal (NoNetwork: kein Pull)' -f $toolImages.python) }
    }
    $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo:ro'), '-w', '/repo', $toolImages.python, 'python', '-m', 'unittest', 'discover', '-s', 'Ansible/tests')
    Format-ToolResult $r 'upload_mac_list-Client-Tests gruen' 'Python-Client-Tests rot'
}

# --- Integration-Lane ---------------------------------------------------------

Add-Gate -Name 'migrate-check' -Lanes $intRel -Kind 'container' -Body {
    if (-not (Test-Container $phpContainer)) { return New-InfraResult ('Container {0} laeuft nicht' -f $phpContainer) }
    $r = Invoke-Tool 'docker' @('exec', $phpContainer, 'php', '/var/www/html/lib/migrate.php', '--check')
    Format-ToolResult $r 'Migrationen konsistent (pending=0)' 'migrate.php --check rot'
}

Add-Gate -Name 'phpunit-full' -Lanes $intRel -Kind 'container' -Body {
    if (-not (Test-Container $phpContainer)) { return New-InfraResult ('Container {0} laeuft nicht' -f $phpContainer) }
    # --fail-on-skipped folgt erst mit der ADR-0015-Ergaenzung (Plan v2, E3/AP4).
    $r = Invoke-Tool 'docker' @('exec', $phpContainer, 'composer', '--working-dir=/var/www/html', 'test')
    Format-ToolResult $r 'vollstaendige PHPUnit-Suite gruen' 'PHPUnit-Suite rot'
}

Add-Gate -Name 'schema-convergence' -Lanes $intRel -Kind 'container' -Body {
    $r = Invoke-CheckShell 'check-schema-convergence.sh' @()
    if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar (Git Bash oder Docker noetig)' }
    if ($r.ExitCode -eq 2) { return New-InfraResult 'Konvergenz-Check ohne Umgebung (Stack/.env fehlt)' $r.Output }
    Format-ToolResult $r 'struktur.sql und migrate.php konvergieren' 'Schema-Konvergenz verletzt'
}

Add-Gate -Name 'health-contract' -Lanes $intRel -Kind 'container' -Body {
    if (-not (Test-Container $webContainer)) { return New-InfraResult ('Container {0} laeuft nicht' -f $webContainer) }
    $n = Invoke-Tool 'docker' @('exec', $webContainer, 'nginx', '-t')
    if ($n.ExitCode -ne 0) { return New-FailResult 'nginx -t rot' $n.Output }
    try {
        $health = Invoke-WebRequest -Uri ($portalBase + '/portal/health.php') -UseBasicParsing -TimeoutSec 10
        if ([int]$health.StatusCode -ne 200) { return New-FailResult ('health.php liefert HTTP {0} statt 200' -f $health.StatusCode) }
    } catch { return New-FailResult ('health.php nicht erreichbar: ' + $_.Exception.Message) }
    $testsStatus = 0
    try {
        $t = Invoke-WebRequest -Uri ($portalBase + '/tests/bootstrap.php') -UseBasicParsing -TimeoutSec 10
        $testsStatus = [int]$t.StatusCode
    } catch {
        if ($_.Exception.Response) { $testsStatus = [int]$_.Exception.Response.StatusCode } else { return New-InfraResult ('Portal nicht erreichbar: ' + $_.Exception.Message) }
    }
    if ($testsStatus -ne 403) { return New-FailResult ('/tests/bootstrap.php liefert HTTP {0} statt 403 (Exposure-Vertrag)' -f $testsStatus) }
    New-PassResult 'nginx -t OK, health=200, /tests=403'
}

Add-Gate -Name 'guard-harness' -Lanes $intRel -Kind 'native' -Body {
    $hostExe = 'powershell'
    if ($PSVersionTable.PSEdition -eq 'Core') { $hostExe = 'pwsh' }
    $r = Invoke-Tool $hostExe @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $scriptDir 'test-guards.ps1'))
    if ($r.ExitCode -eq 2) { return New-InfraResult 'Guard-Harness ohne vollstaendige Umgebung' $r.Output }
    Format-ToolResult $r 'alle Guards positiv/negativ/zero-match bewiesen' 'Guard-Harness meldet unbewiesene Guards'
}

Add-Gate -Name 'legacy-csharp-build' -Lanes $intRel -Kind 'windows-only' -Network $true -Body {
    if (-not $isWindowsHost) { return New-NaResult 'windows-only: Legacy-C#-Build braucht MSBuild/NuGet auf Windows' }
    if (-not (Test-Command 'msbuild')) { return New-InfraResult 'MSBuild nicht im PATH (Developer Command Prompt / Build Tools noetig)' }
    $r = Invoke-Tool 'msbuild' @((Join-Path $repoRoot 'VirtuSphere.sln'), '/t:Build', '/p:Configuration=Release', '/v:m', '/nologo')
    Format-ToolResult $r 'Legacy-Client baut reproduzierbar' 'MSBuild rot'
}

# --- Release-Lane -------------------------------------------------------------

Add-Gate -Name 'restore-drill' -Lanes @('Release') -Kind 'container' -Body {
    $r = Invoke-CheckShell 'restore_test.sh' @()
    if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar (Git Bash oder Docker noetig)' }
    if ($r.ExitCode -eq 2) { return New-InfraResult 'Restore-Drill ohne Umgebung (Stack/Backup fehlt)' $r.Output }
    Format-ToolResult $r 'Backup-/Restore-Drill gruen' 'Restore-Drill rot'
}

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

    foreach ($g in $selected) {
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
        Write-Host ('[{0}] {1} ({2}s) {3}' -f $outcome.class, $g.Name, $entry.durationSeconds, $outcome.detail) -ForegroundColor $color
        if ($outcome.class -eq 'fail' -or $outcome.class -eq 'infrastructure_error') {
            foreach ($line in ($gateOutput | Select-Object -Last 25)) { Write-Host ('    ' + $line) }
            if ($FailFast) { break }
        }
    }
} finally {
    $env:VIRTUSPHERE_CHECK_ROOT = $originalCheckRoot
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
