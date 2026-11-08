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

.PARAMETER Shard
    Nur den Shard k von N fahren (Form 'k/N'): jeden N-ten Fall der Auswahl
    nach -Filter, Round-Robin ab Fall k. Die Zaehler laufen dann ueber die
    Faelle des Shards. Ein leerer Shard ist ein ungueltiger Aufruf (Exit 3).

.PARAMETER Parallel
    N Shard-Prozesse gleichzeitig starten (Standard 1 = seriell im selben
    Prozess). Der Elternprozess gibt jede Zeile der Kinder sofort mit dem
    Praefix '[Shard k/N]' weiter, meldet je Shard '[k/N] RUN' und das
    Shard-Ergebnis und fasst die Exitcodes zusammen: 1 vor 2 vor 0. Ein Kind
    ohne vollstaendige Abschlusszeile zaehlt als infra. N wird auf die Zahl
    der ausgewaehlten Faelle begrenzt.

.PARAMETER UnitJUnit
    JUnit-Bericht des Gates phpunit-unit derselben Lane. Dann prueft der Fall
    portal-require-closure.probes die Closure-Proben darin, statt sie ein
    weiteres Mal zu fahren. Ohne Angabe faehrt er sie selbst.

.EXAMPLE
    powershell -NoProfile -File scripts\test-guards.ps1

.EXAMPLE
    powershell -NoProfile -File scripts\test-guards.ps1 -Parallel 4
#>
[CmdletBinding()]
param(
    [string[]]$Filter,
    [switch]$List,
    [string]$Shard = '',
    [int]$Parallel = 1,
    [string]$UnitJUnit = ''
)

Set-StrictMode -Version 1.0
$ErrorActionPreference = 'Stop'

if ($Filter) { $Filter = @($Filter | ForEach-Object { $_ -split ',' } | Where-Object { $_ -ne '' }) }

# Aufrufpruefung vor jedem Werkzeug- oder Verzeichniszugriff: Exit 3 laut Kopf.
$shardIndex = 0
$shardCount = 0
if ($Shard -ne '') {
    if ($Shard -notmatch '^([1-9][0-9]*)/([1-9][0-9]*)$' -or [int]$Matches[1] -gt [int]$Matches[2]) {
        Write-Host ("test-guards.ps1: -Shard erwartet 'k/N' mit 1 <= k <= N, nicht '" + $Shard + "'") -ForegroundColor Red
        exit 3
    }
    $shardIndex = [int]$Matches[1]
    $shardCount = [int]$Matches[2]
}
if ($Parallel -lt 1 -or $Parallel -gt 16) {
    Write-Host ('test-guards.ps1: -Parallel erwartet 1 bis 16, nicht ' + $Parallel) -ForegroundColor Red
    exit 3
}
if ($Parallel -gt 1 -and $shardCount -gt 0) {
    Write-Host 'test-guards.ps1: -Parallel und -Shard schliessen sich aus (der Elternprozess vergibt die Shards)' -ForegroundColor Red
    exit 3
}

$scriptDir = $PSScriptRoot
$repoRoot = (Split-Path $scriptDir -Parent) -replace '\\', '/'
# Ein Laufverzeichnis nach der einen Namensregel des Moduls (eigener Name je Lauf,
# nie wiederverwendet). $workRoot wird erst nach -List und -Filter angelegt, damit
# diese Aufrufe keine Verzeichnisse hinterlassen.
. (Join-Path (Join-Path (Join-Path $scriptDir 'lib') 'check') 'artifact-dir.ps1')
$workRoot = $null
$hostShell = 'powershell'
if ($PSVersionTable.PSEdition -eq 'Core') { $hostShell = 'pwsh' }

# --- Werkzeuge: Invoke-Tool, Test-Command und Find-Sh kommen aus runtime.ps1 ----
# (eine Quelle; das Modul definiert nur Funktionen und fuehrt beim Import nichts aus)
. (Join-Path (Join-Path (Join-Path $scriptDir 'lib') 'check') 'runtime.ps1')
$shExe = Find-Sh
# PHP-Tooling-Image aus der Lockdatei (dockerImages.php.ref), derselben Quelle
# wie der php-lint-Hook; ohne lesbaren Eintrag gilt das Image als nicht verfuegbar.
$phpImage = $null
try {
    $phpImage = (Get-Content -Raw -LiteralPath (Join-Path $scriptDir 'tool-lock.json') | ConvertFrom-Json).dockerImages.php.ref
} catch {
    $phpImage = $null
}
$dockerAvailable = Test-Command 'docker'
$phpImageAvailable = $false
if ($dockerAvailable -and $phpImage) {
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

# Mutation mit Anker: schlaegt der Anker fehl, wirft Edit-Fixture. Der Lauf faengt
# jede Ausnahme eines Falls (catch um $case.Body) und klassifiziert sie als infra,
# nicht als unproven (Exit 2, sofern kein Fall unproven ist): die Quelle hat sich
# bewegt, der Fall ist also weder bewiesen noch widerlegt, und ein No-op-Gruenlauf
# ist ausgeschlossen.
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

# doc-fragments leitet aus Git ab, was neu ist. Die Fixture ist deshalb ein
# Git-Repo, dessen erster Commit die echten Quellen enthaelt (VERSION macht ihn
# zum Release-Commit, die Archivzeile im Register zum Archiv-Commit); die
# Mutation folgt als zweiter Commit. Ohne git: $null, der Fall wird infra.
function New-DocFragmentRepo {
    param([switch]$WithoutArchiveMarker)
    if (-not (Test-Command 'git')) { return $null }
    $fx = New-Fixture $docFragmentFixtureFiles
    if ($WithoutArchiveMarker) {
        Edit-Fixture $fx $docFragmentArchive "<!-- register-archive -->`n" ''
    }
    # Der Release-Commit enthaelt nur die README der Fragmentordner; die echten
    # Fragmente folgen in einem eigenen Commit. Sonst waeren sie aus Sicht des
    # Waechters liegengebliebene Fragmente eines Release-Commits (stale-fragment).
    foreach ($gitArguments in @(
        @('init', '-q', '.'), @('config', 'user.email', 'guard@example.test'), @('config', 'user.name', 'guard'),
        @('config', 'core.autocrlf', 'false'), @('config', 'commit.gpgsign', 'false'),
        @('add', '-A'), @('reset', '-q', '--', 'docs/changelog.d'), @('add', 'docs/changelog.d/README.md'),
        @('commit', '-qm', 'release'),
        @('add', '-A'), @('commit', '-q', '--allow-empty', '-m', 'fragments')
    )) {
        $r = Invoke-Tool 'git' (@('-C', $fx) + $gitArguments)
        if ($r.ExitCode -ne 0) { throw ('git ' + ($gitArguments -join ' ') + ' in der Fixture: ' + (@($r.Output) -join ' ')) }
    }
    return $fx
}

function Save-DocFragmentCommit {
    param([string]$FixtureRoot)
    foreach ($gitArguments in @(@('add', '-A'), @('commit', '-qm', 'mutation'))) {
        $r = Invoke-Tool 'git' (@('-C', $FixtureRoot) + $gitArguments)
        if ($r.ExitCode -ne 0) { throw ('git ' + ($gitArguments -join ' ') + ' in der Fixture: ' + (@($r.Output) -join ' ')) }
    }
}

# name-denylist prueft origin/main..HEAD. Die Fixture ist ein Git-Repo mit einem
# Basis-Commit, auf den refs/remotes/origin/main zeigt (ohne -WithoutBase fehlt
# der Ref), und einem zweiten Commit mit $Content. Der Treffer kommt aus einer
# privaten Liste in der Fixture (VIRTUSPHERE_NAME_DENYLIST); ihr Eintrag wird
# erst zur Laufzeit zusammengesetzt, damit dieses Skript ihn nicht selbst traegt.
# Ohne git: $null, der Fall wird infra.
function New-NameDenylistRepo {
    param([string]$Content, [switch]$WithoutBase)
    if (-not (Test-Command 'git')) { return $null }
    $fx = New-Fixture
    Add-FixtureFile $fx 'README.md' "base`n"
    foreach ($gitArguments in @(
        @('init', '-q', '.'), @('config', 'user.email', 'guard@example.test'), @('config', 'user.name', 'guard'),
        @('config', 'core.autocrlf', 'false'), @('config', 'commit.gpgsign', 'false'), @('config', 'core.hooksPath', 'no-hooks'),
        @('add', '-A'), @('commit', '-qm', 'base')
    )) {
        $r = Invoke-Tool 'git' (@('-C', $fx) + $gitArguments)
        if ($r.ExitCode -ne 0) { throw ('git ' + ($gitArguments -join ' ') + ' in der Fixture: ' + (@($r.Output) -join ' ')) }
    }
    if (-not $WithoutBase) {
        $r = Invoke-Tool 'git' @('-C', $fx, 'update-ref', 'refs/remotes/origin/main', 'HEAD')
        if ($r.ExitCode -ne 0) { throw ('git update-ref in der Fixture: ' + (@($r.Output) -join ' ')) }
    }
    Add-FixtureFile $fx 'docs/change.md' $Content
    foreach ($gitArguments in @(@('add', '-A'), @('commit', '-qm', 'change'))) {
        $r = Invoke-Tool 'git' (@('-C', $fx) + $gitArguments)
        if ($r.ExitCode -ne 0) { throw ('git ' + ($gitArguments -join ' ') + ' in der Fixture: ' + (@($r.Output) -join ' ')) }
    }
    return $fx
}

function Get-NameDenylistProbe { return ('guard' + '-probe-' + 'name') }

# Runner-Gate name-denylist gegen eine Fixture, mit einer privaten Liste aus
# genau dem Probeeintrag.
function Invoke-NameDenylistGate {
    param([string]$FixtureRoot)
    if (-not $FixtureRoot) { return $null }
    $list = Join-Path $FixtureRoot 'private-denylist.txt'
    [System.IO.File]::WriteAllText($list, ((Get-NameDenylistProbe) + "`n"), (New-Object System.Text.UTF8Encoding($false)))
    $prevList = $env:VIRTUSPHERE_NAME_DENYLIST
    $env:VIRTUSPHERE_NAME_DENYLIST = ($list -replace '\\', '/')
    try {
        return Invoke-RunnerGate $FixtureRoot 'name-denylist'
    } finally {
        $env:VIRTUSPHERE_NAME_DENYLIST = $prevList
    }
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
# Jede Datei, aus der check-enum-sync.sh eine Seite eines Spiegels liest. Fehlt
# eine, meldet der Waechter fuer dieses Paar [enum-sync.no-consts] und der
# Fixture-Lauf wird rot, BEVOR die eigentliche Mutation greift: der Fall besteht
# dann aus dem falschen Grund und wuerde auch ohne Mutation bestehen.
# directory_constants.php fehlte hier genau so.
$enumFixtureFiles = @(
    'Docker/WebAPI/lib/constants.php', 'Docker/WebAPI/lib/permissions.php',
    'Docker/WebAPI/lib/deploy_constants.php', 'Docker/WebAPI/lib/credentials.php',
    'Docker/WebAPI/lib/directory_constants.php', 'Docker/WebAPI/lib/deploy_create_constants.php',
    'Docker/WebAPI/lib/auth_rate_limit.php',
    'Docker/mysql/mysql-init/struktur.sql', 'Docker/WebAPI/lib/migrate.php',
    'Docker/WebAPI/lib/migrations'
)
$fileSizeFixtureFiles = @('Docker/WebAPI/lib', 'Docker/WebAPI/portal')
$moduleContractFixtureFiles = @(
    'Ansible', 'Docker/qa-ansible/module-probe.yml',
    'Docker/qa-ansible/module-deprecations.txt', 'Docker/qa-ansible/module-contract.sh',
    'Docker/qa-ansible/verify-collection-lock.py', 'Docker/qa-ansible/collection-lock-contract.py'
)
$phpVerFixtureFiles = @(
    'Docker/php/Dockerfile', 'Docker/WebAPI/composer.json',
    'Docker/WebAPI/lib/constants.php', 'CLAUDE.md', 'AGENTS.md'
)
$docFixtureFiles = @('AGENTS.md', 'GROK.md', 'CLAUDE.md', 'README.md')
# doc-fragments: das Archiv ist die Datei mit der Archivzeile; die Fixture braucht
# sie, den CHANGELOG, VERSION und beide Fragmentordner samt README.
$docFragmentArchive = 'docs/audits/2026-09-12-consolidated-session-backlog.md'
$docFragmentFixtureFiles = @(
    'Docker/WebAPI/lib/VERSION', 'docs/CHANGELOG.md', $docFragmentArchive,
    'docs/changelog.d', 'docs/register'
)
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
    # Der Name folgt dem, was check-doc-semantics.sh in $create_playbook
    # liest. Teiletappe D hat das alte createVMs-Playbook in vier zerlegt und
    # die Pruefung mitgezogen, diese Fixture aber nicht: sechzehn
    # doc-semantics-Waechter liefen danach als infra und bewiesen nichts
    # mehr, ohne dass irgendetwas rot wurde.
    'Ansible/createVMLaunch-ESXi_playbook.yml',
    # Seit ADR-0023 Nachtrag 7 die eigentliche SSoT der Regel 15: die Tabelle
    # der vmx-Versionen je ESXi-Version. Ohne sie meldet Regel 15 no-ssot.
    'Docker/WebAPI/lib/esxi_create_compatibility.php'
)
# struktur.sql gehoert seit Etappe 10C dazu: bounds-sync prueft dort den
# gespiegelten Audit-Kontext-Bytewert gegen die PHP-Konstante und bricht ohne
# die Datei mit audit-context-zero-match ab. Jeder andere Fall waere dann rot
# aus einem anderen Grund als seiner eigenen Mutation, also unproven.
$boundsFixtureFiles = @('Docker/WebAPI/lib', 'Docker/WebAPI/lang', 'Docker/mysql/mysql-init/struktur.sql')
# check-audit-contract scannt jede first-party PHP-Datei unter Docker/WebAPI und
# liest die Registry als SSoT. Die Wurzeldateien gehoeren dazu, weil die
# Machine-API-Endpunkte eigene Producer sind; ohne sie waere jeder Negativfall
# aus einem anderen Grund rot als aus seiner Mutation.
# Abgeleitet, nicht aufgezaehlt: Jede PHP-Datei im WebAPI-Wurzelverzeichnis kann
# ein Audit-Ereignis erzeugen. Die frueher handgeschriebene Liste kannte
# deploy_vm_touch.php nicht; sein Ereignis galt im Fixture deshalb als ungenutzt.
$auditFixtureFiles = @('Docker/WebAPI/lib', 'Docker/WebAPI/portal') + @(
    Get-ChildItem -LiteralPath (Join-Path $repoRoot 'Docker/WebAPI') -Filter '*.php' -File |
        Sort-Object Name |
        ForEach-Object { 'Docker/WebAPI/' + $_.Name }
)
# Muss dem AKTUELLEN Spiegel folgen (ADR-0016): 'cancelling' kam mit
# Migration 0031, und ein Anker auf einem Literal, das es nicht mehr gibt,
# faerbt beide Mutationsfaelle infra statt proven.
$enumList = "'queued','running','cancelling','succeeded','failed','cancelled','partial'"

$cases = @(
    @{ Name = 'enum-sync.green'; Body = {
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-enum-sync.sh') @('--ci')) @(0)
    } }
    @{ Name = 'enum-sync.missing-value'; Body = {
        $fx = New-Fixture $enumFixtureFiles
        Edit-Fixture $fx 'Docker/mysql/mysql-init/struktur.sql' $enumList "'queued','running','cancelling','succeeded','failed','cancelled'"
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-enum-sync.sh') @('--ci') $fx) @(1) '\[enum-sync\.drift\]'
    } }
    @{ Name = 'enum-sync.reordered'; Body = {
        $fx = New-Fixture $enumFixtureFiles
        Edit-Fixture $fx 'Docker/WebAPI/lib/migrate.php' $enumList "'running','queued','cancelling','succeeded','failed','cancelled','partial'"
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-enum-sync.sh') @('--ci') $fx) @(1) '\[enum-sync\.drift\]'
    } }
    # Tabellenweite Paare (Etappe 14B). `status` ist als Spaltenname mehrdeutig,
    # deshalb prueft der Waechter hier CREATE-TABLE-Block-scoped, und der neue
    # Spiegel liegt in lib/migrations/ statt in migrate.php. Beide Seiten
    # bekommen ihre eigene Mutation, sonst bewiese der Fall nur eine davon.
    @{ Name = 'enum-sync.table-scoped-schema'; Body = {
        $fx = New-Fixture $enumFixtureFiles
        Edit-Fixture $fx 'Docker/mysql/mysql-init/struktur.sql' "'pending','prepared','running','succeeded','failed','uncertain','skipped'" "'pending','prepared','running','succeeded','uncertain','failed','skipped'"
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-enum-sync.sh') @('--ci') $fx) @(1) '\[enum-sync\.drift\]'
    } }
    @{ Name = 'enum-sync.table-scoped-migration'; Body = {
        $fx = New-Fixture $enumFixtureFiles
        Edit-Fixture $fx 'Docker/WebAPI/lib/migrations/0047_deploy_create_results.php' "'create','verify_skip'" "'verify_skip','create'"
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

    # Release-Nummer: die Werte stehen explizit in den Fixtures (keine Mutation
    # einer echten Nummer), damit der Fall bei jedem Release gleich bleibt.
    @{ Name = 'version-sync.green'; Body = {
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-version-sync.sh') @('--ci')) @(0)
    } }
    @{ Name = 'version-sync.drift'; Body = {
        $fx = New-Fixture @()
        Add-FixtureFile $fx 'Docker/WebAPI/lib/VERSION' "2026.10.1`n"
        Add-FixtureFile $fx 'Powershell-MECM/VERSION' "2026.10.2`n"
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-version-sync.sh') @('--ci') $fx) @(1) '\[version-sync\.drift\]'
    } }
    @{ Name = 'version-sync.format'; Body = {
        $fx = New-Fixture @()
        Add-FixtureFile $fx 'Docker/WebAPI/lib/VERSION' "2026.1.1`n"
        Add-FixtureFile $fx 'Powershell-MECM/VERSION' "2026.1.1`n"
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-version-sync.sh') @('--ci') $fx) @(1) '\[version-sync\.format\]'
    } }
    @{ Name = 'version-sync.zero-match'; Body = {
        $fx = New-Fixture @()
        Add-FixtureFile $fx 'Powershell-MECM/VERSION' "2026.10.1`n"
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-version-sync.sh') @('--ci') $fx) @(1) '\[version-sync\.no-ssot\]'
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
    @{ Name = 'doc-hygiene.byte-budget'; Body = {
        $fx = New-Fixture $docFixtureFiles
        # One long line stays below the line budget but must not bypass context limits.
        [System.IO.File]::WriteAllText((Join-Path $fx 'AGENTS.md'), ('x' * 40000), (New-Object System.Text.UTF8Encoding($false)))
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-hygiene.sh') @('--ci') $fx) @(1) '\[doc-hygiene\.byte-budget\]'
    } }
    @{ Name = 'doc-hygiene.utf8-byte-budget'; Body = {
        $fx = New-Fixture $docFixtureFiles
        # Below the character count budget, above the UTF-8 byte budget.
        [System.IO.File]::WriteAllText((Join-Path $fx 'CLAUDE.md'), ([string][char]0x00E4 * 1500), (New-Object System.Text.UTF8Encoding($false)))
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-hygiene.sh') @('--ci') $fx) @(1) '\[doc-hygiene\.byte-budget\]'
    } }
    @{ Name = 'doc-hygiene.zero-match'; Body = {
        $fx = New-Fixture $docFixtureFiles
        [System.IO.File]::WriteAllText((Join-Path $fx 'AGENTS.md'), '', (New-Object System.Text.UTF8Encoding($false)))
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-hygiene.sh') @('--ci') $fx) @(1) '\[doc-hygiene\.empty-file\]'
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
    @{ Name = 'doc-semantics.phantom-path-ignored-decoy'; Body = {
        # Befund G1 (Release 2026.10.1): Im Hauptbaum nannten gitignorierte
        # Audit-Notizen unter qa-artifacts/ den Phantomnamen, und der Scan las
        # sie als Erzeuger. Ein ignorierter Koeder im echten Baum darf den Fund
        # nicht verdecken. Der Koeder liegt nur fuer diesen Fall dort.
        $phantom = 'initial-admin' + '-password' + '.txt'
        $decoyDir = Join-Path $repoRoot ('qa-artifacts/guard-decoy-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Force $decoyDir | Out-Null
        try {
            Set-Content -Encoding utf8 -Path (Join-Path $decoyDir 'note.md') -Value ("Audit-Notiz nennt $phantom")
            $fx = New-Fixture $docSemFixtureFiles
            Edit-Fixture $fx 'docs/operations/go-live.md' '## Schritt 3: Backup' ("Das Erstpasswort liegt in ``Docker/WebAPI/logs/$phantom``.`n`n## Schritt 3: Backup")
            Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.phantom-path\]'
        } finally {
            Remove-Item -Recurse -Force $decoyDir -Confirm:$false
        }
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
    @{ Name = 'doc-semantics.installation-ascii-umlaut'; Body = {
        # Pinnt den weggefallenen Sonderweg: Die Installationsanleitung gehoert
        # zum gleichen deutschen Prosa-Scope wie jedes andere aktive Runbook.
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'docs/INSTALLATION-ANLEITUNG.md' '# Installationsanleitung' ("# Installationsanleitung`n`nDiese Ausnahme waere fuer Administratoren stoerend.")
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
        # Das Paar, nicht die Zahl: die Tabelle in lib/esxi_create_compatibility.php
        # ist die SSoT, und jedes ihrer Paare muss in den Matrixzeilen zur
        # Erstellung stehen. Eine geaenderte vmx-Zuordnung ohne Doku verspricht
        # etwas anderes, als der Worker tut.
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'Docker/WebAPI/lib/esxi_create_compatibility.php' "['esxi' => '7.0.2', 'vmx' => 19]" "['esxi' => '7.0.2', 'vmx' => 18]"
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.hw-version-matrix\]'
    } }
    @{ Name = 'doc-semantics.hw-version-hardwired'; Body = {
        # Der Vorfall vom 15.10.2026: eine verdrahtete Zahl im Playbook ignoriert
        # die gemeldete Hostversion. Sie darf nicht zurueckkommen.
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'Ansible/createVMLaunch-ESXi_playbook.yml' 'version: >-' 'version: 21'
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.hw-version-hardwired\]'
    } }
    @{ Name = 'doc-semantics.hw-version-ssot-unreadable'; Body = {
        # Eine Tabelle, aus der die Regel nichts liest, ist kein gruener Lauf:
        # sonst haette ein Umbau der Zeilenform die Regel still stillgelegt.
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'Docker/WebAPI/lib/esxi_create_compatibility.php' "['esxi' => " "['release' => "
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.no-ssot\]'
    } }
    @{ Name = 'doc-semantics.vcenter-deploy-boundary'; Body = {
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'docs/DEPLOYMENT.md' '| vCenter (`api_type = VirtualCenter`) | deploy **no**, partial read-only inventory |' '| vCenter (`api_type = VirtualCenter`) | deploy yes |'
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.vcenter-deploy-boundary\]'
    } }
    @{ Name = 'doc-semantics.vcenter-deploy-zero-match'; Body = {
        $fx = New-Fixture $docSemFixtureFiles
        Edit-Fixture $fx 'docs/DEPLOYMENT.md' '| vCenter (`api_type = VirtualCenter`) | deploy **no**, partial read-only inventory |' '| Management endpoint | deploy **no**, partial read-only inventory |'
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-semantics.sh') @('--ci') $fx) @(1) '\[doc-semantics\.vcenter-deploy-boundary\]'
    } }

    @{ Name = 'doc-fragments.green'; Body = {
        $r = Invoke-GuardShell (Join-Path $scriptDir 'check-doc-fragments.sh') @('--ci')
        if ($r -and (@($r.Output) -join ' ') -match '\[doc-fragments\.no-git\]') { return @{ Status = 'infra'; Detail = 'Repo ohne volle Git-Historie' } }
        Assert-Guard $r @(0)
    } }
    @{ Name = 'doc-fragments.fixture-control'; Body = {
        $fx = New-DocFragmentRepo
        if (-not $fx) { return @{ Status = 'infra'; Detail = 'git fehlt' } }
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-fragments.sh') @('--ci') $fx) @(0)
    } }
    # Ein Eintrag direkt unter der obersten Release-Ueberschrift, committet: das
    # alte Verfahren mit der Ueberschrift des letzten Releases statt "Noch in
    # keinem Release". Der Anker ist die erste "## "-Zeile, kein Releasename.
    @{ Name = 'doc-fragments.new-entry'; Body = {
        $fx = New-DocFragmentRepo
        if (-not $fx) { return @{ Status = 'infra'; Detail = 'git fehlt' } }
        $file = Join-Path $fx 'docs/CHANGELOG.md'
        $text = [System.IO.File]::ReadAllText($file)
        $heading = $text.IndexOf("`n## ")
        if ($heading -lt 0) { throw 'Mutationsanker nicht gefunden in docs/CHANGELOG.md: erste Ueberschrift' }
        $lineEnd = $text.IndexOf("`n", $heading + 1)
        $text = $text.Insert($lineEnd + 1, "`n- **Guard probe (X1).** Old place.`n")
        [System.IO.File]::WriteAllText($file, $text, (New-Object System.Text.UTF8Encoding($false)))
        Save-DocFragmentCommit $fx
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-fragments.sh') @('--ci') $fx) @(1) '\[doc-fragments\.new-entry\]'
    } }
    # unreleased-section, fragment-format und no-git beweist
    # tests/powershell/VirtuSphere.DocFragments.Tests.ps1 gegen dasselbe Skript
    # mit derselben Diagnose-ID (R4, C2: je Regel abgeschaltet, Pester-Fall rot).
    # Hier bleiben gruen, Fixture-Kontrolle, je eine Mutation der beiden Haelften
    # (CHANGELOG und Archivregister) auf den echten Quellen und Zero-Match.
    @{ Name = 'doc-fragments.archive-entry'; Body = {
        $fx = New-DocFragmentRepo
        if (-not $fx) { return @{ Status = 'infra'; Detail = 'git fehlt' } }
        $file = Join-Path $fx $docFragmentArchive
        [System.IO.File]::AppendAllText($file, "`n### Welle X1 (guard probe)`n", (New-Object System.Text.UTF8Encoding($false)))
        Save-DocFragmentCommit $fx
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-fragments.sh') @('--ci') $fx) @(1) '\[doc-fragments\.archive-entry\]'
    } }
    @{ Name = 'doc-fragments.zero-match'; Body = {
        $fx = New-DocFragmentRepo -WithoutArchiveMarker
        if (-not $fx) { return @{ Status = 'infra'; Detail = 'git fehlt' } }
        Assert-Guard (Invoke-GuardShell (Join-Path $scriptDir 'check-doc-fragments.sh') @('--ci') $fx) @(1) '\[doc-fragments\.no-archive\]'
    } }

    # name-denylist: das Runner-Gate selbst (Bereich, Exitcode, Basis). Was das
    # Skript als Treffer erkennt, beweist tests/powershell/VirtuSphere.NameDenylist.Tests.ps1.
    @{ Name = 'name-denylist.runner-clean'; Body = {
        Assert-Guard (Invoke-NameDenylistGate (New-NameDenylistRepo "clean change`n")) @(0) 'origin/main\.\.HEAD: 1 Commit\(s\) ohne Treffer'
    } }
    @{ Name = 'name-denylist.runner-hit'; Body = {
        Assert-Guard (Invoke-NameDenylistGate (New-NameDenylistRepo ('host ' + (Get-NameDenylistProbe) + "`n"))) @(1) '1 von 1 Commit\(s\) mit Treffer'
    } }
    @{ Name = 'name-denylist.runner-no-base'; Body = {
        Assert-Guard (Invoke-NameDenylistGate (New-NameDenylistRepo "clean change`n" -WithoutBase)) @(2) 'Basis origin/main fehlt'
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
    # i18n R11 (Welle A2): the German pair only. The fixture value is a PHP
    # double-quoted string with \u{...} escapes, so this file stays ASCII and the
    # fixture bytes do not depend on the encoding Edit-Fixture writes.
    @{ Name = 'lang-audit.quote-mix'; Body = {
        $fx = New-Fixture @('Docker/WebAPI/lang')
        Edit-Fixture $fx 'Docker/WebAPI/lang/de/deploy.php' '];' "    'zz_guard_quote' => `"Reiter \u{201E}Probe\`" offen`",`n];"
        Edit-Fixture $fx 'Docker/WebAPI/lang/en/deploy.php' '];' "    'zz_guard_quote' => 'Tab Probe open',`n];"
        Assert-Guard (Invoke-GuardPhp 'lang-audit.php' @('--ci') $fx) @(1) '\[lang-audit\.quote-mix\].*zz_guard_quote'
    } }
    @{ Name = 'lang-audit.quote-unclosed'; Body = {
        $fx = New-Fixture @('Docker/WebAPI/lang')
        Edit-Fixture $fx 'Docker/WebAPI/lang/de/deploy.php' '];' "    'zz_guard_open' => `"Reiter \u{201E}Probe offen`",`n];"
        Edit-Fixture $fx 'Docker/WebAPI/lang/en/deploy.php' '];' "    'zz_guard_open' => 'Tab Probe open',`n];"
        Assert-Guard (Invoke-GuardPhp 'lang-audit.php' @('--ci') $fx) @(1) '\[lang-audit\.quote-mix\].*zz_guard_open.*never closed'
    } }
    # i18n R12: English quotes with the straight pair. Same ASCII-only fixture
    # technique as above for the typographic marks.
    @{ Name = 'lang-audit.quote-en-typographic'; Body = {
        $fx = New-Fixture @('Docker/WebAPI/lang')
        Edit-Fixture $fx 'Docker/WebAPI/lang/de/deploy.php' '];' "    'zz_guard_en_quote' => 'Reiter Probe offen',`n];"
        Edit-Fixture $fx 'Docker/WebAPI/lang/en/deploy.php' '];' "    'zz_guard_en_quote' => `"Tab \u{201C}Probe\u{201D} open`",`n];"
        Assert-Guard (Invoke-GuardPhp 'lang-audit.php' @('--ci') $fx) @(1) '\[lang-audit\.quote-mix\].*zz_guard_en_quote en: typographic quote mark U\+201C'
    } }
    @{ Name = 'lang-audit.quote-en-unclosed'; Body = {
        $fx = New-Fixture @('Docker/WebAPI/lang')
        Edit-Fixture $fx 'Docker/WebAPI/lang/de/deploy.php' '];' "    'zz_guard_en_open' => 'Reiter Probe offen',`n];"
        Edit-Fixture $fx 'Docker/WebAPI/lang/en/deploy.php' '];' "    'zz_guard_en_open' => 'Tab `"Probe open',`n];"
        Assert-Guard (Invoke-GuardPhp 'lang-audit.php' @('--ci') $fx) @(1) '\[lang-audit\.quote-mix\].*zz_guard_en_open en: straight quote " is never closed'
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

    # --- Etappe 10C: Audit-SSoT ---------------------------------------------
    # Der Guard beweist, dass persistierte Ereignisse genau einen Owner haben.
    # Jeder Negativfall mutiert genau eine Regel; der Zero-Match-Fall ist der
    # wichtigste, weil jede andere Regel eine Suche nach etwas Schlechtem ist
    # und eine Suche ohne Treffer wie ein sauberes Repository aussieht.
    @{ Name = 'audit-contract.green'; Body = {
        Assert-Guard (Invoke-GuardPhp 'check-audit-contract.php' @('--ci')) @(0)
    } }
    @{ Name = 'audit-contract.free-producer'; Body = {
        # Die alte Freitextform: Kategorie plus Satz statt Ereigniscode.
        $fx = New-Fixture $auditFixtureFiles
        Add-FixtureFile $fx 'Docker/WebAPI/lib/zz_guard_producer.php' @'
<?php
declare(strict_types=1);
function zz_guard_producer(mysqli $db): void
{
    audit($db, VIRTUSPHERE_LOG_CATEGORY_SYSTEM, 'something happened', null, 'cli');
}
'@
        Assert-Guard (Invoke-GuardPhp 'check-audit-contract.php' @('--ci') $fx) @(1) '\[audit-contract\.free-producer\].*zz_guard_producer'
    } }
    @{ Name = 'audit-contract.unknown-event'; Body = {
        # Eine Konstante, die aussieht wie ein Ereignis, aber keines ist.
        $fx = New-Fixture $auditFixtureFiles
        Add-FixtureFile $fx 'Docker/WebAPI/lib/zz_guard_unknown.php' @'
<?php
declare(strict_types=1);
function zz_guard_unknown(mysqli $db): void
{
    audit_event($db, VIRTUSPHERE_AUDIT_EVENT_ZZ_NOT_REGISTERED, 'user', 1, 'success', []);
}
'@
        Assert-Guard (Invoke-GuardPhp 'check-audit-contract.php' @('--ci') $fx) @(1) '\[audit-contract\.unknown-event\].*ZZ_NOT_REGISTERED'
    } }
    @{ Name = 'audit-contract.registry-bypass'; Body = {
        # Ein zweiter Schreibpfad in die Audittabelle erzeugt Zeilen, die keine
        # Registry validiert hat, und jeder Leser zaehlt sie trotzdem mit.
        $fx = New-Fixture $auditFixtureFiles
        Add-FixtureFile $fx 'Docker/WebAPI/lib/migrate.php' @'
<?php
declare(strict_types=1);
function zz_guard_bypass(mysqli $db): void
{
    $db->query("INSERT INTO deploy_logs (ip, category, log_message) VALUES ('1.2.3.4', 'system', 'direct')");
}
'@
        Assert-Guard (Invoke-GuardPhp 'check-audit-contract.php' @('--ci') $fx) @(1) '\[audit-contract\.registry-bypass\].*migrate\.php'
    } }
    @{ Name = 'audit-contract.forbidden-field'; Body = {
        $fx = New-Fixture $auditFixtureFiles
        Add-FixtureFile $fx 'Docker/WebAPI/lib/zz_guard_field.php' @'
<?php
declare(strict_types=1);
function zz_guard_field(mysqli $db): void
{
    audit_event($db, VIRTUSPHERE_AUDIT_EVENT_USER_ROLE_CHANGED, 'user', 1, 'success', ['role' => 'admin', 'password' => 'hunter2']);
}
'@
        Assert-Guard (Invoke-GuardPhp 'check-audit-contract.php' @('--ci') $fx) @(1) '\[audit-contract\.forbidden-field\].*password'
    } }
    @{ Name = 'audit-contract.dead-sink'; Body = {
        # addLog() war nie aufgerufen und einen Aufruf davon entfernt, einen
        # Auth-Token in eine Tabelle mit 365-Tage-Fenster zu schreiben.
        $fx = New-Fixture $auditFixtureFiles
        Add-FixtureFile $fx 'Docker/WebAPI/lib/zz_guard_sink.php' @'
<?php
declare(strict_types=1);
function zz_guard_sink(mysqli $db): void
{
    addLog($db, 'system', 'back again');
}
'@
        Assert-Guard (Invoke-GuardPhp 'check-audit-contract.php' @('--ci') $fx) @(1) '\[audit-contract\.dead-sink\].*addLog'
    } }
    @{ Name = 'audit-contract.token-sink'; Body = {
        # Die Signatur ist die Grenze, nicht die Aufrufstellen: solange der
        # Parameter existiert, ist "kein Aufrufer uebergibt einen Token" eine
        # Aussage ueber heute.
        $fx = New-Fixture $auditFixtureFiles
        Add-FixtureFile $fx 'Docker/WebAPI/lib/zz_guard_token.php' @'
<?php
declare(strict_types=1);
function zz_guard_audit_line(mysqli $db, string $token, string $message): void
{
    error_log($message);
}
'@
        Assert-Guard (Invoke-GuardPhp 'check-audit-contract.php' @('--ci') $fx) @(1) '\[audit-contract\.token-sink\].*zz_guard_audit_line'
    } }
    @{ Name = 'audit-contract.unredacted-sink'; Body = {
        $fx = New-Fixture $auditFixtureFiles
        Add-FixtureFile $fx 'Docker/WebAPI/lib/zz_guard_unredacted.php' "<?php`ndeclare(strict_types=1);`nfunction zz_guard_unredacted(Throwable `$exception): void`n{`n    error_log('provider failed: ' . `$exception->getMessage());`n}`n"
        Assert-Guard (Invoke-GuardPhp 'check-audit-contract.php' @('--ci') $fx) @(1) '\[audit-contract\.unredacted-sink\].*zz_guard_unredacted'
    } }
    @{ Name = 'audit-contract.sentinel'; Body = {
        $fx = New-Fixture $auditFixtureFiles
        Add-FixtureFile $fx 'Docker/WebAPI/lib/zz_guard_sentinel.php' @'
<?php
declare(strict_types=1);
function zz_guard_sentinel(): void
{
    error_log('probe Authorization: Bearer eyJhbGciOiJIUzI1NiJ9zzGuardSentinel');
}
'@
        Assert-Guard (Invoke-GuardPhp 'check-audit-contract.php' @('--ci') $fx) @(1) '\[audit-contract\.sentinel\].*zz_guard_sentinel'
    } }
    @{ Name = 'audit-contract.unused-event'; Body = {
        # Ein Code, den kein Producer schreibt, ist ein Wert, den ein Filter
        # anbietet und den keine Zeile je traegt.
        $fx = New-Fixture $auditFixtureFiles
        Edit-Fixture $fx 'Docker/WebAPI/lib/audit_event_definitions.php' `
            "const VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGOUT = 'auth.logout';" `
            "const VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGOUT = 'auth.logout';`nconst VIRTUSPHERE_AUDIT_EVENT_ZZ_GUARD_ORPHAN = 'zz.guard_orphan';"
        Assert-Guard (Invoke-GuardPhp 'check-audit-contract.php' @('--ci') $fx) @(1) '\[audit-contract\.unused-event\].*ZZ_GUARD_ORPHAN'
    } }
    # Historische Ereignisse (VIRTUSPHERE_AUDIT_EVENTS_HISTORICAL) bleiben lesbar,
    # duerfen aber nie wieder geschrieben werden. Die Liste ist die einzige
    # Ausnahme von unused-event. Der Ereignisname ist synthetisch, damit der Fall
    # nicht davon abhaengt, welche echten Ereignisse historisch sind.
    @{ Name = 'audit-contract.historical-produced'; Body = {
        $fx = New-Fixture $auditFixtureFiles
        Edit-Fixture $fx 'Docker/WebAPI/lib/audit_event_definitions.php' `
            "const VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGOUT = 'auth.logout';" `
            "const VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGOUT = 'auth.logout';`nconst VIRTUSPHERE_AUDIT_EVENT_ZZ_GUARD_RETIRED = 'zz.guard_retired';"
        Edit-Fixture $fx 'Docker/WebAPI/lib/audit_event_definitions.php' `
            'const VIRTUSPHERE_AUDIT_EVENTS_HISTORICAL = [' `
            "const VIRTUSPHERE_AUDIT_EVENTS_HISTORICAL = [`n    VIRTUSPHERE_AUDIT_EVENT_ZZ_GUARD_RETIRED,"
        Add-FixtureFile $fx 'Docker/WebAPI/lib/zz_guard_historical.php' @'
<?php
declare(strict_types=1);
function zz_guard_historical(mysqli $db): void
{
    audit_event($db, VIRTUSPHERE_AUDIT_EVENT_ZZ_GUARD_RETIRED, 'user', 1, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, []);
}
'@
        Assert-Guard (Invoke-GuardPhp 'check-audit-contract.php' @('--ci') $fx) @(1) '\[audit-contract\.historical-produced\].*ZZ_GUARD_RETIRED'
    } }
    @{ Name = 'audit-contract.historical-exempt'; Body = {
        # Dieselbe synthetische Registrierung wie historical-produced, aber ohne
        # Producer: der Listeneintrag allein befreit das Ereignis von unused-event.
        $fx = New-Fixture $auditFixtureFiles
        Edit-Fixture $fx 'Docker/WebAPI/lib/audit_event_definitions.php' `
            "const VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGOUT = 'auth.logout';" `
            "const VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGOUT = 'auth.logout';`nconst VIRTUSPHERE_AUDIT_EVENT_ZZ_GUARD_RETIRED = 'zz.guard_retired';"
        Edit-Fixture $fx 'Docker/WebAPI/lib/audit_event_definitions.php' `
            'const VIRTUSPHERE_AUDIT_EVENTS_HISTORICAL = [' `
            "const VIRTUSPHERE_AUDIT_EVENTS_HISTORICAL = [`n    VIRTUSPHERE_AUDIT_EVENT_ZZ_GUARD_RETIRED,"
        Assert-Guard (Invoke-GuardPhp 'check-audit-contract.php' @('--ci') $fx) @(0)
    } }
    @{ Name = 'audit-contract.zero-match-registry'; Body = {
        # Eine leere Registry darf nie als "alle Producer sind sauber" gelten.
        $fx = New-Fixture $auditFixtureFiles
        Add-FixtureFile $fx 'Docker/WebAPI/lib/audit_event_definitions.php' "<?php`ndeclare(strict_types=1);`n"
        Assert-Guard (Invoke-GuardPhp 'check-audit-contract.php' @('--ci') $fx) @(1) '\[audit-contract\.zero-match\]'
    } }
    @{ Name = 'audit-contract.zero-match-producers'; Body = {
        # Registry vorhanden, aber kein einziger Producer: die Aufrufform wurde
        # umgeschrieben und der Scan sieht nichts mehr.
        $fx = New-Fixture @()
        Add-FixtureFile $fx 'Docker/WebAPI/lib/audit_event_definitions.php' "<?php`ndeclare(strict_types=1);`nconst VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGOUT = 'auth.logout';`n"
        Add-FixtureFile $fx 'Docker/WebAPI/lib/zz_guard_empty.php' "<?php`ndeclare(strict_types=1);`n"
        Assert-Guard (Invoke-GuardPhp 'check-audit-contract.php' @('--ci') $fx) @(1) '\[audit-contract\.zero-match\]'
    } }
    @{ Name = 'audit-contract.zero-match-files'; Body = {
        # Leerer Scope: kein Producer gefunden heisst nicht, dass keiner falsch ist.
        $fx = New-Fixture
        Add-FixtureFile $fx 'README.md' "placeholder`n"
        Assert-Guard (Invoke-GuardPhp 'check-audit-contract.php' @('--ci') $fx) @(1) '\[audit-contract\.zero-match\]'
    } }
    @{ Name = 'audit-contract.new-module-allowed'; Body = {
        # Positivrichtung: ein neues Modul mit einem korrekten Producer bleibt
        # still. Ein Guard, der nur je feuert, ist einer, neben den niemand
        # eine Datei legen kann.
        $fx = New-Fixture $auditFixtureFiles
        Add-FixtureFile $fx 'Docker/WebAPI/lib/zz_guard_ok.php' @'
<?php
declare(strict_types=1);
function zz_guard_ok(mysqli $db): void
{
    audit_event($db, VIRTUSPHERE_AUDIT_EVENT_USER_ROLE_CHANGED, 'user', 1, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['role' => 'admin']);
}
'@
        Assert-Guard (Invoke-GuardPhp 'check-audit-contract.php' @('--ci') $fx) @(0)
    } }

    @{ Name = 'file-size.green'; Body = {
        Assert-Guard (Invoke-GuardPhp 'check-file-size.php' @('--ci')) @(0)
    } }
    @{ Name = 'file-size.small-file-allowed'; Body = {
        # The positive direction: a new module inside the budget is silent. A
        # guard that only ever fires is one nobody can add a file next to.
        $fx = New-Fixture $fileSizeFixtureFiles
        Add-FixtureFile $fx 'Docker/WebAPI/lib/zz_guard_small.php' ("<?php`n" + ("// small`n" * 20))
        Assert-Guard (Invoke-GuardPhp 'check-file-size.php' @('--ci') $fx) @(0)
    } }
    @{ Name = 'file-size.oversize'; Body = {
        $fx = New-Fixture $fileSizeFixtureFiles
        Add-FixtureFile $fx 'Docker/WebAPI/lib/zz_guard_big.php' ("<?php`n" + ("// filler`n" * 420))
        Assert-Guard (Invoke-GuardPhp 'check-file-size.php' @('--ci') $fx) @(1) '\[file-size\.oversize\].*zz_guard_big'
    } }
    @{ Name = 'file-size.grown'; Body = {
        # A recorded exception is a ceiling: the file may shrink, never grow.
        $fx = New-Fixture $fileSizeFixtureFiles
        Edit-Fixture $fx 'Docker/WebAPI/lib/migrate.php' 'declare(strict_types=1);' "declare(strict_types=1);`n// guard probe`n// guard probe"
        Assert-Guard (Invoke-GuardPhp 'check-file-size.php' @('--ci') $fx) @(1) '\[file-size\.grown\].*migrate\.php'
    } }
    @{ Name = 'file-size.stale-allowance'; Body = {
        # An exception that outlives its split silently re-opens the budget.
        $fx = New-Fixture $fileSizeFixtureFiles
        Add-FixtureFile $fx 'Docker/WebAPI/lib/constants.php' "<?php`ndeclare(strict_types=1);`n"
        Assert-Guard (Invoke-GuardPhp 'check-file-size.php' @('--ci') $fx) @(1) '\[file-size\.stale\].*constants\.php'
    } }
    @{ Name = 'file-size.zero-match'; Body = {
        # An empty scope must never read as "no file is too large".
        $fx = New-Fixture
        Add-FixtureFile $fx 'README.md' "placeholder`n"
        Assert-Guard (Invoke-GuardPhp 'check-file-size.php' @('--ci') $fx) @(1) '\[file-size\.zero-match\]'
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
    @{ Name = 'runner.portal-assets-vendor-free.green'; Body = {
        Assert-Guard (Invoke-RunnerGate $repoRoot 'portal-assets-vendor-free') @(0)
    } }
    @{ Name = 'runner.portal-assets-vendor-free.zero-match'; Body = {
        $fx = New-Fixture
        Add-FixtureFile $fx 'Docker/WebAPI/portal/assets/img/logo.png' 'x'
        $result = Invoke-RunnerGate $fx 'portal-assets-vendor-free'
        Assert-Guard $result @(2) '\[assets\.zero-match\]'
    } }
)
# Ein Fall je Merkmal fremden Codes (DG1): die echte Asset-Kopie plus genau
# eine Fremddatei. Jede muss mit ihrer eigenen Diagnose-ID rot werden; ein
# gruener Lauf hiesse, dass der Guard dieses Merkmal nicht mehr sieht.
$assetMutations = @(
    @{ Case = 'minified-name'; Path = 'lib/chart.min.js'; Content = "var a=1;`n"; Id = 'minified' }
    @{ Case = 'minified-line'; Path = 'widget.js'; Content = ('var x="' + ('a' * 1200) + '";' + "`n"); Id = 'minified' }
    @{ Case = 'license-banner'; Path = 'widget.js'; Content = "/*! Widget v1.2.3 | (c) Example Authors | MIT License */`nvar w=1;`n"; Id = 'license-banner' }
    @{ Case = 'source-map'; Path = 'widget.js'; Content = "var w=1;`n//# sourceMappingURL=widget.js.map`n"; Id = 'source-map' }
    @{ Case = 'bundle'; Path = 'widget.js'; Content = "(function(g,f){typeof exports === 'object' && typeof module !== 'undefined' ? module.exports = f() : g.W = f();})(this,function(){return 1;});`n"; Id = 'bundle' }
    @{ Case = 'vendor-dir'; Path = 'vendor/helper.js'; Content = "var h=1;`n"; Id = 'vendor-dir' }
    @{ Case = 'font'; Path = 'css/fonts/inter.woff2'; Content = 'x'; Id = 'font' }
    @{ Case = 'external-url'; Path = 'css/theme-extra.css'; Content = "@import url(`"https://cdn.example.invalid/x.css`");`n"; Id = 'external-url' }
    @{ Case = 'unknown-type'; Path = 'engine.wasm'; Content = 'x'; Id = 'unknown-type' }
)
foreach ($mutation in $assetMutations) {
    $cases += @{ Name = ('runner.portal-assets-vendor-free.' + $mutation.Case); Mutation = $mutation; Body = {
        # Der Lauf ruft jeden Body in seiner Schleife ueber $case auf; $mutation
        # haette dann laengst den Wert des letzten Eintrags.
        $m = $case.Mutation
        $fx = New-Fixture @('Docker/WebAPI/portal/assets')
        Add-FixtureFile $fx ('Docker/WebAPI/portal/assets/' + $m.Path) $m.Content
        Assert-Guard (Invoke-RunnerGate $fx 'portal-assets-vendor-free') @(1) ('\[assets\.' + $m.Id + '\]')
    } }
}
$cases += @(
    # dependency-osv ohne Netz: Faelle ohne eine einzige Lockdatei stellen keine
    # Anfrage, beweisen aber die Klassen, auf die es ankommt. Eine leere Wurzel ist
    # nie pass, eine fehlende Ausnahmedatei auch nicht, und ein Manifest ohne
    # Lock ist ein Befund, der den Infrastrukturfehler dominiert.
    @{ Name = 'runner.dependency-osv.zero-match'; Body = {
        $fx = New-Fixture @('.dependency-osv-ignore.yaml')
        Assert-Guard (Invoke-RunnerGate $fx 'dependency-osv') @(2) '\[osv\.zero-match\]'
    } }
    @{ Name = 'runner.dependency-osv.ignore-missing'; Body = {
        $fx = New-Fixture
        Assert-Guard (Invoke-RunnerGate $fx 'dependency-osv') @(2) '\[osv\.ignore-missing\]'
    } }
    @{ Name = 'runner.dependency-osv.ignore-invalid'; Body = {
        $fx = New-Fixture @('.dependency-osv-ignore.yaml')
        Edit-Fixture $fx '.dependency-osv-ignore.yaml' 'vulnerabilities: []' "vulnerabilities:`n  - id: GHSA-xxxx-xxxx-xxxx`n    statement: ohne Verantwortlichen`n    expired_at: 2099-12-31"
        Assert-Guard (Invoke-RunnerGate $fx 'dependency-osv') @(2) '\[osv\.ignore-invalid\].*Owner'
    } }
    @{ Name = 'runner.dependency-osv.unlocked-manifest'; Body = {
        $fx = New-Fixture @('.dependency-osv-ignore.yaml', 'Docker/WebAPI/composer.json')
        Assert-Guard (Invoke-RunnerGate $fx 'dependency-osv') @(1) '\[osv\.unlocked-manifest\] Docker/WebAPI/composer\.json'
    } }
    @{ Name = 'runner.fail-dominates-infra'; Body = {
        $fx = New-Fixture
        Add-FixtureFile $fx 'broken.ps1' "function Broken {`n"
        # ansible-lint ist ohne QA-Image infrastructure_error; der Parserfehler
        # muss trotzdem Exit 1 dominieren (ADR-0031 Exitcode-Praezedenz).
        Assert-Guard (Invoke-RunnerGate $fx 'powershell-syntax,ansible-lint') @(1)
    } }
    @{ Name = 'runner.ansible-module-contract.control'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture $moduleContractFixtureFiles
        Assert-Guard (Invoke-RunnerGate $fx 'ansible-module-contract') @(0) -InfraOnExit2
    } }
    @{ Name = 'runner.ansible-module-contract.missing-library'; Body = {
        # Der Originalbefund war: ohne `requests` scheitert JEDES benutzte
        # community.vmware-Modul beim Import, vor der Argumentpruefung, waehrend
        # ansible-lint --strict gruen bleibt. Bewiesen wird hier die Erkennung -
        # dass diese Meldung den Build bricht - nicht der Zustand des Images: den
        # beweist der gruene Gate-Lauf gegen das echte Repo. Die Bibliothek im
        # Image zu entfernen ginge nur ueber einen Image-Build je Fall.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture $moduleContractFixtureFiles
        $probe = [System.IO.File]::ReadAllText((Join-Path $fx 'Docker/qa-ansible/module-probe.yml'))
        Add-FixtureFile $fx 'Docker/qa-ansible/module-probe.yml' ($probe + @'

    - name: die Meldung, die das Gate faengt
      ansible.builtin.fail:
        msg: Failed to import the required Python library (requests) on probe Python /usr/bin/python3
      ignore_errors: true
'@)
        $gate = Invoke-RunnerGate $fx 'ansible-module-contract'
        $verdict = Assert-Guard $gate @(1) '\[ansible-module-contract\.missing-library\]' -InfraOnExit2
        # In Lanes war dieser Fall mehrfach unbewiesen, ohne dass zu sehen war,
        # woran der Gate stattdessen scheiterte. Die Erwartung bleibt; ein
        # unbewiesener Lauf nennt jetzt das Ende der Gate-Ausgabe.
        if ($verdict.Status -eq 'unproven') {
            $verdict.Detail = $verdict.Detail + ' | Gate-Ausgabe: ' + ((@($gate.Output) | Select-Object -Last 12) -join ' / ')
        }
        return $verdict
    } }
    @{ Name = 'runner.ansible-module-contract.argument-spec'; Body = {
        # Gegen die ECHTE Collection: ein Argumentname, den die gepinnte Version
        # nicht kennt, muss auffallen. Genau diese Klasse hat einmal 13
        # Portgruppen als 0 gemeldet, weil die Aufgabe unter ignore_errors lief.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture $moduleContractFixtureFiles
        Edit-Fixture $fx 'Docker/qa-ansible/module-probe.yml' 'esxi_hostname: virtusphere-probe-host' 'esxi_hostnaem: virtusphere-probe-host'
        Assert-Guard (Invoke-RunnerGate $fx 'ansible-module-contract') @(1) '\[ansible-module-contract\.argument-spec\]' -InfraOnExit2
    } }
    @{ Name = 'runner.ansible-module-contract.deprecation-unrecorded'; Body = {
        # Ein upstream neu als deprecated markiertes Modul darf nicht still
        # durchlaufen: die Frist muss im Repo stehen, sonst faellt sie beim Kunden auf.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture $moduleContractFixtureFiles
        Add-FixtureFile $fx 'Docker/qa-ansible/module-deprecations.txt' "# leer`n"
        Assert-Guard (Invoke-RunnerGate $fx 'ansible-module-contract') @(1) '\[ansible-module-contract\.deprecation-unrecorded\]' -InfraOnExit2
    } }
    @{ Name = 'runner.ansible-module-contract.probe-incomplete'; Body = {
        # Der realistische Weg, wie der Vertrag stillschweigend schrumpft: jemand
        # benutzt ein neues Modul in einem Playbook. Ohne diese Richtung meldet die
        # Probe weiter gruen fuer die zehn, die sie kennt.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture $moduleContractFixtureFiles
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
        Assert-Guard (Invoke-RunnerGate $fx 'ansible-module-contract') @(1) '\[ansible-module-contract\.probe-incomplete\]' -InfraOnExit2
    } }
    @{ Name = 'runner.ansible-module-contract.probe-stale'; Body = {
        # Gegenrichtung: eine Probe-Zeile fuer ein Modul, das kein Playbook mehr
        # benutzt, bindet den Vertrag an etwas, das uns nicht mehr betrifft.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture $moduleContractFixtureFiles
        Edit-Fixture $fx 'Docker/qa-ansible/module-probe.yml' 'community.vmware.vmware_about_info' 'community.vmware.vmware_cluster_info'
        Assert-Guard (Invoke-RunnerGate $fx 'ansible-module-contract') @(1) '\[ansible-module-contract\.probe-stale\]' -InfraOnExit2
    } }
    @{ Name = 'runner.ansible-module-contract.zero-match'; Body = {
        # Eine Ableitung, die nichts findet, macht jede Pruefung darunter
        # dauerhaft und still gruen. Sie muss ein Fehler sein.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-Fixture $moduleContractFixtureFiles
        Get-ChildItem -LiteralPath (Join-Path $fx 'Ansible') -Filter '*.yml' | Where-Object { $_.Name -ne 'requirements.yml' } | ForEach-Object {
            Add-FixtureFile $fx ('Ansible/' + $_.Name) "---
[]
"
        }
        Assert-Guard (Invoke-RunnerGate $fx 'ansible-module-contract') @(1) '\[ansible-module-contract\.zero-match\]' -InfraOnExit2
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
        # oberhalb der -S-warning-Schwelle des Gates. Dazu ein if ohne fi: die
        # zweite Art Parse-Fehler, fuer die bis C2 der Fall sh-posix.parse-all
        # jedes Skript mit sh -n las. Beide Dateien muessen gemeldet werden;
        # shellcheck deckt seitdem allein ab, was nicht parsebar ist (R3).
        Add-FixtureFile $fx 'probe.sh' "#!/bin/sh`necho `"unclosed`n"
        Add-FixtureFile $fx 'probe-if.sh' "#!/bin/sh`nif true; then`n  echo open`n"
        $result = Invoke-RunnerGate $fx 'shellcheck'
        $verdict = Assert-Guard $result @(1) -InfraOnExit2
        if ($verdict.Status -ne 'proven') { return $verdict }
        Assert-Guard $result @(1) 'probe-if\.sh'
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

# php-lint-Hook mit einer Payload wie unter Windows (JSON-escapte Backslashes).
function Invoke-PhpLintHook {
    param([string]$File)
    $jsonPath = ($File -replace '/', '\') -replace '\\', '\\'
    return Invoke-HookWithPayload '.claude/hooks/php-lint.sh' ('{"tool_input":{"file_path":"' + $jsonPath + '"}}')
}
# Der Hook lintet mit Host-PHP oder im Tooling-Image; ohne beides kann kein
# Blockfall bewiesen werden (infra, nie ein stiller Gruenfall).
$phpLintToolAvailable = (Test-Command 'php') -or $phpImageAvailable

# Hook-Payload-Faelle: ungueltige Payloads duerfen weder crashen noch blocken
# (Exit 0); eine Payload auf eine kaputte PHP-Datei muss blocken (Exit 2).
# Der Hook lintet genau die editierte Datei (Host-PHP oder Tooling-Image aus
# tool-lock.json), nie die gleichnamige Datei im laufenden App-Container.
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
        if (-not $phpLintToolAvailable) { return @{ Status = 'infra'; Detail = 'weder Host-PHP noch PHP-Tooling-Image' } }
        $fx = New-Fixture
        Add-FixtureFile $fx 'broken.php' "<?php`nif (true {`n"
        $payload = '{"tool_input":{"file_path":"' + (($fx -replace '\\', '/') + '/broken.php') + '"}}'
        Assert-Guard (Invoke-HookWithPayload '.claude/hooks/php-lint.sh' $payload) @(2) 'BLOCK'
    } }
    @{ Name = 'hooks.php-lint.lints-edited-copy'; Body = {
        # Kaputte Kopie einer Datei, die es im App-Container intakt gibt (wie
        # eine Aenderung im Worktree): geblockt wird die bearbeitete Kopie.
        if (-not $phpLintToolAvailable) { return @{ Status = 'infra'; Detail = 'weder Host-PHP noch PHP-Tooling-Image' } }
        $fx = New-Fixture
        Add-FixtureFile $fx 'Docker/WebAPI/lib/ansible.php' "<?php`nif (true {`n"
        Assert-Guard (Invoke-PhpLintHook (Join-Path $fx 'Docker/WebAPI/lib/ansible.php')) @(2) 'BLOCK'
    } }
    @{ Name = 'hooks.php-lint.passes-new-valid-file'; Body = {
        # Neue gueltige Datei, die der App-Container nicht kennt (neue Datei im
        # Worktree): kein Block.
        if (-not $phpLintToolAvailable) { return @{ Status = 'infra'; Detail = 'weder Host-PHP noch PHP-Tooling-Image' } }
        $fx = New-Fixture
        Add-FixtureFile $fx 'Docker/WebAPI/guard-new-file-probe.php' "<?php`necho 1;`n"
        Assert-Guard (Invoke-PhpLintHook (Join-Path $fx 'Docker/WebAPI/guard-new-file-probe.php')) @(0)
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
# expliziten Interpolationen aufloest.
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
        'Docker/php/Dockerfile', 'Docker/nginx/Dockerfile',
        'Docker/mysql/Dockerfile', 'Docker/phpmyadmin/Dockerfile',
        'Docker/qa-ansible/Dockerfile', 'Docker/nginx/default.conf')
    Copy-Item -Force -Path (Join-Path $fx '.env.example') -Destination (Join-Path $fx '.env')
    return $fx
}
# Rollout-Pruefschritt C-07: belegt, dass jedes Netz der aufgeloesten Config ein
# gepinntes Subnetz hat. Die Fixture-Override pinnt mit Dokumentationsbereichen
# (RFC 5737); der Lauf darf keinen davon ausgeben.
function Invoke-NetworkPinsGuard {
    param([string]$FixtureRoot)
    return Invoke-GuardShell (Join-Path $scriptDir 'check-compose-network-pins.sh') @() $FixtureRoot
}
function Get-NetworkPinsOverride {
    param([string[]]$Networks)
    $lines = @('networks:')
    $subnets = @{ 'default' = '192.0.2.0/24'; 'front' = '198.51.100.0/24' }
    foreach ($net in $Networks) {
        $lines += ('  {0}:' -f $net)
        $lines += '    ipam:'
        $lines += '      config:'
        $lines += ('        - subnet: {0}' -f $subnets[$net])
    }
    return (($lines -join "`n") + "`n")
}
function Assert-NoSubnetEcho {
    param($Result)
    $joined = @($Result.Output) -join "`n"
    if ($joined -match '192\.0\.2\.|198\.51\.100\.') {
        return @{ Status = 'unproven'; Detail = 'Pruefschritt gibt ein Subnetz aus' }
    }
    return $null
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
        Edit-Fixture $fx 'docker-compose.yml' '    logging: *container-logging' "    logging: *container-logging`n    env_file:`n      - .env"
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.env-scope\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.php-root-secret-drift'; Body = {
        # App-Runtime und Worker duerfen das nur fuer MySQL/Hostwerkzeuge
        # bestimmte Rootsecret auch dann nicht erhalten, wenn es in .env steht.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' '      WEB_HTTPS_PORT: ${WEB_HTTPS_PORT}' "      WEB_HTTPS_PORT: `${WEB_HTTPS_PORT}`n      MYSQL_ROOT_PASSWORD: `${MYSQL_ROOT_PASSWORD}"
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
    @{ Name = 'compose-hardening.logging-bound-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' '    max-file: "5"' '    max-file: "50"'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.logging\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.mysql-binlog-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' '    command: ["mysqld", "--skip-log-bin"]' '    command: ["mysqld"]'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.mysql-binlog\]' -InfraOnExit2
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
    @{ Name = 'compose-hardening.php-kill-missing'; Body = {
        # The root FPM master must signal its uid-33 children during shutdown.
        # Removing KILL reproduces the measured SIGQUIT timeout / exit 137.
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' '      - KILL' '      # required FPM signal capability removed'
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
    @{ Name = 'compose-hardening.built-image-tag-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' 'image: mysql:8.4-virtusphere' 'image: mysql:8.4-untrusted'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.built-image-tag\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.built-image-context-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' 'context: ./Docker/mysql' 'context: ./Docker/php'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.built-image-context\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.built-image-context-zero-match'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' '      context: ./Docker/mysql' '      x-context-disabled: ./Docker/mysql'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.built-image-context\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.php-runtime-target-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' '    target: runtime' '    target: tooling'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.built-image-target\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.php-worker-build-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' '  deploy-worker:' ("  deploy-worker:`n    build:`n      context: ./Docker/php`n      target: runtime")
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.php-worker-build\]' -InfraOnExit2
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
    # Netztrennung (C-07): jede Lockerung hat ihre eigene ID.
    @{ Name = 'compose-hardening.network-webserver-backend-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' "    networks:`n      - front`n    depends_on:" "    networks:`n      - front`n      - default`n    depends_on:"
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.network-edge\] webserver' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.network-php-front-missing'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' "    networks:`n      - default`n      - front`n" "    networks:`n      - default`n"
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.network-upstream\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.network-front-member-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' '    command: ["mysqld", "--skip-log-bin"]' ("    command: [`"mysqld`", `"--skip-log-bin`"]`n    networks:`n      - default`n      - front")
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.network-front\] mysql' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.network-mode-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' '    command: ["php", "/var/www/html/lib/maintenance_worker.php", "--loop", "--sleep=15"]' ("    command: [`"php`", `"/var/www/html/lib/maintenance_worker.php`", `"--loop`", `"--sleep=15`"]`n    network_mode: host")
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.network-mode\] maintenance-worker' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.network-unknown-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' "networks:`n  front: {}" "networks:`n  front: {}`n  extra: {}"
        Edit-Fixture $fx 'docker-compose.yml' '    command: ["php", "/var/www/html/lib/deploy_worker.php", "--loop", "--sleep=5"]' ("    command: [`"php`", `"/var/www/html/lib/deploy_worker.php`", `"--loop`", `"--sleep=5`"]`n    networks:`n      - default`n      - extra")
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.network-unknown\] deploy-worker' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.network-published-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        # Abgeleitet, nicht gelistet: ein LAN-veroeffentlichter Port an einem
        # Backend-Dienst ist rot, egal welcher Dienst es ist.
        Edit-Fixture $fx 'docker-compose.yml' '      - "127.0.0.1:${PMA_PORT}:80"' '      - "0.0.0.0:${PMA_PORT}:80"'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.network-published\] phpmyadmin' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.network-ipam-in-repo-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'docker-compose.yml' "networks:`n  front: {}" "networks:`n  front:`n    ipam:`n      config:`n        - subnet: 198.51.100.0/24"
        $r = Invoke-ComposeHardeningGuard $fx
        $echo = Assert-NoSubnetEcho $r
        if ($null -ne $echo) { return $echo }
        Assert-Guard $r @(1) '\[compose\.network-ipam\]' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.network-upstream-drift'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'Docker/nginx/default.conf' 'fastcgi_pass php:9000;' 'fastcgi_pass mysql:9000;'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.network-upstream\].*mysql' -InfraOnExit2
    } }
    @{ Name = 'compose-hardening.network-upstream-zero-match'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Edit-Fixture $fx 'Docker/nginx/default.conf' 'fastcgi_pass php:9000;' '# fastcgi_pass entfernt'
        Assert-Guard (Invoke-ComposeHardeningGuard $fx) @(1) '\[compose\.network-upstream\].*Zero-Match' -InfraOnExit2
    } }
    @{ Name = 'network-pins.green'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Add-FixtureFile $fx 'docker-compose.override.yml' (Get-NetworkPinsOverride @('default', 'front'))
        $r = Invoke-NetworkPinsGuard $fx
        $echo = Assert-NoSubnetEcho $r
        if ($null -ne $echo) { return $echo }
        Assert-Guard $r @(0) '\[2/2\] gepinnt=ja front' -InfraOnExit2
    } }
    @{ Name = 'network-pins.front-unpinned'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        # Genau der Rollout-Fehler: die Override pinnt nur das alte default.
        $fx = New-ComposeFixture
        Add-FixtureFile $fx 'docker-compose.override.yml' (Get-NetworkPinsOverride @('default'))
        $r = Invoke-NetworkPinsGuard $fx
        $echo = Assert-NoSubnetEcho $r
        if ($null -ne $echo) { return $echo }
        Assert-Guard $r @(1) 'gepinnt=nein front' -InfraOnExit2
    } }
    @{ Name = 'network-pins.no-override'; Body = {
        if (-not $dockerAvailable) { return @{ Status = 'infra'; Detail = 'docker fehlt' } }
        $fx = New-ComposeFixture
        Assert-Guard (Invoke-NetworkPinsGuard $fx) @(1) '2 von 2 Netzen ohne gepinntes Subnetz' -InfraOnExit2
    } }
)

# Offline-Bundle-Vorbedingungen: --release lehnt einen dirty Worktree hart ab,
# ein nichtleeres Ziel wird nie ueberbaut. Beide Checks stehen VOR jedem
# Docker-Schritt, deshalb kommen die Faelle ohne Images aus; die Fixtures sind
# eigene Mini-Git-Repos, das echte Repo wird nie beruehrt.
function Invoke-BundleScript {
    param([string]$FixtureRoot, [string[]]$ScriptArgs)
    if (-not $shExe) { return $null }
    $prevPath = $env:PATH
    $env:PATH = (Split-Path $shExe -Parent) + [System.IO.Path]::PathSeparator + $env:PATH
    try {
        $scriptPosix = ((Join-Path $FixtureRoot 'scripts/build-offline-bundle.sh') -replace '\\', '/')
        $argLine = @($ScriptArgs | ForEach-Object { "'" + ($_ -replace '\\', '/') + "'" }) -join ' '
        return Invoke-Tool $shExe @('-c', ("sh '" + $scriptPosix + "' " + $argLine))
    } finally {
        $env:PATH = $prevPath
    }
}
function New-BundleFixture {
    param([string[]]$ExtraPaths = @())
    $fx = New-Fixture (@('scripts/build-offline-bundle.sh') + $ExtraPaths)
    foreach ($gitStep in @(
            @('init', '-q'),
            @('config', 'user.email', 'guard@fixture.invalid'),
            @('config', 'user.name', 'guard'),
            @('add', '.'),
            @('commit', '-q', '-m', 'fixture'))) {
        $r = Invoke-Tool 'git' (@('-C', $fx) + $gitStep)
        if ($r.ExitCode -ne 0) { throw ('git ' + ($gitStep -join ' ') + ' im Bundle-Fixture fehlgeschlagen: ' + (@($r.Output) -join ' ')) }
    }
    return $fx
}
$cases += @(
    @{ Name = 'offline-bundle.release-dirty-abort'; Body = {
        $fx = New-BundleFixture
        Add-FixtureFile $fx 'dirty.txt' 'uncommitted'
        Assert-Guard (Invoke-BundleScript $fx @('--release', (Join-Path $fx 'dist/out'))) @(1) '\[bundle\.dirty\]'
    } }
    @{ Name = 'offline-bundle.dirty-warns-without-release'; Body = {
        # Negativrichtung: ohne --release ist dirty nur eine Warnung und der Lauf
        # geht ueber den Check hinaus. Die Fixture laesst tool-lock.json bewusst
        # weg, damit der Lauf deterministisch am naechsten Umgebungs-Halt endet
        # (exit 2), bevor irgendein Docker-Schritt laufen koennte; exit 2 statt 1
        # beweist zugleich, dass es NICHT der [bundle.dirty]-Abbruch war.
        $fx = New-BundleFixture
        Add-FixtureFile $fx 'dirty.txt' 'uncommitted'
        Assert-Guard (Invoke-BundleScript $fx @((Join-Path $fx 'dist/out'))) @(2) 'WARNUNG: Worktree ist nicht sauber'
    } }
    @{ Name = 'offline-bundle.dest-not-empty-abort'; Body = {
        $fx = New-BundleFixture
        Add-FixtureFile $fx 'dist/out/altdatei.txt' 'aus einem frueheren Lauf'
        Assert-Guard (Invoke-BundleScript $fx @((Join-Path $fx 'dist/out'))) @(2) '\[bundle\.dest-not-empty\]'
    } }
)

# Die Closure-Proben laufen schon im Gate phpunit-unit derselben Lane (R1, C2).
# Mit -UnitJUnit liest der Fall dessen JUnit-Bericht statt die beiden Klassen ein
# drittes Mal zu fahren: jede test*-Methode beider Dateien (aus dem Quelltext
# abgeleitet, keine Liste) muss dort stehen und gruen sein, ohne Skip. Ohne
# Bericht (Harness allein gestartet) faehrt der Fall die Klassen selbst.
$closureProbeFiles = @('Docker/WebAPI/tests/Static/PortalClosureAnalysisTest.php', 'Docker/WebAPI/tests/Static/PortalRequireClosureContractTest.php')
function Test-ClosureProbesInJUnit {
    param([string]$JUnitPath)
    try { [xml]$report = [System.IO.File]::ReadAllText($JUnitPath) } catch {
        return @{ Status = 'infra'; Detail = ('JUnit-Bericht nicht lesbar: ' + $JUnitPath) }
    }
    foreach ($relative in $closureProbeFiles) {
        $class = [System.IO.Path]::GetFileNameWithoutExtension($relative)
        $source = [System.IO.File]::ReadAllText((Join-Path $repoRoot $relative))
        $expected = @([regex]::Matches($source, 'public function (test\w+)\s*\(') | ForEach-Object { $_.Groups[1].Value })
        if ($expected.Count -eq 0) { return @{ Status = 'unproven'; Detail = ('keine test-Methode in ' + $relative + ' (Zero-Match)') } }
        $cases = @($report.SelectNodes("//testcase[@class='" + $class + "']"))
        $seen = @($cases | ForEach-Object { ([string]$_.name -split ' with data set ')[0] })
        $missing = @($expected | Where-Object { $seen -notcontains $_ })
        if ($missing.Count -gt 0) { return @{ Status = 'unproven'; Detail = ($class + ': fehlt im JUnit-Bericht von phpunit-unit: ' + ($missing -join ', ')) } }
        $bad = @($cases | Where-Object { $_.SelectSingleNode('failure|error|skipped') } | ForEach-Object { [string]$_.name })
        if ($bad.Count -gt 0) { return @{ Status = 'unproven'; Detail = ($class + ': rot oder uebersprungen: ' + ($bad -join ', ')) } }
    }
    return @{ Status = 'proven'; Detail = '' }
}
$cases += @(
    @{ Name = 'portal-require-closure.probes'; Body = {
        if ($UnitJUnit -ne '') {
            if (-not (Test-Path -LiteralPath $UnitJUnit -PathType Leaf)) { return @{ Status = 'infra'; Detail = ('JUnit-Bericht fehlt: ' + $UnitJUnit) } }
            return Test-ClosureProbesInJUnit $UnitJUnit
        }
        if (-not $phpImageAvailable) { throw 'INFRA: PHP test image unavailable' }
        $result = Invoke-Tool 'docker' @('run', '--rm', '--network', 'none',
            '-v', ($repoRoot + ':/repo'), '-w', '/repo/Docker/WebAPI',
            $phpImage, 'php', 'vendor/bin/phpunit', '--testsuite', 'unit', '--filter',
            'PortalClosureAnalysisTest|PortalRequireClosureContractTest',
            '--fail-on-empty-test-suite', '--fail-on-skipped', '--testdox')
        $proof = Assert-Guard $result @(0) 'Dashboard regression mutation removes its real module edge'
        if ($proof.Status -ne 'proven') { return $proof }
        Assert-Guard $result @(0) 'Zero entrypoints are a contract error'
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

# Round-Robin ueber die Reihenfolge der Auswahl: Fall i (ab 0) gehoert zu Shard
# (i mod N) + 1. Die Familien liegen im Katalog beieinander; so verteilt sich
# auch eine schwere Familie (doc-semantics) auf alle Shards.
$selectedTotal = $selected.Count
if ($shardCount -gt 0) {
    $selected = @(for ($i = 0; $i -lt $selected.Count; $i++) {
            if (($i % $shardCount) -eq ($shardIndex - 1)) { $selected[$i] }
        })
    if ($selected.Count -eq 0) {
        Write-Host ('test-guards.ps1: Shard {0}/{1} ist leer ({2} Fall/Faelle ausgewaehlt)' -f $shardIndex, $shardCount, $selectedTotal) -ForegroundColor Red
        exit 3
    }
}

if ($List) {
    Write-Host ('{0} Guard-Faelle:' -f $selected.Count)
    foreach ($c in $selected) { Write-Host ('  ' + $c.Name) }
    exit 0
}

# Ein Anlegefehler ist Infrastruktur (Exit 2 laut Kopf), kein Fallergebnis.
try {
    $workRoot = New-CheckArtifactDirectory -Root ([System.IO.Path]::GetTempPath()) -Stamp (Get-Date -Format 'yyyyMMdd-HHmmss') -Prefix 'virtusphere-guards-'
} catch {
    Write-Host ('test-guards.ps1: Arbeitsverzeichnis nicht anlegbar: ' + $_.Exception.Message) -ForegroundColor Yellow
    exit 2
}
$proven = 0
$unproven = 0
$infra = 0
$caseNumber = 0
$caseTotal = $selected.Count
$invariant = [System.Globalization.CultureInfo]::InvariantCulture

function Format-GuardSeconds {
    param([System.Diagnostics.Stopwatch]$Watch)
    return $Watch.Elapsed.TotalSeconds.ToString('0.0', $invariant) + 's'
}

# Abschlusszeile eines Laufs; der Elternprozess liest sie aus jedem Shard zurueck.
$summaryPattern = '^Guards: (\d+) proven, (\d+) unproven, (\d+) infra => Exit (\d+)'

# --- Elternprozess: N Shards gleichzeitig ---------------------------------------
$shardWorkers = [Math]::Min($Parallel, $selected.Count)
if ($shardWorkers -gt 1) {
    . (Join-Path (Join-Path (Join-Path $scriptDir 'lib') 'check') 'child-streams.ps1')
    $workers = @()
    $doneCases = 0
    $overall = [System.Diagnostics.Stopwatch]::StartNew()
    try {
        Write-Host ('==> VirtuSphere test-guards.ps1 - {0} Fall/Faelle in {1} Shards' -f $selected.Count, $shardWorkers) -ForegroundColor Cyan
        for ($k = 1; $k -le $shardWorkers; $k++) {
            $argumentLine = '-NoProfile -ExecutionPolicy Bypass -File "' + $PSCommandPath + '" -Shard ' + $k + '/' + $shardWorkers
            if ($Filter) { $argumentLine += ' -Filter "' + ($Filter -join ',') + '"' }
            if ($UnitJUnit -ne '') { $argumentLine += ' -UnitJUnit "' + $UnitJUnit + '"' }
            $shardCases = 0
            for ($i = $k - 1; $i -lt $selected.Count; $i += $shardWorkers) { $shardCases++ }
            Write-Host ('[{0}/{1}] RUN  Shard {0}/{1} ({2} Fall/Faelle)' -f $k, $shardWorkers, $shardCases) -ForegroundColor Cyan
            $worker = Start-StreamedChild -Exe $hostShell -ArgumentLine $argumentLine `
                -OutPath (Join-Path $workRoot ('shard-' + $k + '.out')) -ErrPath (Join-Path $workRoot ('shard-' + $k + '.err'))
            $worker.Index = $k
            $worker.Cases = $shardCases
            $worker.Summary = $null
            $workers += $worker
        }

        # Jede vollstaendige Kindzeile sofort weitergeben; Ergebniszeilen bekommen
        # den Gesamtzaehler, die Abschlusszeile wird fuer die Zusammenfassung gemerkt.
        function Send-ShardLines {
            param($Worker, [switch]$Final)
            foreach ($line in @(Read-StreamedChildLines $Worker -Final:$Final)) {
                if ($line -eq '') { continue }
                $suffix = ''
                if ($line -match '^\[\d+/\d+\] (proven|unproven|infra) ') {
                    $script:doneCases = $script:doneCases + 1
                    $suffix = (' | gesamt {0}/{1}' -f $script:doneCases, $selected.Count)
                }
                if ($line -match $summaryPattern) { $Worker.Summary = $Matches }
                Write-Host ('[Shard {0}/{1}] {2}{3}' -f $Worker.Index, $shardWorkers, $line, $suffix)
            }
        }

        while (@($workers | Where-Object { -not $_.Finished }).Count -gt 0) {
            foreach ($worker in @($workers | Where-Object { -not $_.Finished })) {
                Send-ShardLines $worker
                if (-not (Test-StreamedChildExited $worker)) { continue }
                Send-ShardLines $worker -Final
                foreach ($line in @(Close-StreamedChild $worker)) {
                    Write-Host ('[Shard {0}/{1}] stderr: {2}' -f $worker.Index, $shardWorkers, $line) -ForegroundColor Yellow
                }
                $childExit = $worker.ExitCode
                $summary = $worker.Summary
                $complete = ($null -ne $summary) -and (([int]$summary[1] + [int]$summary[2] + [int]$summary[3]) -eq $worker.Cases)
                if ($complete) {
                    $proven += [int]$summary[1]
                    $unproven += [int]$summary[2]
                    $infra += [int]$summary[3]
                }
                $word = 'proven'
                $color = 'Green'
                if ($childExit -eq 1 -or ($complete -and [int]$summary[2] -gt 0)) {
                    $word = 'unproven'
                    $color = 'Red'
                    if (-not $complete) { $unproven += 1 }
                } elseif ($childExit -ne 0 -or -not $complete -or [int]$summary[3] -gt 0) {
                    $word = 'infra'
                    $color = 'Yellow'
                    if (-not $complete) { $infra += 1 }
                }
                $detail = 'Abschlusszeile fehlt oder unvollstaendig'
                if ($complete) { $detail = ('{0} proven, {1} unproven, {2} infra' -f $summary[1], $summary[2], $summary[3]) }
                Write-Host ('[{0}/{1}] {2} Shard {0}/{1} ({3}) - {4}, Exit {5}' -f $worker.Index, $shardWorkers, $word, (Format-GuardSeconds $worker.Watch), $detail, $childExit) -ForegroundColor $color
            }
            if (@($workers | Where-Object { -not $_.Finished }).Count -gt 0) { Start-Sleep -Milliseconds 250 }
        }
    } finally {
        # Nur bei Abbruch des Elternprozesses wirksam: die eigenen Kinder samt Baum.
        Stop-StreamedChildren $workers
        Remove-Item -Recurse -Force -Path $workRoot -ErrorAction SilentlyContinue
    }
    $overall.Stop()
    $exitCode = 0
    if ($infra -gt 0) { $exitCode = 2 }
    if ($unproven -gt 0) { $exitCode = 1 }
    Write-Host ''
    Write-Host ('Guards: {0} proven, {1} unproven, {2} infra => Exit {3} ({4} Shards, {5})' -f $proven, $unproven, $infra, $exitCode, $shardWorkers, (Format-GuardSeconds $overall)) `
        -ForegroundColor $(if ($exitCode -eq 0) { 'Green' } elseif ($exitCode -eq 1) { 'Red' } else { 'Yellow' })
    exit $exitCode
}

# --- Ein Prozess: Faelle nacheinander ---------------------------------------------
try {
    $header = ('==> VirtuSphere test-guards.ps1 - {0} Fall/Faelle' -f $selected.Count)
    if ($shardCount -gt 0) { $header += (' (Shard {0}/{1} von {2})' -f $shardIndex, $shardCount, $selectedTotal) }
    Write-Host $header -ForegroundColor Cyan
    foreach ($case in $selected) {
        $caseNumber = $caseNumber + 1
        Write-Host ('[{0}/{1}] RUN  {2}' -f $caseNumber, $caseTotal, $case.Name) -ForegroundColor Cyan
        $verdict = $null
        $caseWatch = [System.Diagnostics.Stopwatch]::StartNew()
        try {
            $verdict = & $case.Body
        } catch {
            $verdict = @{ Status = 'infra'; Detail = ('Exception: ' + $_.Exception.Message) }
        }
        $caseWatch.Stop()
        # Die Dauer haengt hinter dem Fallnamen; die Zeilenform davor bleibt die
        # vertraute (ProgressReporting liest sie).
        $seconds = ' (' + (Format-GuardSeconds $caseWatch) + ')'
        switch ($verdict.Status) {
            'proven' {
                $proven = $proven + 1
                Write-Host (('[{0}/{1}] proven   {2}' -f $caseNumber, $caseTotal, $case.Name) + $seconds) -ForegroundColor Green
            }
            'infra' {
                $infra = $infra + 1
                Write-Host (('[{0}/{1}] infra    {2}' -f $caseNumber, $caseTotal, $case.Name) + $seconds + ' - ' + $verdict.Detail) -ForegroundColor Yellow
            }
            default {
                $unproven = $unproven + 1
                Write-Host (('[{0}/{1}] unproven {2}' -f $caseNumber, $caseTotal, $case.Name) + $seconds + ' - ' + $verdict.Detail) -ForegroundColor Red
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
