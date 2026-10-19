<?php

declare(strict_types=1);

require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/../audit_events.php';
require_once __DIR__ . '/../ssh_transport_exceptions.php';
require_once __DIR__ . '/../ssh_host_key_format.php';

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

/**
 * Explicit administrator write; the posted old pin fences two browser tabs.
 *
 * $fingerprint is the SHA256 fingerprint or the whole `ssh-keygen -l` line. The
 * key type comes from that line or from the observed or pinned key the value
 * matches; $keyType (the form's select) names it only for a key nobody has
 * observed. A value that deviates from the pin, or from the observation while
 * nothing is pinned, is written only with $replaceDeviation: that is the second,
 * explicit step after the warning. Returns true when that step replaced a pin.
 */
function repo_confirm_ansible_host_identity(mysqli $db, int $id, int $revision, string $oldFingerprint, string $fingerprint, string $keyType, int $userId, bool $replaceDeviation = false): bool
{
    $parsed = ssh_host_key_parse_input($fingerprint);
    if ($parsed === null || $userId <= 0) {
        throw new ValidationException(['host_fingerprint' => validator_text('credentials.host_identity_invalid', 'Enter the SHA256 fingerprint or the ssh-keygen line from the host key check.')]);
    }
    $replaced = false;
    repo_transaction($db, static function () use ($db, $id, $revision, $oldFingerprint, $fingerprint, $keyType, $userId, $replaceDeviation, $parsed, &$replaced): void {
        // Keep the existing Job -> Credential lock order before the row lock.
        $snapshot = repo_credential($db, $id);
        if ($snapshot === null || $snapshot['type'] !== VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE) {
            throw new RuntimeException('Credential not found.');
        }
        repo_deploy_create_fence_credential_change($db, $id, $snapshot);
        // Any change of the pinned key is a target change: refused while rows of this credential are open.
        repo_remote_secret_fence_credential_change($db, $id, array_replace($snapshot, ['ansible_host_fingerprint' => $parsed['fingerprint']]));
        $current = repo_fetch_one($db, 'SELECT * FROM deploy_credentials WHERE id = ? FOR UPDATE', 'i', [$id]);
        if ($current === null || $current['type'] !== VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE
            || (int) $current['config_revision'] !== $revision
            || !hash_equals((string) ($current['ansible_host_fingerprint'] ?? ''), $oldFingerprint)
        ) {
            throw new ValidationException([], validator_text('credentials.host_identity_changed', 'The credential or host identity changed. Reload and check the fingerprint again.'));
        }
        // The decision reads the row under its lock, so the page's warning and the write see the same anchor.
        $observedPair = repo_host_identity_pair($current, 'ansible_host_observed_fingerprint', 'ansible_host_observed_type');
        $pinnedPair = repo_host_identity_pair($current, 'ansible_host_fingerprint', 'ansible_host_key_type');
        $plan = ssh_host_key_confirmation_plan($fingerprint, $keyType, $observedPair, $pinnedPair);
        if (!$plan['ok']) {
            throw repo_host_identity_plan_rejection($plan['error']);
        }
        if ($plan['deviates'] && !$replaceDeviation) {
            // The second step: nothing is written. The page shows both values and asks once more.
            throw new ValidationException(['host_identity_deviation' => '1'], validator_text('credentials.host_identity_deviation', 'The entered host key differs and was not saved. Check it and confirm the difference explicitly.'));
        }
        $fingerprint = $plan['fingerprint'];
        $keyType = $plan['type'];
        $replaced = $plan['deviates'] && $pinnedPair !== null;
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
            ['action' => $replaced ? 'host_identity_replaced' : 'host_identity_confirmed', 'changes' => ($oldFingerprint === '' ? 'none' : $oldFingerprint) . ' -> ' . $keyType . ' ' . $fingerprint], $userId);
    });

    return $replaced;
}

/**
 * The identity a pair of columns holds, or null when no fingerprint is stored.
 *
 * @return array{fingerprint: string, type: string}|null
 */
function repo_host_identity_pair(array $row, string $fingerprintColumn, string $typeColumn): ?array
{
    $fingerprint = (string) ($row[$fingerprintColumn] ?? '');

    return $fingerprint === '' ? null : ['fingerprint' => $fingerprint, 'type' => (string) ($row[$typeColumn] ?? '')];
}

/** The field-level refusal of one plan error; the message names what to correct. */
function repo_host_identity_plan_rejection(string $error): ValidationException
{
    return match ($error) {
        'type_conflict' => new ValidationException(['host_key_type' => validator_text('credentials.host_identity_type_conflict', 'The key type does not match the entered fingerprint. Leave the field empty or paste the whole ssh-keygen line.')]),
        'type_unknown' => new ValidationException(['host_key_type' => validator_text('credentials.host_identity_type_unknown', 'Choose one of the offered key types.')]),
        'type_needed' => new ValidationException(['host_key_type' => validator_text('credentials.host_identity_type_needed', 'Choose the key type the host shows for this fingerprint, or paste the whole ssh-keygen line.')]),
        'needs_line' => new ValidationException(['host_fingerprint' => validator_text('credentials.host_identity_needs_line', 'This value matches neither the observed nor the pinned key. Paste the whole ssh-keygen line; it names the key type.')]),
        default => new ValidationException(['host_fingerprint' => validator_text('credentials.host_identity_invalid', 'Enter the SHA256 fingerprint or the ssh-keygen line from the host key check.')]),
    };
}
