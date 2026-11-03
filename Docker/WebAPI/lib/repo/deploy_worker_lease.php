<?php

declare(strict_types=1);

require_once __DIR__ . '/../remote_execution_constants.php';
require_once __DIR__ . '/helpers.php';

/** @return array{lease_name:string,epoch:int,owner_token:?string,lease_until:?string,renewed_at:?string,claims_paused:bool,pause_reason:?string} */
function repo_deploy_worker_lease_snapshot(mysqli $db): array
{
    $row = repo_fetch_one(
        $db,
        'SELECT lease_name, epoch, owner_token, lease_until, renewed_at, claims_paused, pause_reason FROM deploy_worker_leases WHERE lease_name = ? LIMIT 1',
        's',
        [VIRTUSPHERE_DEPLOY_WORKER_LEASE_NAME]
    );
    if ($row === null) {
        throw new RuntimeException('Deploy worker lease singleton is missing.');
    }

    return [
        'lease_name' => (string) $row['lease_name'],
        'epoch' => (int) $row['epoch'],
        'owner_token' => $row['owner_token'] === null ? null : (string) $row['owner_token'],
        'lease_until' => $row['lease_until'] === null ? null : (string) $row['lease_until'],
        'renewed_at' => $row['renewed_at'] === null ? null : (string) $row['renewed_at'],
        'claims_paused' => (int) $row['claims_paused'] === 1,
        'pause_reason' => $row['pause_reason'] === null ? null : (string) $row['pause_reason'],
    ];
}
