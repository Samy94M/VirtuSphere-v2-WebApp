# Pester-Suite fuer die Groessenbegrenzung der Tageslogdateien des Client-Loggings:
# 10 MiB je Datei, Rotation in numerierte Nachbarn `<Tag>_<Komponente>.<N>.log`,
# Retention (30 Tage nach Aenderungszeit) erfasst die Nachbarn wie die Tagesdateien.
# Spiegel von VirtuSphere.LogRotation.Tests.ps1 fuer die Client-Implementation
# (ADR-0029: beide Module teilen keine Datei, nur den Vertrag).
#
# Ausfuehren:  Invoke-Pester tests/powershell/VirtuSphere.ClientLogRotation.Tests.ps1

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:ClientLogging = Join-Path (Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'clients') 'VirtuSphere-Client-Logging.ps1'
    $script:ServerLogging = Join-Path (Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'mecm') 'VirtuSphere-Logging.ps1'

    function Invoke-InClientLogging {
        param([scriptblock]$Body, [object[]]$Arguments = @())
        & {
            param($p, $b, $a)
            . $p
            & $b @a
        } $script:ClientLogging $Body $Arguments
    }

    function New-ClientLogRoot {
        $root = Join-Path ([System.IO.Path]::GetTempPath()) ('vs-clientlogrot-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $root -Force | Out-Null
        return $root
    }
}

Describe 'Client-Tageslogdatei: Obergrenze 10 MiB' {

    It 'liegt die Obergrenze bei 10 MiB (10485760 Bytes)' {
        Invoke-InClientLogging -Body { $script:VsClientLogFileMaxBytes } | Should -Be 10485760
    }

    It 'stimmt mit der Server-Obergrenze im Quelltext ueberein' {
        $clientText = Get-Content -LiteralPath $script:ClientLogging -Raw
        $serverText = Get-Content -LiteralPath $script:ServerLogging -Raw
        $clientValue = [regex]::Match($clientText, '\$script:VsClientLogFileMaxBytes\s*=\s*(\d+)').Groups[1].Value
        $serverValue = [regex]::Match($serverText, '\$script:VsLogFileMaxBytes\s*=\s*(\d+)').Groups[1].Value
        $clientValue | Should -Not -BeNullOrEmpty -Because 'der Client-Test muss die Konstante finden'
        $serverValue | Should -Not -BeNullOrEmpty -Because 'der Server-Test muss die Konstante finden'
        $clientValue | Should -Be $serverValue
    }

    It 'rotiert erst, wenn die naechste Zeile die Grenze reissen wuerde (genaue Grenze)' {
        $root = New-ClientLogRoot
        try {
            $file = Join-Path $root 'grenze.log'
            Set-Content -LiteralPath $file -Value ('x' * 390) -Encoding ASCII -NoNewline
            Invoke-InClientLogging -Arguments @($file) -Body {
                param($f)
                $script:VsClientLogFileMaxBytes = 400
                Invoke-VsClientLogRotation -Path $f -AddBytes 10
            }
            (Test-Path -LiteralPath $file) | Should -BeTrue -Because '390 + 10 = 400 liegt genau auf der Grenze'
            (Test-Path -LiteralPath (Join-Path $root 'grenze.1.log')) | Should -BeFalse

            Invoke-InClientLogging -Arguments @($file) -Body {
                param($f)
                $script:VsClientLogFileMaxBytes = 400
                Invoke-VsClientLogRotation -Path $f -AddBytes 11
            }
            (Test-Path -LiteralPath (Join-Path $root 'grenze.1.log')) | Should -BeTrue
            (Test-Path -LiteralPath $file) | Should -BeFalse -Because 'die volle Datei wandert in den Nachbarn'
        } finally {
            Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'schreibt eine Tageslogdatei ueber die Grenze in numerierte Nachbarn und verliert keine Zeile' {
        $root = New-ClientLogRoot
        try {
            $day = Get-Date -Format 'yyyy-MM-dd'
            Invoke-InClientLogging -Arguments @($root) -Body {
                param($r)
                $script:VsClientLogFileMaxBytes = 400
                Initialize-VsClientLog -Component 'rot' -LogRoot $r
                1..6 | ForEach-Object { Write-VsClientLog -Message ('zeile {0} ' -f $_ + ('x' * 100)) }
            }
            $active = Join-Path $root ($day + '_rot.log')
            $first = Join-Path $root ($day + '_rot.1.log')
            (Test-Path -LiteralPath $first) | Should -BeTrue
            (Test-Path -LiteralPath $active) | Should -BeTrue
            (Get-Item -LiteralPath $active).Length | Should -BeLessOrEqual 403 -Because 'eine Datei bleibt unter der Grenze (BOM-Spielraum 3 Bytes)'
            $all = @(Get-ChildItem -LiteralPath $root -Filter '*.log' -File | ForEach-Object { Get-Content -LiteralPath $_.FullName -Encoding UTF8 })
            @($all | Where-Object { $_ -match 'zeile \d' }).Count | Should -Be 6
        } finally {
            Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'zaehlt die Nachbarnummer hoch und ueberschreibt keinen vorhandenen Nachbarn' {
        $root = New-ClientLogRoot
        try {
            $file = Join-Path $root 'zaehl.log'
            $existing = Join-Path $root 'zaehl.1.log'
            Set-Content -LiteralPath $existing -Value 'alt' -Encoding ASCII
            Set-Content -LiteralPath $file -Value ('y' * 400) -Encoding ASCII -NoNewline
            Invoke-InClientLogging -Arguments @($file) -Body {
                param($f)
                $script:VsClientLogFileMaxBytes = 400
                Invoke-VsClientLogRotation -Path $f -AddBytes 1
            }
            (Get-Content -LiteralPath $existing -Raw).Trim() | Should -Be 'alt'
            (Test-Path -LiteralPath (Join-Path $root 'zaehl.2.log')) | Should -BeTrue
        } finally {
            Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'laesst eine fehlende Datei unberuehrt' {
        $root = New-ClientLogRoot
        try {
            Invoke-InClientLogging -Arguments @((Join-Path $root 'none.log')) -Body {
                param($f)
                Invoke-VsClientLogRotation -Path $f -AddBytes 10
            }
            @(Get-ChildItem -LiteralPath $root).Count | Should -Be 0
        } finally {
            Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}

Describe 'Client-Tageslogdatei: Retention erfasst die Nachbarn' {

    It 'loescht einen 31 Tage alten Nachbarn und behaelt einen 29 Tage alten' {
        $root = New-ClientLogRoot
        try {
            $now = Get-Date
            $old = Join-Path $root ($now.AddDays(-31).ToString('yyyy-MM-dd') + '_rot.1.log')
            $recent = Join-Path $root ($now.AddDays(-29).ToString('yyyy-MM-dd') + '_rot.2.log')
            Set-Content -LiteralPath $old -Value 'alt' -Encoding ASCII
            Set-Content -LiteralPath $recent -Value 'jung' -Encoding ASCII
            (Get-Item -LiteralPath $old).LastWriteTime = $now.AddDays(-31)
            (Get-Item -LiteralPath $recent).LastWriteTime = $now.AddDays(-29)
            Invoke-InClientLogging -Arguments @($root, $now) -Body {
                param($r, $n)
                $script:VsClientLogRoot = $r
                Invoke-VsClientLogRetention -Now $n | Out-Null
            }
            (Test-Path -LiteralPath $old) | Should -BeFalse
            (Test-Path -LiteralPath $recent) | Should -BeTrue
        } finally {
            Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'die Obergrenze steht im Modulkommentar' {
        Get-Content -LiteralPath $script:ClientLogging -Raw | Should -Match '10 MiB'
    }
}
