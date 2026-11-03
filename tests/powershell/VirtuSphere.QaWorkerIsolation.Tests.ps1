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
        Mock Get-QaActiveJobCount { 0 }
        Mock Invoke-QaCompose { @{ ExitCode = 0; Output = @() } }
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
