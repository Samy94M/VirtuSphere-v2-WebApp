BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:JournalModule = Join-Path (Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'mecm') 'VirtuSphere-MembershipJournal.ps1'
    $script:DeviceSyncPath = Join-Path (Split-Path $script:JournalModule -Parent) 'mecm_new-device-sync.ps1'
    $script:ServerInstallerPath = Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'install-VirtuSphere-MECM.ps1'
    . $script:JournalModule
}

Describe 'Membership journal durability contract' {
    BeforeEach {
        # Pester 5.7 behaelt TestDrive innerhalb des Describe-Blocks. Jeder Fall
        # bekommt deshalb einen eigenen Journalnamen: absichtlich erhaltene
        # Quarantaene-Evidenz eines Tests darf den naechsten nicht blockieren.
        $script:JournalPath = Join-Path $TestDrive ('membership-journal-{0}.json' -f [guid]::NewGuid().ToString('N'))
        $script:Entry = New-VsMembershipJournalEntry -VmId 17 -RolloutRevision 3 -ResourceId '9001' -CollectionId 'VS100001' -CollectionName 'Paket Ä' -Type package -Change added
    }

    It 'uses a stable operation identity for the same fenced remote mutation' {
        $same = New-VsMembershipJournalEntry -VmId 17 -RolloutRevision 3 -ResourceId '9001' -CollectionId 'VS100001' -CollectionName 'anderer Anzeigename' -Type package -Change added
        $different = New-VsMembershipJournalEntry -VmId 17 -RolloutRevision 4 -ResourceId '9001' -CollectionId 'VS100001' -CollectionName 'Paket Ä' -Type package -Change added
        $same.operation_id | Should -Be $script:Entry.operation_id
        $different.operation_id | Should -Not -Be $script:Entry.operation_id
    }

    It 'persists intent, confirmed state and acknowledgement removal as BOM-less UTF-8' {
        Set-VsMembershipJournalEntry -Path $script:JournalPath -Entry $script:Entry
        (Get-VsMembershipJournalEntriesForVm -Path $script:JournalPath -VmId 17)[0].state | Should -Be 'intent'

        Set-VsMembershipJournalState -Path $script:JournalPath -OperationId $script:Entry.operation_id -State remote_confirmed
        (Get-VsMembershipJournalEntriesForVm -Path $script:JournalPath -VmId 17)[0].state | Should -Be 'remote_confirmed'
        [IO.File]::ReadAllBytes($script:JournalPath)[0] | Should -Not -Be 0xEF

        Remove-VsMembershipJournalEntry -Path $script:JournalPath -OperationId $script:Entry.operation_id
        @(Get-VsMembershipJournalEntriesForVm -Path $script:JournalPath -VmId 17).Count | Should -Be 0
    }

    It 'acknowledges a related operation group in one journal replacement' {
        $remove = New-VsMembershipJournalEntry -VmId 17 -RolloutRevision 3 -ResourceId '9001' -CollectionId 'VS100001' -CollectionName 'Paket A' -Type package -Change removed -State remote_confirmed
        Set-VsMembershipJournalEntry -Path $script:JournalPath -Entry $script:Entry
        Set-VsMembershipJournalEntry -Path $script:JournalPath -Entry $remove

        Remove-VsMembershipJournalEntries -Path $script:JournalPath -OperationIds @($script:Entry.operation_id, $remove.operation_id)

        @(Get-VsMembershipJournalEntriesForVm -Path $script:JournalPath -VmId 17).Count | Should -Be 0
    }

    It 'preserves Unicode exactly through repeated read-modify-write cycles on every supported PowerShell' {
        # Keep the fixture source ASCII so Windows PowerShell 5.1 cannot
        # mojibake both the expected value and the journal value identically.
        $unicodeName = 'Paket ' + [char]0x00C4 + ' ' + [char]0x00B7 + ' Stra' + [char]0x00DF + 'e ' + [char]0x00B7 + ' ' + [char]0x6771 + [char]0x4EAC + ' ' + [char]0x00B7 + ' ' + [char]::ConvertFromUtf32(0x1F680)
        $unicodeEntry = New-VsMembershipJournalEntry -VmId 23 -RolloutRevision 7 -ResourceId '9002' -CollectionId 'VS100009' -CollectionName $unicodeName -Type package -Change added
        Set-VsMembershipJournalEntry -Path $script:JournalPath -Entry $unicodeEntry

        foreach ($round in 1..3) {
            $stored = @(Get-VsMembershipJournalEntriesForVm -Path $script:JournalPath -VmId 23)
            $stored.Count | Should -Be 1
            $stored[0].collection_name | Should -BeExactly $unicodeName
            Set-VsMembershipJournalState -Path $script:JournalPath -OperationId $unicodeEntry.operation_id -State remote_confirmed -Reason ('round_' + $round)
        }

        $strictUtf8 = New-Object Text.UTF8Encoding($false, $true)
        $raw = [IO.File]::ReadAllText($script:JournalPath, $strictUtf8)
        ([Text.Encoding]::UTF8.GetBytes($raw) -join ',') | Should -BeExactly ([IO.File]::ReadAllBytes($script:JournalPath) -join ',')
        (($raw | ConvertFrom-Json).entries[0].collection_name.ToCharArray() | ForEach-Object { [int]$_ }) -join ',' |
            Should -BeExactly (($unicodeName.ToCharArray() | ForEach-Object { [int]$_ }) -join ',')
    }

    It 'keeps malformed durable evidence blocking in a fresh PowerShell process until manual clearance' {
        Set-Content -LiteralPath $script:JournalPath -Value '{bad json' -Encoding UTF8
        { Read-VsMembershipJournal -Path $script:JournalPath } | Should -Throw '*quarantiniert*'
        Test-Path -LiteralPath $script:JournalPath | Should -BeFalse
        $quarantinePattern = (Split-Path $script:JournalPath -Leaf) + '.quarantine.*.json'
        $quarantines = @(Get-ChildItem -LiteralPath $TestDrive -Filter $quarantinePattern)
        $quarantines.Count | Should -Be 1

        # A new device-sync process sees no main file. The preserved evidence,
        # rather than that absence, is the durable mutation boundary.
        $engine = (Get-Process -Id $PID).Path
        $moduleArg = $script:JournalModule.Replace("'", "''")
        $journalArg = $script:JournalPath.Replace("'", "''")
        $oldLastExitCode = Get-Variable -Name LASTEXITCODE -Scope Global -ErrorAction SilentlyContinue
        if ($oldLastExitCode) { $oldLastExitValue = $oldLastExitCode.Value }
        try {
            $childOutput = & $engine -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "try { . '$moduleArg'; Read-VsMembershipJournal -Path '$journalArg'; [Console]::Out.WriteLine('UNEXPECTED_READ') } catch { [Console]::Out.WriteLine('EXPECTED_BLOCK: ' + `$_.Exception.Message) }"
            $childExitCode = $LASTEXITCODE
        } finally {
            if ($oldLastExitCode) {
                Set-Variable -Name LASTEXITCODE -Scope Global -Value $oldLastExitValue
            } else {
                Remove-Variable -Name LASTEXITCODE -Scope Global -ErrorAction SilentlyContinue
            }
        }
        $childExitCode | Should -Be 0
        ($childOutput -join "`n") | Should -Not -Match 'UNEXPECTED_READ'
        ($childOutput -join "`n") | Should -Match 'EXPECTED_BLOCK'
        ($childOutput -join "`n") | Should -Match 'ungeklaerte Quarantaenedatei'
        Test-Path -LiteralPath $script:JournalPath | Should -BeFalse
        @(Get-ChildItem -LiteralPath $TestDrive -Filter $quarantinePattern).Count | Should -Be 1

        # An operator must inspect and explicitly clear the quarantined bytes.
        Remove-Item -LiteralPath $quarantines[0].FullName -Force
        $cleared = Read-VsMembershipJournal -Path $script:JournalPath
        @($cleared.entries).Count | Should -Be 0
    }

    It 'quarantines BOM-less and BOM-prefixed invalid UTF-8 bytes unchanged and keeps each next read blocked' {
        $invalidJson = [byte[]](0x7B, 0x22, 0x78, 0x22, 0x3A, 0x22, 0xC3, 0x28, 0x22, 0x7D)
        $fixtures = @(
            @{ name = 'plain'; bytes = $invalidJson },
            @{ name = 'bom'; bytes = [byte[]](0xEF, 0xBB, 0xBF) + $invalidJson }
        )

        foreach ($fixture in $fixtures) {
            $path = Join-Path $TestDrive ($fixture.name + '-membership-journal.json')
            [IO.File]::WriteAllBytes($path, [byte[]]$fixture.bytes)

            { Read-VsMembershipJournal -Path $path } | Should -Throw '*quarantiniert*'
            $quarantine = @(Get-ChildItem -LiteralPath $TestDrive -Filter ($fixture.name + '-membership-journal.json.quarantine.*.json'))
            $quarantine.Count | Should -Be 1
            $expectedBytes = [byte[]]$fixture.bytes
            ([IO.File]::ReadAllBytes($quarantine[0].FullName) -join ',') | Should -BeExactly ($expectedBytes -join ',')
            { Read-VsMembershipJournal -Path $path } | Should -Throw '*ungeklaerte Quarantaenedatei*'
        }
    }

    It 'does not let a replacement main file bypass unresolved quarantined evidence' {
        Set-Content -LiteralPath $script:JournalPath -Value '{bad json' -Encoding UTF8
        { Read-VsMembershipJournal -Path $script:JournalPath } | Should -Throw '*quarantiniert*'
        Write-VsMembershipJournal -Path $script:JournalPath -Entries @($script:Entry)

        { Read-VsMembershipJournal -Path $script:JournalPath } | Should -Throw '*ungeklaerte Quarantaenedatei*'
    }

    It 'accepts one legacy UTF-8 BOM without changing Unicode' {
        $unicodeName = 'Paket ' + [char]0x00C4 + ' ' + [char]0x6771 + [char]0x4EAC
        $entry = New-VsMembershipJournalEntry -VmId 29 -RolloutRevision 2 -ResourceId '9003' -CollectionId 'VS100019' -CollectionName $unicodeName -Type package -Change added
        Write-VsMembershipJournal -Path $script:JournalPath -Entries @($entry)
        $bomBytes = [byte[]](0xEF, 0xBB, 0xBF) + [IO.File]::ReadAllBytes($script:JournalPath)
        [IO.File]::WriteAllBytes($script:JournalPath, [byte[]]$bomBytes)

        (Read-VsMembershipJournal -Path $script:JournalPath).entries[0].collection_name | Should -BeExactly $unicodeName
    }

    It 'holds an exclusive process lock' {
        $first = Enter-VsMembershipJournalInstance -Path $script:JournalPath
        try {
            { Enter-VsMembershipJournalInstance -Path $script:JournalPath } | Should -Throw '*andere Device-Sync-Instanz*'
        } finally {
            $first.Dispose()
        }
    }

    It 'refuses capacity overflow before replacing the current journal' {
        Set-VsMembershipJournalEntry -Path $script:JournalPath -Entry $script:Entry
        $before = [IO.File]::ReadAllText($script:JournalPath)
        $tooMany = 1..($script:VsMembershipJournalMaxEntries + 1) | ForEach-Object { $script:Entry }
        { Write-VsMembershipJournal -Path $script:JournalPath -Entries $tooMany } | Should -Throw '*voll*'
        [IO.File]::ReadAllText($script:JournalPath) | Should -Be $before
    }

    It 'keeps the journal owner in the staged and hash-verified server package' {
        $installer = Get-Content -LiteralPath $script:ServerInstallerPath -Raw
        $installer | Should -Match "requiredServerFiles\s*=\s*@\([^\r\n]*'VirtuSphere-MembershipJournal\.ps1'"
        $installer | Should -Match ([regex]::Escape("Get-ChildItem -Path `$sourceDir -Filter '*.ps1' -File"))
        $installer | Should -Match 'Get-FileHash[^\r\n]+\$source\.FullName[^\r\n]+Get-FileHash[^\r\n]+\$staged'
    }
}

Describe 'Device sync journal ordering contract' {
    It 'persists intent before remote write and confirmation before portal report' {
        $source = Get-Content -LiteralPath $script:DeviceSyncPath -Raw
        $intent = $source.IndexOf('Set-VsMembershipJournalEntry -Path $membershipJournalPath -Entry $journalEntry')
        $remote = $source.IndexOf('Add-CMDeviceCollectionDirectMembershipRule -CollectionId $collectionId')
        $confirmed = $source.IndexOf('Set-VsMembershipJournalState -Path $membershipJournalPath -OperationId $journalEntry.operation_id -State remote_confirmed')
        $report = $source.IndexOf("Invoke-VsApi -Config `$config -Path '/mecm_updateid.php?action=reportMembership'", $remote)
        $intent | Should -BeLessThan $remote
        $remote | Should -BeLessThan $confirmed
        $confirmed | Should -BeLessThan $report
        $source | Should -Match 'state -ne ''remote_confirmed'''
        $source | Should -Match 'rollout_or_resource_changed'
    }
}
