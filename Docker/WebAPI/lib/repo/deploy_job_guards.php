<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../credentials.php';
require_once __DIR__ . '/../defaults.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/helpers.php';

/**
 * Preconditions for a deploy write: the locking reads and assertions every
 * enqueue, retry and destructive mission change takes before it writes.
 *
 * These are reads, but not the reads of deploy_job_queries.php: they run inside
 * a transaction and take row locks, and their lock order (mission row first,
 * deploy_jobs second) is a contract of its own - a path that checked jobs first
 * would deadlock against an enqueue instead of queueing behind it. Mixing them
 * with display reads is how that ordering gets lost.
 */

/**
 * The one-active-job-per-mission guard, as a LOCKING read on purpose.
 *
 * Both enqueue paths call this inside repo_transaction() after locking the
 * mission row. Under REPEATABLE READ a plain COUNT would read the transaction
 * snapshot, and that snapshot was pinned by the first plain read of the
 * enqueue, i.e. before this transaction started waiting on the mission lock.
 * The second of two overlapping enqueues therefore counted against a state in
 * which the first one's job did not exist yet, and both inserted: two active
 * jobs on one mission, which one worker then runs back to back (the second
 * deploy re-runs against freshly created VMs). A locking read always reads the
 * latest committed rows, so behind the mission lock it is race-free. Proven
 * live before the fix; pinned by DeployEnqueueRaceTest.
 */
function repo_deploy_active_job_exists(mysqli $db, int $missionId): bool
{
    // The active set is the SSoT constant (queued/running/cancelling): a
    // cancelling job's playbook may still be executing, so it protects its
    // mission exactly like a running one until the worker confirms (ADR-0033).
    $active = VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES;
    $placeholders = implode(', ', array_fill(0, count($active), '?'));

    return repo_fetch_one(
        $db,
        'SELECT id FROM deploy_jobs WHERE mission_id = ? AND status IN (' . $placeholders . ') AND cancelled_at IS NULL LIMIT 1 FOR UPDATE',
        'i' . str_repeat('s', count($active)),
        array_merge([$missionId], $active)
    ) !== null;
}

/**
 * Locks one mission row for a change that must not race a deploy, and returns
 * it (null when it is gone, which is the caller's decision to interpret).
 *
 * The lock order matters and is fixed: mission row first, deploy_jobs second,
 * exactly as both enqueue paths take it. A destructive path that checked the
 * jobs first would deadlock against an enqueue instead of queueing behind it.
 * Only meaningful inside repo_transaction(): FOR UPDATE outside a transaction
 * releases immediately and proves nothing.
 *
 * @return array<string, mixed>|null
 */
function repo_deploy_lock_mission(mysqli $db, int $missionId): ?array
{
    return repo_fetch_one($db, 'SELECT id, mission_name FROM deploy_missions WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$missionId]);
}

/**
 * Refuses a destructive change while a job of this mission is queued or
 * running. The guard repo_delete_credential() and the bulk VM delete already
 * had, and the two paths that could delete the very state a running deploy is
 * working on did not: deleting a mission cascaded its jobs, their logs and its
 * VM rows out from under the worker, and the single-VM delete of the same page
 * as the bulk delete simply lacked its sibling's check.
 *
 * Hard refusal on purpose (no implicit cancel): cancelling somebody else's
 * running deploy is a separate decision with its own confirmation, and the two
 * sibling guards answer the same situation the same way.
 *
 * Call after repo_deploy_lock_mission() and inside the same transaction.
 */
function repo_deploy_assert_mission_idle(mysqli $db, int $missionId): void
{
    if (repo_deploy_active_job_exists($db, $missionId)) {
        throw new RuntimeException('Mission has an active deploy job.');
    }
}

function repo_deploy_assert_user_exists(mysqli $db, int $userId): void
{
    $row = repo_fetch_one($db, 'SELECT id FROM deploy_users WHERE id = ? LIMIT 1', 'i', [$userId]);
    if ($row === null) {
        throw new RuntimeException('Deploy user not found.');
    }
}

/**
 * Operator-facing readiness gate. An empty mission datacenter is allowed when the
 * chosen ESXi credential reports exactly one; the worker then resolves it from
 * the inventory cache. Passing 0 as the credential id keeps the old, strict
 * behaviour for any caller that does not know its deploy target.
 *
 * The datastore stays mandatory: a host almost always has several, and
 * vmware_guest has no default for it (unlike `datacenter`, which defaults to
 * `ha-datacenter`).
 */
/**
 * Enqueue-time gate. The location requirements only apply to modes that read a
 * location: the autostart playbook writes the host's boot configuration and
 * reads neither `datacenter_name` nor `datastore_name`, so refusing it because
 * the mission has no datastore would answer a question nobody asked.
 *
 * Template, mission existence and "has VMs" hold for every mode.
 */
function repo_deploy_assert_mission_ready(mysqli $db, array $mission, int $esxiCredentialId = 0, string $mode = VIRTUSPHERE_DEPLOY_MODE_FULL): void
{
    $missionId = (int) ($mission['id'] ?? 0);
    $missionName = trim((string) ($mission['mission_name'] ?? ''));
    if ($missionId <= 0 || $missionName === '') {
        throw new RuntimeException('Mission not found.');
    }
    if (mission_name_is_template($missionName)) {
        throw new RuntimeException('Templates cannot be deployed directly.');
    }
    if (virtusphere_deploy_mode_needs_location($mode)) {
        if (trim((string) ($mission['hypervisor_datacenter'] ?? '')) === ''
            && repo_esxi_sole_datacenter($db, $esxiCredentialId) === null) {
            throw new RuntimeException('Mission datacenter is required: the selected ESXi credential does not report exactly one datacenter.');
        }
        if (trim((string) ($mission['hypervisor_datastorage'] ?? '')) === '') {
            throw new RuntimeException('Mission datastore is required before deployment.');
        }
    }

    $vmCount = (int) repo_scalar($db, 'SELECT COUNT(*) FROM deploy_vms WHERE mission_id = ?', 'i', [$missionId]);
    if ($vmCount <= 0) {
        throw new RuntimeException('Mission has no VMs to deploy.');
    }
}

function repo_deploy_assert_credential_type(mysqli $db, int $credentialId, string $type): array
{
    $label = credential_type_label($type);
    $stmt = $db->prepare('SELECT id, type, name, host, port, username, secret_ciphertext, esxi_trust_mode, esxi_cert_kind, esxi_certificate_pem, esxi_strict_tested_at FROM deploy_credentials WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $credentialId);
    $stmt->execute();
    $credential = $stmt->get_result()->fetch_assoc();
    if (!$credential) {
        throw new RuntimeException('Selected ' . $label . ' credential not found.');
    }
    if ((string) $credential['type'] !== $type) {
        throw new RuntimeException('Selected ' . $label . ' credential has the wrong type.');
    }

    $missing = [];
    foreach (['host' => 'host', 'username' => 'username', 'secret_ciphertext' => 'secret'] as $column => $name) {
        if (trim((string) ($credential[$column] ?? '')) === '') {
            $missing[] = $name;
        }
    }
    $port = $credential['port'];
    if ($port !== null && ((int) $port < 1 || (int) $port > 65535)) {
        $missing[] = 'valid port';
    }
    if ($missing !== []) {
        throw new RuntimeException('Selected ' . $label . ' credential is incomplete: ' . implode(', ', $missing) . '.');
    }

    return $credential;
}
