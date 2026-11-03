<?php

declare(strict_types=1);

require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../credentials.php';
require_once __DIR__ . '/../remote_execution_constants.php';
require_once __DIR__ . '/helpers.php';

function remote_activation_contract(array $row): ?string
{
    $state = (string) ($row['state'] ?? '');
    $contract = $row['contract_version'] ?? null;
    if (!in_array($state, VIRTUSPHERE_REMOTE_ACTIVATION_STATES, true)) {
        throw new RuntimeException('Unknown remote activation state.');
    }
    if ($state === VIRTUSPHERE_REMOTE_ACTIVATION_DISABLED) {
        if ($contract !== null) {
            throw new RuntimeException('Disabled remote activation must not carry an execution contract.');
        }
        return null;
    }
    if ($state === VIRTUSPHERE_REMOTE_ACTIVATION_LEGACY && $contract === VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY) {
        return VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY;
    }
    if (in_array($state, [VIRTUSPHERE_REMOTE_ACTIVATION_PILOT, VIRTUSPHERE_REMOTE_ACTIVATION_ENABLED, VIRTUSPHERE_REMOTE_ACTIVATION_ROLLBACK], true)
        && $contract === VIRTUSPHERE_EXECUTION_CONTRACT_REMOTE) {
        return VIRTUSPHERE_EXECUTION_CONTRACT_REMOTE;
    }
    throw new RuntimeException('Remote activation state and contract disagree.');
}

function repo_deploy_remote_mode_activations(mysqli $db, int $credentialId): array
{
    $stmt = $db->prepare('SELECT credential_ansible_id, mode, state, contract_version, host_preflight_fingerprint, fault_matrix_fingerprint, evidence_expires_at, changed_at, changed_by, optimistic_lock_at FROM deploy_remote_mode_activations WHERE credential_ansible_id = ? ORDER BY mode');
    $stmt->bind_param('i', $credentialId);
    $stmt->execute();
    $rows = repo_fetch_all($stmt->get_result());
    foreach ($rows as $row) {
        if (!in_array((string) $row['mode'], virtusphere_deploy_modes(), true)) {
            throw new RuntimeException('Remote activation contains an unknown deploy mode.');
        }
        remote_activation_contract($row);
    }
    return $rows;
}

function repo_materialize_disabled_remote_activations(mysqli $db, int $credentialId): void
{
    if ($credentialId <= 0) {
        throw new InvalidArgumentException('Ansible credential id is required.');
    }
    $stmt = $db->prepare("INSERT IGNORE INTO deploy_remote_mode_activations (credential_ansible_id, mode, state, contract_version) VALUES (?, ?, 'disabled', NULL)");
    foreach (virtusphere_deploy_modes() as $mode) {
        $stmt->bind_param('is', $credentialId, $mode);
        $stmt->execute();
    }
}

function repo_sync_disabled_remote_activations(mysqli $db, int $credentialId, string $credentialType): void
{
    if ($credentialType === VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE) {
        repo_materialize_disabled_remote_activations($db, $credentialId);
        return;
    }
    $active = (int) repo_scalar(
        $db,
        "SELECT COUNT(*) FROM deploy_remote_mode_activations WHERE credential_ansible_id = ? AND NOT (state = 'disabled' AND contract_version IS NULL)",
        'i',
        [$credentialId]
    );
    if ($active > 0) {
        throw new RuntimeException('Credential type cannot change while a remote activation is not disabled.');
    }
    repo_execute($db, 'DELETE FROM deploy_remote_mode_activations WHERE credential_ansible_id = ?', 'i', [$credentialId]);
}
