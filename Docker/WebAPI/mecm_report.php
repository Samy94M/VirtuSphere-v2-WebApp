<?php

declare(strict_types=1);

// Machine report channel (ADR-0018): Windows clients report deploy-phase
// events by MAC, MECM server sync loops report heartbeats. POST-only, JSON.
// This endpoint never mutates deploy_vms lifecycle state - lifecycle writes
// stay exclusive to the legacy read surface. Display-only telemetry.

// JSON on the wire even for an uncaught error; must precede mysql.php, which
// connects while it loads (see virtusphere_error_response_mode).
require_once __DIR__ . '/lib/errors.php';
virtusphere_error_response_mode('json');

require_once __DIR__ . '/mysql.php';
require_once __DIR__ . '/lib/machine_api.php';
require_once __DIR__ . '/lib/repo/client_events.php';
require_once __DIR__ . '/lib/repo/heartbeats.php';
require_once __DIR__ . '/lib/run_report.php';

header('Content-Type: application/json; charset=utf-8');

$clientIp = machine_api_client_ip();
$action = request_string($_GET, 'action'); // array-safe: `?action[]=x` must not 500 (lib/request.php)

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    machine_api_json(['error' => 'Method not allowed'], 405);
}

// ADR-0032: adopt the PowerShell client's per-run correlation id for this
// request's audit lines. Diagnostic only; an invalid header is ignored.
$headerCorrelation = (string) ($_SERVER['HTTP_X_VIRTUSPHERE_CORRELATION'] ?? '');
if (virtusphere_correlation_id_is_valid($headerCorrelation)) {
    virtusphere_correlation_adopt($headerCorrelation);
}

try {
    // The report token, when configured, authenticates the server sync channel:
    // legacy heartbeats and the reportRun result channel. Client phase reports
    // (reportPhase) authenticate by their already-known MAC (see below) and
    // deliberately do not require the token, so it never has to be provisioned
    // onto the ephemeral deploy VMs (ADR-0018).
    $tokenGatedAction = $action === 'heartbeat' || $action === 'reportRun';
    if ($tokenGatedAction
        && !machine_api_report_token_ok($connection, $_SERVER['HTTP_X_VIRTUSPHERE_TOKEN'] ?? null)) {
        machine_api_audit_warning($connection, 'mecm_report_token', 'Rejected ' . $action . ' with invalid token from ' . $clientIp, $clientIp);
        machine_api_json(['error' => 'Invalid token'], 401);
    }

    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > VIRTUSPHERE_CLIENT_EVENT_MAX_BODY_BYTES) {
        machine_api_json(['error' => 'Payload too large'], 413);
    }

    $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        machine_api_json(['error' => 'Invalid JSON body'], 400);
    }

    $ipAllowed = machine_api_ip_allowed($connection, $clientIp);

    if ($action === 'reportPhase') {
        $mac = (string) ($data['mac'] ?? '');
        if ($mac === '' || !filter_var($mac, FILTER_VALIDATE_MAC)) {
            machine_api_json(['error' => 'Invalid MAC address'], 400);
        }
        $mac = virtusphere_normalize_mac($mac) ?? $mac;

        $phase = (string) ($data['phase'] ?? '');
        if (!in_array($phase, VIRTUSPHERE_CLIENT_PHASES, true)) {
            machine_api_json(['error' => 'Invalid phase'], 400);
        }

        $event = (string) ($data['event'] ?? '');
        if (!in_array($event, VIRTUSPHERE_CLIENT_EVENTS, true)) {
            machine_api_json(['error' => 'Invalid event'], 400);
        }

        if (!$ipAllowed && !machine_api_mac_allowed($connection, $mac)) {
            machine_api_forbidden($clientIp, $connection);
        }

        $stmt = $connection->prepare('SELECT vm_id FROM deploy_interfaces WHERE mac = ? LIMIT 1');
        $stmt->bind_param('s', $mac);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!is_array($row)) {
            machine_api_json(['error' => 'Unknown MAC address'], 404);
        }
        $vmId = (int) $row['vm_id'];

        $detail = repo_client_event_sanitize_detail(isset($data['detail']) ? (string) $data['detail'] : null);

        if (repo_client_event_is_duplicate($connection, $vmId, $phase, $event, $detail)) {
            machine_api_json(['success' => true, 'vm_id' => $vmId, 'deduplicated' => true]);
        }

        if (repo_client_event_count_recent($connection, $vmId, 86400) >= VIRTUSPHERE_CLIENT_EVENT_MAX_PER_DAY) {
            machine_api_audit_warning($connection, 'mecm_report_flood', 'Daily client event cap reached for vm ' . $vmId . ' (mac ' . $mac . ')', $clientIp);
            machine_api_json(['error' => 'Too many events'], 429);
        }

        repo_record_client_event($connection, $vmId, $mac, $phase, $event, $detail, $clientIp);
        machine_api_json(['success' => true, 'vm_id' => $vmId]);
    }

    if ($action === 'heartbeat') {
        if (!$ipAllowed) {
            machine_api_forbidden($clientIp, $connection);
        }

        $source = (string) ($data['source'] ?? '');
        if (!in_array($source, VIRTUSPHERE_INTEGRATION_WIRE_SOURCES, true)) {
            machine_api_json(['error' => 'Invalid source'], 400);
        }

        $interval = (int) ($data['interval_seconds'] ?? 0);
        if ($interval < VIRTUSPHERE_HEARTBEAT_INTERVAL_MIN_SECONDS || $interval > VIRTUSPHERE_HEARTBEAT_INTERVAL_MAX_SECONDS) {
            machine_api_json(['error' => 'Invalid interval_seconds'], 400);
        }

        $detail = repo_client_event_sanitize_detail(isset($data['detail']) ? (string) $data['detail'] : null);
        if ($detail !== null) {
            $detail = mb_substr($detail, 0, 255);
        }

        repo_touch_integration_heartbeat($connection, $source, $clientIp, $interval, $detail);
        machine_api_json(['success' => true, 'source' => $source]);
    }

    if ($action === 'reportRun') {
        if (!$ipAllowed) {
            machine_api_forbidden($clientIp, $connection);
        }

        $validated = run_report_validate($data);
        if (isset($validated['error'])) {
            machine_api_json(['error' => $validated['error']], $validated['status']);
        }

        $report = $validated['report'];
        $report['ip'] = $clientIp;
        $result = repo_record_run_report($connection, $report);

        // The one-time switch from legacy heartbeats to V2 result reports is the
        // only per-source event worth an audit line; individual runs are not
        // logged (ADR-0018). The ratchet guarantees it fires once per source.
        if (!empty($result['legacy_to_v2'])) {
            machine_api_audit_warning(
                $connection,
                'mecm_report_v2:' . $report['source'],
                'reporter ' . $report['source'] . ' switched from legacy heartbeats to V2 result reports',
                $clientIp
            );
        }

        $response = ['success' => true, 'source' => $report['source']];
        if (!empty($result['deduplicated'])) {
            $response['deduplicated'] = true;
        }
        machine_api_json($response);
    }

    machine_api_json(['message' => 'Invalid action specified'], 400);
} catch (JsonException) {
    machine_api_json(['error' => 'Invalid JSON body'], 400);
} catch (Throwable $exception) {
    machine_api_log_warning('mecm_report', $exception::class . ': ' . $exception->getMessage());
    machine_api_json(['error' => 'Interner Serverfehler'], 500);
}
