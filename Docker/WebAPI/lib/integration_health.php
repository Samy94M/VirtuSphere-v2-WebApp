<?php

declare(strict_types=1);

/**
 * One request-scoped health snapshot for System status and Dashboard. Every
 * age calculation receives the same clock value so tiles cannot disagree at a
 * threshold boundary.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/status.php';
require_once __DIR__ . '/esxi_inventory.php';
require_once __DIR__ . '/repo/ansible_preflight.php';
require_once __DIR__ . '/repo/credentials.php';
require_once __DIR__ . '/repo/heartbeats.php';

/**
 * @return array<string,mixed>
 */
function integration_health_snapshot(mysqli $db, ?int $now = null): array
{
    $now ??= time();
    $rows = repo_integration_status_rows($db, $now);
    $bySource = [];
    foreach ($rows as $row) {
        $bySource[(string) $row['source']] = $row;
    }

    $syncRows = repo_integration_rows_for_sources($rows, VIRTUSPHERE_INTEGRATION_MECM_SYNC_SOURCES);
    $siteRows = repo_integration_rows_for_sources($rows, VIRTUSPHERE_INTEGRATION_MECM_SITE_SOURCES);
    $internalRows = repo_integration_rows_for_sources($rows, VIRTUSPHERE_INTEGRATION_INTERNAL_SOURCES);
    // The two groups keep their own state and are never collapsed on the
    // Dashboard or the detail panel; 'mecm' is a worst-of ONLY for the System
    // status overview nav card ("something in the MECM area needs attention").
    $mecmRows = array_merge($syncRows, $siteRows);

    // The installer registers all four MECM tasks (three syncs plus site-health)
    // on the same host, so a fresh report arriving from a second IP is equally
    // suspicious for any of them; the mismatch warning spans all four sources.
    $freshIps = [];
    foreach ($mecmRows as $entry) {
        $row = $entry['row'];
        if ($row === null || trim((string) ($row['last_ip'] ?? '')) === '') {
            continue;
        }
        $seen = strtotime((string) ($row['last_seen_at'] ?? ''));
        $freshFor = max(
            (int) ($row['interval_seconds'] ?? 0) * VIRTUSPHERE_HEARTBEAT_WARN_MULTIPLIER,
            VIRTUSPHERE_HEARTBEAT_WARN_FLOOR_SECONDS
        );
        if ($seen !== false && ($now - $seen) <= $freshFor) {
            $freshIps[(string) $row['last_ip']][] = (string) $entry['source'];
        }
    }

    $preflightStates = repo_ansible_preflight_states($db);
    $ansibleRows = [];
    $ansibleWorst = null;
    foreach (repo_credentials_by_type($db, VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE) as $credential) {
        $credentialId = (int) $credential['id'];
        $stateRow = $preflightStates[$credentialId] ?? null;
        // Same derivation as the badge on the Credentials page. This used to be
        // a second copy of that match, so the staleness axis would have landed
        // on one page only and the two would have disagreed about the same row.
        $state = ansible_preflight_ampel($stateRow, $now);
        if ($ansibleWorst === null || virtusphere_heartbeat_state_rank($state) > virtusphere_heartbeat_state_rank($ansibleWorst)) {
            $ansibleWorst = $state;
        }
        $ansibleRows[] = ['credential' => $credential, 'state_row' => $stateRow, 'state' => $state];
    }

    $esxiRows = esxi_inventory_summaries($db);
    $intervalHours = esxi_inventory_interval_hours($db);
    // Stays null when no ESXi credential exists at all, and the dashboard hides
    // its tile on that: a permanently grey tile for a feature nobody configured
    // is noise. Fetch health only, like every other rollup here; a free licence
    // or an HA cluster is a property of a *successful* pull and surfaces as its
    // own badge (esxi_capability_warnings()), never as this colour.
    $esxiWorst = null;
    foreach ($esxiRows as &$entry) {
        $entry['health'] = esxi_inventory_ampel($entry['state'], $intervalHours, $now);
        if ($esxiWorst === null || virtusphere_heartbeat_state_rank($entry['health']) > virtusphere_heartbeat_state_rank($esxiWorst)) {
            $esxiWorst = $entry['health'];
        }
    }
    unset($entry);

    return [
        'generated_at' => gmdate('Y-m-d H:i:s', $now),
        'now' => $now,
        'rows' => $rows,
        'by_source' => $bySource,
        'mecm_sync' => ['rows' => $syncRows, 'state' => repo_integration_worst_state($syncRows)],
        'mecm_site' => ['rows' => $siteRows, 'state' => repo_integration_worst_state($siteRows)],
        'mecm' => ['rows' => $mecmRows, 'state' => repo_integration_worst_state($mecmRows)],
        'internal' => ['rows' => $internalRows, 'state' => repo_integration_worst_state($internalRows)],
        'mecm_fresh_ips' => $freshIps,
        'mecm_ip_mismatch' => count($freshIps) > 1,
        'ansible' => ['rows' => $ansibleRows, 'state' => $ansibleWorst],
        'esxi' => ['rows' => $esxiRows, 'state' => $esxiWorst, 'interval_hours' => $intervalHours],
    ];
}
