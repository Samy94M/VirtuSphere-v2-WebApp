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

Describe 'run artifact directory' {
    BeforeAll {
        # Calls New-CheckArtifactDirectory from the module in a child scope, the
        # same way the Get-CheckFiles case above dot-sources runtime.ps1.
        function Invoke-ArtifactDirectoryCase {
            param([string]$Root, [string]$Stamp)
            return @(& {
                param($module, $root, $stamp)
                . $module
                New-CheckArtifactDirectory -Root $root -Stamp $stamp
            } (Join-Path $script:ModuleDir 'artifact-dir.ps1') $Root $Stamp)
        }
    }

    It 'does not reuse an existing directory with the same stamp' {
        Test-Path -LiteralPath (Join-Path $script:ModuleDir 'artifact-dir.ps1') -PathType Leaf | Should -BeTrue
        $root = Join-Path $TestDrive 'collision-root'
        $stamp = '20261012-120000'
        $occupied = Join-Path $root ('virtusphere-qa-' + $stamp)
        New-Item -ItemType Directory -Path $occupied -Force | Out-Null
        $marker = Join-Path $occupied 'marker.txt'
        Set-Content -LiteralPath $marker -Value 'occupied by another run' -Encoding ascii

        $created = @(Invoke-ArtifactDirectoryCase -Root $root -Stamp $stamp)

        @($created).Count | Should -Be 1
        $created[0] | Should -Not -Be $occupied
        (Split-Path -Leaf $created[0]) | Should -BeLike ('virtusphere-qa-' + $stamp + '-*')
        Test-Path -LiteralPath $created[0] -PathType Container | Should -BeTrue
        @(Get-ChildItem -LiteralPath $created[0] -Force).Count | Should -Be 0
        Get-Content -LiteralPath $marker -Raw | Should -Match 'occupied by another run'
    }

    It 'gives two runs in the same second distinct, existing directories' {
        Test-Path -LiteralPath (Join-Path $script:ModuleDir 'artifact-dir.ps1') -PathType Leaf | Should -BeTrue
        $root = Join-Path $TestDrive 'same-second-root'
        New-Item -ItemType Directory -Path $root -Force | Out-Null
        $stamp = '20261012-120000'

        $first = @(Invoke-ArtifactDirectoryCase -Root $root -Stamp $stamp)
        $second = @(Invoke-ArtifactDirectoryCase -Root $root -Stamp $stamp)

        @($first).Count | Should -Be 1
        @($second).Count | Should -Be 1
        $second[0] | Should -Not -Be $first[0]
        foreach ($dir in @($first[0], $second[0])) {
            Test-Path -LiteralPath $dir -PathType Container | Should -BeTrue
            (Split-Path -Leaf $dir) | Should -BeLike ('virtusphere-qa-' + $stamp + '-*')
            Split-Path -Parent $dir | Should -Be $root
        }
    }

    It 'has check.ps1 delegate the directory name to the module instead of forming it' {
        $lines = @(Get-Content -LiteralPath $script:RunnerPath)
        @($lines | Where-Object { $_ -match "'virtusphere-qa-'\s*\+" }) | Should -BeNullOrEmpty
        @($lines | Where-Object { $_ -match 'New-Item.*-Force.*\$artifactDir' }) | Should -BeNullOrEmpty
        @($lines | Where-Object { $_ -match "'artifact-dir\.ps1'" }).Count | Should -BeGreaterThan 0
        @($lines | Where-Object { $_ -match '\$artifactDir\s*=\s*New-CheckArtifactDirectory\b.*-Stamp\s+\$runStamp' }).Count | Should -BeGreaterThan 0
        Test-Path -LiteralPath (Join-Path $script:ModuleDir 'artifact-dir.ps1') -PathType Leaf | Should -BeTrue
    }
}

Describe 'artifact directory prefixes for the other runners' {
    BeforeAll {
        function Invoke-PrefixedArtifactDirectoryCase {
            param([string]$Root, [string]$Stamp, [string]$Prefix)
            return @(& {
                param($module, $root, $stamp, $prefix)
                . $module
                if ($prefix) { New-CheckArtifactDirectory -Root $root -Stamp $stamp -Prefix $prefix }
                else { New-CheckArtifactDirectory -Root $root -Stamp $stamp }
            } (Join-Path $script:ModuleDir 'artifact-dir.ps1') $Root $Stamp $Prefix)
        }
    }

    It 'gives two guard runs with the same stamp distinct, existing, empty directories under their prefix' {
        $root = Join-Path $TestDrive 'guards-same-second'
        New-Item -ItemType Directory -Path $root -Force | Out-Null
        $stamp = '20261012-120000'
        $prefix = 'virtusphere-guards-'

        $first = @(Invoke-PrefixedArtifactDirectoryCase -Root $root -Stamp $stamp -Prefix $prefix)
        $second = @(Invoke-PrefixedArtifactDirectoryCase -Root $root -Stamp $stamp -Prefix $prefix)

        @($first).Count | Should -Be 1
        @($second).Count | Should -Be 1
        $second[0] | Should -Not -Be $first[0]
        foreach ($dir in @($first[0], $second[0])) {
            Test-Path -LiteralPath $dir -PathType Container | Should -BeTrue
            @(Get-ChildItem -LiteralPath $dir -Force).Count | Should -Be 0
            (Split-Path -Leaf $dir) | Should -BeLike ($prefix + $stamp + '-*')
            Split-Path -Parent $dir | Should -Be $root
        }
    }

    It 'keeps the virtusphere-qa- name when no prefix is given' {
        $root = Join-Path $TestDrive 'qa-default-prefix'
        New-Item -ItemType Directory -Path $root -Force | Out-Null
        $stamp = '20261012-120000'

        $created = @(Invoke-PrefixedArtifactDirectoryCase -Root $root -Stamp $stamp -Prefix '')

        @($created).Count | Should -Be 1
        (Split-Path -Leaf $created[0]) | Should -BeLike ('virtusphere-qa-' + $stamp + '-*')
        Test-Path -LiteralPath $created[0] -PathType Container | Should -BeTrue
    }
}

Describe 'schema convergence from a foreign working directory' {
    BeforeAll {
        # Same host-sh lookup as the restore-root regression: a missing sh fails here.
        $script:ConvergenceShExe = & { param($module) . $module; Find-Sh } (Join-Path $script:ModuleDir 'runtime.ps1')
        if (-not $script:ConvergenceShExe) { throw 'sh is required for the schema-convergence cwd regression' }
    }

    It 'loads struktur.sql from the repo root when started from a foreign working directory' {
        $scriptPath = (Join-Path $script:RepoRoot 'scripts/check-schema-convergence.sh') -replace '\\', '/'
        $structure = Join-Path $script:RepoRoot 'Docker/mysql/mysql-init/struktur.sql'
        $stubDir = Join-Path $TestDrive 'convergence-bin'
        $foreignCwd = Join-Path $TestDrive 'foreign-cwd'
        $stdinLog = Join-Path $TestDrive 'convergence-struktur-stdin.bin'
        New-Item -ItemType Directory -Path $stubDir, $foreignCwd -Force | Out-Null

        # Test double for the docker CLI: records the stdin of the fresh-schema
        # import (mysql ... vs_conv_struktur without -e) and answers every call
        # with 0. It never reaches a Docker engine.
        $stubLines = @(
            '#!/bin/sh',
            'for arg in "$@"; do',
            '  if [ "$arg" = "-e" ]; then exit 0; fi',
            'done',
            'case "$*" in',
            '  *" mysql "*" vs_conv_struktur") cat > "$VS_STUB_STDIN_LOG" ;;',
            'esac',
            'exit 0'
        )
        $stub = Join-Path $stubDir 'docker'
        [IO.File]::WriteAllText($stub, (($stubLines -join "`n") + "`n"), [Text.UTF8Encoding]::new($false))
        if ($env:OS -ne 'Windows_NT') {
            & chmod '+x' $stub
            if ($LASTEXITCODE -ne 0) { throw 'convergence docker stub could not be made executable' }
        }

        $oldPath = $env:PATH
        $oldPassword = $env:MYSQL_ROOT_PASSWORD
        $oldStubLog = $env:VS_STUB_STDIN_LOG
        $oldLocation = (Get-Location).Path
        # The script reports its BLOCK lines on stderr. Under a caller that uses
        # ErrorActionPreference Stop, each native stderr line would become a
        # RemoteException before the assertions run, so the call is wrapped like
        # Invoke-Tool does: Continue locally, every line collected as a string,
        # the preference restored in finally. No exit-code assertion: the stub
        # answers no PHP call, so the run ends in a later, expected BLOCK.
        $oldErrorAction = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'
        try {
            $env:PATH = $stubDir + [IO.Path]::PathSeparator + (Split-Path $script:ConvergenceShExe -Parent) + [IO.Path]::PathSeparator + $oldPath
            $env:MYSQL_ROOT_PASSWORD = 'convergence-test-only'
            $env:VS_STUB_STDIN_LOG = $stdinLog -replace '\\', '/'
            Set-Location -LiteralPath $foreignCwd
            $output = @(& $script:ConvergenceShExe $scriptPath 2>&1 | ForEach-Object { "$_" })
        } finally {
            Set-Location -LiteralPath $oldLocation
            $env:PATH = $oldPath
            $env:MYSQL_ROOT_PASSWORD = $oldPassword
            $env:VS_STUB_STDIN_LOG = $oldStubLog
            $ErrorActionPreference = $oldErrorAction
        }

        ($output -join "`n") | Should -Not -Match '\[schema-convergence\.fresh-load\]'
        Test-Path -LiteralPath $stdinLog -PathType Leaf | Should -BeTrue
        $expected = [IO.File]::ReadAllBytes($structure)
        $actual = [IO.File]::ReadAllBytes($stdinLog)
        $head = [Math]::Min(200, $expected.Length)
        $actual.Length | Should -BeGreaterOrEqual $head
        [Convert]::ToBase64String($actual, 0, $head) | Should -Be ([Convert]::ToBase64String($expected, 0, $head))
    }
}

Describe 'stamp naming and encoding guards over scripts/' {
    BeforeAll {
        # A directory name built from a text prefix and a second-precision stamp
        # collides when two runs start in the same second. Only artifact-dir.ps1
        # may form such a name (New-CheckArtifactDirectory).
        $script:StampNamePattern = '[''"][^''"]*-[''"]\s*\+\s*(?:\(\s*Get-Date\s+-Format\s+[''"]yyyyMMdd-HHmmss[''"]\s*\)|\$runStamp\b)|[''"][^''"]*-\$(?:runStamp\b|\(\s*Get-Date\s+-Format\s+[''"]yyyyMMdd-HHmmss[''"]\s*\))'
        $script:ScriptFiles = @(Get-ChildItem -LiteralPath (Join-Path $script:RepoRoot 'scripts') -Filter '*.ps1' -File -Recurse)
    }

    It 'matches the stamp naming pattern on known cases and not on the stamp itself' {
        $positive = @(
            '$d = Join-Path $root (''virtusphere-guards-'' + (Get-Date -Format ''yyyyMMdd-HHmmss''))',
            '$d = Join-Path $root (''visual-baselines-'' + $runStamp)',
            '$d = "visual-baseline-review-$(Get-Date -Format ''yyyyMMdd-HHmmss'')"'
        )
        $negative = @(
            '$runStamp = Get-Date -Format ''yyyyMMdd-HHmmss''',
            '$artifactDir = New-CheckArtifactDirectory -Root ([System.IO.Path]::GetTempPath()) -Stamp $runStamp'
        )
        foreach ($line in $positive) { $line | Should -Match $script:StampNamePattern }
        foreach ($line in $negative) { $line | Should -Not -Match $script:StampNamePattern }
    }

    It 'keeps prefix-plus-stamp directory names out of scripts/ except in artifact-dir.ps1' {
        $own = Join-Path $script:ModuleDir 'artifact-dir.ps1'
        $files = @($script:ScriptFiles | Where-Object { $_.FullName -ne $own })
        $files.Count | Should -BeGreaterThan 0
        $hits = @()
        foreach ($file in $files) {
            $relative = $file.FullName.Substring($script:RepoRoot.Length).TrimStart('\', '/')
            $lineNumber = 0
            foreach ($text in @(Get-Content -LiteralPath $file.FullName)) {
                $lineNumber++
                if ($text -match $script:StampNamePattern) { $hits += ('{0}:{1}: {2}' -f $relative, $lineNumber, $text.Trim()) }
            }
        }
        if ($hits.Count -gt 0) {
            throw ('Verzeichnisname aus Praefix und Sekundenstempel ausserhalb von artifact-dir.ps1 (New-CheckArtifactDirectory verwenden): ' + ($hits -join ' | '))
        }
    }

    It 'keeps non-ASCII bytes in scripts/ and tests/powershell only behind a UTF-8 BOM' {
        $hits = @()
        $latin1 = [Text.Encoding]::GetEncoding(28591)
        foreach ($root in @('scripts', (Join-Path 'tests' 'powershell'))) {
            $files = @(Get-ChildItem -LiteralPath (Join-Path $script:RepoRoot $root) -Recurse -File |
                Where-Object { $_.Extension -in '.ps1', '.psm1', '.psd1' })
            $files.Count | Should -BeGreaterThan 0
            foreach ($file in $files) {
                $bytes = [IO.File]::ReadAllBytes($file.FullName)
                if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) { continue }
                # Latin-1 maps each byte to exactly one char, so the first char >= 0x80 is the first byte >= 0x80.
                $text = $latin1.GetString($bytes)
                $match = [regex]::Match($text, '[\x80-\xFF]')
                if ($match.Success) {
                    $relative = $file.FullName.Substring($script:RepoRoot.Length).TrimStart('\', '/')
                    $lineNumber = 1 + [regex]::Matches($text.Substring(0, $match.Index), '\n').Count
                    $hits += ('{0}:{1}: Nicht-ASCII-Byte ohne UTF-8-BOM' -f $relative, $lineNumber)
                }
            }
        }
        if ($hits.Count -gt 0) {
            throw ('Nicht-ASCII in BOM-losen Skripten (PS 5.1 liest sie als ANSI): ' + ($hits -join ' | '))
        }
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

Describe 'QA stack teardown carries the tools profile (BK-N5)' {
    BeforeAll {
        . (Join-Path $script:ModuleDir 'qa-identity.ps1')
        . (Join-Path $script:ModuleDir 'runtime.ps1')

        # The teardown branch is taken from the shipped runner, not re-typed here:
        # the if-statement in check.ps1's finally block that tests $script:qaStackStarted.
        $tokens = $null
        $errors = $null
        $runnerAst = [System.Management.Automation.Language.Parser]::ParseFile($script:RunnerPath, [ref]$tokens, [ref]$errors)
        $errors | Should -BeNullOrEmpty
        $finallyBlocks = @($runnerAst.FindAll({
            param($node)
            $node -is [System.Management.Automation.Language.TryStatementAst] -and
                $null -ne $node.Finally -and $node.Finally.Extent.Text -match 'QA-Stack bleibt stehen'
        }, $true))
        $finallyBlocks.Count | Should -Be 1
        $teardownIf = @($finallyBlocks[0].Finally.Statements | Where-Object {
            $_ -is [System.Management.Automation.Language.IfStatementAst] -and
                $_.Clauses[0].Item1.Extent.Text -eq '$script:qaStackStarted'
        })
        $teardownIf.Count | Should -Be 1
        $script:TeardownBranch = [scriptblock]::Create($teardownIf[0].Extent.Text)

        function Invoke-QaTeardownCase {
            param([bool]$KeepArtifacts)
            # Synthetic root: only the identity values matter, no Docker is touched.
            $repoRoot = 'C:/synthetic/qa-root'
            $identity = Get-QaStackIdentity $repoRoot
            $qaProject = $identity.Project
            $qaEnvFile = $identity.EnvFile
            $qaComposeOverride = $identity.ComposeOverride
            $script:qaStackStarted = $true

            # Everything Invoke-QaCompose puts in front of a subcommand (runtime.ps1).
            $script:DockerArguments = @()
            $null = Invoke-QaCompose @()
            $base = @($script:DockerArguments)

            $script:DockerArguments = @()
            $output = @(& $script:TeardownBranch 6>&1 | ForEach-Object { "$_" })
            return @{ Base = $base; Output = $output; Docker = @($script:DockerArguments) }
        }
    }

    BeforeEach {
        Mock Invoke-Tool {
            param($Exe, $Arguments)
            $script:DockerArguments = @($Arguments)
            return @{ ExitCode = 0; Output = @() }
        }
    }

    It 'tears the QA project down with the tools profile before the subcommand' {
        $case = Invoke-QaTeardownCase -KeepArtifacts $false
        $case.Docker | Should -Be (@($case.Base) + @('--profile', 'tools', 'down', '-v', '--remove-orphans'))
    }

    It 'prints the -KeepArtifacts teardown command with the same project, env file, compose files and tools profile' {
        $case = Invoke-QaTeardownCase -KeepArtifacts $true
        $case.Docker.Count | Should -Be 0
        $expected = 'docker ' + ((@($case.Base) + @('--profile', 'tools', 'down', '-v', '--remove-orphans')) -join ' ')
        ($case.Output -join "`n") | Should -Match ([regex]::Escape($expected))
    }
}
