<?php

declare(strict_types=1);

/**
 * Backup status reader (Paket A / ADR-0021).
 *
 * `scripts/backup.sh` appends one JSON line per run to
 * Docker/backups/status/backup-status.jsonl. The host directory is bind-mounted
 * read-only into the PHP container at VIRTUSPHERE_BACKUP_STATUS_PATH so the
 * portal can surface a status card (settings) and a dashboard banner without
 * ever touching the dumps themselves.
 *
 * This reader is deliberately dependency-free and fail-soft: an absent mount, an
 * empty file or a half-written trailing line must never fatal a portal page. The
 * worst case is the `unknown` state.
 *
 * Since ADR-0024 each line may also carry the schedule the run was started from
 * (`schedule`, `schedule_tz`, `schedule_source`) or an exact `next_ts` reported
 * by a systemd timer. That turns "next run" from a guess into a fact; lines
 * written before ADR-0024 simply lack the fields and fall back to the interval.
 */

require_once __DIR__ . '/cron_schedule.php';
require_once __DIR__ . '/format.php';

const VIRTUSPHERE_BACKUP_STATUS_PATH = '/var/backups/virtusphere-status/backup-status.jsonl';

// Fallback rhythm when no schedule was reported: one run per day, plus a grace
// window for a late run before the portal calls the backup stale. With a
// reported schedule the same grace applies to the *expected* run instead.
const VIRTUSPHERE_BACKUP_INTERVAL_SECONDS = 24 * 3600;
const VIRTUSPHERE_BACKUP_GRACE_SECONDS = 2 * 3600;
const VIRTUSPHERE_BACKUP_STALE_AFTER_SECONDS = VIRTUSPHERE_BACKUP_INTERVAL_SECONDS + VIRTUSPHERE_BACKUP_GRACE_SECONDS;

// Below this share of free disk on the backup volume the newest run is flagged.
const VIRTUSPHERE_BACKUP_DISK_LOW_PCT = 10;

const VIRTUSPHERE_BACKUP_STATE_OK = 'ok';
const VIRTUSPHERE_BACKUP_STATE_FAILED = 'failed';
const VIRTUSPHERE_BACKUP_STATE_STALE = 'stale';
const VIRTUSPHERE_BACKUP_STATE_DISK_LOW = 'disk_low';
const VIRTUSPHERE_BACKUP_STATE_UNKNOWN = 'unknown';

// Where the expected next run came from, weakest last.
const VIRTUSPHERE_BACKUP_NEXT_REPORTED = 'reported';  // systemd timer named the epoch
const VIRTUSPHERE_BACKUP_NEXT_SCHEDULE = 'schedule';  // computed from the cron expression
const VIRTUSPHERE_BACKUP_NEXT_ESTIMATED = 'estimated'; // last run plus the fallback interval

/**
 * Reads the newest valid backup-status line and derives a portal state.
 *
 * Severity order (highest first): failed > stale > disk_low > ok. `unknown`
 * means there is no readable status at all (mount missing or script never ran).
 *
 * `expected_at` is when the run *after* the last one is due, `overdue_at` adds
 * the grace window and drives the stale state. `next_run_ts` is what the card
 * shows: the next run from now, which after a missed run is not the same value.
 *
 * @param string|null $path Override for tests; defaults to the container mount.
 * @param int|null $now Override for tests; defaults to time().
 * @return array{state:string,last:array<string,mixed>|null,age_seconds:int|null,schedule:string,schedule_tz:string,schedule_source:string,expected_at:int|null,expected_source:string|null,overdue_at:int|null,next_run_ts:int|null,next_run_source:string|null}
 */
function backup_status_read(?string $path = null, ?int $now = null): array
{
    $path ??= VIRTUSPHERE_BACKUP_STATUS_PATH;
    $now ??= time();
    $result = [
        'state' => VIRTUSPHERE_BACKUP_STATE_UNKNOWN,
        'last' => null,
        'age_seconds' => null,
        'schedule' => '',
        'schedule_tz' => '',
        'schedule_source' => '',
        'expected_at' => null,
        'expected_source' => null,
        'overdue_at' => null,
        'next_run_ts' => null,
        'next_run_source' => null,
    ];

    if (!is_file($path) || !is_readable($path)) {
        return $result;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $result;
    }

    $last = null;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $decoded = json_decode($line, true);
        // Skip unparsable or half-written trailing lines; keep the last valid one.
        if (is_array($decoded) && isset($decoded['ts']) && is_numeric($decoded['ts'])) {
            $last = $decoded;
        }
    }

    if ($last === null) {
        return $result;
    }

    $result['last'] = $last;
    $ts = (int) $last['ts'];
    $age = $ts > 0 ? max(0, $now - $ts) : null;
    $result['age_seconds'] = $age;

    $result['schedule'] = trim((string) ($last['schedule'] ?? ''));
    $result['schedule_tz'] = trim((string) ($last['schedule_tz'] ?? ''));
    $result['schedule_source'] = trim((string) ($last['schedule_source'] ?? ''));

    if ($ts > 0) {
        $expected = backup_status_expected_run($last, $ts);
        $result['expected_at'] = $expected['at'];
        $result['expected_source'] = $expected['source'];
        $result['overdue_at'] = $expected['at'] + VIRTUSPHERE_BACKUP_GRACE_SECONDS;

        // The card shows the upcoming run, not the one that was already missed.
        if ($expected['at'] > $now) {
            $result['next_run_ts'] = $expected['at'];
            $result['next_run_source'] = $expected['source'];
        } elseif ($expected['source'] !== VIRTUSPHERE_BACKUP_NEXT_ESTIMATED) {
            // A schedule can be rolled forward; a bare interval estimate whose
            // due date already passed says nothing about the next run.
            $upcoming = backup_status_expected_run($last, $now);
            if ($upcoming['source'] !== VIRTUSPHERE_BACKUP_NEXT_ESTIMATED) {
                $result['next_run_ts'] = $upcoming['at'];
                $result['next_run_source'] = $upcoming['source'];
            }
        }
    }

    $reported = (string) ($last['status'] ?? '');
    $diskPct = isset($last['disk_free_pct']) && is_numeric($last['disk_free_pct'])
        ? (float) $last['disk_free_pct']
        : null;

    if ($reported === VIRTUSPHERE_BACKUP_STATE_FAILED) {
        $result['state'] = VIRTUSPHERE_BACKUP_STATE_FAILED;
    } elseif ($result['overdue_at'] !== null && $now > $result['overdue_at']) {
        $result['state'] = VIRTUSPHERE_BACKUP_STATE_STALE;
    } elseif ($diskPct !== null && $diskPct < VIRTUSPHERE_BACKUP_DISK_LOW_PCT) {
        $result['state'] = VIRTUSPHERE_BACKUP_STATE_DISK_LOW;
    } elseif ($reported === VIRTUSPHERE_BACKUP_STATE_OK) {
        $result['state'] = VIRTUSPHERE_BACKUP_STATE_OK;
    } else {
        $result['state'] = VIRTUSPHERE_BACKUP_STATE_UNKNOWN;
    }

    return $result;
}

/**
 * When the run following $after is due, and how confident we are about it.
 *
 * Precedence, strongest first:
 *   1. `next_ts` — a systemd timer told backup.sh its own next elapse.
 *   2. `schedule` — the cron expression backup.sh read from the crontab it runs
 *      under, evaluated in the scheduler's timezone.
 *   3. the fallback interval, which is only ever an assumption.
 *
 * @param array<string,mixed> $last
 * @return array{at:int,source:string}
 */
function backup_status_expected_run(array $last, int $after): array
{
    $reportedNext = isset($last['next_ts']) && is_numeric($last['next_ts']) ? (int) $last['next_ts'] : 0;
    if ($reportedNext > $after) {
        return ['at' => $reportedNext, 'source' => VIRTUSPHERE_BACKUP_NEXT_REPORTED];
    }

    $schedule = trim((string) ($last['schedule'] ?? ''));
    if ($schedule !== '') {
        $next = cron_schedule_next($schedule, $after, trim((string) ($last['schedule_tz'] ?? '')));
        if ($next !== null) {
            return ['at' => $next, 'source' => VIRTUSPHERE_BACKUP_NEXT_SCHEDULE];
        }
    }

    return ['at' => $after + VIRTUSPHERE_BACKUP_INTERVAL_SECONDS, 'source' => VIRTUSPHERE_BACKUP_NEXT_ESTIMATED];
}

/**
 * Maps a backup state to an `.alert-*` CSS variant for the banner/card.
 * `ok` returns the neutral info style (the card only renders detail then).
 */
function backup_status_alert_class(string $state): string
{
    return match ($state) {
        VIRTUSPHERE_BACKUP_STATE_FAILED => 'alert-error',
        VIRTUSPHERE_BACKUP_STATE_STALE,
        VIRTUSPHERE_BACKUP_STATE_DISK_LOW,
        VIRTUSPHERE_BACKUP_STATE_UNKNOWN => 'alert-warning',
        default => 'alert-info',
    };
}

/**
 * Maps a backup state to a `.badge-*` CSS variant for the settings card.
 */
function backup_status_badge_class(string $state): string
{
    return match ($state) {
        VIRTUSPHERE_BACKUP_STATE_OK => 'badge-success',
        VIRTUSPHERE_BACKUP_STATE_FAILED => 'badge-danger',
        VIRTUSPHERE_BACKUP_STATE_STALE,
        VIRTUSPHERE_BACKUP_STATE_DISK_LOW => 'badge-warning',
        default => 'badge-neutral',
    };
}

/**
 * Localized short label for a backup state (settings card badge).
 */
function backup_status_label(string $state): string
{
    return match ($state) {
        VIRTUSPHERE_BACKUP_STATE_OK => __t('settings.backup_state_ok'),
        VIRTUSPHERE_BACKUP_STATE_FAILED => __t('settings.backup_state_failed'),
        VIRTUSPHERE_BACKUP_STATE_STALE => __t('settings.backup_state_stale'),
        VIRTUSPHERE_BACKUP_STATE_DISK_LOW => __t('settings.backup_state_disk_low'),
        default => __t('settings.backup_state_unknown'),
    };
}

/**
 * Localized short message for a non-ok backup state, used by the dashboard
 * banner and the settings card. Uses __t() with the key as a safe fallback if
 * the catalog is not loaded (ADR-0014 / .claude/rules/webapi.md).
 */
function backup_status_message(string $state): string
{
    return match ($state) {
        VIRTUSPHERE_BACKUP_STATE_FAILED => __t('dashboard.backup_banner_failed'),
        VIRTUSPHERE_BACKUP_STATE_STALE => __t('dashboard.backup_banner_stale'),
        VIRTUSPHERE_BACKUP_STATE_DISK_LOW => __t('dashboard.backup_banner_disk_low'),
        VIRTUSPHERE_BACKUP_STATE_UNKNOWN => __t('dashboard.backup_banner_unknown'),
        default => '',
    };
}

/**
 * Human-readable byte size (binary units). Kept as the name the settings card,
 * the dashboard and the integrations tables already call; the implementation
 * moved to lib/format.php once the inventory pickers needed the same format.
 */
function backup_status_human_bytes(?int $bytes): string
{
    return virtusphere_human_bytes($bytes);
}
