BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
}

Describe 'Windows PowerShell Generic-List-Kompatibilitaet' {
    It 'materialisiert List-object an den produktiven Grenzen ueber ToArray' {
        $autoimporter = Get-Content -LiteralPath (Join-Path $script:RepoRoot 'Powershell-MECM\mecm\mecm_autoimporter.ps1') -Raw
        $common = Get-Content -LiteralPath (Join-Path $script:RepoRoot 'Powershell-MECM\mecm\VirtuSphere-Common.ps1') -Raw
        $disks = Get-Content -LiteralPath (Join-Path $script:RepoRoot 'Powershell-MECM\clients\Set-VMDisksOnline.ps1') -Raw

        $autoimporter | Should -Match 'foreach \(\$entry in \$packageEntries\.ToArray\(\)\)'
        $autoimporter | Should -Not -Match 'foreach \(\$entry in @\(\$packageEntries\)\)'
        $common | Should -Match '\$items = \$groups\[\$product\]\.ToArray\(\)'
        $common | Should -Not -Match '\$items = @\(\$groups\[\$product\]\)'
        $disks | Should -Match 'return \$result\.ToArray\(\)'
        $disks | Should -Not -Match 'return @\(\$result\)'
    }
}
