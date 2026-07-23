<?php

declare(strict_types=1);

// JSON on the wire even for an uncaught error; must precede mysql.php, which
// connects while it loads (see virtusphere_error_response_mode).
require_once __DIR__ . '/lib/errors.php';
virtusphere_error_response_mode('json');

require_once __DIR__ . '/mysql.php';
require_once __DIR__ . '/lib/machine_api.php';
require_once __DIR__ . '/lib/repo/status_events.php';

header('Content-Type: application/json; charset=utf-8');

$clientIp = machine_api_client_ip();
if (!machine_api_ip_allowed($connection, $clientIp)) {
    machine_api_forbidden($clientIp);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    machine_api_json(['error' => 'Method not allowed'], 405);
}

if (request_string($_GET, 'action') !== 'updateDevice') { // array-safe (lib/request.php)
    machine_api_json(['message' => 'Invalid action specified'], 400);
}

try {
    $data = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    $mecmId = (string) ($data['deviceResourceID'] ?? '');
    $vmId = (int) ($data['deviceid'] ?? 0);

    if ($mecmId === '' || $vmId <= 0) {
        machine_api_json(['error' => 'Invalid data format'], 400);
    }

    repo_set_vm_state($connection, $vmId, VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, VIRTUSPHERE_STATUS_OS_INSTALLING, 0, 'mecm update id', $mecmId);
    machine_api_json(['success' => 'Data updated successfully']);
} catch (JsonException) {
    machine_api_json(['error' => 'Invalid JSON body'], 400);
} catch (Throwable $exception) {
    machine_api_log_warning('mecm_updateid', $exception::class . ': ' . $exception->getMessage());
    machine_api_json(['error' => 'Interner Serverfehler'], 500);
}
