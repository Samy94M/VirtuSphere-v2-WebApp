BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:JournalModule = Join-Path (Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'mecm') 'VirtuSphere-MembershipJournal.ps1'
    $script:DeviceSyncPath = Join-Path (Split-Path $script:JournalModule -Parent) 'mecm_new-device-sync.ps1'
    . $script:JournalModule
}

Describe 'Membership journal durability contract' {
    BeforeEach {
        $script:JournalPath = Join-Path $TestDrive 'membership-journal.json'
        $script:Entry = New-VsMembershipJournalEntry -VmId 17 -RolloutRevision 3 -ResourceId '9001' -CollectionId 'VS100001' -CollectionName 'Paket Ä' -Type package -Change added
    }

    It 'uses a stable operation identity for the same fenced remote mutation' {
        $same = New-VsMembershipJournalEntry -VmId 17 -RolloutRevision 3 -ResourceId '9001' -CollectionId 'VS100001' -CollectionName 'anderer Anzeigename' -Type package -Change added
        $different = New-VsMembershipJournalEntry -VmId 17 -RolloutRevision 4 -ResourceId '9001' -CollectionId 'VS100001' -CollectionName 'Paket Ä' -Type package -Change added
        $same.operation_id | Should -Be $script:Entry.operation_id
        $different.operation_id | Should -Not -Be $script:Entry.operation_id
    }

    It 'persists intent, confirmed state and acknowledgement removal without a BOM' {
        Set-VsMembershipJournalEntry -Path $script:JournalPath -Entry $script:Entry
        (Get-VsMembershipJournalEntriesForVm -Path $script:JournalPath -VmId 17)[0].state | Should -Be 'intent'

        Set-VsMembershipJournalState -Path $script:JournalPath -OperationId $script:Entry.operation_id -State remote_confirmed
        (Get-VsMembershipJournalEntriesForVm -Path $script:JournalPath -VmId 17)[0].state | Should -Be 'remote_confirmed'
        [IO.File]::ReadAllBytes($script:JournalPath)[0] | Should -Not -Be 0xEF

        Remove-VsMembershipJournalEntry -Path $script:JournalPath -OperationId $script:Entry.operation_id
        @(Get-VsMembershipJournalEntriesForVm -Path $script:JournalPath -VmId 17).Count | Should -Be 0
    }

    It 'quarantines malformed durable evidence and fails closed' {
        Set-Content -LiteralPath $script:JournalPath -Value '{bad json' -Encoding UTF8
        { Read-VsMembershipJournal -Path $script:JournalPath } | Should -Throw '*quarantiniert*'
        Test-Path -LiteralPath $script:JournalPath | Should -BeFalse
        @(Get-ChildItem -LiteralPath $TestDrive -Filter 'membership-journal.json.quarantine.*.json').Count | Should -Be 1
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
