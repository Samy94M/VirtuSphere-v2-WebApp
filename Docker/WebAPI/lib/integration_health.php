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
require_once __DIR__ . '/repo/ansible_activity.php';
require_once __DIR__ . '/repo/ansible_preflight.php';
require_once __DIR__ . '/repo/credentials.php';
require_once __DIR__ . '/repo/heartbeats.php';
// Refused machine accesses are what separates "rejected" from "never set up".
require_once __DIR__ . '/repo/log.php';

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
    // Actual mission history is a second, display-only signal. It never enters
    // ansible_preflight_ampel(): a successful start/shutdown job does not prove
    // the SFTP, callback and allowlist checks of the dedicated full test.
    $missionJobs = repo_latest_completed_ansible_mission_jobs($db);
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
        $ansibleRows[] = [
            'credential' => $credential,
            'state_row' => $stateRow,
            'state' => $state,
            'last_mission_job' => $missionJobs[$credentialId] ?? null,
        ];
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
        // Refused machine accesses within the last day. Without this, "configured
        // but rejected" and "never configured" render identically as a grey row,
        // and the commonest setup mistake in the product is invisible: the task
        // runs every minute against a closed IP gate, and the portal looks like a
        // server where MECM was never installed.
        'machine_api_denials' => repo_recent_machine_api_denials($db, VIRTUSPHERE_MACHINE_API_DENIAL_WINDOW_SECONDS),
        'ansible' => ['rows' => $ansibleRows, 'state' => $ansibleWorst],
        // ansible_selected is what esxi_inventory_automation_blocker() needs as
        // its third input, and it is resolved here rather than in the renderer:
        // this function already resolves the interval, and no other renderer on
        // the page reaches into the database itself.
        'esxi' => [
            'rows' => $esxiRows,
            'state' => $esxiWorst,
            'interval_hours' => $intervalHours,
            'ansible_selected' => esxi_inventory_ansible_resolution($db)['credential_id'] !== null,
            // The fourth input of esxi_inventory_automation_blocker(). The pull is
            // a deploy JOB, so a dead deploy worker stops the cycle as thoroughly
            // as a switched-off interval, and the cadence line must say so instead
            // of promising a cycle nobody drives.
            'deploy_worker_alive' => integration_deploy_worker_alive($bySource),
        ],
    ];
}

/**
 * Whether the deploy worker's own status row is currently healthy.
 *
 * Reads the state the page already derived rather than re-deriving staleness:
 * the row and the cadence line have to agree, and two derivations of "stale" is
 * exactly how they would drift apart. A missing row (a stack that never ran the
 * worker) counts as not alive, because nothing has ever proven otherwise.
 *
 * @param array<string, array{source:string,row:array|null,state:string}> $bySource
 */
function integration_deploy_worker_alive(array $bySource): bool
{
    $entry = $bySource[VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER] ?? null;
    if ($entry === null || ($entry['row'] ?? null) === null) {
        return false;
    }

    return in_array((string) $entry['state'], ['ok', 'legacy'], true);
}

/**
 * The same answer for a page that needs only this one fact and not a whole
 * snapshot (the Credentials list). Reuses repo_integration_status_rows(), so the
 * staleness derivation stays single-sourced.
 */
function integration_deploy_worker_alive_now(mysqli $db, ?int $now = null): bool
{
    $bySource = [];
    foreach (repo_integration_status_rows($db, $now ?? time()) as $row) {
        $bySource[(string) $row['source']] = $row;
    }

    return integration_deploy_worker_alive($bySource);
}
