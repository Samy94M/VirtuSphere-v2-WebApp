<?php

declare(strict_types=1);

// Integration heartbeats (ADR-0018): one upserted row per source, no history.
// last_seen_at = last successful contact; last_checked_at/last_status carry
// the most recent attempt so a fresh failure shows red immediately even while
// the last success is not yet stale.

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/helpers.php';

function repo_touch_integration_heartbeat(mysqli $db, string $source, string $ip, int $intervalSeconds, ?string $detail = null): void
{
    // last_event is reset to 'heartbeat' so a sync task that once reported V2
    // results but was rolled back to an old heartbeat-only script is derived as
    // legacy again (the display follows last_event, never report_version).
    $stmt = $db->prepare('INSERT INTO deploy_integration_heartbeats (source, last_seen_at, last_checked_at, last_status, last_detail, last_ip, interval_seconds, beat_count, last_event)
        VALUES (?, NOW(), NOW(), ?, ?, ?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE last_seen_at = NOW(), last_checked_at = NOW(), last_status = VALUES(last_status), last_detail = VALUES(last_detail), last_ip = VALUES(last_ip), interval_seconds = VALUES(interval_seconds), beat_count = beat_count + 1, last_event = VALUES(last_event)');
    $status = VIRTUSPHERE_HEARTBEAT_STATUS_OK;
    $event = VIRTUSPHERE_INTEGRATION_EVENT_HEARTBEAT;
    $stmt->bind_param('ssssis', $source, $status, $detail, $ip, $intervalSeconds, $event);
    $stmt->execute();
}

/**
 * Records a validated run report (mecm_report.php?action=reportRun) into the one
 * summary row for its source. `started` marks last_attempt_at without touching
 * the last completed result; `completed` records the outcome, timestamps,
 * failure streak and summary. Arrival order is the truth: a completed report is
 * always taken unless it repeats the last completed run_id (idempotent replay).
 *
 * @param array{source:string,event:string,run_id:string,interval_seconds:int,ip?:string,outcome?:string,error_category?:?string,duration_ms?:?int,detail?:?string,summary?:?array,script_version?:?string} $report
 * @return array{deduplicated: bool, legacy_to_v2: bool}
 */
function repo_record_run_report(mysqli $db, array $report): array
{
    return repo_transaction($db, static function () use ($db, $report): array {
        $source = (string) $report['source'];

        $stmt = $db->prepare('SELECT last_event, last_run_id, report_version FROM deploy_integration_heartbeats WHERE source = ? FOR UPDATE');
        $stmt->bind_param('s', $source);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();

        // The one-time legacy->V2 note fires only for a row that previously sent
        // legacy heartbeats; a brand-new source going straight to V2 is not a
        // "switch" and does not log one.
        $legacyToV2 = $current !== null && (int) ($current['report_version'] ?? 1) < 2;

        if ((string) $report['event'] === VIRTUSPHERE_RUN_EVENT_COMPLETED
            && $current !== null
            && (string) ($current['last_run_id'] ?? '') === (string) $report['run_id']
            && (string) ($current['last_event'] ?? '') === VIRTUSPHERE_RUN_EVENT_COMPLETED
        ) {
            return ['deduplicated' => true, 'legacy_to_v2' => false];
        }

        if ((string) $report['event'] === VIRTUSPHERE_RUN_EVENT_STARTED) {
            repo_run_report_write_started($db, $report);
        } else {
            repo_run_report_write_completed($db, $report);
        }

        return ['deduplicated' => false, 'legacy_to_v2' => $legacyToV2];
    });
}

/**
 * @param array<string,mixed> $report
 */
function repo_run_report_write_started(mysqli $db, array $report): void
{
    $source = (string) $report['source'];
    $ip = (string) ($report['ip'] ?? '');
    $interval = (int) $report['interval_seconds'];
    $runId = (string) $report['run_id'];
    $event = VIRTUSPHERE_RUN_EVENT_STARTED;
    $scriptVersion = isset($report['script_version']) ? (string) $report['script_version'] : null;

    $stmt = $db->prepare('INSERT INTO deploy_integration_heartbeats
        (source, last_seen_at, last_checked_at, last_attempt_at, last_ip, interval_seconds, beat_count, report_version, last_event, last_run_id, last_script_version)
        VALUES (?, NOW(), NOW(), NOW(), ?, ?, 1, 2, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            last_seen_at = NOW(), last_checked_at = NOW(), last_attempt_at = NOW(),
            last_ip = VALUES(last_ip), interval_seconds = VALUES(interval_seconds),
            beat_count = beat_count + 1, report_version = GREATEST(report_version, 2),
            last_event = VALUES(last_event), last_run_id = VALUES(last_run_id),
            last_script_version = VALUES(last_script_version)');
    $stmt->bind_param('ssisss', $source, $ip, $interval, $event, $runId, $scriptVersion);
    $stmt->execute();
}

/**
 * @param array<string,mixed> $report
 */
function repo_run_report_write_completed(mysqli $db, array $report): void
{
    $source = (string) $report['source'];
    $ip = (string) ($report['ip'] ?? '');
    $interval = (int) $report['interval_seconds'];
    $runId = (string) $report['run_id'];
    $event = VIRTUSPHERE_RUN_EVENT_COMPLETED;
    $outcome = (string) $report['outcome'];
    $detail = array_key_exists('detail', $report) && $report['detail'] !== null ? (string) $report['detail'] : null;
    $errorCategory = array_key_exists('error_category', $report) && $report['error_category'] !== null
        ? (string) $report['error_category'] : null;
    $durationMs = array_key_exists('duration_ms', $report) && $report['duration_ms'] !== null
        ? (int) $report['duration_ms'] : null;
    $scriptVersion = isset($report['script_version']) ? (string) $report['script_version'] : null;
    $summaryJson = (isset($report['summary']) && is_array($report['summary']) && $report['summary'] !== [])
        ? json_encode($report['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;

    // Bound integer flags drive the conditional timestamps and streak so the SQL
    // stays fully static and NOW() remains the sole clock. failure_streak:
    // fail increments, ok resets to zero, warning/unknown leave it unchanged.
    $isOk = $outcome === VIRTUSPHERE_RUN_OUTCOME_OK ? 1 : 0;
    $isFail = $outcome === VIRTUSPHERE_RUN_OUTCOME_FAIL ? 1 : 0;

    $stmt = $db->prepare('INSERT INTO deploy_integration_heartbeats
        (source, last_seen_at, last_checked_at, last_result_at, last_success_at, last_failure_at,
         last_status, last_detail, last_error_category, last_duration_ms, last_ip, interval_seconds,
         beat_count, report_version, last_event, last_run_id, failure_streak, last_summary, last_script_version)
        VALUES (?, NOW(), NOW(), NOW(), IF(?, NOW(), NULL), IF(?, NOW(), NULL),
         ?, ?, ?, ?, ?, ?,
         1, 2, ?, ?, IF(?, 1, 0), ?, ?)
        ON DUPLICATE KEY UPDATE
            last_seen_at = NOW(), last_checked_at = NOW(), last_result_at = NOW(),
            last_success_at = IF(?, NOW(), last_success_at),
            last_failure_at = IF(?, NOW(), last_failure_at),
            last_status = VALUES(last_status), last_detail = VALUES(last_detail),
            last_error_category = VALUES(last_error_category), last_duration_ms = VALUES(last_duration_ms),
            last_ip = VALUES(last_ip), interval_seconds = VALUES(interval_seconds),
            beat_count = beat_count + 1, report_version = GREATEST(report_version, 2),
            last_event = VALUES(last_event), last_run_id = VALUES(last_run_id),
            failure_streak = IF(?, 0, failure_streak + IF(?, 1, 0)),
            last_summary = VALUES(last_summary), last_script_version = VALUES(last_script_version)');

    $params = [
        ['s', $source],
        ['i', $isOk],
        ['i', $isFail],
        ['s', $outcome],
        ['s', $detail],
        ['s', $errorCategory],
        ['i', $durationMs],
        ['s', $ip],
        ['i', $interval],
        ['s', $event],
        ['s', $runId],
        ['i', $isFail],
        ['s', $summaryJson],
        ['s', $scriptVersion],
        ['i', $isOk],
        ['i', $isFail],
        ['i', $isOk],
        ['i', $isFail],
    ];
    $types = implode('', array_column($params, 0));
    $values = array_column($params, 1);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
}

/**
 * All known sources merged with their stored rows; never-seen sources map to
 * null so the status page can render every expected source.
 *
 * @return array<string, array|null>
 */
function repo_integration_heartbeats(mysqli $db): array
{
    $stmt = $db->prepare('SELECT * FROM deploy_integration_heartbeats');
    $stmt->execute();

    $bySource = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $bySource[(string) $row['source']] = $row;
    }

    $out = [];
    foreach (VIRTUSPHERE_INTEGRATION_SOURCES as $source) {
        $out[$source] = $bySource[$source] ?? null;
    }

    return $out;
}

/**
 * Display rows for the status page/dashboard: source, stored row and derived
 * state (ok|legacy|warning|danger|missing|unknown). "missing" is derived per
 * group: a never-seen source is missing only once another source in the SAME
 * group has reported, so a site-health report never makes a missing sync red,
 * and vice versa.
 *
 * @return list<array{source: string, row: array|null, state: string}>
 */
function repo_integration_status_rows(mysqli $db, ?int $now = null): array
{
    require_once __DIR__ . '/../status.php';

    $heartbeats = repo_integration_heartbeats($db);

    $anySeenByKind = ['sync' => false, 'site' => false, 'internal' => false];
    foreach ($heartbeats as $source => $row) {
        if ($row !== null) {
            $anySeenByKind[virtusphere_integration_source_kind((string) $source)] = true;
        }
    }

    $out = [];
    foreach ($heartbeats as $source => $row) {
        $kind = virtusphere_integration_source_kind((string) $source);
        $state = virtusphere_integration_row_state($row, $kind, $anySeenByKind[$kind], $now);
        $out[] = ['source' => $source, 'row' => $row, 'state' => $state];
    }

    return $out;
}

/**
 * @param list<array{source:string,row:array|null,state:string}> $statusRows
 * @param list<string> $sources
 * @return list<array{source:string,row:array|null,state:string}>
 */
function repo_integration_rows_for_sources(array $statusRows, array $sources): array
{
    return array_values(array_filter(
        $statusRows,
        static fn (array $row): bool => in_array((string) $row['source'], $sources, true)
    ));
}

/**
 * @param list<array{source:string,row:array|null,state:string}> $statusRows
 * @param list<string> $sources
 */
function repo_integration_group_worst_state(array $statusRows, array $sources): string
{
    return repo_integration_worst_state(repo_integration_rows_for_sources($statusRows, $sources));
}

// Worst state across all sources for the dashboard tile.
function repo_integration_worst_state(array $statusRows): string
{
    require_once __DIR__ . '/../status.php';

    $worst = 'ok';
    foreach ($statusRows as $row) {
        $state = (string) ($row['state'] ?? 'unknown');
        if (virtusphere_heartbeat_state_rank($state) > virtusphere_heartbeat_state_rank($worst)) {
            $worst = $state;
        }
    }

    return $worst;
}
