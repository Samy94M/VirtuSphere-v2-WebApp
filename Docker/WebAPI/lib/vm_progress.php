<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/ansible_command_modes.php';
require_once __DIR__ . '/vm_inventory_observation.php';

// The two proofs that a VM was switched on (ADR-0038 amendment 2). They start
// the OS-install clock without a click; the operator's own start is the third
// source and exists only for the display.
const VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START = 'portal_start';
const VIRTUSPHERE_VM_POWER_EVIDENCE_INVENTORY = 'inventory';
const VIRTUSPHERE_VM_POWER_EVIDENCE_SOURCES = [
    VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START,
    VIRTUSPHERE_VM_POWER_EVIDENCE_INVENTORY,
];
const VIRTUSPHERE_VM_WATCH_SOURCE_OPERATOR = 'operator';

// The status notes of the three ways the OS-install clock is set. The note is
// the only stored record of the source, so the writers and the reader below
// share these constants (wording: VIRTUSPHERE_VM_STATUS_NOTES).
const VIRTUSPHERE_VM_WATCH_NOTE_OPERATOR = 'OS installation observation restarted';
const VIRTUSPHERE_VM_WATCH_NOTE_PORTAL_START = 'OS installation observation started after a portal start';
const VIRTUSPHERE_VM_WATCH_NOTE_INVENTORY = 'OS installation observation started after a powered-on inventory reading';
const VIRTUSPHERE_VM_WATCH_NOTES = [
    VIRTUSPHERE_VM_WATCH_NOTE_OPERATOR => VIRTUSPHERE_VM_WATCH_SOURCE_OPERATOR,
    VIRTUSPHERE_VM_WATCH_NOTE_PORTAL_START => VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START,
    VIRTUSPHERE_VM_WATCH_NOTE_INVENTORY => VIRTUSPHERE_VM_POWER_EVIDENCE_INVENTORY,
];

// The clock and its status note are two statements of one transaction; their
// server times may differ by the length of that transaction, never by more.
const VIRTUSPHERE_VM_WATCH_NOTE_MATCH_SECONDS = 5;

/**
 * Return the progress observation that is valid for the VM's current state.
 * Observations and their warnings are display-only; this module never writes
 * lifecycle state, deletes rows or turns elapsed time into a failure.
 *
 * @param array<string,mixed> $vm
 */
function virtusphere_vm_progress_watch_kind(array $vm): ?string
{
    $lifecycle = (string) ($vm['lifecycle_state'] ?? '');
    $mecm = (string) ($vm['mecm_sync_state'] ?? '');

    // MECM pending is watched whatever the lifecycle says (VT-E1 C): a client
    // ACK can reach 5/5 before the device-sync binds the VM, and a VM that is
    // then never bound must not fall silent. repo_vm_progress_attention_sql()
    // is the SQL twin of this decision.
    if ($mecm === VIRTUSPHERE_MECM_SYNC_PENDING) {
        return VIRTUSPHERE_VM_PROGRESS_MECM_PENDING;
    }
    if ($lifecycle === VIRTUSPHERE_LIFECYCLE_OS_INSTALLING && $mecm === VIRTUSPHERE_MECM_SYNC_REGISTERED) {
        return VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING;
    }

    return null;
}

/**
 * Derive an overdue warning from a dedicated server-side timestamp.
 *
 * @param array<string,mixed> $vm
 * @return array{kind:string,since:string,age_seconds:int,threshold_seconds:int}|null
 */
function virtusphere_vm_progress_attention(array $vm, ?int $now = null): ?array
{
    $kind = virtusphere_vm_progress_watch_kind($vm);
    if ($kind === null) {
        return null;
    }

    $field = $kind === VIRTUSPHERE_VM_PROGRESS_MECM_PENDING
        ? 'mecm_pending_since'
        : 'os_install_watch_started_at';
    $threshold = $kind === VIRTUSPHERE_VM_PROGRESS_MECM_PENDING
        ? VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS
        : VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS;
    $since = trim((string) ($vm[$field] ?? ''));
    if ($since === '') {
        return null;
    }

    $parsed = strtotime($since . ' UTC');
    if ($parsed === false) {
        return null;
    }
    $age = ($now ?? time()) - $parsed;
    if ($age <= $threshold) {
        return null;
    }

    return [
        'kind' => $kind,
        'since' => $since,
        'age_seconds' => $age,
        'threshold_seconds' => $threshold,
    ];
}

/**
 * Whether evidence may be older than the VM's current MECM rollout, and since
 * when it must not be (ADR-0038 amendment 2). Read from the stored row, pure.
 *
 * Only a MECM-ID reset starts a new rollout without a deploy job, and it always
 * raises `mecm_rollout_revision`. A VM still at the initial revision was never
 * reset, so everything known about it belongs to its only rollout. For any other
 * revision the evidence must not be older than `mecm_pending_since`: the reset
 * writes that clock, and no later write moves it backwards. The clock exists only
 * while the VM is pending, so the registration callback reads it before its state
 * write. Afterwards the start of the rollout is not stored (`since` is ''), and
 * only evidence that arises in the trigger's own transaction counts (`live`).
 *
 * A stored NULL is the initial revision, exactly as the fence owner reads it
 * (mecm_rollout_fence_revision_current()). A row that was read WITHOUT the
 * column says nothing about resets and is fenced.
 *
 * @param array<string,mixed> $vm mecm_rollout_revision, mecm_sync_state, mecm_pending_since
 * @return array{fenced:bool, since:string}
 */
function virtusphere_vm_rollout_fence(array $vm): array
{
    if (array_key_exists('mecm_rollout_revision', $vm)
        && (int) ($vm['mecm_rollout_revision'] ?? VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL) === VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL
    ) {
        return ['fenced' => false, 'since' => ''];
    }
    $pending = (string) ($vm['mecm_sync_state'] ?? '') === VIRTUSPHERE_MECM_SYNC_PENDING;

    return ['fenced' => true, 'since' => $pending ? trim((string) ($vm['mecm_pending_since'] ?? '')) : ''];
}

/**
 * Whether one piece of evidence belongs to the current rollout. Live evidence
 * always does. Past evidence of a fenced VM needs both times, and a time that
 * does not parse proves nothing.
 *
 * @param array{fenced:bool, since:string} $fence
 */
function virtusphere_vm_evidence_in_rollout(array $fence, string $at, bool $live): bool
{
    if ($live || !$fence['fenced']) {
        return true;
    }
    $since = $fence['since'] === '' ? false : strtotime($fence['since'] . ' UTC');
    $time = trim($at) === '' ? false : strtotime($at . ' UTC');

    return $since !== false && $time !== false && $time >= $since;
}

/**
 * E1: the job ran its start step to the end. A succeeded job whose sequence
 * contains the start playbook, or a job that failed only in the autostart step
 * after it. A failed start step proves nothing for any VM of the job, and the
 * per-VM log lines are not evidence. `partial` is not accepted: a create section
 * that built only some VMs ends partial before the start step.
 *
 * @param array<string,mixed>|null $job status, mode, failed_step, then_autostart
 */
function virtusphere_vm_job_completed_start_step(?array $job): bool
{
    $mode = $job === null ? '' : (string) ($job['mode'] ?? '');
    if ($mode === '' || !in_array($mode, virtusphere_user_deploy_modes(), true)) {
        return false;
    }
    $start = VIRTUSPHERE_PLAYBOOKS['start'];
    $status = (string) ($job['status'] ?? '');
    if ($status === VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED) {
        return in_array($start, ansible_playbooks_for_mode($mode), true);
    }
    if ($status !== VIRTUSPHERE_DEPLOY_STATUS_FAILED || (string) ($job['failed_step'] ?? '') !== VIRTUSPHERE_DEPLOY_MODE_AUTOSTART) {
        return false;
    }
    // The autostart step can only have failed where it follows the start step.
    $sequence = ansible_playbooks_for_mode($mode, true, !empty($job['then_autostart']));
    $startAt = array_search($start, $sequence, true);
    $autostartAt = array_search(VIRTUSPHERE_PLAYBOOKS['autostart'], $sequence, true);

    return $startAt !== false && $autostartAt !== false && $startAt < $autostartAt;
}

/**
 * The proof that a VM is switched on in its current rollout, or null. Pure.
 *
 * $evidence: `start_job` (the job of the VM's newest journal row with `status`,
 * `mode`, `failed_step`, `then_autostart`, `captured_at`, `updated_at` and
 * `live`, or null), `inventory` (the access entry of repo_vm_evidence_inventory()
 * plus `live` and, for a live one, `read_since`, or null) and `fence`
 * (virtusphere_vm_rollout_fence()). `live` marks the evidence
 * that arises in the trigger's own transaction: the job that is ending, the
 * inventory that is being applied.
 *
 * The newer reading decides. A mission job moves the power of its VMs (power
 * cycle, start), so an inventory reading says something only if it was taken
 * after the job of the newest journal row ended (`updated_at` of the terminal
 * job), and nothing while that job is still active. Such a reading overrides a
 * portal start: powered on confirms it (and the portal start stays the named
 * source), powered off or absent from the host takes it back. A stale,
 * unanswered or unbound inventory says nothing either way.
 *
 * @param array<string,mixed> $vm
 * @param array<string,mixed> $evidence
 */
function virtusphere_vm_power_evidence(array $vm, array $evidence): ?string
{
    $fence = is_array($evidence['fence'] ?? null) ? $evidence['fence'] : ['fenced' => true, 'since' => ''];
    $job = is_array($evidence['start_job'] ?? null) ? $evidence['start_job'] : null;
    $inventory = is_array($evidence['inventory'] ?? null) ? $evidence['inventory'] : null;

    $jobLive = $job !== null && !empty($job['live']);
    $jobAt = $job === null ? '' : trim((string) ($job['captured_at'] ?? ''));
    $started = virtusphere_vm_job_completed_start_step($job) && virtusphere_vm_evidence_in_rollout($fence, $jobAt, $jobLive);

    $observation = virtusphere_vm_inventory_observation($vm, $inventory);
    $inventoryLive = $inventory !== null && !empty($inventory['live']);
    // A pull that is being applied read the host at some moment after its own
    // claim (`read_since`); its freshness stamp is the later moment of the apply.
    $readSince = $inventoryLive ? trim((string) ($inventory['read_since'] ?? '')) : '';
    $reads = in_array($observation['state'], [VIRTUSPHERE_VM_INVENTORY_PRESENT, VIRTUSPHERE_VM_INVENTORY_MISSING], true)
        && virtusphere_vm_evidence_in_rollout($fence, $observation['time'], $inventoryLive)
        && virtusphere_vm_reading_follows_job($job, $readSince !== '' ? $readSince : $observation['time']);
    $on = $reads && $observation['power'] === VIRTUSPHERE_VM_INVENTORY_POWERED_ON;
    $off = $reads && ($observation['state'] === VIRTUSPHERE_VM_INVENTORY_MISSING || $observation['power'] === VIRTUSPHERE_VM_INVENTORY_POWERED_OFF);

    if (!$started) {
        return $on ? VIRTUSPHERE_VM_POWER_EVIDENCE_INVENTORY : null;
    }

    return $off ? null : VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START;
}

/**
 * Whether an inventory reading is newer than everything the VM's newest
 * journal job did to its power. No job, no objection. A job that is ending in
 * this very trigger (`live`) is newer than any stored reading, and a job that
 * is still active may switch the VM off again a second later. Otherwise the
 * reading must not be older than the job's last write. $readAt is the earliest
 * moment the host may have been read: the freshness stamp of a stored reading,
 * the claim of the pull for one that is being applied.
 *
 * @param array<string,mixed>|null $job status, updated_at, live
 */
function virtusphere_vm_reading_follows_job(?array $job, string $readAt): bool
{
    if ($job === null) {
        return true;
    }
    if (!empty($job['live']) || in_array((string) ($job['status'] ?? ''), VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES, true)) {
        return false;
    }
    $endedAt = trim((string) ($job['updated_at'] ?? ''));
    $ended = $endedAt === '' ? false : strtotime($endedAt . ' UTC');
    $read = trim($readAt) === '' ? false : strtotime($readAt . ' UTC');

    return $ended !== false && $read !== false && $read >= $ended;
}

/**
 * The source that may start the OS-install clock by itself, or null: only for
 * a registered, installing VM of a real mission whose clock is still empty.
 * The automatic start never restarts a clock; that stays the operator's action.
 *
 * @param array<string,mixed> $vm
 * @param array<string,mixed> $evidence virtusphere_vm_power_evidence() plus `is_template`
 */
function virtusphere_vm_install_watch_auto_source(array $vm, array $evidence): ?string
{
    if (!empty($evidence['is_template'])
        || virtusphere_vm_progress_watch_kind($vm) !== VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING
        || trim((string) ($vm['os_install_watch_started_at'] ?? '')) !== ''
    ) {
        return null;
    }

    return virtusphere_vm_power_evidence($vm, $evidence);
}

/**
 * Who set the running OS-install clock, read from the status history: the
 * newest watch note, if it was written with this clock. Null when the history
 * no longer holds it (retention, or a history row that could not be written).
 *
 * @param list<array<string,mixed>> $statusEvents repo_vm_status_events(), newest first
 * @return 'operator'|'portal_start'|'inventory'|null a value of VIRTUSPHERE_VM_WATCH_NOTES
 */
function virtusphere_vm_install_watch_source(array $statusEvents, string $watchSince): ?string
{
    $since = trim($watchSince) === '' ? false : strtotime($watchSince . ' UTC');
    if ($since === false) {
        return null;
    }
    foreach ($statusEvents as $event) {
        $note = trim((string) ($event['note'] ?? ''));
        if (!array_key_exists($note, VIRTUSPHERE_VM_WATCH_NOTES)) {
            continue;
        }
        $at = strtotime((string) ($event['created_at'] ?? '') . ' UTC');

        return $at !== false && abs($at - $since) <= VIRTUSPHERE_VM_WATCH_NOTE_MATCH_SECONDS
            ? VIRTUSPHERE_VM_WATCH_NOTES[$note]
            : null;
    }

    return null;
}
