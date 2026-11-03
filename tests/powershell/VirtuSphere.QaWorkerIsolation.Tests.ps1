# Contract for deterministic database ownership during the full integration
# suite. PHPUnit creates synthetic deploy states that real worker loops must not
# claim, reap or converge while the assertions still own them.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $runner = @(
        Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') 'check.ps1') -Raw
        Get-Content -LiteralPath (Join-Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'check') 'gates-integration.ps1') -Raw
    ) -join "`n"
    $match = [regex]::Match(
        $runner,
        "Add-Gate -Name 'phpunit-full'[\s\S]*?(?=Add-Gate -Name 'schema-convergence')"
    )
    if (-not $match.Success) {
        throw 'phpunit-full gate block not found'
    }
    $script:Gate = $match.Value
    . (Join-Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'check') 'runtime.ps1')
}

Describe 'QA worker isolation for phpunit-full' {
    It 'quiesces both database-mutating worker loops before PHPUnit starts' {
        $script:Gate | Should -Match "\`$qaTestWorkers\s*=\s*@\('deploy-worker', 'maintenance-worker'\)"
        $script:Gate | Should -Match "Invoke-QaCompose \(@\('stop', '--timeout', '30'\) \+ \`$qaTestWorkers\)"

        $stopAt = $script:Gate.IndexOf("@('stop', '--timeout', '30')")
        $phpunitAt = $script:Gate.IndexOf("'vendor/bin/phpunit'")
        $stopAt | Should -BeGreaterThan -1
        $phpunitAt | Should -BeGreaterThan $stopAt
    }

    It 'restores and health-checks both workers from a finally path' {
        $script:Gate | Should -Match '} finally {'
        $script:Gate | Should -Match "Invoke-QaCompose \(@\('up', '-d', '--wait'\) \+ \`$qaTestWorkers\)"
        $script:Gate | Should -Match "QA-Worker nach phpunit-full nicht wieder healthy"

        $finallyAt = $script:Gate.IndexOf('} finally {')
        $restartAt = $script:Gate.IndexOf("@('up', '-d', '--wait')")
        $finallyAt | Should -BeGreaterThan -1
        $restartAt | Should -BeGreaterThan $finallyAt
    }
}

Describe 'Visual QA worker restore contract' {
    BeforeEach {
        $script:snapshotCall = 0
        $script:events = New-Object System.Collections.Generic.List[string]
        # The qa-repo check (OV1) has its own suite, VirtuSphere.QaSourceVolume.Tests.ps1;
        # here the stack is synthetic and its copy counts as current.
        Mock Confirm-QaSourceCurrent { $script:events.Add('source'); $null }
        Mock Get-QaActiveJobCount { 0 }
        Mock Invoke-QaCompose { $script:events.Add($Arguments[0]); @{ ExitCode = 0; Output = @() } }
        Mock Get-QaDeployServiceDrift {
            $script:events.Add('drift')
            @{ Ok = $true; Before = 'ready/accepting/none/success'; After = 'ready/accepting/none/success'; FlipAfterSeconds = $null; Seconds = $Seconds; Output = @() }
        }
        Mock Get-QaWorkerSnapshot {
            $script:snapshotCall++
            if ($script:snapshotCall -eq 1) {
                return @{ Ok = $true; States = @(
                    @{ Service = 'deploy-worker'; Running = $true },
                    @{ Service = 'maintenance-worker'; Running = $false }
                ); Output = @() }
            }
            if ($script:snapshotCall -eq 2) {
                return @{ Ok = $true; States = @(
                    @{ Service = 'deploy-worker'; Running = $false },
                    @{ Service = 'maintenance-worker'; Running = $false }
                ); Output = @() }
            }
            return @{ Ok = $true; States = @(
                @{ Service = 'deploy-worker'; Running = $true },
                @{ Service = 'maintenance-worker'; Running = $false }
            ); Output = @() }
        }
    }

    It 'restores only the worker that was running before the visual body' {
        $result = Invoke-WithPausedQaWorkers { New-PassResult 'visual body passed' }
        $result.class | Should -Be 'pass'
        Should -Invoke Invoke-QaCompose -Times 1 -ParameterFilter { $Arguments[0] -eq 'stop' -and $Arguments -contains 'deploy-worker' -and $Arguments -notcontains 'maintenance-worker' }
        Should -Invoke Invoke-QaCompose -Times 1 -ParameterFilter { $Arguments[0] -eq 'up' -and $Arguments -contains 'deploy-worker' -and $Arguments -notcontains 'maintenance-worker' }
    }

    It 'still restores the original state when the visual body throws' {
        $result = Invoke-WithPausedQaWorkers { throw 'synthetic visual failure' }
        $result.class | Should -Be 'infrastructure_error'
        $result.detail | Should -Match 'synthetic visual failure'
        Should -Invoke Invoke-QaCompose -Times 2
    }

    It 'refuses active jobs before issuing any worker stop' {
        Mock Get-QaActiveJobCount { 1 }
        $script:bodyCalled = $false
        $result = Invoke-WithPausedQaWorkers { $script:bodyCalled = $true; New-PassResult }
        $result.class | Should -Be 'infrastructure_error'
        $result.detail | Should -Match '1 aktive QA-Job'
        $script:bodyCalled | Should -BeFalse
        Should -Invoke Invoke-QaCompose -Times 0
    }

    It 'refuses a worker whose Compose identity cannot be proven' {
        Mock Get-QaWorkerSnapshot { @{ Ok = $false; Detail = 'Worker-Isolation verweigert: shared'; Output = @() } }
        $result = Invoke-WithPausedQaWorkers { New-PassResult }
        $result.class | Should -Be 'infrastructure_error'
        $result.detail | Should -Match 'shared'
        Should -Invoke Invoke-QaCompose -Times 0
    }

    # RH1, 03.11.2026: Die Pause liess die Worker-Zeile altern; ab rund 150 s
    # zeigte deploy.php "nicht erreichbar", und die dritten Versuche trugen
    # 3639/427468/318982 px Inhaltsdiff gegen das Sollbild "bereit".
    It 'checks the rendered service state after the body and before any worker restart' {
        $result = Invoke-WithPausedQaWorkers { New-PassResult 'visual body passed' }
        $result.class | Should -Be 'pass'
        @($script:events) | Should -Be @('source', 'stop', 'drift', 'up')
    }

    # OV1, 03.11.2026: Ein stehender Stack aus einem frueheren qa-stack darf
    # weder gepruefte Bilder noch Sollbilder von altem Code liefern.
    It 'refuses a stale qa-repo copy before any worker stop or body' {
        Mock Confirm-QaSourceCurrent { New-InfraResult 'Arbeitsbaum weicht vom Stand des stehenden QA-Stacks ab (1 Dateien seit qa-stack)' }
        $script:bodyCalled = $false
        $result = Invoke-WithPausedQaWorkers { $script:bodyCalled = $true; New-PassResult }
        $result.class | Should -Be 'infrastructure_error'
        $result.detail | Should -Match 'stehenden QA-Stacks'
        $script:bodyCalled | Should -BeFalse
        Should -Invoke Invoke-QaCompose -Times 0
        Should -Invoke Get-QaWorkerSnapshot -Times 0
    }

    It 'turns a pass into infrastructure_error when the pause flipped the service state' {
        Mock Get-QaDeployServiceDrift {
            @{ Ok = $true; Before = 'ready/accepting/none/success'; After = 'offline/accepting/none/danger'; FlipAfterSeconds = 151; Seconds = 190; Output = @() }
        }
        $result = Invoke-WithPausedQaWorkers { New-PassResult 'visual body passed' @('harness line') }
        $result.class | Should -Be 'infrastructure_error'
        $result.detail | Should -Match 'kippte nach 151 s der 190 s langen Worker-Pause von ready/accepting/none/success auf offline/accepting/none/danger'
        $result.detail | Should -Match 'Body-Urteil nicht verwertbar \(pass: visual body passed\)'
        @($result.output) | Should -Contain 'harness line'
        Should -Invoke Invoke-QaCompose -Times 1 -ParameterFilter { $Arguments[0] -eq 'up' }
    }

    It 'does not let a fail after a flip read as a baseline regression' {
        Mock Get-QaDeployServiceDrift {
            @{ Ok = $true; Before = 'ready/a/n/s'; After = 'offline/a/n/d'; FlipAfterSeconds = 140; Seconds = 200; Output = @() }
        }
        $result = Invoke-WithPausedQaWorkers { New-FailResult 'Visual-Harness meldet Abweichung' }
        $result.class | Should -Be 'infrastructure_error'
        $result.detail | Should -Match 'Body-Urteil nicht verwertbar \(fail: Visual-Harness meldet Abweichung\)'
    }

    It 'fails closed when the service state cannot be read' {
        Mock Get-QaDeployServiceDrift { @{ Ok = $false; Output = @('php error') } }
        $result = Invoke-WithPausedQaWorkers { New-PassResult 'visual body passed' }
        $result.class | Should -Be 'infrastructure_error'
        $result.detail | Should -Match 'nicht pruefbar'
        @($result.output) | Should -Contain 'php error'
    }

    It 'keeps a body infrastructure_error as is without probing' {
        $result = Invoke-WithPausedQaWorkers { New-InfraResult 'QA-Stack laeuft nicht' }
        $result.detail | Should -Be 'QA-Stack laeuft nicht'
        Should -Invoke Get-QaDeployServiceDrift -Times 0
    }

    It 'measures the window from before the stop through the body' {
        $result = Invoke-WithPausedQaWorkers { Start-Sleep -Milliseconds 1100; New-PassResult }
        $result.class | Should -Be 'pass'
        Should -Invoke Get-QaDeployServiceDrift -Times 1 -ParameterFilter { $Seconds -ge 2 }
    }
}

Describe 'Deploy service drift probe' {
    BeforeEach {
        $script:qaPhpContainer = 'virtusphere-qa-php-1'
    }

    It 'asks the product snapshot for every second of the window without the portal bootstrap' {
        Mock Invoke-Tool { @{ ExitCode = 0; Output = @('RH1DRIFT{"before":"ready/accepting/none/success","after":"ready/accepting/none/success","flip":null}RH1DRIFT') } }
        $drift = Get-QaDeployServiceDrift -Seconds 187
        $drift.Ok | Should -BeTrue
        $drift.FlipAfterSeconds | Should -BeNullOrEmpty
        Should -Invoke Invoke-Tool -Times 1 -ParameterFilter {
            $Exe -eq 'docker' -and $Arguments[0] -eq 'exec' -and $Arguments[1] -eq 'virtusphere-qa-php-1' -and
            $Arguments[-1] -match 'deploy_service_health_snapshot\(\$db, \$t\)' -and
            $Arguments[-1] -match '\$from = \$to - 187;' -and
            $Arguments[-1] -notmatch 'bootstrap\.php' -and
            $Arguments[-1] -notmatch '"'
        }
    }

    It 'reports the second at which the rendered state first differs' {
        Mock Invoke-Tool { @{ ExitCode = 0; Output = @('noise', 'RH1DRIFT{"before":"ready/accepting/none/success","after":"offline/accepting/none/danger","flip":151}RH1DRIFT') } }
        $drift = Get-QaDeployServiceDrift -Seconds 190
        $drift.Ok | Should -BeTrue
        $drift.FlipAfterSeconds | Should -Be 151
        $drift.After | Should -Be 'offline/accepting/none/danger'
    }

    It 'is not ok when the probe fails or prints no result' {
        Mock Invoke-Tool { @{ ExitCode = 255; Output = @('PHP Fatal error') } }
        (Get-QaDeployServiceDrift -Seconds 10).Ok | Should -BeFalse
        Mock Invoke-Tool { @{ ExitCode = 0; Output = @('kein Ergebnis') } }
        (Get-QaDeployServiceDrift -Seconds 10).Ok | Should -BeFalse
    }
}

Describe 'Visual QA worker Compose identity contract' {
    BeforeEach {
        $script:qaProject = 'virtusphere-qa'
        Mock Invoke-Tool {
            param($Exe, $Arguments)
            $service = if ($Arguments[-1] -match 'maintenance-worker') { 'maintenance-worker' } else { 'deploy-worker' }
            return @{
                ExitCode = 0
                Output = @('{"com.docker.compose.project":"virtusphere-qa","com.docker.compose.service":"' + $service + '"}|true')
            }
        }
    }

    It 'accepts exact project and service labels returned as JSON' {
        $snapshot = Get-QaWorkerSnapshot
        $snapshot.Ok | Should -BeTrue
        @($snapshot.States).Count | Should -Be 2
        @($snapshot.States | Where-Object { $_.Running }).Count | Should -Be 2
    }

    It 'rejects a shared project label before any pause can be attempted' {
        Mock Invoke-Tool { @{ ExitCode = 0; Output = @('{"com.docker.compose.project":"shared","com.docker.compose.service":"deploy-worker"}|true') } }
        $snapshot = Get-QaWorkerSnapshot
        $snapshot.Ok | Should -BeFalse
        $snapshot.Detail | Should -Match 'Worker-Isolation verweigert'
    }
}
