#Requires -Version 5.1
<#
.SYNOPSIS
    Guard-the-guards-Harness (AP3, ADR-0031): beweist jeden Drift-/Pattern-
    Guard positiv (echtes Repo gruen), negativ (gezielte Mutation in einer
    temporaeren Fixturekopie wird mit der richtigen Diagnose-ID rot) und im
    Zero-Match-Fall (fehlende Suchtreffer werden nie leer gruen).

.DESCRIPTION
    Kein Guard gilt als vertrauenswuerdig, bevor sein Positiv-, Negativ- und
    Zero-Match-Fall automatisiert bewiesen ist. Fixtures entstehen unter
    %TEMP% und werden ueber VIRTUSPHERE_CHECK_ROOT an die Checks gereicht;
    das echte Repo wird nie mutiert.

    Ergebnisklassen je Fall: proven, unproven, infra (Werkzeug fehlt).
    Exitcodes: 0 alle Faelle proven | 1 mindestens ein Fall unproven |
    2 nur Infrastrukturluecken | 3 ungueltiger Aufruf.

    Muss unter Windows PowerShell 5.1 und pwsh laufen; nur ASCII in dieser
    Datei (PS 5.1 liest UTF-8 ohne BOM als ANSI).

.PARAMETER Filter
    Nur Faelle ausfuehren, deren Name mit einem der Praefixe beginnt
    (z. B. -Filter enum-sync,csp).

.PARAMETER List
    Faelle auflisten, nichts ausfuehren.

.EXAMPLE
    powershell -NoProfile -File scripts\test-guards.ps1
#>
[CmdletBinding()]
param(
    [string[]]$Filter,
    [switch]$List
)

Set-StrictMode -Version 1.0
$ErrorActionPreference = 'Stop'

if ($Filter) { $Filter = @($Filter | ForEach-Object { $_ -split ',' } | Where-Object { $_ -ne '' }) }

$scriptDir = $PSScriptRoot
$repoRoot = (Split-Path $scriptDir -Parent) -replace '\\', '/'
$workRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('virtusphere-guards-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
$hostShell = 'powershell'
if ($PSVersionTable.PSEdition -eq 'Core') { $hostShell = 'pwsh' }

# --- Werkzeuge (identische Muster wie scripts/check.ps1) ----------------------
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
$phpImage = 'virtusphere-v2-webapp-php'
$dockerAvailable = Test-Command 'docker'
$phpImageAvailable = $false
if ($dockerAvailable) {
    $probe = Invoke-Tool 'docker' @('image', 'inspect', '--format', 'ok', $phpImage)
    $phpImageAvailable = ($probe.ExitCode -eq 0)
}

# Shellskript mit optionalem Fixture-Root ausfuehren (Host-sh, PATH-Fix wie
# check.ps1: sh.exe direkt gestartet kennt grep/sed/wc sonst nicht).
function Invoke-GuardShell {
    param([string]$ScriptPath, [string[]]$Arguments = @(), [string]$FixtureRoot = '')
    if (-not $shExe) { return $null }
    $prevPath = $env:PATH
    $prevRoot = $env:VIRTUSPHERE_CHECK_ROOT
    $env:PATH = (Split-Path $shExe -Parent) + [System.IO.Path]::PathSeparator + $env:PATH
    if ($FixtureRoot) { $env:VIRTUSPHERE_CHECK_ROOT = ($FixtureRoot -replace '\\', '/') }
    try {
        return Invoke-Tool $shExe (@(($ScriptPath -replace '\\', '/')) + $Arguments)
    } finally {
        $env:PATH = $prevPath
        $env:VIRTUSPHERE_CHECK_ROOT = $prevRoot
    }
}

# PHP-Checkskript ausfuehren: Host-php, sonst Projekt-Image mit Fixture-Mount.
function Invoke-GuardPhp {
    param([string]$ScriptName, [string[]]$Arguments = @(), [string]$FixtureRoot = '')
    if (Test-Command 'php') {
        $prevRoot = $env:VIRTUSPHERE_CHECK_ROOT
        if ($FixtureRoot) { $env:VIRTUSPHERE_CHECK_ROOT = ($FixtureRoot -replace '\\', '/') }
        try {
            return Invoke-Tool 'php' (@((Join-Path $scriptDir $ScriptName)) + $Arguments)
        } finally {
            $env:VIRTUSPHERE_CHECK_ROOT = $prevRoot
        }
    }
    if ($dockerAvailable -and $phpImageAvailable) {
        $mountRoot = $repoRoot
        if ($FixtureRoot) { $mountRoot = ($FixtureRoot -replace '\\', '/') }
        $dockerArgs = @('run', '--rm',
            '-v', ($scriptDir + ':/checker:ro'),
            '-v', ($mountRoot + ':/checkroot:ro'),
            '-e', 'VIRTUSPHERE_CHECK_ROOT=/checkroot',
            $phpImage, 'php', ('/checker/' + $ScriptName)) + $Arguments
        return Invoke-Tool 'docker' $dockerArgs
    }
    return $null
}

# Runner-Gate gegen ein Fixture-Root ausfuehren (beweist die check.ps1-Gates).
function Invoke-RunnerGate {
    param([string]$FixtureRoot, [string]$GateNames)
    $prevRoot = $env:VIRTUSPHERE_CHECK_ROOT
    $env:VIRTUSPHERE_CHECK_ROOT = ($FixtureRoot -replace '\\', '/')
    try {
        return Invoke-Tool $hostShell @('-NoProfile', '-ExecutionPolicy', 'Bypass',
            '-File', (Join-Path $scriptDir 'check.ps1'), '-Gate', $GateNames)
    } finally {
        $env:VIRTUSPHERE_CHECK_ROOT = $prevRoot
    }
}

# --- Fixtures ------------------------------------------------------------------
$fixtureCounter = 0
function New-Fixture {
    param([string[]]$Paths = @())
    $script:fixtureCounter = $script:fixtureCounter + 1
    $root = Join-Path $workRoot ('fx-' + $script:fixtureCounter)
    New-Item -ItemType Directory -Force -Path $root | Out-Null
    foreach ($rel in $Paths) {
        $source = Join-Path $repoRoot $rel
        $target = Join-Path $root $rel
        New-Item -ItemType Directory -Force -Path (Split-Path $target -Parent) | Out-Null
        if (Test-Path $source -PathType Container) {
            Copy-Item -Recurse -Force -Path $source -Destination $target
        } else {
            Copy-Item -Force -Path $source -Destination $target
        }
    }
    return $root
}

# Mutation mit Anker: schlaegt der Anker fehl, ist der Fall unproven (die
# Quelle hat sich bewegt und die Mutation waere ein No-op-Gruenlauf).
function Edit-Fixture {
    param([string]$FixtureRoot, [string]$RelativePath, [string]$Find, [string]$Replace)
    $file = Join-Path $FixtureRoot $RelativePath
    $content = [System.IO.File]::ReadAllText($file)
    if ($content.IndexOf($Find) -lt 0) {
        throw ('Mutationsanker nicht gefunden in ' + $RelativePath + ': ' + $Find)
    }
    $content = $content.Replace($Find, $Replace)
    [System.IO.File]::WriteAllText($file, $content, (New-Object System.Text.UTF8Encoding($false)))
}

function Add-FixtureFile {
    param([string]$FixtureRoot, [string]$RelativePath, [string]$Content)
    $file = Join-Path $FixtureRoot $RelativePath
    New-Item -ItemType Directory -Force -Path (Split-Path $file -Parent) | Out-Null
    [System.IO.File]::WriteAllText($file, $Content, (New-Object System.Text.UTF8Encoding($false)))
}

# --- Bewertung ------------------------------------------------------------------
function Assert-Guard {
    param($Result, [int[]]$ExpectExit, [string]$ExpectPattern = '', [switch]$InfraOnExit2)
    if ($null -eq $Result) { return @{ Status = 'infra'; Detail = 'Werkzeug fehlt (sh/php/docker)' } }
    $joined = @($Result.Output) -join "`n"
    if ($InfraOnExit2 -and $Result.ExitCode -eq 2 -and ($ExpectExit -notcontains 2)) {
        $head = $joined
        if ($head.Length -gt 200) { $head = $head.Substring(0, 200) }
        return @{ Status = 'infra'; Detail = ('Umgebung unvollstaendig: ' + $head) }
    }
    if ($ExpectExit -notcontains $Result.ExitCode) {
        $head = $joined
        if ($head.Length -gt 400) { $head = $head.Substring(0, 400) }
        return @{ Status = 'unproven'; Detail = ('exit ' + $Result.ExitCode + ', erwartet ' + ($ExpectExit -join '/') + '; ' + $head) }
    }
    if ($ExpectPattern -ne '' -and $joined -notmatch $ExpectPattern) {
        return @{ Status = 'unproven'; Detail = ('erwartete Diagnose fehlt im Output: ' + $ExpectPattern) }
    }
    return @{ Status = 'proven'; Detail = '' }
}

# --- Fall-Katalog ----------------------------------------------------------------
$enumFixtureFiles = @(
    'Docker/WebAPI/lib/constants.php', 'Docker/WebAPI/lib/permissions.php',
    'Docker/WebAPI/lib/deploy_constants.php', 'Docker/WebAPI/lib/credentials.php',
    'Docker/mysql/mysql-init/struktur.sql', 'Docker/WebAPI/lib/migrate.php'
)
$phpVerFixtureFiles = @(
    'Docker/php/Dockerfile', 'Docker/WebAPI/composer.json',
    'Docker/WebAPI/lib/constants.php', 'CLAUDE.md', 'AGENTS.md'
)
$docFixtureFiles = @('AGENTS.md', 'GROK.md', 'CLAUDE.md', 'README.md')
$boundsFixtureFiles = @('Docker/WebAPI/lib', 'Docker/WebAPI/lang')
$enumList = "'queued','running','succeeded','failed','cancelled','partial'"

$cases = @(
    @{ Name = 'enum-sync.green'; Body = {
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-enum-sync.sh') @('--ci')) @(0)
    } }
    @{ Name = 'enum-sync.missing-value'; Body = {
        $fx = New-Fixture $enumFixtureFiles
        Edit-Fixture $fx 'Docker/mysql/mysql-init/struktur.sql' $enumList "'queued','running','succeeded','failed','cancelled'"
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-enum-sync.sh') @('--ci') $fx) @(1) '\[enum-sync\.drift\]'
    } }
    @{ Name = 'enum-sync.reordered'; Body = {
        $fx = New-Fixture $enumFixtureFiles
        Edit-Fixture $fx 'Docker/WebAPI/lib/migrate.php' $enumList "'running','queued','succeeded','failed','cancelled','partial'"
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-enum-sync.sh') @('--ci') $fx) @(1) '\[enum-sync\.drift\]'
    } }
    @{ Name = 'enum-sync.zero-match'; Body = {
        $fx = New-Fixture $enumFixtureFiles
        Edit-Fixture $fx 'Docker/WebAPI/lib/deploy_constants.php' 'VIRTUSPHERE_DEPLOY_STATUS_' 'VIRTUSPHERE_DEPLOY_XSTATUS_'
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-enum-sync.sh') @('--ci') $fx) @(1) '\[enum-sync\.no-consts\]'
    } }

    @{ Name = 'php-version-sync.green'; Body = {
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-php-version-sync.sh') @('--ci')) @(0)
    } }
    @{ Name = 'php-version-sync.drift'; Body = {
        $fx = New-Fixture $phpVerFixtureFiles
        Edit-Fixture $fx 'Docker/WebAPI/composer.json' '"php": "8.4.0"' '"php": "8.3.0"'
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-php-version-sync.sh') @('--ci') $fx) @(1) '\[php-version-sync\.drift\]'
    } }
    @{ Name = 'php-version-sync.zero-match'; Body = {
        $fx = New-Fixture $phpVerFixtureFiles
        Edit-Fixture $fx 'Docker/php/Dockerfile' 'FROM php:' 'FROM notphp:'
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-php-version-sync.sh') @('--ci') $fx) @(1) '\[php-version-sync\.no-ssot\]'
    } }

    @{ Name = 'doc-hygiene.green'; Body = {
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-hygiene.sh') @('--ci')) @(0)
    } }
    @{ Name = 'doc-hygiene.changelog-marker'; Body = {
        $fx = New-Fixture $docFixtureFiles
        $grok = [System.IO.File]::ReadAllText((Join-Path $fx 'GROK.md'))
        [System.IO.File]::WriteAllText((Join-Path $fx 'GROK.md'), ("## Stand 2026-07-16`n" + $grok), (New-Object System.Text.UTF8Encoding($false)))
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-hygiene.sh') @('--ci') $fx) @(1) '\[doc-hygiene\.changelog-marker\]'
    } }
    @{ Name = 'doc-hygiene.line-budget'; Body = {
        $fx = New-Fixture $docFixtureFiles
        $pad = ("Zeile fuer das Budget`n" * 80)
        [System.IO.File]::AppendAllText((Join-Path $fx 'CLAUDE.md'), $pad)
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-hygiene.sh') @('--ci') $fx) @(1) '\[doc-hygiene\.line-budget\]'
    } }

    @{ Name = 'lang-audit.green'; Body = {
        Assert-Guard (Invoke-GuardPhp 'lang-audit.php' @('--ci')) @(0)
    } }
    @{ Name = 'lang-audit.missing-key'; Body = {
        $fx = New-Fixture @('Docker/WebAPI/lang')
        Edit-Fixture $fx 'Docker/WebAPI/lang/de/deploy.php' '];' "    'zz_guard_probe' => 'Probe',`n];"
        Assert-Guard (Invoke-GuardPhp 'lang-audit.php' @('--ci') $fx) @(1) '\[lang-audit\.parity-gap\].*zz_guard_probe'
    } }
    @{ Name = 'lang-audit.placeholder-drift'; Body = {
        $fx = New-Fixture @('Docker/WebAPI/lang')
        Edit-Fixture $fx 'Docker/WebAPI/lang/de/deploy.php' '];' "    'zz_guard_ph' => 'Wert :min',`n];"
        Edit-Fixture $fx 'Docker/WebAPI/lang/en/deploy.php' '];' "    'zz_guard_ph' => 'value :minimum',`n];"
        Assert-Guard (Invoke-GuardPhp 'lang-audit.php' @('--ci') $fx) @(1) '\[lang-audit\.placeholder-drift\].*zz_guard_ph'
    } }

    @{ Name = 'bounds-sync.green'; Body = {
        Assert-Guard (Invoke-GuardPhp 'check-bounds-sync.php' @('--ci')) @(0)
    } }
    @{ Name = 'bounds-sync.spelled-out'; Body = {
        $fx = New-Fixture $boundsFixtureFiles
        Add-FixtureFile $fx 'Docker/WebAPI/lib/zz_guard_probe.php' "<?php`nconst VIRTUSPHERE_GUARD_PROBE_SECONDS = 424242;`n"
        Edit-Fixture $fx 'Docker/WebAPI/lang/de/deploy.php' '];' "    'zz_guard_bounds' => 'Wartet 424242 Sekunden auf den Worker.',`n];"
        Assert-Guard (Invoke-GuardPhp 'check-bounds-sync.php' @('--ci') $fx) @(1) '\[bounds-sync\.spelled-out\].*zz_guard_bounds'
    } }
    @{ Name = 'bounds-sync.stale-exempt'; Body = {
        $fx = New-Fixture $boundsFixtureFiles
        Edit-Fixture $fx 'Docker/WebAPI/lang/de/validate.php' "'netbios_hostname'" "'zz_renamed_netbios'"
        Assert-Guard (Invoke-GuardPhp 'check-bounds-sync.php' @('--ci') $fx) @(1) '\[bounds-sync\.stale-exempt\].*netbios_hostname'
    } }

    @{ Name = 'csp.file-clean'; Body = {
        $fx = New-Fixture
        Add-FixtureFile $fx 'clean.php' "<?php`n`$x = 1;`n"
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'lint-csp-patterns.sh') @('--file', 'clean.php') $fx) @(0)
    } }
    @{ Name = 'csp.hard-pattern'; Body = {
        $fx = New-Fixture
        Add-FixtureFile $fx 'bad.php' "<?php`n`$db->query(`"SELECT * FROM t WHERE id = `$id`");`n"
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'lint-csp-patterns.sh') @('--file', 'bad.php') $fx) @(2) '\[csp\.interpolated-sql\]'
    } }
    @{ Name = 'csp.worktree-staged-unstaged-untracked'; Body = {
        if (-not (Test-Command 'git')) { return @{ Status = 'infra'; Detail = 'git fehlt' } }
        $fx = New-Fixture
        $git = { param([string[]]$GitArguments) Invoke-Tool 'git' (@('-C', $fx) + $GitArguments) }
        [void](& $git @('init', '-q', '.'))
        [void](& $git @('config', 'user.email', 'guard@test'))
        [void](& $git @('config', 'user.name', 'guard'))
        Add-FixtureFile $fx 'tracked.php' "<?php`n`$x = 1;`n"
        [void](& $git @('add', 'tracked.php'))
        [void](& $git @('commit', '-qm', 'init'))
        Add-FixtureFile $fx 'staged.php' "<?php`n`$db->query(`"SELECT a FROM t WHERE b = `$b`");`n"
        [void](& $git @('add', 'staged.php'))
        Add-FixtureFile $fx 'tracked.php' "<?php`nmysqli_query(`$c, `"DELETE FROM t WHERE x = `$x`");`n"
        Add-FixtureFile $fx 'untracked.php' "<?php`n`$db->query(`"SELECT `$col FROM t`");`n"
        $r = Invoke-GuardShell (Join-Path $scriptDir 'lint-csp-patterns.sh') @('--worktree') $fx
        $verdict = Assert-Guard $r @(2) '\[csp\.interpolated-sql\]'
        if ($verdict.Status -ne 'proven') { return $verdict }
        $joined = @($r.Output) -join "`n"
        foreach ($expected in @('staged.php', 'tracked.php', 'untracked.php')) {
            if ($joined -notmatch [regex]::Escape($expected)) {
                return @{ Status = 'unproven'; Detail = ('--worktree hat ' + $expected + ' nicht gefunden') }
            }
        }
        return @{ Status = 'proven'; Detail = '' }
    } }
    @{ Name = 'csp.worktree-no-git'; Body = {
        $fx = New-Fixture
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'lint-csp-patterns.sh') @('--worktree') $fx) @(2) '\[csp\.no-git\]'
    } }

    @{ Name = 'sh-posix.parse-all'; Body = {
        if (-not $shExe) { return @{ Status = 'infra'; Detail = 'sh fehlt' } }
        $shFiles = @(Get-ChildItem -Path (Join-Path $repoRoot 'scripts') -Filter '*.sh' -File)
        $shFiles += @(Get-ChildItem -Path (Join-Path (Join-Path $repoRoot '.claude') 'hooks') -Filter '*.sh' -File)
        if ($shFiles.Count -eq 0) { return @{ Status = 'unproven'; Detail = 'Zero-Match: keine Shellskripte gefunden' } }
        foreach ($f in $shFiles) {
            $r = Invoke-GuardShell '-n' @(($f.FullName -replace '\\', '/'))
            if ($r.ExitCode -ne 0) {
                return @{ Status = 'unproven'; Detail = ($f.Name + ' ist nicht POSIX-parsebar: ' + (@($r.Output) -join ' ')) }
            }
        }
        return @{ Status = 'proven'; Detail = ('' + $shFiles.Count + ' Skripte POSIX-parsebar') }
    } }

    @{ Name = 'runner.powershell-syntax.red'; Body = {
        $fx = New-Fixture
        Add-FixtureFile $fx 'broken.ps1' "function Broken {`n"
        Assert-Guard (Invoke-RunnerGate $fx 'powershell-syntax') @(1)
    } }
    @{ Name = 'runner.powershell-syntax.zero-match'; Body = {
        $fx = New-Fixture
        Assert-Guard (Invoke-RunnerGate $fx 'powershell-syntax') @(2)
    } }
    @{ Name = 'runner.js-syntax.red'; Body = {
        if (-not (Test-Command 'node')) { return @{ Status = 'infra'; Detail = 'node fehlt' } }
        $fx = New-Fixture
        Add-FixtureFile $fx 'Docker/WebAPI/portal/assets/bad.js' "function {`n"
        Assert-Guard (Invoke-RunnerGate $fx 'js-syntax') @(1)
    } }
    @{ Name = 'runner.js-syntax.zero-match'; Body = {
        if (-not (Test-Command 'node')) { return @{ Status = 'infra'; Detail = 'node fehlt' } }
        $fx = New-Fixture
        Assert-Guard (Invoke-RunnerGate $fx 'js-syntax') @(2)
    } }
    @{ Name = 'runner.fail-dominates-infra'; Body = {
        $fx = New-Fixture
        Add-FixtureFile $fx 'broken.ps1' "function Broken {`n"
        # ansible-lint ist ohne QA-Image infrastructure_error; der Parserfehler
        # muss trotzdem Exit 1 dominieren (ADR-0031 Exitcode-Praezedenz).
        Assert-Guard (Invoke-RunnerGate $fx 'powershell-syntax,ansible-lint') @(1)
    } }
    @{ Name = 'runner.yaml-lint.red'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture
        Add-FixtureFile $fx 'bad.yml' "key: value:`n  - broken: [unclosed`n"
        Assert-Guard (Invoke-RunnerGate $fx 'yaml-lint') @(1) -InfraOnExit2
    } }
    @{ Name = 'runner.actionlint.red'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture
        Add-FixtureFile $fx '.github/workflows/bad.yml' "on: [push]`njobs:`n  probe:`n    runs-on: ubuntu-latest`n"
        Assert-Guard (Invoke-RunnerGate $fx 'actionlint') @(1) -InfraOnExit2
    } }
    @{ Name = 'runner.hadolint.red'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture
        Add-FixtureFile $fx 'Docker/probe/Dockerfile' "FROM debian`n"
        Assert-Guard (Invoke-RunnerGate $fx 'hadolint') @(1) -InfraOnExit2
    } }
    @{ Name = 'runner.shellcheck.red'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture
        # Unabgeschlossenes Quote: Parserfehler (SC1078, error-Level), sicher
        # oberhalb der -S-warning-Schwelle des Gates.
        Add-FixtureFile $fx 'probe.sh' "#!/bin/sh`necho `"unclosed`n"
        Assert-Guard (Invoke-RunnerGate $fx 'shellcheck') @(1) -InfraOnExit2
    } }
    @{ Name = 'runner.yaml-roundtrip.drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        # Expected-Vertrag der Golden-Mission mutieren: der PyYAML-Verifier
        # muss die semantische Abweichung rot melden, nicht leer gruen laufen.
        $fx = New-Fixture @('Docker/WebAPI/lib', 'Docker/WebAPI/tests/fixtures', 'Docker/WebAPI/tests/tools', 'Ansible/tests')
        Edit-Fixture $fx 'Docker/WebAPI/tests/fixtures/golden-mission.json' '"memory": 8192' '"memory": 9999'
        Assert-Guard (Invoke-RunnerGate $fx 'yaml-roundtrip') @(1) -InfraOnExit2
    } }
    @{ Name = 'runner.yaml-roundtrip.zero-match'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture
        Assert-Guard (Invoke-RunnerGate $fx 'yaml-roundtrip') @(2)
    } }
)

# Hook-Payload-Faelle brauchen stdin; eigener Baustein statt Invoke-Tool.
function Invoke-HookWithPayload {
    param([string]$HookRelPath, [string]$Payload)
    if (-not $shExe) { return $null }
    $prevPath = $env:PATH
    $env:PATH = (Split-Path $shExe -Parent) + [System.IO.Path]::PathSeparator + $env:PATH
    $payloadFile = Join-Path $workRoot ('payload-' + [System.IO.Path]::GetRandomFileName() + '.json')
    [System.IO.File]::WriteAllText($payloadFile, $Payload, (New-Object System.Text.UTF8Encoding($false)))
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $hookPosix = ((Join-Path $repoRoot $HookRelPath) -replace '\\', '/')
        $payloadPosix = ($payloadFile -replace '\\', '/')
        $lines = @(& $shExe '-c' ("cd '" + $repoRoot + "' && sh '" + $hookPosix + "' < '" + $payloadPosix + "'") 2>&1 | ForEach-Object { "$_" })
        return @{ ExitCode = $LASTEXITCODE; Output = $lines }
    } finally {
        $ErrorActionPreference = $prev
        $env:PATH = $prevPath
    }
}

# Hook-Payload-Faelle: ungueltige Payloads duerfen weder crashen noch blocken
# (Exit 0); eine Payload auf eine kaputte PHP-Datei muss blocken (Exit 2),
# sofern Host-PHP existiert.
$cases += @(
    @{ Name = 'hooks.php-lint.invalid-payload'; Body = {
        Assert-Guard (Invoke-HookWithPayload '.claude/hooks/php-lint.sh' 'kein json {{{') @(0)
    } }
    @{ Name = 'hooks.lang-parity.invalid-payload'; Body = {
        Assert-Guard (Invoke-HookWithPayload '.claude/hooks/lang-parity.sh' 'kein json {{{') @(0)
    } }
    @{ Name = 'hooks.csp.invalid-payload'; Body = {
        Assert-Guard (Invoke-HookWithPayload '.claude/hooks/lint-csp-patterns.sh' 'kein json {{{') @(0)
    } }
    @{ Name = 'hooks.php-lint.blocks-syntax-error'; Body = {
        if (-not (Test-Command 'php')) { return @{ Status = 'infra'; Detail = 'Host-PHP fehlt; der Hook lintet fixture-fremde Pfade nur mit Host-PHP' } }
        $fx = New-Fixture
        Add-FixtureFile $fx 'broken.php' "<?php`nif (true {`n"
        $payload = '{"tool_input":{"file_path":"' + (($fx -replace '\\', '/') + '/broken.php') + '"}}'
        Assert-Guard (Invoke-HookWithPayload '.claude/hooks/php-lint.sh' $payload) @(2) 'BLOCK'
    } }
)

# Tool-Lockdatei (AP4): check.ps1 verweigert den Lauf ohne gueltige Pins
# (Exit 2, Pruefumgebung), niemals ein stiller Lauf gegen ungepinnte Tools.
# Die Faelle kopieren check.ps1 samt Lock in ein Fixture und mutieren dort.
function Invoke-FixtureRunner {
    param([string]$FixtureRoot)
    return Invoke-Tool $hostShell @('-NoProfile', '-ExecutionPolicy', 'Bypass',
        '-File', (Join-Path (Join-Path $FixtureRoot 'scripts') 'check.ps1'), '-List')
}
$cases += @(
    @{ Name = 'tool-lock.green'; Body = {
        Assert-Guard (Invoke-Tool $hostShell @('-NoProfile', '-ExecutionPolicy', 'Bypass',
            '-File', (Join-Path $scriptDir 'check.ps1'), '-List')) @(0)
    } }
    @{ Name = 'tool-lock.missing-file'; Body = {
        $fx = New-Fixture @('scripts/check.ps1')
        Assert-Guard (Invoke-FixtureRunner $fx) @(2) 'Tool-Lockdatei fehlt'
    } }
    @{ Name = 'tool-lock.broken-json'; Body = {
        $fx = New-Fixture @('scripts/check.ps1', 'scripts/tool-lock.json')
        Edit-Fixture $fx 'scripts/tool-lock.json' '"version": 1,' '"version": 1,,'
        Assert-Guard (Invoke-FixtureRunner $fx) @(2) 'Tool-Lockdatei unlesbar'
    } }
    @{ Name = 'tool-lock.missing-image-entry'; Body = {
        $fx = New-Fixture @('scripts/check.ps1', 'scripts/tool-lock.json')
        Edit-Fixture $fx 'scripts/tool-lock.json' '"yamllint": {' '"yamllint-renamed": {'
        Assert-Guard (Invoke-FixtureRunner $fx) @(2) 'ohne dockerImages\.yamllint'
    } }
)

# --- Ausfuehrung -----------------------------------------------------------------
$selected = $cases
if ($Filter) {
    $selected = @($cases | Where-Object {
        $name = $_.Name
        @($Filter | Where-Object { $name.StartsWith($_) }).Count -gt 0
    })
    if ($selected.Count -eq 0) {
        Write-Host 'test-guards.ps1: kein Fall passt zum Filter (siehe -List)' -ForegroundColor Red
        exit 3
    }
}

if ($List) {
    Write-Host ('{0} Guard-Faelle:' -f $selected.Count)
    foreach ($c in $selected) { Write-Host ('  ' + $c.Name) }
    exit 0
}

New-Item -ItemType Directory -Force -Path $workRoot | Out-Null
$proven = 0
$unproven = 0
$infra = 0

try {
    Write-Host ('==> VirtuSphere test-guards.ps1 - {0} Fall/Faelle' -f $selected.Count) -ForegroundColor Cyan
    foreach ($case in $selected) {
        $verdict = $null
        try {
            $verdict = & $case.Body
        } catch {
            $verdict = @{ Status = 'infra'; Detail = ('Exception: ' + $_.Exception.Message) }
        }
        switch ($verdict.Status) {
            'proven' {
                $proven = $proven + 1
                Write-Host ('[proven]   ' + $case.Name) -ForegroundColor Green
            }
            'infra' {
                $infra = $infra + 1
                Write-Host ('[infra]    ' + $case.Name + ' - ' + $verdict.Detail) -ForegroundColor Yellow
            }
            default {
                $unproven = $unproven + 1
                Write-Host ('[unproven] ' + $case.Name + ' - ' + $verdict.Detail) -ForegroundColor Red
            }
        }
    }
} finally {
    Remove-Item -Recurse -Force -Path $workRoot -ErrorAction SilentlyContinue
}

$exitCode = 0
if ($infra -gt 0) { $exitCode = 2 }
if ($unproven -gt 0) { $exitCode = 1 }
Write-Host ''
Write-Host ('Guards: {0} proven, {1} unproven, {2} infra => Exit {3}' -f $proven, $unproven, $infra, $exitCode) `
    -ForegroundColor $(if ($exitCode -eq 0) { 'Green' } elseif ($exitCode -eq 1) { 'Red' } else { 'Yellow' })
exit $exitCode
