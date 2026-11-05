# B1: CHANGELOG and register as one file per package.
# scripts/check-doc-fragments.sh (gate doc-fragments) and
# scripts/merge-changelog-fragments.sh (release commit) run against throwaway
# Git repositories under TestDrive. The guard derives what is new from Git
# (last commit that changes Docker/WebAPI/lib/VERSION, the commit that added the
# archive marker), so every red case is a real commit sequence, not a file list.
# ASCII only: Windows PowerShell 5.1 reads UTF-8 without BOM as ANSI.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:Guard = (Join-Path (Join-Path $script:RepoRoot 'scripts') 'check-doc-fragments.sh') -replace '\\', '/'
    $script:Merge = (Join-Path (Join-Path $script:RepoRoot 'scripts') 'merge-changelog-fragments.sh') -replace '\\', '/'
    . (Join-Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'check') 'runtime.ps1')
    $script:ShExe = Find-Sh
    if (-not $script:ShExe) { throw 'sh not found: the doc-fragments scripts cannot be proven (infrastructure)' }
    $script:GitExe = (Get-Command git -ErrorAction SilentlyContinue).Source
    if (-not $script:GitExe) { throw 'git not found: the doc-fragments guard cannot be proven (infrastructure)' }

    $script:Work = Join-Path $TestDrive 'b1'
    New-Item -ItemType Directory -Path $script:Work -Force | Out-Null
    $script:EmptyGitConfig = Join-Path $script:Work 'gitconfig-empty'
    Set-Content -LiteralPath $script:EmptyGitConfig -Value '' -Encoding Ascii
    $script:RepoCounter = 0

    function ConvertTo-NativeArgument {
        param([string]$Value)
        if ($Value -eq '') { return '""' }
        if ($Value -notmatch '[\s"]') { return $Value }
        return '"' + (($Value -replace '(\\*)"', '$1$1\"') -replace '(\\+)$', '$1$1') + '"'
    }

    # sh's directory first in PATH (Git's sh.exe started directly has no
    # usr/bin otherwise) and a hermetic global Git config.
    function Invoke-Native {
        param(
            [Parameter(Mandatory)][string]$FilePath,
            [string[]]$Arguments = @(),
            [Parameter(Mandatory)][string]$WorkDir,
            [hashtable]$Environment = @{}
        )
        $psi = New-Object System.Diagnostics.ProcessStartInfo
        $psi.FileName = $FilePath
        $psi.Arguments = (@($Arguments | ForEach-Object { ConvertTo-NativeArgument $_ }) -join ' ')
        $psi.WorkingDirectory = $WorkDir
        $psi.UseShellExecute = $false
        $psi.RedirectStandardOutput = $true
        $psi.RedirectStandardError = $true
        $psi.EnvironmentVariables['PATH'] = (Split-Path $script:ShExe -Parent) + [IO.Path]::PathSeparator + $env:PATH
        $psi.EnvironmentVariables['GIT_CONFIG_GLOBAL'] = $script:EmptyGitConfig
        foreach ($key in $Environment.Keys) {
            if ($null -eq $Environment[$key]) { $psi.EnvironmentVariables.Remove($key) }
            else { $psi.EnvironmentVariables[$key] = [string]$Environment[$key] }
        }
        $process = [Diagnostics.Process]::Start($psi)
        $stdout = $process.StandardOutput.ReadToEndAsync()
        $stderr = $process.StandardError.ReadToEndAsync()
        $process.WaitForExit()
        return [pscustomobject]@{ ExitCode = $process.ExitCode; Output = ($stdout.Result + $stderr.Result); Stdout = $stdout.Result }
    }

    function Invoke-Guard {
        param([string]$Repo, [string[]]$Arguments = @('--ci'))
        return Invoke-Native -FilePath $script:ShExe -Arguments (@($script:Guard) + $Arguments) -WorkDir $script:Work -Environment @{ VIRTUSPHERE_CHECK_ROOT = ($Repo -replace '\\', '/') }
    }

    function Invoke-Merge {
        param([string]$Repo, [string[]]$Arguments = @())
        return Invoke-Native -FilePath $script:ShExe -Arguments (@($script:Merge) + $Arguments) -WorkDir $script:Work -Environment @{ VIRTUSPHERE_CHECK_ROOT = ($Repo -replace '\\', '/') }
    }

    function Assert-Git {
        param([string]$Repo, [string[]]$Arguments)
        $r = Invoke-Native -FilePath $script:GitExe -Arguments $Arguments -WorkDir $Repo
        if ($r.ExitCode -ne 0) { throw ('git {0} failed: {1}' -f ($Arguments -join ' '), $r.Output) }
        return $r
    }

    function Set-RepoFile {
        param([string]$Repo, [string]$Path, [string]$Text)
        $file = Join-Path $Repo $Path
        New-Item -ItemType Directory -Force -Path (Split-Path $file -Parent) | Out-Null
        [IO.File]::WriteAllText($file, $Text, (New-Object Text.UTF8Encoding $false))
    }

    function Get-RepoFile {
        param([string]$Repo, [string]$Path)
        return [IO.File]::ReadAllText((Join-Path $Repo $Path), (New-Object Text.UTF8Encoding $false))
    }

    function Save-Commit {
        param([string]$Repo, [string]$Message)
        Assert-Git $Repo @('add', '-A') | Out-Null
        Assert-Git $Repo @('commit', '-q', '-m', $Message) | Out-Null
    }

    $script:Preamble = "# Changelog`n`nIntro text; new entries live under changelog.d/.`n`n"
    $script:Released = "## 2026.11.5 (05.11.2026)`n`nUpdate from 2026.11.4.`n`n- **Entry five (QL1, 05.11.2026).** Text.`n`n## 2026.11.4 (04.11.2026)`n`n- **Entry four (DG1, 04.11.2026).** Text.`n"
    $script:Register = "# Register`n`n<!-- register-archive -->`n> Archive.`n`n### Wave FL1`n`n- old entry`n"

    # Release commit 2026.11.5 with archive register and both fragment folders.
    function New-FragmentRepo {
        $script:RepoCounter++
        $repo = Join-Path $script:Work ('repo-' + $script:RepoCounter)
        New-Item -ItemType Directory -Path $repo -Force | Out-Null
        Assert-Git $repo @('init', '-q') | Out-Null
        Assert-Git $repo @('symbolic-ref', 'HEAD', 'refs/heads/main') | Out-Null
        foreach ($setting in @(
            @('user.name', 'B1 Test'), @('user.email', 'b1@example.test'),
            @('core.autocrlf', 'false'), @('commit.gpgsign', 'false'), @('core.hooksPath', 'no-hooks')
        )) { Assert-Git $repo (@('config') + $setting) | Out-Null }
        Set-RepoFile $repo 'Docker/WebAPI/lib/VERSION' "2026.11.4`n"
        Set-RepoFile $repo 'docs/CHANGELOG.md' ($script:Preamble + "## 2026.11.4 (04.11.2026)`n`n- **Entry four (DG1, 04.11.2026).** Text.`n")
        Set-RepoFile $repo 'docs/audits/register.md' "# Register`n`n### Wave FL1`n`n- old entry`n"
        Save-Commit $repo 'Release 2026.11.4'
        Set-RepoFile $repo 'Docker/WebAPI/lib/VERSION' "2026.11.5`n"
        Set-RepoFile $repo 'docs/CHANGELOG.md' ($script:Preamble + $script:Released)
        Save-Commit $repo 'Release 2026.11.5'
        Set-RepoFile $repo 'docs/audits/register.md' $script:Register
        Set-RepoFile $repo 'docs/changelog.d/README.md' "# Fragments`n"
        Set-RepoFile $repo 'docs/register/README.md' "# Register`n"
        Save-Commit $repo 'Fragment layout'
        return $repo
    }
}

Describe 'check-doc-fragments.sh' {
    It 'is green on the fragment layout and reports every unit before and after' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/changelog.d/B1.md' "- **Fragments (B1, 05.11.2026).** Text.`n  continued line.`n"
        Set-RepoFile $repo 'docs/register/2026-11-05-B1.md' "# B1 (05.11.2026): Fragments`n`n- done`n"
        Save-Commit $repo 'B1'
        $r = Invoke-Guard $repo
        $r.ExitCode | Should -Be 0 -Because $r.Output
        foreach ($unit in @('changelog-head', 'changelog-since-release', 'register-archive', 'fragments')) {
            $r.Output | Should -Match ('\[\d/4\] RUN doc-fragments ' + $unit)
            $r.Output | Should -Match ('\[\d/4\] pass doc-fragments ' + $unit)
        }
        $quiet = Invoke-Guard $repo @('--quiet')
        $quiet.ExitCode | Should -Be 0
        $quiet.Output.Trim() | Should -BeNullOrEmpty
    }

    It 'rejects a restored "Noch in keinem Release" section with an entry' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/CHANGELOG.md' ($script:Preamble + "## Noch in keinem Release`n`n- **New (X1, 06.11.2026).** Text.`n`n" + $script:Released)
        $r = Invoke-Guard $repo
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match '\[doc-fragments\.unreleased-section\]'
        $r.Output | Should -Match '\[doc-fragments\.new-entry\]'
        $r.Output | Should -Match '\[1/4\] fail doc-fragments changelog-head'
    }

    It 'rejects an entry before the first release heading' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/CHANGELOG.md' ($script:Preamble + "- **New (X1, 06.11.2026).** Text.`n`n" + $script:Released)
        $r = Invoke-Guard $repo
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match '\[doc-fragments\.preamble-entry\]'
    }

    It 'rejects an entry added under the top release in a later commit' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/CHANGELOG.md' ($script:Preamble + $script:Released.Replace("- **Entry five", "- **Late (X1, 06.11.2026).** Text.`n- **Entry five"))
        Save-Commit $repo 'Package X1 writes the old way'
        $r = Invoke-Guard $repo
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match '\[doc-fragments\.new-entry\]'
        $r.Output | Should -Match 'Late \(X1'
    }

    It 'allows an edit of an existing entry between releases' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/CHANGELOG.md' ($script:Preamble + $script:Released.Replace('Entry five (QL1, 05.11.2026).** Text.', 'Entry five (QL1, 05.11.2026).** Text, corrected.'))
        Save-Commit $repo 'Typo'
        (Invoke-Guard $repo).ExitCode | Should -Be 0
    }

    It 'rejects a release heading set without a release commit' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/CHANGELOG.md' ($script:Preamble + "## 2026.11.6 (06.11.2026)`n`n" + $script:Released)
        $r = Invoke-Guard $repo
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match '\[doc-fragments\.new-heading\]'
    }

    It 'rejects a first heading that is not a release' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/CHANGELOG.md' ($script:Preamble + "## Next`n`n" + $script:Released)
        $r = Invoke-Guard $repo
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match '\[doc-fragments\.first-heading\]'
    }

    It 'rejects a release commit that left fragments behind' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/changelog.d/X1.md' "- **New (X1, 06.11.2026).** Text.`n"
        Save-Commit $repo 'X1'
        Set-RepoFile $repo 'Docker/WebAPI/lib/VERSION' "2026.11.6`n"
        Set-RepoFile $repo 'docs/CHANGELOG.md' ($script:Preamble + "## 2026.11.6 (06.11.2026)`n`n- **By hand (X1, 06.11.2026).** Text.`n`n" + $script:Released)
        Save-Commit $repo 'Release 2026.11.6 without the script'
        $r = Invoke-Guard $repo
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match '\[doc-fragments\.stale-fragment\]'
        $r.Output | Should -Match 'docs/changelog\.d/X1\.md'
    }

    It 'rejects a new line in the archive register and allows a removed one' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/audits/register.md' ($script:Register + "`n### Wave X1`n`n- new entry`n")
        Save-Commit $repo 'X1 writes into the archive'
        $r = Invoke-Guard $repo
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match '\[doc-fragments\.archive-entry\]'
        $r.Output | Should -Match 'Wave X1'

        $clean = New-FragmentRepo
        Set-RepoFile $clean 'docs/audits/register.md' $script:Register.Replace("- old entry`n", '')
        Save-Commit $clean 'Remove a line'
        (Invoke-Guard $clean).ExitCode | Should -Be 0
    }

    It 'keeps protecting an archive whose marker was removed, and one that was deleted' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/audits/register.md' ($script:Register.Replace("<!-- register-archive -->`n", '') + "- sneaked in`n")
        Save-Commit $repo 'Drop the marker'
        $r = Invoke-Guard $repo
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match '\[doc-fragments\.archive-marker\]'

        $gone = New-FragmentRepo
        Assert-Git $gone @('rm', '-q', 'docs/audits/register.md') | Out-Null
        Save-Commit $gone 'Delete the archive'
        $r = Invoke-Guard $gone
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match '\[doc-fragments\.archive-removed\]'
    }

    It 'is red, not green, when no archive register exists (zero match)' {
        # A deleted archive is still known from history (case above); this
        # repository never had one.
        $never = Join-Path $script:Work 'never'
        New-Item -ItemType Directory -Path $never -Force | Out-Null
        Assert-Git $never @('init', '-q') | Out-Null
        foreach ($setting in @(@('user.name', 'B1 Test'), @('user.email', 'b1@example.test'), @('core.autocrlf', 'false'), @('commit.gpgsign', 'false'))) {
            Assert-Git $never (@('config') + $setting) | Out-Null
        }
        Set-RepoFile $never 'Docker/WebAPI/lib/VERSION' "2026.11.5`n"
        Set-RepoFile $never 'docs/CHANGELOG.md' ($script:Preamble + $script:Released)
        Set-RepoFile $never 'docs/audits/register.md' "# Register`n`n- entry`n"
        Set-RepoFile $never 'docs/changelog.d/README.md' "# Fragments`n"
        Set-RepoFile $never 'docs/register/README.md' "# Register`n"
        Save-Commit $never 'Release'
        $r = Invoke-Guard $never
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match '\[doc-fragments\.no-archive\]'
    }

    It 'rejects badly named and badly formed fragments' {
        $cases = @(
            @{ Path = 'docs/changelog.d/b1.md'; Text = "- **x (B1).** y`n"; Pattern = 'PAKET>\.md' }
            @{ Path = 'docs/changelog.d/B1.txt'; Text = "- **x (B1).** y`n"; Pattern = 'PAKET>\.md' }
            @{ Path = 'docs/changelog.d/B1.md'; Text = "`n  `n"; Pattern = 'leer' }
            @{ Path = 'docs/changelog.d/B1.md'; Text = "## B1`n`n- **x (B1).** y`n"; Pattern = 'erste Zeile' }
            @{ Path = 'docs/changelog.d/B1.md'; Text = "- **x (B1).** y`nfree paragraph`n"; Pattern = 'Zeile 2' }
            @{ Path = 'docs/register/B1.md'; Text = "# B1`n`ntext`n"; Pattern = 'JJJJ-MM-TT' }
            @{ Path = 'docs/register/2026-11-05-B1.md'; Text = "# SEC2 (05.11.2026)`n`ntext`n"; Pattern = "'# B1 \.\.\.'" }
            @{ Path = 'docs/register/2026-11-05-B1.md'; Text = "# B1 (05.11.2026)`n"; Pattern = 'nur die Ueberschrift' }
        )
        foreach ($case in $cases) {
            $repo = New-FragmentRepo
            Set-RepoFile $repo $case.Path $case.Text
            $r = Invoke-Guard $repo
            $r.ExitCode | Should -Be 1 -Because ($case.Path + ': ' + $r.Output)
            $r.Output | Should -Match '\[doc-fragments\.fragment-format\]'
            $r.Output | Should -Match $case.Pattern -Because $case.Path
        }
    }

    # `* text=auto` checks .md out with CRLF on Windows; Git stores LF. A
    # fresh Windows clone must stay green (found by the B1 counterprobe).
    It 'accepts fragments and a changelog checked out with CRLF' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/CHANGELOG.md' ($script:Preamble + $script:Released).Replace("`n", "`r`n")
        Set-RepoFile $repo 'docs/changelog.d/B1.md' "- **x (B1).** y`r`n  continued.`r`n"
        Set-RepoFile $repo 'docs/register/2026-11-05-B1.md' "# B1`r`n`r`ntext`r`n"
        $r = Invoke-Guard $repo
        $r.ExitCode | Should -Be 0 -Because $r.Output
    }

    It 'rejects a fragment with a UTF-8 BOM' {
        $repo = New-FragmentRepo
        $bytes = [byte[]](0xEF, 0xBB, 0xBF) + [Text.Encoding]::ASCII.GetBytes("- **x (B1).** y`n")
        [IO.File]::WriteAllBytes((Join-Path $repo 'docs/changelog.d/B1.md'), $bytes)
        $r = Invoke-Guard $repo
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match 'BOM'
    }

    It 'needs the README of each fragment folder' {
        $repo = New-FragmentRepo
        Remove-Item -LiteralPath (Join-Path $repo 'docs/register/README.md')
        $r = Invoke-Guard $repo
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match '\[doc-fragments\.no-ssot\] docs/register/README\.md'
    }

    It 'reports infrastructure outside a Git work tree' {
        $plain = Join-Path $script:Work 'plain'
        New-Item -ItemType Directory -Path $plain -Force | Out-Null
        $r = Invoke-Guard $plain
        $r.ExitCode | Should -Be 2
        $r.Output | Should -Match '\[doc-fragments\.no-git\]'
    }
}

Describe 'merge-changelog-fragments.sh' {
    It 'shows the section in a dry run and writes nothing' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/changelog.d/SEC2.md' "- **Sec (SEC2, 05.11.2026).** Text.`n"
        Set-RepoFile $repo 'docs/changelog.d/B1.md' "- **Frag (B1, 05.11.2026).** Text.`n"
        $before = Get-RepoFile $repo 'docs/CHANGELOG.md'
        $r = Invoke-Merge $repo @('--dry-run', '--version', '2026.11.6', '--date', '06.11.2026')
        $r.ExitCode | Should -Be 0 -Because $r.Output
        $r.Output | Should -Match '\[0/2\] merge-changelog 2026\.11\.6'
        $r.Output | Should -Match '\[1/2\] RUN merge-changelog B1\.md'
        $r.Output | Should -Match '\[2/2\] pass merge-changelog SEC2\.md'
        $r.Stdout | Should -Match '## 2026\.11\.6 \(06\.11\.2026\)'
        (Get-RepoFile $repo 'docs/CHANGELOG.md') | Should -BeExactly $before
        Test-Path (Join-Path $repo 'docs/changelog.d/B1.md') | Should -BeTrue
    }

    It 'merges in file-name order, deletes the fragments and leaves a tree the guard accepts' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/changelog.d/SEC2.md' "- **Sec (SEC2, 05.11.2026).** Text.`n"
        Set-RepoFile $repo 'docs/changelog.d/B1.md' "- **Frag (B1, 05.11.2026).** Text.`n`n- **Frag two (B1, 05.11.2026).** Text.`n  continued.`n"
        Set-RepoFile $repo 'docs/changelog.d/RL1-D7.md' "- **Rest (RL1-D7, 05.11.2026).** Text.`n"
        Save-Commit $repo 'Three packages'
        Set-RepoFile $repo 'Docker/WebAPI/lib/VERSION' "2026.11.6`n"
        Set-RepoFile $repo 'intro.txt' "`nUpdate from 2026.11.5. No migration.`n`n"
        $r = Invoke-Merge $repo @('--date', '06.11.2026', '--intro', ((Join-Path $repo 'intro.txt') -replace '\\', '/'))
        $r.ExitCode | Should -Be 0 -Because $r.Output
        $expected = $script:Preamble + "## 2026.11.6 (06.11.2026)`n`nUpdate from 2026.11.5. No migration.`n`n" +
            "- **Frag (B1, 05.11.2026).** Text.`n- **Frag two (B1, 05.11.2026).** Text.`n  continued.`n" +
            "- **Rest (RL1-D7, 05.11.2026).** Text.`n- **Sec (SEC2, 05.11.2026).** Text.`n`n" + $script:Released
        (Get-RepoFile $repo 'docs/CHANGELOG.md') | Should -BeExactly $expected
        @(Get-ChildItem -LiteralPath (Join-Path $repo 'docs/changelog.d') -Name) | Should -Be @('README.md')

        Remove-Item -LiteralPath (Join-Path $repo 'intro.txt')
        $during = Invoke-Guard $repo
        $during.ExitCode | Should -Be 0 -Because $during.Output
        $during.Output | Should -Match 'Release in Arbeit'
        Save-Commit $repo 'Release 2026.11.6'
        $after = Invoke-Guard $repo
        $after.ExitCode | Should -Be 0 -Because $after.Output

        $again = Invoke-Merge $repo @('--date', '06.11.2026')
        $again.ExitCode | Should -Be 1
        $again.Output | Should -Match '\[changelog-merge\.(duplicate|no-fragments)\]'
    }

    It 'stops before writing when one fragment is broken' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/changelog.d/A1.md' "- **Fine (A1).** Text.`n"
        Set-RepoFile $repo 'docs/changelog.d/B1.md' "Just a paragraph.`n"
        $before = Get-RepoFile $repo 'docs/CHANGELOG.md'
        $r = Invoke-Merge $repo @('--version', '2026.11.6')
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match '\[2/2\] fail merge-changelog B1\.md'
        $r.Output | Should -Match '\[changelog-merge\.fragment-format\]'
        (Get-RepoFile $repo 'docs/CHANGELOG.md') | Should -BeExactly $before
        Test-Path (Join-Path $repo 'docs/changelog.d/A1.md') | Should -BeTrue
    }

    It 'refuses an empty folder, a known version and a malformed version' {
        $repo = New-FragmentRepo
        $empty = Invoke-Merge $repo @('--version', '2026.11.6')
        $empty.ExitCode | Should -Be 1
        $empty.Output | Should -Match '\[changelog-merge\.no-fragments\]'

        Set-RepoFile $repo 'docs/changelog.d/B1.md' "- **x (B1).** y`n"
        $known = Invoke-Merge $repo
        $known.ExitCode | Should -Be 1
        $known.Output | Should -Match '\[changelog-merge\.duplicate\]'

        $bad = Invoke-Merge $repo @('--version', '2026.13.1')
        $bad.ExitCode | Should -Be 2
        $bad.Output | Should -Match '\[changelog-merge\.version\]'
    }

    It 'reads a relative --intro from the caller directory' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/changelog.d/B1.md' "- **x (B1).** y`n"
        [IO.File]::WriteAllText((Join-Path $script:Work 'intro-rel.txt'), "Relative intro.`n", (New-Object Text.UTF8Encoding $false))
        $r = Invoke-Merge $repo @('--dry-run', '--version', '2026.11.6', '--intro', 'intro-rel.txt')
        $r.ExitCode | Should -Be 0 -Because $r.Output
        $r.Stdout | Should -Match 'Relative intro\.'

        $missing = Invoke-Merge $repo @('--dry-run', '--version', '2026.11.6', '--intro', 'no-such-intro.txt')
        $missing.ExitCode | Should -Be 2
        $missing.Output | Should -Match '\[changelog-merge\.intro\]'
    }

    It 'writes CRLF fragments and a CRLF intro without CR' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/changelog.d/B1.md' "- **Crlf (B1).** y`r`n  continued.`r`n"
        Set-RepoFile $repo 'intro.txt' "Crlf intro.`r`n"
        $r = Invoke-Merge $repo @('--version', '2026.11.6', '--date', '06.11.2026', '--intro', ((Join-Path $repo 'intro.txt') -replace '\\', '/'))
        $r.ExitCode | Should -Be 0 -Because $r.Output
        $text = Get-RepoFile $repo 'docs/CHANGELOG.md'
        $text | Should -Not -Match "`r"
        $text | Should -Match "## 2026\.11\.6 \(06\.11\.2026\)`n`nCrlf intro\.`n`n- \*\*Crlf \(B1\)\.\*\* y`n  continued\.`n`n## 2026\.11\.5"
    }

    It 'refuses a changelog that still has the old unreleased section' {
        $repo = New-FragmentRepo
        Set-RepoFile $repo 'docs/CHANGELOG.md' ($script:Preamble + "## Noch in keinem Release`n`n" + $script:Released)
        Set-RepoFile $repo 'docs/changelog.d/B1.md' "- **x (B1).** y`n"
        $r = Invoke-Merge $repo @('--version', '2026.11.6')
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match '\[changelog-merge\.unreleased-section\]'
    }
}
