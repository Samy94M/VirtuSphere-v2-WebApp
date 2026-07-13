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
