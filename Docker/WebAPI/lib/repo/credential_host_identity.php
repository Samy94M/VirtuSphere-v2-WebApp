<?php

declare(strict_types=1);

require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/../audit_events.php';
require_once __DIR__ . '/../ssh_transport_exceptions.php';

/** @param array{fingerprint:string,type:string} $observed */
function repo_verify_ansible_host_identity(mysqli $db, array $snapshot, array $observed): void
{
    $rejection = repo_transaction($db, static function () use ($db, $snapshot, $observed): ?SshHostIdentityRejected {
        $current = repo_fetch_one($db, 'SELECT * FROM deploy_credentials WHERE id = ? FOR UPDATE', 'i', [(int) ($snapshot['id'] ?? 0)]);
        if ($current === null || $current['type'] !== VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE
            || $current['type'] !== ($snapshot['type'] ?? '')
            || (string) $current['host'] !== (string) ($snapshot['host'] ?? '')
            || credential_ssh_port($current['port']) !== credential_ssh_port($snapshot['port'] ?? null)
            || (string) $current['username'] !== (string) ($snapshot['username'] ?? '')
        ) {
            throw new SshHostIdentityRejected('credential_changed', $observed);
        }
        $id = (int) $current['id'];
        // Observation is separate from the immutable trust anchor. Keep a
        // mismatch visible on both portal pages without changing the pin.
        $observation = $db->prepare('UPDATE deploy_credentials SET ansible_host_observed_fingerprint = ?, ansible_host_observed_type = ?, ansible_host_observed_at = NOW(), updated_at = updated_at WHERE id = ?');
        $observation->bind_param('ssi', $observed['fingerprint'], $observed['type'], $id);
        $observation->execute();
        $expected = (string) ($current['ansible_host_fingerprint'] ?? '');
        if ($expected === '') {
            if ((int) $current['ansible_host_accept_new'] !== 1) {
                return new SshHostIdentityRejected('unconfirmed', $observed);
            }
            $stmt = $db->prepare('UPDATE deploy_credentials SET ansible_host_fingerprint = ?, ansible_host_key_type = ?, ansible_host_first_seen_at = NOW(), ansible_host_accept_new = 0 WHERE id = ?');
            $id = (int) $current['id'];
            $stmt->bind_param('ssi', $observed['fingerprint'], $observed['type'], $id);
            $stmt->execute();
            // The audit is in the same transaction. If storage/audit fails, no
            // password may leave this connection and no provisional pin stays.
            audit_event_required($db, VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_CHANGED, 'credential', $id, VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
                ['action' => 'host_identity_provisional', 'changes' => $observed['type'] . ' ' . $observed['fingerprint']]);
            return null;
        }
        if (!hash_equals($expected, $observed['fingerprint'])
            || (string) $current['ansible_host_key_type'] !== $observed['type']
        ) {
            return new SshHostIdentityRejected('mismatch', $observed, $expected);
        }
        return null;
    });
    if ($rejection !== null) {
        throw $rejection;
    }
}

/** Explicit administrator write; the posted old pin fences two browser tabs. */
function repo_confirm_ansible_host_identity(mysqli $db, int $id, int $revision, string $oldFingerprint, string $fingerprint, string $keyType, int $userId): void
{
    $fingerprint = trim($fingerprint);
    $keyType = trim($keyType);
    $digest = str_starts_with($fingerprint, 'SHA256:') ? base64_decode(substr($fingerprint, 7) . '=', true) : false;
    if (preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/D', $fingerprint) !== 1
        || $digest === false || strlen($digest) !== 32
        || !hash_equals($fingerprint, 'SHA256:' . rtrim(base64_encode($digest), '='))
        || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9@._+-]{0,63}$/D', $keyType) !== 1
        || $userId <= 0
    ) {
        throw new ValidationException(['host_fingerprint' => validator_text('credentials.host_identity_invalid', 'Enter the SHA256 fingerprint and key type from the host key check.')]);
    }
    repo_transaction($db, static function () use ($db, $id, $revision, $oldFingerprint, $fingerprint, $keyType, $userId): void {
        // Keep the existing Job -> Credential lock order before the row lock.
        $snapshot = repo_credential($db, $id);
        if ($snapshot === null || $snapshot['type'] !== VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE) {
            throw new RuntimeException('Credential not found.');
        }
        repo_deploy_create_fence_credential_change($db, $id, $snapshot);
        $current = repo_fetch_one($db, 'SELECT * FROM deploy_credentials WHERE id = ? FOR UPDATE', 'i', [$id]);
        if ($current === null || $current['type'] !== VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE
            || (int) $current['config_revision'] !== $revision
            || !hash_equals((string) ($current['ansible_host_fingerprint'] ?? ''), $oldFingerprint)
        ) {
            throw new ValidationException([], validator_text('credentials.host_identity_changed', 'The credential or host identity changed. Reload and check the fingerprint again.'));
        }
        $changed = !hash_equals($oldFingerprint, $fingerprint) || (string) ($current['ansible_host_key_type'] ?? '') !== $keyType;
        $changeInt = $changed ? 1 : 0;
        $stmt = $db->prepare('UPDATE deploy_credentials SET ansible_host_fingerprint = ?, ansible_host_key_type = ?, ansible_host_first_seen_at = IF(? = 1, NOW(), COALESCE(ansible_host_first_seen_at, NOW())), ansible_host_confirmed_at = NOW(), ansible_host_confirmed_by = ?, ansible_host_accept_new = 0, config_revision = config_revision + ?, ansible_test_started_at = IF(? = 1, NULL, ansible_test_started_at) WHERE id = ?');
        $stmt->bind_param('ssiiiii', $fingerprint, $keyType, $changeInt, $userId, $changeInt, $changeInt, $id);
        $stmt->execute();
        if ($changed) {
            $clear = $db->prepare('DELETE FROM deploy_ansible_preflight_state WHERE credential_id = ?');
            $clear->bind_param('i', $id);
            $clear->execute();
        }
        audit_event_required($db, VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_CHANGED, 'credential', $id, VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
            ['action' => 'host_identity_confirmed', 'changes' => ($oldFingerprint === '' ? 'none' : $oldFingerprint) . ' -> ' . $keyType . ' ' . $fingerprint], $userId);
    });
}
