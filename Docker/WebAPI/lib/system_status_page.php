<?php

declare(strict_types=1);

require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/repo/credentials.php';
require_once __DIR__ . '/repo/ansible_preflight.php';
require_once __DIR__ . '/repo/catalog.php';
require_once __DIR__ . '/system_status.php';

/**
 * POST actions of the system status page (ADR-0018/0023): the manual ESXi
 * inventory refresh and the mass VLAN reassignment. Split out of
 * portal/system_status.php so the page keeps its read-only view. Each action
 * carries its own permission gate so the button visibility on the page and the
 * handler permission here stay in step (portal rule). Always redirects.
 */
function system_status_handle_post(mysqli $connection, array $user): void
{
    $inventoryAction = request_string($_POST, 'action');
    $redirect = 'system_status.php';
    if ($inventoryAction === 'refresh_inventory') {
        if (!can('deploy.run', $user)) {
            portal_forbid($connection, $user, 'deploy.run');
        }
        $credentialId = request_int($_POST, 'credential_id');
        // A targeted refresh stays a deliberate retry, even of an auth-paused
        // credential; only the bulk path skips paused ones (lockout protection,
        // ADR-0023). The flash breaks the outcome down so "0 queued" is never
        // left unexplained.
        $skippedPaused = 0;
        if ($credentialId > 0) {
            $credential = repo_credential($connection, $credentialId);
            if ($credential === null || (string) $credential['type'] !== VIRTUSPHERE_CREDENTIAL_TYPE_ESXI) {
                flash_set('error', __t('system_status.inv_refresh_invalid_target'));
                redirect_to(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI));
            }
            $targets = [$credentialId];
            $redirect = system_status_url('credential-' . $credentialId, ['inventory' => $credentialId]);
        } else {
            $bulk = esxi_inventory_refresh_all_targets($connection);
            $targets = $bulk['ids'];
            $skippedPaused = $bulk['skipped_paused'];
        }
        $enqueued = 0;
        $alreadyPending = 0;
        $failed = 0;
        $resolverFailure = '';
        $jobIds = [];
        foreach ($targets as $targetId) {
            $result = esxi_inventory_enqueue_for_credential($connection, $targetId, (int) $user['id']);
            if (!empty($result['enqueued'])) {
                $enqueued++;
                if (isset($result['job_id'])) {
                    $jobIds[] = (int) $result['job_id'];
                }
            } elseif (in_array(($result['reason'] ?? ''), ['no_ansible_credential', 'ambiguous_ansible_credential', 'invalid_ansible_credential'], true)) {
                // Global condition (missing/ambiguous Ansible SSH credential):
                // no other target can enqueue either.
                $resolverFailure = (string) $result['reason'];
                break;
            } elseif (($result['reason'] ?? '') === 'already_pending') {
                $alreadyPending++;
            } else {
                $failed++;
            }
        }
        audit($connection, VIRTUSPHERE_LOG_CATEGORY_DEPLOY, 'requested ESXi inventory refresh targets [' . implode(',', $targets) . '] jobs [' . implode(',', $jobIds) . '] (' . $enqueued . ' queued, ' . $alreadyPending . ' open, ' . $skippedPaused . ' paused, ' . $failed . ' failed)', (int) $user['id']);
        if ($resolverFailure !== '') {
            $message = match ($resolverFailure) {
                'ambiguous_ansible_credential' => __t('system_status.inv_refresh_ambiguous_ansible'),
                'invalid_ansible_credential' => __t('system_status.inv_refresh_invalid_ansible'),
                default => __t('system_status.inv_refresh_no_ansible'),
            };
            flash_set('warning', $message, '', [
                'url' => 'settings.php#panel-catalog',
                'label' => __t('system_status.inv_configure_ansible'),
            ]);
        } else {
            $parts = [__t('system_status.inv_refresh_queued', ['count' => $enqueued])];
            if ($alreadyPending > 0) {
                $parts[] = __t('system_status.inv_refresh_already_pending', ['count' => $alreadyPending]);
            }
            if ($skippedPaused > 0) {
                $parts[] = __t('system_status.inv_refresh_skipped_paused', ['count' => $skippedPaused]);
            }
            if ($failed > 0) {
                $parts[] = __t('system_status.inv_refresh_failed', ['count' => $failed]);
            }
            $flashAction = count($jobIds) === 1
                ? ['url' => 'deploy_log.php?id=' . $jobIds[0], 'label' => __t('system_status.inv_open_job_log')]
                : ['url' => $redirect, 'label' => __t('system_status.inv_open_card')];
            flash_set($enqueued > 0 ? 'success' : 'warning', implode(' ', $parts), '', $flashAction);
        }
    } elseif ($inventoryAction === 'reassign_vlan') {
        if (!can('missions.write', $user) || !can('vms.write', $user)) {
            portal_forbid($connection, $user, 'missions.write+vms.write');
        }
        $redirect = system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEVIATIONS);
        $vlanFrom = request_trimmed($_POST, 'vlan_from');
        $vlanTo = request_trimmed($_POST, 'vlan_to');
        $activeNames = array_map(static fn (array $vlan): string => (string) $vlan['vlan_name'], repo_active_vlans($connection));
        $errors = [];
        if ($vlanFrom === '') {
            $errors['vlan_from'] = __t('system_status.reassign_from_required');
        } elseif (mb_strlen($vlanFrom) > 255) {
            $errors['vlan_from'] = __t('system_status.reassign_too_long');
        }
        if ($vlanTo === '') {
            $errors['vlan_to'] = __t('system_status.reassign_to_invalid');
        } elseif (mb_strlen($vlanTo) > 255) {
            $errors['vlan_to'] = __t('system_status.reassign_too_long');
        } elseif (!in_array($vlanTo, $activeNames, true)) {
            $errors['vlan_to'] = __t('system_status.reassign_to_invalid');
        } elseif ($vlanFrom === $vlanTo) {
            $errors['vlan_to'] = __t('system_status.reassign_must_differ');
        }
        if ($errors !== []) {
            form_remember('vlan_reassign', $_POST, $errors);
            flash_set('error', (string) reset($errors));
            redirect_to(system_status_url('reassign'));
        }
        try {
            $result = repo_reassign_vlan($connection, $vlanFrom, $vlanTo);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_MISSIONS, 'reassigned vlan ' . $vlanFrom . ' to ' . $vlanTo . ' (' . $result['missions'] . ' missions, ' . $result['interfaces'] . ' interfaces)', (int) $user['id']);
            // "From" is free text, so a typo is the likely reason for zero matches.
            // Reporting success with two zeros reads like the rename went through.
            if ($result['missions'] + $result['interfaces'] === 0) {
                form_remember('vlan_reassign', $_POST, []);
                flash_set('warning', __t('system_status.reassign_none_matched', ['from' => $vlanFrom]));
                $redirect = system_status_url('reassign');
            } else {
                flash_set('success', __t('system_status.reassign_done', ['missions' => $result['missions'], 'interfaces' => $result['interfaces']]));
            }
        } catch (Throwable $exception) {
            form_remember('vlan_reassign', $_POST, []);
            flash_set('error', portal_error_message($exception));
            $redirect = system_status_url('reassign');
        }
    } else {
        http_response_code(400);
        echo h(__t('common.unknown_action'));
        exit;
    }
    redirect_to($redirect);
}
