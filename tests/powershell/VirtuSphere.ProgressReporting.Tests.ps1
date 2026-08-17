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
    $script:GuardRunner = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') 'test-guards.ps1') -Raw
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

    It 'reports every selected guard case before and after execution' {
        $script:GuardRunner | Should -Match '\$caseTotal\s*=\s*\$selected\.Count'
        $script:GuardRunner | Should -Match "'\[\{0\}/\{1\}\] RUN\s+\{2\}'"
        $script:GuardRunner | Should -Match "'\[\{0\}/\{1\}\] proven\s+\{2\}'"
    }
}
