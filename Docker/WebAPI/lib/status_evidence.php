<?php

declare(strict_types=1);

const VIRTUSPHERE_STATUS_EVIDENCE_FUTURE_SKEW_SECONDS = 300;

function virtusphere_evidence_timestamp(?string $value, int $now): ?int
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $timestamp = strtotime($value . ' UTC');

    return $timestamp === false || $timestamp > $now + VIRTUSPHERE_STATUS_EVIDENCE_FUTURE_SKEW_SECONDS
        ? null
        : $timestamp;
}

/** One completed sync run; missing or invalid time is not current evidence. */
function virtusphere_run_completed_state(array $row, ?int $now = null): string
{
    $outcome = (string) ($row['last_status'] ?? '');
    $current = $now ?? time();
    $resultAt = isset($row['last_result_at']) ? (string) $row['last_result_at'] : null;
    if (virtusphere_evidence_timestamp($resultAt, $current) === null) {
        return 'unknown';
    }
    if ($outcome === VIRTUSPHERE_RUN_OUTCOME_FAIL) {
        return 'danger';
    }
    $stale = virtusphere_heartbeat_staleness($resultAt, (int) ($row['interval_seconds'] ?? 0), null, $current);
    if (in_array($stale, ['danger', 'warning'], true)) {
        return $stale;
    }

    return match ($outcome) {
        VIRTUSPHERE_RUN_OUTCOME_OK => 'ok',
        VIRTUSPHERE_RUN_OUTCOME_WARNING => 'warning',
        default => 'unknown',
    };
}

// A normal autoimporter scan of many folders takes 15-20 min, longer than the
// fixed run grace, so every run turned red and back on completion (AI1). A run
// therefore also gets this multiple of the duration its last `completed`
// reported; no per-source number. The duration part is capped, so one run that
// hung and still completed late cannot hide the next hang for a day.
const VIRTUSPHERE_RUN_DURATION_GRACE_FACTOR = 2;
const VIRTUSPHERE_RUN_DURATION_GRACE_MAX_SECONDS = 21600;

/**
 * How long a run in progress may take before it counts as overdue:
 * max(3x interval, 60s, RUN_GRACE, FACTOR x last completed duration). A
 * `started` report keeps last_duration_ms, so it is the previous run's length.
 * Without one (first run, legacy history) only the fixed floors apply.
 */
function virtusphere_run_grace_seconds(array $row): int
{
    $grace = max(
        (int) ($row['interval_seconds'] ?? 0) * VIRTUSPHERE_HEARTBEAT_WARN_MULTIPLIER,
        VIRTUSPHERE_HEARTBEAT_WARN_FLOOR_SECONDS,
        VIRTUSPHERE_RUN_GRACE_SECONDS
    );
    $durationMs = $row['last_duration_ms'] ?? null;
    if (!is_numeric($durationMs) || (int) $durationMs <= 0) {
        return $grace;
    }
    $expected = (int) ceil(((int) $durationMs / 1000) * VIRTUSPHERE_RUN_DURATION_GRACE_FACTOR);

    return max($grace, min($expected, VIRTUSPHERE_RUN_DURATION_GRACE_MAX_SECONDS));
}

/** Freshness of the completed site report, independent of its verdict. */
function virtusphere_site_evidence_freshness(array $row, ?int $now = null): string
{
    $current = $now ?? time();
    $resultAt = isset($row['last_result_at']) ? (string) $row['last_result_at'] : null;
    $timestamp = virtusphere_evidence_timestamp($resultAt, $current);
    if ($timestamp === null) {
        return 'unknown';
    }
    $freshFor = max(
        (int) ($row['interval_seconds'] ?? 0) * VIRTUSPHERE_HEARTBEAT_WARN_MULTIPLIER,
        VIRTUSPHERE_HEARTBEAT_WARN_FLOOR_SECONDS
    );
    if (($current - $timestamp) > $freshFor) {
        return 'stale';
    }

    return 'ok';
}

/** The reporter can be overdue without a critical MECM site verdict. */
function virtusphere_site_reporter_state(array $row, ?int $now = null): string
{
    $freshness = virtusphere_site_evidence_freshness($row, $now);

    return $freshness === 'stale' ? 'warning' : $freshness;
}

/** Site evidence never invents MECM criticality from age or provider faults. */
function virtusphere_site_completed_state(array $row, ?int $now = null): string
{
    $freshness = virtusphere_site_evidence_freshness($row, $now);
    if ($freshness !== 'ok') {
        return $freshness;
    }
    $category = (string) ($row['last_error_category'] ?? '');
    if ($category === VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL) {
        return 'danger';
    }
    if ($category === VIRTUSPHERE_RUN_ERROR_SITE_WARNING
        || (string) ($row['last_status'] ?? '') === VIRTUSPHERE_RUN_OUTCOME_WARNING
    ) {
        return 'warning';
    }

    return (string) ($row['last_status'] ?? '') === VIRTUSPHERE_RUN_OUTCOME_OK ? 'ok' : 'unknown';
}
