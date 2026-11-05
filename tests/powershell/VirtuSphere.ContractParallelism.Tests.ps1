# QL2: parallel contract cases and parallel php -l. The Ansible contract
# scripts run their cases on a bounded pool, so progress lines interleave; the
# gates must still see every case once with RUN and once with its result, and
# keep every step's output and duration. php-lint-all.sh lints with xargs -P
# and must still check every file and keep its exit codes.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $checkDir = Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib/check'
    . (Join-Path $checkDir 'runtime.ps1')
    . (Join-Path $checkDir 'gates-fast.ps1')
    $script:ShExe = Find-Sh

    function New-InterleavedRun {
        param([int]$Workers = 4)
        @(
            ('contract cases: 3, workers: {0}' -f $Workers),
            '[1/3] RUN alpha',
            '[2/3] RUN beta',
            '[3/3] RUN gamma',
            '[2/3] pass beta',
            '[3/3] pass gamma',
            '[1/3] pass alpha'
        )
    }
}

Describe 'Get-ContractCaseProgress' {
    It 'accepts interleaved lines with non-monotonic results when every case has one RUN and one result' {
        $progress = Get-ContractCaseProgress -Output (New-InterleavedRun) -ResultWord 'pass'
        $progress.Problem | Should -BeNullOrEmpty
        $progress.Total | Should -Be 3
        $progress.Workers | Should -Be 4
    }

    It 'accepts the serial order of one worker' {
        $lines = @('contract cases: 2, workers: 1', '[1/2] RUN a', '[1/2] PASS a', '[2/2] RUN b', '[2/2] PASS b')
        (Get-ContractCaseProgress -Output $lines -ResultWord 'PASS').Problem | Should -BeNullOrEmpty
    }

    It 'rejects a case whose result line is missing' {
        $lines = @(New-InterleavedRun | Where-Object { $_ -ne '[3/3] pass gamma' })
        (Get-ContractCaseProgress -Output $lines -ResultWord 'pass').Problem | Should -Match 'Fall 3/3 ohne RUN- und pass-Zeile'
    }

    It 'rejects a case that never started' {
        $lines = @(New-InterleavedRun | Where-Object { $_ -notmatch 'gamma' })
        (Get-ContractCaseProgress -Output $lines -ResultWord 'pass').Problem | Should -Match 'Fall 3/3 ohne'
    }

    It 'rejects a result before its RUN line' {
        $lines = @('contract cases: 1, workers: 4', '[1/1] pass alpha', '[1/1] RUN alpha')
        (Get-ContractCaseProgress -Output $lines -ResultWord 'pass').Problem | Should -Match 'vor RUN'
    }

    It 'rejects a doubled RUN or result line' {
        $doubleRun = @(New-InterleavedRun) + '[2/3] RUN beta'
        (Get-ContractCaseProgress -Output $doubleRun -ResultWord 'pass').Problem | Should -Match 'zwei RUN-Zeilen'
        $doubleResult = @(New-InterleavedRun) + '[2/3] pass beta'
        (Get-ContractCaseProgress -Output $doubleResult -ResultWord 'pass').Problem | Should -Match 'zwei Ergebniszeilen'
    }

    It 'rejects another result word in a green run and keeps the word case-sensitive' {
        $failed = @(New-InterleavedRun) -replace '^\[3/3\] pass', '[3/3] fail'
        (Get-ContractCaseProgress -Output $failed -ResultWord 'pass').Problem | Should -Match 'endet mit "fail"'
        (Get-ContractCaseProgress -Output (New-InterleavedRun) -ResultWord 'PASS').Problem | Should -Match 'endet mit "pass"'
    }

    It 'rejects a missing or doubled header, an empty run and a foreign total' {
        $noHeader = @(New-InterleavedRun | Select-Object -Skip 1)
        (Get-ContractCaseProgress -Output $noHeader -ResultWord 'pass').Problem | Should -Match '0 Kopfzeilen'
        (Get-ContractCaseProgress -Output (@('contract cases: 3, workers: 1') + (New-InterleavedRun)) -ResultWord 'pass').Problem | Should -Match '2 Kopfzeilen'
        (Get-ContractCaseProgress -Output @('contract cases: 0, workers: 4') -ResultWord 'pass').Problem | Should -Match 'Zero-Match'
        (Get-ContractCaseProgress -Output (@(New-InterleavedRun) + '[4/4] RUN delta') -ResultWord 'pass').Problem | Should -Match 'ausserhalb'
        (Get-ContractCaseProgress -Output @() -ResultWord 'pass').Problem | Should -Match '0 Kopfzeilen'
    }
}

Describe 'Invoke-ContractSteps' {
    BeforeEach {
        $script:Calls = @()
    }

    It 'keeps every step output, names each duration and the case and worker counts' {
        Mock Invoke-Tool {
            $script:Calls += ,@($Arguments)
            if ($Arguments -contains 'fixture.yml') { return @{ ExitCode = 0; Output = @('PLAY RECAP ok=3'); StartFailed = $false } }
            return @{ ExitCode = 0; Output = (New-InterleavedRun -Workers 2); StartFailed = $false }
        }
        $outcome = Invoke-ContractSteps -OkDetail 'alles bewiesen' -Steps @(
            @{ Label = 'Auswahl'; FailDetail = 'Auswahl rot'; Arguments = @('run', 'fixture.yml') },
            @{ Label = 'Ablauf'; FailDetail = 'Ablauf rot'; Live = $true; ResultWord = 'pass'; Arguments = @('run', 'cases.py') }
        )
        $outcome.class | Should -Be 'pass'
        $outcome.detail | Should -Match '^alles bewiesen \[Auswahl \d+(\.\d)?s; Ablauf 3 Faelle \d+(\.\d)?s \(2 Arbeiter\)\]$'
        $outcome.output | Should -Contain 'PLAY RECAP ok=3'
        $outcome.output | Should -Contain '[1/3] pass alpha'
        @($outcome.output | Where-Object { $_ -match '^== (Auswahl|Ablauf): exit 0, \d+(\.\d)?s ==$' }).Count | Should -Be 2
        $script:Calls.Count | Should -Be 2
        Should -Invoke Invoke-Tool -Times 1 -Exactly -ParameterFilter { $Live -and ($Arguments -contains 'cases.py') }
    }

    It 'stops at the first red step and still names the steps that ran' {
        Mock Invoke-Tool {
            $script:Calls += ,@($Arguments)
            if ($Arguments -contains 'cases.py') { return @{ ExitCode = 1; Output = @('[1/3] RUN alpha', '[1/3] fail alpha', 'Traceback'); StartFailed = $false } }
            return @{ ExitCode = 0; Output = @('ok'); StartFailed = $false }
        }
        $outcome = Invoke-ContractSteps -OkDetail 'alles bewiesen' -Steps @(
            @{ Label = 'Auswahl'; FailDetail = 'Auswahl rot'; Arguments = @('run', 'fixture.yml') },
            @{ Label = 'Ablauf'; FailDetail = 'Ablauf rot'; Live = $true; ResultWord = 'pass'; Arguments = @('run', 'cases.py') },
            @{ Label = 'Wiederholung'; FailDetail = 'Wiederholung rot'; Live = $true; ResultWord = 'pass'; Arguments = @('run', 'retry.py') }
        )
        $outcome.class | Should -Be 'fail'
        $outcome.detail | Should -Match '^Ablauf rot \(exit 1\) \[Auswahl \d+(\.\d)?s; Ablauf \d+(\.\d)?s\]$'
        $outcome.output | Should -Contain 'ok'
        $outcome.output | Should -Contain 'Traceback'
        $script:Calls.Count | Should -Be 2
    }

    It 'turns a green step with a swallowed case red' {
        Mock Invoke-Tool {
            return @{ ExitCode = 0; Output = @(New-InterleavedRun | Where-Object { $_ -ne '[2/3] pass beta' }); StartFailed = $false }
        }
        $outcome = Invoke-ContractSteps -OkDetail 'alles bewiesen' -Steps @(
            @{ Label = 'Ablauf'; FailDetail = 'Ablauf rot'; Live = $true; ResultWord = 'pass'; Arguments = @('run', 'cases.py') }
        )
        $outcome.class | Should -Be 'fail'
        $outcome.detail | Should -Be 'Ablauf: Fortschrittszeilen verletzen A22: Fall 2/3 ohne RUN- und pass-Zeile'
    }

    It 'reports a tool that never started as infrastructure_error' {
        Mock Invoke-Tool { return @{ ExitCode = 127; Output = @('docker fehlt'); StartFailed = $true } }
        $outcome = Invoke-ContractSteps -OkDetail 'alles bewiesen' -Steps @(
            @{ Label = 'Ablauf'; FailDetail = 'Ablauf rot'; Live = $true; ResultWord = 'pass'; Arguments = @('run', 'cases.py') }
        )
        $outcome.class | Should -Be 'infrastructure_error'
    }
}

Describe 'php-lint-all.sh in parallel' {
    BeforeAll {
        $script:LintScript = (Join-Path (Join-Path $script:RepoRoot 'scripts') 'php-lint-all.sh') -replace '\\', '/'
        $script:LintBin = Join-Path $TestDrive 'bin'
        $null = New-Item -ItemType Directory -Path $script:LintBin
        # A stand-in php: records every file it was asked to lint, fails like
        # php -l (exit 255) for files named *bad*, and takes a little time.
        $fakePhp = @(
            '#!/bin/sh',
            '[ "$1" = "-l" ] || exit 2',
            'echo "$2" >> "$PHP_LINT_CALLS"',
            'case "$2" in *bad*) echo "PHP Parse error: synthetic in $2" >&2; exit 255;; esac',
            'sleep 0.02',
            'echo "No syntax errors detected in $2"'
        ) -join "`n"
        $phpPath = Join-Path $script:LintBin 'php'
        [IO.File]::WriteAllText($phpPath, $fakePhp + "`n")
        $chmod = @(& $script:ShExe -c 'PATH=/usr/bin:/bin:$PATH; chmod +x "$1" && echo chmod-ok' 'chmod' ($phpPath -replace '\\', '/') 2>&1 | ForEach-Object { "$_" })
        if ($chmod -notcontains 'chmod-ok') { throw ('stand-in php not executable: ' + ($chmod -join ' ')) }

        function New-LintRoot {
            param([string]$Name, [string[]]$Files)
            $root = Join-Path $TestDrive $Name
            foreach ($file in $Files) {
                $path = Join-Path $root $file
                $null = New-Item -ItemType Directory -Force -Path (Split-Path $path -Parent)
                [IO.File]::WriteAllText($path, "<?php`n")
            }
            return $root
        }

        function Invoke-Lint {
            param([string]$Root, [string]$Jobs = '')
            # Windows PowerShell 5.1 exposes native stderr as ErrorRecord, and the
            # runner sets Stop; the broken-file case must reach its exit code.
            $ErrorActionPreference = 'Continue'
            $calls = Join-Path $TestDrive ('calls-' + [guid]::NewGuid().ToString('N'))
            [IO.File]::WriteAllText($calls, '')
            $saved = @{ PATH = $env:PATH; ROOT = $env:VIRTUSPHERE_CHECK_ROOT; JOBS = $env:VIRTUSPHERE_PHP_LINT_JOBS; CALLS = $env:PHP_LINT_CALLS }
            try {
                $env:PATH = $script:LintBin + [IO.Path]::PathSeparator + (Split-Path $script:ShExe -Parent) + [IO.Path]::PathSeparator + $saved.PATH
                $env:VIRTUSPHERE_CHECK_ROOT = $Root -replace '\\', '/'
                $env:VIRTUSPHERE_PHP_LINT_JOBS = $Jobs
                $env:PHP_LINT_CALLS = $calls -replace '\\', '/'
                $output = @(& $script:ShExe $script:LintScript 2>&1 | ForEach-Object { "$_" })
                $code = $LASTEXITCODE
            } finally {
                $env:PATH = $saved.PATH
                $env:VIRTUSPHERE_CHECK_ROOT = $saved.ROOT
                $env:VIRTUSPHERE_PHP_LINT_JOBS = $saved.JOBS
                $env:PHP_LINT_CALLS = $saved.CALLS
            }
            $linted = @(Get-Content -LiteralPath $calls | Where-Object { $_ })
            return @{ ExitCode = $code; Output = $output; Linted = $linted }
        }
    }

    BeforeEach {
        if (-not $script:ShExe) { Set-ItResult -Inconclusive -Because 'kein sh auf diesem Host' }
    }

    It 'starts one php -l per file through a bounded xargs pool' {
        $source = Get-Content -LiteralPath $script:LintScript -Raw
        $source | Should -Match 'jobs="\$\{VIRTUSPHERE_PHP_LINT_JOBS:-8\}"'
        $source | Should -Match 'xargs -0 -n 1 -P "\$jobs" sh -c ''php -l "\$1" >/dev/null \|\| exit 1'''
        $source | Should -Match '123\) rc=1 ;;'
        $source | Should -Match 'echo "linted \$n file\(s\)"'
    }

    It 'lints every first-party file once in parallel and skips vendor' {
        $files = @(1..24 | ForEach-Object { 'Docker/WebAPI/lib/file{0}.php' -f $_ }) + 'scripts/tool.php'
        $root = New-LintRoot -Name 'clean' -Files ($files + 'Docker/WebAPI/vendor/pkg/skip.php')
        $result = Invoke-Lint -Root $root
        $result.ExitCode | Should -Be 0
        $result.Output | Should -Contain 'linted 25 file(s)'
        @($result.Linted | Sort-Object -Unique).Count | Should -Be 25
        $result.Linted.Count | Should -Be 25
        ($result.Linted -join "`n") | Should -Not -Match 'vendor'
    }

    It 'checks every file after a syntax error and ends with exit 1' {
        $root = New-LintRoot -Name 'broken' -Files @('Docker/WebAPI/lib/a.php', 'Docker/WebAPI/lib/bad1.php', 'Docker/WebAPI/lib/b.php', 'scripts/bad2.php', 'scripts/c.php')
        $result = Invoke-Lint -Root $root -Jobs '2'
        $result.ExitCode | Should -Be 1
        $result.Linted.Count | Should -Be 5
        ($result.Output -join "`n") | Should -Match 'synthetic in Docker/WebAPI/lib/bad1\.php'
        ($result.Output -join "`n") | Should -Match 'synthetic in scripts/bad2\.php'
        $result.Output | Should -Contain 'linted 5 file(s)'
    }

    It 'runs one after another with one job' {
        $root = New-LintRoot -Name 'serial' -Files @('Docker/WebAPI/lib/a.php', 'scripts/b.php')
        $result = Invoke-Lint -Root $root -Jobs '1'
        $result.ExitCode | Should -Be 0
        $result.Output | Should -Contain 'linted 2 file(s)'
    }

    It 'refuses an invalid job count and an empty root as exit 9' {
        $root = New-LintRoot -Name 'jobs' -Files @('scripts/a.php')
        foreach ($jobs in @('0', '33', 'many')) {
            $result = Invoke-Lint -Root $root -Jobs $jobs
            $result.ExitCode | Should -Be 9 -Because $jobs
            $result.Linted.Count | Should -Be 0
        }
        $empty = Join-Path $TestDrive 'empty'
        $null = New-Item -ItemType Directory -Force -Path $empty
        $result = Invoke-Lint -Root $empty
        $result.ExitCode | Should -Be 9
        $result.Output | Should -Contain 'no php files found'
    }
}
