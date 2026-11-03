<?php

declare(strict_types=1);

// Machine report channel (ADR-0018): Windows clients report deploy-phase
// events by MAC, MECM server sync loops report heartbeats. POST-only, JSON.
// This endpoint never mutates deploy_vms lifecycle state. The explicit
// mecm_client_ack.php POST owns the client-ready transition (ADR-0019/E3).
// This channel remains display-only telemetry.

// JSON on the wire even for an uncaught error; must precede mysql.php, which
// connects while it loads (see virtusphere_error_response_mode).
require_once __DIR__ . '/lib/errors.php';
virtusphere_error_response_mode('json');

require_once __DIR__ . '/mysql.php';
require_once __DIR__ . '/lib/machine_api.php';
require_once __DIR__ . '/lib/repo/client_events.php';
require_once __DIR__ . '/lib/repo/heartbeats.php';
require_once __DIR__ . '/lib/repo/package_runs.php';
require_once __DIR__ . '/lib/run_report.php';
require_once __DIR__ . '/lib/package_run_report.php';

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
        machine_api_audit_warning(
            $connection,
            VIRTUSPHERE_AUDIT_EVENT_MECM_REPORT_TOKEN_REJECTED,
            'machine_endpoint',
            'mecm_report.php',
            VIRTUSPHERE_AUDIT_RESULT_DENIED,
            ['action' => $action],
            $clientIp
        );
        machine_api_json(['error' => 'Invalid token'], 401);
    }

    $bodyLimit = $action === 'reportPackageRun'
        ? VIRTUSPHERE_PACKAGE_REPORT_MAX_BODY_BYTES : VIRTUSPHERE_CLIENT_EVENT_MAX_BODY_BYTES;
    $raw = (string) file_get_contents('php://input', false, null, 0, $bodyLimit + 1);
    if (strlen($raw) > $bodyLimit) {
        machine_api_json(['error' => 'Payload too large'], 413);
    }

    $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        machine_api_json(['error' => 'Invalid JSON body'], 400);
    }

    // K20: client reports versus the MECM server's token-gated reports.
    $ipAllowed = machine_api_ip_allowed($connection, $clientIp, match ($action) {
        'reportPackageRun' => 'package_report',
        'reportPhase' => 'client_phase',
        default => 'server_report',
    });

    if ($action === 'reportPackageRun') {
        if (!$ipAllowed) {
            machine_api_forbidden($clientIp, $connection);
        }

        $validated = package_run_report_validate($data);
        if (isset($validated['error'])) {
            machine_api_json(['error' => $validated['error']], $validated['status']);
        }
        $report = $validated['report'];
        $resolved = repo_package_report_resolve_vm($connection, $report['mac_candidates']);
        if (($resolved['status'] ?? 500) !== 200) {
            machine_api_json(['error' => $resolved['error'] ?? 'device_lookup_failed'], (int) ($resolved['status'] ?? 500));
        }
        $result = repo_package_report_record($connection, (int) $resolved['vm_id'], $report);
        if (($result['status'] ?? 500) !== 200) {
            machine_api_json(['error' => $result['error'] ?? 'report_rejected'], (int) ($result['status'] ?? 500));
        }

        machine_api_json([
            'schema_version' => VIRTUSPHERE_PACKAGE_REPORT_SCHEMA_VERSION,
            'run_id' => $report['run_id'],
            'event' => $report['event'],
            'event_seq' => $report['event_seq'],
            'accepted' => (bool) ($result['accepted'] ?? false),
            'deduplicated' => (bool) ($result['deduplicated'] ?? false),
        ]);
    }

    if ($action === 'reportPhase') {
        // Every field below is read only when it is a string (MA-02): an array
        // made the cast throw into a 500, and a number was coerced silently. The
        // existing 400 body of each field answers the refusal.
        $mac = is_string($data['mac'] ?? null) ? (string) $data['mac'] : '';
        if ($mac === '' || !filter_var($mac, FILTER_VALIDATE_MAC)) {
            machine_api_json(['error' => 'Invalid MAC address'], 400);
        }
        $mac = virtusphere_normalize_mac($mac) ?? $mac;

        $phase = is_string($data['phase'] ?? null) ? (string) $data['phase'] : '';
        if (!in_array($phase, VIRTUSPHERE_CLIENT_PHASES, true)) {
            machine_api_json(['error' => 'Invalid phase'], 400);
        }

        $event = is_string($data['event'] ?? null) ? (string) $data['event'] : '';
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

        // The detail is free text: absent (null) or a string. Checked after the
        // MAC lookup on purpose, so an unknown MAC keeps answering 404 (MA-02).
        $rawDetail = $data['detail'] ?? null;
        if ($rawDetail !== null && !is_string($rawDetail)) {
            machine_api_json(['error' => 'Invalid data format'], 400);
        }
        $detail = repo_client_event_sanitize_detail($rawDetail);

        // MA-11: duplicate check, daily cap and insert share one locked transaction.
        $outcome = repo_client_event_record_once($connection, $vmId, $mac, $phase, $event, $detail, $clientIp);
        if ($outcome === 'duplicate') {
            machine_api_json(['success' => true, 'vm_id' => $vmId, 'deduplicated' => true]);
        }
        if ($outcome === 'capped') {
            // Scoped to the VM, not to the client IP: a VM that hits its cap
            // must not silence the first cap of a different VM that happens to
            // report from the same address. The MAC is deliberately not carried;
            // the VM id identifies the row without duplicating an identifier
            // that appears nowhere else in this category.
            machine_api_audit_warning(
                $connection,
                VIRTUSPHERE_AUDIT_EVENT_MECM_CLIENT_CAP,
                'vm',
                $vmId,
                VIRTUSPHERE_AUDIT_RESULT_WARNING,
                [],
                $clientIp,
                'vm-' . $vmId
            );
            machine_api_json(['error' => 'Too many events'], 429);
        }

        machine_api_json(['success' => true, 'vm_id' => $vmId]);
    }

    if ($action === 'heartbeat') {
        if (!$ipAllowed) {
            machine_api_forbidden($clientIp, $connection);
        }

        $source = is_string($data['source'] ?? null) ? (string) $data['source'] : '';
        if (!in_array($source, VIRTUSPHERE_INTEGRATION_WIRE_SOURCES, true)) {
            machine_api_json(['error' => 'Invalid source'], 400);
        }

        // MA-02: only a JSON integer is an interval. An absent value, a string such as
        // "12abc", an array or a float answers the same 400 as an out-of-range one.
        $rawInterval = $data['interval_seconds'] ?? null;
        $interval = is_int($rawInterval) ? $rawInterval : 0;
        if ($interval < VIRTUSPHERE_HEARTBEAT_INTERVAL_MIN_SECONDS || $interval > VIRTUSPHERE_HEARTBEAT_INTERVAL_MAX_SECONDS) {
            machine_api_json(['error' => 'Invalid interval_seconds'], 400);
        }

        $rawDetail = $data['detail'] ?? null;
        if ($rawDetail !== null && !is_string($rawDetail)) {
            machine_api_json(['error' => 'Invalid data format'], 400);
        }
        $detail = repo_client_event_sanitize_detail($rawDetail);
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
                VIRTUSPHERE_AUDIT_EVENT_MECM_REPORTER_UPGRADED,
                'integration_source',
                (string) $report['source'],
                VIRTUSPHERE_AUDIT_RESULT_RECOVERED,
                ['report_version' => VIRTUSPHERE_REPORT_CHANNEL_VERSION],
                $clientIp,
                (string) $report['source']
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
