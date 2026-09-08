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

/** Site evidence never invents MECM criticality from age or provider faults. */
function virtusphere_site_completed_state(array $row, ?int $now = null): string
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
