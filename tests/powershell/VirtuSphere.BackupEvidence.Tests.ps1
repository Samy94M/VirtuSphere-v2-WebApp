# Backup evidence for the System status page: scripts/backup-scrub.sh runs
# against a synthetic checkout in TestDrive. The drill record that
# scripts/restore_test.sh writes itself (N1b) is covered by RestoreRoot and the
# restore-drill gate; here only its file name is pinned to the portal reader.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:ScrubScript = Join-Path (Join-Path $script:RepoRoot 'scripts') 'backup-scrub.sh'
    $script:DrillScript = Join-Path (Join-Path $script:RepoRoot 'scripts') 'restore_test.sh'
    $script:EvidenceReader = Join-Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'Docker') 'WebAPI') 'lib') 'backup_evidence.php'
    $script:CheckRuntime = Join-Path (Join-Path (Join-Path (Join-Path $script:RepoRoot 'scripts') 'lib') 'check') 'runtime.ps1'
    . $script:CheckRuntime
    $script:ShExe = Find-Sh

    function Write-LfFile {
        param([Parameter(Mandatory)][string]$Path, [Parameter(Mandatory)][AllowEmptyString()][string]$Content)
        New-Item -ItemType Directory -Path (Split-Path $Path -Parent) -Force | Out-Null
        [IO.File]::WriteAllText($Path, $Content.Replace("`r`n", "`n"), [Text.UTF8Encoding]::new($false))
    }

    function Get-Sha256Hex {
        param([Parameter(Mandatory)][string]$Path)
        return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
    }

    # A checkout with the two scripts and $Stamps complete backup sets.
    function New-EvidenceRoot {
        param([string[]]$Stamps = @('20261101-060000', '20261102-060000'))
        $root = Join-Path $TestDrive ('root-' + [guid]::NewGuid().ToString('N'))
        $scripts = Join-Path $root 'scripts'
        $backups = Join-Path (Join-Path $root 'Docker') 'backups'
        New-Item -ItemType Directory -Path $scripts, $backups -Force | Out-Null
        Copy-Item -LiteralPath $script:ScrubScript -Destination $scripts
        foreach ($stamp in $Stamps) {
            $db = "db-$stamp.sql.gz"
            $config = "config-$stamp.tar.gz.cms"
            Write-LfFile -Path (Join-Path $backups $db) -Content ("synthetic dump $stamp`n")
            Write-LfFile -Path (Join-Path $backups $config) -Content ("synthetic config $stamp`n")
            $manifest = '{0}  {1}{4}{2}  {3}{4}' -f (Get-Sha256Hex (Join-Path $backups $db)), $db, (Get-Sha256Hex (Join-Path $backups $config)), $config, "`n"
            Write-LfFile -Path (Join-Path $backups "manifest-$stamp.sha256") -Content $manifest
        }
        return $root
    }

    function Invoke-Sh {
        param([Parameter(Mandatory)][string]$Script, [string[]]$Arguments = @())
        $oldPath = $env:PATH
        # run-pester.ps1 runs with Stop; under Windows PowerShell 5.1 every
        # stderr line of a native command would then end the test early.
        $ErrorActionPreference = 'Continue'
        try {
            $env:PATH = (Split-Path $script:ShExe -Parent) + [IO.Path]::PathSeparator + $oldPath
            $output = @(& $script:ShExe ($Script -replace '\\', '/') @Arguments 2>&1 | ForEach-Object { [string]$_ })
            return [pscustomobject]@{ ExitCode = $LASTEXITCODE; Output = $output }
        } finally {
            $env:PATH = $oldPath
        }
    }

    function Get-BackupFingerprint {
        param([Parameter(Mandatory)][string]$Root)
        $backups = Join-Path (Join-Path $Root 'Docker') 'backups'
        return @(Get-ChildItem -LiteralPath $backups -File | Sort-Object Name | ForEach-Object { $_.Name + '=' + (Get-Sha256Hex $_.FullName) }) -join ';'
    }

    function Get-PhpConstant {
        param([Parameter(Mandatory)][string]$Name)
        $source = Get-Content -LiteralPath $script:EvidenceReader -Raw
        $match = [regex]::Match($source, "const $Name = '([^']+)';")
        if (-not $match.Success) { throw "constant $Name not found in backup_evidence.php" }
        return $match.Groups[1].Value
    }
}

Describe 'backup-scrub.sh' {
    BeforeAll {
        if (-not $script:ShExe) { throw 'sh is required for the backup evidence tests' }
    }

    It 'passes intact sets, reports progress and changes no backup' {
        $root = New-EvidenceRoot
        $before = Get-BackupFingerprint $root
        $run = Invoke-Sh (Join-Path (Join-Path $root 'scripts') 'backup-scrub.sh')

        $run.ExitCode | Should -Be 0
        $run.Output | Should -Contain '[1/2] RUN 20261101-060000'
        $run.Output | Should -Contain '[1/2] pass 20261101-060000'
        $run.Output | Should -Contain '[2/2] pass 20261102-060000'
        $statusFile = Join-Path (Join-Path (Join-Path (Join-Path $root 'Docker') 'backups') 'status') (Get-PhpConstant 'VIRTUSPHERE_BACKUP_SCRUB_STATUS_FILE')
        $status = Get-Content -LiteralPath $statusFile -Raw | ConvertFrom-Json
        $status.status | Should -Be 'ok'
        $status.sets_checked | Should -Be 2
        $status.sets_failed | Should -Be 0
        @($status.failed_sets).Count | Should -Be 0
        ($status.ts -gt 0) | Should -BeTrue
        $before | Should -Be (Get-BackupFingerprint $root)
        @(Get-ChildItem -LiteralPath (Split-Path $statusFile -Parent) -Force | Where-Object { $_.Name -like '.*' }).Count | Should -Be 0
    }

    It 'fails a set whose archive no longer matches its manifest' {
        $root = New-EvidenceRoot
        Write-LfFile -Path (Join-Path (Join-Path (Join-Path $root 'Docker') 'backups') 'db-20261102-060000.sql.gz') -Content "bit rot`n"
        $run = Invoke-Sh (Join-Path (Join-Path $root 'scripts') 'backup-scrub.sh')

        $run.ExitCode | Should -Be 1
        $run.Output | Should -Contain '[2/2] fail 20261102-060000'
        $status = Get-Content -LiteralPath (Join-Path $root 'Docker/backups/status/backup-scrub.json') -Raw | ConvertFrom-Json
        $status.status | Should -Be 'failed'
        $status.sets_failed | Should -Be 1
        @($status.failed_sets) | Should -Be @('20261102-060000')
    }

    It 'fails a manifest that does not name exactly the dump and config of its own stamp' {
        $root = New-EvidenceRoot -Stamps @('20261101-060000')
        $backups = Join-Path (Join-Path $root 'Docker') 'backups'
        $db = Join-Path $backups 'db-20261101-060000.sql.gz'
        Write-LfFile -Path (Join-Path $backups 'manifest-20261101-060000.sha256') -Content ((Get-Sha256Hex $db) + "  db-20261101-060000.sql.gz`n")
        $run = Invoke-Sh (Join-Path (Join-Path $root 'scripts') 'backup-scrub.sh')

        $run.ExitCode | Should -Be 1
        (Get-Content -LiteralPath (Join-Path $backups 'status/backup-scrub.json') -Raw | ConvertFrom-Json).status | Should -Be 'failed'
    }

    It 'fails a set whose listed archive is missing while its manifest remains' {
        $root = New-EvidenceRoot -Stamps @('20261101-060000')
        Remove-Item -LiteralPath (Join-Path $root 'Docker/backups/config-20261101-060000.tar.gz.cms')
        $run = Invoke-Sh (Join-Path (Join-Path $root 'scripts') 'backup-scrub.sh')

        $run.ExitCode | Should -Be 1
    }

    It 'ends as a missing prerequisite without a set and keeps the previous result' {
        $root = New-EvidenceRoot -Stamps @()
        $statusFile = Join-Path $root 'Docker/backups/status/backup-scrub.json'
        Write-LfFile -Path $statusFile -Content "previous`n"
        $run = Invoke-Sh (Join-Path (Join-Path $root 'scripts') 'backup-scrub.sh')

        $run.ExitCode | Should -Be 2
        ($run.Output -join "`n") | Should -Match '\[scrub\.no-backup\]'
        Get-Content -LiteralPath $statusFile -Raw | Should -Be "previous`n"
    }
}

Describe 'evidence names' {
    It 'the scripts write the files the portal reader reads' {
        (Get-Content -LiteralPath $script:ScrubScript -Raw) | Should -Match ([regex]::Escape('STATUS_FILE="$STATUS_DIR/' + (Get-PhpConstant 'VIRTUSPHERE_BACKUP_SCRUB_STATUS_FILE') + '"'))
        (Get-Content -LiteralPath $script:DrillScript -Raw) | Should -Match ([regex]::Escape('DRILL_STATUS_FILE="$DRILL_STATUS_DIR/' + (Get-PhpConstant 'VIRTUSPHERE_RESTORE_DRILL_STATUS_FILE') + '"'))
        (Get-PhpConstant 'VIRTUSPHERE_RESTORE_DRILL_RECORD_COMMAND') | Should -Be 'sudo sh scripts/restore_test.sh'
        Test-Path -LiteralPath $script:DrillScript | Should -BeTrue -Because 'the portal hint names the drill that writes the record'
    }
}
