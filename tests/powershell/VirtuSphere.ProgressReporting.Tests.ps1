# Repository contract for visible progress in long-running checks. This test
# deliberately reads the agent instructions as well as both canonical runners:
# the convention must survive new sessions and future model changes, not only
# today's implementation.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:AgentGuide = Get-Content -LiteralPath (Join-Path $script:RepoRoot 'AGENTS.md') -Raw
    $script:ClaudeGuide = Get-Content -LiteralPath (Join-Path $script:RepoRoot 'CLAUDE.md') -Raw
    $script:QaGuide = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'docs') 'QA.md') -Raw
    $script:CheckRunner = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') 'check.ps1') -Raw
    $script:CheckRuntime = Get-Content -LiteralPath (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib/check') 'runtime.ps1') -Raw
    $script:FastGates = Get-Content -LiteralPath (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib/check') 'gates-fast.ps1') -Raw
    $script:CreateAsyncContract = Get-Content -LiteralPath (Join-Path (Join-Path (Join-Path $script:RepoRoot 'Docker') 'qa-ansible') 'create-async-contract.py') -Raw
    $script:GuardRunner = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') 'test-guards.ps1') -Raw
    $script:PowerShellRunner = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') 'run-pester.ps1') -Raw
    $script:VisualRunner = Get-Content -LiteralPath (Join-Path (Join-Path (Join-Path $script:RepoRoot 'tests') 'e2e/visual') 'harness.js') -Raw
    $script:CollectionLock = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'Docker/qa-ansible') 'verify-collection-lock.py') -Raw
    $script:CollectionLockContract = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'Docker/qa-ansible') 'collection-lock-contract.py') -Raw
    $script:NetworkPreflight = Get-Content -LiteralPath (Join-Path (Join-Path (Join-Path $script:RepoRoot 'Docker') 'WebAPI/lib') 'deploy_worker_network_preflight.php') -Raw
    $script:MecmCommon = Get-Content -LiteralPath (Join-Path (Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'mecm') 'VirtuSphere-Common.ps1') -Raw
    $script:RestoreDrill = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') 'restore_test.sh') -Raw
    $script:BackupRunner = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') 'backup.sh') -Raw
}


Describe 'Visible progress reporting contract' {
    It 'binds future agents and multi-unit runners to the n/total convention' {
        $script:AgentGuide | Should -Match 'Progress reporting is a repository contract'
        $script:AgentGuide | Should -Match '\[n/total\] RUN'
        $script:AgentGuide | Should -Match 'buffered'
        $script:AgentGuide | Should -Match '\[0/total\]'
        $script:AgentGuide | Should -Match 'elapsed-time-only'
        $script:ClaudeGuide | Should -Match 'actual latest `\[n/total\]`'
        $script:QaGuide | Should -Match 'latest real `\[n/total\]`'
        $script:QaGuide | Should -Match '`\[0/total\]`'
    }

    It 'reports every selected check gate before and after execution' {
        $script:CheckRunner | Should -Match '\$gateTotal\s*=\s*\$selected\.Count'
        $script:CheckRunner | Should -Match "'\[\{0\}/\{1\}\] RUN\s+\{2\}'"
        $script:CheckRunner | Should -Match "'\[\{0\}/\{1\}\] \{2\} \{3\}"
    }

    It 'streams the long PowerShell child while preserving its captured artifact' {
        $script:CheckRuntime | Should -Match 'param\(\[string\]\$Exe, \[string\[\]\]\$Arguments = @\(\), \[switch\]\$Live\)'
        $script:CheckRuntime | Should -Match 'if \(\$Live\) \{ Write-Host \$line \}'
        $script:FastGates | Should -Match "run-pester\.ps1'\)\) -Live"
    }

    It 'reports the selected analyzer, Pester suite and historical register units before and after execution' {
        $script:PowerShellRunner | Should -Match '\$unitTotal\s*=\s*0'
        $script:PowerShellRunner | Should -Match 'if \(-not \$SkipAnalyzer\) \{ \$unitTotal\+\+ \}'
        $script:PowerShellRunner | Should -Match 'if \(-not \$SkipTests\) \{\s+\$unitTotal\+\+'
        $script:PowerShellRunner | Should -Match 'if \(\$registerPresent\) \{ \$unitTotal\+\+ \}'
        $script:PowerShellRunner | Should -Match "'\[\{0\}/\{1\}\] RUN\s+\{2\}'"
        ([regex]::Matches($script:PowerShellRunner, "Write-Host \('\[\{0\}/\{1\}\] RUN\s+\{2\}'")).Count | Should -Be 3
        $script:PowerShellRunner | Should -Match '\$unitName\s*=\s*''PSScriptAnalyzer \(Powershell-MECM\)'''
        $script:PowerShellRunner | Should -Match '\$unitName\s*=\s*''Pester \(tests\\powershell\)'''
        $script:PowerShellRunner | Should -Match '\$unitName\s*=\s*''Haertungsregister 2026-08 \(nicht Teil des Gates\)'''
        $script:PowerShellRunner | Should -Match "'\[\{0\}/\{1\}\] pass \{2\}:"
        $script:PowerShellRunner | Should -Match "'\[\{0\}/\{1\}\] fail \{2\}:"
        $script:PowerShellRunner | Should -Match "'\[\{0\}/\{1\}\] open \{2\}:"
        $script:PowerShellRunner | Should -Match "'\[\{0\}/\{1\}\] infrastructure_error \{2\}:"
    }

    It 'shows named historical results and distinguishes discovery failure from an empty register without blocking the gate' {
        $script:PowerShellRunner | Should -Match '\$regConfig\.Output\.Verbosity\s*=\s*''Detailed'''
        $script:PowerShellRunner | Should -Match '\$registerFailedContainers\s*=\s*@\(\$reg\.FailedContainers'
        $script:PowerShellRunner | Should -Match '\$reg\.FailedContainersCount -gt 0 -or \$reg\.FailedBlocksCount -gt 0'
        $script:PowerShellRunner | Should -Match 'Discovery/Containerfehler:'
        $script:PowerShellRunner | Should -Match '\$reg\.TotalCount\s*-eq\s*0'
        $script:PowerShellRunner | Should -Match '0 Tests entdeckt \(nicht blockierend\)'
        $registerSection = $script:PowerShellRunner.Substring($script:PowerShellRunner.IndexOf('# --- Haertungsregister'))
        $registerSection | Should -Not -Match '\$failed\s*=\s*\$true'
    }

    It 'reports and streams every create async production sample' {
        $script:CreateAsyncContract | Should -Match 'for index, \(label, case\) in enumerate\(cases, start=1\)'
        $script:CreateAsyncContract | Should -Match 'f"\[\{index\}/\{len\(cases\)\}\] RUN \{label\}"'
        $script:CreateAsyncContract | Should -Match 'f"\[\{index\}/\{len\(cases\)\}\] PASS \{label\}"'
        $script:CreateAsyncContract | Should -Match 'f"\[\{index\}/\{len\(cases\)\}\] FAIL \{label\}:'
        $script:FastGates | Should -Match "create-async-contract\.py'.+-Live"
    }

    It 'reports every selected guard case before and after execution' {
        $script:GuardRunner | Should -Match '\$caseTotal\s*=\s*\$selected\.Count'
        $script:GuardRunner | Should -Match "'\[\{0\}/\{1\}\] RUN\s+\{2\}'"
        $script:GuardRunner | Should -Match "'\[\{0\}/\{1\}\] proven\s+\{2\}'"
    }

    It 'counts the complete unmutated module fixture as an observable guard case' {
        $script:GuardRunner | Should -Match "Name = 'runner.ansible-module-contract.control'"
        $script:GuardRunner | Should -Match 'New-Fixture \$moduleContractFixtureFiles'
        $script:GuardRunner | Should -Match 'Docker/qa-ansible/verify-collection-lock.py'
        $script:GuardRunner | Should -Match 'Docker/qa-ansible/collection-lock-contract.py'
    }

    It 'reports every visual theme before and after, and every capture inside it' {
        # Die Fortschrittseinheit ist seit Etappe 17 das Theme, nicht der
        # einzelne Playwright-Lauf: wie viele Aufnahmen ein Theme braucht, haengt
        # am Ergebnis (zwei Versuche immer, ein dritter nur fuer noch offene
        # Bilder), und ein Gesamtwert, der nie erreicht wird, liest sich wie ein
        # abgebrochener Lauf. Jede Aufnahme meldet sich weiterhin, als Unterzeile
        # unter ihrem Theme.
        $script:VisualRunner | Should -Match 'const total = contract\.themes\.length'
        $script:VisualRunner | Should -Match '`\[\$\{position\}/\$\{total\}\] RUN visual-'
        $script:VisualRunner | Should -Match '`\[\$\{position\}/\$\{total\}\] \$\{state\} visual-'
        $script:VisualRunner | Should -Match "const state = themes\[theme\]\.unmatched\.length === 0 \? 'pass' : 'fail'"
        $script:VisualRunner | Should -Match '`  capture \$\{label\}'
    }

    It 'reports every effective VM around the worker network preflight' {
        $script:NetworkPreflight | Should -Match '\$progressRows\s*=\s*\$preflight\[''vms''\]'
        $script:NetworkPreflight | Should -Match 'foreach\s*\(\$missingVmIds\s+as\s+\$missingVmId\)'
        $script:NetworkPreflight | Should -Match '\$total\s*=\s*count\(\$progressRows\)'
        $script:NetworkPreflight | Should -Match '\$position.+\$total.+RUN network/WDS preflight'
        $script:NetworkPreflight | Should -Match '\$position.+\$total.+OK network/WDS preflight'
        $script:NetworkPreflight | Should -Match '\$position.+\$total.+FAIL network/WDS preflight'
    }

    It 'reports every pinned Ansible collection before and after lock verification' {
        $script:CollectionLock | Should -Match '\[\{position\}/\{total\}\] RUN collection-lock-'
        $script:CollectionLock | Should -Match '\[\{position\}/\{total\}\] PASS collection-lock-'
        $script:CollectionLock | Should -Match '\[\{position\}/\{total\}\] FAIL collection-lock-'
        $script:CollectionLockContract | Should -Match '\[\{position\}/\{total\}\] RUN collection-lock-contract-'
        $script:CollectionLockContract | Should -Match '\[\{position\}/\{total\}\] PASS collection-lock-contract-'
        $script:CollectionLockContract | Should -Match '\[\{position\}/\{total\}\] FAIL collection-lock-contract-'
    }

    It 'keeps the portal closure probes inside the counted guard harness' {
        $script:GuardRunner | Should -Match "Name = 'portal-require-closure.probes'"
        $script:GuardRunner | Should -Match '--fail-on-empty-test-suite'
        $script:GuardRunner | Should -Match 'Zero entrypoints are a contract error'
        (Get-Content -Raw (Join-Path $script:RepoRoot 'scripts/lib/check/gates-integration.ps1')) | Should -Match "test-guards\.ps1'\)\) -Live"
    }

    It 'reports every restore and backup phase before and after execution' {
        $script:RestoreDrill | Should -Match 'PROGRESS_TOTAL=9'
        $script:RestoreDrill | Should -Match 'echo "\[\$progress_position/\$PROGRESS_TOTAL\] RUN \$progress_unit"'
        ([regex]::Matches($script:RestoreDrill, 'progress_run [1-9] ')).Count | Should -Be 9
        $script:RestoreDrill | Should -Match 'progress_result (pass|fail|infrastructure_error)'
        $script:RestoreDrill | Should -Match 'progress_run 9 cleanup'
        $script:BackupRunner | Should -Match 'BACKUP_PROGRESS_TOTAL=4'
        $script:BackupRunner | Should -Match 'echo "\[\$backup_progress_position/\$BACKUP_PROGRESS_TOTAL\] RUN \$backup_progress_unit"'
        ([regex]::Matches($script:BackupRunner, 'backup_progress_run [1-4] ')).Count | Should -Be 4
        $script:BackupRunner | Should -Match 'backup_progress_result (pass|fail)'
    }

    It 'reports every explicitly approved MECM cleanup unit without polluting its result stream' {
        $script:MecmCommon | Should -Match '\$total\s*=\s*@\(\$CurrentPlan\.Items\)\.Count'
        $script:MecmCommon | Should -Match '\[\{0\}/\{1\}\] RUN cleanup'
        $script:MecmCommon | Should -Match '\[\{0\}/\{1\}\] pass cleanup'
        $script:MecmCommon | Should -Match '\[\{0\}/\{1\}\] fail cleanup'
        $script:MecmCommon | Should -Not -Match 'Write-Output\s+\("\[\{0\}/\{1\}\].*cleanup'
    }
}
