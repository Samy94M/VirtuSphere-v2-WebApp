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
# doc-semantics liest den vollen Doku-Scope plus seine SSoT-Quellen; eine
# unvollstaendige Fixture wuerde ueber missing-file rot und die Mutation
# waere nicht mehr der bewiesene Grund.
$docSemFixtureFiles = @(
    'README.md', 'AGENTS.md', 'GROK.md', 'CLAUDE.md', 'PRE-SHIP-CHECKLIST.md',
    'docs/QA.md', 'docs/QUALITY-GATES.md', 'docs/TESTPLAN.md',
    'docs/DEPLOYMENT.md', 'docs/INSTALLATION-ANLEITUNG.md',
    'docs/operations', 'docs/security',
    'Docker/WebAPI/phpstan.neon.dist', 'docker-compose.yml', '.github/workflows/ci.yml',
    # SSoT der const-mirror-Regel: ohne sie meldet jede "aktuell N"-Nennung im
    # Doku-Scope "nicht auffindbar", und jeder andere Fall waere aus dem
    # falschen Grund rot statt aus seiner eigenen Mutation.
    'Docker/WebAPI/lib/constants.php', 'Docker/WebAPI/lib/deploy_constants.php',
    # SSoT der env-key-Regel: ohne .env.example meldet sie no-ssot, und jeder
    # andere Fall waere aus dem falschen Grund rot.
    '.env.example',
    # SSoT der Hardware-Version: dasselbe Argument. Ohne das Playbook meldet
    # Regel 15 no-ssot und faerbt jeden anderen Fall mit.
    'Ansible/createVMs-ESXi_playbook.yml'
)
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

    @{ Name = 'doc-semantics.green'; Body = {
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci')) @(0)
    } }
    @{ Name = 'doc-semantics.pre-ship-checked'; Body = {
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'PRE-SHIP-CHECKLIST.md' '- [ ] Fast-Lane' '- [x] Fast-Lane'
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.pre-ship-checked\]'
    } }
    @{ Name = 'doc-semantics.stale-number'; Body = {
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'docs/QA.md' 'PHPStan (level 5' 'PHPStan (level 4'
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.phpstan-level\]'
    } }
    @{ Name = 'doc-semantics.const-mirror'; Body = {
        # Zweite Nennung derselben Zeile: sie faellt nur auf, wenn die Regel jede
        # Nennung einzeln an ihre Zahl bindet statt einmal pro Zeile zu ersetzen.
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'docs/operations/esxi-inventory.md' 'aktuell 2x' 'aktuell 5x'
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.const-mirror\]'
    } }
    @{ Name = 'doc-semantics.sccm-terminology'; Body = {
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'docs/operations/mecm-integration.md' 'MECM-Server' 'SCCM-Server'
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.sccm-terminology\]'
    } }
    @{ Name = 'doc-semantics.zero-match'; Body = {
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'Docker/WebAPI/phpstan.neon.dist' 'level: 5' 'tier: 5'
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.no-ssot\]'
    } }
    @{ Name = 'doc-semantics.phantom-path'; Body = {
        # Der Originalbefund: das Runbook schickte den Admin zu einer
        # Erstpasswort-Datei, die nichts im Repository je schreibt. Der
        # Erzeuger-Scan laeuft gegen den echten Baum (nicht die Fixture), sonst
        # waere in einer Doku-Fixture jeder Pfad scheinbar verwaist.
        #
        # Der Dateiname wird zusammengesetzt und steht nirgends als Literal: aus
        # demselben Grund. Ein Literal in dieser Datei liegt ausserhalb von docs/
        # und waere fuer den Scan selbst ein Erzeuger, womit die Fixture die
        # Regel aushebeln wuerde, die sie beweisen soll.
        $phantom = 'initial-admin' + '-password' + '.txt'
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'docs/operations/go-live.md' '## Schritt 3: Backup' ("Das Erstpasswort liegt in ``Docker/WebAPI/logs/$phantom``.`n`n## Schritt 3: Backup")
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.phantom-path\]'
    } }
    @{ Name = 'doc-semantics.phantom-path-removal-note'; Body = {
        # Gegenrichtung: eine Loeschungsnotiz ("entfernt X") darf NICHT rot
        # werden, sonst waere jede historische Aussage in aktiver Doku ein Befund
        # und die Regel wuerde abgeschaltet.
        $gone = 'keiner-liest' + '-das' + '.txt'
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'docs/operations/go-live.md' '## Schritt 3: Backup' ("Phase X entfernt ``portal/$gone``.`n`n## Schritt 3: Backup")
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(0)
    } }
    @{ Name = 'doc-semantics.env-key-unnamed'; Body = {
        # APP_BIND_IP fehlte im .env-Schritt, und sein Vorlagenwert macht das
        # Portal im LAN unerreichbar, waehrend der Stack gesund ist.
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'docs/operations/go-live.md' 'APP_BIND_IP' 'APP_IRGENDWAS'
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.env-key-unnamed\]'
    } }
    @{ Name = 'doc-semantics.migration-range'; Body = {
        # Die Bereichsform, die die Zaehlregel nicht fing: beide Enden sind
        # konkrete Namen, also sah die Spanne wie eine fachliche Referenz aus und
        # veraltete trotzdem mit der naechsten Migration.
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'docs/operations/go-live.md' '## Schritt 3: Backup' ("Erwartet: Migrationen 0001-0028 angewandt.`n`n## Schritt 3: Backup")
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.migration-range\]'
    } }
    @{ Name = 'doc-semantics.doc-ascii-umlaut'; Body = {
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'docs/operations/go-live.md' '## Schritt 3: Backup' ("Das Backup laeuft taeglich und ist fuer den Betrieb noetig.`n`n## Schritt 3: Backup")
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.doc-ascii-umlaut\]'
    } }
    @{ Name = 'doc-semantics.doc-ascii-umlaut-in-code'; Body = {
        # Gegenrichtung: derselbe Text in Backticks ist ein zitierter Wert. Ein
        # PowerShell-Skript vergleicht wirklich gegen 'Uebersprungen'; dort einen
        # Umlaut zu erzwingen wuerde die Doku falsch machen, und eine Regel, die
        # das verlangt, wird abgeschaltet statt befolgt.
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'docs/operations/go-live.md' '## Schritt 3: Backup' ("Das Skript meldet ``Uebersprungen``.`n`n## Schritt 3: Backup")
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(0)
    } }
    @{ Name = 'doc-semantics.hw-version-matrix'; Body = {
        # Das Paar, nicht die Zahl: eine Hardware-Version, deren ESXi-Untergrenze
        # die Support-Matrix nicht nennt, verspricht Hosts, auf denen die
        # VM-Erstellung hart fehlschlaegt.
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'Ansible/createVMs-ESXi_playbook.yml' 'version: 21' 'version: 19'
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.hw-version-matrix\]'
    } }
    @{ Name = 'doc-semantics.hw-version-unknown'; Body = {
        # Eine Version, fuer die niemand eine Untergrenze belegt hat, ist kein
        # gruener Lauf: sonst haette ein kuenftiges vmx-22 die Regel still
        # stillgelegt.
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'Ansible/createVMs-ESXi_playbook.yml' 'version: 21' 'version: 22'
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.hw-version-unknown\]'
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
    @{ Name = 'bounds-sync.array-element'; Body = {
        # An array element carries the unit in its key, not in a constant name.
        # Before the allowlist, "50 GB" in the storage help was invisible for two
        # independent reasons: the value lives in VIRTUSPHERE_VM_DEFAULTS and "GB"
        # was not a unit word.
        $fx = New-Fixture $boundsFixtureFiles
        Edit-Fixture $fx 'Docker/WebAPI/lang/de/deploy.php' '];' "    'zz_guard_array' => 'Standard sind 50 GB je Festplatte.',`n];"
        Assert-Guard (Invoke-GuardPhp 'check-bounds-sync.php' @('--ci') $fx) @(1) "\[bounds-sync\.spelled-out\].*zz_guard_array.*VM_DEFAULTS"
    } }
    @{ Name = 'bounds-sync.product-constant'; Body = {
        # A constant written as a product, read in the unit the prose uses. Both
        # halves have to work: evaluating 2 * 1024 * 1024 and deriving MB from it.
        $fx = New-Fixture $boundsFixtureFiles
        Edit-Fixture $fx 'Docker/WebAPI/lang/de/deploy.php' '];' "    'zz_guard_bytes' => 'Die Datei darf hoechstens 2 MB gross sein.',`n];"
        Assert-Guard (Invoke-GuardPhp 'check-bounds-sync.php' @('--ci') $fx) @(1) "\[bounds-sync\.spelled-out\].*zz_guard_bytes.*MISSION_IMPORT_MAX_BYTES"
    } }
    @{ Name = 'bounds-sync.unreadable-array'; Body = {
        # An allowlisted array the script can no longer parse must fail loudly.
        # Silently skipping it leaves the elements unchecked while the guard keeps
        # reporting green, which is worse than never having had the check.
        $fx = New-Fixture $boundsFixtureFiles
        Edit-Fixture $fx 'Docker/WebAPI/lib/defaults.php' 'const VIRTUSPHERE_VM_DEFAULTS' 'const VIRTUSPHERE_VM_DEFAULTS_RENAMED'
        Assert-Guard (Invoke-GuardPhp 'check-bounds-sync.php' @('--ci') $fx) @(1) '\[bounds-sync\.unreadable-array\].*VIRTUSPHERE_VM_DEFAULTS'
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
    @{ Name = 'runner.ansible-module-contract.missing-library'; Body = {
        # Der Originalbefund war: ohne `requests` scheitert JEDES benutzte
        # community.vmware-Modul beim Import, vor der Argumentpruefung, waehrend
        # ansible-lint --strict gruen bleibt. Bewiesen wird hier die Erkennung -
        # dass diese Meldung den Build bricht - nicht der Zustand des Images: den
        # beweist der gruene Gate-Lauf gegen das echte Repo. Die Bibliothek im
        # Image zu entfernen ginge nur ueber einen Image-Build je Fall.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture @('Ansible', 'Docker/qa-ansible/module-deprecations.txt',
            'Docker/qa-ansible/module-contract.sh')
        Add-FixtureFile $fx 'Docker/qa-ansible/module-probe.yml' @'
---
- name: Fixture
  hosts: localhost
  gather_facts: false
  tasks:
    - name: die Meldung, die das Gate faengt
      ansible.builtin.fail:
        msg: Failed to import the required Python library (requests) on probe Python /usr/bin/python3
      ignore_errors: true
'@
        Assert-Guard (Invoke-RunnerGate $fx 'ansible-module-contract') @(1) -InfraOnExit2
    } }
    @{ Name = 'runner.ansible-module-contract.argument-spec'; Body = {
        # Gegen die ECHTE Collection: ein Argumentname, den die gepinnte Version
        # nicht kennt, muss auffallen. Genau diese Klasse hat einmal 13
        # Portgruppen als 0 gemeldet, weil die Aufgabe unter ignore_errors lief.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture @('Ansible', 'Docker/qa-ansible/module-probe.yml',
            'Docker/qa-ansible/module-deprecations.txt', 'Docker/qa-ansible/module-contract.sh')
        Edit-Fixture $fx 'Docker/qa-ansible/module-probe.yml' 'esxi_hostname: virtusphere-probe-host' 'esxi_hostnaem: virtusphere-probe-host'
        Assert-Guard (Invoke-RunnerGate $fx 'ansible-module-contract') @(1) -InfraOnExit2
    } }
    @{ Name = 'runner.ansible-module-contract.deprecation-unrecorded'; Body = {
        # Ein upstream neu als deprecated markiertes Modul darf nicht still
        # durchlaufen: die Frist muss im Repo stehen, sonst faellt sie beim Kunden auf.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture @('Ansible', 'Docker/qa-ansible/module-probe.yml',
            'Docker/qa-ansible/module-deprecations.txt', 'Docker/qa-ansible/module-contract.sh')
        Add-FixtureFile $fx 'Docker/qa-ansible/module-deprecations.txt' "# leer`n"
        Assert-Guard (Invoke-RunnerGate $fx 'ansible-module-contract') @(1) -InfraOnExit2
    } }
    @{ Name = 'runner.ansible-module-contract.probe-incomplete'; Body = {
        # Der realistische Weg, wie der Vertrag stillschweigend schrumpft: jemand
        # benutzt ein neues Modul in einem Playbook. Ohne diese Richtung meldet die
        # Probe weiter gruen fuer die zehn, die sie kennt.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture @('Ansible', 'Docker/qa-ansible/module-probe.yml',
            'Docker/qa-ansible/module-deprecations.txt', 'Docker/qa-ansible/module-contract.sh')
        Add-FixtureFile $fx 'Ansible/zzprobe_playbook.yml' @'
---
- name: Fixture
  hosts: localhost
  gather_facts: false
  tasks:
    - name: ein Modul, das die Probe nicht kennt
      community.vmware.vmware_cluster_info:
        hostname: 127.0.0.1
'@
        Assert-Guard (Invoke-RunnerGate $fx 'ansible-module-contract') @(1) 'probe-incomplete' -InfraOnExit2
    } }
    @{ Name = 'runner.ansible-module-contract.probe-stale'; Body = {
        # Gegenrichtung: eine Probe-Zeile fuer ein Modul, das kein Playbook mehr
        # benutzt, bindet den Vertrag an etwas, das uns nicht mehr betrifft.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture @('Ansible', 'Docker/qa-ansible/module-probe.yml',
            'Docker/qa-ansible/module-deprecations.txt', 'Docker/qa-ansible/module-contract.sh')
        Edit-Fixture $fx 'Docker/qa-ansible/module-probe.yml' 'community.vmware.vmware_about_info' 'community.vmware.vmware_cluster_info'
        Assert-Guard (Invoke-RunnerGate $fx 'ansible-module-contract') @(1) 'probe-stale' -InfraOnExit2
    } }
    @{ Name = 'runner.ansible-module-contract.zero-match'; Body = {
        # Eine Ableitung, die nichts findet, macht jede Pruefung darunter
        # dauerhaft und still gruen. Sie muss ein Fehler sein.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture @('Docker/qa-ansible/module-probe.yml',
            'Docker/qa-ansible/module-deprecations.txt', 'Docker/qa-ansible/module-contract.sh')
        Add-FixtureFile $fx 'Ansible/.gitkeep' ''
        Assert-Guard (Invoke-RunnerGate $fx 'ansible-module-contract') @(1) -InfraOnExit2
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
# (Exit 0); eine Payload auf eine kaputte PHP-Datei muss blocken (Exit 2).
# Der Hook nutzt Host-PHP oder fuer fixture-fremde Pfade das Projekt-Image.
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

# Compose-/Dockerfile-Haertungs-Contract (AP8): check-compose-hardening.ps1
# liest VIRTUSPHERE_CHECK_ROOT selbst; die Fixtures brauchen eine .env (aus
# .env.example kopiert, enthaelt keine Geheimnisse), weil compose config die
# env_file-Direktiven aufloest.
function Invoke-ComposeHardeningGuard {
    param([string]$FixtureRoot = '')
    $prevRoot = $env:VIRTUSPHERE_CHECK_ROOT
    if ($FixtureRoot) { $env:VIRTUSPHERE_CHECK_ROOT = ($FixtureRoot -replace '\\', '/') }
    try {
        return Invoke-Tool $hostShell @('-NoProfile', '-ExecutionPolicy', 'Bypass',
            '-File', (Join-Path $scriptDir 'check-compose-hardening.ps1'))
    } finally {
        $env:VIRTUSPHERE_CHECK_ROOT = $prevRoot
    }
}
function New-ComposeFixture {
    $fx = New-Fixture @('docker-compose.yml', '.env.example',
        'Docker/php/Dockerfile', 'Docker/nginx/Dockerfile', 'Docker/qa-ansible/Dockerfile')
    Copy-Item -Force -Path (Join-Path $fx '.env.example') -Destination (Join-Path $fx '.env')
    return $fx
}
$cases += @(
    @{ Name = 'compose-hardening.green'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        Assert-Guard (Invoke-ComposeHardeningGuard) @(0) -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.env-leak-drift'; Body = {
        # Der Originalbefund: env_file gab dem LAN-zugewandten nginx-Container
        # APP_KEY, DB_PASS und MYSQL_ROOT_PASSWORD, obwohl das Image keinen dieser
        # Werte liest. Wiederhergestellt heisst das: die Regel muss es sehen.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' '      - ./Docker/logs/nginx:/var/log/nginx' "      - ./Docker/logs/nginx:/var/log/nginx`n    env_file:`n      - .env"
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.env-scope\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.pma-port-collision-drift'; Body = {
        # Gegenrichtung derselben Regel: der Container BRAUCHT PMA_PORT, weil das
        # Image es als MySQL-Serverport liest. Faellt es weg, protokolliert
        # phpMyAdmin je Anfrage "Undefined array key PMA_PORT" und faellt still auf
        # 3306 zurueck - genau die Sorte stiller Vorgabe, die spaeter jemand
        # umkonfiguriert und nicht mehr findet.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' '      PMA_PORT: ${DB_PORT}' '      # PMA_PORT entfernt'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.env-scope\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.read-only-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' 'read_only: true' 'read_only: false'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.read-only\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.cap-add-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' '      - DAC_READ_SEARCH' ("      - DAC_READ_SEARCH`n      - SYS_ADMIN")
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.cap-add\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.depends-started-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' 'condition: service_healthy' 'condition: service_started'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.depends-healthy\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.pma-profile-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        # Der reale Drift: Profil entfernt (x-Key ist valide, compose ignoriert
        # ihn) und phpMyAdmin startet wieder always-on.
        Edit-Fixture $fx 'docker-compose.yml' '    profiles:' '    x-disabled-profiles:'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.pma-profile\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.pma-wrong-profile-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        # In ein fremdes Profil verschoben: dank --profile "*" bleibt der
        # Service sichtbar und wird als falsch platziert gemeldet.
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' '      - tools' '      - werkzeuge'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.pma-profile\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.image-indirection-drift'; Body = {
        # Die Gegenrichtung: ohne die Indirektion kann ein luftspaltgetrennter Host
        # das Image nicht aufloesen, weil docker load keinen RepoDigest
        # wiederherstellt. Ein umbenannter Variablenname faellt auf, obwohl die
        # AUFGELOESTE Referenz weiter den Digest traegt - genau die Luecke, die die
        # Klartextpruefung schliesst.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' 'image: ${MYSQL_IMAGE:-' 'image: ${MYSQL_IMAGE_TYPO:-'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.image-indirection\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.image-digest-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        # Anker digest-unabhaengig, sonst bricht jeder Digest-Bump den Guard.
        # Die Klammer wird GESCHLOSSEN und der Digest-Rest zum echten
        # YAML-Kommentar: innerhalb von ${...} ist ein # kein Kommentar, und ein
        # Standard mit Leerzeichen macht `docker compose config` unparsebar - der
        # Fall waere dann infra statt Befund.
        Edit-Fixture $fx 'docker-compose.yml' 'image: ${MYSQL_IMAGE:-mysql:8.4@' 'image: ${MYSQL_IMAGE:-mysql:8.4} # @'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.image-digest\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.dockerfile-digest-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        # Gleiche Digest-Unabhaengigkeit: der Check parst die FROM-Referenz nur
        # bis zum ersten Leerzeichen und baut nie, der Rest darf Muell sein.
        Edit-Fixture $fx 'Docker/php/Dockerfile' 'FROM php:8.4-fpm@sha256:' 'FROM php:8.4-fpm sha256:'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.dockerfile-digest\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.zero-match'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' '  phpmyadmin:' '  phpmyadmin-renamed:'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.services\]' -InfraOnExit2
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
