# Contracts for the QA source volume qa-repo (OV1, 03.11.2026): the QA stack and
# phpunit-full read a verified copy of the checkout instead of the Docker Desktop
# 9p share. Selection, hash format, comparison, the stale-stack guard and the
# Compose override are pinned here; the copy itself is proven by qa-stack.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:ModuleDir = Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'check'
    . (Join-Path $script:ModuleDir 'qa-identity.ps1')
    . (Join-Path $script:ModuleDir 'runtime.ps1')

    function Invoke-Git {
        param([string]$Root, [string[]]$Arguments)
        $output = @(& git -C $Root @Arguments 2>&1)
        if ($LASTEXITCODE -ne 0) { throw ('git ' + ($Arguments -join ' ') + ': ' + ($output -join ' ')) }
    }

    function New-SourceFixture {
        $root = Join-Path $TestDrive ('src-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $root | Out-Null
        Invoke-Git $root @('init', '--quiet')
        Invoke-Git $root @('config', 'user.email', 'qa@example.invalid')
        Invoke-Git $root @('config', 'user.name', 'qa')
        Invoke-Git $root @('config', 'core.autocrlf', 'false')
        $files = @{
            '.gitignore'                            = "bin/`n.env`nqa-artifacts/`nnode_modules/`nDocker/WebAPI/var/`n"
            'Docker/WebAPI/lib/app.php'             = '<?php echo 1;'
            'Docker/WebAPI/lib/gone.php'            = '<?php echo 2;'
            'Docker/WebAPI/vendor/autoload.php'     = '<?php // tracked'
            'Ansible/site.yml'                      = '---'
        }
        foreach ($relative in $files.Keys) {
            $path = Join-Path $root $relative
            New-Item -ItemType Directory -Force -Path (Split-Path $path -Parent) | Out-Null
            [System.IO.File]::WriteAllText($path, $files[$relative])
        }
        Invoke-Git $root @('add', '-A')
        Invoke-Git $root @('commit', '--quiet', '-m', 'fixture')
        # Working-tree state after the commit: one change, one deletion, one new
        # file, ignored noise, the ignored Composer bin and a nested worktree.
        [System.IO.File]::WriteAllText((Join-Path $root 'Docker/WebAPI/lib/app.php'), '<?php echo 3;')
        Remove-Item -LiteralPath (Join-Path $root 'Docker/WebAPI/lib/gone.php')
        [System.IO.File]::WriteAllText((Join-Path $root 'Docker/WebAPI/lib/new.php'), '<?php')
        foreach ($noise in @('.env', 'Docker/WebAPI/.env', 'qa-artifacts/run/log.txt', 'tests/e2e/node_modules/x/index.js',
                'Docker/WebAPI/var/cache.bin', 'Docker/WebAPI/vendor/bin/phpunit', 'nested/inner.php')) {
            $path = Join-Path $root $noise
            New-Item -ItemType Directory -Force -Path (Split-Path $path -Parent) | Out-Null
            [System.IO.File]::WriteAllText($path, 'x')
        }
        # Another working tree inside this one (like .claude/worktrees): git
        # reports it as 'nested/' and it must not be copied.
        Invoke-Git (Join-Path $root 'nested') @('init', '--quiet')
        return $root
    }
}

Describe 'QA source selection (qa-repo)' {
    It 'takes tracked, changed and new files plus the Composer tree, and nothing ignored, deleted or nested' {
        $root = New-SourceFixture
        $selection = Get-QaSourceSelection $root
        $selection.Ok | Should -BeTrue
        @($selection.Files) | Should -Be @(
            '.gitignore', 'Ansible/site.yml', 'Docker/WebAPI/lib/app.php', 'Docker/WebAPI/lib/new.php',
            'Docker/WebAPI/vendor/autoload.php', 'Docker/WebAPI/vendor/bin/phpunit')
        @($selection.Directories) | Should -Be @(
            'Ansible', 'Docker', 'Docker/WebAPI', 'Docker/WebAPI/lib', 'Docker/WebAPI/vendor', 'Docker/WebAPI/vendor/bin')
    }

    It 'is an infrastructure failure outside a git checkout, not an empty copy' {
        $plain = Join-Path $TestDrive 'not-a-repo'
        New-Item -ItemType Directory -Force -Path $plain | Out-Null
        (Get-QaSourceSelection $plain).Ok | Should -BeFalse
    }
}

Describe 'QA source hashes' {
    It 'writes the sha256sum line format' {
        $root = Join-Path $TestDrive 'hash'
        New-Item -ItemType Directory -Force -Path (Join-Path $root 'a') | Out-Null
        [System.IO.File]::WriteAllText((Join-Path $root 'a/b.txt'), 'abc')
        ((Get-QaSourceHashLines $root @('a/b.txt')) -join '|') | Should -Be @('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad  a/b.txt')
    }

    It 'accepts only an identical file set and names every difference' {
        $a = 'a' * 64
        $b = 'b' * 64
        $expected = @("$a  x.php", "$b  y/z.php")
        # The functions return one array object; compare its elements, not a wrapper.
        $same = Compare-QaSourceHashes $expected @("$b  ./y/z.php", ' Container probe Created', "$a  ./x.php")
        $same.Count | Should -Be 0
        $diff = Compare-QaSourceHashes $expected @("$a  ./x.php", "$a  ./y/z.php", "$a  ./extra.php")
        ($diff -join '|') | Should -Be 'Inhalt weicht ab: y/z.php|nur im Volume: extra.php'
        $missing = Compare-QaSourceHashes $expected @("$a  ./x.php")
        ($missing -join '|') | Should -Be 'fehlt im Volume: y/z.php'
        $empty = Compare-QaSourceHashes @() @()
        ($empty -join '|') | Should -Be 'Checkout-Liste leer'
    }

    It 'gives one digest per content set, independent of line order' {
        $x = ('a' * 64) + '  x'
        $y = ('b' * 64) + '  y'
        $one = Get-QaSourceDigest @($x, $y)
        Get-QaSourceDigest @($y, $x) | Should -Be $one
        Get-QaSourceDigest @($x, (('c' * 64) + '  y')) | Should -Not -Be $one
    }
}

Describe 'QA source copy and stale-stack guard' {
    BeforeEach {
        $script:FixtureRoot = New-SourceFixture
        $repoRoot = $script:FixtureRoot
        $identity = Get-QaStackIdentity $repoRoot
        $qaProject = 'ov1-pester'
        $qaEnvFile = $identity.EnvFile
        $qaComposeOverride = $identity.ComposeOverride
        $artifactDir = Join-Path $TestDrive ('run-' + [guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $artifactDir | Out-Null
        $script:artifactDir = $artifactDir
        $script:DockerCalls = @()
        $script:VolumeLines = $null
        Remove-Variable -Name qaSourceManifest -Scope Script -ErrorAction SilentlyContinue
        Mock Invoke-Tool -ParameterFilter { $Exe -eq 'docker' } {
            $script:DockerCalls += , @($Arguments)
            $joined = $Arguments -join ' '
            if ($joined -match ' run .* qa-source$') {
                # What the container would report: by default the exact checkout.
                $lines = if ($null -ne $script:VolumeLines) { $script:VolumeLines } else {
                    @(Get-Content -LiteralPath (Join-Path $script:artifactDir 'qa-source.checkout.sha256') | ForEach-Object { $_ -replace '  ', '  ./' })
                }
                [System.IO.File]::WriteAllLines((Join-Path $script:artifactDir 'qa-source.volume.sha256'), [string[]]$lines)
            }
            if ($joined -match '--entrypoint cat qa-source') { return @{ ExitCode = 0; Output = $script:VolumeLines; StartFailed = $false } }
            return @{ ExitCode = 0; Output = @(); StartFailed = $false }
        }
    }

    It 'builds the runtime image, copies the NUL-separated list and passes on an identical volume' {
        $result = Sync-QaSourceVolume
        $result.class | Should -Be 'pass'
        $result.detail | Should -Match '6 Dateien'
        $result.detail | Should -Match 'Datei fuer Datei identisch'
        ($script:DockerCalls[0] -join ' ') | Should -Match ' build php$'
        ($script:DockerCalls[1] -join ' ') | Should -Match ' run --rm --no-deps -T -v .+:/qa-evidence qa-source$'
        ($script:DockerCalls[1] -join ' ') | Should -Match '-p ov1-pester '
        $list = [System.IO.File]::ReadAllText((Join-Path $script:artifactDir 'qa-source.files'))
        $entries = @($list.TrimEnd([char]0).Split([char]0))
        $entries[0] | Should -Be 'Ansible'
        $entries | Should -Contain 'Docker/WebAPI/vendor/bin/phpunit'
        $entries | Should -Not -Contain '.env'
        $origin = Get-Content -LiteralPath (Join-Path $script:artifactDir 'qa-source.origin.json') -Raw | ConvertFrom-Json
        $origin.commit | Should -Match '^[0-9a-f]{40}$'
        @($origin.changed) | Should -Contain ' M Docker/WebAPI/lib/app.php'
        @($origin.changed) | Should -Contain ' D Docker/WebAPI/lib/gone.php'
        $origin.files | Should -Be 6
    }

    It 'refuses a volume that differs from the checkout' {
        $script:VolumeLines = @(('0' * 64) + '  ./Docker/WebAPI/lib/app.php')
        $result = Sync-QaSourceVolume
        $result.class | Should -Be 'infrastructure_error'
        $result.detail | Should -Match 'qa-repo weicht vom Checkout ab'
        @($result.output) | Should -Contain 'Inhalt weicht ab: Docker/WebAPI/lib/app.php'
    }

    It 'skips the guard after this run proved the copy and refuses a stale standing stack otherwise' {
        $sync = Sync-QaSourceVolume
        $sync.class | Should -Be 'pass'
        $calls = $script:DockerCalls.Count
        Confirm-QaSourceCurrent | Should -BeNullOrEmpty
        $script:DockerCalls.Count | Should -Be $calls

        Remove-Variable -Name qaSourceManifest -Scope Script -ErrorAction SilentlyContinue
        $script:VolumeLines = @(Get-Content -LiteralPath (Join-Path $script:artifactDir 'qa-source.volume.sha256'))
        Confirm-QaSourceCurrent | Should -BeNullOrEmpty
        ($script:DockerCalls[-1] -join ' ') | Should -Match ' run --rm --no-deps -T --entrypoint cat qa-source /qa-repo/meta/qa-source.sha256$'

        Remove-Variable -Name qaSourceManifest -Scope Script -ErrorAction SilentlyContinue
        [System.IO.File]::WriteAllText((Join-Path $script:FixtureRoot 'Docker/WebAPI/lib/app.php'), '<?php echo 4;')
        $stale = Confirm-QaSourceCurrent
        $stale.class | Should -Be 'infrastructure_error'
        $stale.detail | Should -Match 'zuerst -Gate qa-stack'
        @($stale.output) | Should -Be @('Inhalt weicht ab: Docker/WebAPI/lib/app.php')
    }
}

Describe 'QA stack reads only the verified copy' {
    BeforeAll {
        $composeBase = (Get-Content -LiteralPath (Join-Path $script:RepoRoot 'docker-compose.yml') -Raw) -replace "`r`n", "`n"
        $composeOverride = (Get-Content -LiteralPath (Join-Path $script:RepoRoot 'Docker/qa/docker-compose.qa.yml') -Raw) -replace "`r`n", "`n"

        function Get-ServiceBlocks {
            param([string]$Compose)
            $blocks = @{}
            foreach ($match in [regex]::Matches($Compose, '(?ms)^  ([a-z][a-z0-9-]*):\n(.*?)(?=^  [a-z][a-z0-9-]*:\n|^[a-z]|\z)')) {
                $blocks[$match.Groups[1].Value] = $match.Groups[2].Value
            }
            return $blocks
        }
    }

    It 'replaces every working-tree bind of a QA service with a qa-repo or project volume on the same target' {
        # Derived from the base file: a new bind there must be answered here.
        $anchors = @{}
        foreach ($match in [regex]::Matches($composeOverride, '(?ms)^x-[a-z-]+: &([a-z-]+)\n(.*?)(?=^\S)')) {
            if ($match.Groups[2].Value -match '(?m)^  target: (\S+)$') { $anchors[$match.Groups[1].Value] = $Matches[1] }
        }
        $baseServices = Get-ServiceBlocks $composeBase
        $qaServices = Get-ServiceBlocks $composeOverride
        $checked = 0
        foreach ($service in (Get-QaStackIdentity 'C:/synthetic').Services) {
            if (-not $baseServices.ContainsKey($service)) { continue }
            $qaTargets = @()
            if ($qaServices.ContainsKey($service)) {
                foreach ($line in ($qaServices[$service] -split "`n")) {
                    # An anchor may be used as-is or merged with extra keys
                    # ("- <<: *qa-code" plus "read_only: true" on the webserver).
                    if ($line -match '^      - (?:<<: )?\*([a-z-]+)\s*$') { $qaTargets += $anchors[$Matches[1]] }
                    elseif ($line -match '^      - [a-z][a-z0-9-]*:(/[^:\s]+)') { $qaTargets += $Matches[1] }
                    elseif ($line -match '^        target: (\S+)$') { $qaTargets += $Matches[1] }
                }
            }
            foreach ($bind in [regex]::Matches($baseServices[$service], '(?m)^      - \./[^:\s]+:(/[^:\s]+)')) {
                $qaTargets | Should -Contain $bind.Groups[1].Value -Because ("{0} binds {1} from the working tree" -f $service, $bind.Groups[1].Value)
                $checked++
            }
        }
        $checked | Should -BeGreaterThan 5
        $composeOverride | Should -Not -Match '(?m)^      - \./'
    }

    It 'mounts code, playbooks, fixture and init schema from qa-repo/tree and keeps qa-source out of up' {
        foreach ($subpath in @('tree/Docker/WebAPI', 'tree/Ansible', 'tree/Docker/WebAPI/tests/fixtures/ldap', 'tree/Docker/mysql/mysql-init')) {
            $composeOverride | Should -Match ("(?m)^    subpath: " + [regex]::Escape($subpath) + '$')
        }
        $qaServices = Get-ServiceBlocks $composeOverride
        $qaServices['qa-source'] | Should -Match 'profiles: \["qa-source"\]'
        $qaServices['qa-source'] | Should -Match 'network_mode: none'
        $qaServices['qa-source'] | Should -Match '(?ms)target: /qa-source\n\s+read_only: true'
        (Get-QaStackIdentity 'C:/synthetic').Services | Should -Not -Contain 'qa-source'
        $composeOverride | Should -Match '(?m)^  qa-repo:$'
    }

    It 'fills the volume first in qa-stack and runs phpunit-full from it' {
        $integration = (Get-Content -LiteralPath (Join-Path $script:ModuleDir 'gates-integration.ps1') -Raw) -replace "`r`n", "`n"
        $integration.IndexOf('$source = Sync-QaSourceVolume') | Should -BeGreaterThan 0
        $integration.IndexOf('$source = Sync-QaSourceVolume') | Should -BeLessThan $integration.IndexOf('$logInit =')
        $integration | Should -Match ([regex]::Escape("'type=volume,source={0},target=/repo,volume-subpath=tree,volume-nocopy=true' -f `$qaIdentity.SourceVolume"))
        $phpunitFull = $integration.Substring($integration.IndexOf("Add-Gate -Name 'phpunit-full'"))
        $phpunitFull = $phpunitFull.Substring(0, $phpunitFull.IndexOf("Add-Gate -Name 'schema-convergence'"))
        $phpunitFull | Should -Not -Match ([regex]::Escape("(`$repoRoot + ':/repo')"))
        $phpunitFull.IndexOf('Confirm-QaSourceCurrent') | Should -BeLessThan $phpunitFull.IndexOf("'stop'")
        (Get-QaStackIdentity 'C:/synthetic').SourceVolume | Should -Be 'virtusphere-qa_qa-repo'
    }

    It 'attaches the phpunit-full container to every network of the stack before it starts (C-07)' {
        # Abgeleitet aus der Basis-Compose: jedes dort deklarierte Netz ausser
        # default muss die QA-Identitaet als <Projekt>_<Netz> kennen, sonst
        # erreicht der Testcontainer webserver:8080 nicht.
        $compose = (Get-Content -LiteralPath (Join-Path $script:RepoRoot 'docker-compose.yml') -Raw) -replace "`r`n", "`n"
        $block = [regex]::Match($compose, '(?ms)^networks:\n(.*?)(?=^\S|\z)').Groups[1].Value
        $declared = @([regex]::Matches($block, '(?m)^  ([A-Za-z0-9_.-]+):') | ForEach-Object { $_.Groups[1].Value })
        $declared | Should -Contain 'front'
        $identity = Get-QaStackIdentity 'C:/synthetic'
        foreach ($net in @($declared | Where-Object { $_ -ne 'default' })) {
            $identity.FrontNetwork | Should -Be ('{0}_{1}' -f $identity.Project, $net)
        }
        $integration = (Get-Content -LiteralPath (Join-Path $script:ModuleDir 'gates-integration.ps1') -Raw) -replace "`r`n", "`n"
        $phpunitFull = $integration.Substring($integration.IndexOf("Add-Gate -Name 'phpunit-full'"))
        $phpunitFull = $phpunitFull.Substring(0, $phpunitFull.IndexOf("Add-Gate -Name 'schema-convergence'"))
        $phpunitFull | Should -Match "'create', '--rm'"
        $phpunitFull | Should -Match "'--network', \`$qaNetwork"
        $connect = $phpunitFull.IndexOf("'network', 'connect', `$qaIdentity.FrontNetwork")
        $connect | Should -BeGreaterThan 0
        $connect | Should -BeLessThan $phpunitFull.IndexOf("'start', '--attach'")
        $phpunitFull | Should -Not -Match "Invoke-Tool 'docker' @\('run'"
    }

    It 'checks the copy before any Playwright run or worker pause' {
        $integration = (Get-Content -LiteralPath (Join-Path $script:ModuleDir 'gates-integration.ps1') -Raw) -replace "`r`n", "`n"
        $suite = $integration.Substring($integration.IndexOf('function Invoke-PlaywrightSuite'))
        $suite.IndexOf('Confirm-QaSourceCurrent') | Should -BeGreaterThan 0
        $suite.IndexOf('Confirm-QaSourceCurrent') | Should -BeLessThan $suite.IndexOf("'stop'")
        $runtime = (Get-Content -LiteralPath (Join-Path $script:ModuleDir 'runtime.ps1') -Raw) -replace "`r`n", "`n"
        $pause = $runtime.Substring($runtime.IndexOf('function Invoke-WithPausedQaWorkers'))
        $pause.IndexOf('Confirm-QaSourceCurrent') | Should -BeGreaterThan 0
        $pause.IndexOf('Confirm-QaSourceCurrent') | Should -BeLessThan $pause.IndexOf('Get-QaWorkerSnapshot')
    }
}
