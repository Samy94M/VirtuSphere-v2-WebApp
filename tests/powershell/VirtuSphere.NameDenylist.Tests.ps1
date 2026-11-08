# NS1/NS3 name protection: scripts/check-name-denylist.sh, the tracked pattern
# list .githooks/name-patterns.txt and the hooks under .githooks/. Every case
# runs against throwaway Git repositories under TestDrive with synthetic values
# only (example.test, *.test, 192.0.2.0/24, 198.51.100.0/24); a real server,
# domain, address or site code must never appear in this file. Values that the
# shipped pattern list must catch are assembled at run time, so this file
# itself passes the hook.
# The output assertions prove that a hit names its location and list line but
# never the found text.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:Checker = (Join-Path (Join-Path $script:RepoRoot 'scripts') 'check-name-denylist.sh') -replace '\\', '/'
    $script:HookDir = (Join-Path $script:RepoRoot '.githooks') -replace '\\', '/'
    $script:Template = Join-Path $script:RepoRoot '.githooks/name-denylist.template.txt'
    $script:ShippedPatterns = Join-Path $script:RepoRoot '.githooks/name-patterns.txt'
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
    $script:EmptyHome = Join-Path $script:Work 'empty-home'
    New-Item -ItemType Directory -Path $script:EmptyHome -Force | Out-Null

    # Private list with BOM, CRLF, a comment, a blank line, a substring and a word entry.
    $script:List = Join-Path $script:Work 'name-denylist.txt'
    $listText = "# synthetic list`r`n" + $script:Name + "`r`n`r`nword:192.0.2.10`r`n"
    [IO.File]::WriteAllBytes($script:List, [byte[]](0xEF, 0xBB, 0xBF) + [Text.Encoding]::ASCII.GetBytes($listText))

    # Synthetic pattern list for the hermetic cases (line numbers are asserted).
    $script:Patterns = Join-Path $script:Work 'patterns.txt'
    Set-Content -LiteralPath $script:Patterns -Encoding Ascii -Value @(
        '# synthetic patterns',                      # 1
        're:node-[a-z0-9-]+\.test',                  # 2
        'allow:node-ok(-[a-z]+)?\.test',             # 3
        're:198\.51\.100\.[0-9]+(:[0-9]+)?',         # 4
        'allow:198\.51\.100\.1'                      # 5
    )

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
        $psi.EnvironmentVariables['VIRTUSPHERE_NAME_PATTERNS'] = $script:Patterns
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

    function New-ListFile {
        param([string]$Name, [string[]]$Lines)
        $path = Join-Path $script:Work $Name
        Set-Content -LiteralPath $path -Value $Lines -Encoding Ascii
        return $path
    }

    # Stages one file in a fresh repository and runs --staged against the given lists.
    function Test-StagedText {
        param([string]$Repo, [string]$Text, [hashtable]$Environment = @{})
        Set-RepoFile $Repo 'probe.txt' $Text
        Assert-Git $Repo @('add', 'probe.txt') | Out-Null
        return Invoke-Checker -Arguments @('--staged') -WorkDir $Repo -Environment $Environment
    }
}

Describe 'check-name-denylist.sh list handling' {
    It 'fails closed with exit 2 when the pattern list is missing' {
        $r = Invoke-Checker -Arguments @('--check-list') -Environment @{ VIRTUSPHERE_NAME_PATTERNS = (Join-Path $script:Work 'absent.txt') }
        $r.ExitCode | Should -Be 2
        $r.Output | Should -Match 'name-denylist\.missing'
        $r.Output | Should -Match 'Musterliste'
    }

    It 'fails closed with exit 2 when the pattern list has no hit pattern' {
        $onlyAllow = New-ListFile 'only-allow.txt' @('# comment', 'allow:x\.test')
        $r = Invoke-Checker -Arguments @('--check-list') -Environment @{ VIRTUSPHERE_NAME_PATTERNS = $onlyAllow }
        $r.ExitCode | Should -Be 2
        $r.Output | Should -Match 'name-denylist\.empty'
        $r = Invoke-Checker -Arguments @('--check-list') -Environment @{ VIRTUSPHERE_NAME_PATTERNS = $script:Template }
        $r.ExitCode | Should -Be 2
        $r.Output | Should -Match 'name-denylist\.empty'
    }

    It 'fails closed with exit 2 when an explicitly named private list is missing' {
        $r = Invoke-Checker -Arguments @('--check-list') -Environment @{ VIRTUSPHERE_NAME_DENYLIST = (Join-Path $script:Work 'absent.txt') }
        $r.ExitCode | Should -Be 2
        $r.Output | Should -Match 'name-denylist\.missing'
        $r.Output | Should -Match 'name-denylist\.template\.txt'
    }

    It 'treats a missing private list at the default place as optional' {
        $r = Invoke-Checker -Arguments @('--check-list') -Environment @{ VIRTUSPHERE_NAME_DENYLIST = $null; HOME = ($script:EmptyHome -replace '\\', '/') }
        $r.ExitCode | Should -Be 0
        $r.Output | Should -Match 'Private Liste nicht vorhanden'
    }

    It 'counts the shipped template as a private list without entries' {
        $r = Invoke-Checker -Arguments @('--check-list') -Environment @{ VIRTUSPHERE_NAME_DENYLIST = $script:Template }
        $r.ExitCode | Should -Be 0
        $r.Output | Should -Match ': 0 Eintrag'
    }

    It 'rejects an entry that is empty after its prefix' {
        foreach ($prefix in @('word:', 're:', 'allow:')) {
            $bad = New-ListFile 'bad-list.txt' @('# x', $prefix)
            $r = Invoke-Checker -Arguments @('--check-list') -Environment @{ VIRTUSPHERE_NAME_DENYLIST = $bad }
            $r.ExitCode | Should -Be 2
            $r.Output | Should -Match 'name-denylist\.invalid'
            $r.Output | Should -Match 'bad-list\.txt:2'
        }
    }

    It 'rejects a pattern awk cannot compile, a {n,m} repetition and a pattern that matches nothing' {
        foreach ($line in @('re:(unclosed', 're:a{2}', 'allow:[0-9]{1,3}', 're:x*')) {
            $bad = New-ListFile 'bad-patterns.txt' @('re:ok\.test', $line)
            $r = Invoke-Checker -Arguments @('--check-list') -Environment @{ VIRTUSPHERE_NAME_PATTERNS = $bad }
            $r.ExitCode | Should -Be 2 -Because $line
            $r.Output | Should -Match 'name-denylist\.invalid'
            $r.Output | Should -Match 'bad-patterns\.txt:2'
        }
    }

    It 'reads a BOM and CRLF list and counts only real entries' {
        $r = Invoke-Checker -Arguments @('--check-list')
        $r.ExitCode | Should -Be 0
        $r.Output | Should -Match 'Musterliste lesbar \(patterns\.txt\): 2 Muster, 2 Freigabe'
        $r.Output | Should -Match 'Private Liste lesbar \(name-denylist\.txt\): 2 Eintrag'
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

    It 'finds the shipped pattern list next to the script and accepts it' {
        $r = Invoke-Checker -Arguments @('--check-list') -Environment @{ VIRTUSPHERE_NAME_PATTERNS = $null }
        $r.ExitCode | Should -Be 0
        $r.Output | Should -Match 'Musterliste lesbar \(name-patterns\.txt\): [1-9][0-9]* Muster'
    }

    It 'rejects an invalid invocation with exit 3' {
        (Invoke-Checker -Arguments @('--bogus')).ExitCode | Should -Be 3
        (Invoke-Checker -Arguments @('--message')).ExitCode | Should -Be 3
        (Invoke-Checker -Arguments @('--tree')).ExitCode | Should -Be 3
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
        $r.Output | Should -Match 'TREFFER: notes\.txt:3: name-denylist\.txt:2'
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
        $r.Output | Should -Match 'notes\.txt:3: name-denylist\.txt:4'
        $r.Output | Should -Not -Match '192\.0\.2\.10'
    }

    It 'finds a re: pattern regardless of case and names list and line, never the text' {
        Set-RepoFile $script:Staged 'notes.txt' "first`nkeep`nsee NODE-Beta9.test now`n"
        Assert-Git $script:Staged @('add', 'notes.txt') | Out-Null
        $r = Invoke-Checker -Arguments @('--staged') -WorkDir $script:Staged
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match 'TREFFER: notes\.txt:3: patterns\.txt:2'
        $r.Output | Should -Not -Match '(?i)node-beta9'
    }

    It 'lets allow: suppress a re: hit only when it covers the whole match' {
        Set-RepoFile $script:Staged 'notes.txt' "first`nkeep`nnode-ok.test and node-ok-two.test`nip 198.51.100.1`n"
        Assert-Git $script:Staged @('add', 'notes.txt') | Out-Null
        (Invoke-Checker -Arguments @('--staged') -WorkDir $script:Staged).ExitCode | Should -Be 0

        # 198.51.100.1:8021 and 198.51.100.12 are longer than the allowed text.
        Set-RepoFile $script:Staged 'notes.txt' "first`nkeep`nurl 198.51.100.1:8021`nip 198.51.100.12`n"
        Assert-Git $script:Staged @('add', 'notes.txt') | Out-Null
        $r = Invoke-Checker -Arguments @('--staged') -WorkDir $script:Staged
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match 'notes\.txt:3: patterns\.txt:4'
        $r.Output | Should -Match 'notes\.txt:4: patterns\.txt:4'
    }

    It 'keeps re: hits to whole words: no letter, digit, _ or dotted continuation around them' {
        Set-RepoFile $script:Staged 'notes.txt' "first`nkeep`nx198.51.100.7`n1.3.198.51.100.7.2`nv198.51.100.7_a`nbuild 198.51.100.7.0`n"
        Assert-Git $script:Staged @('add', 'notes.txt') | Out-Null
        (Invoke-Checker -Arguments @('--staged') -WorkDir $script:Staged).ExitCode | Should -Be 0

        Set-RepoFile $script:Staged 'notes.txt' "first`nkeep`n(198.51.100.7).`n"
        Assert-Git $script:Staged @('add', 'notes.txt') | Out-Null
        (Invoke-Checker -Arguments @('--staged') -WorkDir $script:Staged).ExitCode | Should -Be 1
    }

    It 'does not report the tail of an allowed name as a hit of its own' {
        # alpha-beta.test is allowed; its tail beta.test, which follows a '-'
        # and matches on its own, must not count: the scan resumes after the
        # allowed text instead of inside it.
        $tail = New-ListFile 'tail-patterns.txt' @('re:([a-z0-9][a-z0-9-]*\.)+test', 'allow:alpha-beta\.test')
        $r = Test-StagedText $script:Staged "first`nkeep`nhost alpha-beta.test`n" @{ VIRTUSPHERE_NAME_PATTERNS = $tail }
        $r.ExitCode | Should -Be 0
        $r = Test-StagedText $script:Staged "first`nkeep`nhost gamma-beta.test`n" @{ VIRTUSPHERE_NAME_PATTERNS = $tail }
        $r.ExitCode | Should -Be 1
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

    It 'checks file names and masks fixed and pattern hits in the location' {
        Set-RepoFile $script:Staged ($script:Name + '.md') "plain`n"
        Assert-Git $script:Staged @('add', '-A') | Out-Null
        $r = Invoke-Checker -Arguments @('--staged') -WorkDir $script:Staged
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match '\*\*\*\.md \(Dateiname\): name-denylist\.txt:2'
        $r.Output | Should -Not -Match $script:NamePattern
        Assert-Git $script:Staged @('reset', '-q', '--hard', 'HEAD') | Out-Null
        Assert-Git $script:Staged @('clean', '-q', '-f', '-d') | Out-Null

        Set-RepoFile $script:Staged 'node-zeta.test' "plain`n"
        Assert-Git $script:Staged @('add', '-A') | Out-Null
        $r = Invoke-Checker -Arguments @('--staged') -WorkDir $script:Staged
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match 'TREFFER: \*\*\* \(Dateiname\): patterns\.txt:2'
        $r.Output | Should -Not -Match 'node-zeta'
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

Describe 'shipped pattern list .githooks/name-patterns.txt' {
    BeforeAll {
        $script:Shipped = New-TestRepo 'shipped'
        Set-RepoFile $script:Shipped 'probe.txt' "base`n"
        Assert-Git $script:Shipped @('add', 'probe.txt') | Out-Null
        Assert-Git $script:Shipped @('commit', '-q', '-m', 'base') | Out-Null
        $script:ShippedEnv = @{ VIRTUSPHERE_NAME_PATTERNS = $null; VIRTUSPHERE_NAME_DENYLIST = $null; HOME = ($script:EmptyHome -replace '\\', '/') }
    }
    AfterEach {
        Assert-Git $script:Shipped @('reset', '-q', '--hard', 'HEAD') | Out-Null
    }

    It 'passes documentation values, versions, OIDs and public hosts' {
        $clean = @(
            'ip 192.0.2.10 and 198.51.100.7:8021 and 203.0.113.0/24',
            'loopback 127.0.0.1:8080, any 0.0.0.0, mask 255.255.255.0',
            'version 5.1.17763.316, oid 2.5.4.3, build 10.0.17763.0, pin pyvmomi==9.1.0.0',
            'oid 1.3.6.1.4.1.311.21.7 and section 4.1.2.5 and placeholder 1.2.3.4',
            'host srv-a.example.test, dc.example.com, web.invalid',
            'docs https://learn.microsoft.com/de-de/ and https://github.com/x/y',
            'mac 00:00:5E:00:53:01 and AA-BB-CC-DD-EE-01',
            'share \\fileserver.example.test\packages',
            'site code XYZ in root\sms\site_XYZ'
        ) -join "`n"
        $r = Test-StagedText $script:Shipped ($clean + "`n") $script:ShippedEnv
        $r.ExitCode | Should -Be 0 -Because $r.Output
    }

    It 'catches each category without printing the value' {
        # Assembled at run time: the literal values would trip this file itself.
        $cases = @(
            @{ Label = 'private IPv4'; Text = ('gw ' + '10.' + '200.30.40') },
            @{ Label = 'public IPv4 with prefix'; Text = ('net ' + '8.8.4.' + '0/24') },
            @{ Label = 'bare public IPv4'; Text = ('dns ' + '8.8.' + '4.4') },
            @{ Label = 'bare IPv4 in quotes'; Text = ("host='" + '203.0.' + "114.9'") },
            @{ Label = 'four-part value next to an allowed version'; Text = ('pin ' + '9.1.0.' + '1') },
            @{ Label = 'UNC path'; Text = ('share ' + '\\' + 'filer9\data') },
            @{ Label = 'internal FQDN'; Text = ('host srv9.' + 'acme-corp.' + 'lan') },
            @{ Label = 'public FQDN'; Text = ('host portal.' + 'acme-corp.' + 'de') },
            @{ Label = 'site code'; Text = ('ns root\sms\' + 'site_' + 'Q7Z') },
            @{ Label = 'site code assignment'; Text = ('SiteCode = ' + "'" + 'Q7Z' + "'") },
            @{ Label = 'MAC'; Text = ('mac ' + '3C:5A:' + 'B4:01:02:03') }
        )
        foreach ($case in $cases) {
            $r = Test-StagedText $script:Shipped ($case.Text + "`n") $script:ShippedEnv
            $r.ExitCode | Should -Be 1 -Because $case.Label
            $r.Output | Should -Match 'TREFFER: probe\.txt:1: name-patterns\.txt:[0-9]+' -Because $case.Label
            $r.Output | Should -Not -Match ([regex]::Escape($case.Text.Split(' ')[-1])) -Because $case.Label
            Assert-Git $script:Shipped @('reset', '-q', '--hard', 'HEAD') | Out-Null
        }
    }

    It 'lets this repository''s pattern list, script and tests pass themselves' {
        foreach ($path in @('.githooks/name-patterns.txt', '.githooks/name-denylist.template.txt', 'scripts/check-name-denylist.sh', 'tests/powershell/VirtuSphere.NameDenylist.Tests.ps1')) {
            $text = [IO.File]::ReadAllText((Join-Path $script:RepoRoot $path))
            $r = Test-StagedText $script:Shipped $text $script:ShippedEnv
            $r.ExitCode | Should -Be 0 -Because ($path + ': ' + $r.Output)
            Assert-Git $script:Shipped @('reset', '-q', '--hard', 'HEAD') | Out-Null
        }
    }
}

Describe 'check-name-denylist.sh commit message, range and tree' {
    It 'finds a name in the message body and stops at the scissors line' {
        $msg = Join-Path $script:Work 'msg-hit.txt'
        Set-Content -LiteralPath $msg -Value @('Subject', '', ('Body mentions ' + $script:Name)) -Encoding Ascii
        $r = Invoke-Checker -Arguments @('--message', $msg)
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match 'Commit-Nachricht:3: name-denylist\.txt:2'
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
        $r.Output | Should -Match 'a\.txt:2: name-denylist\.txt:2'
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
        $r.Output | Should -Match 'Nachricht:1: name-denylist\.txt:2'
    }

    It 'checks every line and file name of a tree with --tree' {
        $repo = New-TestRepo 'tree'
        Set-RepoFile $repo 'a.txt' "base`nnode-ok.test`n"
        Assert-Git $repo @('add', 'a.txt') | Out-Null
        Assert-Git $repo @('commit', '-q', '-m', 'base') | Out-Null
        $r = Invoke-Checker -Arguments @('--tree', 'main') -WorkDir $repo
        $r.ExitCode | Should -Be 0
        $r.Output | Should -Match 'Stand main ohne Treffer'

        Set-RepoFile $repo 'b.txt' "one`ntwo node-x.test`n"
        Assert-Git $repo @('add', 'b.txt') | Out-Null
        Assert-Git $repo @('commit', '-q', '-m', 'more') | Out-Null
        $r = Invoke-Checker -Arguments @('--tree', 'main') -WorkDir $repo
        $r.ExitCode | Should -Be 1
        $r.Output | Should -Match 'TREFFER: b\.txt:2: patterns\.txt:2'
        $r.Output | Should -Not -Match 'node-x'
        (Invoke-Checker -Arguments @('--tree', 'no-such-ref') -WorkDir $repo).ExitCode | Should -Be 3
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
        $r.Output | Should -Match 'Commit-Nachricht:1: name-denylist\.txt:2'
        Get-Head $script:Hooked | Should -Be $before
    }

    It 'fails closed when the pattern list is missing' {
        $before = Get-Head $script:Hooked
        Set-RepoFile $script:Hooked 'a.txt' "base`nclean`n"
        $r = Invoke-TestGit -WorkDir $script:Hooked -Arguments @('commit', '-a', '-m', 'clean') -Environment @{ VIRTUSPHERE_NAME_PATTERNS = (Join-Path $script:Work 'absent.txt') }
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

    It 'uses the shipped pattern list next to the hooks and needs no private list' {
        $shippedEnv = @{ VIRTUSPHERE_NAME_PATTERNS = $null; VIRTUSPHERE_NAME_DENYLIST = $null; HOME = ($script:EmptyHome -replace '\\', '/') }
        $before = Get-Head $script:Hooked
        Set-RepoFile $script:Hooked 'a.txt' ("base`ngw " + '10.' + "200.30.40`n")
        $r = Invoke-TestGit -WorkDir $script:Hooked -Arguments @('commit', '-a', '-m', 'leak') -Environment $shippedEnv
        $r.ExitCode | Should -Not -Be 0
        $r.Output | Should -Match 'TREFFER: a\.txt:2: name-patterns\.txt:[0-9]+'
        $r.Output | Should -Match 'name-denylist\.hit'
        Get-Head $script:Hooked | Should -Be $before

        Set-RepoFile $script:Hooked 'a.txt' "base`ngw 192.0.2.1`n"
        $r = Invoke-TestGit -WorkDir $script:Hooked -Arguments @('commit', '-a', '-m', 'documentation address') -Environment $shippedEnv
        $r.ExitCode | Should -Be 0 -Because $r.Output
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
        $r.Output | Should -Match 'side\.txt:1: name-denylist\.txt:2'
        Get-Head $script:Hooked | Should -Be $before
    }
}
