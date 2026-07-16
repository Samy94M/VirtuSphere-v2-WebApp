<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../defaults.php';
require_once __DIR__ . '/../credentials.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../mac_import.php';
require_once __DIR__ . '/../validate.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/status_events.php';

/**
 * Reads a mode out of a stored payload. Accepts system modes too, because the
 * worker reads back the payload of an inventory job with this.
 */
function deploy_job_normalize_mode(string $mode): string
{
    $mode = strtolower(trim($mode));
    if (!in_array($mode, virtusphere_deploy_modes(), true)) {
        throw new InvalidArgumentException('Unknown deploy mode: ' . $mode . '.');
    }

    return $mode;
}

/**
 * Reads a mode an operator asked for. A mission job may only carry a mode the
 * deploy form offers. A system mode here would queue a mission job that the
 * worker then routes into the mission-less inventory branch, which reads the
 * job's ESXi credential and ignores the mission entirely. Enforced in the
 * repository rather than only on the page, because a page-level guard is
 * bypassable by a crafted POST.
 */
function deploy_job_normalize_mission_mode(string $mode): string
{
    $mode = strtolower(trim($mode));
    if (!in_array($mode, virtusphere_user_deploy_modes(), true)) {
        throw new InvalidArgumentException('Deploy mode must be one of: ' . implode(', ', virtusphere_user_deploy_modes()) . '.');
    }

    return $mode;
}

function deploy_job_bool(mixed $value): bool
{
    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}

function deploy_job_normalize_vm_ids(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $ids = [];
    foreach ($value as $vmId) {
        $id = (int) $vmId;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }

    return array_values(array_map('intval', array_keys($ids)));
}

function deploy_job_normalize_wait(mixed $value): int
{
    $wait = (int) ($value ?? VIRTUSPHERE_POWERCYCLE_WAIT_DEFAULT);
    if ($wait < VIRTUSPHERE_POWERCYCLE_WAIT_MIN) {
        return VIRTUSPHERE_POWERCYCLE_WAIT_MIN;
    }
    if ($wait > VIRTUSPHERE_POWERCYCLE_WAIT_MAX) {
        return VIRTUSPHERE_POWERCYCLE_WAIT_MAX;
    }

    return $wait;
}

function deploy_job_payload(array $data): array
{
    return [
        'mode' => deploy_job_normalize_mode((string) ($data['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL)),
        'verbose' => deploy_job_bool($data['verbose'] ?? false),
        'vm_ids' => deploy_job_normalize_vm_ids($data['vm_ids'] ?? []),
        'powercycle_wait' => deploy_job_normalize_wait($data['powercycle_wait'] ?? null),
    ];
}

/**
 * Only failed, cancelled and partial jobs can be re-queued: re-running a
 * succeeded job is the start form's business (it walks the readiness preview),
 * and active jobs are cancelled, not retried. A partial job is terminal with a
 * durable per-VM verdict in result_json; its retry is the export-only follow-up
 * (deploy_job_retry_plan). Mission-less system jobs (the ESXi inventory pulls)
 * are scheduled by the worker, never retried by hand.
 */
function deploy_job_is_retryable(string $status, ?int $missionId): bool
{
    if ($missionId === null || $missionId <= 0) {
        return false;
    }

    return in_array($status, [
        VIRTUSPHERE_DEPLOY_STATUS_FAILED,
        VIRTUSPHERE_DEPLOY_STATUS_CANCELLED,
        VIRTUSPHERE_DEPLOY_STATUS_PARTIAL,
    ], true);
}

/**
 * Decides what a retry re-queues. NULL means "repeat the old payload
 * unchanged", which stays the behaviour for plain failed and cancelled jobs.
 *
 * Once a MAC import has committed anything, a retry must never re-run
 * `create` or `powercycle` for the whole job again:
 * - a `partial` job retries as export-only for exactly its failed_vm_ids;
 * - if that failed set is not trustworthy - the job status and the stored
 *   outcome diverge (a `failed` job whose result says success/partial, e.g.
 *   after a lost HTTP response), or a partial job's result is missing,
 *   malformed or names no failed VM - the export is repeated for the
 *   ORIGINAL selection, never widened to the full deploy.
 * Cancelled jobs keep the plain re-queue: a cancellation makes no outcome
 * claim, so there is nothing to diverge from.
 *
 * @param array{outcome:string, successful_vm_ids:list<int>, failed_vm_ids:list<int>}|null $result decoded result_json (mac_import_decode_result)
 * @param int[] $originalVmIds the old payload's vm_ids ([] = whole mission)
 * @return array{mode:string, vm_ids:list<int>, scope:string}|null
 */
function deploy_job_retry_plan(string $status, ?array $result, array $originalVmIds): ?array
{
    if ($status === VIRTUSPHERE_DEPLOY_STATUS_PARTIAL) {
        if ($result !== null && $result['outcome'] === 'partial' && $result['failed_vm_ids'] !== []) {
            return ['mode' => 'export', 'vm_ids' => $result['failed_vm_ids'], 'scope' => 'failed_vms'];
        }

        return ['mode' => 'export', 'vm_ids' => array_values($originalVmIds), 'scope' => 'original_selection'];
    }

    if ($status === VIRTUSPHERE_DEPLOY_STATUS_FAILED && $result !== null && $result['outcome'] !== 'failed') {
        return ['mode' => 'export', 'vm_ids' => array_values($originalVmIds), 'scope' => 'original_selection'];
    }

    return null;
}

function deploy_schedule_error(string $field, string $key, string $fallback, array $replace = []): ValidationException
{
    $message = validator_text($key, $fallback, $replace);

    return new ValidationException([$field => $message], $message);
}

/**
 * Parses the deploy "schedule" block from POST into a normalized structure.
 * Interprets the datetime-local value as portal-timezone wall time and converts
 * to UTC. Throws ValidationException on past times (5-min grace), a DST gap,
 * the 30-day horizon or an out-of-range/mode-incompatible stagger interval.
 *
 * @return array{has_schedule:bool, base_utc:?string, base_epoch:int, stagger:?int, mode:string}
 */
function deploy_parse_schedule(array $post, ?string $timezone = null): array
{
    // Reads $_POST, so a system mode is rejected here as well as in the repo.
    $mode = deploy_job_normalize_mission_mode((string) ($post['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL));
    $startMode = (string) ($post['start_mode'] ?? 'now');
    $tz = new DateTimeZone($timezone ?? portal_timezone());
    $nowEpoch = time();

    $baseUtc = null;
    $baseEpoch = $nowEpoch;
    if ($startMode === 'scheduled') {
        $raw = trim((string) ($post['scheduled_at'] ?? ''));
        if ($raw === '') {
            throw deploy_schedule_error('scheduled_at', 'validate.deploy_schedule_required', 'Please pick a start date and time.');
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw, $tz)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $raw, $tz);
        if ($dt === false) {
            throw deploy_schedule_error('scheduled_at', 'validate.deploy_schedule_invalid', 'The start date/time is invalid.');
        }
        // DST spring-forward: a non-existent local time gets shifted by PHP.
        if (substr($dt->format('Y-m-d\TH:i'), 0, 16) !== substr($raw, 0, 16)) {
            throw deploy_schedule_error('scheduled_at', 'validate.deploy_schedule_dst', 'That local time does not exist (daylight-saving gap). Please pick another time.');
        }
        $baseEpoch = $dt->getTimestamp();
        if ($baseEpoch < $nowEpoch - VIRTUSPHERE_DEPLOY_SCHEDULE_PAST_GRACE_SECONDS) {
            throw deploy_schedule_error('scheduled_at', 'validate.deploy_schedule_past', 'The start time is in the past.');
        }
        $baseUtc = gmdate('Y-m-d H:i:s', $baseEpoch);
    }

    $stagger = null;
    $staggerRaw = trim((string) ($post['stagger_minutes'] ?? ''));
    if ($staggerRaw !== '' && (int) $staggerRaw > 0) {
        if (!in_array($mode, VIRTUSPHERE_DEPLOY_STAGGER_MODES, true)) {
            throw deploy_schedule_error('stagger_minutes', 'validate.deploy_stagger_mode', 'Staggering is only available for the full, power-cycle and start modes.');
        }
        $stagger = (int) $staggerRaw;
        if ($stagger < VIRTUSPHERE_DEPLOY_STAGGER_MIN || $stagger > VIRTUSPHERE_DEPLOY_STAGGER_MAX) {
            throw deploy_schedule_error('stagger_minutes', 'validate.deploy_stagger_range', 'The stagger interval must be between :min and :max minutes.', ['min' => VIRTUSPHERE_DEPLOY_STAGGER_MIN, 'max' => VIRTUSPHERE_DEPLOY_STAGGER_MAX]);
        }
    }

    if ($baseEpoch > $nowEpoch + VIRTUSPHERE_DEPLOY_SCHEDULE_HORIZON_DAYS * 86400) {
        throw deploy_schedule_error('scheduled_at', 'validate.deploy_schedule_horizon', 'The last staggered start is beyond the :days-day scheduling horizon.', ['days' => VIRTUSPHERE_DEPLOY_SCHEDULE_HORIZON_DAYS]);
    }

    return [
        'has_schedule' => $baseUtc !== null || $stagger !== null,
        'base_utc' => $baseUtc,
        'base_epoch' => $baseEpoch,
        'stagger' => $stagger,
        'mode' => $mode,
    ];
}

/**
 * Computes the per-VM start times for the schedule preview without creating any
 * jobs. Returns Unix epochs so the page formats them in the portal timezone.
 *
 * @return array<int, array{vm_name:string, epoch:int}>
 */
function deploy_preview_rows(mysqli $db, int $missionId, array $payloadData, array $schedule): array
{
    $vmIds = is_array($payloadData['vm_ids'] ?? null) ? $payloadData['vm_ids'] : [];
    $vms = repo_deploy_group_vm_list($db, $missionId, $vmIds);
    $baseEpoch = (int) $schedule['base_epoch'];
    $stagger = $schedule['stagger'];

    $rows = [];
    foreach (array_values($vms) as $i => $vm) {
        $epoch = $baseEpoch + ($stagger !== null ? $i * $stagger * 60 : 0);
        $rows[] = ['vm_name' => (string) $vm['vm_name'], 'epoch' => $epoch];
    }

    return $rows;
}

function deploy_job_payload_summary(?string $payloadJson): string
{
    if ($payloadJson === null || trim($payloadJson) === '') {
        return VIRTUSPHERE_DEPLOY_MODE_FULL;
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return 'invalid payload';
    }

    $mode = (string) ($payload['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL);
    $verbose = !empty($payload['verbose']) ? ' -vvv' : '';
    $vmIds = is_array($payload['vm_ids'] ?? null) ? $payload['vm_ids'] : [];
    $scope = $vmIds === [] ? '' : ' (' . count($vmIds) . ' VMs)';

    return $mode . $verbose . $scope;
}

function repo_deploy_jobs(mysqli $db, int $limit = 100, ?int $missionId = null): array
{
    $limit = max(1, min(500, $limit));
    if ($missionId !== null && $missionId > 0) {
        $stmt = $db->prepare(
            'SELECT j.id, j.mission_id, m.mission_name, j.user_id, u.name AS user_name, j.status, j.locked_at, j.locked_by, j.heartbeat_at, j.attempts, j.last_error, j.payload_json, j.result_json, j.credential_esxi_id, e.name AS esxi_credential_name, j.credential_ansible_id, a.name AS ansible_credential_name, j.cancelled_at, j.scheduled_at, j.group_id, j.created_at, j.updated_at
             FROM deploy_jobs j
             INNER JOIN deploy_missions m ON m.id = j.mission_id
             LEFT JOIN deploy_users u ON u.id = j.user_id
             LEFT JOIN deploy_credentials e ON e.id = j.credential_esxi_id
             LEFT JOIN deploy_credentials a ON a.id = j.credential_ansible_id
             WHERE j.mission_id = ?
             ORDER BY j.id DESC
             LIMIT ?'
        );
        $stmt->bind_param('ii', $missionId, $limit);
    } else {
        $stmt = $db->prepare(
            'SELECT j.id, j.mission_id, m.mission_name, j.user_id, u.name AS user_name, j.status, j.locked_at, j.locked_by, j.heartbeat_at, j.attempts, j.last_error, j.payload_json, j.result_json, j.credential_esxi_id, e.name AS esxi_credential_name, j.credential_ansible_id, a.name AS ansible_credential_name, j.cancelled_at, j.scheduled_at, j.group_id, j.created_at, j.updated_at
             FROM deploy_jobs j
             INNER JOIN deploy_missions m ON m.id = j.mission_id
             LEFT JOIN deploy_users u ON u.id = j.user_id
             LEFT JOIN deploy_credentials e ON e.id = j.credential_esxi_id
             LEFT JOIN deploy_credentials a ON a.id = j.credential_ansible_id
             ORDER BY j.id DESC
             LIMIT ?'
        );
        $stmt->bind_param('i', $limit);
    }
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

function repo_deploy_job(mysqli $db, int $jobId): ?array
{
    return repo_fetch_one(
        $db,
        'SELECT j.id, j.mission_id, m.mission_name, j.user_id, u.name AS user_name, j.status, j.locked_at, j.locked_by, j.heartbeat_at, j.attempts, j.last_error, j.payload_json, j.result_json, j.credential_esxi_id, e.name AS esxi_credential_name, j.credential_ansible_id, a.name AS ansible_credential_name, j.cancelled_at, j.scheduled_at, j.group_id, j.created_at, j.updated_at
         FROM deploy_jobs j
         LEFT JOIN deploy_missions m ON m.id = j.mission_id
         LEFT JOIN deploy_users u ON u.id = j.user_id
         LEFT JOIN deploy_credentials e ON e.id = j.credential_esxi_id
         LEFT JOIN deploy_credentials a ON a.id = j.credential_ansible_id
         WHERE j.id = ?
         LIMIT 1',
        'i',
        [$jobId]
    );
}

function repo_deploy_job_logs(mysqli $db, int $jobId, int $afterSeq = 0, int $limit = 500): array
{
    $limit = max(1, min(1000, $limit));
    $afterSeq = max(0, $afterSeq);
    $stmt = $db->prepare('SELECT seq, stream, line, created_at FROM deploy_job_logs WHERE job_id = ? AND seq > ? ORDER BY seq ASC LIMIT ?');
    $stmt->bind_param('iii', $jobId, $afterSeq, $limit);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/**
 * @param int[] $vmIds
 * @return int[] VM ids that belong to the mission (order preserved)
 *
 * An empty input stays empty and is read as "whole mission" one level up. But a
 * non-empty input that filters down to nothing is a selection whose VMs have all
 * been deleted (or never belonged here) since the form was rendered: silently
 * returning [] would widen that job to the entire mission. Throw instead, with
 * the exact wording of the worker-side gate (ansible_prepare_job_artifacts), so
 * the same condition reads the same one stage earlier.
 */
function repo_deploy_filter_mission_vm_ids(mysqli $db, int $missionId, array $vmIds): array
{
    if ($vmIds === []) {
        return [];
    }

    $stmt = $db->prepare('SELECT id FROM deploy_vms WHERE mission_id = ?');
    $stmt->bind_param('i', $missionId);
    $stmt->execute();
    $owned = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $owned[(int) $row['id']] = true;
    }

    $filtered = array_values(array_filter($vmIds, static fn (int $id): bool => isset($owned[$id])));
    if ($filtered === []) {
        throw new RuntimeException('None of the selected VMs belong to this mission.');
    }

    return $filtered;
}

/**
 * Ordered VM list for a stagger group / its preview. An explicit selection
 * (filtered to the mission) keeps its order; an empty selection means the whole
 * mission ordered by vm_name.
 *
 * @param int[] $selectedVmIds
 * @return array<int, array{id:int, vm_name:string}>
 */
function repo_deploy_group_vm_list(mysqli $db, int $missionId, array $selectedVmIds): array
{
    $selected = repo_deploy_filter_mission_vm_ids($db, $missionId, $selectedVmIds);
    if ($selected === []) {
        $stmt = $db->prepare('SELECT id, vm_name FROM deploy_vms WHERE mission_id = ? ORDER BY vm_name');
        $stmt->bind_param('i', $missionId);
        $stmt->execute();

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'vm_name' => (string) $r['vm_name']],
            repo_fetch_all($stmt->get_result())
        );
    }

    $vms = [];
    foreach ($selected as $vmId) {
        $name = (string) (repo_scalar($db, 'SELECT vm_name FROM deploy_vms WHERE id = ? AND mission_id = ? LIMIT 1', 'ii', [$vmId, $missionId]) ?? '');
        $vms[] = ['id' => $vmId, 'vm_name' => $name];
    }

    return $vms;
}

/**
 * Drops the streamed output of jobs that finished more than $retentionDays ago.
 *
 * The window is measured on the JOB, not on the log row: a job that streams for
 * an hour must not lose its opening lines while it is still running, and a live
 * tail must never race a purge. Only terminal jobs qualify, so `queued` and
 * `running` are untouchable by construction.
 *
 * The job row survives with its status and last_error, so the deploy list keeps
 * its history; deploy_log.php tells the reader that the output was pruned.
 */
function repo_purge_deploy_job_logs(mysqli $db, int $retentionDays = VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS): int
{
    $terminal = VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES;
    $placeholders = implode(',', array_fill(0, count($terminal), '?'));
    $stmt = $db->prepare(
        'DELETE l FROM deploy_job_logs l
         JOIN deploy_jobs j ON j.id = l.job_id
         WHERE j.status IN (' . $placeholders . ')
           AND j.updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    $params = array_merge($terminal, [$retentionDays]);
    $stmt->bind_param(str_repeat('s', count($terminal)) . 'i', ...$params);
    $stmt->execute();

    return $stmt->affected_rows;
}

/**
 * Removes finished mission-less system jobs (the ESXi inventory pulls). No page
 * lists them once they are terminal, and their one durable result lives in
 * deploy_esxi_inventory_state, so the rows are pure growth: one every interval
 * per credential. Their log rows cascade with the FK.
 *
 * Mission jobs are deliberately kept: the deploy page shows their history.
 */
function repo_purge_finished_system_jobs(mysqli $db, int $retentionDays = VIRTUSPHERE_SYSTEM_JOB_RETENTION_DAYS): int
{
    $terminal = VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES;
    $placeholders = implode(',', array_fill(0, count($terminal), '?'));
    $stmt = $db->prepare(
        'DELETE FROM deploy_jobs
         WHERE mission_id IS NULL
           AND status IN (' . $placeholders . ')
           AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    $params = array_merge($terminal, [$retentionDays]);
    $stmt->bind_param(str_repeat('s', count($terminal)) . 'i', ...$params);
    $stmt->execute();

    return $stmt->affected_rows;
}

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
    return repo_fetch_one(
        $db,
        'SELECT id FROM deploy_jobs WHERE mission_id = ? AND status IN (?, ?) AND cancelled_at IS NULL LIMIT 1 FOR UPDATE',
        'iss',
        [$missionId, VIRTUSPHERE_DEPLOY_STATUS_QUEUED, VIRTUSPHERE_DEPLOY_STATUS_RUNNING]
    ) !== null;
}

function repo_create_deploy_job(mysqli $db, int $missionId, int $userId, int $esxiCredentialId, int $ansibleCredentialId, array $payloadData, ?string $scheduledAtUtc = null): int
{
    if ($missionId <= 0 || $userId <= 0 || $esxiCredentialId <= 0 || $ansibleCredentialId <= 0) {
        throw new InvalidArgumentException('Mission, user, ESXi credential and Ansible credential are required.');
    }

    // A mission job may only carry a mode the deploy form offers.
    deploy_job_normalize_mission_mode((string) ($payloadData['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL));
    $payload = deploy_job_payload($payloadData);

    return repo_transaction($db, static function () use ($db, $missionId, $userId, $esxiCredentialId, $ansibleCredentialId, $payload, $scheduledAtUtc): int {
        repo_deploy_assert_user_exists($db, $userId);

        $stmt = $db->prepare('SELECT id, mission_name, hypervisor_datastorage, hypervisor_datacenter FROM deploy_missions WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $missionId);
        $stmt->execute();
        $mission = $stmt->get_result()->fetch_assoc();
        if (!$mission) {
            throw new RuntimeException('Mission not found.');
        }
        repo_deploy_assert_mission_ready($db, $mission, $esxiCredentialId, (string) $payload['mode']);

        // Keep only VM ids that actually belong to this mission; drop the rest.
        // No selection at all means "whole mission"; a selection that filters down
        // to nothing (its VMs were deleted since the form was rendered) throws
        // rather than silently widening to the whole mission.
        $payload['vm_ids'] = repo_deploy_filter_mission_vm_ids($db, $missionId, $payload['vm_ids']);
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        repo_deploy_assert_credential_type($db, $esxiCredentialId, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
        repo_deploy_assert_credential_type($db, $ansibleCredentialId, VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);

        if (repo_deploy_active_job_exists($db, $missionId)) {
            throw new RuntimeException('This mission already has an active deploy job.');
        }

        $stmt = $db->prepare('INSERT INTO deploy_jobs (mission_id, user_id, payload_json, credential_esxi_id, credential_ansible_id, scheduled_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iisiis', $missionId, $userId, $payloadJson, $esxiCredentialId, $ansibleCredentialId, $scheduledAtUtc);
        $stmt->execute();
        $jobId = (int) $db->insert_id;
        $logSuffix = $scheduledAtUtc !== null ? ' scheduled for ' . $scheduledAtUtc . ' UTC' : '';
        repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Deploy job queued: ' . deploy_job_payload_summary($payloadJson) . $logSuffix);

        return $jobId;
    });
}

/**
 * Re-queues a retryable mission job as a NEW job with the old job's credential
 * snapshot. Runs immediately (no scheduled_at or group_id carry-over: "retry"
 * means "run it again now") and is attributed to the retrying user, not the
 * original author. The payload is the old one, unless deploy_job_retry_plan()
 * turns the retry into an export-only follow-up (partial jobs and diverged
 * failed jobs). Every create-time guard fires again via
 * repo_create_deploy_job(): one-active-job-per-mission, mission readiness,
 * VM-id filtering and the credential type asserts, so a mission or credential
 * deleted since the original run throws instead of half-queuing.
 */
function repo_retry_deploy_job(mysqli $db, int $jobId, int $userId): int
{
    if ($jobId <= 0 || $userId <= 0) {
        throw new InvalidArgumentException('Job and user are required.');
    }

    return repo_transaction($db, static function () use ($db, $jobId, $userId): int {
        $job = repo_deploy_job($db, $jobId);
        if ($job === null) {
            throw new RuntimeException('Deploy job not found.');
        }
        $missionId = $job['mission_id'] !== null ? (int) $job['mission_id'] : null;
        if (!deploy_job_is_retryable((string) $job['status'], $missionId)) {
            throw new RuntimeException('Only failed, cancelled or partial mission jobs can be retried.');
        }

        $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $plan = deploy_job_retry_plan(
            (string) $job['status'],
            mac_import_decode_result(isset($job['result_json']) ? (string) $job['result_json'] : null),
            deploy_job_normalize_vm_ids($payload['vm_ids'] ?? [])
        );
        if ($plan !== null) {
            $payload['mode'] = $plan['mode'];
            $payload['vm_ids'] = $plan['vm_ids'];
        }
        $newJobId = repo_create_deploy_job(
            $db,
            (int) $missionId,
            $userId,
            (int) $job['credential_esxi_id'],
            (int) $job['credential_ansible_id'],
            $payload,
            null
        );
        $note = 'Retry of deploy job ' . $jobId;
        if ($plan !== null) {
            $note .= ' (export-only: ' . ($plan['scope'] === 'failed_vms' ? count($plan['vm_ids']) . ' failed VMs' : 'original selection') . ')';
        }
        repo_insert_deploy_job_log_unlocked($db, $newJobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $note);

        return $newJobId;
    });
}

/**
 * Enqueues a staggered group: one job per VM, same group_id, each scheduled
 * offset by $staggerMinutes from $baseUtc (or from now when $baseUtc is null).
 * The single-active-per-mission guard is checked ONCE for the whole group.
 *
 * @return array{group_id:string, count:int, schedule:array<int,array{vm_id:int,vm_name:string,scheduled_at:string}>}
 */
function repo_enqueue_deploy_group(mysqli $db, int $missionId, int $userId, int $esxiCredentialId, int $ansibleCredentialId, array $payloadData, ?string $baseUtc, int $staggerMinutes): array
{
    if ($missionId <= 0 || $userId <= 0 || $esxiCredentialId <= 0 || $ansibleCredentialId <= 0) {
        throw new InvalidArgumentException('Mission, user, ESXi credential and Ansible credential are required.');
    }
    if ($staggerMinutes < VIRTUSPHERE_DEPLOY_STAGGER_MIN || $staggerMinutes > VIRTUSPHERE_DEPLOY_STAGGER_MAX) {
        throw new InvalidArgumentException('Stagger interval out of range.');
    }

    $groupMode = deploy_job_normalize_mission_mode((string) ($payloadData['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL));
    // Staggering spreads a power operation over time. A config write has nothing
    // to spread: staggering `autostart` would queue one job per VM, each
    // rewriting the host's defaults. deploy_parse_schedule() refuses it too, but
    // that guard sits on the page and pages are bypassable.
    if (!in_array($groupMode, VIRTUSPHERE_DEPLOY_STAGGER_MODES, true)) {
        throw new InvalidArgumentException('Mode ' . $groupMode . ' cannot be staggered.');
    }
    $basePayload = deploy_job_payload($payloadData);

    return repo_transaction($db, static function () use ($db, $missionId, $userId, $esxiCredentialId, $ansibleCredentialId, $basePayload, $baseUtc, $staggerMinutes): array {
        repo_deploy_assert_user_exists($db, $userId);

        $stmt = $db->prepare('SELECT id, mission_name, hypervisor_datastorage, hypervisor_datacenter FROM deploy_missions WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $missionId);
        $stmt->execute();
        $mission = $stmt->get_result()->fetch_assoc();
        if (!$mission) {
            throw new RuntimeException('Mission not found.');
        }
        repo_deploy_assert_mission_ready($db, $mission, $esxiCredentialId, (string) $basePayload['mode']);
        repo_deploy_assert_credential_type($db, $esxiCredentialId, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
        repo_deploy_assert_credential_type($db, $ansibleCredentialId, VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);

        if (repo_deploy_active_job_exists($db, $missionId)) {
            throw new RuntimeException('This mission already has an active deploy job.');
        }

        // Resolve the ordered VM list (shared with the preview so both agree).
        $vms = repo_deploy_group_vm_list($db, $missionId, $basePayload['vm_ids']);
        if ($vms === []) {
            throw new RuntimeException('Mission has no VMs to deploy.');
        }

        // Horizon guard for the LAST staggered slot (base + (n-1)*stagger).
        $baseEpoch = $baseUtc !== null ? strtotime($baseUtc . ' UTC') : time();
        $lastEpoch = $baseEpoch + (count($vms) - 1) * $staggerMinutes * 60;
        if ($lastEpoch > time() + VIRTUSPHERE_DEPLOY_SCHEDULE_HORIZON_DAYS * 86400) {
            throw new ValidationException(
                ['scheduled_at' => validator_text('validate.deploy_schedule_horizon', 'The last staggered start is beyond the :days-day scheduling horizon.', ['days' => VIRTUSPHERE_DEPLOY_SCHEDULE_HORIZON_DAYS])],
                validator_text('validate.deploy_schedule_horizon', 'The last staggered start is beyond the :days-day scheduling horizon.', ['days' => VIRTUSPHERE_DEPLOY_SCHEDULE_HORIZON_DAYS])
            );
        }

        $groupId = bin2hex(random_bytes(6)); // 12 hex chars -> CHAR(12)
        $schedule = [];
        $index = 0;
        foreach ($vms as $vm) {
            $vmId = (int) $vm['id'];
            $slotUtc = gmdate('Y-m-d H:i:s', $baseEpoch + $index * $staggerMinutes * 60);
            $payload = $basePayload;
            $payload['vm_ids'] = [$vmId];
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

            $stmt = $db->prepare('INSERT INTO deploy_jobs (mission_id, user_id, payload_json, credential_esxi_id, credential_ansible_id, scheduled_at, group_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('iisiiss', $missionId, $userId, $payloadJson, $esxiCredentialId, $ansibleCredentialId, $slotUtc, $groupId);
            $stmt->execute();
            $jobId = (int) $db->insert_id;
            repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Deploy job queued (group ' . $groupId . ', slot ' . ($index + 1) . '/' . count($vms) . ') scheduled for ' . $slotUtc . ' UTC');
            $schedule[] = ['vm_id' => $vmId, 'vm_name' => (string) ($vm['vm_name'] ?? ''), 'scheduled_at' => $slotUtc];
            $index++;
        }

        return ['group_id' => $groupId, 'count' => count($vms), 'schedule' => $schedule];
    });
}

/**
 * Cancels all still-queued jobs of a stagger group. A job that is already
 * running is left to finish; only queued slots are stopped.
 */
function repo_cancel_deploy_group(mysqli $db, string $groupId, int $userId): int
{
    $groupId = trim($groupId);
    if ($groupId === '' || $userId <= 0) {
        throw new InvalidArgumentException('Group and user are required.');
    }

    return repo_transaction($db, static function () use ($db, $groupId, $userId): int {
        $queued = VIRTUSPHERE_DEPLOY_STATUS_QUEUED;
        $stmt = $db->prepare('SELECT id FROM deploy_jobs WHERE group_id = ? AND status = ? AND cancelled_at IS NULL FOR UPDATE');
        $stmt->bind_param('ss', $groupId, $queued);
        $stmt->execute();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], repo_fetch_all($stmt->get_result()));

        $cancelled = VIRTUSPHERE_DEPLOY_STATUS_CANCELLED;
        $message = 'Cancelled with group ' . $groupId . ' by user id ' . $userId;
        foreach ($ids as $jobId) {
            $stmt = $db->prepare('UPDATE deploy_jobs SET status = ?, cancelled_at = NOW(), locked_at = NULL, locked_by = NULL, heartbeat_at = NULL, last_error = ?, updated_at = NOW() WHERE id = ? AND status = ?');
            $stmt->bind_param('ssis', $cancelled, $message, $jobId, $queued);
            $stmt->execute();
            if ($stmt->affected_rows === 1) {
                repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $message);
            }
        }

        return count($ids);
    });
}

/**
 * Enqueues a mission-less system job (e.g. ESXi inventory, ADR-0023). Race-safe:
 * returns null if a queued/running system job already exists for the same ESXi
 * credential, so a manual refresh + the interval automation cannot pile up.
 */
function repo_create_system_job(mysqli $db, string $mode, int $esxiCredentialId, int $ansibleCredentialId, ?int $userId = null): ?int
{
    if ($esxiCredentialId <= 0 || $ansibleCredentialId <= 0) {
        throw new InvalidArgumentException('ESXi and Ansible credentials are required.');
    }
    // The mirror of deploy_job_normalize_mission_mode(): a mission-less job may
    // only carry a system mode. A `full` job without a mission would reach the
    // deploy branch and die on a NULL mission id.
    $mode = deploy_job_normalize_mode($mode);
    if (!array_key_exists($mode, VIRTUSPHERE_SYSTEM_PLAYBOOKS)) {
        throw new InvalidArgumentException('System jobs must use a system mode, not ' . $mode . '.');
    }

    return repo_transaction($db, static function () use ($db, $mode, $esxiCredentialId, $ansibleCredentialId, $userId): ?int {
        $existing = repo_fetch_one(
            $db,
            "SELECT id FROM deploy_jobs WHERE mission_id IS NULL AND credential_esxi_id = ? AND status IN ('queued', 'running') AND cancelled_at IS NULL LIMIT 1 FOR UPDATE",
            'i',
            [$esxiCredentialId]
        );
        if ($existing !== null) {
            return null;
        }

        repo_deploy_assert_credential_type($db, $esxiCredentialId, VIRTUSPHERE_CREDENTIAL_TYPE_ESXI);
        repo_deploy_assert_credential_type($db, $ansibleCredentialId, VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE);

        $payloadJson = json_encode(['mode' => $mode], JSON_THROW_ON_ERROR);
        $userParam = $userId !== null && $userId > 0 ? $userId : null;
        $stmt = $db->prepare('INSERT INTO deploy_jobs (mission_id, user_id, payload_json, credential_esxi_id, credential_ansible_id) VALUES (NULL, ?, ?, ?, ?)');
        $stmt->bind_param('isii', $userParam, $payloadJson, $esxiCredentialId, $ansibleCredentialId);
        $stmt->execute();
        $jobId = (int) $db->insert_id;
        repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'System job queued: ' . $mode . ' for ESXi credential ' . $esxiCredentialId);

        return $jobId;
    });
}

function repo_cancel_deploy_job(mysqli $db, int $jobId, int $userId): bool
{
    if ($jobId <= 0 || $userId <= 0) {
        throw new InvalidArgumentException('Job and user are required.');
    }

    return repo_transaction($db, static function () use ($db, $jobId, $userId): bool {
        $stmt = $db->prepare('SELECT id, status FROM deploy_jobs WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        if (!$job) {
            throw new RuntimeException('Deploy job not found.');
        }
        if (!in_array((string) $job['status'], VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES, true)) {
            throw new RuntimeException('Only queued or running deploy jobs can be cancelled.');
        }

        $message = 'Cancelled by user id ' . $userId;
        $status = VIRTUSPHERE_DEPLOY_STATUS_CANCELLED;
        $stmt = $db->prepare('UPDATE deploy_jobs SET status = ?, cancelled_at = NOW(), locked_at = NULL, locked_by = NULL, heartbeat_at = NULL, last_error = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('ssi', $status, $message, $jobId);
        $stmt->execute();
        repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $message);

        return true;
    });
}

function repo_claim_next_deploy_job(mysqli $db, string $workerId): ?array
{
    $workerId = trim($workerId);
    if ($workerId === '') {
        throw new InvalidArgumentException('Worker id is required.');
    }

    return repo_transaction($db, static function () use ($db, $workerId): ?array {
        $queued = VIRTUSPHERE_DEPLOY_STATUS_QUEUED;
        // Scheduled jobs (scheduled_at in the future) are not yet eligible.
        // The DB session is pinned to UTC (db()), so UTC_TIMESTAMP() matches the
        // stored UTC scheduled_at (ADR-0022).
        //
        // Mission deploys claim before mission-less system jobs (inventory
        // pulls): with several ESXi credentials one interval cycle enqueues a
        // burst of pulls that would otherwise delay an operator's deploy by
        // many minutes. Deliberate starvation trade-off: a continuous deploy
        // stream postpones inventory jobs, which is fine because the cache is
        // a warn-only mirror and re-enqueues every interval (ADR-0023). The
        // expression is not index-backed; with LIMIT 1 over the small queued
        // set that is irrelevant.
        $stmt = $db->prepare('SELECT id FROM deploy_jobs WHERE status = ? AND cancelled_at IS NULL AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP()) ORDER BY (mission_id IS NULL) ASC, id ASC LIMIT 1 FOR UPDATE');
        $stmt->bind_param('s', $queued);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return null;
        }

        $jobId = (int) $row['id'];
        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $stmt = $db->prepare('UPDATE deploy_jobs SET status = ?, locked_at = NOW(), locked_by = ?, heartbeat_at = NOW(), attempts = attempts + 1, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('ssi', $running, $workerId, $jobId);
        $stmt->execute();
        repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'Deploy job claimed by ' . $workerId);

        return repo_deploy_job($db, $jobId);
    });
}

function repo_touch_deploy_job_heartbeat(mysqli $db, int $jobId, string $workerId): bool
{
    $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
    $stmt = $db->prepare('UPDATE deploy_jobs SET heartbeat_at = NOW(), updated_at = NOW() WHERE id = ? AND locked_by = ? AND status = ?');
    $stmt->bind_param('iss', $jobId, $workerId, $running);

    return $stmt->execute() && $stmt->affected_rows === 1;
}

function repo_reap_stale_deploy_jobs(mysqli $db, int $staleAfterSeconds = VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS): array
{
    $staleAfterSeconds = max(60, $staleAfterSeconds);
    $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
    $failed = VIRTUSPHERE_DEPLOY_STATUS_FAILED;
    $message = 'Reaped stale deploy job after missing heartbeat for ' . $staleAfterSeconds . ' seconds.';

    return repo_transaction($db, static function () use ($db, $staleAfterSeconds, $running, $failed, $message): array {
        $stmt = $db->prepare('SELECT id, mission_id, payload_json, locked_by FROM deploy_jobs WHERE status = ? AND (heartbeat_at IS NULL OR heartbeat_at < DATE_SUB(NOW(), INTERVAL ? SECOND)) ORDER BY heartbeat_at ASC, id ASC FOR UPDATE SKIP LOCKED');
        $stmt->bind_param('si', $running, $staleAfterSeconds);
        $stmt->execute();
        $jobs = repo_fetch_all($stmt->get_result());

        foreach ($jobs as $job) {
            $jobId = (int) $job['id'];
            $stmt = $db->prepare('UPDATE deploy_jobs SET status = ?, last_error = ?, locked_at = NULL, locked_by = NULL, heartbeat_at = NULL, updated_at = NOW() WHERE id = ? AND status = ?');
            $stmt->bind_param('ssis', $failed, $message, $jobId, $running);
            $stmt->execute();
            if ($stmt->affected_rows === 1) {
                repo_insert_deploy_job_log_unlocked($db, $jobId, VIRTUSPHERE_DEPLOY_LOG_SYSTEM, $message);
            }
        }

        return $jobs;
    });
}

/**
 * Convergence sweep for orphaned deploying VMs. A VM left in `deploying` whose
 * mission has no queued/running job any more can never be finished by a
 * worker: the job that owned it is terminal, and the worker died (or was
 * cancelled and then died) before its own failure path could mark the VM. The
 * heartbeat reaper cannot help either - it only touches jobs still `running`.
 *
 * Convergence means failed/failed: lifecycle_state and mecm_sync_state
 * together, so no orphan advertises a MECM pickup that can never happen.
 * Stored MACs are untouched and the frozen legacy vm_status is not rewritten.
 * VMs of missions with an active job are never touched, however long they
 * have been deploying - the running worker owns them.
 *
 * SKIP LOCKED for the same reason as the reaper: a concurrent import callback
 * holds row locks on its mission's VMs, and the sweep must neither block on it
 * nor deadlock; skipped rows converge on the next interval.
 *
 * @return array<int, array{vm_id:int, mission_id:int, vm_name:string}>
 */
function repo_sweep_orphaned_deploying_vms(mysqli $db): array
{
    $deploying = VIRTUSPHERE_LIFECYCLE_DEPLOYING;
    $failedLifecycle = VIRTUSPHERE_LIFECYCLE_FAILED;
    $failedMecm = VIRTUSPHERE_MECM_FAILED;
    $note = 'convergence sweep: stuck in deploying without an active deploy job';

    return repo_transaction($db, static function () use ($db, $deploying, $failedLifecycle, $failedMecm, $note): array {
        $queued = VIRTUSPHERE_DEPLOY_STATUS_QUEUED;
        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $stmt = $db->prepare(
            'SELECT v.id, v.mission_id, v.vm_name, v.vm_status FROM deploy_vms v
             WHERE v.lifecycle_state = ?
               AND NOT EXISTS (SELECT 1 FROM deploy_jobs j WHERE j.mission_id = v.mission_id AND j.status IN (?, ?))
             ORDER BY v.id
             FOR UPDATE SKIP LOCKED'
        );
        $stmt->bind_param('sss', $deploying, $queued, $running);
        $stmt->execute();
        $vms = repo_fetch_all($stmt->get_result());

        $swept = [];
        foreach ($vms as $vm) {
            $vmId = (int) $vm['id'];
            $stmt = $db->prepare('UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, updated_at = NOW() WHERE id = ? AND lifecycle_state = ?');
            $stmt->bind_param('ssis', $failedLifecycle, $failedMecm, $vmId, $deploying);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                continue;
            }
            repo_record_vm_status_event($db, $vmId, $failedLifecycle, $failedMecm, (string) ($vm['vm_status'] ?? ''), $note);
            $swept[] = ['vm_id' => $vmId, 'mission_id' => (int) $vm['mission_id'], 'vm_name' => (string) $vm['vm_name']];
        }

        return $swept;
    });
}

function repo_finish_deploy_job(mysqli $db, int $jobId, string $workerId, string $status, ?string $lastError = null): bool
{
    if (!in_array($status, VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true)) {
        throw new InvalidArgumentException('Deploy job finish status must be terminal.');
    }

    $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
    $stmt = $db->prepare('UPDATE deploy_jobs SET status = ?, last_error = ?, locked_at = NULL, locked_by = NULL, heartbeat_at = NULL, updated_at = NOW() WHERE id = ? AND locked_by = ? AND status = ?');
    $stmt->bind_param('ssiss', $status, $lastError, $jobId, $workerId, $running);

    return $stmt->execute() && $stmt->affected_rows === 1;
}

function repo_append_deploy_job_log(mysqli $db, int $jobId, string $stream, string $line): int
{
    return repo_transaction($db, static fn (): int => repo_insert_deploy_job_log_unlocked($db, $jobId, $stream, $line));
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
    $stmt = $db->prepare('SELECT id, type, name, host, port, username, secret_ciphertext FROM deploy_credentials WHERE id = ? LIMIT 1');
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

function repo_insert_deploy_job_log_unlocked(mysqli $db, int $jobId, string $stream, string $line): int
{
    if (!in_array($stream, VIRTUSPHERE_DEPLOY_LOG_STREAMS, true)) {
        throw new InvalidArgumentException('Invalid deploy log stream.');
    }

    $stmt = $db->prepare('SELECT seq FROM deploy_job_logs WHERE job_id = ? ORDER BY seq DESC LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $seq = (int) ($row['seq'] ?? 0) + 1;

    $stmt = $db->prepare('INSERT INTO deploy_job_logs (job_id, seq, stream, line) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('iiss', $jobId, $seq, $stream, $line);
    $stmt->execute();

    return $seq;
}
