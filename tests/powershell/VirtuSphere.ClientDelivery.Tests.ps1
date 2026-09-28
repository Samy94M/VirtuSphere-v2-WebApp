BeforeAll {
    $script:RepoRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    . (Join-Path $script:RepoRoot 'Powershell-MECM/mecm/VirtuSphere-ClientPackaging.ps1')
    . (Join-Path $script:RepoRoot 'Powershell-MECM/mecm/VirtuSphere-ClientDelivery.ps1')

    function New-TestGroup {
        param([string]$App, [string]$Group, [object[]]$Targets, [string]$State = 'confirmed')
        [pscustomobject]@{ App = $App; Group = $Group; Targets = @($Targets); State = $State }
    }
    function New-TestTarget {
        param([string]$App, [bool]$AutoInstall = $true)
        [pscustomobject]@{ App = $App; AutoInstall = $AutoInstall }
    }
    function Get-TargetGroups {
        @(
            New-TestGroup -App 'client_hostname' -Group 'Requires client_getInfos' -Targets @(New-TestTarget 'client_getInfos')
            New-TestGroup -App 'client_VMDisksOnline' -Group 'Requires client_hostname' -Targets @(New-TestTarget 'client_hostname')
            New-TestGroup -App 'client_staticip' -Group 'Requires client_VMDisksOnline' -Targets @(New-TestTarget 'client_VMDisksOnline')
        )
    }
}

Describe 'MC03 journaled four-folder source activation' {
    BeforeEach {
        $script:Base = Join-Path ([IO.Path]::GetTempPath()) ('vs-mc03-activate-' + [guid]::NewGuid().ToString('N'))
        $script:Packages = Join-Path $script:Base 'packages'
        $script:Stage = Join-Path $script:Packages '.stage'
        $script:Journal = Join-Path $script:Base 'journal.jsonl'
        $script:Specs = @(Get-VsClientAppSpecs)
        foreach ($spec in $script:Specs) {
            New-Item -ItemType Directory -Path (Join-Path $script:Packages $spec.Folder) -Force | Out-Null
            Set-Content -LiteralPath (Join-Path (Join-Path $script:Packages $spec.Folder) 'marker.txt') -Value 'old' -Encoding ASCII
            New-Item -ItemType Directory -Path (Join-Path $script:Stage $spec.Folder) -Force | Out-Null
            Set-Content -LiteralPath (Join-Path (Join-Path $script:Stage $spec.Folder) 'marker.txt') -Value 'new' -Encoding ASCII
        }
    }
    AfterEach { Remove-Item -LiteralPath $script:Base -Recurse -Force -ErrorAction SilentlyContinue }

    It 'activates all four folders, keeps the old set as backup and journals intent before outcome' {
        $result = Invoke-VsClientSourceActivation -PackagesBase $script:Packages -StageRoot $script:Stage -Specs $script:Specs -BundleId ('a' * 64) -JournalPath $script:Journal
        $result.Status | Should -Be 'activated'
        foreach ($spec in $script:Specs) {
            (Get-Content -LiteralPath (Join-Path (Join-Path $script:Packages $spec.Folder) 'marker.txt')) | Should -Be 'new'
            (Get-Content -LiteralPath (Join-Path (Join-Path $result.BackupRoot $spec.Folder) 'marker.txt')) | Should -Be 'old'
        }
        $events = @(Get-Content -LiteralPath $script:Journal | ForEach-Object { $_ | ConvertFrom-Json })
        @($events.Event) | Should -Be @('intent', 'outcome')
        $events[1].Outcome | Should -Be 'activated'
        $events[0].BundleId | Should -Be ('a' * 64)
    }

    It 'restores the complete old set when the third folder cannot be activated' {
        $script:MoveCount = 0
        Mock Move-Item {
            $script:MoveCount++
            if ($script:MoveCount -eq 6) { throw 'injected rename failure' }
            Microsoft.PowerShell.Management\Move-Item -LiteralPath $LiteralPath -Destination $Destination -ErrorAction Stop
        }
        $result = Invoke-VsClientSourceActivation -PackagesBase $script:Packages -StageRoot $script:Stage -Specs $script:Specs -BundleId ('a' * 64) -JournalPath $script:Journal
        $result.Status | Should -Be 'rolled_back'
        foreach ($spec in $script:Specs) {
            (Get-Content -LiteralPath (Join-Path (Join-Path $script:Packages $spec.Folder) 'marker.txt')) | Should -Be 'old'
        }
        $events = @(Get-Content -LiteralPath $script:Journal | ForEach-Object { $_ | ConvertFrom-Json })
        $events[-1].Outcome | Should -Be 'rolled_back'
        $events[-1].Detail | Should -Match 'injected rename failure'
    }

    It 'refuses a stage outside PackagesBase or with a missing folder before touching the active set' {
        Remove-Item -LiteralPath (Join-Path $script:Stage 'client_staticip') -Recurse -Force
        { Invoke-VsClientSourceActivation -PackagesBase $script:Packages -StageRoot $script:Stage -Specs $script:Specs -BundleId ('a' * 64) -JournalPath $script:Journal } | Should -Throw '*stage*'
        $outside = Join-Path $script:Base 'outside'
        New-Item -ItemType Directory -Path $outside -Force | Out-Null
        { Invoke-VsClientSourceActivation -PackagesBase $script:Packages -StageRoot $outside -Specs $script:Specs -BundleId ('a' * 64) -JournalPath $script:Journal } | Should -Throw '*PackagesBase*'
        foreach ($spec in $script:Specs) {
            (Get-Content -LiteralPath (Join-Path (Join-Path $script:Packages $spec.Folder) 'marker.txt')) | Should -Be 'old'
        }
        Test-Path -LiteralPath $script:Journal | Should -BeFalse
    }
}

Describe 'MC03 distribution decision per content and distribution point' {
    It 'chooses initial, update, redistribute or nothing from the observed state' {
        $cases = @(
            @{ Association = 'absent'; ContentChanged = $true; DpState = 'none'; Expected = 'initial' }
            # A DP added to the group later has never received unchanged content.
            @{ Association = 'absent'; ContentChanged = $false; DpState = 'none'; Expected = 'initial' }
            @{ Association = 'present'; ContentChanged = $true; DpState = 'ready'; Expected = 'update' }
            @{ Association = 'present'; ContentChanged = $true; DpState = 'failed'; Expected = 'update' }
            @{ Association = 'present'; ContentChanged = $false; DpState = 'failed'; Expected = 'redistribute' }
            @{ Association = 'present'; ContentChanged = $false; DpState = 'ready'; Expected = 'none' }
        )
        foreach ($case in $cases) {
            (Get-VsClientDistributionAction -Association $case.Association -ContentChanged $case.ContentChanged -DpState $case.DpState).Action |
                Should -Be $case.Expected -Because ($case | ConvertTo-Json -Compress)
        }
    }

    It 'blocks every unknown, in-progress or contradictory state instead of guessing a request' {
        $cases = @(
            @{ Association = 'unknown'; ContentChanged = $true; DpState = 'ready' }
            @{ Association = 'present'; ContentChanged = $false; DpState = 'unknown' }
            @{ Association = 'present'; ContentChanged = $false; DpState = 'in_progress' }
            @{ Association = 'present'; ContentChanged = $true; DpState = 'in_progress' }
            @{ Association = 'absent'; ContentChanged = $true; DpState = 'ready' }
            @{ Association = 'present'; ContentChanged = $false; DpState = 'none' }
            @{ Association = 'present'; ContentChanged = $true; DpState = 'removing' }
        )
        foreach ($case in $cases) {
            $decision = Get-VsClientDistributionAction -Association $case.Association -ContentChanged $case.ContentChanged -DpState $case.DpState
            $decision.Action | Should -Be 'blocked' -Because ($case | ConvertTo-Json -Compress)
            $decision.Reason | Should -Not -BeNullOrEmpty
        }
    }
}

Describe 'MC03 ordered dependency graph reconciliation plan' {
    It 'derives the three target edges from the one spec table' {
        $edges = @(Get-VsClientDesiredDependencyEdges -Specs (Get-VsClientAppSpecs))
        @($edges | ForEach-Object { '{0}->{1}' -f $_.App, $_.DependsOn }) |
            Should -Be @('client_hostname->client_getInfos', 'client_VMDisksOnline->client_hostname', 'client_staticip->client_VMDisksOnline')
    }

    It 'plans nothing for the confirmed target graph' {
        $plan = Get-VsClientGraphReconciliationPlan -Specs (Get-VsClientAppSpecs) -ActualGroups (Get-TargetGroups) -DeploymentFree $true
        $plan.Status | Should -Be 'ready'
        @($plan.Steps).Count | Should -Be 0
    }

    It 'removes the old disks-after-staticip edges before adding the target edges in chain order' {
        $old = @(
            New-TestGroup -App 'client_hostname' -Group 'Requires client_getInfos' -Targets @(New-TestTarget 'client_getInfos')
            New-TestGroup -App 'client_staticip' -Group 'Requires client_hostname' -Targets @(New-TestTarget 'client_hostname')
            New-TestGroup -App 'client_VMDisksOnline' -Group 'Requires client_staticip' -Targets @(New-TestTarget 'client_staticip')
        )
        $plan = Get-VsClientGraphReconciliationPlan -Specs (Get-VsClientAppSpecs) -ActualGroups $old -DeploymentFree $true
        $plan.Status | Should -Be 'ready'
        @($plan.Steps | ForEach-Object { '{0}:{1}:{2}' -f $_.Action, $_.App, $_.Group }) | Should -Be @(
            'remove-group:client_staticip:Requires client_hostname'
            'remove-group:client_VMDisksOnline:Requires client_staticip'
            'add-dependency:client_VMDisksOnline:Requires client_hostname'
            'add-dependency:client_staticip:Requires client_VMDisksOnline'
        )
    }

    It 'replaces a target edge that is not auto-install and removes a legacy getinfo edge' {
        $groups = @(
            New-TestGroup -App 'client_hostname' -Group 'Requires client_getInfos' -Targets @(New-TestTarget 'client_getInfos' $false)
            New-TestGroup -App 'client_hostname' -Group 'Requires client_getinfo' -Targets @(New-TestTarget 'client_getinfo')
            New-TestGroup -App 'client_VMDisksOnline' -Group 'Requires client_hostname' -Targets @(New-TestTarget 'client_hostname')
            New-TestGroup -App 'client_staticip' -Group 'Requires client_VMDisksOnline' -Targets @(New-TestTarget 'client_VMDisksOnline')
        )
        $plan = Get-VsClientGraphReconciliationPlan -Specs (Get-VsClientAppSpecs) -ActualGroups $groups -DeploymentFree $true
        $plan.Status | Should -Be 'ready'
        @($plan.Steps | ForEach-Object { '{0}:{1}:{2}' -f $_.Action, $_.App, $_.Group }) | Should -Be @(
            'remove-group:client_hostname:Requires client_getInfos'
            'remove-group:client_hostname:Requires client_getinfo'
            'add-dependency:client_hostname:Requires client_getInfos'
        )
    }

    It 'blocks a foreign dependency, an unknown group, a cycle and any change without deployment-free evidence' {
        $foreign = @(Get-TargetGroups) + @(New-TestGroup -App 'client_staticip' -Group 'Requires Office' -Targets @(New-TestTarget 'Office'))
        (Get-VsClientGraphReconciliationPlan -Specs (Get-VsClientAppSpecs) -ActualGroups $foreign -DeploymentFree $true).Status | Should -Be 'blocked'

        $unknown = @(Get-TargetGroups)
        $unknown[0].State = 'unknown'
        (Get-VsClientGraphReconciliationPlan -Specs (Get-VsClientAppSpecs) -ActualGroups $unknown -DeploymentFree $true).Status | Should -Be 'blocked'

        $cycle = @(
            New-TestGroup -App 'client_hostname' -Group 'Requires client_staticip' -Targets @(New-TestTarget 'client_staticip')
            New-TestGroup -App 'client_staticip' -Group 'Requires client_hostname' -Targets @(New-TestTarget 'client_hostname')
        )
        $plan = Get-VsClientGraphReconciliationPlan -Specs (Get-VsClientAppSpecs) -ActualGroups $cycle -DeploymentFree $true
        $plan.Status | Should -Be 'blocked'
        ($plan.Findings -join '|') | Should -Match 'actual-graph-cycle'

        $old = @(New-TestGroup -App 'client_VMDisksOnline' -Group 'Requires client_staticip' -Targets @(New-TestTarget 'client_staticip'))
        $plan = Get-VsClientGraphReconciliationPlan -Specs (Get-VsClientAppSpecs) -ActualGroups $old -DeploymentFree $false
        $plan.Status | Should -Be 'blocked'
        ($plan.Findings -join '|') | Should -Match 'deployment-free-evidence-missing'
        @($plan.Steps).Count | Should -Be 0
    }

    It 'still reports a confirmed target graph as ready without deployment-free evidence because nothing changes' {
        (Get-VsClientGraphReconciliationPlan -Specs (Get-VsClientAppSpecs) -ActualGroups (Get-TargetGroups) -DeploymentFree $false).Status | Should -Be 'ready'
    }
}
