#Requires -Version 5.1
<#
.SYNOPSIS
    Haertungs-Contract fuer docker-compose.yml und die First-Party-Dockerfiles
    (AP8): jede Lockerung der bestehenden Container-Haertung bricht den Build.

.DESCRIPTION
    Prueft die kanonisch aufgeloeste Compose-Konfiguration semantisch (docker
    compose config --format json, inklusive tools-Profil), nie per Regex ueber
    das YAML: gepinnt werden read_only+tmpfs, cap_drop ALL, die exakt
    dokumentierten cap_add-Sets, no-new-privileges, PID-/Memory-Limits,
    Healthchecks, service_healthy-Startordnung, restart- und Logging-Policy, die
    Loopback-Bindung und das tools-Profil von phpMyAdmin, feste Tags und
    Build-Kontexte der lokal gehaerteten Runtime-Images sowie die Digest-Pins in
    den FROM-/COPY---from-Zeilen der First-Party-Dockerfiles. Der QA-Override (Docker/qa) aendert nur
    volumes/restart und erbt die Haertung; geprueft wird die Basisdatei.

    Das aufgeloeste config-JSON enthaelt interpolierte Secrets aus .env und wird
    deshalb weder gespeichert noch ausgegeben; Diagnosen nennen nur Service und
    Feld. Diagnose-IDs sind stabil: [compose.<fall>].

    Exitcodes: 0 Contract erfuellt | 1 Verstoss | 2 Pruefumgebung unvollstaendig.

.PARAMETER Quiet
    Bei Erfolg nichts ausgeben (Befunde erscheinen immer).
#>
[CmdletBinding()]
param([switch]$Quiet)

Set-StrictMode -Version 1.0
$ErrorActionPreference = 'Stop'

$scriptDir = $PSScriptRoot
$root = $env:VIRTUSPHERE_CHECK_ROOT
if (-not $root) { $root = Split-Path $scriptDir -Parent }
$root = ($root -replace '\\', '/').TrimEnd('/')

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

if (-not (Get-Command 'docker' -ErrorAction SilentlyContinue)) {
    Write-Output 'check-compose-hardening: docker nicht gefunden'
    exit 2
}
$composeFile = Join-Path $root 'docker-compose.yml'
if (-not (Test-Path $composeFile)) {
    Write-Output ('check-compose-hardening: docker-compose.yml fehlt unter ' + $root)
    exit 2
}
if (-not (Test-Path (Join-Path $root '.env'))) {
    Write-Output 'check-compose-hardening: .env fehlt (compose config braucht sie; in CI aus .env.example erzeugt)'
    exit 2
}

# --profile "*": alle Profile aufloesen. Mit einem konkreten Profilnamen
# verschwaende ein in ein fremdes Profil verschobener Service einfach aus der
# Sicht, und der Contract koennte ihn nie als falsch platziert melden.
$r = Invoke-Tool 'docker' @('compose', '--project-directory', $root, '-f', $composeFile,
    '--profile', '*', 'config', '--format', 'json')
if ($r.ExitCode -ne 0) {
    Write-Output 'check-compose-hardening: docker compose config fehlgeschlagen:'
    $r.Output | Select-Object -First 5 | ForEach-Object { Write-Output ('  ' + $_) }
    exit 2
}
try {
    $config = (@($r.Output) -join "`n") | ConvertFrom-Json
} catch {
    Write-Output 'check-compose-hardening: compose config lieferte kein parsebares JSON'
    exit 2
}

$findings = New-Object System.Collections.ArrayList
function Add-Finding { param([string]$Id, [string]$Message) [void]$findings.Add(('[compose.{0}] {1}' -f $Id, $Message)) }
function Get-Prop {
    param($Object, [string]$Name)
    $p = $Object.PSObject.Properties[$Name]
    if ($p) { return $p.Value }
    return $null
}

# --- Erwartungs-SSoT -----------------------------------------------------------
$expectedServices = @('webserver', 'php', 'deploy-worker', 'maintenance-worker', 'mysql', 'phpmyadmin')
$expectedLogDriver = 'json-file'
$expectedLogOptions = @{ 'max-size' = '10m'; 'max-file' = '5' }
$readOnlyServices = @('webserver', 'php', 'deploy-worker', 'maintenance-worker')
$expectedCapAdd = @{
    'webserver'          = @('CHOWN', 'DAC_READ_SEARCH', 'SETGID', 'SETUID')
    'php'                = @('KILL', 'SETGID', 'SETUID')
    'deploy-worker'      = @()
    'maintenance-worker' = @()
    'mysql'              = @('CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'SETGID', 'SETUID')
    'phpmyadmin'         = @()
}
# phpMyAdmin bewusst ohne Healthcheck: tools-Profil, nichts wartet darauf.
$healthcheckedServices = @('webserver', 'php', 'deploy-worker', 'maintenance-worker', 'mysql')
$dependsHealthy = @{
    'webserver'          = @('php')
    'php'                = @('mysql')
    'deploy-worker'      = @('mysql')
    'maintenance-worker' = @('mysql')
    'phpmyadmin'         = @('mysql')
}
$builtRuntimeImages = @{
    php = @{ Tag = 'virtusphere-php:8.4-runtime'; Context = '/Docker/php'; Target = 'runtime' }
    mysql = @{ Tag = 'mysql:8.4-virtusphere'; Context = '/Docker/mysql' }
    phpmyadmin = @{ Tag = 'phpmyadmin:5.2.3-virtusphere'; Context = '/Docker/phpmyadmin' }
}

# Welche Umgebungsschluessel ein Service sehen DARF, nach Aufloesung durch
# `docker compose config`.
#
# Anlass: `env_file: .env` gab dem LAN-zugewandten nginx-Container und dem
# phpMyAdmin-Container APP_KEY, DB_PASS und MYSQL_ROOT_PASSWORD, obwohl keines der
# beiden Images einen dieser Werte anfasst. Beim phpMyAdmin kam ein zweiter Schaden
# dazu: das Image liest PMA_PORT als Port des MySQL-SERVERS, waehrend das Projekt
# unter demselben Namen den Host-Veroeffentlichungsport fuehrt - der Container
# versuchte also mysql:8023, wo nichts lauscht, und die Datenbankverbindung war
# kaputt, waehrend die Oberflaeche erreichbar blieb.
#
$expectedEnvKeys = @{
    'webserver' = @()
    'php' = @('ANSIBLE_SOURCE_DIR', 'APP_ENV', 'APP_KEY', 'APP_PUBLIC_BASE_URL',
        'DB_HOST', 'DB_NAME', 'DB_PASS', 'DB_PORT', 'DB_USER', 'SEED_ADMIN_EMAIL',
        'SEED_ADMIN_PASSWORD', 'SEED_ADMIN_USER', 'VIRTUSPHERE_DEBUG', 'WEB_HTTPS_PORT')
    'deploy-worker' = @('ANSIBLE_SOURCE_DIR', 'APP_ENV', 'APP_KEY', 'APP_PUBLIC_BASE_URL',
        'DB_HOST', 'DB_NAME', 'DB_PASS', 'DB_PORT', 'DB_USER', 'VIRTUSPHERE_DEBUG',
        'VIRTUSPHERE_DEPLOY_WORKDIR')
    'maintenance-worker' = @('APP_ENV', 'APP_KEY', 'DB_HOST', 'DB_NAME', 'DB_PASS',
        'DB_PORT', 'DB_USER', 'VIRTUSPHERE_DEBUG')
    'phpmyadmin' = @('PMA_HOST', 'PMA_PORT')
    'mysql' = @('MYSQL_DATABASE', 'MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'MYSQL_USER')
}

$servicesNode = Get-Prop $config 'services'
if ($null -eq $servicesNode) {
    Add-Finding 'services' 'compose config enthaelt keinen services-Block'
} else {
    foreach ($name in $expectedServices) {
        if (-not $servicesNode.PSObject.Properties[$name]) {
            Add-Finding 'services' ('Service {0} fehlt in docker-compose.yml (Zero-Match-Schutz: der Contract kennt ihn)' -f $name)
        }
    }
}

if ($findings.Count -eq 0) {
    foreach ($name in $expectedServices) {
        $svc = Get-Prop $servicesNode $name

        # cap_drop ALL, exakt.
        $capDrop = @(Get-Prop $svc 'cap_drop')
        if (-not ($capDrop.Count -eq 1 -and "$($capDrop[0])" -eq 'ALL')) {
            Add-Finding 'cap-drop' ('{0}: cap_drop muss exakt [ALL] sein' -f $name)
        }

        # cap_add exakt das dokumentierte Set. Aeusseres @() ueberall: eine
        # Ein-Element-Pipeline liefert unter PS 5.1 einen Skalar ohne .Count.
        $capAdd = @(@(Get-Prop $svc 'cap_add') | Where-Object { $null -ne $_ } | ForEach-Object { "$_" })
        $expected = @($expectedCapAdd[$name])
        $actualSorted = @($capAdd | Sort-Object)
        $expectedSorted = @($expected | Sort-Object)
        if ((($actualSorted -join ',')) -ne (($expectedSorted -join ','))) {
            Add-Finding 'cap-add' ('{0}: cap_add ist [{1}], dokumentiert ist [{2}]' -f $name, ($actualSorted -join ', '), ($expectedSorted -join ', '))
        }

        # Umgebungsschluessel exakt das dokumentierte Set. Gemeldet werden nur
        # NAMEN, nie Werte: dieses Skript darf kein Geheimnis in einen CI-Log
        # schreiben (siehe Kopf).
        if ($null -ne $expectedEnvKeys[$name]) {
            $envNode = Get-Prop $svc 'environment'
            $envKeys = @()
            if ($null -ne $envNode) {
                $envKeys = @($envNode.PSObject.Properties.Name | ForEach-Object { "$_" })
            }
            $envActual = @($envKeys | Sort-Object)
            $envExpected = @(@($expectedEnvKeys[$name]) | Sort-Object)
            if (($envActual -join ',') -ne ($envExpected -join ',')) {
                $extra = @($envActual | Where-Object { $envExpected -notcontains $_ })
                $missing = @($envExpected | Where-Object { $envActual -notcontains $_ })
                $detail = @()
                if ($extra.Count -gt 0) { $detail += ('zusaetzlich: ' + ($extra -join ', ')) }
                if ($missing.Count -gt 0) { $detail += ('fehlt: ' + ($missing -join ', ')) }
                Add-Finding 'env-scope' ('{0}: Umgebung weicht vom dokumentierten Satz ab ({1}). Ein Container, der ein Geheimnis nie liest, soll es auch nicht sehen.' -f $name, ($detail -join '; '))
            }
        }

        # Containerlogs bleiben pro Dienst auf 5 * 10 MiB begrenzt. Exakt statt
        # nur "logging vorhanden", damit eine still entfernte Option rot wird.
        $logging = Get-Prop $svc 'logging'
        $driver = if ($null -ne $logging) { "$(Get-Prop $logging 'driver')" } else { '' }
        if ($driver -ne $expectedLogDriver) {
            Add-Finding 'logging' ('{0}: Logging-Treiber muss {1} sein' -f $name, $expectedLogDriver)
        }
        $options = if ($null -ne $logging) { Get-Prop $logging 'options' } else { $null }
        foreach ($optionName in $expectedLogOptions.Keys) {
            $actual = if ($null -ne $options) { "$(Get-Prop $options $optionName)" } else { '' }
            if ($actual -ne $expectedLogOptions[$optionName]) {
                Add-Finding 'logging' ('{0}: logging.options.{1} muss {2} sein' -f $name, $optionName, $expectedLogOptions[$optionName])
            }
        }

        # no-new-privileges.
        $secOpt = @(@(Get-Prop $svc 'security_opt') | ForEach-Object { "$_" })
        if ($secOpt -notcontains 'no-new-privileges:true') {
            Add-Finding 'no-new-privileges' ('{0}: security_opt no-new-privileges:true fehlt' -f $name)
        }

        # PID-/Memory-Limits.
        $pids = Get-Prop $svc 'pids_limit'
        if ($null -eq $pids -or [long]$pids -le 0) {
            Add-Finding 'limits' ('{0}: pids_limit fehlt oder ist nicht positiv' -f $name)
        }
        $mem = Get-Prop $svc 'mem_limit'
        if ($null -eq $mem -or [long]$mem -le 0) {
            Add-Finding 'limits' ('{0}: mem_limit fehlt oder ist nicht positiv' -f $name)
        }

        # restart-Policy (DEPLOYMENT.md: Stack ueberlebt Host-Reboot/Crash).
        $restart = Get-Prop $svc 'restart'
        if ("$restart" -ne 'unless-stopped') {
            Add-Finding 'restart' ('{0}: restart muss unless-stopped sein (QA-Override setzt bewusst "no")' -f $name)
        }

        # Kein Docker-Socket in irgendeinem Container (GROK.md).
        foreach ($vol in @(Get-Prop $svc 'volumes')) {
            if ($null -eq $vol) { continue }
            $source = "$(Get-Prop $vol 'source')"
            if ($source -match 'docker\.sock') {
                Add-Finding 'docker-socket' ('{0}: mountet den Docker-Socket ({1})' -f $name, $source)
            }
        }
    }

    $webVolumes = @(Get-Prop (Get-Prop $servicesNode 'webserver') 'volumes')
    foreach ($vol in $webVolumes) {
        if ($null -ne $vol -and "$(Get-Prop $vol 'target')" -eq '/var/log/nginx') {
            Add-Finding 'nginx-log-mount' 'webserver: /var/log/nginx darf kein unrotiertes Host-/Volumeziel sein'
        }
    }

    $mysqlCommand = @(@(Get-Prop (Get-Prop $servicesNode 'mysql') 'command') | ForEach-Object { "$_" })
    if (($mysqlCommand -join ',') -ne 'mysqld,--skip-log-bin') {
        Add-Finding 'mysql-binlog' 'mysql: command muss exakt [mysqld, --skip-log-bin] sein (ADR-0017: kein Replikations-/PITR-Verbraucher)'
    }

    # read_only + tmpfs fuer die First-Party-Services.
    foreach ($name in $readOnlyServices) {
        $svc = Get-Prop $servicesNode $name
        if (-not [bool](Get-Prop $svc 'read_only')) {
            Add-Finding 'read-only' ('{0}: read_only muss true sein' -f $name)
        }
        $tmpfs = @(@(Get-Prop $svc 'tmpfs') | ForEach-Object { "$_" })
        if ($tmpfs -notcontains '/tmp') {
            Add-Finding 'tmpfs' ('{0}: read_only ohne tmpfs /tmp' -f $name)
        }
    }

    # Healthchecks: vorhanden und nicht deaktiviert.
    foreach ($name in $healthcheckedServices) {
        $svc = Get-Prop $servicesNode $name
        $hc = Get-Prop $svc 'healthcheck'
        $test = @()
        if ($null -ne $hc) { $test = @(@(Get-Prop $hc 'test') | ForEach-Object { "$_" }) }
        if ($test.Count -eq 0 -or $test[0] -eq 'NONE') {
            Add-Finding 'healthcheck' ('{0}: healthcheck fehlt oder ist deaktiviert' -f $name)
        }
    }

    # Startordnung: echte Readiness, nie service_started.
    foreach ($name in $dependsHealthy.Keys) {
        $svc = Get-Prop $servicesNode $name
        $deps = Get-Prop $svc 'depends_on'
        foreach ($target in $dependsHealthy[$name]) {
            $dep = $null
            if ($null -ne $deps) { $dep = Get-Prop $deps $target }
            $condition = ''
            if ($null -ne $dep) { $condition = "$(Get-Prop $dep 'condition')" }
            if ($condition -ne 'service_healthy') {
                Add-Finding 'depends-healthy' ('{0}: depends_on {1} muss condition service_healthy tragen (ist: {2})' -f $name, $target, $condition)
            }
        }
    }

    # phpMyAdmin: tools-Profil + Loopback-only.
    $pma = Get-Prop $servicesNode 'phpmyadmin'
    $profiles = @(@(Get-Prop $pma 'profiles') | ForEach-Object { "$_" })
    if (-not ($profiles.Count -eq 1 -and $profiles[0] -eq 'tools')) {
        Add-Finding 'pma-profile' 'phpmyadmin: muss exakt im Profil [tools] liegen (startet sonst wieder always-on)'
    }
    foreach ($port in @(Get-Prop $pma 'ports')) {
        if ($null -eq $port) { continue }
        $hostIp = "$(Get-Prop $port 'host_ip')"
        if ($hostIp -ne '127.0.0.1') {
            Add-Finding 'pma-loopback' ('phpmyadmin: Port {0} bindet an {1} statt 127.0.0.1' -f "$(Get-Prop $port 'published')", $hostIp)
        }
    }

    # The three PHP services share one versioned runtime target; MySQL and
    # phpMyAdmin are locally hardened child images. Stable tags
    # survive docker save/load; reproducibility comes from the digest-pinned
    # FROM lines below plus the bundle checksum. Both the tag and build context
    # are part of the contract so an environment override cannot bypass them.
    foreach ($name in $builtRuntimeImages.Keys) {
        $svc = Get-Prop $servicesNode $name
        $image = "$(Get-Prop $svc 'image')"
        $expected = $builtRuntimeImages[$name]
        if ($image -ne $expected.Tag) {
            Add-Finding 'built-image-tag' ('{0}: image "{1}" statt festem Bundle-Tag "{2}"' -f $name, $image, $expected.Tag)
        }
        $build = Get-Prop $svc 'build'
        $context = ''
        if ($build) { $context = "$(Get-Prop $build 'context')" -replace '\\', '/' }
        if (-not $build -or -not $context.EndsWith($expected.Context, [System.StringComparison]::OrdinalIgnoreCase)) {
            Add-Finding 'built-image-context' ('{0}: Build-Kontext "{1}" endet nicht auf "{2}"' -f $name, $context, $expected.Context)
        }
        if ($expected.Target) {
            $target = if ($build) { "$(Get-Prop $build 'target')" } else { '' }
            if ($target -ne $expected.Target) {
                Add-Finding 'built-image-target' ('{0}: Build-Target "{1}" statt "{2}"' -f $name, $target, $expected.Target)
            }
        }
    }

    # PHP is the sole build owner; both workers consume its exact stable tag.
    # Giving every service a copied/merged build block makes BuildKit evaluate
    # the same target three times and can produce distinct provenance records.
    foreach ($name in @('php', 'deploy-worker', 'maintenance-worker')) {
        $svc = Get-Prop $servicesNode $name
        $image = "$(Get-Prop $svc 'image')"
        if ($image -ne 'virtusphere-php:8.4-runtime') {
            Add-Finding 'php-runtime-image' ('{0}: PHP-Laufzeitreferenz ist "{1}" statt virtusphere-php:8.4-runtime' -f $name, $image)
        }
        if ($name -ne 'php' -and $null -ne (Get-Prop $svc 'build')) {
            Add-Finding 'php-worker-build' ('{0}: Worker darf keinen eigenen Build-Block besitzen; php ist alleiniger Buildowner' -f $name)
        }
    }
}

# --- First-Party-Dockerfiles: FROM/COPY --from per Digest ----------------------
$dockerfiles = @(
    'Docker/php/Dockerfile', 'Docker/nginx/Dockerfile',
    'Docker/mysql/Dockerfile', 'Docker/phpmyadmin/Dockerfile',
    'Docker/qa-ansible/Dockerfile'
)
$digestRe = '@sha256:[0-9a-f]{64}'
foreach ($rel in $dockerfiles) {
    $path = Join-Path $root $rel
    if (-not (Test-Path $path)) {
        Add-Finding 'dockerfile-digest' ($rel + ': Datei fehlt (Zero-Match-Schutz: der Contract kennt sie)')
        continue
    }
    $lineNo = 0
    $knownStages = @()
    foreach ($line in [System.IO.File]::ReadAllLines($path)) {
        $lineNo++
        if ($line -match '^\s*FROM\s+(\S+)(?:\s+AS\s+(\S+))?') {
            $ref = $Matches[1]
            $stageName = $Matches[2]
            if (($knownStages -notcontains $ref) -and ($ref -notmatch $digestRe)) {
                Add-Finding 'dockerfile-digest' ('{0}:{1} FROM {2} ohne @sha256-Digest' -f $rel, $lineNo, $ref)
            }
            if ($stageName) { $knownStages += $stageName }
        }
        if ($line -match '^\s*COPY\s+--from=(\S+)') {
            $ref = $Matches[1]
            # Nur echte Image-Referenzen (mit : oder /) brauchen einen Digest;
            # ein blanker Name/Index referenziert eine Build-Stage.
            if (($ref -match '[:/]') -and ($ref -notmatch $digestRe)) {
                Add-Finding 'dockerfile-digest' ('{0}:{1} COPY --from={2} ohne @sha256-Digest' -f $rel, $lineNo, $ref)
            }
        }
    }
}

if ($findings.Count -gt 0) {
    $findings | ForEach-Object { Write-Output $_ }
    Write-Output ('check-compose-hardening: {0} Verstoss/Verstoesse gegen den Haertungs-Contract' -f $findings.Count)
    exit 1
}
if (-not $Quiet) {
    Write-Output ('check-compose-hardening: OK ({0} Services, {1} Dockerfiles gepinnt)' -f $expectedServices.Count, $dockerfiles.Count)
}
exit 0
