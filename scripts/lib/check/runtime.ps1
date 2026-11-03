# Dot-sourced check module. Importing defines functions only.

function Get-QaAppEnvFile {
    # Der QA-Owner braucht MYSQL_ROOT_PASSWORD fuer den MySQL-Service und
    # Schemawerkzeuge. PHP-Testprozesse bekommen dagegen eine zur Laufzeit aus
    # derselben SSoT abgeleitete Datei ohne das Rootsecret.
    $target = Join-Path $artifactDir 'qa-app-runtime.env'
    if (Test-Path -LiteralPath $target) { return $target }

    $lines = @(Get-Content -LiteralPath $qaEnvFile | Where-Object {
        $_ -notmatch '^\s*MYSQL_ROOT_PASSWORD='
    })
    if ($lines.Count -eq 0 -or @($lines | Where-Object { $_ -match '^\s*DB_PASS=' }).Count -ne 1) {
        throw 'QA-App-Umgebung konnte nicht eindeutig aus qa.env abgeleitet werden.'
    }
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllLines($target, [string[]]$lines, $utf8NoBom)
    return $target
}

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

# Snapshot and pause only workers carrying the exact throwaway QA Compose
# labels. A similarly named shared/dev container is refused before any stop.
function Get-QaWorkerSnapshot {
    $states = @()
    foreach ($service in @('deploy-worker', 'maintenance-worker')) {
        $container = $qaProject + '-' + $service + '-1'
        $format = '{{json .Config.Labels}}|{{.State.Running}}'
        $probe = Invoke-Tool 'docker' @('inspect', '--format', $format, $container)
        if ($probe.ExitCode -ne 0) {
            return @{ Ok = $false; Detail = ('QA-Worker nicht inspizierbar: ' + $container); Output = $probe.Output }
        }
        $line = ((@($probe.Output) -join '').Trim())
        $separator = $line.LastIndexOf('|')
        $labels = $null
        if ($separator -gt 0) {
            try { $labels = $line.Substring(0, $separator) | ConvertFrom-Json } catch { $labels = $null }
        }
        $runningText = if ($separator -gt 0) { $line.Substring($separator + 1) } else { '' }
        if (($null -eq $labels) -or ($labels.'com.docker.compose.project' -ne $qaProject) -or ($labels.'com.docker.compose.service' -ne $service)) {
            return @{ Ok = $false; Detail = ('Worker-Isolation verweigert: unerwartete Compose-Labels an ' + $container); Output = $probe.Output }
        }
        $states += @{ Service = $service; Container = $container; Running = ($runningText -eq 'true') }
    }
    return @{ Ok = $true; States = $states; Output = @() }
}

function Get-QaActiveJobCount {
    $database = Get-QaEnvValue 'DB_NAME'
    $rootPassword = Get-QaEnvValue 'MYSQL_ROOT_PASSWORD'
    if ((-not $database) -or (-not $rootPassword)) { return $null }
    $query = "SELECT COUNT(*) FROM deploy_jobs WHERE status IN ('queued','running','cancelling')"
    $probe = Invoke-Tool 'docker' @('exec', '-e', ('MYSQL_PWD=' + $rootPassword),
        $qaMysqlContainer, 'mysql', '--batch', '--skip-column-names', '-uroot', $database, '-e', $query)
    if ($probe.ExitCode -ne 0) { return $null }
    $value = 0
    if (-not [int]::TryParse(((@($probe.Output) -join '').Trim()), [ref]$value)) { return $null }
    return $value
}

# Der Dienststatus, den deploy.php rendert, kommt aus deploy_service_health_snapshot()
# und haengt an Heartbeat-Zeilen, die nur die Worker schreiben. Waehrend der
# Pause altern sie; nach der Produktgrenze kippt die Seite von "bereit" auf
# "nicht erreichbar", und jede spaetere Aufnahme zeigt einen Zustand, den kein
# Sollbild beschreibt (RH1, 03.11.2026: unter worker_v1 nach rund 150 s, das
# waren die 3639/427468/318982 px der dritten Versuche). Die Grenze wird hier
# nicht nachgebaut: Der Snapshot nimmt die Uhrzeit als Parameter, also fragt
# die Pruefung das Produkt fuer jede Sekunde des Fensters, ob es denselben
# Status rendern wuerde wie am Fensteranfang. Uhr ist die des PHP-Containers,
# das Fenster eine Dauer der Host-Stoppuhr; so stoert ein abweichender
# Docker-VM-Takt nicht. Nur Lesen, keine Sitzung, kein Bootstrap.
function Get-QaDeployServiceDrift {
    param([int]$Seconds)
    $window = [Math]::Max(0, $Seconds)
    $php = @(
        "require_once '/var/www/html/lib/constants.php';"
        "require_once '/var/www/html/lib/db.php';"
        "require_once '/var/www/html/lib/repo/deploy_runtime_identity.php';"
        "require_once '/var/www/html/lib/deploy_service_health.php';"
        '$db = db();'
        '$to = time();'
        ('$from = $to - {0};' -f $window)
        '$state = static function (int $t) use ($db): string {'
        '  $s = deploy_service_health_snapshot($db, $t);'
        "  return implode('/', [`$s['availability'], `$s['claim_state'], `$s['recovery_attention'], `$s['badge']]);"
        '};'
        '$before = $state($from); $after = $before; $flip = null;'
        'for ($t = $from + 1; $t <= $to; $t++) {'
        '  $now = $state($t);'
        '  if ($now !== $before) { $flip = $t - $from; $after = $now; break; }'
        '}'
        "echo 'RH1DRIFT' . json_encode(['before' => `$before, 'after' => `$after, 'flip' => `$flip]) . 'RH1DRIFT';"
    ) -join ' '
    $probe = Invoke-Tool 'docker' @('exec', $qaPhpContainer, 'php', '-r', $php)
    $text = (@($probe.Output) -join "`n")
    if (($probe.ExitCode -ne 0) -or ($text -notmatch 'RH1DRIFT(.*)RH1DRIFT')) {
        return @{ Ok = $false; Output = $probe.Output }
    }
    try { $parsed = $Matches[1] | ConvertFrom-Json } catch { return @{ Ok = $false; Output = $probe.Output } }
    return @{ Ok = $true; Before = [string]$parsed.before; After = [string]$parsed.after; FlipAfterSeconds = $parsed.flip; Seconds = $window; Output = $probe.Output }
}

# Ein pass oder fail des Bodys gilt nur, wenn die Pause den gerenderten
# Dienststatus nicht veraendert hat. Sonst ist das Urteil nicht zuzuordnen:
# rot statt still falsch, als infrastructure_error, mit dem Body-Urteil im Text.
function Confirm-QaPauseKeptServiceState {
    param($Outcome, [int]$Seconds)
    if (($null -eq $Outcome) -or ($Outcome.class -eq 'infrastructure_error')) { return $Outcome }
    $verdict = ('{0}: {1}' -f $Outcome.class, $Outcome.detail)
    $drift = Get-QaDeployServiceDrift -Seconds $Seconds
    if (-not $drift.Ok) {
        return New-InfraResult ('Deploy-Dienststatus im Pausenfenster nicht pruefbar; Body-Urteil nicht verwertbar ({0})' -f $verdict) (@($Outcome.output) + @($drift.Output))
    }
    if ($null -ne $drift.FlipAfterSeconds) {
        $detail = ('Deploy-Dienststatus kippte nach {0} s der {1} s langen Worker-Pause von {2} auf {3}; Aufnahmen danach zeigen nicht den Zustand der Sollbilder. Body-Urteil nicht verwertbar ({4})' -f $drift.FlipAfterSeconds, $drift.Seconds, $drift.Before, $drift.After, $verdict)
        return New-InfraResult $detail $Outcome.output
    }
    return $Outcome
}

function Invoke-WithPausedQaWorkers {
    param([scriptblock]$Body)
    $before = Get-QaWorkerSnapshot
    if (-not $before.Ok) { return New-InfraResult $before.Detail $before.Output }
    $activeJobs = Get-QaActiveJobCount
    if ($null -eq $activeJobs) { return New-InfraResult 'aktive QA-Jobs vor Worker-Pause nicht pruefbar' }
    if ($activeJobs -ne 0) { return New-InfraResult ('Worker-Pause verweigert: {0} aktive QA-Job(s)' -f $activeJobs) }

    $running = @($before.States | Where-Object { $_.Running } | ForEach-Object { $_.Service })
    $outcome = $null
    $restoreFailure = $null
    # Das Fenster beginnt vor dem stop: Bezug ist der Status, den die laufenden
    # Worker hinterlassen, nicht der nach einem langsamen stop.
    $pauseClock = [System.Diagnostics.Stopwatch]::StartNew()
    try {
        if ($running.Count -gt 0) {
            $stopped = Invoke-QaCompose (@('stop', '--timeout', '30') + $running)
            if ($stopped.ExitCode -ne 0) {
                $outcome = New-InfraResult 'isolierte QA-Worker nicht pausierbar' $stopped.Output
            }
        }
        if ($null -eq $outcome) {
            $paused = Get-QaWorkerSnapshot
            if ((-not $paused.Ok) -or (@($paused.States | Where-Object { $_.Running }).Count -ne 0)) {
                $outcome = New-InfraResult 'isolierte QA-Worker nach stop nicht vollstaendig pausiert' $paused.Output
            } else {
                try { $outcome = & $Body } catch { $outcome = New-InfraResult ('Visual-Harness warf eine Exception: ' + $_.Exception.Message) }
                # Vor dem Neustart pruefen: ein frisch gestarteter Worker schreibt
                # seine Zeile neu und wuerde das Kippen verdecken.
                $outcome = Confirm-QaPauseKeptServiceState -Outcome $outcome -Seconds ([int][Math]::Ceiling($pauseClock.Elapsed.TotalSeconds))
            }
        }
    } finally {
        if ($running.Count -gt 0) {
            $restarted = Invoke-QaCompose (@('up', '-d', '--wait') + $running)
            if ($restarted.ExitCode -ne 0) {
                $restoreFailure = New-InfraResult 'QA-Worker-Ausgangszustand nicht wiederherstellbar' $restarted.Output
            }
        }
        if ($null -eq $restoreFailure) {
            $after = Get-QaWorkerSnapshot
            if (-not $after.Ok) {
                $restoreFailure = New-InfraResult $after.Detail $after.Output
            } else {
                foreach ($state in $before.States) {
                    $restored = @($after.States | Where-Object { $_.Service -eq $state.Service })[0]
                    if (($null -eq $restored) -or ($restored.Running -ne $state.Running)) {
                        $restoreFailure = New-InfraResult ('QA-Worker-Ausgangszustand weicht ab: ' + $state.Service)
                        break
                    }
                }
            }
        }
    }
    if ($null -ne $restoreFailure) { return $restoreFailure }
    return $outcome
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
    param([string]$Exe, [string[]]$Arguments = @(), [switch]$Live)
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $lines = @()
    # StartFailed trennt "Werkzeug lief und meldete etwas" von "Werkzeug lief nie":
    # ein fehlendes Programm oder eine Kommandozeile ueber der Windows-Grenze
    # (32767 Zeichen, "Der Dateiname oder die Erweiterung ist zu lang") wirft hier,
    # und Exit 127 allein ist von einem echten Exit 127 des Werkzeugs nicht zu
    # unterscheiden (RH1, 03.11.2026: yaml-lint/shellcheck als "meldet Befunde").
    $startFailed = $false
    try {
        $lines = @(& $Exe @Arguments 2>&1 | ForEach-Object {
            $line = "$_"
            if ($Live) { Write-Host $line }
            $line
        })
        $code = $LASTEXITCODE
    } catch {
        $lines = @("$($_.Exception.Message)")
        $code = 127
        $startFailed = $true
    } finally {
        $ErrorActionPreference = $prev
    }
    return @{ ExitCode = $code; Output = $lines; StartFailed = $startFailed }
}

function Get-ToolStartFailureDetail {
    param($Result)
    return ('Werkzeug nicht gestartet, das Gate hat nichts geprueft: ' + ((@($Result.Output) -join ' ').Trim()))
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

# Der Node-Resolver ist die einzige Browserauflosung fuer Runner und
# playwright.config.js. Er fragt die lockfile-gepinnte playwright-core-Revision
# ab; dieser PowerShell-Pfad scannt keinen Cache und traegt keinen Benutzerpfad.
function Resolve-PlaywrightBrowser {
    param([string]$Engine)
    if (-not (Test-Command 'node')) { return $null }
    $resolver = Join-Path (Join-Path (Join-Path $repoRoot 'tests') 'e2e') 'lib/browser-resolver.js'
    if (-not (Test-Path $resolver)) { return $null }
    $r = Invoke-Tool 'node' @($resolver, '--engine', $Engine)
    if ($r.ExitCode -ne 0) { return $null }
    try { return ((@($r.Output) -join "`n") | ConvertFrom-Json) } catch { return $null }
}

function Test-PlaywrightEngineCache {
    param([string]$Engine)
    return ($null -ne (Resolve-PlaywrightBrowser $Engine))
}

function Test-PlaywrightChromium {
    return (Test-PlaywrightEngineCache 'chromium')
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

# Ein Repo-Shellskript ausfuehren: Host-sh, sonst das explizite PHP-Tooling-Image.
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

# Ein Repo-PHP-Skript ausfuehren: Host-php, sonst das PHP-Tooling-Image.
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
# sein. Nur das Tooling-Target bringt Composer mit; vendor/ kommt aus dem Mount.
# Ein fehlendes Toolimage ist Infrastrukturfehler, kein Auftrag an den Dev-Stack.
function Invoke-AppComposer {
    param([string[]]$Arguments)
    if (Test-DockerImage $toolImages.php) {
        $qaAppEnvFile = Get-QaAppEnvFile
        return Invoke-Tool 'docker' (@('run', '--rm',
            '-v', ($repoRoot + ':/repo'), '-w', '/repo/Docker/WebAPI',
            '--tmpfs', '/repo/Docker/WebAPI/var:mode=1777', '--tmpfs', '/repo/Docker/WebAPI/logs:mode=1777',
            '-v', ($qaAppEnvFile + ':/repo/.env:ro'), '-v', ($qaAppEnvFile + ':/repo/Docker/WebAPI/.env:ro'),
            '-e', 'COMPOSER_CACHE_DIR=/tmp/composer-cache', '-e', 'COMPOSER_ALLOW_SUPERUSER=1',
            # Der Mount gehoert dem Host-User, composer laeuft als root: ohne
            # safe.directory verweigert git die Versionsermittlung und composer
            # verrauscht jeden Lauf mit "dubious ownership"-Fatals (CI-Lauf
            # 2026-07-16). Env-Config statt --global, das Image bleibt sauber.
            '-e', 'GIT_CONFIG_COUNT=1',
            '-e', 'GIT_CONFIG_KEY_0=safe.directory', '-e', 'GIT_CONFIG_VALUE_0=*',
            $toolImages.php, 'composer') + $Arguments)
    }
    return $null
}

# Dateien unter dem Pruef-Root sammeln. Ausgabebaeume werden schon bei der
# Traversierung abgeschnitten, damit kopierte Quellen darin weder geparst noch
# als eigener Produktumfang gezaehlt werden. Ein Unterverzeichnis mit eigenem
# `.git` (Datei bei `git worktree`, Ordner bei einem eingebetteten Klon) ist ein
# anderer Arbeitsbaum: sein Inhalt ist fremder Stand, nicht dieser. Abgeleitet
# statt als Pfadliste, damit ein neuer Ablageort (etwa .claude/worktrees,
# .codex) nicht still wieder mitgezaehlt wird (RH1, 03.11.2026: 692 statt 35
# YAML-Dateien, docker-Kommandozeile ueber der Windows-Grenze).
function Test-NestedWorkingTree {
    param([System.IO.DirectoryInfo]$Directory)
    return (Test-Path -LiteralPath (Join-Path $Directory.FullName '.git'))
}

function Get-CheckFiles {
    param([string[]]$Patterns)
    $excludedDirectories = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
    foreach ($name in @('vendor', 'node_modules', '.git', '.vs', 'var', 'bin', 'obj', 'qa-artifacts')) {
        [void]$excludedDirectories.Add($name)
    }
    $pending = New-Object 'System.Collections.Generic.Stack[System.IO.DirectoryInfo]'
    $pending.Push((Get-Item -LiteralPath $repoRoot -Force -ErrorAction Stop))
    $found = @()
    while ($pending.Count -gt 0) {
        $directory = $pending.Pop()
        foreach ($file in @(Get-ChildItem -LiteralPath $directory.FullName -File -Force -ErrorAction SilentlyContinue)) {
            foreach ($pattern in $Patterns) {
                if ($file.Name -like $pattern) {
                    $found += $file.FullName
                    break
                }
            }
        }
        foreach ($child in @(Get-ChildItem -LiteralPath $directory.FullName -Directory -Force -ErrorAction SilentlyContinue)) {
            if ($excludedDirectories.Contains($child.Name)) { continue }
            if (Test-NestedWorkingTree $child) { continue }
            $pending.Push($child)
        }
    }
    return @($found | Sort-Object -Unique)
}

function ConvertTo-RepoRelative {
    param([string[]]$Paths)
    return @($Paths | ForEach-Object { (($_ -replace '\\', '/') -replace [regex]::Escape($repoRoot + '/'), '') })
}

function Format-ToolResult {
    param($Result, [string]$OkDetail, [string]$FailDetail)
    if ($Result.StartFailed) { return New-InfraResult (Get-ToolStartFailureDetail $Result) $Result.Output }
    if ($Result.ExitCode -eq 0) { return New-PassResult $OkDetail $Result.Output }
    return New-FailResult ("{0} (exit {1})" -f $FailDetail, $Result.ExitCode) $Result.Output
}
