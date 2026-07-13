<?php

declare(strict_types=1);

// Client deploy-phase events reported through mecm_report.php (ADR-0018).
// Rows cascade away with their VM (MECM is the single point of truth for
// device decommissioning) and are purged after the retention window by the
// maintenance worker. Timestamps are always server-side NOW().

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/helpers.php';

// Strips control characters and truncates to the storable detail length.
function repo_client_event_sanitize_detail(?string $detail): ?string
{
    if ($detail === null) {
        return null;
    }

    $detail = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $detail));
    if ($detail === '') {
        return null;
    }

    return mb_substr($detail, 0, VIRTUSPHERE_CLIENT_EVENT_DETAIL_MAX_CHARS);
}

function repo_record_client_event(mysqli $db, int $vmId, string $mac, string $phase, string $event, ?string $detail, string $clientIp): bool
{
    $stmt = $db->prepare('INSERT INTO deploy_client_events (vm_id, mac, phase, event, detail, client_ip) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isssss', $vmId, $mac, $phase, $event, $detail, $clientIp);

    return $stmt->execute();
}

// Latest stored event for one (vm, phase) pair or null.
function repo_client_event_latest(mysqli $db, int $vmId, string $phase): ?array
{
    return repo_fetch_one($db, 'SELECT * FROM deploy_client_events WHERE vm_id = ? AND phase = ? ORDER BY id DESC LIMIT 1', 'is', [$vmId, $phase]);
}

// True when the latest event for (vm, phase) matches event+detail and is
// younger than the dedupe window - the endpoint then skips the insert.
function repo_client_event_is_duplicate(mysqli $db, int $vmId, string $phase, string $event, ?string $detail): bool
{
    $latest = repo_client_event_latest($db, $vmId, $phase);
    if ($latest === null) {
        return false;
    }
    if ((string) $latest['event'] !== $event || (string) ($latest['detail'] ?? '') !== (string) ($detail ?? '')) {
        return false;
    }

    $createdTs = strtotime((string) $latest['created_at']);

    return $createdTs !== false && (time() - $createdTs) < VIRTUSPHERE_CLIENT_EVENT_DEDUPE_SECONDS;
}

function repo_client_event_count_recent(mysqli $db, int $vmId, int $seconds): int
{
    return (int) repo_scalar($db, 'SELECT COUNT(*) FROM deploy_client_events WHERE vm_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)', 'ii', [$vmId, $seconds]);
}

function repo_client_events_for_vm(mysqli $db, int $vmId, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $stmt = $db->prepare('SELECT * FROM deploy_client_events WHERE vm_id = ? ORDER BY id DESC LIMIT ?');
    $stmt->bind_param('ii', $vmId, $limit);
    $stmt->execute();

    return repo_fetch_all($stmt->get_result());
}

/**
 * Latest event per phase for the fixed phase order.
 *
 * @return array<string, array|null> phase => latest event row or null
 */
function repo_client_phase_summary(mysqli $db, int $vmId): array
{
    $stmt = $db->prepare('SELECT e.* FROM deploy_client_events e JOIN (SELECT phase, MAX(id) AS max_id FROM deploy_client_events WHERE vm_id = ? GROUP BY phase) latest ON latest.max_id = e.id');
    $stmt->bind_param('i', $vmId);
    $stmt->execute();

    $byPhase = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $byPhase[(string) $row['phase']] = $row;
    }

    $summary = [];
    foreach (VIRTUSPHERE_CLIENT_PHASES as $phase) {
        $summary[$phase] = $byPhase[$phase] ?? null;
    }

    return $summary;
}

// Retention: called by the maintenance worker, returns deleted row count.
function repo_purge_client_events(mysqli $db, int $retentionDays = VIRTUSPHERE_CLIENT_EVENT_RETENTION_DAYS): int
{
    $stmt = $db->prepare('DELETE FROM deploy_client_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->bind_param('i', $retentionDays);
    $stmt->execute();

    return $stmt->affected_rows;
}
