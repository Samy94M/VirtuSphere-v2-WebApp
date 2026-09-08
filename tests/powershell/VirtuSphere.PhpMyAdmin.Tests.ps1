BeforeAll {
    $root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    . (Join-Path $root 'scripts/lib/check/phpmyadmin.ps1')
}

Describe 'phpMyAdmin authenticated response contract' {
    It 'rejects the original HTTP-200 socket error page' {
        Test-PhpMyAdminLoginContent '<input name="pma_password"><div>mysqli::real_connect(): No such file or directory</div>' | Should -BeFalse
    }
    It 'requires both authenticated navigation and TCP connection evidence' {
        Test-PhpMyAdminLoginContent '<a href="index.php?route=/logout">Logout</a> mysql via TCP/IP' | Should -BeTrue
        Test-PhpMyAdminLoginContent '<a href="index.php?route=/logout">Logout</a> localhost via UNIX socket' | Should -BeFalse
        Test-PhpMyAdminLoginContent 'mysql via TCP/IP <input name="pma_password">' | Should -BeFalse
    }
    It 'fails closed for empty output' {
        Test-PhpMyAdminLoginContent '' | Should -BeFalse
    }
}
