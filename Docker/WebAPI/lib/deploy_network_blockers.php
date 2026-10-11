<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_form_state.php';
require_once __DIR__ . '/deploy_page.php';
require_once __DIR__ . '/deploy_urls.php';
require_once __DIR__ . '/vm_network_display.php';
require_once __DIR__ . '/vm_urls.php';
require_once __DIR__ . '/repo/missions.php';
require_once __DIR__ . '/repo/vms.php';
require_once __DIR__ . '/repo/vm_network.php';
require_once __DIR__ . '/repo/deploy_job_scope.php';

/** @return list<array<string,mixed>> */
function deploy_queue_warnings(mysqli $db, array $input): array
{
    $state = deploy_queue_normalize_input($input);
    if ($state['mission_id'] <= 0) {
        return [];
    }
    $mission = repo_get_mission($db, $state['mission_id']);
    if ($mission === null || mission_name_is_template((string) ($mission['mission_name'] ?? ''))) {
        return [];
    }
    $missionVms = getVMs($db, $state['mission_id']);
    $missionVmIds = array_map(static fn (array $vm): int => (int) $vm['id'], $missionVms);
    $scopeIds = $state['vm_ids'] === [] ? [] : array_values(array_intersect($state['vm_ids'], $missionVmIds));
    // An explicitly emptied form selection is a blocker, never "whole mission".
    if (($state['vm_ids'] !== [] && $scopeIds === []) || ($state['vm_selection_explicit'] && $state['vm_ids'] === [])) {
        return [];
    }
    $scope = repo_deploy_mode_vm_selection($db, $state['mission_id'], $state['vm_ids'], $state['mode']);
    $eligibleIds = array_map(static fn (array $vm): int => $vm['id'], $scope['eligible_vms']);
    $warnings = $scope['skipped_vms'] === [] ? [] : [[
        'kind' => 'vm_uncreated_skipped',
        'code' => 'uncreated_vm_skipped',
        'message' => __t(count($scope['skipped_vms']) === 1 ? 'deploy.uncreated_vm_skipped_one' : 'deploy.uncreated_vm_skipped_many', [
            'count' => count($scope['skipped_vms']),
        ]),
        'action' => [
            'type' => 'link',
            'url' => deploy_mission_url($state['mission_id']) . '&mode=create#deploy-queue-form',
            'label' => __t('status.mode_create'),
            'permission' => 'deploy.run',
        ],
    ]];
    if ($eligibleIds === []) {
        return $warnings;
    }
    $preflight = repo_vm_network_preflight($db, $state['mission_id'], $eligibleIds, (string) ($mission['wds_vlan'] ?? ''));
    return array_merge($warnings, array_map(
        static fn (array $finding): array => deploy_network_finding_item($finding, false),
        repo_vm_network_preflight_warnings($preflight, $state['mode'])
    ));
}

/** @return array<string,mixed> */
function deploy_network_finding_item(array $finding, bool $blocking): array
{
    $vmId = (int) ($finding['vm_id'] ?? 0);
    $missionId = (int) ($finding['mission_id'] ?? 0);
    $code = (string) ($finding['code'] ?? '');
    $action = $code === VIRTUSPHERE_WDS_MISSION_MISSING
        ? ['type' => 'link', 'url' => mission_details_url($missionId), 'label' => __t('deploy.open_mission_details'), 'permission' => 'missions.write']
        : ['type' => 'link', 'url' => vm_edit_url($missionId, $vmId, 'interfaces'), 'label' => __t('deploy.network_open'), 'permission' => 'vms.write'];
    return [
        'kind' => VIRTUSPHERE_DEPLOY_BLOCKER_VM_NETWORK_MAPPING,
        'code' => $code,
        'message' => vm_network_finding_message($finding),
        'action' => $action,
        'finding' => $finding,
        'blocking' => $blocking,
    ];
}
