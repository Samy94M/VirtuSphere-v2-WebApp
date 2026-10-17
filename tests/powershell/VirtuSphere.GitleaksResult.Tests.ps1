# Auswertung der gitleaks-Ausgabe im Secret-Scan-Gate (scripts/lib/check/gates-release.ps1).
# Ein Lauf zaehlt nur dann als Evidenz, wenn gitleaks Commits gescannt hat.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $checkDir = Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib/check'
    . (Join-Path $checkDir 'gates-release.ps1')
    $script:ReleaseGates = Get-Content -LiteralPath (Join-Path $checkDir 'gates-release.ps1') -Raw
    $script:Esc = [string][char]27
}

Describe 'Get-GitleaksScannedCommitCount' {
    It 'reads the commit count from a plain gitleaks v8 summary line' {
        Get-GitleaksScannedCommitCount @('2026-10-16T10:00:00+02:00 INF 123 commits scanned.') | Should -Be 123
    }

    It 'returns zero for a run that scanned no commits' {
        Get-GitleaksScannedCommitCount @('INF 0 commits scanned.') | Should -Be 0
    }

    It 'tolerates ANSI color codes around the timestamp, level and count' {
        $line = $script:Esc + '[90m2026-10-16T10:00:00+02:00' + $script:Esc + '[0m ' + $script:Esc + '[32mINF' + $script:Esc + '[0m ' + $script:Esc + '[1m4321' + $script:Esc + '[0m commits scanned.'
        Get-GitleaksScannedCommitCount @('INF no leaks found', $line) | Should -Be 4321
    }

    It 'returns null when no commits-scanned line exists' {
        Get-GitleaksScannedCommitCount @('INF no leaks found', 'WRN something else') | Should -BeNullOrEmpty
        Get-GitleaksScannedCommitCount @() | Should -BeNullOrEmpty
    }
}

Describe 'secret-scan gate uses the commit count before it classifies the result' {
    It 'turns a missing or zero commit count into an infrastructure error' {
        $script:ReleaseGates | Should -Match 'Get-GitleaksScannedCommitCount \$r\.Output'
        $script:ReleaseGates | Should -Match '\$null -eq \$scannedCommits -or \$scannedCommits -eq 0'
        $script:ReleaseGates | Should -Match 'New-InfraResult ''gitleaks hat keine Commits gescannt'
    }

    It 'checks the commit count before the pass or fail classification' {
        $gate = $script:ReleaseGates.Substring($script:ReleaseGates.IndexOf("Add-Gate -Name 'secret-scan'"))
        $gate = $gate.Substring(0, $gate.IndexOf("Add-Gate -Name 'sbom'"))
        $gate.IndexOf('Get-GitleaksScannedCommitCount') | Should -BeGreaterThan 0
        $gate.IndexOf('Get-GitleaksScannedCommitCount') | Should -BeLessThan $gate.IndexOf('Format-ToolResult $r')
    }
}
