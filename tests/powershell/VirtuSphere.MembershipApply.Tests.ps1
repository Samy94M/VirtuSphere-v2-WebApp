BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    $script:DeviceSyncPath = Join-Path (Join-Path (Join-Path $script:RepoRoot 'Powershell-MECM') 'mecm') 'mecm_new-device-sync.ps1'
    $vectorPath = Join-Path (Join-Path (Join-Path $script:RepoRoot 'Docker') 'WebAPI\tests\fixtures') 'mecm-plan-vectors.json'
    $vectors = (Get-Content -LiteralPath $vectorPath -Raw | ConvertFrom-Json).vectors
    $script:StaleOwnedVector = @($vectors | Where-Object { $_.name -eq 'stale-owned-rule' })[0]
    $tokens = $null
    $parseErrors = $null
    $ast = [System.Management.Automation.Language.Parser]::ParseFile($script:DeviceSyncPath, [ref]$tokens, [ref]$parseErrors)
    $script:DispositionFunction = $ast.Find(
        { param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Get-VsStaleOwnedDisposition' },
        $true)
    if ($script:DispositionFunction) {
        . ([scriptblock]::Create($script:DispositionFunction.Extent.Text))
    }
    $script:DispositionParseErrors = @($parseErrors)
    $script:StaleOwnedLoop = @($ast.FindAll({
        param($node)
        $node -is [System.Management.Automation.Language.ForEachStatementAst] -and $node.Extent.Text -match '\$plan\.stale_owned'
    }, $true) | Sort-Object { $_.Extent.Text.Length } | Select-Object -First 1)[0]
    $script:ReplayIf = @($ast.FindAll({
        param($node)
        $node -is [System.Management.Automation.Language.IfStatementAst] -and $node.Clauses[0].Item1.Extent.Text -match '^\$journalEntries\.Count\s+-gt\s+0$'
    }, $true) | Select-Object -First 1)[0]
    $script:DeviceSyncSource = Get-Content -LiteralPath $script:DeviceSyncPath -Raw

    function Invoke-StaleOwnedApplyBlock {
        param([array]$PlannedAdds, [array]$AppliedAdds, [array]$StaleOwned)
        & {
            param($planned, $applied, $stale, $loopText)
            $plan = @{ add = @($planned); stale_owned = @($stale) }
            $appliedMembershipAdds = New-Object System.Collections.Generic.List[object]
            foreach ($entry in @($applied)) { [void]$appliedMembershipAdds.Add($entry) }
            $membershipReport = New-Object System.Collections.Generic.List[object]
            $journalWrites = New-Object System.Collections.Generic.List[object]
            $device = @{ id = 17 }
            $rolloutRevision = 3
            $resourceId = '9001'
            $membershipJournalPath = 'unused-by-mock.json'
            $deviceName = 'VM-A'

            function Write-VsLog { param($Level, $Context, $Message) }
            function New-VsMembershipJournalEntry {
                param($VmId, $RolloutRevision, $ResourceId, $CollectionId, $CollectionName, $Type, $Change, $State)
                [pscustomobject]@{ operation_id = 'op-' + $CollectionId; collection_id = $CollectionId; collection_name = $CollectionName; type = $Type; change = $Change }
            }
            function Set-VsMembershipJournalEntry {
                param($Path, $Entry)
                [void]$journalWrites.Add($Entry)
            }

            . ([scriptblock]::Create($loopText))
            [pscustomobject]@{ reports = $membershipReport.ToArray(); journal_writes = $journalWrites.ToArray() }
        } $PlannedAdds $AppliedAdds $StaleOwned $script:StaleOwnedLoop.Extent.Text
    }

    function Invoke-MembershipReplayBlock {
        param([array]$Entries, [hashtable]$MembershipStates = @{})
        & {
            param($givenEntries, $states, $ifText)
            $journalEntries = @($givenEntries)
            $rolloutRevision = 3
            $resourceId = '9001'
            $membershipJournalPath = 'unused-by-mock.json'
            $deviceName = 'VM-A'
            $config = @{}
            $itemFailures = 0
            $causes = New-Object System.Collections.Generic.List[object]
            $apiBodies = New-Object System.Collections.Generic.List[object]
            $ackGroups = New-Object System.Collections.Generic.List[object]
            $stateChanges = New-Object System.Collections.Generic.List[object]

            function Get-VsDirectMembershipState {
                param($CollectionId, $ResourceId)
                $state = if ($states.ContainsKey([string]$CollectionId)) { [string]$states[[string]$CollectionId] } else { 'absent' }
                [pscustomobject]@{ State = $state }
            }
            function Invoke-VsApi {
                param($Config, $Path, $Method, $Body)
                [void]$apiBodies.Add($Body)
            }
            function Remove-VsMembershipJournalEntries {
                param($Path, $OperationIds)
                [void]$ackGroups.Add([pscustomobject]@{ operation_ids = @($OperationIds) })
            }
            function Set-VsMembershipJournalState {
                param($Path, $OperationId, $State, $Reason)
                [void]$stateChanges.Add([pscustomobject]@{ operation_id = $OperationId; state = $State; reason = $Reason })
            }
            function Write-VsLog { param($Level, $Context, $Message) }
            function Add-VsRunCause { param($Causes, $Cause, $Target) }
            function Get-VsErrorStatusCode { param($ErrorRecord) 500 }
            function Get-VsErrorDetail { param($ErrorRecord) 'mock failure' }

            $wrapped = 'do { ' + $ifText + ' } while ($false)'
            . ([scriptblock]::Create($wrapped))
            [pscustomobject]@{
                api_bodies = $apiBodies.ToArray()
                ack_groups = $ackGroups.ToArray()
                state_changes = $stateChanges.ToArray()
            }
        } $Entries $MembershipStates $script:ReplayIf.Extent.Text
    }
}

Describe 'Device sync membership apply contract (D-01)' {
    It 'loads the pure disposition owner without executing the device-sync loop' {
        $script:DispositionParseErrors.Count | Should -Be 0
        $script:DispositionFunction | Should -Not -BeNullOrEmpty
        $script:StaleOwnedLoop | Should -Not -BeNullOrEmpty
        $script:ReplayIf | Should -Not -BeNullOrEmpty
        $script:StaleOwnedVector | Should -Not -BeNullOrEmpty
        Get-Command Get-VsStaleOwnedDisposition -ErrorAction SilentlyContinue | Should -Not -BeNullOrEmpty
    }

    It 'keeps provenance when the desired own rule was restored under the exact same CollectionID despite a renamed target' {
        $result = Get-VsStaleOwnedDisposition -CollectionId 'VS100001' -CollectionName 'Old display name' `
            -PlannedAdds @(@{ name = 'New display name'; type = 'package' }) `
            -AppliedAdds @(@{ collection_id = 'VS100001'; collection_name = 'New display name' })

        $result | Should -Be 'restored_same_id'
    }

    It 'withdraws only the old exact CollectionID after a confirmed same-name replacement' {
        $result = Get-VsStaleOwnedDisposition -CollectionId 'VS100001' -CollectionName 'Package A' `
            -PlannedAdds @(@{ name = 'Package A'; type = 'package' }) `
            -AppliedAdds @(@{ collection_id = 'VS100099'; collection_name = 'Package A' })

        $result | Should -Be 'withdraw_replaced_id'
    }

    It 'defers provenance withdrawal when a still-desired restoration was not confirmed' {
        $result = Get-VsStaleOwnedDisposition -CollectionId 'VS100001' -CollectionName 'Package A' `
            -PlannedAdds @(@{ name = 'Package A'; type = 'package' }) -AppliedAdds @()

        $result | Should -Be 'defer_until_restored'
    }

    It 'withdraws stale ownership when the collection is no longer desired' {
        Get-VsStaleOwnedDisposition -CollectionId 'VS100001' -CollectionName 'Package A' -PlannedAdds @() -AppliedAdds @() |
            Should -Be 'withdraw'
    }

    It 'applies add plus stale-owned for the same exact ID without emitting a removal' {
        $owned = $script:StaleOwnedVector.owned[0]
        $result = Invoke-StaleOwnedApplyBlock `
            -PlannedAdds @($script:StaleOwnedVector.desired) `
            -AppliedAdds @(@{ collection_id = [string]$owned.collection_id; collection_name = [string]$owned.collection_name }) `
            -StaleOwned @(@{ collection_id = [string]$owned.collection_id; collection_name = [string]$owned.collection_name; type = 'os' })

        @($result.reports).Count | Should -Be 0
        @($result.journal_writes).Count | Should -Be 0
    }

    It 'applies a confirmed replacement as an exact old-ID provenance removal' {
        $result = Invoke-StaleOwnedApplyBlock `
            -PlannedAdds @(@{ name = 'Package A'; type = 'package' }) `
            -AppliedAdds @(@{ collection_id = 'VS100099'; collection_name = 'Package A' }) `
            -StaleOwned @(@{ collection_id = 'VS100001'; collection_name = 'Package A'; type = 'package' })

        @($result.reports).Count | Should -Be 1
        $result.reports[0].change | Should -Be 'removed'
        $result.reports[0].collection_id | Should -BeExactly 'VS100001'
        $result.journal_writes[0].collection_id | Should -BeExactly 'VS100001'
    }

    It 'does not emit or journal a stale removal when the desired add was not confirmed' {
        $result = Invoke-StaleOwnedApplyBlock `
            -PlannedAdds @(@{ name = 'Package A'; type = 'package' }) -AppliedAdds @() `
            -StaleOwned @(@{ collection_id = 'VS100001'; collection_name = 'Package A'; type = 'package' })

        @($result.reports).Count | Should -Be 0
        @($result.journal_writes).Count | Should -Be 0
    }

    It 'replays a historical same-ID add/stale pair as one add and acknowledges both entries atomically' {
        $entries = @(
            [pscustomobject]@{ operation_id = 'add-op'; vm_id = 17; rollout_revision = 3; resource_id = '9001'; collection_id = 'VS100001'; collection_name = 'Package A'; type = 'package'; change = 'added'; state = 'remote_confirmed' },
            [pscustomobject]@{ operation_id = 'stale-op'; vm_id = 17; rollout_revision = 3; resource_id = '9001'; collection_id = 'VS100001'; collection_name = 'Package A'; type = 'package'; change = 'removed'; state = 'remote_confirmed' }
        )
        $result = Invoke-MembershipReplayBlock -Entries $entries -MembershipStates @{ VS100001 = 'present' }

        @($result.api_bodies).Count | Should -Be 1
        $result.api_bodies[0].memberships[0].change | Should -Be 'added'
        @($result.ack_groups).Count | Should -Be 1
        (@($result.ack_groups[0].operation_ids) -join ',') | Should -BeExactly 'add-op,stale-op'
    }

    It 'keeps unrelated exact IDs separately replayable' {
        $entries = @(
            [pscustomobject]@{ operation_id = 'add-op'; vm_id = 17; rollout_revision = 3; resource_id = '9001'; collection_id = 'VS100001'; collection_name = 'Package A'; type = 'package'; change = 'added'; state = 'remote_confirmed' },
            [pscustomobject]@{ operation_id = 'remove-op'; vm_id = 17; rollout_revision = 3; resource_id = '9001'; collection_id = 'VS100002'; collection_name = 'Package B'; type = 'package'; change = 'removed'; state = 'remote_confirmed' }
        )
        $result = Invoke-MembershipReplayBlock -Entries $entries -MembershipStates @{ VS100002 = 'absent' }

        @($result.api_bodies).Count | Should -Be 2
        (@($result.api_bodies | ForEach-Object { $_.memberships[0].collection_id }) -join ',') | Should -BeExactly 'VS100001,VS100002'
        @($result.ack_groups).Count | Should -Be 2
    }

    It 'marks an orphan removal uncertain while the exact remote membership is present' {
        $entry = [pscustomobject]@{ operation_id = 'orphan-remove'; vm_id = 17; rollout_revision = 3; resource_id = '9001'; collection_id = 'VS100001'; collection_name = 'Package A'; type = 'package'; change = 'removed'; state = 'remote_confirmed' }
        $result = Invoke-MembershipReplayBlock -Entries @($entry) -MembershipStates @{ VS100001 = 'present' }

        @($result.api_bodies).Count | Should -Be 0
        @($result.ack_groups).Count | Should -Be 0
        $result.state_changes[0].state | Should -Be 'uncertain'
        $result.state_changes[0].reason | Should -Be 'replay_remove_still_present'
    }

    It 'marks an orphan removal uncertain when exact remote presence cannot be read' {
        $entry = [pscustomobject]@{ operation_id = 'orphan-remove'; vm_id = 17; rollout_revision = 3; resource_id = '9001'; collection_id = 'VS100001'; collection_name = 'Package A'; type = 'package'; change = 'removed'; state = 'remote_confirmed' }
        $result = Invoke-MembershipReplayBlock -Entries @($entry) -MembershipStates @{ VS100001 = 'unknown' }

        @($result.api_bodies).Count | Should -Be 0
        @($result.ack_groups).Count | Should -Be 0
        $result.state_changes[0].state | Should -Be 'uncertain'
        $result.state_changes[0].reason | Should -Be 'replay_remove_presence_unknown'
    }

    It 'uses the disposition before creating a stale removal journal entry and preserves the rollout fence in both reports' {
        $staleLoop = $script:DeviceSyncSource.IndexOf('foreach ($rule in @($plan.stale_owned))')
        $decision = $script:DeviceSyncSource.IndexOf('Get-VsStaleOwnedDisposition', $staleLoop)
        $staleRemoval = $script:DeviceSyncSource.IndexOf('-Change removed -State remote_confirmed', $staleLoop)
        $membershipRevision = $script:DeviceSyncSource.IndexOf("`$membershipBody['rollout_revision']", $staleLoop)
        $resourceRevision = $script:DeviceSyncSource.IndexOf("`$updateBody['rollout_revision']", $membershipRevision)

        $staleLoop | Should -BeGreaterOrEqual 0
        $decision | Should -BeGreaterThan $staleLoop
        $staleRemoval | Should -BeGreaterThan $decision
        $membershipRevision | Should -BeGreaterThan $staleRemoval
        $resourceRevision | Should -BeGreaterThan $membershipRevision
    }
}
