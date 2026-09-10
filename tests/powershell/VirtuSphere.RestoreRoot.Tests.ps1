# Root-routing regression for the canonical restore-drill gate. The dynamic
# cases stop at a fake `docker image inspect`; they prove source selection and
# fail-closed behavior, not a database import, mount, migration or smoke run.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:RestoreScript = Join-Path (Join-Path $script:RepoRoot 'scripts') 'restore_test.sh'
    $script:CheckRunner = Join-Path (Join-Path $script:RepoRoot 'scripts') 'check.ps1'
    $script:CheckRuntime = Join-Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'check') 'runtime.ps1'
    $script:ReleaseGates = Join-Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'check') 'gates-release.ps1'
    $script:FixtureDir = Join-Path $PSScriptRoot 'fixtures\restore-root'
    $script:ShExe = (Get-Command sh -ErrorAction SilentlyContinue).Source
    $script:TarExe = (Get-Command tar -ErrorAction SilentlyContinue).Source
    $script:EngineExe = (Get-Process -Id $PID).Path

    function New-SyntheticRestoreRoot {
        param(
            [Parameter(Mandatory)][string]$Root,
            [Parameter(Mandatory)][string]$Stamp,
            [ValidateSet('complete', 'incomplete-triplet', 'empty-backups', 'incomplete-root')]
            [string]$State = 'complete'
        )
        $backupDir = Join-Path $Root 'Docker\backups'
        New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
        if ($State -eq 'incomplete-root') { return }

        foreach ($relative in @(
            'Docker\mysql\mysql-init\struktur.sql',
            'Docker\WebAPI\lib\migrate.php',
            'Docker\WebAPI\lib\directory_restore_converge.php',
            'Docker\WebAPI\tests\tools\restore-drill-probe.php'
        )) {
            $path = Join-Path $Root $relative
            New-Item -ItemType Directory -Path (Split-Path $path -Parent) -Force | Out-Null
            Set-Content -LiteralPath $path -Value ('fixture ' + $Stamp) -Encoding Ascii
        }
        if ($State -eq 'empty-backups') { return }

        $dump = Join-Path $backupDir ('db-{0}.sql.gz' -f $Stamp)
        $dumpBytes = [Text.Encoding]::UTF8.GetBytes("-- harmless synthetic dump $Stamp`n")
        $fileStream = [IO.File]::Create($dump)
        try {
            $gzip = New-Object IO.Compression.GZipStream -ArgumentList @(
                $fileStream, [IO.Compression.CompressionMode]::Compress
            )
            try { $gzip.Write($dumpBytes, 0, $dumpBytes.Length) } finally { $gzip.Dispose() }
        } finally { $fileStream.Dispose() }
        $gzipHeader = [IO.File]::ReadAllBytes($dump)
        if ($gzipHeader.Length -lt 2 -or $gzipHeader[0] -ne 0x1f -or $gzipHeader[1] -ne 0x8b) {
            throw 'synthetic dump is not a gzip archive'
        }
        if ($State -eq 'incomplete-triplet') { return }

        $archiveSource = Join-Path $Root ('archive-source-' + $Stamp)
        New-Item -ItemType Directory -Path $archiveSource -Force | Out-Null
        $archiveEnv = Join-Path $archiveSource '.env'
        Copy-Item -LiteralPath (Join-Path $script:FixtureDir 'archive.env') `
            -Destination $archiveEnv
        Copy-Item -LiteralPath (Join-Path $script:FixtureDir 'docker-compose.yml') `
            -Destination (Join-Path $archiveSource 'docker-compose.yml')
        if ($env:OS -ne 'Windows_NT') {
            & chmod '0600' $archiveEnv
            if ($LASTEXITCODE -ne 0) { throw 'synthetic archive .env could not be restricted to mode 0600' }
        }
        $config = Join-Path $backupDir ('config-{0}.tar.gz' -f $Stamp)
        $tarOutput = @(& $script:TarExe -czf $config -C $archiveSource '.env' 'docker-compose.yml' 2>&1)
        if ($LASTEXITCODE -ne 0) { throw ('synthetic tar failed: ' + ($tarOutput -join ' | ')) }
        $tarListing = @(& $script:TarExe -tzf $config 2>&1 | ForEach-Object { "$_" })
        if ($LASTEXITCODE -ne 0 -or $tarListing -notcontains '.env' -or $tarListing -notcontains 'docker-compose.yml') {
            throw ('synthetic config archive is incomplete: ' + ($tarListing -join ' | '))
        }

        $manifest = Join-Path $backupDir ('manifest-{0}.sha256' -f $Stamp)
        $dumpHash = (Get-FileHash -LiteralPath $dump -Algorithm SHA256).Hash.ToLowerInvariant()
        $configHash = (Get-FileHash -LiteralPath $config -Algorithm SHA256).Hash.ToLowerInvariant()
        $manifestLines = @(
            ('{0}  {1}' -f $dumpHash, (Split-Path $dump -Leaf)),
            ('{0}  {1}' -f $configHash, (Split-Path $config -Leaf))
        )
        [IO.File]::WriteAllText($manifest, ($manifestLines -join "`n") + "`n", [Text.Encoding]::ASCII)
    }

    function New-CanonicalSourceRoot {
        param([Parameter(Mandatory)][string]$Root, [Parameter(Mandatory)][string]$Stamp)
        New-SyntheticRestoreRoot -Root $Root -Stamp $Stamp
        $scripts = Join-Path $Root 'scripts'
        $moduleParent = Join-Path $scripts 'lib'
        New-Item -ItemType Directory -Path $scripts, $moduleParent -Force | Out-Null
        foreach ($file in @('check.ps1', 'tool-lock.json', 'restore_test.sh')) {
            Copy-Item -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') $file) `
                -Destination (Join-Path $scripts $file)
        }
        Copy-Item -LiteralPath (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'check') `
            -Destination $moduleParent -Recurse
    }

    function New-FakeDockerPath {
        param(
            [Parameter(Mandatory)][string]$Root,
            [string]$DockerFixture = 'fake-docker.sh'
        )
        New-Item -ItemType Directory -Path $Root -Force | Out-Null
        Copy-Item -LiteralPath (Join-Path $script:FixtureDir 'fake-docker.cmd') -Destination (Join-Path $Root 'docker.cmd')
        $shellFake = Join-Path $Root 'docker'
        Copy-Item -LiteralPath (Join-Path $script:FixtureDir $DockerFixture) -Destination $shellFake
        if ($env:OS -eq 'Windows_NT') {
            Copy-Item -LiteralPath (Join-Path $script:FixtureDir 'fake-sh.cmd') -Destination (Join-Path $Root 'sh.cmd')
        } else {
            $shellShim = Join-Path $Root 'sh'
            Copy-Item -LiteralPath (Join-Path $script:FixtureDir 'fake-sh.sh') -Destination $shellShim
            & chmod '+x' $shellFake $shellShim
            if ($LASTEXITCODE -ne 0) { throw 'fake docker or shell shim could not be made executable' }
        }
    }

    function Invoke-CanonicalSelectionCase {
        param(
            [Parameter(Mandatory)][string]$SourceRoot,
            [string]$CheckRoot,
            [switch]$UseDefault
        )
        $fakeBin = Join-Path $TestDrive ('fake-bin-' + [guid]::NewGuid().ToString('N'))
        $fakeLog = Join-Path $TestDrive ('fake-docker-' + [guid]::NewGuid().ToString('N') + '.log')
        New-FakeDockerPath -Root $fakeBin
        $oldPath = $env:PATH
        $oldRoot = $env:VIRTUSPHERE_CHECK_ROOT
        $oldLog = $env:VS_FAKE_DOCKER_LOG
        $oldPhpImage = $env:VIRTUSPHERE_PHP_IMAGE
        $oldMysqlImage = $env:VIRTUSPHERE_MYSQL_IMAGE
        $oldRealSh = $env:VS_REAL_SH
        try {
            $env:PATH = $fakeBin + [IO.Path]::PathSeparator + $oldPath
            $env:VS_FAKE_DOCKER_LOG = $fakeLog
            $env:VS_REAL_SH = $script:ShExe
            $env:VIRTUSPHERE_PHP_IMAGE = 'fixture-php-image-never-present'
            $env:VIRTUSPHERE_MYSQL_IMAGE = 'fixture-mysql-image-never-present'
            if ($UseDefault) { Remove-Item Env:VIRTUSPHERE_CHECK_ROOT -ErrorAction SilentlyContinue }
            else { $env:VIRTUSPHERE_CHECK_ROOT = $CheckRoot }

            $runnerShell = (Get-Command sh -ErrorAction SilentlyContinue).Source
            if (-not $runnerShell -or $runnerShell -notmatch 'fake-bin-') {
                throw 'shell shim does not own runner Find-Sh resolution; refusing canonical selection case'
            }
            $resolved = @(& $runnerShell -c 'command -v docker' 2>&1 | ForEach-Object { "$_" })
            if ($LASTEXITCODE -ne 0 -or ($resolved -join '') -notmatch 'fake-bin-') {
                throw 'fake docker does not own effective runner shell resolution; refusing canonical selection case'
            }
            $resolvedByRunner = (Get-Command docker -ErrorAction SilentlyContinue).Source
            if (-not $resolvedByRunner -or $resolvedByRunner -notmatch 'fake-bin-') {
                throw 'fake docker does not own runner command resolution; refusing canonical selection case'
            }
            $runner = Join-Path (Join-Path $SourceRoot 'scripts') 'check.ps1'
            $output = @(& $script:EngineExe -NoProfile -ExecutionPolicy Bypass -File $runner `
                -Lane Release -Gate restore-drill 2>&1 | ForEach-Object { "$_" })
            $exitCode = $LASTEXITCODE
            $calls = if (Test-Path -LiteralPath $fakeLog) { @(Get-Content -LiteralPath $fakeLog) } else { @() }
            return [pscustomobject]@{ ExitCode = $exitCode; Output = $output; DockerCalls = $calls }
        } finally {
            $env:PATH = $oldPath
            $env:VIRTUSPHERE_CHECK_ROOT = $oldRoot
            $env:VS_FAKE_DOCKER_LOG = $oldLog
            $env:VS_REAL_SH = $oldRealSh
            $env:VIRTUSPHERE_PHP_IMAGE = $oldPhpImage
            $env:VIRTUSPHERE_MYSQL_IMAGE = $oldMysqlImage
        }
    }
}

Describe 'Restore drill check-root contract' {
    BeforeAll {
        if (-not $script:ShExe) { throw 'sh is required for restore-root regression' }
        if (-not $script:TarExe) { throw 'tar is required to build harmless synthetic config archives' }
    }

    It 'derives every restore data source and repo mount from the selected root' {
        $restore = Get-Content -LiteralPath $script:RestoreScript -Raw
        $runtime = Get-Content -LiteralPath $script:CheckRuntime -Raw
        $release = Get-Content -LiteralPath $script:ReleaseGates -Raw

        $restore | Should -Match 'BACKUP_DIR="\$CHECK_ROOT/Docker/backups"'
        $restore | Should -Match 'SCHEMA_SQL="\$CHECK_ROOT/Docker/mysql/mysql-init/struktur\.sql"'
        $restore | Should -Match 'cd "\$CHECK_ROOT"[\s\S]+REPO_MOUNT="\$\(pwd -W'
        $restore | Should -Match 'config="\$BACKUP_DIR/config-\$ts\.tar\.gz"'
        $restore | Should -Match 'manifest="\$BACKUP_DIR/manifest-\$ts\.sha256"'
        $restore | Should -Match '< "\$SCHEMA_SQL"'
        ([regex]::Matches($restore, '-v "\$REPO_MOUNT:/repo"')).Count | Should -Be 3
        $restore | Should -Match 'run_php "\$APP_KEY" /repo/Docker/WebAPI/lib/migrate\.php'
        $restore | Should -Match 'PROBE=/repo/Docker/WebAPI/tests/tools/restore-drill-probe\.php'
        $runtime | Should -Match '\$scriptPath = \(Join-Path \$scriptDir \$ScriptName\)'
        $runtime | Should -Match '''VIRTUSPHERE_CHECK_ROOT=/checkroot'''
        $release | Should -Match "Invoke-CheckShell 'restore_test\.sh'"
    }

    It 'selects only root B through the canonical runner and starts no restore container' {
        $sourceA = Join-Path $TestDrive 'source-a'
        $rootB = Join-Path $TestDrive 'source-a-shadow'
        New-CanonicalSourceRoot -Root $sourceA -Stamp 'fixture-a'
        New-SyntheticRestoreRoot -Root $rootB -Stamp 'fixture-b'

        $result = Invoke-CanonicalSelectionCase -SourceRoot $sourceA -CheckRoot $rootB
        $text = $result.Output -join "`n"
        $result.ExitCode | Should -Be 2
        $text | Should -Match 'Pruefroot=.*source-a-shadow; Backup-Stand=fixture-b'
        $text | Should -Match 'Backup-Stand=fixture-b'
        $text | Should -Not -Match 'Backup-Stand=fixture-a'
        $result.DockerCalls | Should -Contain 'image inspect fixture-php-image-never-present'
        @($result.DockerCalls | Where-Object { $_ -match '^(run|exec)\s|^network\s+create' }).Count | Should -Be 0
    }

    It 'does not fall back to complete A when B has an incomplete triplet' {
        $sourceA = Join-Path $TestDrive 'complete-a'
        $rootB = Join-Path $TestDrive 'incomplete-b'
        New-CanonicalSourceRoot -Root $sourceA -Stamp 'complete-a'
        New-SyntheticRestoreRoot -Root $rootB -Stamp 'incomplete-b' -State incomplete-triplet

        $result = Invoke-CanonicalSelectionCase -SourceRoot $sourceA -CheckRoot $rootB
        $text = $result.Output -join "`n"
        $result.ExitCode | Should -Be 1
        $text | Should -Match '\[restore\.backup-incomplete\]'
        $text | Should -Match 'incomplete-b'
        $text | Should -Not -Match 'Backup-Stand=complete-a'
        @($result.DockerCalls | Where-Object { $_ -match '^image\s+inspect|^(run|exec)\s|^network\s+create' }).Count | Should -Be 0
    }

    It 'fails missing, empty and incomplete B roots without consulting A' {
        $sourceA = Join-Path $TestDrive 'fallback-a'
        New-CanonicalSourceRoot -Root $sourceA -Stamp 'fallback-a'
        $emptyB = Join-Path $TestDrive 'empty-b'
        New-SyntheticRestoreRoot -Root $emptyB -Stamp 'empty-b' -State empty-backups
        $incompleteB = Join-Path $TestDrive 'root-incomplete-b'
        New-SyntheticRestoreRoot -Root $incompleteB -Stamp 'root-incomplete-b' -State incomplete-root
        $missingB = Join-Path $TestDrive 'missing-b'

        $empty = Invoke-CanonicalSelectionCase -SourceRoot $sourceA -CheckRoot $emptyB
        $incomplete = Invoke-CanonicalSelectionCase -SourceRoot $sourceA -CheckRoot $incompleteB
        $missing = Invoke-CanonicalSelectionCase -SourceRoot $sourceA -CheckRoot $missingB
        $empty.ExitCode | Should -Be 2
        $incomplete.ExitCode | Should -Be 2
        $missing.ExitCode | Should -Be 2
        ($empty.Output -join "`n") | Should -Match '\[restore\.backup-missing\]'
        ($incomplete.Output -join "`n") | Should -Match '\[restore\.root-incomplete\]'
        ($missing.Output -join "`n") | Should -Match '\[restore\.root-missing\]'
        ($empty.Output -join "`n") | Should -Not -Match 'Backup-Stand=fallback-a'
        ($incomplete.Output -join "`n") | Should -Not -Match 'Backup-Stand=fallback-a'
        ($missing.Output -join "`n") | Should -Not -Match 'Backup-Stand=fallback-a'
        $startCalls = @($empty.DockerCalls) + @($incomplete.DockerCalls) + @($missing.DockerCalls) |
            Where-Object { $_ -match '^(run|exec)\s|^network\s+create' }
        @($startCalls).Count | Should -Be 0
    }

    It 'keeps the canonical default bound to the checker source root' {
        $sourceA = Join-Path $TestDrive 'default-a'
        New-CanonicalSourceRoot -Root $sourceA -Stamp 'default-a'

        $result = Invoke-CanonicalSelectionCase -SourceRoot $sourceA -UseDefault
        $result.ExitCode | Should -Be 2
        ($result.Output -join "`n") | Should -Match 'Backup-Stand=default-a'
        $result.DockerCalls | Should -Contain 'image inspect fixture-php-image-never-present'
    }

    It 'rejects an explicitly empty shell root before the runner can normalize it' {
        $sourceA = Join-Path $TestDrive 'empty-env-a'
        New-CanonicalSourceRoot -Root $sourceA -Stamp 'empty-env-a'
        $scriptPath = (Join-Path (Join-Path $sourceA 'scripts') 'restore_test.sh') -replace '\\', '/'
        $output = @(& $script:ShExe -c 'VIRTUSPHERE_CHECK_ROOT= sh "$1" 2>&1' 'restore-empty-root' $scriptPath |
            ForEach-Object { "$_" })
        $LASTEXITCODE | Should -Be 2
        ($output -join "`n") | Should -Match '\[restore\.root-empty\]'
    }
}



Describe 'Restore and backup resource safety' {
    BeforeAll {
        function Set-CompleteSyntheticDump {
            param([Parameter(Mandatory)][string]$Root, [Parameter(Mandatory)][string]$Stamp)
            $backupDir = Join-Path $Root 'Docker\backups'
            $dump = Join-Path $backupDir ('db-{0}.sql.gz' -f $Stamp)
            $lines = New-Object System.Collections.Generic.List[string]
            $lines.Add('-- Current Database: `fixture_restore`')
            foreach ($position in 1..10) {
                $lines.Add(('CREATE TABLE fixture_{0} (id INT);' -f $position))
            }
            $bytes = [Text.Encoding]::UTF8.GetBytes(($lines -join "`n") + "`n")
            $stream = [IO.File]::Create($dump)
            try {
                $gzip = New-Object IO.Compression.GZipStream -ArgumentList @(
                    $stream, [IO.Compression.CompressionMode]::Compress
                )
                try { $gzip.Write($bytes, 0, $bytes.Length) } finally { $gzip.Dispose() }
            } finally { $stream.Dispose() }
            $config = Join-Path $backupDir ('config-{0}.tar.gz' -f $Stamp)
            $manifest = Join-Path $backupDir ('manifest-{0}.sha256' -f $Stamp)
            $manifestLines = @(
                ('{0}  {1}' -f (Get-FileHash -LiteralPath $dump -Algorithm SHA256).Hash.ToLowerInvariant(), (Split-Path $dump -Leaf)),
                ('{0}  {1}' -f (Get-FileHash -LiteralPath $config -Algorithm SHA256).Hash.ToLowerInvariant(), (Split-Path $config -Leaf))
            )
            [IO.File]::WriteAllText($manifest, ($manifestLines -join "`n") + "`n", [Text.Encoding]::ASCII)
        }

        function Invoke-WithSafetyDocker {
            param(
                [Parameter(Mandatory)][string]$ScriptPath,
                [Parameter(Mandatory)][string]$Mode,
                [string]$CheckRoot,
                [string]$BackupContainer = 'fixture-backup-mysql-never-real'
            )
            $fakeBin = Join-Path $TestDrive ('safety-bin-' + [guid]::NewGuid().ToString('N'))
            $fakeLog = Join-Path $TestDrive ('safety-' + [guid]::NewGuid().ToString('N') + '.log')
            $fakeState = Join-Path $TestDrive ('safety-state-' + [guid]::NewGuid().ToString('N'))
            New-FakeDockerPath -Root $fakeBin -DockerFixture 'fake-docker-safety.sh'
            New-Item -ItemType Directory -Path $fakeState -Force | Out-Null
            $saved = @{
                PATH = $env:PATH
                VS_FAKE_DOCKER_LOG = $env:VS_FAKE_DOCKER_LOG
                VS_FAKE_DOCKER_MODE = $env:VS_FAKE_DOCKER_MODE
                VS_FAKE_DOCKER_STATE = $env:VS_FAKE_DOCKER_STATE
                VS_REAL_SH = $env:VS_REAL_SH
                VS_SAFETY_SCRIPT = $env:VS_SAFETY_SCRIPT
                VIRTUSPHERE_CHECK_ROOT = $env:VIRTUSPHERE_CHECK_ROOT
                VIRTUSPHERE_PHP_IMAGE = $env:VIRTUSPHERE_PHP_IMAGE
                VIRTUSPHERE_MYSQL_IMAGE = $env:VIRTUSPHERE_MYSQL_IMAGE
                VIRTUSPHERE_MYSQL_CONTAINER = $env:VIRTUSPHERE_MYSQL_CONTAINER
                VIRTUSPHERE_BACKUP_SCHEDULE = $env:VIRTUSPHERE_BACKUP_SCHEDULE
                TZ = $env:TZ
            }
            try {
                # Product shell paths (notably mktemp) need that shell's POSIX
                # tools. Archive construction above keeps its explicit native tar.
                $env:PATH = $fakeBin + [IO.Path]::PathSeparator + (Split-Path $script:ShExe -Parent) + [IO.Path]::PathSeparator + $saved.PATH
                $env:VS_FAKE_DOCKER_LOG = $fakeLog
                $env:VS_FAKE_DOCKER_MODE = $Mode
                $env:VS_FAKE_DOCKER_STATE = $fakeState
                $env:VS_REAL_SH = $script:ShExe.Replace('\', '/')
                $env:VS_SAFETY_SCRIPT = $ScriptPath.Replace('\', '/')
                $env:VIRTUSPHERE_CHECK_ROOT = $CheckRoot
                $env:VIRTUSPHERE_PHP_IMAGE = 'fixture-php-never-real'
                $env:VIRTUSPHERE_MYSQL_IMAGE = 'fixture-mysql-never-real'
                $env:VIRTUSPHERE_MYSQL_CONTAINER = $BackupContainer
                $env:VIRTUSPHERE_BACKUP_SCHEDULE = '0 0 * * *'
                $env:TZ = 'UTC'

                $runnerShell = (Get-Command sh -ErrorAction SilentlyContinue).Source
                if (-not $runnerShell -or $runnerShell -notmatch 'safety-bin-') {
                    throw 'shell shim does not own safety fixture shell resolution'
                }
                $resolved = @(& $runnerShell -c 'command -v docker' 2>&1 | ForEach-Object { "$_" })
                if ($LASTEXITCODE -ne 0 -or ($resolved -join '') -notmatch 'safety-bin-.+/docker$') {
                    throw 'fake docker does not own the effective shell command'
                }
                $resolvedByRunner = (Get-Command docker -ErrorAction SilentlyContinue).Source
                if (-not $resolvedByRunner -or $resolvedByRunner -notmatch 'safety-bin-') {
                    throw 'fake docker does not own PowerShell command resolution'
                }

                # Redirect inside the shell so Windows PowerShell 5.1 does not
                # promote expected native stderr to an error record. The shell
                # is passed explicitly and never resolved through fixture PATH.
                $captureScript = Join-Path $fakeBin 'capture.sh'
                [IO.File]::WriteAllText($captureScript, "#!/bin/sh`n" + 'exec "$VS_REAL_SH" "$VS_SAFETY_SCRIPT" 2>&1' + "`n", [Text.UTF8Encoding]::new($false))
                $output = @(& $script:ShExe $captureScript |
                    ForEach-Object { "$_" })
                $scriptExit = $LASTEXITCODE
                if ($scriptExit -ne 0) { $output | ForEach-Object { Write-Host ('    synthetic safety: ' + $_) } }
                [pscustomobject]@{
                    ExitCode = $scriptExit
                    Output = $output
                    DockerCalls = if (Test-Path -LiteralPath $fakeLog) { @(Get-Content -LiteralPath $fakeLog) } else { @() }
                    StatePath = $fakeState
                }
            } finally {
                foreach ($name in $saved.Keys) {
                    if ($null -eq $saved[$name]) {
                        Remove-Item -LiteralPath ('Env:' + $name) -ErrorAction SilentlyContinue
                    } else {
                        Set-Item -LiteralPath ('Env:' + $name) -Value $saved[$name]
                    }
                }
            }
        }

        function Invoke-RestoreSafety {
            param([Parameter(Mandatory)][string]$Mode, [switch]$FullDump)
            $stamp = $Mode
            $root = Join-Path $TestDrive ('restore-' + $Mode)
            New-CanonicalSourceRoot -Root $root -Stamp $stamp
            if ($FullDump) { Set-CompleteSyntheticDump -Root $root -Stamp $stamp }
            $scriptPath = Join-Path (Join-Path $root 'scripts') 'restore_test.sh'
            Invoke-WithSafetyDocker -ScriptPath $scriptPath -Mode $Mode -CheckRoot $root
        }

        function Invoke-BackupSafety {
            param([Parameter(Mandatory)][string]$Mode)
            $root = Join-Path $TestDrive ('backup-' + $Mode)
            $scripts = Join-Path $root 'scripts'
            New-Item -ItemType Directory -Path $scripts -Force | Out-Null
            Copy-Item -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') 'backup.sh') -Destination $scripts
            Set-Content -LiteralPath (Join-Path $root 'docker-compose.yml') -Value 'services: {}' -Encoding Ascii
            Invoke-WithSafetyDocker -ScriptPath (Join-Path $scripts 'backup.sh') -Mode $Mode -CheckRoot $root
        }
    }

    It 'does not clean up PID-derived names after a failure before resource creation' {
        $root = Join-Path $TestDrive 'restore-early-failure'
        New-CanonicalSourceRoot -Root $root -Stamp 'early-failure'
        Add-Content -LiteralPath (Join-Path $root 'Docker\backups\db-early-failure.sql.gz') -Value 'break-manifest'
        $result = Invoke-WithSafetyDocker -ScriptPath (Join-Path $root 'scripts\restore_test.sh') -Mode early-failure -CheckRoot $root
        $result.ExitCode | Should -Be 1
        @($result.DockerCalls | Where-Object { $_ -match '^(rm|run|create|start)\s|^network\s+(create|rm)' }).Count | Should -Be 0
    }

    It 'fails closed on a generated network-name collision without mutation' {
        $result = Invoke-RestoreSafety -Mode collision
        $result.ExitCode | Should -Be 1
        ($result.Output -join "`n") | Should -Match '\[restore\.resource-collision\]'
        @($result.DockerCalls | Where-Object { $_ -match '^(rm|run|create|start)\s|^network\s+(create|rm)' }).Count | Should -Be 0
    }

    It 'cleans only its exact IDs after a successful synthetic drill' {
        $result = Invoke-RestoreSafety -Mode success -FullDump
        $result.ExitCode | Should -Be 0
        $result.DockerCalls | Should -Contain ('rm -f ' + ('c' * 64))
        $result.DockerCalls | Should -Contain ('rm -f ' + ('b' * 64))
        $result.DockerCalls | Should -Contain ('network rm ' + ('a' * 64))
        ($result.Output -join "`n") | Should -Match '\[9/9\] pass cleanup'
    }

    It 'keeps ownership after create when database start fails and cleans by exact ID' {
        $result = Invoke-RestoreSafety -Mode start-failure
        $result.ExitCode | Should -Be 2
        $result.DockerCalls | Should -Contain ('rm -f ' + ('b' * 64))
        $result.DockerCalls | Should -Contain ('network rm ' + ('a' * 64))
    }

    It 'refuses cleanup when the recorded ID no longer has the run label' {
        $result = Invoke-RestoreSafety -Mode identity-mismatch -FullDump
        $result.ExitCode | Should -Be 1
        ($result.Output -join "`n") | Should -Match 'Cleanup verweigert fremden'
        @($result.DockerCalls | Where-Object { $_ -match '^rm -f [bc]{64}$' }).Count | Should -Be 0
    }

    It 'turns an exact-ID docker rm failure into a failed drill' {
        $result = Invoke-RestoreSafety -Mode cleanup-rm-failure -FullDump
        $result.ExitCode | Should -Be 1
        ($result.Output -join "`n") | Should -Match '\[9/9\] fail cleanup'
    }

    It 'cleans recorded IDs when TERM arrives after create and before start completes' {
        $result = Invoke-RestoreSafety -Mode signal
        $result.ExitCode | Should -Be 130
        $result.DockerCalls | Should -Contain ('rm -f ' + ('b' * 64))
        $result.DockerCalls | Should -Contain ('network rm ' + ('a' * 64))
    }

    It 'rejects a greater-than-10KiB partial mysqldump with its real exit status' {
        $result = Invoke-BackupSafety -Mode partial-dump
        $result.ExitCode | Should -Be 1
        ($result.Output -join "`n") | Should -Match 'mysqldump schlug fehl'
        $root = Join-Path $TestDrive 'backup-partial-dump'
        @(Get-ChildItem -LiteralPath (Join-Path $root 'Docker\backups') -File -Filter 'db-*.sql.gz').Count | Should -Be 0
        @(Get-ChildItem -LiteralPath (Join-Path $root 'Docker\backups') -File -Filter '.db-*.sql').Count | Should -Be 0
        $status = Get-Content -LiteralPath (Join-Path $root 'Docker\backups\status\backup-status.jsonl') | Select-Object -Last 1 | ConvertFrom-Json
        $status.status | Should -Be 'failed'
        $result.DockerCalls | Should -Contain 'exec fixture-backup-mysql-never-real true'
        (Get-Content -LiteralPath (Join-Path $script:FixtureDir 'fake-docker-safety.sh') -Raw) | Should -Match 'head -c 32768 /dev/urandom'
    }

    It 'cleans the protected raw dump and records failure when TERM interrupts mysqldump' {
        $result = Invoke-BackupSafety -Mode backup-signal
        $result.ExitCode | Should -Be 130
        $root = Join-Path $TestDrive 'backup-backup-signal'
        @(Get-ChildItem -LiteralPath (Join-Path $root 'Docker\backups') -File -Filter '.db-*.sql').Count | Should -Be 0
        $status = Get-Content -LiteralPath (Join-Path $root 'Docker\backups\status\backup-status.jsonl') | Select-Object -Last 1 | ConvertFrom-Json
        $status.status | Should -Be 'failed'
    }
}
