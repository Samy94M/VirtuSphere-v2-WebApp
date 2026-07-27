<?php

declare(strict_types=1);

// JSON on the wire even for an uncaught error; must precede mysql.php, which
// connects while it loads (see virtusphere_error_response_mode).
require_once __DIR__ . '/lib/errors.php';
virtusphere_error_response_mode('json');

require_once __DIR__ . '/mysql.php';
require_once __DIR__ . '/lib/machine_api.php';
require_once __DIR__ . '/lib/repo/status_events.php';
require_once __DIR__ . '/lib/repo/mecm_provenance.php';

header('Content-Type: application/json; charset=utf-8');

$clientIp = machine_api_client_ip();
if (!machine_api_ip_allowed($connection, $clientIp)) {
    machine_api_forbidden($clientIp, $connection);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    machine_api_json(['error' => 'Method not allowed'], 405);
}

$action = request_string($_GET, 'action'); // array-safe (lib/request.php)
if (!in_array($action, ['updateDevice', 'reportMembership'], true)) {
    machine_api_json(['message' => 'Invalid action specified'], 400);
}

if ($action === 'reportMembership') {
    // Additive wire (ADR-0034): the device-sync reports the membership rules
    // it added or removed, and the portal keeps the provenance. Same envelope
    // family as updateDevice: 400 for a malformed report, 404 for an unknown
    // VM (the sync keeps the device queued and says so), 200 on success.
    try {
        $data = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        $vmId = (int) ($data['deviceid'] ?? 0);
        $rawMemberships = $data['memberships'] ?? null;
        if ($vmId <= 0 || !is_array($rawMemberships) || $rawMemberships === []
            || count($rawMemberships) > VIRTUSPHERE_MECM_RULE_REPORT_MAX_ENTRIES) {
            machine_api_json(['error' => 'Invalid data format'], 400);
        }

        $entries = [];
        foreach ($rawMemberships as $rawEntry) {
            $entry = mecm_rule_report_entry($rawEntry);
            if ($entry === null) {
                // The script builds the report from its own applied plan, so a
                // malformed entry is a coding error: reject the whole report
                // before anything is written.
                machine_api_json(['error' => 'Invalid data format'], 400);
            }
            $entries[] = $entry;
        }

        $vmExists = null !== repo_fetch_one($connection, 'SELECT id FROM deploy_vms WHERE id = ? LIMIT 1', 'i', [$vmId]);
        if (!$vmExists) {
            machine_api_audit_warning(
                $connection,
                'mecm_updateid',
                'Membership report for unknown VM id ' . $vmId,
                $clientIp,
                VIRTUSPHERE_LOG_CATEGORY_MECM
            );
            machine_api_json(['error' => 'Unknown VM id'], 404);
        }

        repo_mecm_rules_apply_report($connection, $vmId, $entries, $clientIp);
        machine_api_json(['success' => 'Data updated successfully']);
    } catch (JsonException) {
        machine_api_json(['error' => 'Invalid JSON body'], 400);
    } catch (Throwable $exception) {
        machine_api_log_warning('mecm_updateid', $exception::class . ': ' . $exception->getMessage());
        machine_api_json(['error' => 'Interner Serverfehler'], 500);
    }
}

try {
    $data = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    $mecmId = (string) ($data['deviceResourceID'] ?? '');
    $vmId = (int) ($data['deviceid'] ?? 0);

    if ($mecmId === '' || $vmId <= 0) {
        machine_api_json(['error' => 'Invalid data format'], 400);
    }

    // Forward-only: this endpoint used to write `os_installing` unconditionally,
    // so a VM that had already reported `os_installed` fell visibly back to 4/5
    // every time the device-sync re-reported its ResourceID. The ResourceID is
    // still stored in that case; only the lifecycle is not walked backwards.
    //
    // A false return means the VM id does not exist. The endpoint answered 200
    // "Data updated successfully" for it, and the device-sync reads that as
    // "done": the device left the queue and was never reported again, for a row
    // that was deleted in the portal. 404 lets the sync keep it and say so.
    if (!repo_set_vm_state_forward($connection, $vmId, VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, VIRTUSPHERE_STATUS_OS_INSTALLING, 0, 'mecm update id', $mecmId)) {
        machine_api_audit_warning(
            $connection,
            'mecm_updateid',
            'ResourceID ' . $mecmId . ' reported for unknown VM id ' . $vmId,
            $clientIp,
            VIRTUSPHERE_LOG_CATEGORY_MECM
        );
        machine_api_json(['error' => 'Unknown VM id'], 404);
    }

    machine_api_json(['success' => 'Data updated successfully']);
} catch (JsonException) {
    machine_api_json(['error' => 'Invalid JSON body'], 400);
} catch (Throwable $exception) {
    machine_api_log_warning('mecm_updateid', $exception::class . ': ' . $exception->getMessage());
    machine_api_json(['error' => 'Interner Serverfehler'], 500);
}
