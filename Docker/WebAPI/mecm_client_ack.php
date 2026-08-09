<?php

declare(strict_types=1);

// Explicit client-ready acknowledgement (ADR-0019/E3). Configuration remains
// a side-effect-free GET on mecm-api.php; only this POST advances the VM to 5/5.
// It intentionally does not live in mecm_report.php: that channel is display-
// only telemetry by ADR-0018 and must never mutate lifecycle state.

require_once __DIR__ . '/lib/errors.php';
virtusphere_error_response_mode('json');

require_once __DIR__ . '/mysql.php';
require_once __DIR__ . '/lib/machine_api.php';
require_once __DIR__ . '/lib/repo/status_events.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    machine_api_json(['error' => 'Method not allowed'], 405);
}

$clientIp = machine_api_client_ip();

// ADR-0032: preserve a valid client correlation id for endpoint diagnostics.
$headerCorrelation = (string) ($_SERVER['HTTP_X_VIRTUSPHERE_CORRELATION'] ?? '');
if (virtusphere_correlation_id_is_valid($headerCorrelation)) {
    virtusphere_correlation_adopt($headerCorrelation);
}

try {
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > VIRTUSPHERE_CLIENT_EVENT_MAX_BODY_BYTES) {
        machine_api_json(['error' => 'Payload too large'], 413);
    }

    $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        machine_api_json(['error' => 'Invalid JSON body'], 400);
    }

    $mac = (string) ($data['mac'] ?? '');
    if ($mac === '' || !filter_var($mac, FILTER_VALIDATE_MAC)) {
        machine_api_json(['error' => 'Invalid MAC address'], 400);
    }
    $mac = virtusphere_normalize_mac($mac) ?? $mac;

    if (!machine_api_ip_allowed($connection, $clientIp) && !machine_api_mac_allowed($connection, $mac)) {
        machine_api_forbidden($clientIp, $connection, 'mecm_client_ack.php');
    }

    $connection->begin_transaction();
    try {
        // The lock makes a retry after an uncertain network result idempotent:
        // concurrent ACKs serialize and only the first writes a history event.
        $stmt = $connection->prepare('SELECT v.id, v.lifecycle_state, v.mecm_sync_state, v.vm_status FROM deploy_vms v JOIN deploy_interfaces i ON i.vm_id = v.id WHERE i.mac = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('s', $mac);
        $stmt->execute();
        $vm = $stmt->get_result()->fetch_assoc();
        if (!is_array($vm)) {
            $connection->rollback();
            machine_api_json(['error' => 'Unknown MAC address'], 404);
        }

        $vmId = (int) $vm['id'];
        if ((string) $vm['lifecycle_state'] === VIRTUSPHERE_LIFECYCLE_OS_INSTALLED
            && (string) $vm['mecm_sync_state'] === VIRTUSPHERE_MECM_SYNC_REGISTERED
            && (string) $vm['vm_status'] === VIRTUSPHERE_STATUS_OS_INSTALLED
        ) {
            $connection->commit();
            machine_api_json(['success' => true, 'vm_id' => $vmId, 'deduplicated' => true]);
        }

        if (!repo_set_vm_state_forward(
            $connection,
            $vmId,
            VIRTUSPHERE_LIFECYCLE_OS_INSTALLED,
            VIRTUSPHERE_MECM_SYNC_REGISTERED,
            VIRTUSPHERE_STATUS_OS_INSTALLED,
            null,
            'mecm client ready acknowledgement'
        )) {
            $connection->rollback();
            machine_api_json(['error' => 'Unknown MAC address'], 404);
        }

        $connection->commit();
        machine_api_json(['success' => true, 'vm_id' => $vmId]);
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
} catch (JsonException) {
    machine_api_json(['error' => 'Invalid JSON body'], 400);
} catch (Throwable $exception) {
    machine_api_log_warning('mecm_client_ack', $exception::class . ': ' . $exception->getMessage());
    machine_api_json(['error' => 'Interner Serverfehler'], 500);
}
