<?php

declare(strict_types=1);

require_once __DIR__ . '/esxi_inventory.php';

/**
 * POST actions of the integrations page (ADR-0018/0023): the manual ESXi
 * inventory refresh and the mass VLAN reassignment. Split out of
 * portal/integrations.php so the page keeps its read-only view. Each action
 * carries its own permission gate so the button visibility on the page and the
 * handler permission here stay in step (portal rule). Always redirects.
 */
function integrations_handle_post(mysqli $connection, array $user): void
{
    $inventoryAction = request_string($_POST, 'action');
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
            $targets = [$credentialId];
        } else {
            $bulk = esxi_inventory_refresh_all_targets($connection);
            $targets = $bulk['ids'];
            $skippedPaused = $bulk['skipped_paused'];
        }
        $enqueued = 0;
        $alreadyPending = 0;
        $failed = 0;
        $noAnsible = false;
        foreach ($targets as $targetId) {
            $result = esxi_inventory_enqueue_for_credential($connection, $targetId, (int) $user['id']);
            if (!empty($result['enqueued'])) {
                $enqueued++;
            } elseif (($result['reason'] ?? '') === 'no_ansible_credential') {
                // Global condition (missing/ambiguous Ansible SSH credential):
                // no other target can enqueue either.
                $noAnsible = true;
                break;
            } elseif (($result['reason'] ?? '') === 'already_pending') {
                $alreadyPending++;
            } else {
                $failed++;
            }
        }
        audit($connection, VIRTUSPHERE_LOG_CATEGORY_SETTINGS, 'requested ESXi inventory refresh (' . $enqueued . ' job(s), ' . $skippedPaused . ' paused skipped)', (int) $user['id']);
        if ($noAnsible) {
            flash_set('warning', __t('integrations.inv_refresh_no_ansible'));
        } else {
            $parts = [__t('integrations.inv_refresh_queued', ['count' => $enqueued])];
            if ($alreadyPending > 0) {
                $parts[] = __t('integrations.inv_refresh_already_pending', ['count' => $alreadyPending]);
            }
            if ($skippedPaused > 0) {
                $parts[] = __t('integrations.inv_refresh_skipped_paused', ['count' => $skippedPaused]);
            }
            if ($failed > 0) {
                $parts[] = __t('integrations.inv_refresh_failed', ['count' => $failed]);
            }
            flash_set($enqueued > 0 ? 'success' : 'warning', implode(' ', $parts));
        }
    } elseif ($inventoryAction === 'reassign_vlan') {
        if (!can('missions.write', $user) || !can('vms.write', $user)) {
            portal_forbid($connection, $user, 'missions.write+vms.write');
        }
        $vlanFrom = request_string($_POST, 'vlan_from');
        try {
            $result = repo_reassign_vlan($connection, $vlanFrom, request_string($_POST, 'vlan_to'));
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_MISSIONS, 'reassigned vlan "' . $vlanFrom . '" to "' . request_string($_POST, 'vlan_to') . '" (' . $result['missions'] . ' missions, ' . $result['interfaces'] . ' interfaces)', (int) $user['id']);
            // "From" is free text, so a typo is the likely reason for zero matches.
            // Reporting success with two zeros reads like the rename went through.
            if ($result['missions'] + $result['interfaces'] === 0) {
                flash_set('warning', __t('integrations.reassign_none_matched', ['from' => $vlanFrom]));
            } else {
                flash_set('success', __t('integrations.reassign_done', ['missions' => $result['missions'], 'interfaces' => $result['interfaces']]));
            }
        } catch (Throwable $exception) {
            flash_set('error', portal_error_message($exception));
        }
    }
    redirect_to('integrations.php');
}
