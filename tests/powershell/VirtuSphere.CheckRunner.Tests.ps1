# Golden and negative contracts for the modular canonical runner (Etappe 11).

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:RunnerPath = Join-Path (Join-Path $script:RepoRoot 'scripts') 'check.ps1'
    $script:ModuleDir = Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'check'
    $script:Golden = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'fixtures/check-runner-golden.json') -Raw | ConvertFrom-Json
    # The runner under test starts on the same engine as this suite: Windows
    # PowerShell 5.1 in its CI job, pwsh on the Linux Fast lane (no 'powershell').
    $script:EngineExe = (Get-Process -Id $PID).Path

    function Invoke-CheckRunnerCase {
        param([string[]]$Arguments)
        $output = @(& $script:EngineExe -NoProfile -ExecutionPolicy Bypass -File $script:RunnerPath @Arguments 2>&1 | ForEach-Object { "$_" })
        return @{ ExitCode = $LASTEXITCODE; Output = $output }
    }

    function Get-ListedGateNames {
        param([string]$Lane)
        $result = Invoke-CheckRunnerCase @('-Lane', $Lane, '-List')
        if ($result.ExitCode -ne 0) { throw "runner list failed for $Lane" }
        return @($result.Output | ForEach-Object { if ($_ -match '^  (\S+)\s+') { $Matches[1] } })
    }
}

Describe 'check.ps1 golden surface' {
    It 'keeps the public parameter surface and comment help complete' {
        $tokens = $null
        $errors = $null
        $ast = [System.Management.Automation.Language.Parser]::ParseFile($script:RunnerPath, [ref]$tokens, [ref]$errors)
        $errors | Should -BeNullOrEmpty
        @($ast.ParamBlock.Parameters.Name.VariablePath.UserPath) | Should -Be @($script:Golden.parameters)

        $help = @(& $script:EngineExe -NoProfile -Command "Get-Help '$script:RunnerPath' -Full | Out-String -Width 240" 2>&1) -join "`n"
        $help | Should -Match 'check\.ps1'
        foreach ($parameter in $script:Golden.parameters) { $help | Should -Match ([regex]::Escape('-' + $parameter)) }
    }

    It 'keeps every lane catalog in exact order' {
        foreach ($lane in @('Fast', 'Integration', 'Release')) {
            Get-ListedGateNames $lane | Should -Be @($script:Golden.lanes.$lane)
        }
    }

    It 'keeps invalid lane and gate calls on exit 3 with stable diagnostics' {
        $lane = Invoke-CheckRunnerCase @('-Lane', 'Bogus')
        $lane.ExitCode | Should -Be 3
        ($lane.Output -join "`n") | Should -Be $script:Golden.invalidLane

        $gate = Invoke-CheckRunnerCase @('-Gate', 'does-not-exist')
        $gate.ExitCode | Should -Be 3
        ($gate.Output -join "`n") | Should -Be $script:Golden.invalidGate
    }

    It 'keeps Fast selection independent of comma or array spelling' {
        $comma = Invoke-CheckRunnerCase @('-Lane', 'Fast', '-Gate', 'js-syntax,powershell-syntax', '-List')
        $comma.ExitCode | Should -Be 0
        @($comma.Output | ForEach-Object { if ($_ -match '^  (\S+)\s+') { $Matches[1] } }) | Should -Be @('js-syntax', 'powershell-syntax')
    }

    It 'keeps the JSON schema while running through the modular entry point' {
        $json = Join-Path ([System.IO.Path]::GetTempPath()) ('virtusphere-check-golden-' + [guid]::NewGuid().ToString('N') + '.json')
        try {
            $result = Invoke-CheckRunnerCase @('-Lane', 'Fast', '-Gate', 'js-syntax', '-Json', $json)
            $result.ExitCode | Should -Be 0
            $artifact = Get-Content -LiteralPath $json -Raw | ConvertFrom-Json
            @($artifact.PSObject.Properties.Name | Sort-Object) | Should -Be @($script:Golden.jsonTopLevel)
            @($artifact.results[0].PSObject.Properties.Name | Sort-Object) | Should -Be @($script:Golden.jsonResult)
            @($artifact.summary.PSObject.Properties.Name | Sort-Object) | Should -Be @($script:Golden.jsonSummary)
            $artifact.results[0].name | Should -Be 'js-syntax'
        } finally {
            Remove-Item -LiteralPath $json -Force -ErrorAction SilentlyContinue
        }
    }

    It 'makes a catalog mutation observably fail the golden comparison' {
        $mutant = @($script:Golden.lanes.Fast | Select-Object -Skip 1)
        (Compare-Object -ReferenceObject @($script:Golden.lanes.Fast) -DifferenceObject $mutant) | Should -Not -BeNullOrEmpty
    }
}

Describe 'dot-sourced runner modules' {
    It 'contain only function definitions at import scope and resolve no caller working directory' {
        $modules = @(Get-ChildItem -LiteralPath $script:ModuleDir -Filter '*.ps1' -File)
        $modules.Count | Should -BeGreaterThan 0
        foreach ($module in $modules) {
            $tokens = $null
            $errors = $null
            $ast = [System.Management.Automation.Language.Parser]::ParseFile($module.FullName, [ref]$tokens, [ref]$errors)
            $errors | Should -BeNullOrEmpty
            @($ast.EndBlock.Statements | Where-Object { $_ -isnot [System.Management.Automation.Language.FunctionDefinitionAst] }) | Should -BeNullOrEmpty
            $source = Get-Content -LiteralPath $module.FullName -Raw
            $source | Should -Not -Match 'Get-Location|\[Environment\]::CurrentDirectory|C:\\Users\\'
        }
    }

    It 'emit no output and mutate neither environment nor working directory on import' {
        foreach ($module in (Get-ChildItem -LiteralPath $script:ModuleDir -Filter '*.ps1' -File)) {
            $beforeLocation = (Get-Location).Path
            $beforeEnvironment = [Environment]::GetEnvironmentVariables().Count
            $output = @(& { . $module.FullName })
            $output | Should -BeNullOrEmpty
            (Get-Location).Path | Should -Be $beforeLocation
            [Environment]::GetEnvironmentVariables().Count | Should -Be $beforeEnvironment
        }
    }

    It 'Get-CheckFiles schneidet QA-Artefakte frueh ab und behaelt neue echte Quellen' {
        $runtime = Join-Path $script:ModuleDir 'runtime.ps1'
        $fixture = Join-Path $TestDrive 'scan-root'
        $sourceDir = Join-Path $fixture 'source'
        $artifactDir = Join-Path $fixture 'qa-artifacts\copy'
        New-Item -ItemType Directory -Path $sourceDir -Force | Out-Null
        New-Item -ItemType Directory -Path $artifactDir -Force | Out-Null
        Set-Content -LiteralPath (Join-Path $sourceDir 'new-untracked.ps1') -Value 'function Test-NewSource { return 1 }'
        Set-Content -LiteralPath (Join-Path $artifactDir 'broken-copy.ps1') -Value 'this is { broken'

        $files = & {
            param($module, $root)
            . $module
            $repoRoot = $root
            Get-CheckFiles @('*.ps1')
        } $runtime $fixture

        @($files).Count | Should -Be 1
        @($files)[0] | Should -Be (Join-Path $sourceDir 'new-untracked.ps1')
    }
}

Describe 'JavaScript visual contracts' {
    It 'makes runner and Playwright consume the same browser resolver' {
        $runtime = Get-Content -LiteralPath (Join-Path $script:ModuleDir 'runtime.ps1') -Raw
        $config = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'tests/e2e') 'playwright.config.js') -Raw
        $runtime | Should -Match "tests'.*'e2e'.*'lib/browser-resolver\.js"
        $config | Should -Match "require\('./lib/browser-resolver'\)"
        $runtime | Should -Not -Match 'Get-ChildItem.*ms-playwright|chromium-\d+|C:\\Users\\'
        $config | Should -Not -Match 'chromium-\d+|C:\\Users\\'
    }

    It 'run their resolver, metadata, pixel and seed cases in the Integration gate visual-contract' {
        # The suite needs playwright-core and the lockfile Chromium, and the Fast
        # lane stays browser-free (docs/QA.md). It runs only as the Integration and
        # Release gate visual-contract; this case pins that it really covers the file.
        Test-Path -LiteralPath (Join-Path $script:RepoRoot 'tests/e2e/tests/visual-contract.test.js') -PathType Leaf | Should -BeTrue
        $integration = Get-Content -LiteralPath (Join-Path $script:ModuleDir 'gates-integration.ps1') -Raw
        $integration | Should -Match "Add-Gate -Name 'visual-contract'[^\n]*\n(?:.*\n){0,15}?.*@\('--test', 'tests/\*\.test\.js'\)"
        @($script:Golden.lanes.Integration) | Should -Contain 'visual-contract'
        @($script:Golden.lanes.Fast) | Should -Not -Contain 'visual-contract'
    }
}
