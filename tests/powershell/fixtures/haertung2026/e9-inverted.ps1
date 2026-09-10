$plan = New-VsClientNetworkPlan -Targets $targets -Adapters $adapters
if ($plan.Valid) {
    exit 1
}
Rename-NetAdapter -InputObject $adapter -NewName $targetName
