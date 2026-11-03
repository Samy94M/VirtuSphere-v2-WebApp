<?php

declare(strict_types=1);

const VIRTUSPHERE_EDIT_VERSION_INCREMENT_SQL = 'edit_version = edit_version + 1';

/** Caller owns the parent lock and the transaction containing its child write. */
function repo_advance_vm_edit_version(mysqli $db, int $vmId): void
{
    $stmt = $db->prepare('UPDATE deploy_vms SET ' . VIRTUSPHERE_EDIT_VERSION_INCREMENT_SQL . ', updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('i', $vmId);
    $stmt->execute();
}

/** Empty expectations are the intentional legacy opt-out, never a portal default. */
function repo_edit_version_matches(string $expected, mixed $current, bool $requireVersion): bool
{
    if ($expected === '') {
        return !$requireVersion;
    }
    return preg_match('/^[1-9][0-9]*$/D', $expected) === 1 && $expected === (string) $current;
}
