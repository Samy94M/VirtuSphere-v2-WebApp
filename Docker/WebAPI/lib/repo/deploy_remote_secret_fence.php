<?php

declare(strict_types=1);

require_once __DIR__ . '/../audit_event_definitions.php';
require_once __DIR__ . '/../credentials.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../network_mac_constants.php';
require_once __DIR__ . '/../validate.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/log.php';

/*
 * The fence of the ESXi access file on its Ansible credential (K21-F).
 *
 * A row of deploy_remote_secret_files says that accounts.yml may still be on the
 * host the credential reaches. The contact that removes it reaches that host only
 * through this credential. So while any row of the credential exists, the
 * credential cannot be deleted, and its target (type, host, effective SSH port,
 * pinned host key) cannot change. Every row counts: a live lease, an active job or
 * a row of a finished job alike. A row of another credential does not block this
 * one.
 *
 * Writers call repo_remote_secret_fence_credential_change() in their own
 * transaction, right after repo_deploy_create_fence_credential_change(). The
 * static contract test derives the callers and fails on a writer without the call.
 *
 * repo_remote_secret_acknowledge() is the one logged way out. An operator
 * establishes by hand that the file is gone, or that the host is out of service.
 */

/** The reason of an acknowledgement, after trimming, has at least this many characters. */
const VIRTUSPHERE_REMOTE_SECRET_REASON_MIN_CHARS = 10;

/** The audit context field "reason" keeps at most this many bytes (audit_registry.php). */
const VIRTUSPHERE_REMOTE_SECRET_REASON_MAX_BYTES = 256;

/**
 * The open rows of one Ansible credential, as the fence sees them. Must run while
 * the credential row is locked by the caller, so a concurrent writer of a row
 * waits for the decision.
 *
 * @return array{files: int, host: string, port: int, oldest: string}|null null when no row is open
 */
function repo_remote_secret_fence_open(mysqli $db, int $credentialId): ?array
{
    $count = repo_fetch_one(
        $db,
        'SELECT COUNT(*) AS files FROM deploy_remote_secret_files WHERE credential_ansible_id = ? FOR UPDATE',
        'i',
        [$credentialId]
    );
    $files = (int) ($count['files'] ?? 0);
    if ($files === 0) {
        return null;
    }
    $oldest = repo_fetch_one(
        $db,
        'SELECT target_host, target_port, uploaded_at FROM deploy_remote_secret_files WHERE credential_ansible_id = ?'
            . ' ORDER BY uploaded_at, id LIMIT 1 FOR UPDATE',
        'i',
        [$credentialId]
    );
    if ($oldest === null) {
        throw new RuntimeException('The open ESXi access file records of credential ' . $credentialId . ' changed while they were locked.');
    }

    return [
        'files' => $files,
        'host' => (string) $oldest['target_host'],
        'port' => (int) $oldest['target_port'],
        'oldest' => repo_remote_secret_utc_iso((string) $oldest['uploaded_at']),
    ];
}

/**
 * A row time as ISO 8601 in UTC, the form of the audit field "oldest". The rows
 * are written in UTC, as the sweep's diagnostic already states.
 */
function repo_remote_secret_utc_iso(string $databaseTime): string
{
    return (new DateTimeImmutable($databaseTime, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

/**
 * Whether the replacement keeps the target of the credential. The comparisons are
 * the ones of the identity reset in repo_update_credential(): a change that resets
 * the pinned key is a target change, so it is fenced here too. A key that the
 * replacement does not carry is not compared, and neither is a field it omits.
 *
 * @param array<string, mixed> $current the credential row, locked
 * @param array<string, mixed> $replacement the credential after the change
 */
function repo_remote_secret_fence_same_target(array $current, array $replacement): bool
{
    $type = array_key_exists('type', $replacement) ? (string) $replacement['type'] : (string) $current['type'];
    $host = array_key_exists('host', $replacement) ? (string) $replacement['host'] : (string) $current['host'];
    $port = array_key_exists('port', $replacement) ? $replacement['port'] : $current['port'];
    if ($type !== (string) $current['type']
        || $host !== (string) $current['host']
        || credential_ssh_port($port) !== credential_ssh_port($current['port'])
    ) {
        return false;
    }
    if (array_key_exists('ansible_host_fingerprint', $replacement)) {
        $pinned = $current['ansible_host_fingerprint'] === null ? null : (string) $current['ansible_host_fingerprint'];
        $new = $replacement['ansible_host_fingerprint'] === null ? null : (string) $replacement['ansible_host_fingerprint'];
        if ($pinned !== $new) {
            return false;
        }
    }

    return true;
}

/**
 * Refuses a change of the target of a credential while any row of it is open.
 * $replacement is the credential after the change; null means it is deleted.
 * Must run inside the caller's transaction, after
 * repo_deploy_create_fence_credential_change(). The refusal is a ValidationException
 * with an action to the credential's row on the credentials page.
 *
 * @param array<string, mixed>|null $replacement
 * @throws ValidationException
 */
function repo_remote_secret_fence_credential_change(mysqli $db, int $id, ?array $replacement): void
{
    // Lock sequence: the jobs (create fence), the credential, then its rows.
    $current = repo_fetch_one(
        $db,
        'SELECT type, host, port, ansible_host_fingerprint FROM deploy_credentials WHERE id = ? FOR UPDATE',
        'i',
        [$id]
    );
    $open = repo_remote_secret_fence_open($db, $id);
    if ($open === null) {
        return;
    }
    if ($replacement !== null && $current !== null && repo_remote_secret_fence_same_target($current, $replacement)) {
        return;
    }

    $message = validator_text(
        'credentials.remote_secret_fence',
        'The Ansible host :host:port still holds :count ESXi access file record(s) whose removal is not confirmed, the oldest since :oldest. '
            . 'Until they are removed or acknowledged by hand, this credential cannot be deleted or pointed at another host, port or host key.',
        ['count' => $open['files'], 'host' => $open['host'], 'port' => $open['port'], 'oldest' => $open['oldest']]
    );
    throw new ValidationException([], $message, [
        'url' => 'credentials.php#remote-secret-' . $id,
        'label_key' => 'credentials.remote_secret_open_action',
        'permission' => 'credentials.manage',
    ]);
}

/**
 * The operator's acknowledgement: closes every open row of one Ansible credential
 * (or of a credential that was deleted, $credentialId null) on one target, host and
 * port, except rows under a live lease and rows of an active job. The pinned key is
 * not part of the match. One audit row says how many were closed, in the same
 * transaction. Returns that number; 0 closes nothing and writes no audit row.
 *
 * @throws ValidationException when the reason is shorter than the minimum after trimming
 */
function repo_remote_secret_acknowledge(mysqli $db, ?int $credentialId, string $targetHost, int $targetPort, string $reason, int $userId): int
{
    $targetHost = trim($targetHost);
    if ($targetHost === '' || $targetPort < 1 || $targetPort > 65535 || $userId <= 0) {
        throw new InvalidArgumentException('The acknowledged target and the operator are required.');
    }
    $reason = trim($reason);
    if (mb_strlen($reason) < VIRTUSPHERE_REMOTE_SECRET_REASON_MIN_CHARS) {
        $message = validator_text(
            'credentials.remote_secret_reason_required',
            'Enter a reason of at least :min characters, for example: file removed by hand, or host out of service.',
            ['min' => VIRTUSPHERE_REMOTE_SECRET_REASON_MIN_CHARS]
        );
        throw new ValidationException(['reason' => $message], $message);
    }
    $reason = virtusphere_bounded_utf8_bytes($reason, VIRTUSPHERE_REMOTE_SECRET_REASON_MAX_BYTES);

    return repo_transaction($db, static function () use ($db, $credentialId, $targetHost, $targetPort, $reason, $userId): int {
        // Lock sequence of the fence: the jobs of the credential, the credential, then its rows.
        if ($credentialId !== null) {
            $jobs = $db->prepare('SELECT id FROM deploy_jobs WHERE credential_ansible_id = ? ORDER BY id FOR UPDATE');
            $jobs->bind_param('i', $credentialId);
            $jobs->execute();
            repo_fetch_all($jobs->get_result());
            repo_fetch_one($db, 'SELECT id FROM deploy_credentials WHERE id = ? FOR UPDATE', 'i', [$credentialId]);
        }

        $active = VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES;
        $placeholders = implode(',', array_fill(0, count($active), '?'));
        $select = $db->prepare(
            'SELECT s.id, s.uploaded_at FROM deploy_remote_secret_files s LEFT JOIN deploy_jobs j ON j.id = s.job_id'
            . ' WHERE s.credential_ansible_id <=> ? AND s.target_host = ? AND s.target_port = ?'
            . ' AND (s.lease_until IS NULL OR s.lease_until <= NOW(6))'
            . ' AND (j.id IS NULL OR j.status NOT IN (' . $placeholders . '))'
            . ' ORDER BY s.uploaded_at, s.id FOR UPDATE OF s'
        );
        $params = [$credentialId, $targetHost, $targetPort, ...$active];
        $select->bind_param('isi' . str_repeat('s', count($active)), ...$params);
        $select->execute();
        $rows = repo_fetch_all($select->get_result());
        $count = count($rows);
        if ($count === 0) {
            return 0;
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        repo_execute(
            $db,
            'DELETE FROM deploy_remote_secret_files WHERE id IN (' . implode(',', array_fill(0, $count, '?')) . ')',
            str_repeat('i', $count),
            $ids
        );
        // The audit row is part of the closing: if it cannot be written, the rows stay open.
        audit_event_required(
            $db,
            VIRTUSPHERE_AUDIT_EVENT_DEPLOY_REMOTE_SECRET_ACKNOWLEDGED,
            'credential',
            $credentialId ?? 0,
            VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
            [
                'count' => $count,
                'host' => mb_substr($targetHost, 0, 253),
                'port' => $targetPort,
                'oldest' => repo_remote_secret_utc_iso((string) $rows[0]['uploaded_at']),
                'reason' => $reason,
                'credential_id' => $credentialId ?? 0,
            ],
            $userId
        );

        return $count;
    });
}
