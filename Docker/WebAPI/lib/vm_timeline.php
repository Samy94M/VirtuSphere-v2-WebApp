<?php

declare(strict_types=1);

/**
 * The VM timeline (plan ST-2, section 2): one pure mixer over the four evidence
 * sources of a VM, and the catalog that turns the writers' technical status notes
 * into words. No database and no clock of its own: every time is the row's value.
 */

require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/deploy_constants.php';

const VIRTUSPHERE_VM_TIMELINE_LIMIT = 20;

const VIRTUSPHERE_VM_TIMELINE_SOURCE_STATUS = 'status';
const VIRTUSPHERE_VM_TIMELINE_SOURCE_JOB = 'job';
const VIRTUSPHERE_VM_TIMELINE_SOURCE_PACKAGE = 'package';
const VIRTUSPHERE_VM_TIMELINE_SOURCE_CLIENT = 'client';

/** The order of equal times: status before job before package before client. */
const VIRTUSPHERE_VM_TIMELINE_SOURCES = [
    VIRTUSPHERE_VM_TIMELINE_SOURCE_STATUS,
    VIRTUSPHERE_VM_TIMELINE_SOURCE_JOB,
    VIRTUSPHERE_VM_TIMELINE_SOURCE_PACKAGE,
    VIRTUSPHERE_VM_TIMELINE_SOURCE_CLIENT,
];

// The entry kinds. A job and a package run each yield one or two of them.
const VIRTUSPHERE_VM_TIMELINE_STATUS = 'status';
const VIRTUSPHERE_VM_TIMELINE_JOB_STARTED = 'job_started';
const VIRTUSPHERE_VM_TIMELINE_JOB_FINISHED = 'job_finished';
const VIRTUSPHERE_VM_TIMELINE_PACKAGE_RECEIVED = 'package_received';
const VIRTUSPHERE_VM_TIMELINE_PACKAGE_COMPLETED = 'package_completed';
const VIRTUSPHERE_VM_TIMELINE_CLIENT = 'client';

/** Exact status notes as the writers store them => suffix of the vm_timeline.note_* key. */
const VIRTUSPHERE_VM_STATUS_NOTES = [
    'created from portal' => 'created_from_portal',
    'imported from file' => 'imported_from_file',
    'cloned from template' => 'cloned_from_template',
    'captured from mission' => 'captured_from_mission',
    'mecm id reset from portal' => 'mecm_id_reset',
    'mecm update id' => 'mecm_update_id',
    'mecm client ready acknowledgement' => 'mecm_client_ack',
    'ansible mac import' => 'ansible_mac_import',
    'MECM pending observation restarted' => 'mecm_pending_watch_restarted',
    'OS installation observation restarted' => 'os_install_watch_restarted',
    'queued for MECM assignment transfer from portal' => 'mecm_transfer_queued',
    'convergence sweep: restore completed job bracket' => 'sweep_restore',
    'convergence sweep: apply recorded job/VM outcome' => 'sweep_apply',
];

/** The worker's 'deploy job <id> <suffix>' notes: suffix => suffix of the vm_timeline.note_* key. */
const VIRTUSPHERE_VM_STATUS_JOB_NOTES = [
    'started' => 'job_started',
    'failed' => 'job_failed',
    'cancelled' => 'job_cancelled',
    'mac import partial' => 'job_mac_import_partial',
    'succeeded without export step; lifecycle restored' => 'job_restored',
    'reaped after stale heartbeat' => 'job_reaped',
    'cancelled; converged by the reaper after a stale heartbeat' => 'job_cancelled_reaped',
    'stopped before its pipeline because the create section did not complete' => 'job_create_incomplete',
];

/** The lifecycle sentence the forward writer appends, even to an empty note. */
const VIRTUSPHERE_VM_STATUS_KEPT_PATTERN = '/\A(.*) \(lifecycle kept at ([a-z_]+); a later state is not overwritten\)\z/s';

const VIRTUSPHERE_VM_STATUS_JOB_PATTERN = '/\Adeploy job (\d+) (.+)\z/s';

/**
 * Merges the four sources of one VM, newest first, and cuts the result to $limit.
 *
 * An entry without a usable time is never given an invented one: it stands at the
 * end, in source and input order. `truncated` is true when more entries exist than
 * fit, or when a source reached $sourceLimit rows and may have been cut by its reader.
 *
 * @param list<array<string,mixed>> $statusEvents repo_vm_status_events()
 * @param list<array<string,mixed>> $jobs repo_vm_timeline_jobs()
 * @param list<array<string,mixed>> $packageRuns repo_package_runs_for_vm()
 * @param list<array<string,mixed>> $clientEvents repo_client_events_for_vm()
 * @return array{entries:list<array{at:string,source:string,kind:string,row:array<string,mixed>}>, truncated:bool}
 */
function virtusphere_vm_timeline(array $statusEvents, array $jobs, array $packageRuns, array $clientEvents, int $limit = VIRTUSPHERE_VM_TIMELINE_LIMIT, int $sourceLimit = VIRTUSPHERE_VM_TIMELINE_LIMIT): array
{
    $candidates = [];
    $add = static function (string $source, string $kind, string $at, array $row) use (&$candidates): void {
        $candidates[] = [
            'at' => $at,
            'time' => virtusphere_vm_timeline_time($at),
            'source' => $source,
            'kind' => $kind,
            'row' => $row,
            'seq' => count($candidates),
        ];
    };

    foreach ($statusEvents as $row) {
        $add(VIRTUSPHERE_VM_TIMELINE_SOURCE_STATUS, VIRTUSPHERE_VM_TIMELINE_STATUS, (string) ($row['created_at'] ?? ''), $row);
    }
    foreach ($jobs as $job) {
        $started = (string) ($job['started_at'] ?? '');
        $add(VIRTUSPHERE_VM_TIMELINE_SOURCE_JOB, VIRTUSPHERE_VM_TIMELINE_JOB_STARTED, $started !== '' ? $started : (string) ($job['created_at'] ?? ''), $job);
        if (in_array((string) ($job['status'] ?? ''), VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true)) {
            $add(VIRTUSPHERE_VM_TIMELINE_SOURCE_JOB, VIRTUSPHERE_VM_TIMELINE_JOB_FINISHED, (string) ($job['updated_at'] ?? ''), $job);
        }
    }
    foreach ($packageRuns as $run) {
        $add(VIRTUSPHERE_VM_TIMELINE_SOURCE_PACKAGE, VIRTUSPHERE_VM_TIMELINE_PACKAGE_RECEIVED, (string) ($run['first_received_at'] ?? ''), $run);
        $completed = (string) ($run['completed_received_at'] ?? '');
        if ($completed !== '') {
            $add(VIRTUSPHERE_VM_TIMELINE_SOURCE_PACKAGE, VIRTUSPHERE_VM_TIMELINE_PACKAGE_COMPLETED, $completed, $run);
        }
    }
    foreach ($clientEvents as $event) {
        $add(VIRTUSPHERE_VM_TIMELINE_SOURCE_CLIENT, VIRTUSPHERE_VM_TIMELINE_CLIENT, (string) ($event['created_at'] ?? ''), $event);
    }

    usort($candidates, static function (array $a, array $b): int {
        if (($a['time'] === null) !== ($b['time'] === null)) {
            return $a['time'] === null ? 1 : -1;
        }
        if ($a['time'] !== null && $a['time'] !== $b['time']) {
            return $b['time'] <=> $a['time'];
        }
        $source = array_search($a['source'], VIRTUSPHERE_VM_TIMELINE_SOURCES, true) <=> array_search($b['source'], VIRTUSPHERE_VM_TIMELINE_SOURCES, true);

        return $source !== 0 ? $source : $a['seq'] <=> $b['seq'];
    });

    $truncated = count($candidates) > $limit
        || count($statusEvents) >= $sourceLimit
        || count($jobs) >= $sourceLimit
        || count($packageRuns) >= $sourceLimit
        || count($clientEvents) >= $sourceLimit;

    $entries = [];
    foreach (array_slice($candidates, 0, $limit) as $candidate) {
        $entries[] = [
            'at' => $candidate['at'],
            'source' => $candidate['source'],
            'kind' => $candidate['kind'],
            'row' => $candidate['row'],
        ];
    }

    return ['entries' => $entries, 'truncated' => $truncated];
}

/** Unix time of a stored UTC timestamp, or null for an empty or unparsable one. */
function virtusphere_vm_timeline_time(string $at): ?int
{
    if (trim($at) === '') {
        return null;
    }
    $timestamp = strtotime($at . ' UTC');

    return $timestamp === false ? null : $timestamp;
}

/**
 * The catalog reading of one status note, or null for an empty note. The writer's
 * lifecycle sentence is recognised on the untrimmed note (its leading space is the
 * empty base); the base is trimmed and resolved afterwards. `key` is the base's
 * catalog key, null when the base is empty or unknown. `kept` is the state token of
 * the sentence, unknown tokens included.
 *
 * @return array{key:?string, params:array<string,string|int>, kept:?string, raw:string}|null
 */
function virtusphere_vm_status_note(?string $note): ?array
{
    $base = rtrim((string) $note);
    $kept = null;
    if (preg_match(VIRTUSPHERE_VM_STATUS_KEPT_PATTERN, $base, $match) === 1) {
        $base = $match[1];
        $kept = $match[2];
    }
    $raw = trim($base);
    if ($raw === '' && $kept === null) {
        return null;
    }
    $resolved = virtusphere_vm_status_note_key($raw);

    return ['key' => $resolved['key'], 'params' => $resolved['params'], 'kept' => $kept, 'raw' => $raw];
}

/**
 * The catalog key and its parameters of a trimmed base note.
 *
 * @return array{key:?string, params:array<string,string|int>}
 */
function virtusphere_vm_status_note_key(string $raw): array
{
    if ($raw === '') {
        return ['key' => null, 'params' => []];
    }
    if (array_key_exists($raw, VIRTUSPHERE_VM_STATUS_NOTES)) {
        return ['key' => 'vm_timeline.note_' . VIRTUSPHERE_VM_STATUS_NOTES[$raw], 'params' => []];
    }
    if (preg_match(VIRTUSPHERE_VM_STATUS_JOB_PATTERN, $raw, $match) === 1
        && array_key_exists($match[2], VIRTUSPHERE_VM_STATUS_JOB_NOTES)
    ) {
        return [
            'key' => 'vm_timeline.note_' . VIRTUSPHERE_VM_STATUS_JOB_NOTES[$match[2]],
            'params' => ['job' => (int) $match[1]],
        ];
    }

    return ['key' => null, 'params' => []];
}

/**
 * The wording of one status note in $locale, '' for an empty note. An unknown note,
 * or a key missing in this locale, shows the trimmed note itself. The lifecycle
 * sentence follows the base after one space; an unknown kept state gets the neutral
 * label, so the raw token never reaches the reader.
 */
function virtusphere_vm_status_note_text(?string $note, string $locale): string
{
    $parsed = virtusphere_vm_status_note($note);
    if ($parsed === null) {
        return '';
    }

    $base = $parsed['raw'];
    if ($parsed['key'] !== null) {
        $text = Lang::getIn($locale, $parsed['key'], $parsed['params']);
        if ($text !== $parsed['key']) {
            $base = $text;
        }
    }
    if ($parsed['kept'] === null) {
        return $base;
    }

    $stateKey = 'status.lifecycle_' . $parsed['kept'];
    $state = Lang::getIn($locale, $stateKey);
    if ($state === $stateKey) {
        $state = Lang::getIn($locale, 'status.lifecycle_unknown');
    }
    $sentence = Lang::getIn($locale, 'vm_timeline.note_lifecycle_kept', ['state' => $state]);

    return $base === '' ? $sentence : $base . ' ' . $sentence;
}
