# Contract for release-grade provenance in the canonical Release lane.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:CheckRunner = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') 'check.ps1') -Raw
}

Describe 'Release bundle gate contract' {
    It 'builds the offline bundle in release mode' {
        $requiredCall = "Invoke-CheckShell 'build-offline-bundle.sh' @('--release', `$bundleDir)"
        $script:CheckRunner | Should -Match ([regex]::Escape($requiredCall))
    }
}
