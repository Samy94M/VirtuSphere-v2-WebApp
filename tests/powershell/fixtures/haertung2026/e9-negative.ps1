$plan = New-VsClientNetworkPlan -Targets $targets -Adapters $adapters
Rename-NetAdapter -InputObject $adapter -NewName $targetName
if (-not $plan.Valid) {
    exit 1
}
