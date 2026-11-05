# Root-routing regression for the canonical restore-drill gate. The dynamic
# cases stop at a fake `docker image inspect`; they prove source selection and
# fail-closed behavior, not a database import, mount, migration or smoke run.
# MP1 adds the backup directory as a second, separately selectable source.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:RestoreScript = Join-Path (Join-Path $script:RepoRoot 'scripts') 'restore_test.sh'
    $script:CheckRunner = Join-Path (Join-Path $script:RepoRoot 'scripts') 'check.ps1'
    $script:CheckRuntime = Join-Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'check') 'runtime.ps1'
    $script:ReleaseGates = Join-Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'check') 'gates-release.ps1'
    $script:FixtureDir = Join-Path $PSScriptRoot 'fixtures\restore-root'
    . $script:CheckRuntime
    $script:ShExe = Find-Sh
    $script:TarExe = (Get-Command tar -ErrorAction SilentlyContinue).Source
    $script:EngineExe = (Get-Process -Id $PID).Path

    function New-SyntheticRestoreRoot {
        param(
            [Parameter(Mandatory)][string]$Root,
            [Parameter(Mandatory)][string]$Stamp,
            [ValidateSet('complete', 'incomplete-triplet', 'empty-backups', 'incomplete-root')]
            [string]$State = 'complete',
            [string[]]$ExtraArchiveEnv = @()
        )
        $backupDir = Join-Path $Root 'Docker\backups'
        New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
        if ($State -eq 'incomplete-root') { return }

        foreach ($relative in @(
            'Docker\mysql\mysql-init\struktur.sql',
            'Docker\WebAPI\lib\migrate.php',
            'Docker\WebAPI\lib\directory_restore_converge.php',
            'Docker\WebAPI\lib\package_report_restore_converge.php',
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
        if ($ExtraArchiveEnv.Count -gt 0) {
            $archiveText = [IO.File]::ReadAllText($archiveEnv).TrimEnd([char]13, [char]10)
            [IO.File]::WriteAllText($archiveEnv, $archiveText + "`n" + (($ExtraArchiveEnv) -join "`n") + "`n", [Text.UTF8Encoding]::new($false))
        }
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
        param([Parameter(Mandatory)][string]$Root, [Parameter(Mandatory)][string]$Stamp, [string[]]$ExtraArchiveEnv = @())
        New-SyntheticRestoreRoot -Root $Root -Stamp $Stamp -ExtraArchiveEnv $ExtraArchiveEnv
        $scripts = Join-Path $Root 'scripts'
        $moduleParent = Join-Path $scripts 'lib'
        New-Item -ItemType Directory -Path $scripts, $moduleParent -Force | Out-Null
        foreach ($file in @('check.ps1', 'tool-lock.json', 'restore_test.sh')) {
            Copy-Item -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') $file) `
                -Destination (Join-Path $scripts $file)
        }
        Copy-Item -LiteralPath (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'check') `
            -Destination $moduleParent -Recurse
        Copy-Item -LiteralPath (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'backup_crypto.sh') `
            -Destination $moduleParent
        Copy-Item -LiteralPath (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'env_file.sh') `
            -Destination $moduleParent
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
            [switch]$UseDefault,
            [string]$BackupDir = ''
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
        $oldBackupDir = $env:VIRTUSPHERE_RESTORE_BACKUP_DIR
        try {
            $realShBin = Split-Path $script:ShExe -Parent
            $env:PATH = $fakeBin + [IO.Path]::PathSeparator + $realShBin + [IO.Path]::PathSeparator + $oldPath
            $env:VS_FAKE_DOCKER_LOG = $fakeLog
            $env:VS_REAL_SH = $script:ShExe
            $env:VIRTUSPHERE_PHP_IMAGE = 'fixture-php-image-never-present'
            $env:VIRTUSPHERE_MYSQL_IMAGE = 'fixture-mysql-image-never-present'
            if ($UseDefault) { Remove-Item Env:VIRTUSPHERE_CHECK_ROOT -ErrorAction SilentlyContinue }
            else { $env:VIRTUSPHERE_CHECK_ROOT = $CheckRoot }
            if ($BackupDir -ne '') { $env:VIRTUSPHERE_RESTORE_BACKUP_DIR = $BackupDir }
            else { Remove-Item Env:VIRTUSPHERE_RESTORE_BACKUP_DIR -ErrorAction SilentlyContinue }

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
            $env:VIRTUSPHERE_RESTORE_BACKUP_DIR = $oldBackupDir
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
        $restore | Should -Match 'run_php "\$APP_KEY" /repo/Docker/WebAPI/lib/package_report_restore_converge\.php'
        $restore | Should -Match '\$generation_before" != "\$generation_after"'
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
        $result.DockerCalls | Should -Contain 'image inspect --format {{.Id}} fixture-php-image-never-present'
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
        ($result.Output -join "`n") | Should -Match 'Backupverzeichnis=.*default-a/Docker/backups \(aus Pruefroot\)'
        $result.DockerCalls | Should -Contain 'image inspect --format {{.Id}} fixture-php-image-never-present'
    }

    It 'rejects an explicitly empty shell root before the runner can normalize it' {
        $sourceA = Join-Path $TestDrive 'empty-env-a'
        New-CanonicalSourceRoot -Root $sourceA -Stamp 'empty-env-a'
        $scriptPath = (Join-Path (Join-Path $sourceA 'scripts') 'restore_test.sh') -replace '\\', '/'
        $oldPath = $env:PATH
        try {
            $env:PATH = (Split-Path $script:ShExe -Parent) + [IO.Path]::PathSeparator + $oldPath
            $output = @(& $script:ShExe -c 'VIRTUSPHERE_CHECK_ROOT= sh "$1" 2>&1' 'restore-empty-root' $scriptPath |
                ForEach-Object { "$_" })
            $exitCode = $LASTEXITCODE
        } finally {
            $env:PATH = $oldPath
        }
        $exitCode | Should -Be 2
        ($output -join "`n") | Should -Match '\[restore\.root-empty\]'
    }

    # MP1: the backup directory is a second source next to the check root. A new
    # clone as check root reads the backup of the running checkout in place.
    It 'MP1: reads the triplet from VIRTUSPHERE_RESTORE_BACKUP_DIR and the code from the check root' {
        $sourceA = Join-Path $TestDrive 'mp1-code-a'
        New-CanonicalSourceRoot -Root $sourceA -Stamp 'mp1-code-a'
        $cloneB = Join-Path $TestDrive 'mp1-clone-b'
        New-SyntheticRestoreRoot -Root $cloneB -Stamp 'mp1-clone-b'
        Remove-Item -LiteralPath (Join-Path $cloneB 'Docker\backups') -Recurse -Force
        $backupRoot = Join-Path $TestDrive 'mp1-running'
        New-SyntheticRestoreRoot -Root $backupRoot -Stamp 'mp1-nightly'
        $backupDir = Join-Path $backupRoot 'Docker\backups'

        $result = Invoke-CanonicalSelectionCase -SourceRoot $sourceA -CheckRoot $cloneB -BackupDir $backupDir
        $text = $result.Output -join "`n"
        $result.ExitCode | Should -Be 2 -Because 'the fake image inspect stops the drill after the selection'
        $text | Should -Match 'Pruefroot=.*mp1-clone-b; Backup-Stand=mp1-nightly'
        $text | Should -Match 'Backupverzeichnis=.*mp1-running/Docker/backups \(aus VIRTUSPHERE_RESTORE_BACKUP_DIR\)'
        $text | Should -Not -Match 'Backup-Stand=mp1-code-a'
        $text | Should -Not -Match '\[restore\.root-incomplete\]' -Because 'a clone without Docker/backups is a valid check root once the backup directory is named'
        $result.DockerCalls | Should -Contain 'image inspect --format {{.Id}} fixture-php-image-never-present'
        @($result.DockerCalls | Where-Object { $_ -match '^(run|exec)\s|^network\s+create' }).Count | Should -Be 0
    }

    It 'MP1: fails a missing or tripletless backup directory without falling back to the check root' {
        $sourceA = Join-Path $TestDrive 'mp1-fallback-a'
        New-CanonicalSourceRoot -Root $sourceA -Stamp 'mp1-fallback-a'
        $emptyRoot = Join-Path $TestDrive 'mp1-empty-backups'
        New-SyntheticRestoreRoot -Root $emptyRoot -Stamp 'mp1-empty' -State empty-backups
        $missingDir = Join-Path $TestDrive 'mp1-missing-backups'

        $missing = Invoke-CanonicalSelectionCase -SourceRoot $sourceA -CheckRoot $sourceA -BackupDir $missingDir
        $empty = Invoke-CanonicalSelectionCase -SourceRoot $sourceA -CheckRoot $sourceA -BackupDir (Join-Path $emptyRoot 'Docker\backups')
        $missing.ExitCode | Should -Be 2
        $empty.ExitCode | Should -Be 2
        ($missing.Output -join "`n") | Should -Match '\[restore\.backup-dir-missing\]'
        ($empty.Output -join "`n") | Should -Match '\[restore\.backup-missing\]'
        ($missing.Output -join "`n") | Should -Not -Match 'Backup-Stand=mp1-fallback-a'
        ($empty.Output -join "`n") | Should -Not -Match 'Backup-Stand=mp1-fallback-a'
        @(@($missing.DockerCalls) + @($empty.DockerCalls) | Where-Object { $_ -match '^image\s+inspect|^(run|exec)\s|^network\s+create' }).Count | Should -Be 0
    }

    It 'MP1: rejects an explicitly empty backup directory instead of using the check root' {
        $sourceA = Join-Path $TestDrive 'mp1-empty-env-a'
        New-CanonicalSourceRoot -Root $sourceA -Stamp 'mp1-empty-env-a'
        $scriptPath = (Join-Path (Join-Path $sourceA 'scripts') 'restore_test.sh') -replace '\\', '/'
        $oldPath = $env:PATH
        try {
            $env:PATH = (Split-Path $script:ShExe -Parent) + [IO.Path]::PathSeparator + $oldPath
            $output = @(& $script:ShExe -c 'VIRTUSPHERE_CHECK_ROOT="$2" VIRTUSPHERE_RESTORE_BACKUP_DIR= sh "$1" 2>&1' 'restore-empty-backup-dir' $scriptPath ($sourceA -replace '\\', '/') |
                ForEach-Object { "$_" })
            $exitCode = $LASTEXITCODE
        } finally {
            $env:PATH = $oldPath
        }
        $exitCode | Should -Be 2
        ($output -join "`n") | Should -Match '\[restore\.backup-dir-empty\]'
        ($output -join "`n") | Should -Not -Match 'Backup-Stand=mp1-empty-env-a'
    }

    It 'MP1: names the check-root commit, a changed work tree, and unknown outside Git' {
        $git = (Get-Command git -ErrorAction SilentlyContinue).Source
        if (-not $git) { throw 'git is required to prove the check-root commit line' }
        $sourceA = Join-Path $TestDrive 'mp1-commit-a'
        New-CanonicalSourceRoot -Root $sourceA -Stamp 'mp1-commit-a'
        $plain = Invoke-CanonicalSelectionCase -SourceRoot $sourceA -CheckRoot $sourceA
        ($plain.Output -join "`n") | Should -Match 'Pruefroot-Commit=unbekannt'

        $gitArgs = @('-C', $sourceA, '-c', 'core.autocrlf=false', '-c', 'user.name=fixture', '-c', 'user.email=fixture@example.test', '-c', 'commit.gpgsign=false')
        & $git @gitArgs init -q
        & $git @gitArgs add -A
        & $git @gitArgs commit -q -m 'mp1 fixture'
        if ($LASTEXITCODE -ne 0) { throw 'synthetic check-root commit failed' }
        $head = (& $git -C $sourceA rev-parse HEAD).Trim()

        $clean = Invoke-CanonicalSelectionCase -SourceRoot $sourceA -CheckRoot $sourceA
        ($clean.Output -join "`n") | Should -Match ('Pruefroot-Commit=' + $head + '; Backupverzeichnis=')

        Add-Content -LiteralPath (Join-Path $sourceA 'Docker\WebAPI\lib\migrate.php') -Value 'changed'
        $dirty = Invoke-CanonicalSelectionCase -SourceRoot $sourceA -CheckRoot $sourceA
        ($dirty.Output -join "`n") | Should -Match ('Pruefroot-Commit=' + $head + ' \(Arbeitsbaum geaendert\)')
    }

    It 'MP1: never mounts or writes the backup directory' {
        $restore = Get-Content -LiteralPath $script:RestoreScript -Raw
        $restore | Should -Match 'if \[ "\$\{VIRTUSPHERE_RESTORE_BACKUP_DIR\+x\}" = x \]'
        $restore | Should -Not -Match '-v\s+"?\$BACKUP_DIR'
        $restore | Should -Not -Match '>>?\s*"?\$BACKUP_DIR'
        $restore | Should -Not -Match '\b(rm|mv|cp|touch|mkdir|chmod|chown|tee|ln)\b[^\r\n]*\$BACKUP_DIR'
        $restore | Should -Not -Match '\b(rm|mv|touch|chmod|chown)\b[^\r\n]*"\$(dump|config|manifest)"'
        $restore | Should -Match 'docker rm -f -v "\$MYSQL_ID"'
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
                [string]$BackupContainer = 'fixture-backup-mysql-never-real',
                [string]$BackupCert = '',
                [string]$PrivateKey = '',
                [string]$ProcessPin = '',
                [string]$BackupDir = ''
            )
            $fakeBin = Join-Path $TestDrive ('safety-bin-' + [guid]::NewGuid().ToString('N'))
            $fakeLog = Join-Path $TestDrive ('safety-' + [guid]::NewGuid().ToString('N') + '.log')
            $fakeState = Join-Path $TestDrive ('safety-state-' + [guid]::NewGuid().ToString('N'))
            New-FakeDockerPath -Root $fakeBin -DockerFixture 'fake-docker-safety.sh'
            # Git Bash on Windows ships no flock. backup.sh takes its single-run
            # lock with it on the Linux hosts it runs on, so the fixture models
            # such a host; without it the orphan sweep would correctly stay off.
            [IO.File]::WriteAllText((Join-Path $fakeBin 'flock'), "#!/bin/sh`nexit 0`n", [Text.UTF8Encoding]::new($false))
            if ($env:OS -ne 'Windows_NT') {
                & chmod '+x' (Join-Path $fakeBin 'flock')
                if ($LASTEXITCODE -ne 0) { throw 'flock shim could not be made executable' }
            }
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
                VS_FAKE_BACKUP_CERT = $env:VS_FAKE_BACKUP_CERT
                VIRTUSPHERE_BACKUP_PRIVATE_KEY = $env:VIRTUSPHERE_BACKUP_PRIVATE_KEY
                VIRTUSPHERE_BACKUP_RECIPIENT_SHA256 = $env:VIRTUSPHERE_BACKUP_RECIPIENT_SHA256
                VIRTUSPHERE_RESTORE_BACKUP_DIR = $env:VIRTUSPHERE_RESTORE_BACKUP_DIR
                MSYS_NO_PATHCONV = $env:MSYS_NO_PATHCONV
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
                if ($BackupCert -ne '') { $env:VS_FAKE_BACKUP_CERT = $BackupCert.Replace('\', '/') }
                else { Remove-Item Env:VS_FAKE_BACKUP_CERT -ErrorAction SilentlyContinue }
                if ($PrivateKey -ne '') { $env:VIRTUSPHERE_BACKUP_PRIVATE_KEY = $PrivateKey.Replace('\', '/') }
                else { Remove-Item Env:VIRTUSPHERE_BACKUP_PRIVATE_KEY -ErrorAction SilentlyContinue }
                # K19-A3: the pin is read from .env only. A value set here is a
                # probe for the process-environment case, never a source.
                if ($ProcessPin -ne '') { $env:VIRTUSPHERE_BACKUP_RECIPIENT_SHA256 = $ProcessPin }
                else { Remove-Item Env:VIRTUSPHERE_BACKUP_RECIPIENT_SHA256 -ErrorAction SilentlyContinue }
                if ($BackupDir -ne '') { $env:VIRTUSPHERE_RESTORE_BACKUP_DIR = $BackupDir.Replace('\', '/') }
                else { Remove-Item Env:VIRTUSPHERE_RESTORE_BACKUP_DIR -ErrorAction SilentlyContinue }

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
            param([Parameter(Mandatory)][string]$Mode, [switch]$FullDump, [string]$Cert = '', [string]$PrivateKey = '', [string]$Stamp = '', [string[]]$ArchiveEnv = @())
            $stamp = if ($Stamp -ne '') { $Stamp } else { $Mode }
            $root = Join-Path $TestDrive ('restore-' + $stamp)
            New-CanonicalSourceRoot -Root $root -Stamp $stamp -ExtraArchiveEnv $ArchiveEnv
            if ($FullDump) { Set-CompleteSyntheticDump -Root $root -Stamp $stamp }
            if ($Cert -ne '') { Protect-SyntheticBackup -Root $root -Stamp $stamp -Cert $Cert }
            $scriptPath = Join-Path (Join-Path $root 'scripts') 'restore_test.sh'
            Invoke-WithSafetyDocker -ScriptPath $scriptPath -Mode $Mode -CheckRoot $root -PrivateKey $PrivateKey
        }

        # -Name separates roots of runs that share a fake-docker mode; -EnvText
        # is the exact .env content; -Arrange seeds the backup directory.
        function Invoke-BackupSafety {
            param(
                [Parameter(Mandatory)][string]$Mode,
                [string]$BackupCert = '',
                [string]$Name = '',
                [string]$EnvText = "APP_KEY=k19-fixture-app-key`n",
                [switch]$NoEnvFile,
                [string]$ProcessPin = '',
                [scriptblock]$Arrange = $null
            )
            $label = if ($Name -ne '') { $Name } else { $Mode }
            $root = Join-Path $TestDrive ('backup-' + $label)
            $scripts = Join-Path $root 'scripts'
            New-Item -ItemType Directory -Path (Join-Path $scripts 'lib') -Force | Out-Null
            Copy-Item -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') 'backup.sh') -Destination $scripts
            Copy-Item -LiteralPath (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'backup_crypto.sh') -Destination (Join-Path $scripts 'lib')
            Copy-Item -LiteralPath (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'env_file.sh') -Destination (Join-Path $scripts 'lib')
            Set-Content -LiteralPath (Join-Path $root 'docker-compose.yml') -Value 'services: {}' -Encoding Ascii
            New-Item -ItemType Directory -Path (Join-Path $root 'Docker\backups') -Force | Out-Null
            if (-not $NoEnvFile) {
                [IO.File]::WriteAllText((Join-Path $root '.env'), $EnvText, [Text.UTF8Encoding]::new($false))
            }
            if ($null -ne $Arrange) { & $Arrange $root }
            Invoke-WithSafetyDocker -ScriptPath (Join-Path $scripts 'backup.sh') -Mode $Mode -CheckRoot $root -BackupCert $BackupCert -ProcessPin $ProcessPin
        }

        # K19: a throwaway RSA-3072 pair per test run; the private key exists
        # only in TestDrive and is never committed.
        function New-BackupTestKeyPair {
            $dir = (Join-Path $TestDrive ('keys-' + [guid]::NewGuid().ToString('N'))).Replace('\', '/')
            New-Item -ItemType Directory -Path $dir -Force | Out-Null
            $r = Invoke-PosixSnippet -Snippet 'cd "$1" && openssl req -x509 -newkey rsa:3072 -nodes -keyout key.pem -out cert.pem -days 30 -subj "/CN=k19-test" >/dev/null 2>&1 && openssl x509 -in cert.pem -noout -fingerprint -sha256' -Arguments @($dir)
            if ($r.ExitCode -ne 0) { throw ('test key pair could not be generated: ' + ($r.Output -join ' | ')) }
            $fingerprint = (($r.Output -join '') -replace '^[^=]*=', '' -replace ':', '').ToLowerInvariant()
            [pscustomobject]@{ Cert = "$dir/cert.pem"; Key = "$dir/key.pem"; Fingerprint = $fingerprint }
        }

        # Runs one shell snippet with the Git/POSIX tools next to sh (openssl,
        # gunzip, tar) and returns its output lines. The snippet goes through a
        # file: Windows PowerShell 5.1 strips embedded quotes from native
        # arguments, the same reason the safety harness writes capture.sh.
        function Invoke-PosixSnippet {
            param([Parameter(Mandatory)][string]$Snippet, [string[]]$Arguments = @())
            $file = Join-Path $TestDrive ('snippet-' + [guid]::NewGuid().ToString('N') + '.sh')
            # /usr/bin is Git's usr/bin under MSYS and the system tools on Linux.
            $body = "export MSYS_NO_PATHCONV=1`nPATH=/usr/bin:`$PATH`n" + $Snippet + "`n"
            [IO.File]::WriteAllText($file, $body, [Text.UTF8Encoding]::new($false))
            $out = @(& $script:ShExe $file.Replace('\', '/') @Arguments 2>&1 | ForEach-Object { "$_" })
            [pscustomobject]@{ ExitCode = $LASTEXITCODE; Output = $out }
        }

        # Replaces the plaintext triplet of a synthetic root by an encrypted one
        # the way backup.sh writes it: two .cms files plus their manifest.
        function Protect-SyntheticBackup {
            param([Parameter(Mandatory)][string]$Root, [Parameter(Mandatory)][string]$Stamp, [Parameter(Mandatory)][string]$Cert)
            $dir = (Join-Path $Root 'Docker\backups').Replace('\', '/')
            $snippet = 'cd "$1" && for f in "db-$2.sql.gz" "config-$2.tar.gz"; do openssl cms -encrypt -binary -aes256 -stream -outform DER -in "$f" -out "$f.cms" "$3" && rm -f "$f" || exit 1; done && sha256sum "db-$2.sql.gz.cms" "config-$2.tar.gz.cms" > "manifest-$2.sha256"'
            $r = Invoke-PosixSnippet -Snippet $snippet -Arguments @($dir, $Stamp, $Cert)
            if ($r.ExitCode -ne 0) { throw ('synthetic backup could not be encrypted: ' + ($r.Output -join ' | ')) }
        }

        # K19-A3 helpers. The .env text always starts with the APP_KEY line that
        # the backup needs; PinLines carry the pin spellings under test.
        function New-K19EnvText {
            param([string[]]$PinLines = @(), [string]$Eol = "`n")
            $lines = @('APP_KEY=k19-fixture-app-key') + @($PinLines)
            return (($lines -join $Eol) + $Eol)
        }

        # The spellings an operator can paste: the card form (lower case, no
        # separators), upper case, colon-separated, and the openssl output with
        # its prefix. Quoting and CRLF are added by the caller.
        function Format-K19Pin {
            param([Parameter(Mandatory)][string]$Fingerprint, [Parameter(Mandatory)][string]$Form)
            $pairs = New-Object System.Collections.Generic.List[string]
            for ($i = 0; $i -lt 64; $i += 2) { $pairs.Add($Fingerprint.Substring($i, 2).ToUpperInvariant()) }
            $colons = $pairs -join ':'
            switch ($Form) {
                'card' { return $Fingerprint }
                'upper-card' { return $Fingerprint.ToUpperInvariant() }
                'colon' { return $colons }
                'openssl' { return 'SHA256 Fingerprint=' + $colons }
                'openssl-lower' { return 'sha256 Fingerprint=' + $colons }
                'quoted' { return '"' + $Fingerprint + '"' }
                default { throw "unknown pin spelling: $Form" }
            }
        }

        function Get-K19BackupState {
            param([Parameter(Mandatory)][string]$Root)
            $dir = Join-Path $Root 'Docker\backups'
            $names = @(Get-ChildItem -LiteralPath $dir -Force -ErrorAction SilentlyContinue | ForEach-Object { $_.Name })
            [pscustomobject]@{
                Plain = @($names | Where-Object { $_ -match '^(db|config)-.*\.(sql\.gz|tar\.gz)$' })
                Encrypted = @($names | Where-Object { $_ -match '\.cms$' })
                Temp = @($names | Where-Object { $_ -match '^\.(db|config|cert)-' })
            }
        }

        function Get-K19LastStatus {
            param([Parameter(Mandatory)][string]$Root)
            Get-Content -LiteralPath (Join-Path $Root 'Docker\backups\status\backup-status.jsonl') | Select-Object -Last 1 | ConvertFrom-Json
        }

        # A refused run leaves no readable archive, no .cms file and no temp
        # file, and its status line says failed.
        function Assert-K19NothingWritten {
            param([Parameter(Mandatory)][string]$Root)
            $state = Get-K19BackupState -Root $Root
            @($state.Plain).Count | Should -Be 0 -Because 'a refused run never leaves a readable archive'
            @($state.Encrypted).Count | Should -Be 0 -Because 'a refused run writes no .cms file'
            @($state.Temp).Count | Should -Be 0 -Because 'a refused run removes its temp files'
            (Get-K19LastStatus -Root $Root).status | Should -Be 'failed'
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
        $result.DockerCalls | Should -Contain ('rm -f -v ' + ('b' * 64))
        $result.DockerCalls | Should -Contain ('network rm ' + ('a' * 64))
        ($result.Output -join "`n") | Should -Match '\[10/10\] pass cleanup'
        ($result.Output -join "`n") | Should -Match 'Restore-Drill Images: PHP=fixture-php-never-real \(sha256:d{64}\); MySQL=fixture-mysql-never-real \(sha256:d{64}\)'
    }

    It 'MP1: a drill with a separate backup directory passes, names its sources and leaves the backups untouched' {
        $root = Join-Path $TestDrive 'restore-mp1-clone'
        New-CanonicalSourceRoot -Root $root -Stamp 'mp1-nightly'
        Set-CompleteSyntheticDump -Root $root -Stamp 'mp1-nightly'
        $backupDir = Join-Path $TestDrive 'mp1-running-backups'
        Move-Item -LiteralPath (Join-Path $root 'Docker\backups') -Destination $backupDir
        $snapshot = {
            Get-ChildItem -LiteralPath $backupDir -Recurse -Force | Sort-Object FullName | ForEach-Object {
                $hash = if ($_.PSIsContainer) { 'dir' } else { (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash }
                '{0}|{1}|{2}' -f $_.FullName.Substring($backupDir.Length), $_.LastWriteTimeUtc.Ticks, $hash
            }
        }
        $before = @(& $snapshot)

        $result = Invoke-WithSafetyDocker -ScriptPath (Join-Path $root 'scripts\restore_test.sh') -Mode success -CheckRoot $root -BackupDir $backupDir
        $text = $result.Output -join "`n"
        $result.ExitCode | Should -Be 0
        $text | Should -Match 'Backup-Stand=mp1-nightly'
        $text | Should -Match 'Backupverzeichnis=.*mp1-running-backups \(aus VIRTUSPHERE_RESTORE_BACKUP_DIR\)'
        $text | Should -Match 'Restore-Drill Images: PHP=fixture-php-never-real \(sha256:d{64}\)'
        $text | Should -Match 'Restore-Drill OK: .*Stand mp1-nightly, Pruefroot-Commit '
        $text | Should -Match '\[10/10\] pass cleanup'
        $result.DockerCalls | Should -Contain ('rm -f -v ' + ('b' * 64))
        @($result.DockerCalls | Where-Object { $_ -match 'mp1-running-backups' }).Count | Should -Be 0 -Because 'the backup directory is never mounted into a container'
        $before.Count | Should -BeGreaterThan 2
        (@(& $snapshot) -join "`n") | Should -Be ($before -join "`n") -Because 'the drill only reads the backup directory'
        Test-Path -LiteralPath (Join-Path $root 'Docker\backups') | Should -BeFalse -Because 'the drill creates no backup directory in the check root'
    }

    It 'R1: imports the dump only after the final MySQL server answers over TCP' {
        # The image's temporary init server answers on the socket but has no
        # network. Waiting on the socket let the import fall into the gap
        # before the final server (ERROR 2002 under load).
        $result = Invoke-RestoreSafety -Mode init-gap -FullDump
        $result.ExitCode | Should -Be 0
        ($result.Output -join "`n") | Should -Not -Match 'ERROR 2002'
        $calls = @($result.DockerCalls)
        $probes = @(0..($calls.Count - 1) | Where-Object { $calls[$_] -match '^exec b{64} mysql --protocol=TCP -h127\.0\.0\.1 -P3306 -uroot -p\S+ -N -e SELECT 1$' })
        $probes.Count | Should -Be 3 -Because 'two refused TCP probes, then the final server'
        $imports = @(0..($calls.Count - 1) | Where-Object { $calls[$_] -match '^exec -i b{64} mysql -uroot -p\S+$' })
        $imports.Count | Should -BeGreaterThan 0
        $imports[0] | Should -BeGreaterThan $probes[-1] -Because 'the dump import starts only after the final server answered'
        @(0..($imports[0] - 1) | Where-Object { $calls[$_] -match '^exec b{64} mysql -uroot -p\S+ -N -e SELECT 1$' }).Count | Should -Be 0 -Because 'before the import a socket probe reaches the temporary server too'
    }

    It 'R1: the production restore starts the dump import only after the healthy final MySQL server' {
        # Production restores into an emptied data directory, so the entrypoint
        # runs its temporary init server (with struktur.sql) first. Only the
        # TCP healthcheck tells the final server apart; --wait waits for it.
        $runbook = Get-Content -LiteralPath (Join-Path $script:RepoRoot 'docs\operations\backup.md') -Raw -Encoding UTF8
        $section = [regex]::Match($runbook, '(?ms)^## Echter Restore.*?(?=^## )').Value
        $section | Should -Not -BeNullOrEmpty
        $section | Should -Match 'docker compose up -d --wait mysql'
        $section | Should -Not -Match 'docker compose up -d mysql`'
        $section.IndexOf('docker compose up -d --wait mysql') | Should -BeLessThan $section.IndexOf('gunzip -c')
        $compose = Get-Content -LiteralPath (Join-Path $script:RepoRoot 'docker-compose.yml') -Raw
        $mysqlService = [regex]::Match($compose, '(?ms)^  mysql:\r?\n.*?(?=^  \S)').Value
        $mysqlService | Should -Match 'healthcheck:\s*\r?\n\s*test: \["CMD-SHELL", "mysqladmin ping -h 127\.0\.0\.1 ' -Because 'the temporary init server has no network, so only a TCP healthcheck waits for the final server'
    }

    It 'keeps ownership after create when database start fails and cleans by exact ID' {
        $result = Invoke-RestoreSafety -Mode start-failure
        $result.ExitCode | Should -Be 2
        $result.DockerCalls | Should -Contain ('rm -f -v ' + ('b' * 64))
        $result.DockerCalls | Should -Contain ('network rm ' + ('a' * 64))
    }

    It 'refuses cleanup when the recorded ID no longer has the run label' {
        $result = Invoke-RestoreSafety -Mode identity-mismatch -FullDump
        $result.ExitCode | Should -Be 1
        ($result.Output -join "`n") | Should -Match 'Cleanup verweigert fremden'
        @($result.DockerCalls | Where-Object { $_ -match '^rm -f (-v )?[bc]{64}$' }).Count | Should -Be 0
    }

    It 'turns an exact-ID docker rm failure into a failed drill' {
        $result = Invoke-RestoreSafety -Mode cleanup-rm-failure -FullDump
        $result.ExitCode | Should -Be 1
        ($result.Output -join "`n") | Should -Match '\[10/10\] fail cleanup'
    }

    It 'cleans recorded IDs when TERM arrives after create and before start completes' {
        $result = Invoke-RestoreSafety -Mode signal
        $result.ExitCode | Should -Be 130
        $result.DockerCalls | Should -Contain ('rm -f -v ' + ('b' * 64))
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

    It 'K19: encrypts dump and config archive to the stored certificate and leaves no readable copy' {
        $keys = New-BackupTestKeyPair
        $result = Invoke-BackupSafety -Mode encrypted-backup -BackupCert $keys.Cert
        $result.ExitCode | Should -Be 0
        $dir = Join-Path $TestDrive 'backup-encrypted-backup\Docker\backups'
        $db = @(Get-ChildItem -LiteralPath $dir -File -Filter 'db-*.sql.gz.cms')
        $config = @(Get-ChildItem -LiteralPath $dir -File -Filter 'config-*.tar.gz.cms')
        $db.Count | Should -Be 1
        $config.Count | Should -Be 1
        @(Get-ChildItem -LiteralPath $dir -File -Force | Where-Object { $_.Name -match '\.(sql|gz)$' -or $_.Name -match '^\.(db|config|cert)-' }).Count | Should -Be 0
        @(Get-ChildItem -LiteralPath $dir -Directory -Force | Where-Object { $_.Name -like '.cert-*' }).Count | Should -Be 0
        foreach ($file in @($db[0], $config[0])) {
            $bytes = [IO.File]::ReadAllBytes($file.FullName)
            $bytes[0] | Should -Be 0x30 -Because 'a CMS envelope is DER, not gzip'
            $text = [Text.Encoding]::GetEncoding(28591).GetString($bytes)
            $text | Should -Not -Match 'APP_KEY|k19_secret_marker|CREATE TABLE'
        }
        $status = Get-Content -LiteralPath (Join-Path $dir 'status\backup-status.jsonl') | Select-Object -Last 1 | ConvertFrom-Json
        $status.status | Should -Be 'ok'
        $status.encrypted | Should -BeTrue
        $status.recipient_sha256 | Should -Be $keys.Fingerprint
        $manifest = Get-Content -LiteralPath (Join-Path $dir ((Get-ChildItem -LiteralPath $dir -Filter 'manifest-*.sha256').Name))
        ($manifest -join "`n") | Should -Match 'db-.*\.sql\.gz\.cms'

        $restored = Invoke-PosixSnippet -Snippet 'openssl cms -decrypt -binary -inform DER -in "$1" -inkey "$3" | gunzip -c | grep -c k19_secret_marker; openssl cms -decrypt -binary -inform DER -in "$2" -inkey "$3" | tar -xzOf - .env' `
            -Arguments @($db[0].FullName.Replace('\', '/'), $config[0].FullName.Replace('\', '/'), $keys.Key)
        ($restored.Output -join "`n") | Should -Match '(?m)^1$'
        ($restored.Output -join "`n") | Should -Match 'APP_KEY=k19-fixture-app-key'
    }

    It 'K19: without a stored certificate keeps the plaintext triplet and says so in the status line' {
        $result = Invoke-BackupSafety -Mode plain-backup
        $result.ExitCode | Should -Be 0
        $dir = Join-Path $TestDrive 'backup-plain-backup\Docker\backups'
        @(Get-ChildItem -LiteralPath $dir -File -Filter 'db-*.sql.gz').Count | Should -Be 1
        @(Get-ChildItem -LiteralPath $dir -File -Filter '*.cms').Count | Should -Be 0
        $status = Get-Content -LiteralPath (Join-Path $dir 'status\backup-status.jsonl') | Select-Object -Last 1 | ConvertFrom-Json
        $status.status | Should -Be 'ok'
        $status.encrypted | Should -BeFalse
        $status.recipient_sha256 | Should -Be ''
    }

    It 'K19: restores an encrypted triplet with the private test key' {
        $keys = New-BackupTestKeyPair
        $result = Invoke-RestoreSafety -Mode success -FullDump -Cert $keys.Cert -PrivateKey $keys.Key -Stamp 'encrypted-success'
        $result.ExitCode | Should -Be 0
        ($result.Output -join "`n") | Should -Match 'verschluesseltes Backup encrypted-success entschluesselt'
        ($result.Output -join "`n") | Should -Match '\[10/10\] pass cleanup'
    }

    It 'K19: an encrypted triplet without a private key is a missing prerequisite, not a pass' {
        $keys = New-BackupTestKeyPair
        $result = Invoke-RestoreSafety -Mode success -FullDump -Cert $keys.Cert -Stamp 'encrypted-nokey'
        $result.ExitCode | Should -Be 2
        ($result.Output -join "`n") | Should -Match '\[restore\.key-missing\]'
        @($result.DockerCalls | Where-Object { $_ -match '^(rm|run|create|start)\s|^network\s+(create|rm)' }).Count | Should -Be 0
    }

    # K19-A2: the drill restores the newest db-/config- pair it finds. A decrypted
    # copy left next to an encrypted triplet of the same stamp can be newer than
    # the .cms files, and the manifest (which names the .cms files) must refuse it.
    It 'K19-A2: a plaintext copy next to an encrypted triplet of the same stamp fails the drill instead of restoring an unlisted file' {
        $keys = New-BackupTestKeyPair
        $stamp = 'k19-a2-plain-copy'
        $root = Join-Path $TestDrive ('restore-' + $stamp)
        New-CanonicalSourceRoot -Root $root -Stamp $stamp
        Set-CompleteSyntheticDump -Root $root -Stamp $stamp
        $backupDir = Join-Path $root 'Docker\backups'
        $names = @(('db-{0}.sql.gz' -f $stamp), ('config-{0}.tar.gz' -f $stamp))
        $holding = Join-Path $TestDrive ('holding-' + $stamp)
        New-Item -ItemType Directory -Path $holding -Force | Out-Null
        foreach ($name in $names) {
            Copy-Item -LiteralPath (Join-Path $backupDir $name) -Destination (Join-Path $holding $name)
        }
        Protect-SyntheticBackup -Root $root -Stamp $stamp -Cert $keys.Cert
        foreach ($name in $names) {
            $copy = Join-Path $backupDir $name
            Copy-Item -LiteralPath (Join-Path $holding $name) -Destination $copy
            $encrypted = Get-Item -LiteralPath (Join-Path $backupDir ($name + '.cms'))
            (Get-Item -LiteralPath $copy).LastWriteTime = $encrypted.LastWriteTime.AddMinutes(5)
        }
        $scriptPath = Join-Path (Join-Path $root 'scripts') 'restore_test.sh'
        $result = Invoke-WithSafetyDocker -ScriptPath $scriptPath -Mode success -CheckRoot $root -PrivateKey $keys.Key
        $result.ExitCode | Should -Be 1
        ($result.Output -join "`n") | Should -Match '\[restore\.manifest-mismatch\]'
        @($result.DockerCalls | Where-Object { $_ -match '^(rm|run|create|start)\s|^network\s+(create|rm)' }).Count | Should -Be 0
    }

    # K19-A2, second shape: a plain triplet whose manifest lists one more file
    # that the drill never chose. The listed hashes all verify, so only the
    # exact-name rule can refuse it.
    It 'K19-A2: a manifest that lists a third foreign file fails the drill even when the chosen triplet verifies' {
        $stamp = 'k19-a2-foreign-line'
        $root = Join-Path $TestDrive ('restore-' + $stamp)
        New-CanonicalSourceRoot -Root $root -Stamp $stamp
        Set-CompleteSyntheticDump -Root $root -Stamp $stamp
        $backupDir = Join-Path $root 'Docker\backups'
        $foreign = Join-Path $backupDir ('extra-{0}.txt' -f $stamp)
        Set-Content -LiteralPath $foreign -Value 'foreign file listed in the manifest' -Encoding Ascii
        $manifest = Join-Path $backupDir ('manifest-{0}.sha256' -f $stamp)
        $foreignLine = '{0}  {1}' -f (Get-FileHash -LiteralPath $foreign -Algorithm SHA256).Hash.ToLowerInvariant(), (Split-Path $foreign -Leaf)
        $manifestLines = @(Get-Content -LiteralPath $manifest) + $foreignLine
        [IO.File]::WriteAllText($manifest, (($manifestLines -join "`n") + "`n"), [Text.Encoding]::ASCII)
        $scriptPath = Join-Path (Join-Path $root 'scripts') 'restore_test.sh'
        $result = Invoke-WithSafetyDocker -ScriptPath $scriptPath -Mode success -CheckRoot $root
        $result.ExitCode | Should -Be 1
        ($result.Output -join "`n") | Should -Match '\[restore\.manifest-mismatch\]'
        @($result.DockerCalls | Where-Object { $_ -match '^(rm|run|create|start)\s|^network\s+(create|rm)' }).Count | Should -Be 0
    }

    It 'cleans the protected raw dump and records failure when TERM interrupts mysqldump' {
        $result = Invoke-BackupSafety -Mode backup-signal
        $result.ExitCode | Should -Be 130
        $root = Join-Path $TestDrive 'backup-backup-signal'
        @(Get-ChildItem -LiteralPath (Join-Path $root 'Docker\backups') -File -Filter '.db-*.sql').Count | Should -Be 0
        $status = Get-Content -LiteralPath (Join-Path $root 'Docker\backups\status\backup-status.jsonl') | Select-Object -Last 1 | ConvertFrom-Json
        $status.status | Should -Be 'failed'
    }

    It 'K19-A1: removes orphaned plaintext temp files of an interrupted run and reports how many' {
        $keys = New-BackupTestKeyPair
        $arrange = {
            param($root)
            $dir = Join-Path $root 'Docker\backups'
            Set-Content -LiteralPath (Join-Path $dir '.db-20260101-000000-AbCdEf.gz') -Value 'orphaned plaintext dump' -Encoding Ascii
            Set-Content -LiteralPath (Join-Path $dir '.config-20260101-000000-AbCdEf.gz') -Value 'orphaned plaintext config' -Encoding Ascii
            $certDir = Join-Path $dir '.cert-20260101-000000-AbCdEf'
            New-Item -ItemType Directory -Path $certDir -Force | Out-Null
            Set-Content -LiteralPath (Join-Path $certDir 'backup-cert.pem') -Value 'orphaned certificate directory' -Encoding Ascii
            Set-Content -LiteralPath (Join-Path $dir 'db-20251231-235959.sql.gz.cms') -Value 'older encrypted run' -Encoding Ascii
        }
        $result = Invoke-BackupSafety -Mode encrypted-backup -Name k19-a1-orphans -BackupCert $keys.Cert -Arrange $arrange
        $result.ExitCode | Should -Be 0
        ($result.Output -join "`n") | Should -Match '3 verwaiste Klartext-Tempdateien entfernt'
        $root = Join-Path $TestDrive 'backup-k19-a1-orphans'
        $state = Get-K19BackupState -Root $root
        @($state.Temp).Count | Should -Be 0
        @($state.Encrypted | Where-Object { $_ -eq 'db-20251231-235959.sql.gz.cms' }).Count | Should -Be 1 -Because 'the sweep matches temp names only'
        @($state.Encrypted | Where-Object { $_ -ne 'db-20251231-235959.sql.gz.cms' }).Count | Should -Be 2 -Because 'this run wrote its own encrypted pair'
    }

    It 'K19-A3 case 1: a pin that matches the stored certificate encrypts as before' {
        $keys = New-BackupTestKeyPair
        $envText = New-K19EnvText -PinLines @("VIRTUSPHERE_BACKUP_RECIPIENT_SHA256=$($keys.Fingerprint)")
        $result = Invoke-BackupSafety -Mode encrypted-backup -Name k19-a3-match -BackupCert $keys.Cert -EnvText $envText
        $result.ExitCode | Should -Be 0
        $root = Join-Path $TestDrive 'backup-k19-a3-match'
        @((Get-K19BackupState -Root $root).Encrypted).Count | Should -Be 2
        @((Get-K19BackupState -Root $root).Plain).Count | Should -Be 0
        $status = Get-K19LastStatus -Root $root
        $status.status | Should -Be 'ok'
        $status.recipient_sha256 | Should -Be $keys.Fingerprint
    }

    It 'K19-A3 case 2: a pin that differs from the stored certificate fails and names both fingerprints' {
        $stored = New-BackupTestKeyPair
        $pinned = New-BackupTestKeyPair
        $envText = New-K19EnvText -PinLines @("VIRTUSPHERE_BACKUP_RECIPIENT_SHA256=$($pinned.Fingerprint)")
        $result = Invoke-BackupSafety -Mode encrypted-backup -Name k19-a3-mismatch -BackupCert $stored.Cert -EnvText $envText
        $result.ExitCode | Should -Be 1
        # Colons are dropped before matching, so an openssl-style print of either fingerprint also counts.
        $text = (($result.Output -join "`n").ToLowerInvariant()) -replace ':', ''
        $text | Should -Match $stored.Fingerprint
        $text | Should -Match $pinned.Fingerprint
        Assert-K19NothingWritten -Root (Join-Path $TestDrive 'backup-k19-a3-mismatch')
    }

    It 'K19-A3 case 3: a pin without a stored certificate fails and writes no plaintext archive' {
        $envText = New-K19EnvText -PinLines @("VIRTUSPHERE_BACKUP_RECIPIENT_SHA256=$('ab' * 32)")
        $result = Invoke-BackupSafety -Mode plain-backup -Name k19-a3-pin-no-cert -EnvText $envText
        $result.ExitCode | Should -Be 1
        Assert-K19NothingWritten -Root (Join-Path $TestDrive 'backup-k19-a3-pin-no-cert')
    }

    It 'K19-A3 case 4: a malformed pin (<Name>) fails the run and is never read as unset' -ForEach @(
        @{ Name = 'k19-a3-pin-short'; Pin = 'abc123' }
        @{ Name = 'k19-a3-pin-non-hex'; Pin = ('g' * 64) }
    ) {
        $keys = New-BackupTestKeyPair
        $envText = New-K19EnvText -PinLines @("VIRTUSPHERE_BACKUP_RECIPIENT_SHA256=$Pin")
        $result = Invoke-BackupSafety -Mode encrypted-backup -Name $Name -BackupCert $keys.Cert -EnvText $envText
        $result.ExitCode | Should -Be 1
        Assert-K19NothingWritten -Root (Join-Path $TestDrive ('backup-' + $Name))
    }

    It 'K19-A3 case 5: an empty pin value keeps the encrypted run of today' {
        $keys = New-BackupTestKeyPair
        $envText = New-K19EnvText -PinLines @('VIRTUSPHERE_BACKUP_RECIPIENT_SHA256=')
        $result = Invoke-BackupSafety -Mode encrypted-backup -Name k19-a3-empty-pin -BackupCert $keys.Cert -EnvText $envText
        $result.ExitCode | Should -Be 0
        $root = Join-Path $TestDrive 'backup-k19-a3-empty-pin'
        @((Get-K19BackupState -Root $root).Encrypted).Count | Should -Be 2
        (Get-K19LastStatus -Root $root).recipient_sha256 | Should -Be $keys.Fingerprint
    }

    It 'K19-A3 case 5: a missing .env keeps the encrypted run of today' {
        $keys = New-BackupTestKeyPair
        $result = Invoke-BackupSafety -Mode encrypted-backup -Name k19-a3-no-env -BackupCert $keys.Cert -NoEnvFile
        $result.ExitCode | Should -Be 0
        $root = Join-Path $TestDrive 'backup-k19-a3-no-env'
        @((Get-K19BackupState -Root $root).Encrypted).Count | Should -Be 2
        (Get-K19LastStatus -Root $root).recipient_sha256 | Should -Be $keys.Fingerprint
    }

    It 'K19-A3 case 6: a pin that names the stored certificate is accepted in the spelling <Form>' -ForEach @(
        @{ Form = 'upper-card' }
        @{ Form = 'colon' }
        @{ Form = 'openssl' }
        @{ Form = 'openssl-lower' }
        @{ Form = 'quoted' }
        @{ Form = 'crlf-card' }
    ) {
        $keys = New-BackupTestKeyPair
        $eol = "`n"
        $spelling = $Form
        if ($Form -eq 'crlf-card') {
            $eol = "`r`n"
            $spelling = 'card'
        }
        $pin = Format-K19Pin -Fingerprint $keys.Fingerprint -Form $spelling
        $name = 'k19-a3-spelling-' + $Form
        $envText = New-K19EnvText -PinLines @("VIRTUSPHERE_BACKUP_RECIPIENT_SHA256=$pin") -Eol $eol
        $result = Invoke-BackupSafety -Mode encrypted-backup -Name $name -BackupCert $keys.Cert -EnvText $envText
        $result.ExitCode | Should -Be 0 -Because ('the pin spelled as ' + $Form + ' names the stored certificate')
        $root = Join-Path $TestDrive ('backup-' + $name)
        @((Get-K19BackupState -Root $root).Encrypted).Count | Should -Be 2
        (Get-K19LastStatus -Root $root).recipient_sha256 | Should -Be $keys.Fingerprint
    }

    It 'K19-A3 case 6b: a pin in the openssl spelling that names another certificate still fails' {
        $stored = New-BackupTestKeyPair
        $other = New-BackupTestKeyPair
        $pin = Format-K19Pin -Fingerprint $other.Fingerprint -Form 'openssl'
        $envText = New-K19EnvText -PinLines @("VIRTUSPHERE_BACKUP_RECIPIENT_SHA256=$pin")
        $result = Invoke-BackupSafety -Mode encrypted-backup -Name k19-a3-spelling-mismatch -BackupCert $stored.Cert -EnvText $envText
        $result.ExitCode | Should -Be 1
        Assert-K19NothingWritten -Root (Join-Path $TestDrive 'backup-k19-a3-spelling-mismatch')
    }

    It 'K19-A3 case 7: of duplicate pin lines the first one decides, a later correct line does not rescue it' {
        $stored = New-BackupTestKeyPair
        $wrong = New-BackupTestKeyPair
        $envText = New-K19EnvText -PinLines @(
            "VIRTUSPHERE_BACKUP_RECIPIENT_SHA256=$($wrong.Fingerprint)",
            "VIRTUSPHERE_BACKUP_RECIPIENT_SHA256=$($stored.Fingerprint)"
        )
        $result = Invoke-BackupSafety -Mode encrypted-backup -Name k19-a3-dup-wrong-first -BackupCert $stored.Cert -EnvText $envText
        $result.ExitCode | Should -Be 1
        Assert-K19NothingWritten -Root (Join-Path $TestDrive 'backup-k19-a3-dup-wrong-first')
    }

    It 'K19-A3 case 7: of duplicate pin lines a correct first one decides over a later wrong one' {
        $stored = New-BackupTestKeyPair
        $wrong = New-BackupTestKeyPair
        $envText = New-K19EnvText -PinLines @(
            "VIRTUSPHERE_BACKUP_RECIPIENT_SHA256=$($stored.Fingerprint)",
            "VIRTUSPHERE_BACKUP_RECIPIENT_SHA256=$($wrong.Fingerprint)"
        )
        $result = Invoke-BackupSafety -Mode encrypted-backup -Name k19-a3-dup-right-first -BackupCert $stored.Cert -EnvText $envText
        $result.ExitCode | Should -Be 0
        @((Get-K19BackupState -Root (Join-Path $TestDrive 'backup-k19-a3-dup-right-first')).Encrypted).Count | Should -Be 2
    }

    It 'K19-A3 case 8: a certificate replaced before its pin, or a pin moved before its certificate, fails loudly' {
        $old = New-BackupTestKeyPair
        $new = New-BackupTestKeyPair
        $certFirst = Invoke-BackupSafety -Mode encrypted-backup -Name k19-a3-rotate-cert-first -BackupCert $new.Cert -EnvText (New-K19EnvText -PinLines @("VIRTUSPHERE_BACKUP_RECIPIENT_SHA256=$($old.Fingerprint)"))
        $certFirst.ExitCode | Should -Be 1 -Because 'the certificate was replaced while the pin still names the old key'
        Assert-K19NothingWritten -Root (Join-Path $TestDrive 'backup-k19-a3-rotate-cert-first')
        $pinFirst = Invoke-BackupSafety -Mode encrypted-backup -Name k19-a3-rotate-pin-first -BackupCert $old.Cert -EnvText (New-K19EnvText -PinLines @("VIRTUSPHERE_BACKUP_RECIPIENT_SHA256=$($new.Fingerprint)"))
        $pinFirst.ExitCode | Should -Be 1 -Because 'the pin moved to the new key while the certificate is still the old one'
        Assert-K19NothingWritten -Root (Join-Path $TestDrive 'backup-k19-a3-rotate-pin-first')
    }

    It 'K19-A3 case 9: a restore drill does not read the pin, so a pin in the archived .env does not fail it' {
        $keys = New-BackupTestKeyPair
        $other = New-BackupTestKeyPair
        $archivePin = "VIRTUSPHERE_BACKUP_RECIPIENT_SHA256=$($other.Fingerprint)"
        $result = Invoke-RestoreSafety -Mode success -FullDump -Cert $keys.Cert -PrivateKey $keys.Key -Stamp 'k19-a3-restore-pin' -ArchiveEnv @($archivePin)
        $result.ExitCode | Should -Be 0
        ($result.Output -join "`n") | Should -Match '\[10/10\] pass cleanup'
    }

    It 'K19-A3: a pin set only in the process environment has no effect' {
        $keys = New-BackupTestKeyPair
        $other = New-BackupTestKeyPair
        $result = Invoke-BackupSafety -Mode encrypted-backup -Name k19-a3-env-only -BackupCert $keys.Cert -ProcessPin $other.Fingerprint
        $result.ExitCode | Should -Be 0
        $root = Join-Path $TestDrive 'backup-k19-a3-env-only'
        @((Get-K19BackupState -Root $root).Encrypted).Count | Should -Be 2
        (Get-K19LastStatus -Root $root).recipient_sha256 | Should -Be $keys.Fingerprint
    }

    It 'K19-A3: the .env pin decides even when the process environment holds the right value' {
        $keys = New-BackupTestKeyPair
        $other = New-BackupTestKeyPair
        $envText = New-K19EnvText -PinLines @("VIRTUSPHERE_BACKUP_RECIPIENT_SHA256=$($other.Fingerprint)")
        $result = Invoke-BackupSafety -Mode encrypted-backup -Name k19-a3-env-loses -BackupCert $keys.Cert -EnvText $envText -ProcessPin $keys.Fingerprint
        $result.ExitCode | Should -Be 1
        Assert-K19NothingWritten -Root (Join-Path $TestDrive 'backup-k19-a3-env-loses')
    }

    It 'K19-A3: backup.sh names the recipient pin key VIRTUSPHERE_BACKUP_RECIPIENT_SHA256' {
        $backupSh = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') 'backup.sh') -Raw
        $match = [regex]::Match($backupSh, 'VIRTUSPHERE_BACKUP_[A-Z0-9_]*SHA256')
        $match.Success | Should -BeTrue -Because 'backup.sh names the recipient pin key'
        $match.Value | Should -Be 'VIRTUSPHERE_BACKUP_RECIPIENT_SHA256'
    }

    It 'K19-A3: .env.example (commented) and backup.md document the pin key that backup.sh names' {
        $backupSh = Get-Content -LiteralPath (Join-Path (Join-Path $script:RepoRoot 'scripts') 'backup.sh') -Raw
        $key = [regex]::Match($backupSh, 'VIRTUSPHERE_BACKUP_[A-Z0-9_]*SHA256').Value
        $key | Should -Not -BeNullOrEmpty -Because 'the key name is derived from backup.sh'
        $example = Get-Content -LiteralPath (Join-Path $script:RepoRoot '.env.example') -Raw
        $example | Should -Match ('(?m)^#[ \t]*' + $key + '=')
        $example | Should -Not -Match ('(?m)^[ \t]*' + $key + '[ \t]*=')
        $doc = Get-Content -LiteralPath (Join-Path (Join-Path (Join-Path $script:RepoRoot 'docs') 'operations') 'backup.md') -Raw
        $doc | Should -Match $key
    }
}
