<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_worker_outcome.php';
require_once __DIR__ . '/repo/missions.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/vm_network.php';
require_once __DIR__ . '/vm_network_preflight_result.php';

final class DeployWorkerConfigurationBlocked extends RuntimeException
{
    /** @param array<string,mixed>|null $result */
    public function __construct(string $message, public readonly ?array $result = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

/** @return list<int> actual materialized VM ids */
function deploy_worker_network_preflight(DeployWorkerDbChannel $channel, array $job, string $workerId, ?callable $beforeBlock = null): array
{
    $jobId = (int) $job['id'];
    $missionId = (int) $job['mission_id'];
    $payload = deploy_worker_payload($job);
    $mission = repo_get_mission($channel->connection(), $missionId);
    if ($mission === null) {
        throw new DeployWorkerConfigurationBlocked('Mission no longer exists.');
    }
    $requestedVmIds = (array) ($payload['vm_ids'] ?? []);
    $preflight = repo_vm_network_preflight(
        $channel->connection(),
        $missionId,
        $requestedVmIds,
        (string) ($mission['wds_vlan'] ?? '')
    );
    $materializedVmIds = array_map(static fn (array $vm): int => (int) $vm['id'], $preflight['vms']);
    $missingVmIds = $requestedVmIds === [] ? [] : array_values(array_diff($requestedVmIds, $materializedVmIds));
    sort($missingVmIds, SORT_NUMERIC);
    if ($missingVmIds === []) {
        try {
            // Recheck mutable location evidence at the same pre-remote boundary
            // as the NIC contract. A queue-time proof may expire before start.
            repo_deploy_assert_mission_ready(
                $channel->connection(),
                $mission,
                (int) $job['credential_esxi_id'],
                (string) $payload['mode']
            );
        } catch (Throwable $exception) {
            throw new DeployWorkerConfigurationBlocked($exception->getMessage(), null, $exception);
        }
    }
    $blockers = repo_vm_network_preflight_blockers($preflight, (string) $payload['mode']);
    $warnings = repo_vm_network_preflight_warnings($preflight, (string) $payload['mode']);
    $byVmBlocker = [];
    foreach ($blockers as $finding) {
        $byVmBlocker[(int) ($finding['vm_id'] ?? 0)][] = $finding;
    }
    $progressRows = $preflight['vms'];
    foreach ($missingVmIds as $missingVmId) {
        $progressRows[] = ['id' => $missingVmId, 'vm_name' => 'VM #' . $missingVmId, 'missing' => true];
    }
    usort($progressRows, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);
    $total = count($progressRows);
    foreach ($progressRows as $index => $vm) {
        $position = $index + 1;
        $vmId = (int) $vm['id'];
        $name = (string) $vm['vm_name'];
        $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, '[' . $position . '/' . $total . '] RUN network/WDS preflight ' . $name);
        if (!empty($vm['missing'])) {
            $channel->log(VIRTUSPHERE_DEPLOY_LOG_WORKER_ERROR, '[' . $position . '/' . $total . '] FAIL network/WDS preflight ' . $name . ': selected VM no longer exists');
        } elseif (isset($byVmBlocker[$vmId])) {
            $codes = implode(',', array_column($byVmBlocker[$vmId], 'code'));
            $channel->log(VIRTUSPHERE_DEPLOY_LOG_WORKER_ERROR, '[' . $position . '/' . $total . '] FAIL network/WDS preflight ' . $name . ': ' . $codes);
        } else {
            $channel->log(VIRTUSPHERE_DEPLOY_LOG_SYSTEM, '[' . $position . '/' . $total . '] OK network/WDS preflight ' . $name);
        }
    }
    if ($warnings !== []) {
        $warningVmIds = array_values(array_unique(array_map(static fn (array $warning): int => (int) ($warning['vm_id'] ?? 0), $warnings)));
        $warningCodes = array_values(array_unique(array_map(static fn (array $warning): string => (string) ($warning['code'] ?? ''), $warnings)));
        sort($warningCodes, SORT_STRING);
        $channel->log(
            VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
            'Network preflight warning: ' . count($warningVmIds) . ' VM(s), codes=' . implode(',', $warningCodes) . '. Mode continues without MAC mapping.'
        );
    }
    if ($blockers !== [] || $missingVmIds !== []) {
        $result = vm_network_preflight_result_contract((string) $payload['mode'], $preflight['vms'], $blockers, $missingVmIds);
        // The contract is already bounded; encoding here only proves that the
        // document this worker is about to hand to the terminal CAS is storable.
        repo_encode_deploy_preflight_result($result);
        if ($beforeBlock !== null) {
            $beforeBlock($result);
        }
        throw new DeployWorkerConfigurationBlocked('VM network configuration changed after queueing; remote work was not started.', $result);
    }
    return $materializedVmIds;
}
