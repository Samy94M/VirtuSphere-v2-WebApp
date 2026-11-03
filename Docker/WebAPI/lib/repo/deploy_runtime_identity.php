<?php

declare(strict_types=1);

require_once __DIR__ . '/../remote_execution_constants.php';
require_once __DIR__ . '/helpers.php';

/** @return array{id:int,current_generation_id:string,supervisor_contract:string,created_at:string,rotation_reason:string,rotated_by:?int} */
function repo_deploy_runtime_identity(mysqli $db): array
{
    $row = repo_fetch_one(
        $db,
        'SELECT id, LOWER(HEX(current_generation_id)) AS current_generation_id, supervisor_contract, created_at, rotation_reason, rotated_by FROM deploy_runtime_identity WHERE id = 1 LIMIT 1'
    );
    if ($row === null || preg_match('/^[a-f0-9]{32}$/', (string) ($row['current_generation_id'] ?? '')) !== 1) {
        throw new RuntimeException('Deploy runtime identity is missing or malformed.');
    }
    if (!in_array((string) $row['supervisor_contract'], VIRTUSPHERE_SUPERVISOR_CONTRACTS, true)
        || !in_array((string) $row['rotation_reason'], VIRTUSPHERE_RUNTIME_ROTATION_REASONS, true)) {
        throw new RuntimeException('Deploy runtime identity contains an unknown contract value.');
    }

    return [
        'id' => (int) $row['id'],
        'current_generation_id' => (string) $row['current_generation_id'],
        'supervisor_contract' => (string) $row['supervisor_contract'],
        'created_at' => (string) $row['created_at'],
        'rotation_reason' => (string) $row['rotation_reason'],
        'rotated_by' => $row['rotated_by'] === null ? null : (int) $row['rotated_by'],
    ];
}
