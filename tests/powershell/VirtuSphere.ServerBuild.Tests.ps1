# Pester-Suite fuer Build-Kennung und Installationsmanifest des MECM-
# Serverpakets (15.10.2026). Anlass: der Stand auf dem MECM-Server liess sich
# keinem Commit zuordnen. Seitdem traegt jedes git-archive-Paket seinen Commit
# (BUILD.json, export-subst), der Installer schreibt manifest.json, und jede
# Aufgabe meldet Build und Abweichung mit ihrer Kennung.
#
# Ausfuehren:  Invoke-Pester tests/powershell/VirtuSphere.ServerBuild.Tests.ps1

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:PsRoot = Join-Path $script:RepoRoot 'Powershell-MECM'
    $script:MecmRoot = Join-Path $script:PsRoot 'mecm'
    $script:Installer = Join-Path $script:PsRoot 'install-VirtuSphere-MECM.ps1'
    . (Join-Path $script:MecmRoot 'VirtuSphere-Common.ps1')

    $script:TaskScripts = @('mecm_new-device-sync.ps1', 'mecm_Packages-TaskSeq-sync.ps1', 'mecm_autoimporter.ps1', 'mecm_site-health.ps1')

    function New-InstalledSet {
        param([Parameter(Mandatory)][string]$Root)
        New-Item -ItemType Directory -Path $Root -Force | Out-Null
        foreach ($name in @('VirtuSphere-Common.ps1', 'VirtuSphere-Logging.ps1', 'mecm_site-health.ps1')) {
            [IO.File]::WriteAllText((Join-Path $Root $name), ('# ' + $name), (New-Object Text.UTF8Encoding($false)))
        }
        $build = [pscustomobject]@{ Commit = '53ba24ae0123456789abcdef0123456789abcdef'; Date = '2026-10-15'; Release = '2026.10.1' }
        [void](New-VsServerManifest -Directory $Root -FileNames @('VirtuSphere-Common.ps1', 'VirtuSphere-Logging.ps1', 'mecm_site-health.ps1') -Build $build -Mode 'server-only')
    }
}

Describe 'Build-Kennung des Pakets (BUILD.json)' {
    It 'traegt die export-subst-Platzhalter, die git archive ersetzt' {
        $raw = Get-Content -LiteralPath (Join-Path $script:PsRoot 'BUILD.json') -Raw
        $raw | Should -Match ([regex]::Escape('"commit": "$Format:%H$"'))
        $raw | Should -Match ([regex]::Escape('"date": "$Format:%cs$"'))
        $attributes = Get-Content -LiteralPath (Join-Path $script:RepoRoot '.gitattributes')
        $attributes | Should -Contain 'Powershell-MECM/BUILD.json export-subst'
    }

    It 'liest einen eingesetzten Commit und nennt einen Checkout ehrlich unbekannt' {
        $packed = Join-Path $TestDrive 'packed'
        $checkout = Join-Path $TestDrive 'checkout'
        $missing = Join-Path $TestDrive 'missing'
        New-Item -ItemType Directory -Path $packed, $checkout, $missing -Force | Out-Null
        Set-Content -LiteralPath (Join-Path $packed 'BUILD.json') -Value '{"commit":"53ba24ae0123456789abcdef0123456789abcdef","date":"2026-10-15"}' -Encoding UTF8
        Copy-Item -LiteralPath (Join-Path $script:PsRoot 'BUILD.json') -Destination (Join-Path $checkout 'BUILD.json')

        $info = Get-VsServerBuildInfo -PackageRoot $packed
        $info.Commit | Should -BeExactly '53ba24ae0123456789abcdef0123456789abcdef'
        $info.Date | Should -BeExactly '2026-10-15'
        (Get-VsServerBuildInfo -PackageRoot $checkout).Commit | Should -BeExactly ''
        (Get-VsServerBuildInfo -PackageRoot $missing).Commit | Should -BeExactly ''
    }
}

Describe 'Release-Nummer des Pakets (VERSION)' {
    BeforeAll {
        # Format JJJJ.MM.N, N hoechstens 99 (Entscheidung 16.10.2026).
        $script:ReleaseFormat = '^[0-9]{4}\.(0[1-9]|1[0-2])\.[1-9][0-9]?$'
    }

    It 'liegt als eine Zeile im Paket vor und stimmt mit der Docker-Kopie ueberein' {
        $repoVersion = Join-Path $script:PsRoot 'VERSION'
        $dockerVersion = Join-Path $script:RepoRoot 'Docker/WebAPI/lib/VERSION'
        Test-Path -LiteralPath $repoVersion -PathType Leaf | Should -BeTrue
        Test-Path -LiteralPath $dockerVersion -PathType Leaf | Should -BeTrue
        $release = (Get-Content -LiteralPath $repoVersion -Raw).Trim()
        $release | Should -CMatch $script:ReleaseFormat
        $release | Should -BeExactly (Get-Content -LiteralPath $dockerVersion -Raw).Trim()
    }

    It 'liest die Release aus VERSION mit LF und mit CRLF' {
        $lf = Join-Path $TestDrive 'version-lf'
        $crlf = Join-Path $TestDrive 'version-crlf'
        New-Item -ItemType Directory -Path $lf, $crlf -Force | Out-Null
        [IO.File]::WriteAllText((Join-Path $lf 'VERSION'), "2026.10.1`n", (New-Object Text.UTF8Encoding($false)))
        [IO.File]::WriteAllText((Join-Path $crlf 'VERSION'), "2026.10.1`r`n", (New-Object Text.UTF8Encoding($false)))
        (Get-VsServerBuildInfo -PackageRoot $lf).Release | Should -BeExactly '2026.10.1'
        (Get-VsServerBuildInfo -PackageRoot $crlf).Release | Should -BeExactly '2026.10.1'
    }

    It 'verwirft ungueltigen Inhalt (<name>) und liefert dann keine Release' -ForEach @(
        @{ name = 'Monat 13'; content = '2026.13.1' }
        @{ name = 'Monat einstellig'; content = '2026.1.1' }
        @{ name = 'N gleich 0'; content = '2026.10.0' }
        @{ name = 'N dreistellig'; content = '2026.10.100' }
        @{ name = 'leer'; content = '' }
    ) {
        $root = Join-Path $TestDrive ('bad-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $root -Force | Out-Null
        [IO.File]::WriteAllText((Join-Path $root 'VERSION'), $content, (New-Object Text.UTF8Encoding($false)))
        (Get-VsServerBuildInfo -PackageRoot $root).Release | Should -BeExactly ''
    }

    It 'behaelt den Commit eines Pakets ohne VERSION-Datei und meldet dann keine Release' {
        $root = Join-Path $TestDrive 'build-only'
        New-Item -ItemType Directory -Path $root -Force | Out-Null
        Set-Content -LiteralPath (Join-Path $root 'BUILD.json') -Value '{"commit":"53ba24ae0123456789abcdef0123456789abcdef","date":"2026-10-15"}' -Encoding UTF8
        $info = Get-VsServerBuildInfo -PackageRoot $root
        $info.Commit | Should -BeExactly '53ba24ae0123456789abcdef0123456789abcdef'
        $info.Release | Should -BeExactly ''
    }
}

Describe 'Installationsmanifest' {
    It 'bestaetigt einen unveraenderten Satz und ignoriert Nicht-Manifest-Dateien' {
        $root = Join-Path $TestDrive 'clean'
        New-InstalledSet -Root $root
        Set-Content -LiteralPath (Join-Path $root 'membership-journal.json') -Value '{}' -Encoding UTF8
        $state = Get-VsServerFileSetState -InstallDir $root
        $state.ManifestPresent | Should -BeTrue
        $state.Commit | Should -BeExactly '53ba24ae0123456789abcdef0123456789abcdef'
        $state.Release | Should -BeExactly '2026.10.1'
        $state.Checked | Should -Be 3
        @($state.Deviations).Count | Should -Be 0
        $manifest = Get-Content -LiteralPath (Join-Path $root 'manifest.json') -Raw | ConvertFrom-Json
        $manifest.mode | Should -BeExactly 'server-only'
        $manifest.release | Should -BeExactly '2026.10.1'
        @($manifest.files).Count | Should -Be 3
    }

    It 'meldet eine geaenderte und eine fehlende Datei' {
        $root = Join-Path $TestDrive 'drift'
        New-InstalledSet -Root $root
        Add-Content -LiteralPath (Join-Path $root 'VirtuSphere-Common.ps1') -Value '# von Hand'
        Remove-Item -LiteralPath (Join-Path $root 'mecm_site-health.ps1')
        $state = Get-VsServerFileSetState -InstallDir $root
        @($state.Deviations | Sort-Object) | Should -Be @(@('VirtuSphere-Common.ps1', 'mecm_site-health.ps1') | Sort-Object)
    }

    It 'wertet ein beschaedigtes Manifest als Abweichung und ein fehlendes als nicht installiert' {
        $broken = Join-Path $TestDrive 'broken'
        New-InstalledSet -Root $broken
        Set-Content -LiteralPath (Join-Path $broken 'manifest.json') -Value '{kaputt' -Encoding UTF8
        $state = Get-VsServerFileSetState -InstallDir $broken
        $state.ManifestPresent | Should -BeTrue
        @($state.Deviations) | Should -Contain 'manifest.json'

        $none = Join-Path $TestDrive 'none'
        New-Item -ItemType Directory -Path $none -Force | Out-Null
        (Get-VsServerFileSetState -InstallDir $none).ManifestPresent | Should -BeFalse
    }

    It 'wertet eine ungueltige release im Manifest weder als Abweichung noch als Release' {
        $root = Join-Path $TestDrive 'bad-release'
        New-InstalledSet -Root $root
        $manifestPath = Join-Path $root 'manifest.json'
        $doc = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
        $doc.release = '2026.1.1'
        [IO.File]::WriteAllText($manifestPath, (($doc | ConvertTo-Json -Depth 4) + "`n"), (New-Object Text.UTF8Encoding($false)))
        $state = Get-VsServerFileSetState -InstallDir $root
        $state.Release | Should -BeExactly ''
        @($state.Deviations).Count | Should -Be 0
    }
}

Describe 'Kennung im Laufbericht' {
    It '<why>' -ForEach @(
        @{ why = 'Commit kurz angehaengt'; state = @{ ManifestPresent = $true; Commit = '53ba24ae0123456789abcdef0123456789abcdef'; Deviations = @() }; expect = 'device-sync/2.1+53ba24a' }
        @{ why = 'Abweichung markiert'; state = @{ ManifestPresent = $true; Commit = '53ba24ae0123456789abcdef0123456789abcdef'; Deviations = @('x.ps1') }; expect = 'device-sync/2.1+53ba24a-abw' }
        @{ why = 'Installation aus Git-Checkout'; state = @{ ManifestPresent = $true; Commit = ''; Deviations = @() }; expect = 'device-sync/2.1+unbekannt' }
        @{ why = 'ohne Installer kopiert'; state = @{ ManifestPresent = $false; Commit = ''; Deviations = @() }; expect = 'device-sync/2.1+ohne-manifest' }
        @{ why = 'Release ohne Commit'; state = @{ ManifestPresent = $true; Commit = ''; Release = '2026.10.1'; Deviations = @() }; expect = 'device-sync/2.1+2026.10.1' }
        @{ why = 'Release mit Abweichung'; state = @{ ManifestPresent = $true; Commit = ''; Release = '2026.10.1'; Deviations = @('x.ps1') }; expect = 'device-sync/2.1+2026.10.1-abw' }
        @{ why = 'Release gewinnt ueber Commit'; state = @{ ManifestPresent = $true; Commit = '53ba24ae0123456789abcdef0123456789abcdef'; Release = '2026.10.1'; Deviations = @() }; expect = 'device-sync/2.1+2026.10.1' }
        @{ why = 'ungueltige Release faellt auf Commit zurueck'; state = @{ ManifestPresent = $true; Commit = '53ba24ae0123456789abcdef0123456789abcdef'; Release = '2026.1.1'; Deviations = @() }; expect = 'device-sync/2.1+53ba24a' }
        @{ why = 'Release ohne Manifest'; state = @{ ManifestPresent = $false; Commit = ''; Release = '2026.10.1'; Deviations = @() }; expect = 'device-sync/2.1+ohne-manifest' }
    ) {
        Format-VsReportedScriptVersion -BaseVersion 'device-sync/2.1' -State ([pscustomobject]$state) | Should -BeExactly $expect
    }

    It 'bleibt fuer jede Aufgabe und jeden Fall innerhalb der 32 Wire-Zeichen, ohne den Nachsatz abzuschneiden' {
        foreach ($script in $script:TaskScripts) {
            $text = Get-Content -LiteralPath (Join-Path $script:MecmRoot $script) -Raw
            $base = [regex]::Match($text, "(?m)^\`$SCRIPT_VERSION = '([^']+)'").Groups[1].Value
            $base | Should -Not -BeNullOrEmpty
            foreach ($case in @(
                [pscustomobject]@{ ManifestPresent = $true; Commit = ('a' * 40); Deviations = @('x') },
                [pscustomobject]@{ ManifestPresent = $true; Commit = ''; Deviations = @('x') },
                [pscustomobject]@{ ManifestPresent = $false; Commit = ''; Deviations = @() },
                [pscustomobject]@{ ManifestPresent = $true; Commit = ('a' * 40); Release = '2026.12.99'; Deviations = @('x') }
            )) {
                $release = if ($case.PSObject.Properties['Release']) { [string]$case.Release } else { '' }
                $value = '{0}+{1}' -f $base, $(if (-not $case.ManifestPresent) { 'ohne-manifest' } elseif ($release -cmatch '^[0-9]{4}\.(0[1-9]|1[0-2])\.[1-9][0-9]?$') { $release } elseif ($case.Commit) { 'aaaaaaa' } else { 'unbekannt' })
                if (@($case.Deviations).Count -gt 0) { $value += '-abw' }
                $value.Length | Should -BeLessOrEqual 32 -Because $value
                Format-VsReportedScriptVersion -BaseVersion $base -State $case | Should -BeExactly $value
            }
        }
    }

    It 'loest die Kennung in jeder Aufgabe direkt nach dem Literal und vor dem ersten Bericht auf' {
        foreach ($script in $script:TaskScripts) {
            $text = Get-Content -LiteralPath (Join-Path $script:MecmRoot $script) -Raw
            $literal = [regex]::Match($text, "(?m)^\`$SCRIPT_VERSION = '[^']+'").Index
            $resolve = $text.IndexOf('$SCRIPT_VERSION = Resolve-VsReportedScriptVersion -BaseVersion $SCRIPT_VERSION -InstallDir $PSScriptRoot')
            $firstReport = $text.IndexOf('Send-VsRunReport')
            $literal | Should -BeGreaterThan 0 -Because $script
            $resolve | Should -BeGreaterThan $literal -Because $script
            $firstReport | Should -BeGreaterThan $resolve -Because $script
        }
    }
}

Describe 'Installer schreibt und prueft das Manifest' {
    BeforeAll {
        $script:InstallerText = Get-Content -LiteralPath $script:Installer -Raw
    }

    It 'stagt das Manifest aus den geprueften Dateien und aktiviert es als letzte Datei' {
        $stage = $script:InstallerText.IndexOf('New-VsServerManifest -Directory $installStage -FileNames @($serverSources.Name) -Build $buildInfo -Mode $installMode')
        $activate = $script:InstallerText.IndexOf('$orderedNames = @(@($serverSources.Name) + $script:VsServerManifestName')
        $stage | Should -BeGreaterThan -1
        $activate | Should -BeGreaterThan $stage
        $script:InstallerText | Should -Match 'elseif \(\$_ -eq \$script:VsServerManifestName\) \{ 3 \}'
    }

    It 'bricht nach der Aktivierung ab, wenn das Manifest nicht zum Dateisatz passt' {
        $check = $script:InstallerText.IndexOf('$installedFileSet = Get-VsServerFileSetState -InstallDir $installDir')
        $tasks = $script:InstallerText.IndexOf("Write-Step 'Registriere geplante Aufgaben'")
        $check | Should -BeGreaterThan -1
        $check | Should -BeLessThan $tasks
        $script:InstallerText | Should -Match 'Installationsmanifest passt nicht zum aktivierten Dateisatz'
    }

    It 'schreibt den Build in Registry und Aufgabenbeschreibung' {
        $script:InstallerText | Should -Match 'InstalledBuild\s+=\s+\$buildLabel'
        $script:InstallerText | Should -Match 'InstalledBuildDate\s+=\s+\[string\]\$buildInfo\.Date'
        $script:InstallerText | Should -Match "-Description \('VirtuSphere MECM Integration, Build \{0\}"
    }
}
