<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_service_health.php';

require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/deploy_form_state.php';
require_once __DIR__ . '/deploy_blockers.php';
require_once __DIR__ . '/deploy_page.php';
require_once __DIR__ . '/deploy_storage.php';
require_once __DIR__ . '/esxi_capabilities.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/repo/credentials.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/esxi_inventory.php';
require_once __DIR__ . '/repo/missions.php';
require_once __DIR__ . '/system_status.php';

/** @return array<string,mixed> */
function deploy_build_view_model(mysqli $connection, int $selectedMissionId): array
{
    $missions = array_values(array_filter(getMissions($connection), static function (array $mission): bool {
        return !mission_name_is_template((string) ($mission['mission_name'] ?? ''));
    }));
    $esxiCredentials = repo_credentials_by_type($connection, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
    $ansibleCredentials = repo_credentials_by_type($connection, VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);
    $jobs = repo_deploy_jobs($connection, 100, $selectedMissionId > 0 ? $selectedMissionId : null);
    $selectedMission = $selectedMissionId > 0 ? repo_get_mission($connection, $selectedMissionId) : null;
    // The one snapshot (Etappe 13R): the sentence above the queue button and the
    // System status card read the same function, so the page cannot promise an
    // immediate start while the card says the service is paused.
    $serviceSnapshot = deploy_service_health_snapshot($connection);
    $missionVms = $selectedMission !== null ? getVMs($connection, $selectedMissionId) : [];
    $selectedMissionDeviates = $selectedMissionId > 0
        && isset(esxi_inventory_deviating_mission_ids($connection)[$selectedMissionId]);

    $hostWarnings = [];
    if ($selectedMission !== null && $esxiCredentials !== []) {
        $credentialNames = [];
        foreach ($esxiCredentials as $credential) {
            $credentialNames[(int) $credential['id']] = (string) ($credential['name'] ?? '');
        }
        foreach (esxi_inventory_mission_missing_by_credential($connection, $selectedMissionId) as $credId => $missingValues) {
            $hostWarnings[(string) $credId] = __t('deploy.host_missing_warn', [
                'host' => $credentialNames[$credId] ?? ('#' . $credId),
                'values' => implode(', ', $missingValues),
            ]);
        }
    }

    $capabilityWarnings = [];
    $esxiStates = repo_esxi_inventory_states($connection);
    foreach ($esxiCredentials as $credential) {
        $credentialId = (int) $credential['id'];
        $messages = [];
        foreach (esxi_capability_warnings($esxiStates[$credentialId] ?? null) as $warning) {
            if ($warning['level'] === 'warning') {
                $messages[] = __t('system_status.cap_legend_' . $warning['key']);
            }
        }
        if ($messages !== []) {
            $capabilityWarnings[(string) $credentialId] = __t('deploy.capability_warn', [
                'host' => (string) ($credential['name'] ?? ('#' . $credentialId)),
                'notes' => implode(' ', $messages),
            ]);
        }
    }

    $storageRows = $selectedMission !== null
        ? ansible_storage_by_datastore($selectedMission, deploy_selected_vms($missionVms, array_keys(deploy_form_vm_selection() ?? [])))
        : [];
    $storageIsland = deploy_storage_island($connection, $storageRows, $esxiCredentials);

    $selectedEsxiId = deploy_form_value('credential_esxi_id');
    $deployBlockers = deploy_queue_blockers($connection, deploy_form_state()['values']);
    $deployWarnings = deploy_queue_warnings($connection, deploy_form_state()['values']);
    $canQueue = $deployBlockers === [];
    $initialHostWarning = $hostWarnings[$selectedEsxiId] ?? '';
    $initialCapabilityWarning = $capabilityWarnings[$selectedEsxiId] ?? '';
    $initialCapacity = $selectedEsxiId !== '' ? deploy_datastore_capacity($connection, (int) $selectedEsxiId) : [];

    $scheduleMinLocal = (new DateTimeImmutable('now', new DateTimeZone(portal_timezone())))->format('Y-m-d\TH:i');
    $groupPositions = [];
    $retryEvaluations = [];
    $byGroup = [];
    foreach ($jobs as $job) {
        $groupKey = (string) ($job['group_id'] ?? '');
        if ($groupKey !== '') {
            $byGroup[$groupKey][] = (int) $job['id'];
        }
    }
    foreach ($byGroup as $ids) {
        sort($ids);
        $total = count($ids);
        foreach ($ids as $pos => $id) {
            $groupPositions[$id] = [$pos + 1, $total];
        }
    }
    foreach ($jobs as $job) {
        if (!deploy_job_is_retryable((string) ($job['status'] ?? ''), isset($job['mission_id']) ? (int) $job['mission_id'] : null)) {
            continue;
        }
        $retryEvaluations[(int) $job['id']] = deploy_retry_blockers($connection, (int) $job['id']);
    }

    return compact(
        'missions',
        'esxiCredentials',
        'ansibleCredentials',
        'jobs',
        'selectedMission',
        'missionVms',
        'deployBlockers',
        'deployWarnings',
        'selectedMissionDeviates',
        'hostWarnings',
        'capabilityWarnings',
        'storageRows',
        'storageIsland',
        'selectedEsxiId',
        'canQueue',
        'initialHostWarning',
        'initialCapabilityWarning',
        'serviceSnapshot',
        'initialCapacity',
        'scheduleMinLocal',
        'groupPositions',
        'retryEvaluations'
    );
}
