<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../deploy_remote_secret.php';

/*
 * K21-F: the read side of the ESXi access files that are still open on an
 * Ansible host. The rows are written by lib/deploy_remote_secret.php, the
 * acknowledgement that closes them is repo_remote_secret_acknowledge() in
 * lib/repo/deploy_remote_secret_fence.php. This module only reads.
 *
 * A row is open when no running lease holds it and no active job owns it (a
 * missing job row counts as inactive, as in the sweep). An open row is DUE for
 * the System status notice when it is
 *  - orphaned: its Ansible credential is gone,
 *  - moved: its target (host, effective port, pinned host key) no longer matches
 *    the credential, or
 *  - overdue: uploaded longer ago than VIRTUSPHERE_REMOTE_SECRET_NOTICE_AFTER_SECONDS.
 *
 * Rows are grouped per target (credential, host, port). A group is shown when
 * one of its rows is due, and it counts every open row of its target: the
 * acknowledgement closes all of them, so the count is what a confirmation writes.
 */

/** A file that is due for the System status notice after this many seconds (24 hours). */
const VIRTUSPHERE_REMOTE_SECRET_NOTICE_AFTER_SECONDS = 86400;

/** Reasons in the order the notice lists them: the ones that act at once first. */
const VIRTUSPHERE_REMOTE_SECRET_REASON_RANK = [
    'orphaned' => 0,
    'moved' => 1,
    'overdue' => 2,
];

/**
 * Every open row with its due reason (null when it is not due), in upload order.
 * Reads no secret: the rows carry the target and the upload time only.
 *
 * @return list<array{credential_id: ?int, credential_name: ?string, target_host: string, target_port: int, uploaded_at: string, reason: ?string}>
 */
function repo_remote_secret_open_rows(mysqli $db): array
{
    $active = VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES;
    $stmt = $db->prepare(
        'SELECT s.credential_ansible_id, s.target_host, s.target_port, s.target_fingerprint, s.uploaded_at,'
        . ' (s.uploaded_at < NOW(6) - INTERVAL ? SECOND) AS is_overdue'
        . ' FROM deploy_remote_secret_files s LEFT JOIN deploy_jobs j ON j.id = s.job_id'
        . ' WHERE (s.lease_until IS NULL OR s.lease_until <= NOW(6))'
        . ' AND (j.id IS NULL OR j.status NOT IN (' . implode(',', array_fill(0, count($active), '?')) . '))'
        . ' ORDER BY s.uploaded_at, s.id'
    );
    $params = array_merge([VIRTUSPHERE_REMOTE_SECRET_NOTICE_AFTER_SECONDS], $active);
    $stmt->bind_param('i' . str_repeat('s', count($active)), ...$params);
    $stmt->execute();

    // The current target of each credential, read once per credential (the same owner the writer uses).
    $current = [];
    $open = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $ansibleId = $row['credential_ansible_id'] === null ? null : (int) $row['credential_ansible_id'];
        $credentialTarget = null;
        $credentialName = null;
        if ($ansibleId !== null) {
            if (!array_key_exists($ansibleId, $current)) {
                $current[$ansibleId] = [
                    'target' => deploy_remote_secret_target($db, $ansibleId),
                    'name' => repo_remote_secret_credential_name($db, $ansibleId),
                ];
            }
            $credentialTarget = $current[$ansibleId]['target'];
            $credentialName = $current[$ansibleId]['name'];
        }

        $rowTarget = [
            'target_host' => (string) $row['target_host'],
            'target_port' => (int) $row['target_port'],
            'target_fingerprint' => $row['target_fingerprint'] === null ? null : (string) $row['target_fingerprint'],
        ];
        $reason = null;
        if ($credentialTarget === null) {
            $reason = 'orphaned';
        } elseif (!deploy_remote_secret_same_target($rowTarget, $credentialTarget)) {
            $reason = 'moved';
        } elseif ((int) $row['is_overdue'] === 1) {
            $reason = 'overdue';
        }

        $open[] = [
            'credential_id' => $credentialTarget === null ? null : $ansibleId,
            'credential_name' => $credentialTarget === null ? null : $credentialName,
            'target_host' => $rowTarget['target_host'],
            'target_port' => $rowTarget['target_port'],
            'uploaded_at' => (string) $row['uploaded_at'],
            'reason' => $reason,
        ];
    }

    return $open;
}

function repo_remote_secret_credential_name(mysqli $db, int $credentialId): ?string
{
    $name = repo_scalar($db, 'SELECT name FROM deploy_credentials WHERE id = ?', 'i', [$credentialId]);

    return $name === null ? null : (string) $name;
}

/**
 * Open rows grouped per target. The rank is the most urgent due reason of the
 * group, or null when no row of it is due.
 *
 * @param list<array{credential_id: ?int, credential_name: ?string, target_host: string, target_port: int, uploaded_at: string, reason: ?string}> $rows
 * @return list<array{credential_id: ?int, credential_name: ?string, target_host: string, target_port: int, count: int, oldest: string, rank: ?int}>
 */
function repo_remote_secret_target_groups(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $key = ($row['credential_id'] ?? 0) . '|' . strtolower($row['target_host']) . '|' . $row['target_port'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'credential_id' => $row['credential_id'],
                'credential_name' => $row['credential_name'],
                'target_host' => $row['target_host'],
                'target_port' => $row['target_port'],
                'count' => 0,
                'oldest' => $row['uploaded_at'],
                'rank' => null,
            ];
        }
        $groups[$key]['count']++;
        if ($row['uploaded_at'] < $groups[$key]['oldest']) {
            $groups[$key]['oldest'] = $row['uploaded_at'];
        }
        if ($row['reason'] !== null) {
            $rank = VIRTUSPHERE_REMOTE_SECRET_REASON_RANK[$row['reason']];
            $groups[$key]['rank'] = $groups[$key]['rank'] === null ? $rank : min($groups[$key]['rank'], $rank);
        }
    }

    return array_values($groups);
}

/**
 * The groups of the System status notice: the targets with at least one due row,
 * the orphaned ones first, then the moved, then the overdue, each by the oldest
 * finding, then host, port and credential. Bounded by the credentials and their
 * targets, so it is never cut: a cut list would hide the total.
 *
 * @return list<array{credential_id: ?int, credential_name: ?string, target_host: string, target_port: int, count: int, oldest: string, reason: string}>
 */
function repo_remote_secret_notice_groups(mysqli $db): array
{
    $due = array_filter(repo_remote_secret_target_groups(repo_remote_secret_open_rows($db)), static fn (array $group): bool => $group['rank'] !== null);
    $reasons = array_flip(VIRTUSPHERE_REMOTE_SECRET_REASON_RANK);
    usort($due, static function (array $a, array $b): int {
        return [$a['rank'], $a['oldest'], strtolower($a['target_host']), $a['target_port'], $a['credential_id'] ?? 0]
            <=> [$b['rank'], $b['oldest'], strtolower($b['target_host']), $b['target_port'], $b['credential_id'] ?? 0];
    });

    $groups = [];
    foreach ($due as $group) {
        $groups[] = [
            'credential_id' => $group['credential_id'],
            'credential_name' => $group['credential_name'],
            'target_host' => $group['target_host'],
            'target_port' => $group['target_port'],
            'count' => $group['count'],
            'oldest' => $group['oldest'],
            'reason' => (string) $reasons[$group['rank']],
        ];
    }

    return $groups;
}

/**
 * The open rows of every Ansible credential per target, for the credentials page.
 * Any age and any reason: the acknowledgement there closes every open row of the
 * target. Orphaned rows belong to no credential and are left out.
 *
 * @return array<int, list<array{target_host: string, target_port: int, count: int, oldest: string}>> credential id => its targets, oldest first
 */
function repo_remote_secret_open_by_credential(mysqli $db): array
{
    $byCredential = [];
    foreach (repo_remote_secret_target_groups(repo_remote_secret_open_rows($db)) as $group) {
        if ($group['credential_id'] === null) {
            continue;
        }
        $byCredential[(int) $group['credential_id']][] = [
            'target_host' => $group['target_host'],
            'target_port' => $group['target_port'],
            'count' => $group['count'],
            'oldest' => $group['oldest'],
        ];
    }
    foreach ($byCredential as $credentialId => $targets) {
        usort($targets, static fn (array $a, array $b): int => [$a['oldest'], strtolower($a['target_host']), $a['target_port']]
            <=> [$b['oldest'], strtolower($b['target_host']), $b['target_port']]);
        $byCredential[$credentialId] = $targets;
    }

    return $byCredential;
}
