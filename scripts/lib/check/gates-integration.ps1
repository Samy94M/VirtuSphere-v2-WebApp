# Dot-sourced check module. Importing defines functions only.

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
    if (-not (Test-Path (Join-Path $e2eDir 'node_modules'))) {
        $ci = Invoke-Tool 'npm' @('--prefix', $e2eDir, 'ci')
        if ($ci.ExitCode -ne 0) { return New-InfraResult 'npm ci fuer tests/e2e rot' $ci.Output }
    }
    if (-not (Test-PlaywrightChromium)) { return New-InfraResult 'kein Chromium fuer Playwright: gemeinsamer Browserresolver meldet die lockfile-gepinnte Revision als fehlend' }

    $prevEnv = @{}
    $e2eEnv = @{
        VIRTUSPHERE_BASE_URL        = ($qaPortalBase + '/portal/')  # Slash ist tragend (ADR-0028)
        VIRTUSPHERE_PHP_CONTAINER   = $qaPhpContainer
        VIRTUSPHERE_MYSQL_CONTAINER = $qaMysqlContainer
        VIRTUSPHERE_ADMIN_USER      = (Get-QaEnvValue 'SEED_ADMIN_USER')
        VIRTUSPHERE_ADMIN_PASS      = (Get-QaEnvValue 'SEED_ADMIN_PASSWORD')
        DB_NAME                     = (Get-QaEnvValue 'DB_NAME')
        # Eigener Berichtsordner je Gate. Chromium, die Firefox/WebKit-Matrix und
        # Edge liefen zuvor alle in denselben Ordner, sodass das zuletzt laufende
        # Gate den Bericht des gescheiterten ueberschrieb; eine rote Matrix war
        # danach nicht mehr diagnostizierbar.
        VIRTUSPHERE_E2E_REPORT_DIR  = ('playwright-report-' + ($Projects -join '-'))
    }
    foreach ($k in $e2eEnv.Keys) {
        $prevEnv[$k] = [Environment]::GetEnvironmentVariable($k)
        [Environment]::SetEnvironmentVariable($k, [string]$e2eEnv[$k])
    }
    $projectArgs = @()
    foreach ($p in $Projects) { $projectArgs += ('--project=' + $p) }

    # Dieselbe Quieszierung wie vor phpunit-full, und aus demselben Grund. Die
    # Specs legen absichtlich Deployjobs an und loeschen ihre Fixtures wieder;
    # ein gleichzeitig claimender Deploy-Worker oder reapender
    # Maintenance-Worker ist dann ein zweiter Besitzer derselben Wegwerfzeilen.
    # Das ist hier keine Vermutung: `e2e-portal` fiel in der Release-Lane vom
    # 06.09.2026 mit "Deadlock found when trying to get lock" im
    # beforeEach-Cleanup von deploy-actions.spec.js, unmittelbar nachdem dessen
    # Retry-Test den einen Job erzeugt hatte, den der Worker laut Dateikopf
    # claimen darf. Die Abhilfe stand seit dem Etappe-8-Rest an phpunit-full und
    # war nie auf die Playwrightgates uebertragen worden, obwohl die Begruendung
    # woertlich gilt.
    #
    # Kein Spec braucht einen laufenden Worker: die vollstaendige Chromium-Suite
    # ist mit gestoppten Workern 259/259 gruen und dabei schneller als mit.
    # Produktionslocking bleibt unveraendert, und das finally stellt beide auch
    # nach einem roten oder werfenden Lauf health-geprueft wieder her.
    $qaTestWorkers = @('deploy-worker', 'maintenance-worker')
    $stopped = Invoke-QaCompose (@('stop', '--timeout', '30') + $qaTestWorkers)
    if ($stopped.ExitCode -ne 0) {
        foreach ($k in $prevEnv.Keys) { [Environment]::SetEnvironmentVariable($k, $prevEnv[$k]) }
        return New-InfraResult 'QA-Worker vor dem Playwrightlauf nicht quieszierbar' $stopped.Output
    }

    Push-Location $e2eDir
    $r = $null
    $restarted = $null
    try {
        $r = Invoke-Tool 'npx' (@('playwright', 'test') + $projectArgs)
    } finally {
        Pop-Location
        $restarted = Invoke-QaCompose (@('up', '-d', '--wait') + $qaTestWorkers)
        foreach ($k in $prevEnv.Keys) { [Environment]::SetEnvironmentVariable($k, $prevEnv[$k]) }
    }
    if ($null -eq $restarted -or $restarted.ExitCode -ne 0) {
        $restartOutput = if ($null -eq $restarted) { @('worker restart did not return a result') } else { @($restarted.Output) }
        return New-InfraResult 'QA-Worker nach dem Playwrightlauf nicht wieder healthy' (@($r.Output) + $restartOutput)
    }
    Format-ToolResult $r $OkDetail $FailDetail
}

function Invoke-VisualBaselines {
    if (-not (Test-Container $qaPhpContainer)) { return New-InfraResult 'QA-Stack laeuft nicht (Gate qa-stack zuerst)' }
    $e2eDir = Join-Path (Join-Path $repoRoot 'tests') 'e2e'
    $harness = Join-Path (Join-Path $e2eDir 'visual') 'harness.js'
    if (-not (Test-Path $harness)) { return New-InfraResult 'Visual-Harness fehlt unter tests/e2e/visual' }
    $visualArtifactDir = Join-Path (Join-Path $repoRoot 'qa-artifacts') ('visual-baselines-' + $runStamp)

    $previous = @{}
    $visualEnv = @{
        VIRTUSPHERE_BASE_URL            = ($qaPortalBase + '/portal/')
        VIRTUSPHERE_PHP_CONTAINER       = $qaPhpContainer
        VIRTUSPHERE_MYSQL_CONTAINER     = $qaMysqlContainer
        VIRTUSPHERE_ADMIN_USER          = (Get-QaEnvValue 'SEED_ADMIN_USER')
        VIRTUSPHERE_ADMIN_PASS          = (Get-QaEnvValue 'SEED_ADMIN_PASSWORD')
        VIRTUSPHERE_QA_PROJECT          = $qaProject
        VIRTUSPHERE_VISUAL_QA_ALLOWED   = '1'
        VIRTUSPHERE_VISUAL_ARTIFACT_DIR = $visualArtifactDir
        DB_NAME                         = (Get-QaEnvValue 'DB_NAME')
        # Keine Lane darf Sollbilder erzeugen (Etappe 17). Der Harness lehnt jede
        # dieser Variablen ab; hier werden sie zusaetzlich geleert, damit eine
        # geerbte Shell-Variable gar nicht erst bei ihm ankommt und der Lauf als
        # infrastructure_error endet, statt zu pruefen.
        UPDATE_SNAPSHOTS                    = ''
        VIRTUSPHERE_UPDATE_VISUAL_BASELINES = ''
        VIRTUSPHERE_VISUAL_BASELINE_UPDATE  = ''
    }
    foreach ($key in $visualEnv.Keys) {
        $previous[$key] = [Environment]::GetEnvironmentVariable($key)
        [Environment]::SetEnvironmentVariable($key, [string]$visualEnv[$key])
    }
    try {
        return Invoke-WithPausedQaWorkers {
            $run = Invoke-Tool 'node' @($harness)
            if ($run.ExitCode -eq 0) { return New-PassResult ('reviewte Sollbaselines beider Themes bei Nulltoleranz getroffen; Artefakte: ' + $visualArtifactDir) $run.Output }
            if ($run.ExitCode -eq 2) { return New-InfraResult 'Visual-Harness meldet infrastructure_error' $run.Output }
            return New-FailResult 'Visual-Harness meldet Abweichung von den reviewten Sollbaselines oder einen fachlichen Browserfehler' $run.Output
        }
    } finally {
        foreach ($key in $previous.Keys) { [Environment]::SetEnvironmentVariable($key, $previous[$key]) }
    }
}


# Dot-sourced check module. Importing defines functions only.

function Register-IntegrationCheckGates {
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

    Add-Gate -Name 'e2e-portal' -Lanes $intRel -Kind 'native' -Network $true -Body {
        # Playwright-Chromium gegen den QA-Stack (ADR-0028-Revision): beweist, was
        # nur ein Browser beweisen kann. Netzabhaengig wegen npm ci beim Erstlauf.
        $functional = Invoke-PlaywrightSuite @('chromium') 'Playwright-Chromium-Suite gruen (QA-Stack)' 'Playwright-Suite rot'
        if ($functional.class -ne 'pass') { return $functional }
        $visual = Invoke-VisualBaselines
        if ($visual.class -ne 'pass') { return $visual }
        New-PassResult ($functional.detail + '; ' + $visual.detail) (@($functional.output) + @($visual.output))
    }

    Add-Gate -Name 'visual-contract' -Lanes $intRel -Kind 'native' -Body {
        # Die statische Haelfte des Visualvertrags (Etappe 17): Browserresolver,
        # Metadatenvergleich, Update-Verweigerung, Maskendeklaration und Manifest.
        # Sie lief bisher nur von Hand. Ein Vertrag, den keine Lane ausfuehrt, ist
        # genau so viel wert wie kein Vertrag; er laeuft nach e2e-portal, weil der
        # das fehlende node_modules per npm ci herstellt.
        if (-not (Test-Command 'node')) { return New-InfraResult 'node nicht gefunden' }
        $e2eDir = Join-Path (Join-Path $repoRoot 'tests') 'e2e'
        if (-not (Test-Path (Join-Path $e2eDir 'node_modules'))) { return New-InfraResult 'tests/e2e/node_modules fehlt (Gate e2e-portal zuerst)' }
        Push-Location $e2eDir
        # Muster statt Verzeichnis: node 24 loest 'tests/' als Modulpfad auf und
        # bricht mit MODULE_NOT_FOUND ab, statt die Datei darin zu fahren.
        try { $r = Invoke-Tool 'node' @('--test', 'tests/*.test.js') } finally { Pop-Location }
        Format-ToolResult $r 'Visual-/Resolververtraege gruen' 'Visual-/Resolververtraege rot'
    }

    Add-Gate -Name 'guard-harness' -Lanes $intRel -Kind 'native' -Body {
        $hostExe = 'powershell'
        if ($PSVersionTable.PSEdition -eq 'Core') { $hostExe = 'pwsh' }
        $r = Invoke-Tool $hostExe @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $scriptDir 'test-guards.ps1'))
        if ($r.ExitCode -eq 2) { return New-InfraResult 'Guard-Harness ohne vollstaendige Umgebung' $r.Output }
        Format-ToolResult $r 'alle Guards positiv/negativ/zero-match bewiesen' 'Guard-Harness meldet unbewiesene Guards'
    }

}
