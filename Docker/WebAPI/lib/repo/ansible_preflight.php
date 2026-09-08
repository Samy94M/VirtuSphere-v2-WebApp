<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/helpers.php';

/**
 * On-demand Ansible preflight result (migration 0023). A row is written only
 * when an operator tests an Ansible credential; there is no scheduler, so a
 * green row can age. The shared ampel applies its explicit freshness window;
 * revision and test-generation evidence below additionally prevent an older
 * concurrent result from proving a newer credential configuration.
 */

// The three last_status values (plain VARCHAR, no ENUM mirror owed). 'warning'
// means the chain itself was green but a deploy would still lose something,
// today: the host's IP is missing from the machine-API allowlist.
const VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK = 'ok';
const VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_WARNING = 'warning';
const VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_FAILED = 'failed';

/**
 * Records the outcome of one preflight test, replacing any prior row for the
 * credential. On plain success last_component is NULL; otherwise it names the
 * component that broke the chain (a tool token or 'portal') or, for a warning,
 * the check that raised it ('allowlist').
 */
function repo_ansible_preflight_begin(mysqli $db, int $credentialId, int $configRevision): int
{
    return repo_transaction($db, static function () use ($db, $credentialId, $configRevision): int {
        $credential = repo_fetch_one(
            $db,
            'SELECT type, config_revision, ansible_test_generation FROM deploy_credentials WHERE id = ? FOR UPDATE',
            'i',
            [$credentialId]
        );
        if ($credential === null
            || (string) $credential['type'] !== VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE
            || (int) $credential['config_revision'] !== $configRevision
        ) {
            throw new RuntimeException('Ansible credential changed before its test started.');
        }
        $generation = (int) $credential['ansible_test_generation'] + 1;
        repo_execute(
            $db,
            'UPDATE deploy_credentials SET ansible_test_generation = ? WHERE id = ? AND config_revision = ?',
            'iii',
            [$generation, $credentialId, $configRevision]
        );

        return $generation;
    });
}

function repo_ansible_preflight_record(mysqli $db, int $credentialId, string $status, ?string $component, int $configRevision, int $testGeneration): bool
{
    if (!in_array($status, [VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK, VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_WARNING, VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_FAILED], true)) {
        throw new InvalidArgumentException('Unknown preflight status: ' . $status);
    }
    $failedComponent = ($status === VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK || $component === null || $component === '') ? null : $component;
    return repo_transaction($db, static function () use ($db, $credentialId, $status, $failedComponent, $configRevision, $testGeneration): bool {
        $credential = repo_fetch_one(
            $db,
            'SELECT type, config_revision, ansible_test_generation FROM deploy_credentials WHERE id = ? FOR UPDATE',
            'i',
            [$credentialId]
        );
        if ($credential === null
            || (string) $credential['type'] !== VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE
            || (int) $credential['config_revision'] !== $configRevision
            || (int) $credential['ansible_test_generation'] !== $testGeneration
        ) {
            return false;
        }
        $stmt = $db->prepare(
            'INSERT INTO deploy_ansible_preflight_state (credential_id, last_status, last_checked_at, last_component, tested_config_revision, test_generation)
             VALUES (?, ?, NOW(), ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_status = ?, last_checked_at = NOW(), last_component = ?, tested_config_revision = ?, test_generation = ?'
        );
        $stmt->bind_param('issiissii', $credentialId, $status, $failedComponent, $configRevision, $testGeneration, $status, $failedComponent, $configRevision, $testGeneration);
        $stmt->execute();

        return true;
    });
}

/** @return array<string, mixed>|null */
function repo_ansible_preflight_state(mysqli $db, int $credentialId): ?array
{
    $row = repo_fetch_one($db, 'SELECT s.*, c.config_revision AS current_config_revision, c.ansible_test_generation AS current_test_generation FROM deploy_ansible_preflight_state s INNER JOIN deploy_credentials c ON c.id = s.credential_id WHERE s.credential_id = ? LIMIT 1', 'i', [$credentialId]);
    if ($row !== null) {
        $row['evidence_current'] = $row['tested_config_revision'] !== null
            && $row['test_generation'] !== null
            && (int) $row['tested_config_revision'] === (int) $row['current_config_revision']
            && (int) $row['test_generation'] === (int) $row['current_test_generation'];
    }

    return $row;
}

/** @return array<int, array<string,mixed>> */
function repo_ansible_preflight_states(mysqli $db): array
{
    $stmt = $db->prepare('SELECT s.*, c.config_revision AS current_config_revision, c.ansible_test_generation AS current_test_generation FROM deploy_ansible_preflight_state s INNER JOIN deploy_credentials c ON c.id = s.credential_id');
    $stmt->execute();

    $states = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $row['evidence_current'] = $row['tested_config_revision'] !== null
            && $row['test_generation'] !== null
            && (int) $row['tested_config_revision'] === (int) $row['current_config_revision']
            && (int) $row['test_generation'] === (int) $row['current_test_generation'];
        $states[(int) $row['credential_id']] = $row;
    }

    return $states;
}

/**
 * Drops the stored result when the credential is edited. The old result proved
 * the OLD host/account: keeping a green badge across a host change would claim
 * something no test has shown. Back to "not tested", which is the honest state.
 * Safe for any credential id; a row only exists for previously tested Ansible
 * credentials (also cleans up after a type change away from ansible).
 *
 * @return bool Whether a stored result actually existed, so the caller's audit
 *              line can mention the reset only when something was reset.
 */
function repo_ansible_preflight_clear(mysqli $db, int $credentialId): bool
{
    // Own statement instead of repo_execute: the row count must come from the
    // statement itself ($db->affected_rows is not reliable after a prepared
    // execute and reported 0 here even when the DELETE removed a row).
    $stmt = $db->prepare('DELETE FROM deploy_ansible_preflight_state WHERE credential_id = ?');
    $stmt->bind_param('i', $credentialId);
    $stmt->execute();

    return $stmt->affected_rows > 0;
}
