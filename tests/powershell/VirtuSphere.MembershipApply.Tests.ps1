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
    # H01-2: the replay checks the portal answer with the real helper from Common
    # before it removes journal entries; only that function is loaded, not the library.
    $commonPath = Join-Path (Split-Path $script:DeviceSyncPath -Parent) 'VirtuSphere-Common.ps1'
    $commonAst = [System.Management.Automation.Language.Parser]::ParseFile($commonPath, [ref]$tokens, [ref]$parseErrors)
    $script:PortalSuccessFunction = $commonAst.Find(
        { param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Assert-VsPortalSuccess' },
        $true)
    if ($script:PortalSuccessFunction) {
        . ([scriptblock]::Create($script:PortalSuccessFunction.Extent.Text))
    }
    # TS1-K5: the pure clearance table from Common and its apply owner from the
    # device sync, both taken from the production files.
    $script:ClearanceFunction = $commonAst.Find(
        { param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Get-VsJournalClearance' },
        $true)
    if ($script:ClearanceFunction) {
        . ([scriptblock]::Create($script:ClearanceFunction.Extent.Text))
    }
    $script:ClearApplyFunction = $ast.Find(
        { param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Clear-VsUncertainJournalEntries' },
        $true)
    if ($script:ClearApplyFunction) {
        . ([scriptblock]::Create($script:ClearApplyFunction.Extent.Text))
    }
    $script:DeviceSyncAst = $ast
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
            function Write-VsDeviceLog { param($Message, $Level, $Context) }
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
        param([array]$Entries, [hashtable]$MembershipStates = @{}, $PortalAnswer = [pscustomobject]@{ success = 'Data updated successfully' },
            $StatusCode = 500, [string[]]$ClearanceCauses = @())
        & {
            param($givenEntries, $states, $ifText, $portalAnswer, $statusCode, $clearanceCauses)
            $journalEntries = @($givenEntries)
            $journalClearanceCauses = @($clearanceCauses)
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
                # The portal's committed answer (mecm_updateid.php), unless a case overrides it.
                $portalAnswer
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
            function Write-VsDeviceLog { param($Message, $Level, $Context) }
            function Add-VsRunCause { param($Causes, $Cause, $Target) [void]$Causes.Add([string]$Cause) }
            function Get-VsErrorStatusCode { param($ErrorRecord) $statusCode }
            function Get-VsErrorDetail { param($ErrorRecord) 'mock failure' }

            $wrapped = 'do { ' + $ifText + ' } while ($false)'
            . ([scriptblock]::Create($wrapped))
            [pscustomobject]@{
                api_bodies = $apiBodies.ToArray()
                ack_groups = $ackGroups.ToArray()
                state_changes = $stateChanges.ToArray()
                causes = $causes.ToArray()
            }
        } $Entries $MembershipStates $script:ReplayIf.Extent.Text $PortalAnswer $StatusCode $ClearanceCauses
    }

    function New-K5Entry {
        param([string]$Op = 'a1', [string]$State = 'uncertain', [string]$Change = 'added', [int]$Revision = 3,
            [string]$ResourceId = '9001', [string]$CollectionId = 'VS100001', [string]$Reason = 'remote_add_not_confirmed')
        [pscustomobject]@{
            operation_id = ($Op + ('0' * 64)).Substring(0, 64); vm_id = 17; rollout_revision = $Revision; resource_id = $ResourceId
            collection_id = $CollectionId; collection_name = ('Collection ' + $CollectionId); type = 'package'; change = $Change
            state = $State; reason = $Reason
        }
    }

    # Runs the production apply owner against recording mocks. Every remote or
    # portal write is a tripwire that records AND throws, so an accidental call
    # can neither pass silently nor be swallowed unseen.
    function Invoke-JournalClearance {
        param([array]$Entries, [hashtable]$MembershipStates = @{}, [string[]]$KnownCollections = @('VS100001', 'VS100002', 'VS100003'),
            [string[]]$KnownResources = @('9001'), [switch]$RemoveFails)
        & {
            param($k5Entries, $k5States, $k5KnownCollections, $k5KnownResources, $k5RemoveFails)
            $k5Reads = New-Object System.Collections.Generic.List[object]
            $k5Removals = New-Object System.Collections.Generic.List[object]
            $k5Logs = New-Object System.Collections.Generic.List[object]
            $k5Trips = New-Object System.Collections.Generic.List[string]
            function Get-VsDirectMembershipState {
                param($CollectionId, $ResourceId)
                [void]$k5Reads.Add(('{0}|{1}' -f $CollectionId, $ResourceId))
                $key = '{0}|{1}' -f $CollectionId, $ResourceId
                $state = if ($k5States.ContainsKey($key)) { [string]$k5States[$key] } else { 'absent' }
                [pscustomobject]@{ State = $state }
            }
            function Remove-VsMembershipJournalEntries {
                param($Path, $OperationIds)
                if ($k5RemoveFails) { throw 'Membership-Journal ist voll.' }
                [void]$k5Removals.Add(@($OperationIds))
            }
            function Write-VsDeviceLog { param($Message, $Level, $Context) [void]$k5Logs.Add([pscustomobject]@{ Level = $Level; Message = $Message }) }
            function Invoke-VsApi { [void]$k5Trips.Add('Invoke-VsApi'); throw 'tripwire Invoke-VsApi' }
            function Add-CMDeviceCollectionDirectMembershipRule { [void]$k5Trips.Add('Add-CMDeviceCollectionDirectMembershipRule'); throw 'tripwire add' }
            function Remove-CMDeviceCollectionDirectMembershipRule { [void]$k5Trips.Add('Remove-CMDeviceCollectionDirectMembershipRule'); throw 'tripwire remove' }
            function Set-VsMembershipJournalState { [void]$k5Trips.Add('Set-VsMembershipJournalState'); throw 'tripwire state' }
            function Set-VsMembershipJournalEntry { [void]$k5Trips.Add('Set-VsMembershipJournalEntry'); throw 'tripwire entry' }

            $knownCollectionSet = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::Ordinal)
            foreach ($id in @($k5KnownCollections)) { [void]$knownCollectionSet.Add([string]$id) }
            $knownResourceMap = @{}
            foreach ($id in @($k5KnownResources)) { $knownResourceMap[[string]$id] = @([pscustomobject]@{ ResourceID = $id }) }
            $k5Result = $null
            $k5Thrown = $null
            try {
                $k5Result = Clear-VsUncertainJournalEntries -JournalEntries @($k5Entries) -RolloutRevision 3 -ResourceId '9001' `
                    -KnownCollectionIds $knownCollectionSet -KnownResourceIds $knownResourceMap -JournalPath 'C:\VirtuSphere\membership-journal.json' -DeviceName 'VM-A'
            } catch {
                $k5Thrown = $_.Exception.Message
            }
            [pscustomobject]@{
                result = $k5Result; thrown = $k5Thrown; reads = $k5Reads.ToArray(); removals = $k5Removals.ToArray()
                logs = $k5Logs.ToArray(); trips = $k5Trips.ToArray()
            }
        } $Entries $MembershipStates $KnownCollections $KnownResources $RemoveFails.IsPresent
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

    It 'H01-2: keeps the journal entry when a 2xx answer carries no success' {
        # A wrong target or proxy answering 200 with an HTML page: before H01-2 the
        # replay removed the entry although the portal had stored nothing.
        $entry = [pscustomobject]@{ operation_id = 'add-op'; vm_id = 17; rollout_revision = 3; resource_id = '9001'; collection_id = 'VS100001'; collection_name = 'Package A'; type = 'package'; change = 'added'; state = 'remote_confirmed' }
        $result = Invoke-MembershipReplayBlock -Entries @($entry) -MembershipStates @{ VS100001 = 'present' } -PortalAnswer '<html><body>Proxy</body></html>'

        $script:PortalSuccessFunction | Should -Not -BeNullOrEmpty -Because 'the replay must use the real helper from Common'
        @($result.api_bodies).Count | Should -Be 1
        @($result.ack_groups).Count | Should -Be 0
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

    It 'reports the transfer generation it read, including zero, back with the ResourceID (AV-P0)' {
        $script:DeviceSyncSource | Should -Match '\$transferGeneration = \$device\.transfer_generation'
        $send = $script:DeviceSyncSource.IndexOf("`$updateBody['transfer_generation'] = [long]`$transferGeneration")
        $send | Should -BeGreaterThan $script:DeviceSyncSource.IndexOf("`$updateBody['rollout_revision']")
        # Generation 0 is a real value: a truthiness test would drop it and turn
        # the callback into a legacy one that clears any queued transfer.
        $script:DeviceSyncSource | Should -Not -Match 'if \(\$transferGeneration\)'
    }

    It 'S2c-2: a replay transport failure is a failed report and leaves the entry confirmed' {
        $entry = [pscustomobject]@{ operation_id = 'add-op'; vm_id = 17; rollout_revision = 3; resource_id = '9001'; collection_id = 'VS100001'; collection_name = 'Package A'; type = 'package'; change = 'added'; state = 'remote_confirmed' }
        $result = Invoke-MembershipReplayBlock -Entries @($entry) -PortalAnswer '<html>Proxy</html>' -StatusCode $null

        @($result.state_changes).Count | Should -Be 0
        @($result.ack_groups).Count | Should -Be 0
        (@($result.causes) -join ',') | Should -BeExactly 'membership_report_failed'
    }

    It 'S2c-2: a replay answered with <Code> marks the entry uncertain for the next clearance' -ForEach @(@{ Code = 404 }, @{ Code = 409 }) {
        $entry = [pscustomobject]@{ operation_id = 'add-op'; vm_id = 17; rollout_revision = 3; resource_id = '9001'; collection_id = 'VS100001'; collection_name = 'Package A'; type = 'package'; change = 'added'; state = 'remote_confirmed' }
        $result = Invoke-MembershipReplayBlock -Entries @($entry) -PortalAnswer '<html>Refused</html>' -StatusCode $Code

        $result.state_changes[0].state | Should -Be 'uncertain'
        $result.state_changes[0].reason | Should -Be ('portal_http_' + $Code)
        (@($result.causes) -join ',') | Should -BeExactly 'membership_operation_uncertain'
    }

    It 'names the clearance cause of an entry that stays blocked instead of a generic uncertain operation' {
        $entry = New-K5Entry -State 'uncertain'
        $result = Invoke-MembershipReplayBlock -Entries @($entry) -ClearanceCauses @('membership_query_failed')

        @($result.api_bodies).Count | Should -Be 0
        (@($result.causes) -join ',') | Should -BeExactly 'membership_query_failed'
    }

    It 'keeps membership_operation_uncertain for an entry the replay itself just left uncertain' {
        $entry = [pscustomobject]@{ operation_id = 'orphan-remove'; vm_id = 17; rollout_revision = 3; resource_id = '9001'; collection_id = 'VS100001'; collection_name = 'Package A'; type = 'package'; change = 'removed'; state = 'remote_confirmed' }
        $result = Invoke-MembershipReplayBlock -Entries @($entry) -MembershipStates @{ VS100001 = 'present' }

        (@($result.causes) -join ',') | Should -BeExactly 'membership_operation_uncertain'
    }
}

Describe 'Journal clearance from the read membership (TS1-K5, K12)' {
    BeforeDiscovery {
        $k5Origins = @(
            @{ Label = 'intent after a crash'; State = 'intent'; Revision = 3; Reason = '' },
            @{ Label = 'uncertain after a failed call'; State = 'uncertain'; Revision = 3; Reason = 'remote_add_not_confirmed' },
            @{ Label = 'confirmed of an older revision (409 rest)'; State = 'remote_confirmed'; Revision = 2; Reason = '' }
        )
        $k5Reads = @(
            @{ Change = 'added'; Read = 'absent'; Action = 'clear'; Outcome = 'add_not_applied'; Level = 'INFO'; Cause = '' },
            @{ Change = 'added'; Read = 'present'; Action = 'clear'; Outcome = 'add_present_unowned'; Level = 'WARN'; Cause = '' },
            @{ Change = 'removed'; Read = 'absent'; Action = 'clear'; Outcome = 'remove_applied_or_gone'; Level = 'INFO'; Cause = '' },
            @{ Change = 'removed'; Read = 'present'; Action = 'clear'; Outcome = 'remove_not_applied'; Level = 'INFO'; Cause = '' },
            @{ Change = 'added'; Read = 'unknown'; Action = 'keep_blocked'; Outcome = 'membership_unreadable'; Level = 'WARN'; Cause = 'membership_query_failed' },
            @{ Change = 'removed'; Read = 'unknown'; Action = 'keep_blocked'; Outcome = 'membership_unreadable'; Level = 'WARN'; Cause = 'membership_query_failed' }
        )
        $script:K5Matrix = foreach ($origin in $k5Origins) {
            foreach ($read in $k5Reads) {
                $case = @{}
                foreach ($key in $origin.Keys) { $case[$key] = $origin[$key] }
                foreach ($key in $read.Keys) { $case[$key] = $read[$key] }
                $case
            }
        }
    }

    Context 'pure decision table (Get-VsJournalClearance)' {
        It 'loads the pure decision and its apply owner from the production files' {
            $script:ClearanceFunction | Should -Not -BeNullOrEmpty
            $script:ClearApplyFunction | Should -Not -BeNullOrEmpty
            Get-Command Get-VsJournalClearance -ErrorAction SilentlyContinue | Should -Not -BeNullOrEmpty
            Get-Command Clear-VsUncertainJournalEntries -ErrorAction SilentlyContinue | Should -Not -BeNullOrEmpty
        }

        It '<Label>, <Change>, read <Read>: <Action> as <Outcome>' -ForEach $script:K5Matrix {
            $entry = New-K5Entry -State $State -Change $Change -Revision $Revision -Reason $Reason
            $first = Get-VsJournalClearance -Entry $entry -CurrentRevision 3 -CurrentResourceId '9001' -CollectionKnown $true -PreviousResourceKnown $true
            $first.Action | Should -Be 'read' -Because 'a candidate is never decided before its membership was read'
            $first.ReadResourceId | Should -BeExactly '9001'

            $decision = Get-VsJournalClearance -Entry $entry -CurrentRevision 3 -CurrentResourceId '9001' -CollectionKnown $true -PreviousResourceKnown $true -ReadState $Read
            $decision.Action | Should -Be $Action
            $decision.Action | Should -BeIn @('clear', 'keep_blocked')
            $decision.Outcome | Should -BeExactly $Outcome
            $decision.Outcome | Should -Not -BeNullOrEmpty
            $decision.Level | Should -Be $Level
            ([string]$decision.Cause) | Should -BeExactly $Cause
        }

        It 'row 1: a changed ResourceID whose old device left MECM is cleared without reading (<Change>)' -ForEach @(@{ Change = 'added' }, @{ Change = 'removed' }) {
            $entry = New-K5Entry -Change $Change -State 'remote_confirmed' -ResourceId '8001'
            $decision = Get-VsJournalClearance -Entry $entry -CurrentRevision 3 -CurrentResourceId '9001' -CollectionKnown $true -PreviousResourceKnown $false
            $decision.Action | Should -Be 'clear'
            $decision.Outcome | Should -BeExactly 'resource_changed'
        }

        It 'R6 residual: a changed ResourceID whose old device still exists reads the OLD ResourceID' {
            $entry = New-K5Entry -ResourceId '8001'
            $first = Get-VsJournalClearance -Entry $entry -CurrentRevision 3 -CurrentResourceId '9001' -CollectionKnown $true -PreviousResourceKnown $true
            $first.Action | Should -Be 'read'
            $first.ReadResourceId | Should -BeExactly '8001'
        }

        It 'R6 residual: read <Read> on the old device gives <Action> (<Outcome>)' -ForEach @(
            @{ Read = 'present'; Action = 'keep_blocked'; Outcome = 'previous_resource_rule_present'; Cause = 'previous_resource_present' },
            @{ Read = 'absent'; Action = 'clear'; Outcome = 'resource_changed'; Cause = '' },
            @{ Read = 'unknown'; Action = 'keep_blocked'; Outcome = 'membership_unreadable'; Cause = 'membership_query_failed' }
        ) {
            $entry = New-K5Entry -ResourceId '8001'
            $decision = Get-VsJournalClearance -Entry $entry -CurrentRevision 3 -CurrentResourceId '9001' -CollectionKnown $true -PreviousResourceKnown $true -ReadState $Read
            $decision.Action | Should -Be $Action
            $decision.Outcome | Should -BeExactly $Outcome
            ([string]$decision.Cause) | Should -BeExactly $Cause
        }

        It 'row 2: an entry of a deleted collection is cleared without a provider read (<Change>)' -ForEach @(@{ Change = 'added' }, @{ Change = 'removed' }) {
            $entry = New-K5Entry -Change $Change -State 'intent'
            $decision = Get-VsJournalClearance -Entry $entry -CurrentRevision 3 -CurrentResourceId '9001' -CollectionKnown $false -PreviousResourceKnown $true
            $decision.Action | Should -Be 'clear'
            $decision.Outcome | Should -BeExactly 'collection_gone'
        }

        It 'a confirmed entry of the current rollout is no candidate and goes to the replay' {
            $entry = New-K5Entry -State 'remote_confirmed' -Reason ''
            $decision = Get-VsJournalClearance -Entry $entry -CurrentRevision 3 -CurrentResourceId '9001' -CollectionKnown $true -PreviousResourceKnown $true -ReadState 'present'
            $decision.Action | Should -Be 'replay'
        }

        It 'every uncertain reason the device sync writes is a clearance candidate (derived from the source)' {
            # Abgeleitet, nicht aufgezaehlt: jede Setzstelle von -State uncertain
            # im Device-Sync. Null Treffer waere ein leerer Scan, kein Gruen.
            $setters = @([regex]::Matches($script:DeviceSyncSource, "-State uncertain -Reason (?:'(?<literal>[a-z_]+)'|\(?'(?<prefix>[a-z_]+)'\s*\+|\`$(?<variable>\w+))"))
            $setters.Count | Should -BeGreaterOrEqual 4
            foreach ($setter in $setters) {
                $reason = if ($setter.Groups['literal'].Success) { $setter.Groups['literal'].Value } elseif ($setter.Groups['prefix'].Success) { $setter.Groups['prefix'].Value + '409' } else { 'replay_remove_still_present' }
                $entry = New-K5Entry -State 'uncertain' -Reason $reason
                (Get-VsJournalClearance -Entry $entry -CurrentRevision 3 -CurrentResourceId '9001' -CollectionKnown $true -PreviousResourceKnown $true).Action |
                    Should -Be 'read' -Because "the reason '$reason' must never keep a VM blocked without a read"
            }
            @($script:DeviceSyncAst.FindAll({ param($n) $n -is [System.Management.Automation.Language.CommandAst] -and $n.GetCommandName() -eq 'Clear-VsUncertainJournalEntries' }, $true)).Count |
                Should -Be 1
        }
    }

    Context 'apply owner (Clear-VsUncertainJournalEntries)' {
        It 'acknowledges all cleared entries of a VM in ONE journal replacement and passes current confirmed ones to the replay' {
            $entries = @(
                (New-K5Entry -Op 'a1' -State 'uncertain' -Change 'added' -CollectionId 'VS100001'),
                (New-K5Entry -Op 'b2' -State 'intent' -Change 'removed' -CollectionId 'VS100002' -Reason ''),
                (New-K5Entry -Op 'c3' -State 'remote_confirmed' -Change 'added' -Revision 2 -CollectionId 'VS100003' -Reason ''),
                (New-K5Entry -Op 'd4' -State 'remote_confirmed' -Change 'added' -CollectionId 'VS100003' -Reason '')
            )
            $run = Invoke-JournalClearance -Entries $entries -MembershipStates @{ 'VS100003|9001' = 'present' }

            $run.thrown | Should -BeNullOrEmpty
            @($run.removals).Count | Should -Be 1
            (@($run.removals[0]) | ForEach-Object { $_.Substring(0, 2) }) -join ',' | Should -BeExactly 'a1,b2,c3'
            $run.result.Cleared | Should -Be 3
            @($run.result.Remaining).Count | Should -Be 1
            $run.result.Remaining[0].operation_id.Substring(0, 2) | Should -BeExactly 'd4'
            @($run.result.BlockedCauses).Count | Should -Be 0
            @($run.reads).Count | Should -Be 3 -Because 'the current confirmed entry is not read, it is replayed'
        }

        It 'clears only the readable entries and keeps an unreadable one blocked' {
            $entries = @(
                (New-K5Entry -Op 'a1' -CollectionId 'VS100001'),
                (New-K5Entry -Op 'b2' -CollectionId 'VS100002')
            )
            $run = Invoke-JournalClearance -Entries $entries -MembershipStates @{ 'VS100002|9001' = 'unknown' }

            @($run.removals).Count | Should -Be 1
            (@($run.removals[0]) | ForEach-Object { $_.Substring(0, 2) }) -join ',' | Should -BeExactly 'a1'
            @($run.result.Remaining).Count | Should -Be 1
            $run.result.Remaining[0].operation_id.Substring(0, 2) | Should -BeExactly 'b2'
            (@($run.result.BlockedCauses) -join ',') | Should -BeExactly 'membership_query_failed'
        }

        It 'removes nothing and writes nothing when every candidate stays blocked' {
            $run = Invoke-JournalClearance -Entries @(New-K5Entry -Op 'a1') -MembershipStates @{ 'VS100001|9001' = 'unknown' }
            @($run.removals).Count | Should -Be 0
            @($run.result.Remaining).Count | Should -Be 1
        }

        It 'reports nothing and writes no membership, also for an add whose rule is present' {
            $entries = @(
                (New-K5Entry -Op 'a1' -Change 'added' -CollectionId 'VS100001'),
                (New-K5Entry -Op 'b2' -Change 'removed' -CollectionId 'VS100002'),
                (New-K5Entry -Op 'c3' -Change 'added' -CollectionId 'VS100009'),
                (New-K5Entry -Op 'd4' -Change 'added' -ResourceId '8001')
            )
            $run = Invoke-JournalClearance -Entries $entries -MembershipStates @{ 'VS100001|9001' = 'present'; 'VS100002|9001' = 'present' }

            $run.thrown | Should -BeNullOrEmpty
            @($run.trips).Count | Should -Be 0 -Because 'clearing never reports, adopts or writes a membership'
            $run.result.Cleared | Should -Be 4
            # Collection gone and old device gone are decided without a provider read.
            (@($run.reads) -join ',') | Should -BeExactly 'VS100001|9001,VS100002|9001'
        }

        It 'reads the old ResourceID and keeps the VM blocked while its old device still carries the rule' {
            $run = Invoke-JournalClearance -Entries @(New-K5Entry -Op 'a1' -ResourceId '8001') -KnownResources @('9001', '8001') -MembershipStates @{ 'VS100001|8001' = 'present' }

            (@($run.reads) -join ',') | Should -BeExactly 'VS100001|8001'
            @($run.removals).Count | Should -Be 0
            (@($run.result.BlockedCauses) -join ',') | Should -BeExactly 'previous_resource_present'
        }

        It 'writes one log line per entry: WARN for an unowned present add, INFO otherwise, WARN with the journal path when blocked' {
            $entries = @(
                (New-K5Entry -Op 'abcdef0123456789' -Change 'added' -CollectionId 'VS100001'),
                (New-K5Entry -Op 'b2' -Change 'removed' -CollectionId 'VS100002' -State 'intent' -Reason ''),
                (New-K5Entry -Op 'c3' -Change 'added' -CollectionId 'VS100003')
            )
            $run = Invoke-JournalClearance -Entries $entries -MembershipStates @{ 'VS100001|9001' = 'present'; 'VS100003|9001' = 'unknown' }

            @($run.logs).Count | Should -Be 3
            $run.logs[0].Level | Should -Be 'WARN'
            $run.logs[0].Message | Should -Match '^Journaleintrag aus gelesener Mitgliedschaft geklaert: operation=abcdef012345 aktion=added collection=Collection VS100001 \(VS100001\) zustand=uncertain grund=remote_add_not_confirmed gelesen=present ausgang=add_present_unowned revision_eintrag=3 revision_aktuell=3 '
            $run.logs[1].Level | Should -Be 'INFO'
            $run.logs[1].Message | Should -Match 'zustand=intent grund=- gelesen=absent ausgang=remove_applied_or_gone'
            $run.logs[2].Level | Should -Be 'WARN'
            $run.logs[2].Message | Should -Match '^Journaleintrag nicht geklaert: .*gelesen=unknown ausgang=membership_unreadable .*journal=C:\\VirtuSphere\\membership-journal\.json - VM bleibt blockiert; naechster Lauf liest erneut\.$'
            foreach ($line in $run.logs) {
                foreach ($field in @('operation=', 'aktion=', 'collection=', 'zustand=', 'grund=', 'gelesen=', 'ausgang=', 'revision_eintrag=', 'revision_aktuell=')) {
                    $line.Message | Should -Match ([regex]::Escape($field))
                }
                $line.Message | Should -Match '^[\x20-\x7E]+$' -Because 'device-sync log lines stay ASCII'
            }
        }

        It 'propagates a journal write failure and logs no clearance that did not happen' {
            $run = Invoke-JournalClearance -Entries @(New-K5Entry -Op 'a1') -RemoveFails
            $run.thrown | Should -Match 'Membership-Journal ist voll'
            @($run.logs | Where-Object { $_.Message -match 'geklaert:' -and $_.Message -notmatch 'nicht geklaert' }).Count | Should -Be 0
        }

        It 'binds an empty collection and device inventory and clears without a provider read' {
            $run = Invoke-JournalClearance -Entries @(New-K5Entry -Op 'a1') -KnownCollections @() -KnownResources @()
            $run.thrown | Should -BeNullOrEmpty
            @($run.reads).Count | Should -Be 0
            $run.result.Cleared | Should -Be 1
        }

        It 'returns an empty replay set for a VM without journal entries' {
            $run = Invoke-JournalClearance -Entries @()
            @($run.removals).Count | Should -Be 0
            @($run.result.Remaining).Count | Should -Be 0
            $run.result.Cleared | Should -Be 0
        }
    }

    Context 'device-sync wiring' {
        It 'clears in phase journal after reading the journal and before the replay and the plan' {
            $read = $script:DeviceSyncSource.IndexOf('$journalEntries = @(Get-VsMembershipJournalEntriesForVm')
            $call = $script:DeviceSyncSource.IndexOf('Clear-VsUncertainJournalEntries -JournalEntries $journalEntries', $read)
            $replace = $script:DeviceSyncSource.IndexOf('$journalEntries = @($journalClearance.Remaining)', $call)
            $phaseJournal = $script:DeviceSyncSource.LastIndexOf("`$phase = 'journal'", $call)
            $phaseMecm = $script:DeviceSyncSource.IndexOf("`$phase = 'mecm'", $phaseJournal)
            $plan = $script:DeviceSyncSource.IndexOf('Get-VsMembershipPlan -Desired', $read)

            $read | Should -BeGreaterOrEqual 0
            $call | Should -BeGreaterThan $read
            $replace | Should -BeGreaterThan $call
            $phaseJournal | Should -BeLessThan $read -Because 'the clearance removal must fail as journal_blocked'
            $phaseMecm | Should -BeGreaterThan $replace
            $script:ReplayIf | Should -Not -BeNullOrEmpty -Because 'the replay condition stays literally $journalEntries.Count -gt 0'
            $script:ReplayIf.Extent.StartOffset | Should -BeGreaterThan $replace
            $plan | Should -BeGreaterThan $script:ReplayIf.Extent.EndOffset
        }

        It 'never reports, adopts or writes a membership inside the clearance (AST)' {
            foreach ($function in @($script:ClearApplyFunction, $script:ClearanceFunction)) {
                $function | Should -Not -BeNullOrEmpty
                $names = @($function.FindAll({ param($n) $n -is [System.Management.Automation.Language.CommandAst] }, $true) | ForEach-Object { $_.GetCommandName() })
                $names | Should -Not -Contain 'Invoke-VsApi'
                $names | Should -Not -Contain 'Add-CMDeviceCollectionDirectMembershipRule'
                $names | Should -Not -Contain 'Remove-CMDeviceCollectionDirectMembershipRule'
                $names | Should -Not -Contain 'Set-VsMembershipJournalEntry'
                $names | Should -Not -Contain 'Set-VsMembershipJournalState'
                $function.Extent.Text | Should -Not -Match 'reportMembership|updateDevice|explicitly_adopted'
            }
            $removes = @($script:ClearApplyFunction.FindAll({ param($n) $n -is [System.Management.Automation.Language.CommandAst] -and $n.GetCommandName() -eq 'Remove-VsMembershipJournalEntries' }, $true))
            $removes.Count | Should -Be 1 -Because 'all cleared entries leave the journal in one replacement'
            $loops = @($script:ClearApplyFunction.FindAll({ param($n) $n -is [System.Management.Automation.Language.LoopStatementAst] }, $true))
            foreach ($loop in $loops) {
                $loop.Extent.Text | Should -Not -Match 'Remove-VsMembershipJournalEntries' -Because 'a removal per entry is not atomic'
            }
        }
    }
}
