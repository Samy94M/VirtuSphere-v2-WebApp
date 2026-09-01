<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_form_state.php';
require_once __DIR__ . '/deploy_page.php';
require_once __DIR__ . '/vm_network_display.php';
require_once __DIR__ . '/vm_urls.php';
require_once __DIR__ . '/repo/missions.php';
require_once __DIR__ . '/repo/vms.php';
require_once __DIR__ . '/repo/vm_network.php';

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
    if ($state['vm_ids'] !== [] && $scopeIds === []) {
        return [];
    }
    $preflight = repo_vm_network_preflight($db, $state['mission_id'], $scopeIds, (string) ($mission['wds_vlan'] ?? ''));
    return array_map(
        static fn (array $finding): array => deploy_network_finding_item($finding, false),
        repo_vm_network_preflight_warnings($preflight, $state['mode'])
    );
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
