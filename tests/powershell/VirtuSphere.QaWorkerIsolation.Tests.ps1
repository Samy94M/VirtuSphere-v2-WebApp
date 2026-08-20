# Contract for deterministic database ownership during the full integration
# suite. PHPUnit creates synthetic deploy states that real worker loops must not
# claim, reap or converge while the assertions still own them.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $runner = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') 'check.ps1') -Raw
    $match = [regex]::Match(
        $runner,
        "Add-Gate -Name 'phpunit-full'[\s\S]*?(?=Add-Gate -Name 'schema-convergence')"
    )
    if (-not $match.Success) {
        throw 'phpunit-full gate block not found'
    }
    $script:Gate = $match.Value
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
