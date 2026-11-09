# Backup evidence for the System status page: scripts/backup-scrub.sh and
# scripts/restore-drill-record.sh. Both run against a synthetic checkout in
# TestDrive. The drill itself is a stand-in that prints the same source lines
# restore_test.sh prints; the real drill is covered by RestoreRoot and the
# restore-drill gate, not here.

BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:ScrubScript = Join-Path (Join-Path $script:RepoRoot 'scripts') 'backup-scrub.sh'
    $script:RecordScript = Join-Path (Join-Path $script:RepoRoot 'scripts') 'restore-drill-record.sh'
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
        Copy-Item -LiteralPath $script:ScrubScript, $script:RecordScript -Destination $scripts
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

Describe 'restore-drill-record.sh' {
    BeforeAll {
        if (-not $script:ShExe) { throw 'sh is required for the backup evidence tests' }

        # Stand-in drill: the two source lines restore_test.sh prints, then the
        # exit code the test asks for through the first argument.
        function New-FakeDrill {
            param([Parameter(Mandatory)][string]$Root, [switch]$WithoutSourceLines)
            $lines = if ($WithoutSourceLines) { '' } else {
                'echo "Restore-Drill Auswahl: Pruefroot=$ROOT; Backup-Stand=20261102-060000"' + "`n" +
                'echo "Restore-Drill Quelle: Pruefroot-Commit=0123abc (Arbeitsbaum geaendert); Backupverzeichnis=$ROOT/Docker/backups (aus Pruefroot)"' + "`n"
            }
            $body = "#!/bin/sh`nset -eu`nROOT=`$(CDPATH='' cd -- `"`$(dirname `"`$0`")/..`" && pwd -P)`n" + $lines + "echo `"drill args: `$*`"`nexit `"`$1`"`n"
            Write-LfFile -Path (Join-Path (Join-Path $Root 'scripts') 'restore_test.sh') -Content $body
        }
    }

    It 'records a passed drill beside the backup status line and keeps exit code 0' {
        $root = New-EvidenceRoot
        New-FakeDrill -Root $root
        $run = Invoke-Sh (Join-Path (Join-Path $root 'scripts') 'restore-drill-record.sh') @('0', 'extra')

        $run.ExitCode | Should -Be 0
        $run.Output | Should -Contain 'drill args: 0 extra'
        $statusFile = Join-Path (Join-Path (Join-Path (Join-Path $root 'Docker') 'backups') 'status') (Get-PhpConstant 'VIRTUSPHERE_RESTORE_DRILL_STATUS_FILE')
        $lines = @(Get-Content -LiteralPath $statusFile)
        $lines.Count | Should -Be 1
        $line = $lines[0] | ConvertFrom-Json
        $line.status | Should -Be 'ok'
        $line.backup_ts | Should -Be '20261102-060000'
        $line.root_commit | Should -Be '0123abc (Arbeitsbaum geaendert)'
        ($line.ts -gt 0) | Should -BeTrue

        (Invoke-Sh (Join-Path (Join-Path $root 'scripts') 'restore-drill-record.sh') @('0')).ExitCode | Should -Be 0
        @(Get-Content -LiteralPath $statusFile).Count | Should -Be 2
    }

    It 'passes a finding or a missing prerequisite through and records nothing' -TestCases @(
        @{ Code = 1 }, @{ Code = 2 }
    ) {
        param($Code)
        $root = New-EvidenceRoot
        New-FakeDrill -Root $root
        $run = Invoke-Sh (Join-Path (Join-Path $root 'scripts') 'restore-drill-record.sh') @([string]$Code)

        $run.ExitCode | Should -Be $Code
        Test-Path -LiteralPath (Join-Path $root 'Docker/backups/status/restore-drill.jsonl') | Should -BeFalse
    }

    It 'ends with 2 when a passed drill named no backup directory' {
        $root = New-EvidenceRoot
        New-FakeDrill -Root $root -WithoutSourceLines
        $run = Invoke-Sh (Join-Path (Join-Path $root 'scripts') 'restore-drill-record.sh') @('0')

        $run.ExitCode | Should -Be 2
        ($run.Output -join "`n") | Should -Match '\[drill-record\.not-written\]'
        Test-Path -LiteralPath (Join-Path $root 'Docker/backups/status/restore-drill.jsonl') | Should -BeFalse
    }
}

Describe 'evidence names' {
    It 'the scripts write the files the portal reader reads' {
        (Get-Content -LiteralPath $script:ScrubScript -Raw) | Should -Match ([regex]::Escape('STATUS_FILE="$STATUS_DIR/' + (Get-PhpConstant 'VIRTUSPHERE_BACKUP_SCRUB_STATUS_FILE') + '"'))
        (Get-Content -LiteralPath $script:RecordScript -Raw) | Should -Match ([regex]::Escape('STATUS_NAME="' + (Get-PhpConstant 'VIRTUSPHERE_RESTORE_DRILL_STATUS_FILE') + '"'))
    }
}
