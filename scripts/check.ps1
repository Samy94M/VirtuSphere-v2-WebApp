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
# Dev-Stack-Container: letzter Fallback fuer Invoke-AppComposer; der
# primaere Pfad mountet immer den aktuellen Pruef-Root, damit ein Clean
# Checkout nie versehentlich den Code eines parallel laufenden Dev-Stacks
# prueft. Alle Integration-Gates laufen gegen den QA-Stack.
$phpContainer = 'virtusphere-v2-webapp-php-1'

# --- QA-Wegwerf-Stack (Integration-/Release-Lane) ------------------------------
# Eigenes Compose-Projekt aus docker-compose.yml + Docker/qa/docker-compose.qa.yml
# mit Docker/qa/qa.env: eigene Wegwerf-DB, eigene ssl/conf-Volumes, Ports 8031ff.
# Kein Integration-Gate laeuft gegen den Dev-Stack oder die Dev-Datenbank.
$qaProject = 'virtusphere-qa'
$qaPhpContainer = 'virtusphere-qa-php-1'
$qaWebContainer = 'virtusphere-qa-webserver-1'
$qaMysqlContainer = 'virtusphere-qa-mysql-1'
$qaNetwork = 'virtusphere-qa_default'
$qaPortalBase = 'http://127.0.0.1:8031'
$qaDir = Join-Path (Join-Path $repoRoot 'Docker') 'qa'
$qaEnvFile = Join-Path $qaDir 'qa.env'
$qaComposeOverride = Join-Path $qaDir 'docker-compose.qa.yml'
# ldap-* Dienste: hermetische LDAP-TLS-Fixture (Plan-Abschnitt 18.3, Etappe 7,
# Docker/qa/docker-compose.qa.yml). Immer Teil der Integrationslane, damit
# DirectoryLdapFixtureTest.php nie skippt (ADR-0015-Ergaenzung: kein Skip in
# dieser Lane).
$qaServices = @('webserver', 'php', 'mysql', 'deploy-worker', 'maintenance-worker',
    'ldap-dc1', 'ldap-dc2', 'ldap-badcert-unknown-ca', 'ldap-badcert-expired',
    'ldap-badcert-wrongname', 'ldap-dc-rotated', 'ldap-blackhole')
$script:qaStackStarted = $false

function Invoke-QaCompose {
    param([string[]]$Arguments)
    return Invoke-Tool 'docker' (@('compose', '-p', $qaProject,
        '--env-file', $qaEnvFile,
        '-f', (Join-Path $repoRoot 'docker-compose.yml'),
        '-f', $qaComposeOverride,
        '--project-directory', $repoRoot) + $Arguments)
}

# Einen Wert aus qa.env lesen (die Datei ist eingecheckt und enthaelt nur
# QA-Wegwerf-Werte, keine Geheimnisse).
function Get-QaEnvValue {
    param([string]$Name)
    foreach ($line in (Get-Content -Path $qaEnvFile -ErrorAction SilentlyContinue)) {
        if ($line -match ('^' + [regex]::Escape($Name) + '=(.*)$')) { return $Matches[1] }
    }
    return ''
}

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

# Playwright-Browsercache: liegt eine Engine (chromium/firefox/webkit) in
# einem der Playwright-Cache-Wurzelverzeichnisse? Der msedge-Channel laeuft
# nicht ueber den Cache, sondern ueber das installierte Edge.
function Test-PlaywrightEngineCache {
    param([string]$Engine)
    $cacheRoots = @()
    if ($env:PLAYWRIGHT_BROWSERS_PATH) { $cacheRoots += $env:PLAYWRIGHT_BROWSERS_PATH }
    if ($env:LOCALAPPDATA) { $cacheRoots += (Join-Path $env:LOCALAPPDATA 'ms-playwright') }
    if ($env:HOME) { $cacheRoots += (Join-Path (Join-Path $env:HOME '.cache') 'ms-playwright') }
    foreach ($cacheRoot in $cacheRoots) {
        if ((Test-Path $cacheRoot) -and (@(Get-ChildItem -Path $cacheRoot -Directory -Filter ($Engine + '-*') -ErrorAction SilentlyContinue).Count -gt 0)) {
            return $true
        }
    }
    return $false
}

# Chromium-Preflight statt Textmuster im Playwright-Output (die fehlt-Lektion
# aus dem Pester-Gate): env-Pfad, Playwright-Cache oder der Dev-Default aus
# playwright.config.js. Nichts davon da -> Infrastruktur.
function Test-PlaywrightChromium {
    if ($env:PLAYWRIGHT_CHROMIUM) {
        if (Test-Path $env:PLAYWRIGHT_CHROMIUM) { return $true }
    }
    if (Test-PlaywrightEngineCache 'chromium') { return $true }
    # Derselbe Literal-Default wie in tests/e2e/playwright.config.js.
    return (Test-Path 'C:\Users\Samy\AppData\Local\ms-playwright\chromium-1223\chrome-win64\chrome.exe')
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

# Composer im App-Kontext: primaer ein frisches docker run mit dem aktuellen
# Pruef-Root. Ein parallel laufender Dev-Container kann aus einem anderen
# Checkout gemountet sein und darf deshalb nie die bevorzugte Beweisquelle
# sein. Das Projekt-Image bringt composer mit, vendor/ kommt aus dem Mount.
# Der Dev-Container bleibt nur Fallback, falls sein Image-Tag fehlt.
function Invoke-AppComposer {
    param([string[]]$Arguments)
    if (Test-DockerImage $toolImages.php) {
        return Invoke-Tool 'docker' (@('run', '--rm',
            '-v', ($repoRoot + ':/repo'), '-w', '/repo/Docker/WebAPI',
            '-e', 'COMPOSER_CACHE_DIR=/tmp/composer-cache', '-e', 'COMPOSER_ALLOW_SUPERUSER=1',
            # Der Mount gehoert dem Host-User, composer laeuft als root: ohne
            # safe.directory verweigert git die Versionsermittlung und composer
            # verrauscht jeden Lauf mit "dubious ownership"-Fatals (CI-Lauf
            # 2026-07-16). Env-Config statt --global, das Image bleibt sauber.
            '-e', 'GIT_CONFIG_COUNT=1',
            '-e', 'GIT_CONFIG_KEY_0=safe.directory', '-e', 'GIT_CONFIG_VALUE_0=*',
            $toolImages.php, 'composer') + $Arguments)
    }
    if (Test-Container $phpContainer) {
        return Invoke-Tool 'docker' (@('exec', '-w', '/var/www/html', $phpContainer, 'composer') + $Arguments)
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

Add-Gate -Name 'compose-hardening' -Lanes $allLanes -Kind 'container' -Body {
    # Haertungs-Contract (AP8): read_only/cap_drop/cap_add/no-new-privileges/
    # Limits/Healthchecks/service_healthy/tools-Profil/Digest-Pins, semantisch
    # ueber docker compose config. Das Skript liest VIRTUSPHERE_CHECK_ROOT
    # selbst (vererbt sich an den Kindprozess, so beweisen es die Guards).
    $hostExe = 'powershell'
    if ($PSVersionTable.PSEdition -eq 'Core') { $hostExe = 'pwsh' }
    $r = Invoke-Tool $hostExe @('-NoProfile', '-ExecutionPolicy', 'Bypass',
        '-File', (Join-Path $scriptDir 'check-compose-hardening.ps1'))
    if ($r.ExitCode -eq 0) { return New-PassResult 'Container-Haertung, Runtime-Tags und Basis-Digests gepinnt' $r.Output }
    if ($r.ExitCode -eq 2) { return New-InfraResult 'check-compose-hardening: Pruefumgebung unvollstaendig' $r.Output }
    New-FailResult ('Haertungs-Contract verletzt (exit ' + $r.ExitCode + ')') $r.Output
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

Add-Gate -Name 'file-size' -Lanes $allLanes -Kind 'native' -Body {
    $r = Invoke-CheckPhp 'check-file-size.php' @('--ci')
    if ($null -eq $r) { return New-InfraResult 'weder Host-PHP noch Projekt-Image verfuegbar' }
    Format-ToolResult $r 'ADR-0006-Budget eingehalten' 'PHP-Datei ueber Budget oder Ausnahme veraltet'
}

Add-Gate -Name 'doc-hygiene' -Lanes $allLanes -Kind 'native' -Body {
    $r = Invoke-CheckShell 'check-doc-hygiene.sh' @('--ci')
    if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar (Git Bash oder Docker noetig)' }
    Format-ToolResult $r 'Agenten-Dokus sauber' 'Doku-Hygiene verletzt'
}

Add-Gate -Name 'doc-semantics' -Lanes $allLanes -Kind 'native' -Body {
    $r = Invoke-CheckShell 'check-doc-semantics.sh' @('--ci')
    if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar (Git Bash oder Docker noetig)' }
    Format-ToolResult $r 'Betriebsdoku ohne veraltbare Staende' 'Doku behauptet einen veralteten Stand'
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
    # Exitcode-Vertrag von run-pester.ps1 (3 = Modul fehlt), niemals ein
    # Textmuster: der CI-Lauf 2026-07-16 klassifizierte 44 rote Tests als
    # "Module fehlen", weil ein Testname das Wort "fehlt" enthielt.
    if ($r.ExitCode -eq 3) {
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

Add-Gate -Name 'ansible-module-contract' -Lanes $allLanes -Kind 'container' -Body {
    # ansible-syntax und ansible-lint lesen die Playbooks; keiner der beiden ruft
    # ein Modul auf. Genau dort lag der Befund: jedes benutzte community.vmware-
    # Modul importiert vmware_rest_client, der ohne die Python-Bibliothek
    # `requests` abbricht - und die stand weder in der QA-Toolchain noch in den
    # dokumentierten Host-Voraussetzungen. Auf einem nach Doku aufgesetzten
    # Ansible-Host war damit der komplette ESXi-Teil funktionslos, waehrend
    # ansible-lint --strict gruen meldete. Sechs der sieben Inventar-Abfragen
    # laufen unter ignore_errors und haetten "0 Datastores" gemeldet.
    #
    # Dieses Gate ruft jedes Modul mit gueltigen Argumenten gegen 127.0.0.1:443:
    # der Verbindungsfehler ist der Gutfall, eine fehlende Bibliothek, ein
    # Argumentfehler oder ein verschwundenes Modul sind Befunde. Dazu haelt es die
    # Deprecations der INSTALLIERTEN Collection gegen die eingecheckte Liste,
    # damit eine Frist im Repo steht, ohne dass Prosa sie spiegeln muss.
    $contract = Join-Path $repoRoot 'Docker/qa-ansible/module-contract.sh'
    if (-not (Test-Path $contract)) { return New-InfraResult 'Docker/qa-ansible/module-contract.sh fehlt unter dem Pruef-Root (Zero-Match)' }
    if (-not (Test-DockerImage $toolImages.ansible)) {
        return New-InfraResult ('QA-Ansible-Image {0} fehlt (docker build -f Docker/qa-ansible/Dockerfile -t virtusphere-qa-ansible:latest .)' -f $toolImages.ansible)
    }
    $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo:ro'), '-w', '/repo',
        $toolImages.ansible, 'sh', '/repo/Docker/qa-ansible/module-contract.sh')
    Format-ToolResult $r 'Jedes benutzte Modul laedt gegen die gepinnte Collection' 'Modulvertrag der gepinnten Collection verletzt'
}

Add-Gate -Name 'ansible-powercycle-selection' -Lanes $allLanes -Kind 'container' -Body {
    # Wen der Power-Cycle schalten darf, ist reine Jinja-Filterei und damit ohne
    # ESXi-Host beweisbar: das Fixture-Playbook rechnet die ZEICHENGLEICHEN
    # Ketten des Produktions-Playbooks (PowercyclePlaybookContractTest pinnt
    # beide aufeinander) gegen an/aus/suspendiert, fehlendes needs_mac, leere
    # Eingaben und zwei kaputte Modulantwort-Formen und assertet die
    # dokumentierte Auswahl. Der erste Lauf fand einen echten Fehlschluss: ein
    # dotted selectattr WIRFT auf einer Antwort ohne das Attribut, statt sie zu
    # filtern - deshalb prueft der Wachhund im Playbook mit map+default VOR der
    # Auswahl.
    $fixture = Join-Path $repoRoot 'Docker/qa-ansible/powercycle-selection-fixtures.yml'
    if (-not (Test-Path $fixture)) { return New-InfraResult 'powercycle-selection-fixtures.yml fehlt unter dem Pruef-Root (Zero-Match)' }
    if (-not (Test-DockerImage $toolImages.ansible)) {
        return New-InfraResult ('QA-Ansible-Image {0} fehlt (docker build -f Docker/qa-ansible/Dockerfile -t virtusphere-qa-ansible:latest .)' -f $toolImages.ansible)
    }
    $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo:ro'), '-w', '/repo',
        $toolImages.ansible, 'ansible-playbook', '/repo/Docker/qa-ansible/powercycle-selection-fixtures.yml')
    Format-ToolResult $r 'Powercycle-Auswahl gegen Fixtures bewiesen (an/aus/suspendiert/kaputt/leer)' 'Powercycle-Auswahl weicht vom Vertrag ab'
}

Add-Gate -Name 'yaml-roundtrip' -Lanes $allLanes -Kind 'container' -Body {
    # Golden-Mission semantisch durch den echten PyYAML-Loader (AP5): PHP
    # rendert die feindliche Fixture mit den Produktions-Generatoren,
    # roundtrip_verify.py laedt beide Artefakte mit yaml.safe_load (YAML 1.1,
    # wie Ansible) und deep-vergleicht gegen den expected-Vertrag der Fixture.
    $fixture = Join-Path $repoRoot 'Docker/WebAPI/tests/fixtures/golden-mission.json'
    if (-not (Test-Path $fixture)) { return New-InfraResult 'golden-mission.json fehlt unter dem Pruef-Root (Zero-Match)' }
    if (-not (Test-DockerImage $toolImages.php)) { return New-InfraResult ('Projekt-Image {0} fehlt' -f $toolImages.php) }
    if (-not (Test-DockerImage $toolImages.ansible)) {
        return New-InfraResult ('QA-Ansible-Image {0} fehlt (docker build -f Docker/qa-ansible/Dockerfile -t virtusphere-qa-ansible:latest .)' -f $toolImages.ansible)
    }
    $outDir = Join-Path $artifactDir 'yaml-roundtrip'
    New-Item -ItemType Directory -Force -Path $outDir | Out-Null
    $outMount = ($outDir -replace '\\', '/')
    $render = Invoke-Tool 'docker' @('run', '--rm',
        '-v', ($repoRoot + ':/repo:ro'), '-v', ($outMount + ':/out'),
        $toolImages.php, 'php', '/repo/Docker/WebAPI/tests/tools/render-golden-serverlist.php',
        '/repo/Docker/WebAPI/tests/fixtures/golden-mission.json', '/out')
    if ($render.ExitCode -eq 2) { return New-InfraResult 'Golden-Renderer ohne brauchbare Umgebung (Fixture/Outdir)' $render.Output }
    if ($render.ExitCode -ne 0) { return New-FailResult 'Golden-Serverlist-Rendering rot (Generatorfehler)' $render.Output }
    foreach ($artifact in @('serverlist.yml', 'accounts.yml')) {
        if (-not (Test-Path (Join-Path $outDir $artifact))) {
            return New-InfraResult ('Renderer meldete Erfolg, aber {0} fehlt (Zero-Match)' -f $artifact)
        }
    }
    $verify = Invoke-Tool 'docker' @('run', '--rm',
        '-v', ($repoRoot + ':/repo:ro'), '-v', ($outMount + ':/out:ro'),
        $toolImages.ansible, 'python', '/repo/Ansible/tests/roundtrip_verify.py',
        '/repo/Docker/WebAPI/tests/fixtures/golden-mission.json', '/out')
    if ($verify.ExitCode -eq 2) { return New-InfraResult 'PyYAML-Verifier ohne brauchbare Umgebung' $verify.Output }
    Format-ToolResult $verify 'serverlist/accounts ueberleben den PyYAML-Roundtrip semantisch' 'PyYAML-Roundtrip weicht vom expected-Vertrag ab'
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

# Der QA-Stack ist das erste Integration-Gate: alle folgenden Gates laufen
# gegen seine Container. down -v im finally des Runners raeumt ihn ab;
# -KeepArtifacts laesst ihn zum Debuggen stehen.
Add-Gate -Name 'qa-stack' -Lanes $intRel -Kind 'container' -Body {
    if (-not (Test-Path $qaEnvFile)) { return New-InfraResult ('QA-Env fehlt: {0}' -f $qaEnvFile) }
    if (-not (Test-Path (Join-Path $repoRoot '.env'))) {
        return New-InfraResult '.env fehlt (Basis-Compose referenziert sie; in CI aus .env.example stellen)'
    }
    # Ab hier aufraeumen, auch wenn up nur halb durchkommt.
    $script:qaStackStarted = $true
    $up = Invoke-QaCompose (@('up', '-d', '--build', '--wait') + $qaServices)
    if ($up.ExitCode -ne 0) { return New-InfraResult 'docker compose up --wait rot (QA-Stack startet nicht)' $up.Output }

    # Frisches struktur.sql-Schema: Migrationen muessen als No-op durchlaufen
    # und hinterlassen die Tracking-Zeilen fuer migrate --check.
    $mig = Invoke-Tool 'docker' @('exec', $qaPhpContainer, 'php', '/var/www/html/lib/migrate.php')
    if ($mig.ExitCode -ne 0) { return New-FailResult 'Migrationen laufen auf dem frischen QA-Schema nicht durch' $mig.Output }

    # QA-Admin fuer die E2E-Anmeldung (Werte aus qa.env via Container-Env).
    # seed.php erzwingt einen Passwortwechsel; fuer das Wegwerf-Konto wird das
    # Flag zurueckgesetzt, sonst landet der Login nie auf dem Dashboard.
    $seed = Invoke-Tool 'docker' @('exec', $qaPhpContainer, 'php', '/var/www/html/lib/seed.php')
    if ($seed.ExitCode -ne 0) { return New-InfraResult 'seed.php rot (QA-Admin fehlt)' $seed.Output }
    # Bewusst ohne jedes doppelte Anfuehrungszeichen: Windows PowerShell 5.1
    # zerlegt eingebettete Doppel-Quotes in nativen Argumenten (der erste Lauf
    # kam als "SQL syntax near ''" zurueck). Das UPDATE trifft absichtlich alle
    # Zeilen: zu diesem Zeitpunkt existiert nur der frisch geseedete QA-Admin.
    # Die $MYSQL_*-Werte kommen aus qa.env und enthalten keine Leerzeichen.
    $flag = Invoke-Tool 'docker' @('exec', $qaMysqlContainer, 'sh', '-c',
        'MYSQL_PWD=$MYSQL_PASSWORD exec mysql -u$MYSQL_USER $MYSQL_DATABASE -e ''UPDATE deploy_users SET must_change_password = 0''')
    if ($flag.ExitCode -ne 0) { return New-InfraResult 'QA-Admin-Flag nicht zuruecksetzbar' $flag.Output }

    # Portal-Readiness von aussen (nginx -> php-fpm -> DB), bis zu 30s.
    $deadline = (Get-Date).AddSeconds(30)
    while ($true) {
        try {
            $h = Invoke-WebRequest -Uri ($qaPortalBase + '/portal/health.php') -UseBasicParsing -TimeoutSec 5
            if ([int]$h.StatusCode -eq 200) { break }
        } catch { }
        if ((Get-Date) -gt $deadline) {
            return New-InfraResult ('Portal am QA-Stack nicht erreichbar: {0}/portal/health.php' -f $qaPortalBase)
        }
        Start-Sleep -Seconds 2
    }
    New-PassResult ('QA-Stack laeuft ({0}, Portal {1})' -f $qaProject, $qaPortalBase)
}

Add-Gate -Name 'migrate-check' -Lanes $intRel -Kind 'container' -Body {
    if (-not (Test-Container $qaPhpContainer)) { return New-InfraResult 'QA-Stack laeuft nicht (Gate qa-stack zuerst)' }
    $r = Invoke-Tool 'docker' @('exec', $qaPhpContainer, 'php', '/var/www/html/lib/migrate.php', '--check')
    Format-ToolResult $r 'Migrationen konsistent (pending=0)' 'migrate.php --check rot'
}

Add-Gate -Name 'phpunit-full' -Lanes $intRel -Kind 'container' -Body {
    if (-not (Test-Container $qaPhpContainer)) { return New-InfraResult 'QA-Stack laeuft nicht (Gate qa-stack zuerst)' }
    if (-not (Test-DockerImage $toolImages.php)) { return New-InfraResult ('Projekt-Image {0} fehlt' -f $toolImages.php) }
    # Die Suite erzeugt absichtlich queued/running/stale Jobs und loescht ihre
    # Fixtures wieder. Ein gleichzeitig claimender Deploy-Worker oder reapender
    # Maintenance-Worker macht daraus keine Integrationspruefung, sondern zwei
    # konkurrierende Besitzer derselben Wegwerfzeilen (BulkVmActionsTest traf so
    # einen echten MySQL-Deadlock). Beide Worker werden deshalb NUR fuer dieses
    # Gate quiesziert. Produktionslocking bleibt unveraendert; das finally stellt
    # sie auch nach einem roten/werfenden PHPUnit-Lauf wieder health-geprueft her.
    $qaTestWorkers = @('deploy-worker', 'maintenance-worker')
    $stopped = Invoke-QaCompose (@('stop', '--timeout', '30') + $qaTestWorkers)
    if ($stopped.ExitCode -ne 0) {
        return New-InfraResult 'QA-Worker vor phpunit-full nicht quieszierbar' $stopped.Output
    }

    # docker run mit Repo-Mount im QA-Netz statt exec in den App-Container:
    # die Repo-Level-Contract-Tests sehen ihre Dateien, die Integrationstests
    # erreichen mysql und webserver:8080 des QA-Projekts, und --fail-on-skipped
    # macht jeden dynamischen Skip rot (ADR-0015-Ergaenzung: in dieser Lane
    # ist ein Skip nie legitim).
    $r = $null
    $restarted = $null
    try {
        $r = Invoke-Tool 'docker' @('run', '--rm',
            '-v', ($repoRoot + ':/repo'), '-w', '/repo/Docker/WebAPI',
            '--network', $qaNetwork,
            '--env-file', $qaEnvFile,
            '-e', 'ANSIBLE_SOURCE_DIR=/repo/Ansible',
            $toolImages.php, 'php', 'vendor/bin/phpunit', '--fail-on-skipped')
    } finally {
        $restarted = Invoke-QaCompose (@('up', '-d', '--wait') + $qaTestWorkers)
    }
    if ($null -eq $restarted -or $restarted.ExitCode -ne 0) {
        $restartOutput = if ($null -eq $restarted) { @('worker restart did not return a result') } else { @($restarted.Output) }
        return New-InfraResult 'QA-Worker nach phpunit-full nicht wieder healthy' (@($r.Output) + $restartOutput)
    }
    Format-ToolResult $r 'vollstaendige PHPUnit-Suite gruen (ohne Skips)' 'PHPUnit-Suite rot oder geskippt'
}

Add-Gate -Name 'schema-convergence' -Lanes $intRel -Kind 'container' -Body {
    if (-not (Test-Container $qaMysqlContainer)) { return New-InfraResult 'QA-Stack laeuft nicht (Gate qa-stack zuerst)' }
    $prevMy = $env:MYSQL_CONTAINER; $prevPh = $env:PHP_CONTAINER; $prevPw = $env:MYSQL_ROOT_PASSWORD
    $env:MYSQL_CONTAINER = $qaMysqlContainer
    $env:PHP_CONTAINER = $qaPhpContainer
    $env:MYSQL_ROOT_PASSWORD = Get-QaEnvValue 'MYSQL_ROOT_PASSWORD'
    try {
        $r = Invoke-CheckShell 'check-schema-convergence.sh' @()
    } finally {
        $env:MYSQL_CONTAINER = $prevMy; $env:PHP_CONTAINER = $prevPh; $env:MYSQL_ROOT_PASSWORD = $prevPw
    }
    if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar (Git Bash oder Docker noetig)' }
    if ($r.ExitCode -eq 2) { return New-InfraResult 'Konvergenz-Check ohne Umgebung (Stack/.env fehlt)' $r.Output }
    Format-ToolResult $r 'struktur.sql und migrate.php konvergieren' 'Schema-Konvergenz verletzt'
}

Add-Gate -Name 'health-contract' -Lanes $intRel -Kind 'container' -Body {
    if (-not (Test-Container $qaWebContainer)) { return New-InfraResult 'QA-Stack laeuft nicht (Gate qa-stack zuerst)' }
    $n = Invoke-Tool 'docker' @('exec', $qaWebContainer, 'nginx', '-t')
    if ($n.ExitCode -ne 0) { return New-FailResult 'nginx -t rot' $n.Output }
    try {
        $health = Invoke-WebRequest -Uri ($qaPortalBase + '/portal/health.php') -UseBasicParsing -TimeoutSec 10
        if ([int]$health.StatusCode -ne 200) { return New-FailResult ('health.php liefert HTTP {0} statt 200' -f $health.StatusCode) }
    } catch { return New-FailResult ('health.php nicht erreichbar: ' + $_.Exception.Message) }

    # Versionsoffenlegung (AP7): kein X-Powered-By, Server-Header ohne
    # Versionsnummer, health-JSON nur mit grober PHP-Version (major.minor).
    # Die Konfigquellen pinnt VersionExposureContractTest; hier steht der
    # Beweis, dass der laufende Stack sie auch anwendet.
    if ($health.Headers['X-Powered-By']) { return New-FailResult 'health.php sendet X-Powered-By (expose_php greift nicht)' }
    $serverHeader = [string]$health.Headers['Server']
    if ($serverHeader -match '\d') { return New-FailResult ('Server-Header nennt eine Version: {0} (server_tokens off greift nicht)' -f $serverHeader) }
    try { $healthJson = $health.Content | ConvertFrom-Json } catch { return New-FailResult 'health.php liefert kein parsebares JSON' }
    if ([string]$healthJson.php -notmatch '^\d+\.\d+$') { return New-FailResult ('health.php nennt eine exakte PHP-Version: {0}' -f $healthJson.php) }
    $testsStatus = 0
    try {
        $t = Invoke-WebRequest -Uri ($qaPortalBase + '/tests/bootstrap.php') -UseBasicParsing -TimeoutSec 10
        $testsStatus = [int]$t.StatusCode
    } catch {
        if ($_.Exception.Response) { $testsStatus = [int]$_.Exception.Response.StatusCode } else { return New-InfraResult ('Portal nicht erreichbar: ' + $_.Exception.Message) }
    }
    if ($testsStatus -ne 403) { return New-FailResult ('/tests/bootstrap.php liefert HTTP {0} statt 403 (Exposure-Vertrag)' -f $testsStatus) }
    New-PassResult 'nginx -t OK, health=200, /tests=403'
}

# Gemeinsamer Playwright-Lauf gegen den QA-Stack, geteilt von e2e-portal
# (Integration, Chromium) und der Release-Browser-Matrix: Preflight fuer
# Stack/npm/Suite plus Chromium (das setup-Projekt mit Login/storageState
# laeuft immer auf der Chromium-Engine), npm ci beim Erstlauf, QA-Env,
# Projektlauf. Engine-spezifische Preflights bleiben im jeweiligen Gate.
function Invoke-PlaywrightSuite {
    param([string[]]$Projects, [string]$OkDetail, [string]$FailDetail)
    if (-not (Test-Container $qaPhpContainer)) { return New-InfraResult 'QA-Stack laeuft nicht (Gate qa-stack zuerst)' }
    if (-not (Test-Command 'npm')) { return New-InfraResult 'npm fehlt (Node auf dem Pruef-Host noetig)' }
    $e2eDir = Join-Path (Join-Path $repoRoot 'tests') 'e2e'
    if (-not (Test-Path (Join-Path $e2eDir 'package.json'))) { return New-InfraResult 'tests/e2e fehlt unter dem Pruef-Root' }
    if (-not (Test-PlaywrightChromium)) { return New-InfraResult 'kein Chromium fuer Playwright: PLAYWRIGHT_CHROMIUM setzen oder npx playwright install chromium' }

    if (-not (Test-Path (Join-Path $e2eDir 'node_modules'))) {
        $ci = Invoke-Tool 'npm' @('--prefix', $e2eDir, 'ci')
        if ($ci.ExitCode -ne 0) { return New-InfraResult 'npm ci fuer tests/e2e rot' $ci.Output }
    }

    $prevEnv = @{}
    $e2eEnv = @{
        VIRTUSPHERE_BASE_URL        = ($qaPortalBase + '/portal/')  # Slash ist tragend (ADR-0028)
        VIRTUSPHERE_PHP_CONTAINER   = $qaPhpContainer
        VIRTUSPHERE_MYSQL_CONTAINER = $qaMysqlContainer
        VIRTUSPHERE_ADMIN_USER      = (Get-QaEnvValue 'SEED_ADMIN_USER')
        VIRTUSPHERE_ADMIN_PASS      = (Get-QaEnvValue 'SEED_ADMIN_PASSWORD')
        DB_NAME                     = (Get-QaEnvValue 'DB_NAME')
    }
    foreach ($k in $e2eEnv.Keys) {
        $prevEnv[$k] = [Environment]::GetEnvironmentVariable($k)
        [Environment]::SetEnvironmentVariable($k, [string]$e2eEnv[$k])
    }
    $projectArgs = @()
    foreach ($p in $Projects) { $projectArgs += ('--project=' + $p) }
    Push-Location $e2eDir
    try {
        $r = Invoke-Tool 'npx' (@('playwright', 'test') + $projectArgs)
    } finally {
        Pop-Location
        foreach ($k in $prevEnv.Keys) { [Environment]::SetEnvironmentVariable($k, $prevEnv[$k]) }
    }
    Format-ToolResult $r $OkDetail $FailDetail
}

Add-Gate -Name 'e2e-portal' -Lanes $intRel -Kind 'native' -Network $true -Body {
    # Playwright-Chromium gegen den QA-Stack (ADR-0028-Revision): beweist, was
    # nur ein Browser beweisen kann. Netzabhaengig wegen npm ci beim Erstlauf.
    Invoke-PlaywrightSuite @('chromium') 'Playwright-Chromium-Suite gruen (QA-Stack)' 'Playwright-Suite rot'
}

Add-Gate -Name 'guard-harness' -Lanes $intRel -Kind 'native' -Body {
    $hostExe = 'powershell'
    if ($PSVersionTable.PSEdition -eq 'Core') { $hostExe = 'pwsh' }
    $r = Invoke-Tool $hostExe @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $scriptDir 'test-guards.ps1'))
    if ($r.ExitCode -eq 2) { return New-InfraResult 'Guard-Harness ohne vollstaendige Umgebung' $r.Output }
    Format-ToolResult $r 'alle Guards positiv/negativ/zero-match bewiesen' 'Guard-Harness meldet unbewiesene Guards'
}

# --- Release-Lane -------------------------------------------------------------

# Volle Browser-Matrix (ADR-0028-Revision): dieselbe Suite wie e2e-portal auf
# den Engines, die die Integration-Lane nicht faehrt. Firefox/WebKit sind
# plattformneutral aus dem Playwright-Cache; Edge haengt am installierten
# Windows-Edge und ist deshalb ein eigenes windows-only-Gate, damit es auf
# einem Linux-Release-Runner sichtbar not_applicable wird statt still zu fehlen.
Add-Gate -Name 'e2e-browser-matrix' -Lanes @('Release') -Kind 'native' -Network $true -Body {
    foreach ($engine in @('firefox', 'webkit')) {
        if (-not (Test-PlaywrightEngineCache $engine)) {
            return New-InfraResult ('kein ' + $engine + ' fuer Playwright: npx playwright install ' + $engine)
        }
    }
    Invoke-PlaywrightSuite @('firefox', 'webkit') 'Playwright-Suite gruen auf Firefox und WebKit (QA-Stack)' 'Browser-Matrix rot'
}

Add-Gate -Name 'e2e-msedge' -Lanes @('Release') -Kind 'windows-only' -Network $true -Body {
    if (-not $isWindowsHost) { return New-NaResult 'windows-only: der msedge-Channel braucht ein installiertes Windows-Edge' }
    $edgeFound = $false
    foreach ($root in @(${env:ProgramFiles(x86)}, $env:ProgramFiles)) {
        if (-not $root) { continue }
        if (Test-Path (Join-Path $root 'Microsoft\Edge\Application\msedge.exe')) {
            $edgeFound = $true
            break
        }
    }
    if (-not $edgeFound) { return New-InfraResult 'msedge.exe nicht gefunden (installiertes Edge noetig fuer den msedge-Channel)' }
    Invoke-PlaywrightSuite @('msedge') 'Playwright-Suite gruen auf Windows-Edge (QA-Stack)' 'Edge-Lauf rot'
}

Add-Gate -Name 'restore-drill' -Lanes @('Release') -Kind 'container' -Body {
    $r = Invoke-CheckShell 'restore_test.sh' @()
    if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar (Git Bash oder Docker noetig)' }
    if ($r.ExitCode -eq 2) { return New-InfraResult 'Restore-Drill ohne Umgebung (Stack/Backup fehlt)' $r.Output }
    Format-ToolResult $r 'Backup-/Restore-Drill gruen' 'Restore-Drill rot'
}

Add-Gate -Name 'secret-scan' -Lanes @('Release') -Kind 'container' -Network $true -Body {
    # Volle Git-Historie, nicht nur der Worktree: git rm entfernt keine
    # Vergangenheit, und die zwei bekannten Altfunde sind in .gitleaks.toml
    # als Entscheidungen dokumentiert. Erwartung: null Funde; jeder neue Fund
    # ist rot, bis er rotiert oder als Fixture begruendet ist.
    if (-not (Test-Path (Join-Path $repoRoot '.gitleaks.toml'))) { return New-InfraResult '.gitleaks.toml fehlt (Allowlist-Vertrag)' }
    if (-not (Test-DockerImage $toolImages.gitleaks)) {
        if ($NoNetwork) { return New-InfraResult ('Tool-Image {0} fehlt lokal (NoNetwork: kein Pull)' -f $toolImages.gitleaks) }
    }
    $r = Invoke-Tool 'docker' @('run', '--rm', '-v', ($repoRoot + ':/repo'),
        $toolImages.gitleaks, 'detect', '--source', '/repo',
        '--config', '/repo/.gitleaks.toml', '--redact', '--exit-code', '1')
    if ($r.ExitCode -gt 1 -and (@($r.Output) -join ' ') -match 'pull|not found|no such') {
        return New-InfraResult ('Tool-Image {0} nicht verfuegbar' -f $toolImages.gitleaks) $r.Output
    }
    Format-ToolResult $r 'Secret-Scan ueber die volle Historie: null Funde' 'gitleaks meldet Secrets'
}

# Runtime-Images des Stacks fuer die SBOM-/CVE-Gates (AP8): Registry-Refs aus
# der aufgeloesten Compose-Sicht (inkl. tools-Profil), Build-Services ueber den
# Compose-Projektnamen; buildgleiche Images (php und die zwei Worker teilen ein
# Dockerfile) werden ueber die Image-ID dedupliziert. Das config-JSON traegt
# interpolierte Secrets und wird deshalb nie ausgegeben oder gespeichert.
function Get-RuntimeImages {
    $r = Invoke-Tool 'docker' @('compose', '--project-directory', $repoRoot,
        '--profile', '*', 'config', '--format', 'json')
    if ($r.ExitCode -ne 0) { return $null }
    $config = $null
    try { $config = (@($r.Output) -join "`n") | ConvertFrom-Json } catch { return $null }
    $refs = @()
    $project = [string]$config.name
    foreach ($p in $config.services.PSObject.Properties) {
        $imgProp = $p.Value.PSObject.Properties['image']
        if ($imgProp -and $imgProp.Value) {
            $refs += [string]$imgProp.Value
        } elseif ($p.Value.PSObject.Properties['build']) {
            $refs += ($project + '-' + $p.Name)
        }
    }
    $byId = @{}
    $missing = @()
    foreach ($ref in @($refs | Sort-Object -Unique)) {
        $probe = Invoke-Tool 'docker' @('image', 'inspect', '--format', '{{.Id}}', $ref)
        if ($probe.ExitCode -ne 0) { $missing += $ref; continue }
        $id = [string]$probe.Output[0]
        if (-not $byId.ContainsKey($id)) { $byId[$id] = $ref }
    }
    return @{ Images = @($byId.Values | Sort-Object); Missing = @($missing) }
}

# trivy als Container: Docker-Socket, damit es die lokalen Images liest, plus
# ein persistentes Cache-Volume fuer die Vuln-DB (ein Re-Download pro Lauf
# waere Verschwendung; das Volume ist ein Tool-Cache mit virtusphere-Praefix,
# kein QA-Artefakt, und faellt nicht unter das finally-Cleanup).
function Invoke-Trivy {
    param([string[]]$Arguments)
    return Invoke-Tool 'docker' (@('run', '--rm',
        '-v', '/var/run/docker.sock:/var/run/docker.sock',
        '-v', 'virtusphere-trivy-cache:/root/.cache',
        '-v', ($repoRoot + ':/repo:ro'),
        '-v', (($artifactDir -replace '\\', '/') + ':/out'),
        $toolImages.trivy) + $Arguments)
}

Add-Gate -Name 'sbom' -Lanes @('Release') -Kind 'container' -Network $true -Body {
    $found = Get-RuntimeImages
    if ($null -eq $found) { return New-InfraResult 'docker compose config nicht lesbar' }
    if ($found.Missing.Count -gt 0) {
        return New-InfraResult ('Runtime-Image(s) fehlen lokal: ' + ($found.Missing -join ', ') + ' (erst docker compose build bzw. pull)')
    }
    if ($found.Images.Count -eq 0) { return New-InfraResult 'keine Runtime-Images gefunden (Zero-Match)' }
    $written = @()
    foreach ($img in $found.Images) {
        $safe = ($img -replace '[^A-Za-z0-9._-]', '_')
        $r = Invoke-Trivy @('image', '--format', 'spdx-json',
            '--output', ('/out/sbom-' + $safe + '.spdx.json'), $img)
        if ($r.ExitCode -ne 0) { return New-FailResult ('SBOM-Erzeugung fuer ' + $img + ' fehlgeschlagen (exit ' + $r.ExitCode + ')') $r.Output }
        $written += ('sbom-' + $safe + '.spdx.json')
    }
    New-PassResult ('SPDX-SBOM fuer {0} Image(s) unter {1}' -f $found.Images.Count, $artifactDir) $written
}

Add-Gate -Name 'image-cve' -Lanes @('Release') -Kind 'container' -Network $true -Body {
    # Critical/High blockieren; Ausnahmen nur befristet ueber .trivyignore.yaml
    # (expired_at laesst eine abgelaufene Ausnahme automatisch wieder rot werden).
    if (-not (Test-Path (Join-Path $repoRoot '.trivyignore.yaml'))) {
        return New-InfraResult '.trivyignore.yaml fehlt (CVE-Ausnahme-Vertrag)'
    }
    $found = Get-RuntimeImages
    if ($null -eq $found) { return New-InfraResult 'docker compose config nicht lesbar' }
    if ($found.Missing.Count -gt 0) {
        return New-InfraResult ('Runtime-Image(s) fehlen lokal: ' + ($found.Missing -join ', ') + ' (erst docker compose build bzw. pull)')
    }
    if ($found.Images.Count -eq 0) { return New-InfraResult 'keine Runtime-Images gefunden (Zero-Match)' }
    $bad = @()
    $reportLines = @()
    foreach ($img in $found.Images) {
        $safe = ($img -replace '[^A-Za-z0-9._-]', '_')
        # Zwei Sichten (dokumentierte Politik, .trivyignore.yaml): der volle
        # Bericht inklusive unfixed geht als Artefakt raus; blockiert wird nur,
        # wofuer es eine Handlungsoption gibt (--ignore-unfixed), sonst waere
        # das Gate an Debian-will_not_fix-Eintraegen dauerrot und wertlos.
        $full = Invoke-Trivy @('image', '--quiet', '--scanners', 'vuln', '--severity', 'CRITICAL,HIGH',
            '--ignorefile', '/repo/.trivyignore.yaml',
            '--output', ('/out/cve-' + $safe + '.txt'), $img)
        if ($full.ExitCode -ne 0) {
            return New-InfraResult ('trivy-Bericht fuer ' + $img + ' nicht erzeugbar (exit ' + $full.ExitCode + ')') $full.Output
        }
        $r = Invoke-Trivy @('image', '--quiet', '--scanners', 'vuln', '--severity', 'CRITICAL,HIGH',
            '--ignore-unfixed', '--ignorefile', '/repo/.trivyignore.yaml', '--exit-code', '1', $img)
        if ($r.ExitCode -eq 1) {
            $bad += $img
            $reportLines += ('--- ' + $img + ' (voller Bericht: cve-' + $safe + '.txt) ---')
            $reportLines += @($r.Output | Select-Object -Last 25)
        } elseif ($r.ExitCode -ne 0) {
            return New-InfraResult ('trivy-Scan fuer ' + $img + ' nicht ausfuehrbar (exit ' + $r.ExitCode + ')') $r.Output
        }
    }
    if ($bad.Count -gt 0) {
        return New-FailResult ('fixbare Critical/High-CVEs offen in: ' + ($bad -join ', ') + ' (Berichte unter ' + $artifactDir + '; Ausnahmen nur befristet via .trivyignore.yaml)') $reportLines
    }
    New-PassResult ('{0} Image(s) ohne fixbare Critical/High-CVEs (volle Berichte unter {1})' -f $found.Images.Count, $artifactDir)
}

Add-Gate -Name 'offline-bundle' -Lanes @('Release') -Kind 'container' -Network $true -Body {
    # Baut das Offline-Release-Bundle (Images, vendor, Collections, SBOM,
    # CVE-Berichte, Digest-Manifest) und laesst es sich am Ende selbst offline
    # verifizieren (verify.sh). -KeepArtifacts behaelt das Bundle.
    if (-not $shExe) {
        return New-InfraResult 'kein Host-sh (Git Bash): das Bundle-Skript orchestriert docker und kann nicht in den PHP-Container ausweichen'
    }
    $bundleDir = ((Join-Path $artifactDir 'offline-bundle') -replace '\\', '/')
    $r = Invoke-CheckShell 'build-offline-bundle.sh' @('--release', $bundleDir)
    if ($null -eq $r) { return New-InfraResult 'kein sh verfuegbar' }
    if ($r.ExitCode -eq 2) { return New-InfraResult 'Bundle-Umgebung unvollstaendig' $r.Output }
    Format-ToolResult $r ('Offline-Bundle gebaut und offline verifiziert: ' + $bundleDir) 'Offline-Bundle fehlgeschlagen'
}

Add-Gate -Name 'npm-audit' -Lanes @('Release') -Kind 'native' -Network $true -Body {
    # QA-Tooling-Abhaengigkeiten (Playwright/axe in tests/e2e): Advisory-Bericht
    # als Artefakt, blockiert ab high - das Pendant zu composer-audit.
    if (-not (Test-Command 'npm')) { return New-InfraResult 'npm nicht gefunden' }
    $e2eDir = Join-Path (Join-Path $repoRoot 'tests') 'e2e'
    if (-not (Test-Path (Join-Path $e2eDir 'package-lock.json'))) {
        return New-InfraResult 'tests/e2e/package-lock.json fehlt (Zero-Match)'
    }
    $r = Invoke-Tool 'npm' @('--prefix', $e2eDir, 'audit', '--audit-level=high', '--json')
    $reportPath = Join-Path $artifactDir 'npm-audit.json'
    [System.IO.File]::WriteAllLines($reportPath, [string[]]@($r.Output))
    if ($r.ExitCode -eq 0) { return New-PassResult ('keine high/critical-Advisories; Bericht: ' + $reportPath) }
    New-FailResult ('npm audit meldet Advisories ab high (Bericht: ' + $reportPath + ')') @($r.Output | Select-Object -First 40)
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
