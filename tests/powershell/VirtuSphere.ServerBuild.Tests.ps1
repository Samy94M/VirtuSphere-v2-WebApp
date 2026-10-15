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
        $build = [pscustomobject]@{ Commit = '53ba24ae0123456789abcdef0123456789abcdef'; Date = '2026-10-15' }
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

Describe 'Installationsmanifest' {
    It 'bestaetigt einen unveraenderten Satz und ignoriert Nicht-Manifest-Dateien' {
        $root = Join-Path $TestDrive 'clean'
        New-InstalledSet -Root $root
        Set-Content -LiteralPath (Join-Path $root 'membership-journal.json') -Value '{}' -Encoding UTF8
        $state = Get-VsServerFileSetState -InstallDir $root
        $state.ManifestPresent | Should -BeTrue
        $state.Commit | Should -BeExactly '53ba24ae0123456789abcdef0123456789abcdef'
        $state.Checked | Should -Be 3
        @($state.Deviations).Count | Should -Be 0
        $manifest = Get-Content -LiteralPath (Join-Path $root 'manifest.json') -Raw | ConvertFrom-Json
        $manifest.mode | Should -BeExactly 'server-only'
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
}

Describe 'Kennung im Laufbericht' {
    It '<why>' -ForEach @(
        @{ why = 'Commit kurz angehaengt'; state = @{ ManifestPresent = $true; Commit = '53ba24ae0123456789abcdef0123456789abcdef'; Deviations = @() }; expect = 'device-sync/2.1+53ba24a' }
        @{ why = 'Abweichung markiert'; state = @{ ManifestPresent = $true; Commit = '53ba24ae0123456789abcdef0123456789abcdef'; Deviations = @('x.ps1') }; expect = 'device-sync/2.1+53ba24a-abw' }
        @{ why = 'Installation aus Git-Checkout'; state = @{ ManifestPresent = $true; Commit = ''; Deviations = @() }; expect = 'device-sync/2.1+unbekannt' }
        @{ why = 'ohne Installer kopiert'; state = @{ ManifestPresent = $false; Commit = ''; Deviations = @() }; expect = 'device-sync/2.1+ohne-manifest' }
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
                [pscustomobject]@{ ManifestPresent = $false; Commit = ''; Deviations = @() }
            )) {
                $value = '{0}+{1}' -f $base, $(if (-not $case.ManifestPresent) { 'ohne-manifest' } elseif ($case.Commit) { 'aaaaaaa' } else { 'unbekannt' })
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
