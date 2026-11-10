<?php

declare(strict_types=1);

/**
 * Restore-drill and checksum-scrub evidence (ADR-0021, amendment 2026-11).
 *
 * Two host scripts write next to backup-status.jsonl, into the same read-only
 * mount, and never into that file: a drill line there would read as a backup
 * run to backup_status_read().
 *
 * - `scripts/restore_test.sh` appends one line to restore-drill.jsonl as its last
 *   step, only after the drill passed and cleaned up (amendment 2, N1b).
 * - `scripts/backup-scrub.sh` re-hashes every retained set against the manifest
 *   backup.sh wrote and replaces backup-scrub.json atomically.
 *
 * Same fail-soft rules as lib/backup_status.php: an absent file, a broken line
 * or a timestamp from the future is no evidence, and no evidence is `unknown`,
 * never `ok`. A drill run on another machine leaves nothing here, so the portal
 * keeps saying `unknown` instead of guessing.
 */

require_once __DIR__ . '/backup_status.php';
require_once __DIR__ . '/status_evidence.php';

const VIRTUSPHERE_RESTORE_DRILL_STATUS_FILE = 'restore-drill.jsonl';
const VIRTUSPHERE_BACKUP_SCRUB_STATUS_FILE = 'backup-scrub.json';

// The runbook asks for a drill at least monthly (docs/operations/backup.md);
// from this age on the System status shows a hint, not an error.
const VIRTUSPHERE_RESTORE_DRILL_DUE_DAYS = 35;

// The two commands the hints name. They write the evidence this module reads;
// the drill records itself, so its command is the drill.
const VIRTUSPHERE_RESTORE_DRILL_RECORD_COMMAND = 'sudo sh scripts/restore_test.sh';
const VIRTUSPHERE_BACKUP_SCRUB_COMMAND = 'sudo sh scripts/backup-scrub.sh';

// A scrub result is one small JSON object; anything larger is not ours.
const VIRTUSPHERE_BACKUP_SCRUB_MAX_BYTES = 65536;
// backup.sh keeps KEEP=14 sets; the list is display only and capped well above.
const VIRTUSPHERE_BACKUP_SCRUB_MAX_LISTED_SETS = 50;

const VIRTUSPHERE_BACKUP_EVIDENCE_OK = 'ok';
const VIRTUSPHERE_BACKUP_EVIDENCE_DUE = 'due';
const VIRTUSPHERE_BACKUP_EVIDENCE_FAILED = 'failed';
const VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN = 'unknown';

/** Path of an evidence file inside the backup status mount. */
function backup_evidence_path(string $file): string
{
    return dirname(VIRTUSPHERE_BACKUP_STATUS_PATH) . '/' . $file;
}

/**
 * A reported Unix epoch, or null when it proves nothing: missing, not a whole
 * positive number, or further in the future than the shared clock-skew allowance.
 */
function backup_evidence_epoch(mixed $value, int $now): ?int
{
    if (is_int($value)) {
        $epoch = $value;
    } elseif (is_string($value) && preg_match('/^[0-9]{1,12}$/', $value) === 1) {
        $epoch = (int) $value;
    } else {
        return null;
    }
    if ($epoch <= 0 || $epoch > $now + VIRTUSPHERE_STATUS_EVIDENCE_FUTURE_SKEW_SECONDS) {
        return null;
    }

    return $epoch;
}

/** A backup set stamp as backup.sh names its files (`YYYYmmdd-HHMMSS`), or ''. */
function backup_evidence_set_stamp(mixed $value): string
{
    return is_string($value) && preg_match('/^[0-9]{8}-[0-9]{6}$/', $value) === 1 ? $value : '';
}

/**
 * The newest successful restore drill.
 *
 * `due` from VIRTUSPHERE_RESTORE_DRILL_DUE_DAYS whole days on; `unknown` when no
 * valid success line exists. The drill writes successes only, so a line with
 * another status is ignored rather than trusted.
 *
 * @param string|null $path Override for tests; defaults to the container mount.
 * @param int|null $now Override for tests; defaults to time().
 * @return array{state:string,ts:int|null,age_days:int|null,backup_ts:string}
 */
function backup_drill_status_read(?string $path = null, ?int $now = null): array
{
    $path ??= backup_evidence_path(VIRTUSPHERE_RESTORE_DRILL_STATUS_FILE);
    $now ??= time();
    $result = [
        'state' => VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN,
        'ts' => null,
        'age_days' => null,
        'backup_ts' => '',
    ];

    if (!is_file($path) || !is_readable($path)) {
        return $result;
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $result;
    }

    $newest = null;
    $newestTs = 0;
    foreach ($lines as $line) {
        $decoded = json_decode(trim($line), true);
        if (!is_array($decoded) || ($decoded['status'] ?? null) !== 'ok') {
            continue;
        }
        $ts = backup_evidence_epoch($decoded['ts'] ?? null, $now);
        if ($ts !== null && $ts >= $newestTs) {
            $newest = $decoded;
            $newestTs = $ts;
        }
    }
    if ($newest === null) {
        return $result;
    }

    $ageDays = intdiv(max(0, $now - $newestTs), 86400);

    return [
        'state' => $ageDays >= VIRTUSPHERE_RESTORE_DRILL_DUE_DAYS
            ? VIRTUSPHERE_BACKUP_EVIDENCE_DUE
            : VIRTUSPHERE_BACKUP_EVIDENCE_OK,
        'ts' => $newestTs,
        'age_days' => $ageDays,
        'backup_ts' => backup_evidence_set_stamp($newest['backup_ts'] ?? null),
    ];
}

/**
 * The last checksum scrub.
 *
 * `failed` as soon as the result names a mismatching set, whatever its status
 * word says; `ok` only for a status `ok` with at least one checked set and no
 * mismatch; anything else is `unknown`.
 *
 * @param string|null $path Override for tests; defaults to the container mount.
 * @param int|null $now Override for tests; defaults to time().
 * @return array{state:string,ts:int|null,sets_checked:int,sets_failed:int,failed_sets:list<string>}
 */
function backup_scrub_status_read(?string $path = null, ?int $now = null): array
{
    $path ??= backup_evidence_path(VIRTUSPHERE_BACKUP_SCRUB_STATUS_FILE);
    $now ??= time();
    $result = [
        'state' => VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN,
        'ts' => null,
        'sets_checked' => 0,
        'sets_failed' => 0,
        'failed_sets' => [],
    ];

    if (!is_file($path) || !is_readable($path)) {
        return $result;
    }
    $raw = @file_get_contents($path, false, null, 0, VIRTUSPHERE_BACKUP_SCRUB_MAX_BYTES);
    if (!is_string($raw)) {
        return $result;
    }
    $decoded = json_decode(trim($raw), true);
    if (!is_array($decoded)) {
        return $result;
    }
    $ts = backup_evidence_epoch($decoded['ts'] ?? null, $now);
    $checked = $decoded['sets_checked'] ?? null;
    $failed = $decoded['sets_failed'] ?? null;
    if ($ts === null || !is_int($checked) || !is_int($failed) || $checked < 1 || $failed < 0 || $failed > $checked) {
        return $result;
    }

    $failedSets = [];
    foreach ((array) ($decoded['failed_sets'] ?? []) as $stamp) {
        $stamp = backup_evidence_set_stamp($stamp);
        if ($stamp !== '' && count($failedSets) < VIRTUSPHERE_BACKUP_SCRUB_MAX_LISTED_SETS) {
            $failedSets[] = $stamp;
        }
    }

    $status = $decoded['status'] ?? null;
    if ($failed > 0 || $status === VIRTUSPHERE_BACKUP_EVIDENCE_FAILED) {
        $state = VIRTUSPHERE_BACKUP_EVIDENCE_FAILED;
    } elseif ($status === VIRTUSPHERE_BACKUP_EVIDENCE_OK) {
        $state = VIRTUSPHERE_BACKUP_EVIDENCE_OK;
    } else {
        return $result;
    }

    return [
        'state' => $state,
        'ts' => $ts,
        'sets_checked' => $checked,
        'sets_failed' => $failed,
        'failed_sets' => $failedSets,
    ];
}
