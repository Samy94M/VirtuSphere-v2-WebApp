<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../defaults.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../validate.php';

/**
 * Deploy job input: everything that turns operator intent into a normalized,
 * storable payload and a schedule, without touching the database.
 *
 * Kept separate because these are the only functions here a test can call
 * without a connection, and because a payload rule (a clamp, a mode whitelist)
 * has no reason to change when a query or a lock order does. The stored payload
 * is what a retry re-runs, so an out-of-range value must never survive an
 * enqueue - which is why the clamps live at this boundary and not on the page.
 */

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

    // array_keys already yields a list; the intval map keeps the element type
    // explicit for callers that pass it straight into a bind.
    return array_map('intval', array_keys($ids));
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

/**
 * The start step's pause, in seconds. Same shape as the power-cycle wait, and
 * clamped here as well as in ansible_job_payload(): the stored payload is what
 * a retry re-runs, so an out-of-range number must never survive the enqueue.
 * The upper bound keeps the playbook's pause below the worker's SSH idle budget
 * (see VIRTUSPHERE_START_WAIT_SECONDS_MAX).
 */
function deploy_job_normalize_start_wait(mixed $value): int
{
    $wait = (int) ($value ?? VIRTUSPHERE_START_WAIT_SECONDS_DEFAULT);
    if ($wait < VIRTUSPHERE_START_WAIT_SECONDS_MIN) {
        return VIRTUSPHERE_START_WAIT_SECONDS_MIN;
    }
    if ($wait > VIRTUSPHERE_START_WAIT_SECONDS_MAX) {
        return VIRTUSPHERE_START_WAIT_SECONDS_MAX;
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
        'start_wait' => deploy_job_normalize_start_wait($data['start_wait'] ?? null),
        // System-inventory only: a legacy credential can prove its stored
        // certificate under strict validation before the operator activates
        // that mode. Harmless false on mission jobs and old payloads.
        'strict_trust_probe' => deploy_job_bool($data['strict_trust_probe'] ?? false),
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
