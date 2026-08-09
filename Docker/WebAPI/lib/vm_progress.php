<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';

/**
 * Return the progress observation that is valid for the VM's current state.
 * Observations and their warnings are display-only; this module never writes
 * lifecycle state, deletes rows or turns elapsed time into a failure.
 *
 * @param array<string,mixed> $vm
 */
function virtusphere_vm_progress_watch_kind(array $vm): ?string
{
    $lifecycle = (string) ($vm['lifecycle_state'] ?? '');
    $mecm = (string) ($vm['mecm_sync_state'] ?? '');

    if ($lifecycle === VIRTUSPHERE_LIFECYCLE_DEPLOYED && $mecm === VIRTUSPHERE_MECM_SYNC_PENDING) {
        return VIRTUSPHERE_VM_PROGRESS_MECM_PENDING;
    }
    if ($lifecycle === VIRTUSPHERE_LIFECYCLE_OS_INSTALLING && $mecm === VIRTUSPHERE_MECM_SYNC_REGISTERED) {
        return VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING;
    }

    return null;
}

/**
 * Derive an overdue warning from a dedicated server-side timestamp.
 *
 * @param array<string,mixed> $vm
 * @return array{kind:string,since:string,age_seconds:int,threshold_seconds:int}|null
 */
function virtusphere_vm_progress_attention(array $vm, ?int $now = null): ?array
{
    $kind = virtusphere_vm_progress_watch_kind($vm);
    if ($kind === null) {
        return null;
    }

    $field = $kind === VIRTUSPHERE_VM_PROGRESS_MECM_PENDING
        ? 'mecm_pending_since'
        : 'os_install_watch_started_at';
    $threshold = $kind === VIRTUSPHERE_VM_PROGRESS_MECM_PENDING
        ? VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS
        : VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS;
    $since = trim((string) ($vm[$field] ?? ''));
    if ($since === '') {
        return null;
    }

    $parsed = strtotime($since . ' UTC');
    if ($parsed === false) {
        return null;
    }
    $age = ($now ?? time()) - $parsed;
    if ($age <= $threshold) {
        return null;
    }

    return [
        'kind' => $kind,
        'since' => $since,
        'age_seconds' => $age,
        'threshold_seconds' => $threshold,
    ];
}
