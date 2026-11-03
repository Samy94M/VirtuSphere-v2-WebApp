<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../defaults.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../deploy_mode_labels.php';
require_once __DIR__ . '/../deploy_stored_mode.php';
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

/**
 * The remote steps a failed job can name in deploy_jobs.failed_step (K4b,
 * migration 0068): every VIRTUSPHERE_PLAYBOOKS key except `create`, whose
 * section the worker drives per VM and concludes on its own. Mirrored
 * order-exact in the CHECK of struktur.sql and the migration
 * (DeployJobFailedStepContractTest).
 */
const VIRTUSPHERE_DEPLOY_FAILED_STEPS = ['powercycle', 'export', 'start', 'autostart'];

/** The failed_step value for a remote step's playbook; null for anything else. */
function deploy_job_failed_step_for_playbook(?string $playbook): ?string
{
    $step = $playbook === null ? false : array_search($playbook, VIRTUSPHERE_PLAYBOOKS, true);

    return is_string($step) && in_array($step, VIRTUSPHERE_DEPLOY_FAILED_STEPS, true) ? $step : null;
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
    $mode = deploy_job_normalize_mode((string) ($data['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL));

    return [
        'mode' => $mode,
        // K4b: a start job that resumes a full pipeline writes the autostart
        // policy afterwards, under the same mission gate as the full pipeline.
        // Only a start job carries it; any other mode stores false.
        'then_autostart' => $mode === 'start' && deploy_job_bool($data['then_autostart'] ?? false),
        'verbose' => deploy_job_bool($data['verbose'] ?? false),
        'vm_ids' => deploy_job_normalize_vm_ids($data['vm_ids'] ?? []),
        // K16 keeps the skipped IDs beside the runnable scope. In particular,
        // vm_ids=[] plus a non-empty skipped_vm_ids is an explicit no-op scope;
        // an old empty selection without this diagnostic still means all VMs.
        'skipped_vm_ids' => deploy_job_normalize_vm_ids($data['skipped_vm_ids'] ?? []),
        'powercycle_wait' => deploy_job_normalize_wait($data['powercycle_wait'] ?? null),
        'start_wait' => deploy_job_normalize_start_wait($data['start_wait'] ?? null),
        // System-inventory only: a legacy credential can prove its stored
        // certificate under strict validation before the operator activates
        // that mode. Harmless false on mission jobs and old payloads.
        'strict_trust_probe' => deploy_job_bool($data['strict_trust_probe'] ?? false),
    ];
}

/** An explicit empty runnable scope, distinct from a legacy empty selection. */
function deploy_job_payload_has_explicit_empty_scope(array $payload): bool
{
    return deploy_job_normalize_vm_ids($payload['vm_ids'] ?? []) === []
        && deploy_job_normalize_vm_ids($payload['skipped_vm_ids'] ?? []) !== [];
}

/**
 * Only failed, cancelled, partial and outcome_unknown jobs can be re-queued:
 * re-running a succeeded job is the start form's business (it walks the
 * readiness preview), and active jobs are cancelled, not retried. A partial job
 * is terminal with a durable result. The shared evaluation distinguishes a
 * partial Create section from the export-only follow-up of a MAC result. An
 * outcome_unknown job is retryable like a failed one; deploy_retry_blockers()
 * adds a non-blocking finding and the confirmation asks the operator to check
 * ESXi first (WK-04/WK-07 A). Mission-less system jobs (the ESXi inventory
 * pulls) are scheduled by the worker, never retried by hand.
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
        VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN,
    ], true);
}

/**
 * Plans the MAC-result branch of a retry. The shared retry evaluation validates
 * the result protocol and separately recognizes Create summaries before using
 * any plan. NULL means "repeat the old payload
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
 * claim, so there is nothing to diverge from. An outcome_unknown job is read
 * like a failed one: a stored MAC result that is not a plain failure means the
 * import committed something before the silence, so only the export of the
 * original selection repeats.
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

    if (in_array($status, [VIRTUSPHERE_DEPLOY_STATUS_FAILED, VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN], true)
        && $result !== null && $result['outcome'] !== 'failed') {
        return ['mode' => 'export', 'vm_ids' => array_values($originalVmIds), 'scope' => 'original_selection'];
    }

    return null;
}

/**
 * F1 (K4b, user decision 29.10.2026): the retry of a job whose start or
 * autostart step failed continues at that step instead of repeating what
 * succeeded. Asked before deploy_job_retry_plan(); null leaves the plan to it.
 *
 * The failed step comes only from deploy_jobs.failed_step, never from the
 * reason text. Two sources qualify, both `failed`:
 *  - a full pipeline whose MAC export succeeded for EVERY VM. A partial or
 *    missing result keeps today's export path (fail-closed): its VMs are not
 *    all bound, and starting them would run past the step that is missing;
 *  - a start job that resumed a full pipeline (then_autostart) and failed in
 *    its autostart step.
 * Failed at start: mode start over the original selection, plus the autostart
 * step when the mission uses autostart now (the same gate as the full
 * pipeline). Failed at autostart: mode autostart over the original selection.
 * Both modes leave the VM states as the MAC import set them.
 *
 * @param array{outcome:string, successful_vm_ids:list<int>, failed_vm_ids:list<int>}|null $result decoded MAC result
 * @param array<string,mixed> $payload the source job's normalized payload (deploy_job_payload)
 * @return array{mode:string, vm_ids:list<int>, scope:string, then_autostart:bool}|null
 */
function deploy_job_resume_plan(string $status, ?array $result, array $payload, ?string $failedStep, bool $missionAutostart): ?array
{
    if ($status !== VIRTUSPHERE_DEPLOY_STATUS_FAILED || !in_array($failedStep, ['start', VIRTUSPHERE_DEPLOY_MODE_AUTOSTART], true)) {
        return null;
    }
    $mode = (string) ($payload['mode'] ?? '');
    $fromFull = $mode === VIRTUSPHERE_DEPLOY_MODE_FULL && $result !== null && $result['outcome'] === 'success';
    $fromResumedStart = $mode === 'start' && !empty($payload['then_autostart']) && $failedStep === VIRTUSPHERE_DEPLOY_MODE_AUTOSTART;
    if (!$fromFull && !$fromResumedStart) {
        return null;
    }
    $vmIds = array_values(deploy_job_normalize_vm_ids($payload['vm_ids'] ?? []));

    return $failedStep === 'start'
        ? ['mode' => 'start', 'vm_ids' => $vmIds, 'scope' => 'resume_start', 'then_autostart' => $missionAutostart]
        : ['mode' => VIRTUSPHERE_DEPLOY_MODE_AUTOSTART, 'vm_ids' => $vmIds, 'scope' => 'resume_autostart', 'then_autostart' => false];
}

/**
 * The payload of a retry job: the source payload with the evaluated mode,
 * scope and, for a plan, its autostart follow-up. Without a plan the source
 * payload is repeated unchanged, then_autostart included.
 *
 * @param array<string,mixed> $payload raw source payload
 * @param array<string,mixed> $evaluation deploy_retry_blockers()
 * @return array<string,mixed>
 */
function deploy_retry_payload(array $payload, array $evaluation): array
{
    $plan = $evaluation['plan'] ?? null;
    $payload['mode'] = $evaluation['effective_mode'];
    $payload['vm_ids'] = $evaluation['scope_vm_ids'];
    if (is_array($plan)) {
        $payload['then_autostart'] = !empty($plan['then_autostart']);
    }

    return $payload;
}

/** The suffix of the retry job's first log line that names its plan. */
function deploy_retry_plan_note(?array $plan): string
{
    return match ($plan['scope'] ?? null) {
        'failed_vms' => ' (export-only: ' . count($plan['vm_ids']) . ' failed VMs)',
        'original_selection' => ' (export-only: original selection)',
        'resume_start' => ' (resumed at the start step' . (!empty($plan['then_autostart']) ? ', then autostart' : '') . ')',
        'resume_autostart' => ' (resumed at the autostart step)',
        default => '',
    };
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
    if ($timezone === null) {
        require_once __DIR__ . '/../portal_time.php';
        $timezone = portal_timezone();
    }
    $tz = new DateTimeZone($timezone);
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
            // Display names where the portal display layer is loaded (the web
            // request); a CLI caller without it reads the technical keys.
            $modes = function_exists('deploy_mode_label_list')
                ? deploy_mode_label_list(VIRTUSPHERE_DEPLOY_STAGGER_MODES)
                : implode(', ', VIRTUSPHERE_DEPLOY_STAGGER_MODES);
            throw deploy_schedule_error('stagger_minutes', 'validate.deploy_stagger_mode', 'Staggering is only available in the modes :modes.', ['modes' => $modes]);
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
