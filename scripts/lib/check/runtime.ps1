# Dot-sourced check module. Importing defines functions only.

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
    try {
        if ($running.Count -gt 0) {
            $stopped = Invoke-QaCompose (@('stop') + $running)
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
