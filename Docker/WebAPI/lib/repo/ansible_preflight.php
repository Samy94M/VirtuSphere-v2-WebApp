<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/helpers.php';

/**
 * On-demand Ansible preflight result (migration 0023). A row is written only
 * when an operator tests an Ansible credential; there is no scheduler, so a
 * green row can be arbitrarily old. The display shows last_checked_at verbatim
 * and leaves the staleness judgement to the reader, unlike the ESXi inventory
 * ampel which has a freshness window because a scheduler keeps it current.
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
function repo_ansible_preflight_record(mysqli $db, int $credentialId, string $status, ?string $component): void
{
    if (!in_array($status, [VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK, VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_WARNING, VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_FAILED], true)) {
        throw new InvalidArgumentException('Unknown preflight status: ' . $status);
    }
    $failedComponent = ($status === VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK || $component === null || $component === '') ? null : $component;
    $stmt = $db->prepare(
        'INSERT INTO deploy_ansible_preflight_state (credential_id, last_status, last_checked_at, last_component)
         VALUES (?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE last_status = ?, last_checked_at = NOW(), last_component = ?'
    );
    $stmt->bind_param('issss', $credentialId, $status, $failedComponent, $status, $failedComponent);
    $stmt->execute();
}

/** @return array<string, mixed>|null */
function repo_ansible_preflight_state(mysqli $db, int $credentialId): ?array
{
    return repo_fetch_one($db, 'SELECT * FROM deploy_ansible_preflight_state WHERE credential_id = ? LIMIT 1', 'i', [$credentialId]);
}

/** @return array<int, array<string,mixed>> */
function repo_ansible_preflight_states(mysqli $db): array
{
    $stmt = $db->prepare('SELECT * FROM deploy_ansible_preflight_state');
    $stmt->execute();

    $states = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
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
