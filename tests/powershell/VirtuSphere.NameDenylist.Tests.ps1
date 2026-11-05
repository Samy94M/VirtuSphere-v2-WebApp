# NS1 name protection: scripts/check-name-denylist.sh and the hooks under
# .githooks/. Every case runs against throwaway Git repositories under
# TestDrive with synthetic names only (example.test, 192.0.2.0/24); a real
# server, domain, address or site code must never appear in this file.
# The output assertions prove that a hit names its location and list line but
# never the name itself.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:Checker = (Join-Path (Join-Path $script:RepoRoot 'scripts') 'check-name-denylist.sh') -replace '\\', '/'
    $script:HookDir = (Join-Path $script:RepoRoot '.githooks') -replace '\\', '/'
    $script:Template = Join-Path $script:RepoRoot '.githooks/name-denylist.template.txt'
    . (Join-Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'check') 'runtime.ps1')
    $script:ShExe = Find-Sh
    if (-not $script:ShExe) { throw 'sh not found: the name-denylist checker cannot be proven (infrastructure)' }
    $script:GitExe = (Get-Command git -ErrorAction SilentlyContinue).Source
    if (-not $script:GitExe) { throw 'git not found: the name-denylist checker cannot be proven (infrastructure)' }

    $script:Name = 'srv-alpha.example.test'
    $script:NamePattern = [regex]::Escape($script:Name)
    $script:Work = Join-Path $TestDrive 'ns1'
    New-Item -ItemType Directory -Path $script:Work -Force | Out-Null
    $script:EmptyGitConfig = Join-Path $script:Work 'gitconfig-empty'
    Set-Content -LiteralPath $script:EmptyGitConfig -Value '' -Encoding Ascii

    # List with BOM, CRLF, a comment, a blank line, a substring and a word entry.
    $script:List = Join-Path $script:Work 'name-denylist.txt'
    $listText = "# synthetic list`r`n" + $script:Name + "`r`n`r`nword:192.0.2.10`r`n"
    [IO.File]::WriteAllBytes($script:List, [byte[]](0xEF, 0xBB, 0xBF) + [Text.Encoding]::ASCII.GetBytes($listText))

    function ConvertTo-NativeArgument {
        param([string]$Value)
        if ($Value -eq '') { return '""' }
        if ($Value -notmatch '[\s"]') { return $Value }
        return '"' + (($Value -replace '(\\*)"', '$1$1\"') -replace '(\\+)$', '$1$1') + '"'
    }

    # Runs a native program with sh's directory first in PATH (Git's sh.exe
    # started directly has no usr/bin otherwise) and a hermetic global Git
    # config. $Environment entries with $null remove the variable.
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
        $psi.EnvironmentVariables['VIRTUSPHERE_NAME_DENYLIST'] = $script:List
        foreach ($key in $Environment.Keys) {
            if ($null -eq $Environment[$key]) { $psi.EnvironmentVariables.Remove($key) }
            else { $psi.EnvironmentVariables[$key] = [string]$Environment[$key] }
        }
        $process = [Diagnostics.Process]::Start($psi)
        $stdout = $process.StandardOutput.ReadToEndAsync()
        $stderr = $process.StandardError.ReadToEndAsync()
        $process.WaitForExit()
        return [pscustomobject]@{ ExitCode = $process.ExitCode; Output = ($stdout.Result + $stderr.Result) }
    }

    function Invoke-Checker {
        param([string[]]$Arguments, [string]$WorkDir = $script:Work, [hashtable]$Environment = @{})
        return Invoke-Native -FilePath $script:ShExe -Arguments (@($script:Checker) + $Arguments) -WorkDir $WorkDir -Environment $Environment
    }

    function Invoke-TestGit {
        param([string]$WorkDir, [string[]]$Arguments, [hashtable]$Environment = @{})
        return Invoke-Native -FilePath $script:GitExe -Arguments $Arguments -WorkDir $WorkDir -Environment $Environment
    }

    function Assert-Git {
        param([string]$WorkDir, [string[]]$Arguments)
        $r = Invoke-TestGit -WorkDir $WorkDir -Arguments $Arguments
        if ($r.ExitCode -ne 0) { throw ('git {0} failed: {1}' -f ($Arguments -join ' '), $r.Output) }
        return $r
    }

    # Fresh repository; hooks off unless the case activates them.
    function New-TestRepo {
        param([Parameter(Mandatory)][string]$Name)
        $dir = Join-Path $script:Work $Name
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
        Assert-Git $dir @('init', '-q') | Out-Null
        Assert-Git $dir @('symbolic-ref', 'HEAD', 'refs/heads/main') | Out-Null
        foreach ($setting in @(
            @('user.name', 'NS1 Test'), @('user.email', 'ns1@example.test'),
            @('core.autocrlf', 'false'), @('commit.gpgsign', 'false'),
            @('core.hooksPath', 'no-hooks')
        )) { Assert-Git $dir (@('config') + $setting) | Out-Null }
        return $dir
    }

    function Set-RepoFile {
        param([string]$Repo, [string]$Path, [string]$Text)
        [IO.File]::WriteAllText((Join-Path $Repo $Path), $Text, (New-Object Text.UTF8Encoding $false))
    }

    function Get-Head {
        param([string]$Repo)
        return (Invoke-TestGit -WorkDir $Repo -Arguments @('rev-parse', '-q', '--verify', 'HEAD')).Output.Trim()
    }
}

Describe 'check-name-denylist.sh list handling' {
    It 'fails closed with exit 2 when the list is missing' {
        $r = Invoke-Checker -Arguments @('--check-list') -Environment @{ VIRTUSPHERE_NAME_DENYLIST = (Join-Path $script:Work 'absent.txt') }
        $r.ExitCode | Should -Be 2
        $r.Output | Should -Match 'name-denylist\.missing'
        $r.Output | Should -Match 'name-denylist\.template\.txt'
    }

    It 'treats the shipped template as empty (exit 2), so it never protects by accident' {
        $r = Invoke-Checker -Arguments @('--check-list') -Environment @{ VIRTUSPHERE_NAME_DENYLIST = $script:Template }
        $r.ExitCode | Should -Be 2
        $r.Output | Should -Match 'name-denylist\.empty'
    }

    It 'rejects an entry that is empty after its word: prefix' {
        $bad = Join-Path $script:Work 'bad-list.txt'
        Set-Content -LiteralPath $bad -Value @('# x', 'word:') -Encoding Ascii
        $r = Invoke-Checker -Arguments @('--check-list') -Environment @{ VIRTUSPHERE_NAME_DENYLIST = $bad }
        $r.ExitCode | Should -Be 2
        $r.Output | Should -Match 'name-denylist\.invalid'
        $r.Output | Should -Match 'Listenzeile 2'
    }

    It 'reads a BOM and CRLF list and counts only real entries' {
        $r = Invoke-Checker -Arguments @('--check-list')
        $r.ExitCode | Should -Be 0
        $r.Output | Should -Match ': 2 Eintrag'
    }

    It 'uses HOME/.config/virtusphere/name-denylist.txt without the variable' {
        $home2 = Join-Path $script:Work 'home'
        $configDir = Join-Path (Join-Path $home2 '.config') 'virtusphere'
        New-Item -ItemType Directory -Path $configDir -Force | Out-Null
        Copy-Item -LiteralPath $script:List -Destination (Join-Path $configDir 'name-denylist.txt')
        $r = Invoke-Checker -Arguments @('--check-list') -Environment @{ VIRTUSPHERE_NAME_DENYLIST = $null; HOME = ($home2 -replace '\\', '/') }
        $r.ExitCode | Should -Be 0
        $r.Output | Should -Match ': 2 Eintrag'
    }

    It 'rejects an invalid invocation with exit 3' {
        (Invoke-Checker -Arguments @('--bogus')).ExitCode | Should -Be 3
        (Invoke-Checker -Arguments @('--message')).ExitCode | Should -Be 3
    }
}

Describe 'check-name-denylist.sh staged diff' {
    BeforeAll {
        $script:Staged = New-TestRepo 'staged'
        Set-RepoFile $script:Staged 'notes.txt' "first`nkeep`n"
        Assert-Git $script:Staged @('add', 'notes.txt') | Out-Null
        Assert-Git $script:Staged @('commit', '-q', '-m', 'base') | Out-Null
    }
    AfterEach {
        Assert-Git $script:Staged @('reset', '-q', '--hard', 'HEAD') | Out-Null
        Assert-Git $script:Staged @('clean', '-q', '-f', '-d') | Out-Null
    }

    It 'passes a clean change' {
        Set-RepoFile $script:Staged 'notes.txt' "first`nkeep`nhost web.invalid`n"
        Assert-Git $script:Staged @('add', 'notes.txt') | Out-Null
        (Invoke-Checker -Arguments @('--staged') -WorkDir $script:Staged).ExitCode | Should -Be 0
    }

    It 'finds an added name regardless of case and never prints it' {
        Set-RepoFile $script:Staged 'notes.txt' ("first`nkeep`nhost " + $script:Name.ToUpperInvariant() + "`n")
        Assert-Git $script:Staged @('add', 'notes.txt') | Out-Null
        $r = Invoke-Checker -Arguments @('--staged') -WorkDir $script:Staged
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match 'TREFFER: notes\.txt:3: Listenzeile 2'
        $r.Output | Should -Match 'name-denylist\.hit'
        $r.Output | Should -Not -Match $script:NamePattern
    }

    It 'matches word: entries only as a whole word' {
        Set-RepoFile $script:Staged 'notes.txt' "first`nkeep`nip 192.0.2.100`nip 192.0.2.101/24`n"
        Assert-Git $script:Staged @('add', 'notes.txt') | Out-Null
        (Invoke-Checker -Arguments @('--staged') -WorkDir $script:Staged).ExitCode | Should -Be 0

        Set-RepoFile $script:Staged 'notes.txt' "first`nkeep`nip=192.0.2.10,`n"
        Assert-Git $script:Staged @('add', 'notes.txt') | Out-Null
        $r = Invoke-Checker -Arguments @('--staged') -WorkDir $script:Staged
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match 'notes\.txt:3: Listenzeile 4'
        $r.Output | Should -Not -Match '192\.0\.2\.10'
    }

    It 'ignores a removed line' {
        Set-RepoFile $script:Staged 'notes.txt' ("first`nkeep`n" + $script:Name + "`n")
        Assert-Git $script:Staged @('add', 'notes.txt') | Out-Null
        Assert-Git $script:Staged @('commit', '-q', '-m', 'seed') | Out-Null
        Set-RepoFile $script:Staged 'notes.txt' "first`nkeep`n"
        Assert-Git $script:Staged @('add', 'notes.txt') | Out-Null
        (Invoke-Checker -Arguments @('--staged') -WorkDir $script:Staged).ExitCode | Should -Be 0
        Assert-Git $script:Staged @('reset', '-q', '--hard', 'HEAD~1') | Out-Null
    }

    It 'checks file names and masks the name in the location' {
        Set-RepoFile $script:Staged ($script:Name + '.md') "plain`n"
        Assert-Git $script:Staged @('add', '-A') | Out-Null
        $r = Invoke-Checker -Arguments @('--staged') -WorkDir $script:Staged
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match '\*\*\*\.md \(Dateiname\): Listenzeile 2'
        $r.Output | Should -Not -Match $script:NamePattern
    }

    It 'checks binary files by name only and says so' {
        $bytes = [byte[]](0, 1, 2) + [Text.Encoding]::ASCII.GetBytes($script:Name)
        [IO.File]::WriteAllBytes((Join-Path $script:Staged 'blob.dat'), $bytes)
        Assert-Git $script:Staged @('add', 'blob.dat') | Out-Null
        $r = Invoke-Checker -Arguments @('--staged') -WorkDir $script:Staged
        $r.ExitCode | Should -Be 0
        $r.Output | Should -Match 'Binaerdatei nur am Namen geprueft: blob\.dat'
    }

    It 'checks the first commit of an unborn branch' {
        $unborn = New-TestRepo 'unborn'
        Set-RepoFile $unborn 'a.txt' ($script:Name + "`n")
        Assert-Git $unborn @('add', 'a.txt') | Out-Null
        (Invoke-Checker -Arguments @('--staged') -WorkDir $unborn).ExitCode | Should -Be 1
    }
}

Describe 'check-name-denylist.sh commit message and range' {
    It 'finds a name in the message body and stops at the scissors line' {
        $msg = Join-Path $script:Work 'msg-hit.txt'
        Set-Content -LiteralPath $msg -Value @('Subject', '', ('Body mentions ' + $script:Name)) -Encoding Ascii
        $r = Invoke-Checker -Arguments @('--message', $msg)
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match 'Commit-Nachricht:3: Listenzeile 2'
        $r.Output | Should -Not -Match $script:NamePattern

        $verbose = Join-Path $script:Work 'msg-scissors.txt'
        Set-Content -LiteralPath $verbose -Value @('Subject', '# ------------------------ >8 ------------------------', ('-' + $script:Name)) -Encoding Ascii
        (Invoke-Checker -Arguments @('--message', $verbose)).ExitCode | Should -Be 0
    }

    It 'checks every commit of a range, so a later removal does not hide a name' {
        $repo = New-TestRepo 'range'
        Set-RepoFile $repo 'a.txt' "base`n"
        Assert-Git $repo @('add', 'a.txt') | Out-Null
        Assert-Git $repo @('commit', '-q', '-m', 'base') | Out-Null
        Assert-Git $repo @('branch', 'start') | Out-Null
        Set-RepoFile $repo 'a.txt' ("base`n" + $script:Name + "`n")
        Assert-Git $repo @('commit', '-q', '-a', '-m', 'add') | Out-Null
        Set-RepoFile $repo 'a.txt' "base`n"
        Assert-Git $repo @('commit', '-q', '-a', '-m', 'remove') | Out-Null

        $r = Invoke-Checker -Arguments @('--range', 'start..main') -WorkDir $repo
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match '\[0/2\]'
        $r.Output | Should -Match '\[1/2\] fail commit'
        $r.Output | Should -Match '\[2/2\] pass commit'
        $r.Output | Should -Match 'a\.txt:2: Listenzeile 2'
        $r.Output | Should -Not -Match $script:NamePattern

        (Invoke-Checker -Arguments @('--range', 'main..main') -WorkDir $repo).ExitCode | Should -Be 0
        (Invoke-Checker -Arguments @('--range', 'no-such-ref..main') -WorkDir $repo).ExitCode | Should -Be 3
    }

    It 'finds a name that only the commit message of a range carries' {
        $repo = New-TestRepo 'range-msg'
        Set-RepoFile $repo 'a.txt' "base`n"
        Assert-Git $repo @('add', 'a.txt') | Out-Null
        Assert-Git $repo @('commit', '-q', '-m', 'base') | Out-Null
        Set-RepoFile $repo 'a.txt' "base`nmore`n"
        Assert-Git $repo @('commit', '-q', '-a', '-m', ('deploy to ' + $script:Name)) | Out-Null
        $r = Invoke-Checker -Arguments @('--range', 'HEAD~1..HEAD') -WorkDir $repo
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match 'Nachricht:1: Listenzeile 2'
    }
}

Describe '.githooks wiring' {
    BeforeAll {
        $script:Hooked = New-TestRepo 'hooked'
        Assert-Git $script:Hooked @('config', 'core.hooksPath', $script:HookDir) | Out-Null
        Set-RepoFile $script:Hooked 'a.txt' "base`n"
        Assert-Git $script:Hooked @('add', 'a.txt') | Out-Null
        Assert-Git $script:Hooked @('commit', '-q', '-m', 'base') | Out-Null
    }
    AfterEach {
        Invoke-TestGit -WorkDir $script:Hooked -Arguments @('merge', '--abort') | Out-Null
        Assert-Git $script:Hooked @('checkout', '-q', '-f', 'main') | Out-Null
        Assert-Git $script:Hooked @('reset', '-q', '--hard', 'HEAD') | Out-Null
    }

    It 'pre-commit refuses a staged name and keeps HEAD' {
        $before = Get-Head $script:Hooked
        Set-RepoFile $script:Hooked 'a.txt' ("base`n" + $script:Name + "`n")
        $r = Invoke-TestGit -WorkDir $script:Hooked -Arguments @('commit', '-a', '-m', 'leak')
        $r.ExitCode | Should -Not -Be 0
        $r.Output | Should -Match 'name-denylist\.hit'
        $r.Output | Should -Not -Match $script:NamePattern
        Get-Head $script:Hooked | Should -Be $before
    }

    It 'commit-msg refuses a name in the message' {
        $before = Get-Head $script:Hooked
        Set-RepoFile $script:Hooked 'a.txt' "base`nclean`n"
        $r = Invoke-TestGit -WorkDir $script:Hooked -Arguments @('commit', '-a', '-m', ('fix ' + $script:Name))
        $r.ExitCode | Should -Not -Be 0
        $r.Output | Should -Match 'Commit-Nachricht:1: Listenzeile 2'
        Get-Head $script:Hooked | Should -Be $before
    }

    It 'fails closed when the list is missing' {
        $before = Get-Head $script:Hooked
        Set-RepoFile $script:Hooked 'a.txt' "base`nclean`n"
        $r = Invoke-TestGit -WorkDir $script:Hooked -Arguments @('commit', '-a', '-m', 'clean') -Environment @{ VIRTUSPHERE_NAME_DENYLIST = (Join-Path $script:Work 'absent.txt') }
        $r.ExitCode | Should -Not -Be 0
        $r.Output | Should -Match 'name-denylist\.missing'
        Get-Head $script:Hooked | Should -Be $before
    }

    It 'lets a clean commit through' {
        $before = Get-Head $script:Hooked
        Set-RepoFile $script:Hooked 'a.txt' "base`nclean`n"
        $r = Invoke-TestGit -WorkDir $script:Hooked -Arguments @('commit', '-a', '-m', 'clean change')
        $r.ExitCode | Should -Be 0
        Get-Head $script:Hooked | Should -Not -Be $before
    }

    It 'pre-merge-commit refuses a merge that brings a name in' {
        Assert-Git $script:Hooked @('checkout', '-q', '-b', 'side') | Out-Null
        Set-RepoFile $script:Hooked 'side.txt' ($script:Name + "`n")
        Assert-Git $script:Hooked @('add', 'side.txt') | Out-Null
        Assert-Git $script:Hooked @('commit', '-q', '--no-verify', '-m', 'side') | Out-Null
        Assert-Git $script:Hooked @('checkout', '-q', 'main') | Out-Null
        Set-RepoFile $script:Hooked 'main.txt' "main only`n"
        Assert-Git $script:Hooked @('add', 'main.txt') | Out-Null
        Assert-Git $script:Hooked @('commit', '-q', '-m', 'main') | Out-Null
        $before = Get-Head $script:Hooked
        $r = Invoke-TestGit -WorkDir $script:Hooked -Arguments @('merge', '--no-ff', '-m', 'merge side', 'side')
        $r.ExitCode | Should -Not -Be 0
        $r.Output | Should -Match 'side\.txt:1: Listenzeile 2'
        Get-Head $script:Hooked | Should -Be $before
    }
}
