<?php

declare(strict_types=1);

require_once __DIR__ . '/../status.php';

function repo_record_vm_status_event(mysqli $db, int $vmId, string $lifecycleState, string $mecmSyncState, string $legacyStatus, ?string $note = null, ?int $userId = null): void
{
    try {
        $stmt = $db->prepare('INSERT INTO deploy_vm_status_events (vm_id, lifecycle_state, mecm_sync_state, legacy_status, note, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('issssi', $vmId, $lifecycleState, $mecmSyncState, $legacyStatus, $note, $userId);
        $stmt->execute();
    } catch (Throwable $exception) {
        $refId = function_exists('virtusphere_error_reference') ? virtusphere_error_reference() : 'no-ref';
        error_log(sprintf(
            '[status_events] ref=%s skipped vm_id=%d lifecycle=%s mecm=%s legacy=%s: %s',
            $refId,
            $vmId,
            $lifecycleState,
            $mecmSyncState,
            $legacyStatus,
            $exception->getMessage()
        ));
    }
}

/**
 * A state write that can only move a VM FORWARD in its lifecycle. The MECM sync
 * side of the state (mecm_sync_state, mecm_id) is always applied; only the
 * lifecycle and the legacy status are held back when the target would be a step
 * back.
 *
 * The case this exists for: the device-sync re-reports a ResourceID whenever a VM
 * re-enters its queue, and mecm_updateid.php wrote `os_installing` for it
 * unconditionally. A VM that had already reported `os_installed` therefore fell
 * visibly back to 4/5, and the operator watched a finished machine start
 * installing again. Storing the ResourceID is still right in that situation - it
 * is the same device - so the two halves of the write are separated instead of
 * dropping the whole call.
 *
 * Returns false only on a database failure. A held-back lifecycle is a success:
 * the caller asked for a state that is already surpassed.
 */
function repo_set_vm_state_forward(mysqli $db, int $vmId, string $lifecycleState, string $mecmSyncState, string $legacyStatus, ?int $updated, ?string $note = null, ?string $mecmId = null): bool
{
    // The legacy machine-API scripts include this module directly, without the
    // repo helpers; the current-state read below needs them.
    require_once __DIR__ . '/helpers.php';
    virtusphere_assert_lifecycle_state($lifecycleState);

    $current = repo_fetch_one($db, 'SELECT lifecycle_state, vm_status FROM deploy_vms WHERE id = ? LIMIT 1', 'i', [$vmId]);
    if ($current === null) {
        return false;
    }

    $currentLifecycle = (string) $current['lifecycle_state'];
    if (in_array($currentLifecycle, VIRTUSPHERE_LIFECYCLE_STATES, true)
        && virtusphere_lifecycle_rank($currentLifecycle) > virtusphere_lifecycle_rank($lifecycleState)
    ) {
        $lifecycleState = $currentLifecycle;
        $legacyStatus = (string) $current['vm_status'];
        $note = ($note ?? '') . ' (lifecycle kept at ' . $currentLifecycle . '; a later state is not overwritten)';
    }

    return repo_set_vm_state($db, $vmId, $lifecycleState, $mecmSyncState, $legacyStatus, $updated, $note, $mecmId);
}

function repo_set_vm_state(mysqli $db, int $vmId, string $lifecycleState, string $mecmSyncState, string $legacyStatus, ?int $updated, ?string $note = null, ?string $mecmId = null): bool
{
    virtusphere_assert_lifecycle_state($lifecycleState);
    virtusphere_assert_mecm_sync_state($mecmSyncState);

    if ($mecmId !== null) {
        $stmt = $db->prepare('UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, vm_status = ?, updated = ?, mecm_id = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('sssisi', $lifecycleState, $mecmSyncState, $legacyStatus, $updated, $mecmId, $vmId);
    } elseif ($updated !== null) {
        $stmt = $db->prepare('UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, vm_status = ?, updated = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('sssii', $lifecycleState, $mecmSyncState, $legacyStatus, $updated, $vmId);
    } else {
        $stmt = $db->prepare('UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, vm_status = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('sssi', $lifecycleState, $mecmSyncState, $legacyStatus, $vmId);
    }

    $ok = $stmt->execute();
    if ($ok) {
        repo_record_vm_status_event($db, $vmId, $lifecycleState, $mecmSyncState, $legacyStatus, $note);
    }

    return $ok;
}
