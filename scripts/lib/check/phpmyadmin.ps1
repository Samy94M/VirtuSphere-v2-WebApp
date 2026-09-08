# Dot-sourced check module. Importing defines functions only.

function Test-PhpMyAdminLoginContent {
    param([string]$Content)
    # HTTP 200 is also the original broken login response. Require an
    # authenticated page AND the actual MySQL TCP transport, not just HTML.
    return ($Content.Contains('route=/logout') -and
        $Content.Contains('via TCP/IP') -and
        -not $Content.Contains('name="pma_password"') -and
        -not $Content.Contains('mysqli::real_connect'))
}

function Invoke-PhpMyAdminSmoke {
    # Identity is supplied only by the canonical runner's QA registry.
    $identity = Get-QaStackIdentity $repoRoot
    if ($qaProject -ne $identity.Project -or $qaEnvFile -ne $identity.EnvFile) {
        return New-InfraResult 'phpMyAdmin smoke refuses non-QA identity'
    }
    $container = $qaProject + '-phpmyadmin-1'
    $started = Invoke-QaCompose @('--profile', 'tools', 'up', '-d', '--no-deps', '--force-recreate', 'phpmyadmin')
    if ($started.ExitCode -ne 0) { return New-InfraResult 'QA phpMyAdmin tools start failed' $started.Output }
    try {
        $inspection = Invoke-Tool 'docker' @('inspect', '--format', '{{json .}}', $container)
        if ($inspection.ExitCode -ne 0) { return New-InfraResult 'QA phpMyAdmin inspect failed' }
        $state = (@($inspection.Output) -join '') | ConvertFrom-Json
        if ($state.Config.Labels.'com.docker.compose.project' -ne $identity.Project -or
            $state.Config.Labels.'com.docker.compose.service' -ne 'phpmyadmin' -or
            $state.Config.User -ne 'www-data:www-data' -or
            @($state.HostConfig.CapDrop).Count -ne 1 -or
            $state.HostConfig.CapDrop[0] -ne 'ALL' -or
            ($null -ne $state.HostConfig.CapAdd -and @($state.HostConfig.CapAdd).Count -gt 0) -or
            @($state.HostConfig.SecurityOpt) -notcontains 'no-new-privileges:true') {
            return New-FailResult 'phpMyAdmin runtime identity/capability hardening differs'
        }
        # No configuration contents or cookie/token values enter the artifact.
        $base = 'http://127.0.0.1:' + (Get-QaEnvValue 'PMA_PORT')
        $page = $null
        # Bounded startup readiness; never retries the login assertion.
        for ($attempt = 0; $attempt -lt 10; $attempt++) {
            try {
                $page = Invoke-WebRequest -Uri ($base + '/') -SessionVariable loginSession -UseBasicParsing -TimeoutSec 5
                break
            } catch { if ($attempt -lt 9) { Start-Sleep -Seconds 1 } }
        }
        if ($null -eq $page) { return New-InfraResult 'QA phpMyAdmin HTTP startup failed' }
        $probePath = Join-Path $repoRoot 'Docker/qa/phpmyadmin-contract.sh'
        $copy = Invoke-Tool 'docker' @('cp', $probePath, ($container + ':/tmp/phpmyadmin-contract.sh'))
        if ($copy.ExitCode -ne 0) { return New-InfraResult 'phpMyAdmin permission probe unavailable' }
        $rights = Invoke-Tool 'docker' @('exec', $container, 'sh', '/tmp/phpmyadmin-contract.sh')
        if ($rights.ExitCode -ne 0) { return New-FailResult 'phpMyAdmin configuration/vendor/capability contract failed' $rights.Output }
        $token = [regex]::Match($page.Content, 'name="token" value="([^"]+)"').Groups[1].Value
        if (-not $token) { return New-FailResult 'phpMyAdmin login token missing' }
        $body = @{
            pma_username = Get-QaEnvValue 'DB_USER'
            pma_password = Get-QaEnvValue 'DB_PASS'
            server = '1'; target = 'index.php'; token = $token
        }
        $login = Invoke-WebRequest -Uri ($base + '/index.php?route=/') -WebSession $loginSession -Method Post -Body $body -UseBasicParsing -TimeoutSec 15
        if ([int]$login.StatusCode -ne 200 -or -not (Test-PhpMyAdminLoginContent $login.Content)) {
            return New-FailResult 'phpMyAdmin authenticated MySQL TCP login failed (response redacted)'
        }
        $logs = Invoke-Tool 'docker' @('logs', $container)
        if ($logs.ExitCode -ne 0) { return New-InfraResult 'phpMyAdmin startup logs unavailable' }
        if ((@($logs.Output) -join "`n") -match 'Permission denied|Operation not permitted|AH02156') {
            return New-FailResult 'phpMyAdmin startup permission failure (logs redacted)'
        }
        return New-PassResult 'phpMyAdmin tools: unprivileged, zero capabilities, config/vendor permissions, HTTP 200 authenticated MySQL via TCP/IP'
    } catch {
        return New-InfraResult 'phpMyAdmin smoke transport/inspection failed (exception redacted)'
    } finally {
        # The optional tool is not left running by a lane, including on failure.
        $null = Invoke-QaCompose @('--profile', 'tools', 'stop', 'phpmyadmin')
    }
}
