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

/** How long a reported start counts as a live run before the reporter is late. */
function virtusphere_run_start_grace_seconds(int $intervalSeconds): int
{
    return max(
        $intervalSeconds * VIRTUSPHERE_HEARTBEAT_WARN_MULTIPLIER,
        VIRTUSPHERE_HEARTBEAT_WARN_FLOOR_SECONDS,
        VIRTUSPHERE_RUN_GRACE_SECONDS
    );
}

/**
 * Whether a sync reporter itself is late, as opposed to reporting on time with a
 * non-green outcome. Both read "warning", and they need opposite instructions: a
 * late reporter points at Task Scheduler, a punctual run with open points does
 * not, because restarting a task that reports every few seconds clears nothing.
 * Late means no in-time evidence: the completed result (or, for a legacy
 * reporter, its heartbeat) is past the warn threshold, or a reported start has
 * outlived its grace. A missing row or unreadable timestamp is late as well:
 * nothing proves the task is alive.
 */
function virtusphere_run_report_overdue(?array $row, ?int $now = null): bool
{
    if ($row === null) {
        return true;
    }
    $current = $now ?? time();
    $interval = (int) ($row['interval_seconds'] ?? 0);
    $event = (string) ($row['last_event'] ?? VIRTUSPHERE_INTEGRATION_EVENT_HEARTBEAT);
    if ($event === VIRTUSPHERE_RUN_EVENT_STARTED) {
        $attemptTs = virtusphere_evidence_timestamp(isset($row['last_attempt_at']) ? (string) $row['last_attempt_at'] : null, $current);

        return $attemptTs === null || ($current - $attemptTs) > virtusphere_run_start_grace_seconds($interval);
    }
    $evidenceAt = $event === VIRTUSPHERE_RUN_EVENT_COMPLETED ? ($row['last_result_at'] ?? null) : ($row['last_seen_at'] ?? null);

    return virtusphere_heartbeat_staleness($evidenceAt !== null ? (string) $evidenceAt : null, $interval, null, $current) !== 'ok';
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
