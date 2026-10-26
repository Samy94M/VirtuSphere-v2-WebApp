<?php

declare(strict_types=1);

/**
 * Phase and findings of a VM, derived at read time from stored evidence (plan
 * "Phase und Befund", 20.10.2026). Pure: no database, no clock of its own, no
 * writer. Nothing here changes `vm_status`, `lifecycle_state` or
 * `mecm_sync_state`; they stay stored and on the wire (ADR-0008), and the raw
 * x/5 value remains visible as the technical tooltip and in the editor.
 *
 * The two closed vocabularies carry their badge colour here, because the colour
 * belongs to the phase state machine (lib/status.php has no budget left); the
 * visible words come from lib/portal_status_display.php (A26).
 *
 * No conclusion from silence (ADR-0038): a phase is granted only for a report
 * that arrived, and a missing report produces at most a "without evidence"
 * finding, never a failure. A failed job does not move the phase backwards; it
 * produces the finding and the phase keeps its last evidence.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_create_constants.php';
require_once __DIR__ . '/status.php';
require_once __DIR__ . '/vm_progress.php';
require_once __DIR__ . '/vm_inventory_observation.php';

// --- Phases (rank = index in VIRTUSPHERE_VM_PHASES plus one) -----------------
const VIRTUSPHERE_VM_PHASE_CREATED = 'created';
const VIRTUSPHERE_VM_PHASE_IN_PROGRESS = 'in_progress';
const VIRTUSPHERE_VM_PHASE_ON_ESXI = 'on_esxi';
const VIRTUSPHERE_VM_PHASE_MAC_KNOWN = 'mac_known';
const VIRTUSPHERE_VM_PHASE_HANDED_TO_MECM = 'handed_to_mecm';
const VIRTUSPHERE_VM_PHASE_WINDOWS_REPORTS = 'windows_reports';
const VIRTUSPHERE_VM_PHASE_CLIENT_DONE = 'client_done';
// A template VM has no phase: its mission is never deployed.
const VIRTUSPHERE_VM_PHASE_TEMPLATE = 'template';

const VIRTUSPHERE_VM_PHASES = [
    VIRTUSPHERE_VM_PHASE_CREATED,
    VIRTUSPHERE_VM_PHASE_IN_PROGRESS,
    VIRTUSPHERE_VM_PHASE_ON_ESXI,
    VIRTUSPHERE_VM_PHASE_MAC_KNOWN,
    VIRTUSPHERE_VM_PHASE_HANDED_TO_MECM,
    VIRTUSPHERE_VM_PHASE_WINDOWS_REPORTS,
    VIRTUSPHERE_VM_PHASE_CLIENT_DONE,
];

// --- Findings, in severity order (the list order IS the severity) -----------
const VIRTUSPHERE_VM_FINDING_CREATE_UNCERTAIN = 'create_uncertain';
const VIRTUSPHERE_VM_FINDING_JOB_FAILED = 'job_failed';
const VIRTUSPHERE_VM_FINDING_MISSING_FROM_INVENTORY = 'missing_from_inventory';
const VIRTUSPHERE_VM_FINDING_JOB_RUNNING = 'job_running';
const VIRTUSPHERE_VM_FINDING_OLD_MECM_DEVICE = 'old_mecm_device';
const VIRTUSPHERE_VM_FINDING_MECM_PENDING_OVERDUE = 'mecm_pending_overdue';
const VIRTUSPHERE_VM_FINDING_MECM_PENDING = 'mecm_pending';
const VIRTUSPHERE_VM_FINDING_POWERED_OFF = 'powered_off';
const VIRTUSPHERE_VM_FINDING_OS_INSTALL_OVERDUE = 'os_install_overdue';
const VIRTUSPHERE_VM_FINDING_PXE_UNWATCHED = 'pxe_unwatched';
const VIRTUSPHERE_VM_FINDING_OS_INSTALL_WATCHING = 'os_install_watching';
const VIRTUSPHERE_VM_FINDING_CLIENT_PHASE_FAILED = 'client_phase_failed';
const VIRTUSPHERE_VM_FINDING_CLIENT_PHASE_UNCONFIRMED = 'client_phase_unconfirmed';
const VIRTUSPHERE_VM_FINDING_PACKAGE_RUN_FAILED = 'package_run_failed';
const VIRTUSPHERE_VM_FINDING_MECM_SYNC_FAILED = 'mecm_sync_failed';

/** kind => badge variant; the key order is the severity order. */
const VIRTUSPHERE_VM_FINDINGS = [
    VIRTUSPHERE_VM_FINDING_CREATE_UNCERTAIN => 'danger',
    VIRTUSPHERE_VM_FINDING_JOB_FAILED => 'danger',
    VIRTUSPHERE_VM_FINDING_MISSING_FROM_INVENTORY => 'danger',
    VIRTUSPHERE_VM_FINDING_JOB_RUNNING => 'info',
    VIRTUSPHERE_VM_FINDING_OLD_MECM_DEVICE => 'warning',
    VIRTUSPHERE_VM_FINDING_MECM_PENDING_OVERDUE => 'warning',
    VIRTUSPHERE_VM_FINDING_MECM_PENDING => 'info',
    VIRTUSPHERE_VM_FINDING_POWERED_OFF => 'warning',
    VIRTUSPHERE_VM_FINDING_OS_INSTALL_OVERDUE => 'warning',
    VIRTUSPHERE_VM_FINDING_PXE_UNWATCHED => 'info',
    VIRTUSPHERE_VM_FINDING_OS_INSTALL_WATCHING => 'info',
    VIRTUSPHERE_VM_FINDING_CLIENT_PHASE_FAILED => 'warning',
    VIRTUSPHERE_VM_FINDING_CLIENT_PHASE_UNCONFIRMED => 'info',
    VIRTUSPHERE_VM_FINDING_PACKAGE_RUN_FAILED => 'warning',
    VIRTUSPHERE_VM_FINDING_MECM_SYNC_FAILED => 'warning',
];

/** The closed set of places a finding may point to (the presenter builds the href). */
const VIRTUSPHERE_VM_FINDING_LINK_JOB_LOG = 'job_log';
const VIRTUSPHERE_VM_FINDING_LINK_DEPLOY = 'deploy';
const VIRTUSPHERE_VM_FINDING_LINK_SYSTEM_STATUS_MECM = 'system_status_mecm';
const VIRTUSPHERE_VM_FINDING_LINK_SYSTEM_STATUS_ESXI = 'system_status_esxi';
const VIRTUSPHERE_VM_FINDING_LINK_VM_EDITOR = 'vm_editor';
const VIRTUSPHERE_VM_FINDING_LINK_PACKAGE_RUN = 'package_run';

/** The inventory power state that contradicts an installing or reporting VM. */
const VIRTUSPHERE_VM_INVENTORY_POWERED_OFF = 'poweredOff';

/**
 * The phase of one VM; the first rule that matches wins (plan section 3).
 *
 * $evidence carries `is_template` and `client_phases` (phase => latest event
 * row or null, as repo_client_phase_summary() shapes it); everything else is
 * read from the VM row. Rules 4 to 6 are the predicates of
 * virtusphere_legacy_status_from_states(), so the stored x/5 value stays
 * explainable from the phase.
 *
 * @param array<string,mixed> $vm
 * @param array<string,mixed> $evidence
 */
function virtusphere_vm_phase(array $vm, array $evidence = []): string
{
    if (!empty($evidence['is_template'])) {
        return VIRTUSPHERE_VM_PHASE_TEMPLATE;
    }
    $lifecycle = (string) ($vm['lifecycle_state'] ?? '');
    $mecm = (string) ($vm['mecm_sync_state'] ?? '');

    if ($lifecycle === VIRTUSPHERE_LIFECYCLE_OS_INSTALLED) {
        return virtusphere_vm_client_phases_done((array) ($evidence['client_phases'] ?? []))
            ? VIRTUSPHERE_VM_PHASE_CLIENT_DONE
            : VIRTUSPHERE_VM_PHASE_WINDOWS_REPORTS;
    }
    if ($lifecycle === VIRTUSPHERE_LIFECYCLE_OS_INSTALLING || $mecm === VIRTUSPHERE_MECM_SYNC_REGISTERED) {
        return VIRTUSPHERE_VM_PHASE_HANDED_TO_MECM;
    }
    if ($lifecycle === VIRTUSPHERE_LIFECYCLE_DEPLOYED || $mecm === VIRTUSPHERE_MECM_SYNC_PENDING) {
        return VIRTUSPHERE_VM_PHASE_MAC_KNOWN;
    }
    if (virtusphere_vm_is_bound($vm)) {
        return VIRTUSPHERE_VM_PHASE_ON_ESXI;
    }
    if ($lifecycle === VIRTUSPHERE_LIFECYCLE_DEPLOYING) {
        return VIRTUSPHERE_VM_PHASE_IN_PROGRESS;
    }

    return VIRTUSPHERE_VM_PHASE_CREATED;
}

/**
 * The binding predicate, exactly as the SQL twin states it: a non-empty stored
 * instance UUID. No trim, because the twin cannot trim either and the writers
 * never store whitespace (K17).
 *
 * @param array<string,mixed> $vm
 */
function virtusphere_vm_is_bound(array $vm): bool
{
    return (string) ($vm['vm_instance_uuid'] ?? '') !== '';
}

/**
 * All four client phases have `finished` as their latest received event.
 *
 * @param array<string,array<string,mixed>|null> $clientPhases
 */
function virtusphere_vm_client_phases_done(array $clientPhases): bool
{
    foreach (VIRTUSPHERE_CLIENT_PHASES as $phase) {
        $latest = $clientPhases[$phase] ?? null;
        if (!is_array($latest) || (string) ($latest['event'] ?? '') !== VIRTUSPHERE_CLIENT_EVENT_FINISHED) {
            return false;
        }
    }

    return true;
}

/**
 * Sort rank of a phase: 1 to 7 along the chain, 0 for a template. An unknown
 * phase throws, like virtusphere_lifecycle_rank(): a silent default would rank
 * the state it knows least about as either first or last.
 */
function virtusphere_vm_phase_rank(string $phase): int
{
    if ($phase === VIRTUSPHERE_VM_PHASE_TEMPLATE) {
        return 0;
    }
    $index = array_search($phase, VIRTUSPHERE_VM_PHASES, true);
    if ($index === false) {
        throw new InvalidArgumentException('Unranked VM phase: ' . $phase);
    }

    return $index + 1;
}

function virtusphere_vm_phase_meta(string $phase): array
{
    return match ($phase) {
        VIRTUSPHERE_VM_PHASE_CREATED, VIRTUSPHERE_VM_PHASE_TEMPLATE => ['badge' => 'neutral'],
        VIRTUSPHERE_VM_PHASE_IN_PROGRESS => ['badge' => 'warning'],
        VIRTUSPHERE_VM_PHASE_ON_ESXI, VIRTUSPHERE_VM_PHASE_MAC_KNOWN, VIRTUSPHERE_VM_PHASE_HANDED_TO_MECM => ['badge' => 'info'],
        VIRTUSPHERE_VM_PHASE_WINDOWS_REPORTS, VIRTUSPHERE_VM_PHASE_CLIENT_DONE => ['badge' => 'success'],
        default => ['badge' => 'neutral'],
    };
}

/** @param array<string,mixed> $finding one entry of virtusphere_vm_findings() */
function virtusphere_vm_finding_meta(array $finding): array
{
    return ['badge' => VIRTUSPHERE_VM_FINDINGS[(string) ($finding['kind'] ?? '')] ?? 'neutral'];
}

/**
 * The phase rank as one SQL CASE over deploy_vms (alias $vm), the twin of
 * virtusphere_vm_phase() for sorting and later counters. Rule 7 reads the
 * client events through a correlated subquery over the (vm_id, phase, id)
 * index, so the twin is exact and not "phase 6 or 7". Templates are a mission
 * property and are not ranked here; the overview marks them with their badge.
 *
 * The literals are PHP constants of this module, never request text, and each
 * is checked against a closed character class before it enters the string.
 */
function virtusphere_vm_phase_sql(string $vm = 'v'): string
{
    $lit = static function (string $token): string {
        if (preg_match('/\A[a-z_]+\z/', $token) !== 1) {
            throw new InvalidArgumentException('Not a SQL-safe state token: ' . $token);
        }

        return "'" . $token . "'";
    };
    $phases = implode(', ', array_map($lit, VIRTUSPHERE_CLIENT_PHASES));
    $finishedPhases = '(SELECT COUNT(DISTINCT e.phase) FROM deploy_client_events e'
        . ' WHERE e.vm_id = ' . $vm . '.id AND e.event = ' . $lit(VIRTUSPHERE_CLIENT_EVENT_FINISHED)
        . ' AND e.phase IN (' . $phases . ')'
        . ' AND NOT EXISTS (SELECT 1 FROM deploy_client_events n WHERE n.vm_id = e.vm_id AND n.phase = e.phase AND n.id > e.id))';

    return 'CASE'
        . ' WHEN ' . $vm . '.lifecycle_state = ' . $lit(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED)
            . ' AND ' . $finishedPhases . ' = ' . count(VIRTUSPHERE_CLIENT_PHASES) . ' THEN ' . virtusphere_vm_phase_rank(VIRTUSPHERE_VM_PHASE_CLIENT_DONE)
        . ' WHEN ' . $vm . '.lifecycle_state = ' . $lit(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED) . ' THEN ' . virtusphere_vm_phase_rank(VIRTUSPHERE_VM_PHASE_WINDOWS_REPORTS)
        . ' WHEN ' . $vm . '.lifecycle_state = ' . $lit(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING)
            . ' OR ' . $vm . '.mecm_sync_state = ' . $lit(VIRTUSPHERE_MECM_SYNC_REGISTERED) . ' THEN ' . virtusphere_vm_phase_rank(VIRTUSPHERE_VM_PHASE_HANDED_TO_MECM)
        . ' WHEN ' . $vm . '.lifecycle_state = ' . $lit(VIRTUSPHERE_LIFECYCLE_DEPLOYED)
            . ' OR ' . $vm . '.mecm_sync_state = ' . $lit(VIRTUSPHERE_MECM_SYNC_PENDING) . ' THEN ' . virtusphere_vm_phase_rank(VIRTUSPHERE_VM_PHASE_MAC_KNOWN)
        . ' WHEN ' . $vm . ".vm_instance_uuid IS NOT NULL AND " . $vm . ".vm_instance_uuid <> '' THEN " . virtusphere_vm_phase_rank(VIRTUSPHERE_VM_PHASE_ON_ESXI)
        . ' WHEN ' . $vm . '.lifecycle_state = ' . $lit(VIRTUSPHERE_LIFECYCLE_DEPLOYING) . ' THEN ' . virtusphere_vm_phase_rank(VIRTUSPHERE_VM_PHASE_IN_PROGRESS)
        . ' ELSE ' . virtusphere_vm_phase_rank(VIRTUSPHERE_VM_PHASE_CREATED)
        . ' END';
}

/**
 * The findings of one VM in severity order (plan section 4). Each entry is
 * `kind`, `severity`, `params` (raw values: tokens and server timestamps, the
 * label owner formats them) and `link` (a closed target plus its ids).
 *
 * $evidence, as repo_vm_evidence_for_vm() shapes it: `is_template`,
 * `last_job` (the deploy_jobs row of the newest journal row of this VM, with
 * `id`, `status`, `mode`, `started_at`, `created_at`, `updated_at`, or null),
 * `create_uncertain` (list of `job_id`/`position`), `client_phases`,
 * `last_package_run` (`run_id`, `project_name`, `package_version`,
 * `completed_received_at`, `wrapper_result`, `reported_failures`, or null) and
 * `inventory` of the bound access (`fresh`, `answered`, `fresh_at`, `vms` as
 * instance UUID => `power_state`), or null when there is none.
 *
 * Time findings need a valid, non-future server timestamp; without one the
 * state finding stays and the time is simply not claimed (ADR-0038).
 *
 * @param array<string,mixed> $vm
 * @param array<string,mixed> $evidence
 * @return list<array{kind:string,severity:string,params:array<string,mixed>,link:array<string,mixed>}>
 */
function virtusphere_vm_findings(array $vm, array $evidence = [], ?int $now = null): array
{
    if (!empty($evidence['is_template'])) {
        return [];
    }
    $current = $now ?? time();
    $lifecycle = (string) ($vm['lifecycle_state'] ?? '');
    $mecm = (string) ($vm['mecm_sync_state'] ?? '');
    $phase = virtusphere_vm_phase($vm, $evidence);
    $job = is_array($evidence['last_job'] ?? null) ? $evidence['last_job'] : null;
    $jobId = $job === null ? 0 : (int) ($job['id'] ?? 0);
    $jobLink = $jobId > 0
        ? ['target' => VIRTUSPHERE_VM_FINDING_LINK_JOB_LOG, 'job_id' => $jobId]
        : ['target' => VIRTUSPHERE_VM_FINDING_LINK_DEPLOY];
    $findings = [];
    $add = static function (string $kind, array $params, array $link) use (&$findings): void {
        $findings[] = ['kind' => $kind, 'severity' => VIRTUSPHERE_VM_FINDINGS[$kind], 'params' => $params, 'link' => $link];
    };

    // 1: an unresolved Create unit (ADR-0041): the outcome on the host is unknown.
    $uncertain = (array) ($evidence['create_uncertain'] ?? []);
    if ($uncertain !== [] && is_array($uncertain[0] ?? null)) {
        $unit = $uncertain[0];
        $add(VIRTUSPHERE_VM_FINDING_CREATE_UNCERTAIN, ['job_id' => (int) ($unit['job_id'] ?? 0), 'position' => (int) ($unit['position'] ?? 0)], [
            'target' => VIRTUSPHERE_VM_FINDING_LINK_JOB_LOG, 'job_id' => (int) ($unit['job_id'] ?? 0),
        ]);
    }

    // 2: the lifecycle says failed; the newest journal job names mode and time
    // when it is terminal. The job is the display, never a second writer.
    if ($lifecycle === VIRTUSPHERE_LIFECYCLE_FAILED) {
        $terminal = $job !== null && in_array((string) ($job['status'] ?? ''), VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true);
        // A reaped job's outcome is unknown, not failed (WK-04/WK-07 A); the text says so.
        $add(VIRTUSPHERE_VM_FINDING_JOB_FAILED, $terminal ? [
            'job_id' => $jobId,
            'mode' => (string) ($job['mode'] ?? ''),
            'time' => virtusphere_vm_finding_time((string) ($job['updated_at'] ?? ''), $current),
        ] + ((string) ($job['status'] ?? '') === VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN ? ['outcome_unknown' => true] : []) : [], $terminal ? $jobLink : ['target' => VIRTUSPHERE_VM_FINDING_LINK_DEPLOY]);
    }

    // 3 and 8: the reading of the bound access (lib/vm_inventory_observation.php);
    // a stale or unanswered pull is not evidence and produces neither finding.
    $inventory = is_array($evidence['inventory'] ?? null) ? $evidence['inventory'] : null;
    $observation = virtusphere_vm_inventory_observation($vm, $inventory);
    if ($observation['state'] === VIRTUSPHERE_VM_INVENTORY_MISSING) {
        $add(VIRTUSPHERE_VM_FINDING_MISSING_FROM_INVENTORY, [
            'host' => (string) ($vm['vm_bound_esxi_host'] ?? ''),
            'time' => virtusphere_vm_finding_time($observation['time'], $current),
        ], ['target' => VIRTUSPHERE_VM_FINDING_LINK_SYSTEM_STATUS_ESXI]);
    }

    // 4: a job is working on this VM (marked, or the newest journal job is still active).
    $jobActive = $job !== null && in_array((string) ($job['status'] ?? ''), VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES, true);
    if ($lifecycle === VIRTUSPHERE_LIFECYCLE_DEPLOYING || $jobActive) {
        $add(VIRTUSPHERE_VM_FINDING_JOB_RUNNING, $jobActive ? [
            'job_id' => $jobId,
            'mode' => (string) ($job['mode'] ?? ''),
            'since' => virtusphere_vm_finding_time((string) (($job['started_at'] ?? '') ?: ($job['created_at'] ?? '')), $current),
        ] : [], $jobActive ? $jobLink : ['target' => VIRTUSPHERE_VM_FINDING_LINK_DEPLOY]);
    }

    // 5: a tombstone of the previous MECM device (ADR-0044).
    if ((string) ($vm['mecm_previous_id'] ?? '') !== '') {
        $add(VIRTUSPHERE_VM_FINDING_OLD_MECM_DEVICE, ['id' => (string) $vm['mecm_previous_id']], ['target' => VIRTUSPHERE_VM_FINDING_LINK_SYSTEM_STATUS_MECM]);
    }

    // 6 and 7: the MECM wait, overdue by the existing clock rule.
    $attention = virtusphere_vm_progress_attention($vm, $current);
    if ($mecm === VIRTUSPHERE_MECM_SYNC_PENDING) {
        $since = virtusphere_vm_finding_time((string) ($vm['mecm_pending_since'] ?? ''), $current);
        if ($attention !== null && $attention['kind'] === VIRTUSPHERE_VM_PROGRESS_MECM_PENDING) {
            $add(VIRTUSPHERE_VM_FINDING_MECM_PENDING_OVERDUE, ['since' => $since, 'hours' => intdiv(VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS, 3600)], ['target' => VIRTUSPHERE_VM_FINDING_LINK_SYSTEM_STATUS_MECM]);
        } else {
            $add(VIRTUSPHERE_VM_FINDING_MECM_PENDING, ['since' => $since], ['target' => VIRTUSPHERE_VM_FINDING_LINK_SYSTEM_STATUS_MECM]);
        }
    }

    // 9 to 11 share the OS-install observation of lib/vm_progress.php, so the
    // link lands on a button that works: the watch exists only for an
    // installing, registered VM, exactly as repo_restart_vm_progress_watch().
    $watchKind = virtusphere_vm_progress_watch_kind($vm);
    $watchSince = trim((string) ($vm['os_install_watch_started_at'] ?? ''));
    $watching = $watchKind === VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING && $watchSince !== '';

    // 8: the inventory says powered off while MECM or Windows should be working.
    if ($observation['state'] === VIRTUSPHERE_VM_INVENTORY_PRESENT
        && $observation['power'] === VIRTUSPHERE_VM_INVENTORY_POWERED_OFF
        && (in_array($phase, [VIRTUSPHERE_VM_PHASE_HANDED_TO_MECM, VIRTUSPHERE_VM_PHASE_WINDOWS_REPORTS], true) || $watching)
    ) {
        $add(VIRTUSPHERE_VM_FINDING_POWERED_OFF, ['time' => virtusphere_vm_finding_time($observation['time'], $current)], ['target' => VIRTUSPHERE_VM_FINDING_LINK_VM_EDITOR]);
    }

    if ($watchKind === VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING) {
        if ($watchSince === '') {
            $add(VIRTUSPHERE_VM_FINDING_PXE_UNWATCHED, [], ['target' => VIRTUSPHERE_VM_FINDING_LINK_VM_EDITOR]);
        } elseif ($attention !== null && $attention['kind'] === VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING) {
            $add(VIRTUSPHERE_VM_FINDING_OS_INSTALL_OVERDUE, ['hours' => intdiv(VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS, 3600)], ['target' => VIRTUSPHERE_VM_FINDING_LINK_VM_EDITOR]);
        } elseif (virtusphere_vm_finding_time($watchSince, $current) !== '') {
            $add(VIRTUSPHERE_VM_FINDING_OS_INSTALL_WATCHING, ['since' => $watchSince], ['target' => VIRTUSPHERE_VM_FINDING_LINK_VM_EDITOR]);
        }
    }

    // 12 and 13: received client phase states; silence is 'none' and says nothing.
    foreach (VIRTUSPHERE_CLIENT_PHASES as $clientPhase) {
        $latest = ($evidence['client_phases'] ?? [])[$clientPhase] ?? null;
        $state = virtusphere_client_phase_state(is_array($latest) ? $latest : null, $current);
        if ($state === 'failed') {
            $add(VIRTUSPHERE_VM_FINDING_CLIENT_PHASE_FAILED, ['phase' => $clientPhase], ['target' => VIRTUSPHERE_VM_FINDING_LINK_VM_EDITOR]);
        } elseif ($state === 'unconfirmed') {
            $add(VIRTUSPHERE_VM_FINDING_CLIENT_PHASE_UNCONFIRMED, ['phase' => $clientPhase, 'minutes' => intdiv(VIRTUSPHERE_CLIENT_PHASE_UNCONFIRMED_AFTER_SECONDS, 60)], ['target' => VIRTUSPHERE_VM_FINDING_LINK_VM_EDITOR]);
        }
    }

    // 14: the newest received package run, completed, with a reported failure (R53).
    $run = is_array($evidence['last_package_run'] ?? null) ? $evidence['last_package_run'] : null;
    if ($run !== null && (string) ($run['completed_received_at'] ?? '') !== ''
        && ((int) ($run['reported_failures'] ?? 0) > 0 || (string) ($run['wrapper_result'] ?? '') === 'failed')
    ) {
        $add(VIRTUSPHERE_VM_FINDING_PACKAGE_RUN_FAILED, [
            'package' => trim((string) ($run['project_name'] ?? '') . ' ' . (string) ($run['package_version'] ?? '')),
        ], ['target' => VIRTUSPHERE_VM_FINDING_LINK_PACKAGE_RUN, 'run_id' => (string) ($run['run_id'] ?? '')]);
    }

    // 15: the MECM axis still carries the worker's failure mark; the sync is open.
    if ($mecm === VIRTUSPHERE_MECM_SYNC_FAILED) {
        $add(VIRTUSPHERE_VM_FINDING_MECM_SYNC_FAILED, [], $jobLink);
    }

    return $findings;
}

/**
 * A server timestamp as a finding parameter: the raw value when it parses and
 * is not in the future, else '' (the label then drops the time clause).
 */
function virtusphere_vm_finding_time(string $value, int $now): string
{
    return virtusphere_evidence_timestamp($value, $now) === null ? '' : $value;
}
