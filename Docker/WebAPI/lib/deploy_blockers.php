<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/deploy_form_state.php';
require_once __DIR__ . '/deploy_page.php';
require_once __DIR__ . '/deploy_urls.php';
require_once __DIR__ . '/system_status.php';
require_once __DIR__ . '/repo/credentials.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/missions.php';
require_once __DIR__ . '/repo/vms.php';

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
            $blockers[] = deploy_action_blocker(
                'datacenter',
                portal_error_message($exception),
                mission_details_url($missionId),
                __t('deploy.open_mission_details')
            );
        }
    }
    if ($selectedMission !== null && $state['vm_ids'] !== []) {
        $missionVmIds = array_map(static fn (array $vm): int => (int) $vm['id'], $missionVms);
        if (array_intersect($state['vm_ids'], $missionVmIds) === []) {
            $blockers[] = deploy_selection_blocker('selection_gone', __t('deploy.err_selection_gone'));
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

/** @param list<array<string,mixed>> $blockers */
function deploy_render_blockers(array $blockers, array $user): void
{
    $count = count($blockers);
    ?>
    <div data-deploy-blockers data-endpoint="deploy_blockers.php" data-error-message="<?php echo h(__t('deploy.blocker_refresh_failed')); ?>" aria-live="polite">
        <p data-deploy-blocker-summary<?php echo $count === 0 ? ' hidden' : ''; ?>>
            <strong><?php echo h(__t($count === 1 ? 'deploy.blocker_count_one' : 'deploy.blocker_count_many', ['count' => $count])); ?></strong>
            <a href="#deploy-blocker-1" data-deploy-blocker-jump><?php echo h(__t('deploy.blocker_jump')); ?></a>
        </p>
        <div data-deploy-blocker-list>
        <?php foreach ($blockers as $index => $blocker) {
            $id = (string) ($blocker['target_id'] ?? ('deploy-blocker-' . ($index + 1)));
            $kind = (string) ($blocker['kind'] ?? '');
            $action = deploy_blocker_action_for_user($blocker, $user);
            if ($kind === VIRTUSPHERE_DEPLOY_BLOCKER_PREREQUISITE || $kind === VIRTUSPHERE_DEPLOY_BLOCKER_EMPTY_MISSION) { ?>
                <div class="alert alert-error" id="<?php echo h($id); ?>" data-deploy-blocker>
                    <strong><?php echo h(__t('deploy.blocker_prefix')); ?></strong>
                    <?php echo h((string) $blocker['message']); ?>
                    <?php if ($action !== null) {
                        if ((string) $action['type'] !== 'link') {
                            throw new LogicException('Unknown deploy blocker action for ' . $kind . ': ' . (string) $action['type']);
                        } ?>
                        <a href="<?php echo h((string) $action['url']); ?>"><?php echo h((string) $action['label']); ?></a>
                    <?php } ?>
                </div>
            <?php } elseif ($kind === VIRTUSPHERE_DEPLOY_BLOCKER_IDENTITY_CONFLICT) {
                ?>
                <div class="alert alert-error" id="<?php echo h($id); ?>" data-deploy-blocker>
                    <p><strong><?php echo h(__t('deploy.blocker_prefix')); ?></strong> <?php echo h((string) $blocker['message']); ?></p>
                    <?php if ($action !== null && (string) $action['type'] === 'adopt') { ?>
                        <form class="inline-form" method="post" action="<?php echo h((string) $action['url']); ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="adopt_vm">
                            <?php foreach ($action['fields'] as $field => $value) { ?>
                                <input type="hidden" name="<?php echo h((string) $field); ?>" value="<?php echo h((string) $value); ?>">
                            <?php } ?>
                            <button class="button button-secondary" type="submit" data-confirm="<?php echo h((string) $action['confirm']); ?>"><?php echo h((string) $action['label']); ?></button>
                        </form>
                    <?php } elseif ($action !== null && (string) $action['type'] === 'link') { ?>
                        <a href="<?php echo h((string) $action['url']); ?>"><?php echo h((string) $action['label']); ?></a>
                    <?php } elseif ($action !== null) {
                        throw new LogicException('Unknown deploy identity action: ' . (string) $action['type']);
                    } ?>
                </div>
            <?php } else {
                throw new LogicException('Unknown deploy blocker kind: ' . $kind);
            }
        } ?>
        </div>
    </div>
    <?php
}

/** @return null|array<string,mixed> */
function deploy_blocker_action_for_user(array $blocker, array $user): ?array
{
    $action = $blocker['action'] ?? null;
    if (!is_array($action)) {
        return null;
    }
    $permission = (string) ($action['permission'] ?? '');
    if ($permission !== '' && !can($permission, $user)) {
        return null;
    }

    return $action;
}

/** @return array<string,mixed> */
function deploy_blocker_json(array $blocker, array $user): array
{
    $result = [
        'kind' => (string) $blocker['kind'],
        'code' => (string) $blocker['code'],
        'message' => (string) $blocker['message'],
        'target_id' => (string) $blocker['target_id'],
    ];
    $action = deploy_blocker_action_for_user($blocker, $user);
    if ($action !== null) {
        unset($action['permission']);
        $result['action'] = $action;
    }

    return $result;
}
