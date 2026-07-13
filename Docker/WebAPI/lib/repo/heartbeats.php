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
    $stmt = $db->prepare('INSERT INTO deploy_integration_heartbeats (source, last_seen_at, last_checked_at, last_status, last_detail, last_ip, interval_seconds, beat_count)
        VALUES (?, NOW(), NOW(), ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE last_seen_at = NOW(), last_checked_at = NOW(), last_status = VALUES(last_status), last_detail = VALUES(last_detail), last_ip = VALUES(last_ip), interval_seconds = VALUES(interval_seconds), beat_count = beat_count + 1');
    $status = VIRTUSPHERE_HEARTBEAT_STATUS_OK;
    $stmt->bind_param('ssssi', $source, $status, $detail, $ip, $intervalSeconds);
    $stmt->execute();
}

// Records a failed attempt (used by the maintenance worker probe): bumps
// last_checked_at/last_status/last_detail but never last_seen_at.
function repo_mark_integration_failure(mysqli $db, string $source, string $detail, int $intervalSeconds): void
{
    $stmt = $db->prepare('INSERT INTO deploy_integration_heartbeats (source, last_seen_at, last_checked_at, last_status, last_detail, last_ip, interval_seconds, beat_count)
        VALUES (?, NULL, NOW(), ?, ?, \'\', ?, 0)
        ON DUPLICATE KEY UPDATE last_checked_at = NOW(), last_status = VALUES(last_status), last_detail = VALUES(last_detail), interval_seconds = VALUES(interval_seconds)');
    $status = VIRTUSPHERE_HEARTBEAT_STATUS_FAIL;
    $stmt->bind_param('sssi', $source, $status, $detail, $intervalSeconds);
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

// True once any source has ever reported - from then on missing sources are
// rendered as "expected but never seen" (warning) instead of neutral.
function repo_integration_any_seen(mysqli $db): bool
{
    return (int) repo_scalar($db, 'SELECT COUNT(*) FROM deploy_integration_heartbeats') > 0;
}

/**
 * Display rows for the status page/dashboard: source, stored row and derived
 * state (ok|warning|danger|missing|unknown). "missing" applies once any other
 * source has reported (integration is clearly in use).
 *
 * @return list<array{source: string, row: array|null, state: string}>
 */
function repo_integration_status_rows(mysqli $db): array
{
    require_once __DIR__ . '/../status.php';

    $heartbeats = repo_integration_heartbeats($db);
    // Only WIRE sources decide whether the MECM integration is "in use"; the
    // internal maintenance worker reporting must not escalate a fresh install
    // (no MECM yet) from neutral to warning.
    $anySeen = false;
    foreach (VIRTUSPHERE_INTEGRATION_WIRE_SOURCES as $wireSource) {
        if (($heartbeats[$wireSource] ?? null) !== null) {
            $anySeen = true;
            break;
        }
    }

    $out = [];
    foreach ($heartbeats as $source => $row) {
        if ($row === null) {
            $state = $anySeen ? 'missing' : 'unknown';
        } else {
            $state = virtusphere_heartbeat_staleness(
                isset($row['last_seen_at']) ? (string) $row['last_seen_at'] : null,
                (int) $row['interval_seconds'],
                (string) $row['last_status']
            );
        }
        $out[] = ['source' => $source, 'row' => $row, 'state' => $state];
    }

    return $out;
}

// Worst state across all sources for the dashboard tile.
function repo_integration_worst_state(array $statusRows): string
{
    $rank = ['danger' => 4, 'missing' => 3, 'warning' => 2, 'unknown' => 1, 'ok' => 0];
    $worst = 'ok';
    foreach ($statusRows as $row) {
        $state = (string) ($row['state'] ?? 'unknown');
        if (($rank[$state] ?? 1) > ($rank[$worst] ?? 0)) {
            $worst = $state;
        }
    }

    return $worst;
}
