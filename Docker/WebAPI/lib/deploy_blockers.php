<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/deploy_form_state.php';
require_once __DIR__ . '/deploy_page.php';
require_once __DIR__ . '/deploy_network_blockers.php';
require_once __DIR__ . '/deploy_queue_blocker_view.php';
require_once __DIR__ . '/deploy_preflight_bounds.php';
require_once __DIR__ . '/deploy_urls.php';
require_once __DIR__ . '/help_page.php';
require_once __DIR__ . '/system_status.php';
require_once __DIR__ . '/repo/credentials.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/missions.php';
require_once __DIR__ . '/repo/vms.php';
require_once __DIR__ . '/repo/vm_network.php';

/**
 * One exhaustive, discriminated list for every condition that disables queueing.
 * Warnings such as inventory age, capacity and host capabilities never enter it.
 *
 * @param list<array<string,mixed>> $missionVms
 * @param list<array<string,mixed>> $identityConflicts
 * @return list<array<string,mixed>>
 */
function deploy_queue_base_blockers(
    bool $hasMissions,
    bool $hasEsxiCredential,
    bool $hasAnsibleCredential,
    bool $apiBaseUrlReady,
    string $apiBaseUrlError,
    bool $missionSelected,
    ?array $selectedMission,
    array $missionVms,
    array $identityConflicts,
    int $selectedEsxiCredentialId = 0
): array {
    $blockers = deploy_prerequisite_notices(
        $hasMissions,
        $hasEsxiCredential,
        $hasAnsibleCredential,
        $apiBaseUrlReady,
        $apiBaseUrlError
    );

    if ($hasMissions && !$missionSelected) {
        $blockers[] = [
            'kind' => VIRTUSPHERE_DEPLOY_BLOCKER_PREREQUISITE,
            'code' => 'mission_selection',
            'message' => __t('deploy.blocker_select_mission'),
        ];
    } elseif ($missionSelected && $selectedMission === null) {
        $blockers[] = [
            'kind' => VIRTUSPHERE_DEPLOY_BLOCKER_PREREQUISITE,
            'code' => 'mission_unavailable',
            'message' => __t('deploy.blocker_mission_unavailable'),
            'action' => [
                'type' => 'link',
                'url' => 'missions.php?type=missions',
                'label' => __t('deploy.req_missions_link'),
                'permission' => '',
            ],
        ];
    } elseif ($selectedMission !== null && $missionVms === []) {
        $blockers[] = [
            'kind' => VIRTUSPHERE_DEPLOY_BLOCKER_EMPTY_MISSION,
            'code' => 'empty_mission',
            'message' => __t('deploy.vms_empty'),
            'action' => [
                'type' => 'link',
                'url' => 'vms.php?mission_id=' . (int) $selectedMission['id'],
                'label' => __t('deploy.vms_empty_link'),
                'permission' => 'vms.write',
            ],
        ];
    }

    foreach ($identityConflicts as $conflict) {
        $identityComplete = (string) $conflict['inventory_moid'] !== ''
            && (string) $conflict['inventory_instance_uuid'] !== '';
        $action = $identityComplete
            ? [
                'type' => 'adopt',
                'url' => deploy_mission_url((int) ($selectedMission['id'] ?? 0)),
                'label' => __t('deploy.identity_adopt_button'),
                'permission' => 'vms.write',
                'confirm' => __t('deploy.identity_adopt_confirm', ['name' => (string) $conflict['vm_name']]),
                'fields' => [
                    'mission_id' => (int) ($selectedMission['id'] ?? 0),
                    'credential_esxi_id' => $selectedEsxiCredentialId,
                    'vm_id' => (int) $conflict['vm_id'],
                ],
            ]
            : [
                'type' => 'link',
                'url' => system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI),
                'label' => __t('deploy.identity_refresh_link'),
                'permission' => '',
            ];
        $blockers[] = [
            'kind' => VIRTUSPHERE_DEPLOY_BLOCKER_IDENTITY_CONFLICT,
            'code' => 'identity_conflict',
            'message' => __t('deploy.identity_conflict', ['name' => (string) $conflict['vm_name']]),
            'action' => $action,
            'conflict' => $conflict,
        ];
    }

    return $blockers;
}

/** @return list<array<string,mixed>> */
function deploy_queue_blockers(mysqli $db, array $input): array
{
    $state = deploy_queue_normalize_input($input);
    $missions = array_values(array_filter(getMissions($db), static fn (array $mission): bool =>
        !mission_name_is_template((string) ($mission['mission_name'] ?? ''))
    ));
    $esxiCredentials = repo_credentials_by_type($db, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
    $ansibleCredentials = repo_credentials_by_type($db, VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);
    $missionId = $state['mission_id'];
    $selectedMission = $missionId > 0 ? repo_get_mission($db, $missionId) : null;
    if ($selectedMission !== null && mission_name_is_template((string) ($selectedMission['mission_name'] ?? ''))) {
        $selectedMission = null;
    }
    $missionVms = $selectedMission !== null ? getVMs($db, $missionId) : [];

    $selectedEsxiValid = false;
    if ($state['credential_esxi_id'] > 0) {
        try {
            repo_deploy_assert_credential_type($db, $state['credential_esxi_id'], VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
            $selectedEsxiValid = true;
        } catch (Throwable) {
            $selectedEsxiValid = false;
        }
    }
    $selectedAnsibleValid = false;
    if ($state['credential_ansible_id'] > 0) {
        try {
            repo_deploy_assert_credential_type($db, $state['credential_ansible_id'], VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);
            $selectedAnsibleValid = true;
        } catch (Throwable) {
            $selectedAnsibleValid = false;
        }
    }

    $apiBaseUrlReady = true;
    $apiBaseUrlError = '';
    try {
        ansible_resolve_api_base_url($db);
    } catch (Throwable $exception) {
        $apiBaseUrlReady = false;
        $apiBaseUrlError = portal_error_message($exception);
    }

    $identityConflicts = [];
    if ($selectedMission !== null && $selectedEsxiValid) {
        $identityConflicts = repo_vm_identity_conflicts(
            $db,
            $missionId,
            $state['credential_esxi_id'],
            $state['vm_ids']
        );
    }

    $blockers = deploy_queue_base_blockers(
        $missions !== [],
        $esxiCredentials !== [],
        $ansibleCredentials !== [],
        $apiBaseUrlReady,
        $apiBaseUrlError,
        $missionId > 0,
        $selectedMission,
        $missionVms,
        $identityConflicts,
        $state['credential_esxi_id']
    );

    if ($missionId > 0 && $esxiCredentials !== [] && $state['credential_esxi_id'] <= 0) {
        $blockers[] = deploy_selection_blocker('esxi_selection', __t('deploy.blocker_select_esxi'));
    } elseif ($state['credential_esxi_id'] > 0 && !$selectedEsxiValid) {
        $blockers[] = deploy_action_blocker(
            'esxi_unavailable',
            __t('deploy.blocker_esxi_unavailable'),
            'credentials.php',
            __t('deploy.req_credentials_link'),
            'credentials.manage'
        );
    }
    if ($missionId > 0 && $ansibleCredentials !== [] && $state['credential_ansible_id'] <= 0) {
        $blockers[] = deploy_selection_blocker('ansible_selection', __t('deploy.blocker_select_ansible'));
    } elseif ($state['credential_ansible_id'] > 0 && !$selectedAnsibleValid) {
        $blockers[] = deploy_action_blocker(
            'ansible_unavailable',
            __t('deploy.blocker_ansible_unavailable'),
            'credentials.php',
            __t('deploy.req_credentials_link'),
            'credentials.manage'
        );
    }
    if ($selectedMission !== null && deploy_active_job_visible($db, $missionId)) {
        $blockers[] = deploy_selection_blocker('active_job', __t('deploy.err_active_job'));
    }
    if ($selectedMission !== null && virtusphere_deploy_mode_needs_location($state['mode'])
        && trim((string) ($selectedMission['hypervisor_datastorage'] ?? '')) === '') {
        $blockers[] = deploy_action_blocker(
            'datastore',
            __t('deploy.err_datastore_required'),
            mission_details_url($missionId),
            __t('deploy.open_mission_details')
        );
    }
    if ($selectedMission !== null && $selectedEsxiValid) {
        try {
            deploy_assert_datacenter_resolvable($db, $missionId, $state['credential_esxi_id'], $state['mode']);
        } catch (ValidationException $exception) {
            $message = portal_error_message($exception);
            $code = 'datacenter_name_never_confirmed';
            if (preg_match('/^(datacenter_name_[a-z_]+):\s*(.*)$/s', $message, $match) === 1) {
                $code = $match[1];
                $message = $match[2];
            }
            $blockers[] = deploy_action_blocker(
                $code,
                $message,
                system_status_url('credential-' . $state['credential_esxi_id'], ['inventory' => $state['credential_esxi_id']]),
                __t('deploy.inventory_deviation_link')
            );
        }
    }
    if ($selectedMission !== null && $state['vm_ids'] !== []) {
        $missionVmIds = array_map(static fn (array $vm): int => (int) $vm['id'], $missionVms);
        if (array_intersect($state['vm_ids'], $missionVmIds) === []) {
            $blockers[] = deploy_selection_blocker('selection_gone', __t('deploy.err_selection_gone'));
        }
    }

    if ($selectedMission !== null && $missionVms !== []) {
        $missionVmIds = array_map(static fn (array $vm): int => (int) $vm['id'], $missionVms);
        $scopeIds = $state['vm_ids'] === [] ? [] : array_values(array_intersect($state['vm_ids'], $missionVmIds));
        if ($state['vm_ids'] === [] || $scopeIds !== []) {
            $preflight = repo_vm_network_preflight($db, $missionId, $scopeIds, (string) ($selectedMission['wds_vlan'] ?? ''));
            // The same scope cap the repo enforces before the insert, shown here
            // as a blocker with its own fix: an operator must learn that the
            // selection is too large while it is still a selection, not from an
            // exception after the submit.
            try {
                if ((int) $state['stagger_minutes'] > 0 && in_array($state['mode'], VIRTUSPHERE_DEPLOY_STAGGER_MODES, true)) {
                    foreach ($preflight['vms'] as $vm) {
                        repo_vm_network_assert_scope_within_bounds([$vm]);
                    }
                } else {
                    repo_vm_network_assert_scope_within_bounds($preflight['vms']);
                }
            } catch (ValidationException $exception) {
                $scopeBlocker = deploy_action_blocker(
                    'job_scope_limit',
                    portal_error_message($exception),
                    'vms.php?mission_id=' . $missionId,
                    __t('deploy.vms_empty_link'),
                    'vms.write'
                );
                // The only blocker whose number looks arbitrary from the outside,
                // so it is the only one that carries a second link. The first one
                // is the repair (where the selection is made); this one is the
                // reason the ceiling exists, and it lives in the help exactly
                // once. It needs no permission of its own: the deploy help panel
                // already requires `deploy.run`, and nobody without that ever
                // sees this list. Built with help_url(), never by hand.
                $scopeBlocker['help'] = [
                    'url' => help_url('deploy', 'help-network-contract'),
                    'label' => __t('deploy.blocker_help_network_contract'),
                ];
                $blockers[] = $scopeBlocker;
            }
            foreach (repo_vm_network_preflight_blockers($preflight, $state['mode']) as $finding) {
                $blockers[] = deploy_network_finding_item($finding, true);
            }
        }
    }

    foreach ($blockers as $index => &$blocker) {
        $blocker['target_id'] = 'deploy-blocker-' . ($index + 1);
    }
    unset($blocker);

    return $blockers;
}

function deploy_active_job_visible(mysqli $db, int $missionId): bool
{
    $active = VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES;
    $placeholders = implode(', ', array_fill(0, count($active), '?'));

    return repo_fetch_one(
        $db,
        'SELECT id FROM deploy_jobs WHERE mission_id = ? AND status IN (' . $placeholders . ') AND cancelled_at IS NULL LIMIT 1',
        'i' . str_repeat('s', count($active)),
        array_merge([$missionId], $active)
    ) !== null;
}

/** @return array{kind:string,code:string,message:string} */
function deploy_selection_blocker(string $code, string $message): array
{
    return [
        'kind' => VIRTUSPHERE_DEPLOY_BLOCKER_PREREQUISITE,
        'code' => $code,
        'message' => $message,
    ];
}

/** @return array{kind:string,code:string,message:string,action:array{type:string,url:string,label:string,permission:string}} */
function deploy_action_blocker(
    string $code,
    string $message,
    string $url,
    string $label,
    string $permission = ''
): array {
    return [
        'kind' => VIRTUSPHERE_DEPLOY_BLOCKER_PREREQUISITE,
        'code' => $code,
        'message' => $message,
        'action' => [
            'type' => 'link',
            'url' => $url,
            'label' => $label,
            'permission' => $permission,
        ],
    ];
}

function deploy_assert_queue_unblocked(mysqli $db, array $input): void
{
    $blockers = deploy_queue_blockers($db, $input);
    if ($blockers !== []) {
        throw new ValidationException([], (string) $blockers[0]['message']);
    }
}
