$plan = New-VsClientNetworkPlan -Targets $targets -Adapters $adapters
if (-not $plan.Valid) {
    if ($someOtherCondition) {
        exit 1
    }
}
Rename-NetAdapter -InputObject $adapter -NewName $targetName
