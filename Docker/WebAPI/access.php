<?php

declare(strict_types=1);

// JSON on the wire even for an uncaught error; must precede function.php, which
// pulls in mysql.php, and that connects while it loads (see
// virtusphere_error_response_mode).
require_once __DIR__ . '/lib/errors.php';
virtusphere_error_response_mode('json');

require_once __DIR__ . '/function.php';
require_once __DIR__ . '/lib/machine_api.php';

function legacy_json(mixed $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function legacy_body_json(): mixed
{
    $body = file_get_contents('php://input');
    if ($body === false || trim($body) === '') {
        return null;
    }

    return json_decode($body);
}

function legacy_string(string $key, string $source = 'get'): string
{
    // request_trimmed, not a raw cast: `?token[]=x` must not throw into a 500.
    return request_trimmed($source === 'post' ? $_POST : $_GET, $key);
}

function legacy_int(string $key): int
{
    return max(0, request_int($_GET, $key, request_int($_POST, $key)));
}

function legacy_vm_create_response(mysqli $connection, int $missionId, mixed $vmList, string $action, bool $failOnEmpty): never
{
    $count = vmListToCreate($missionId, $vmList, $connection);
    legacy_json(['success' => $count > 0, 'action' => $action, 'VMS' => $count], $failOnEmpty && $count <= 0 ? 500 : 200);
}

function legacy_vm_update_response(mysqli $connection, mixed $vmList, string $action): never
{
    $count = vmListToUpdate($vmList, $connection);
    legacy_json(['success' => $count > 0, 'action' => $action, 'VMS' => $count], $count > 0 ? 200 : 500);
}

function legacy_vm_delete_response(mysqli $connection, mixed $vmList, string $action): never
{
    $ok = vmListToDelete($vmList, $connection);
    legacy_json(['success' => $ok === true, 'action' => $action, 'VMS' => $ok], $ok ? 200 : 500);
}

$token = legacy_string('token');
if (!verifyToken($token, $connection)) {
    // The SUCCESSFUL token issuance was logged and the rejection was not, which
    // is the wrong direction: a valid login is routine, a rejected token is
    // either a stale desktop client or somebody probing. The token itself never
    // reaches the log (redaction is the whole point of the throttle scope being
    // the IP), and the same window keeps a looping client from flooding it.
    machine_api_audit_warning(
        $connection,
        'legacy_token_denied',
        'Rejected legacy token from ' . (machine_api_client_ip() ?: 'an unknown IP') . ' for action ' . (request_string($_GET, 'action', request_string($_POST, 'action')) ?: '(none)'),
        machine_api_client_ip(),
        VIRTUSPHERE_LOG_CATEGORY_MACHINE_API,
        machine_api_client_ip()
    );
    header('HTTP/1.1 418 I\'m a teapot');
    legacy_json('Access Forbidden', 418);
}

$action = request_string($_GET, 'action', request_string($_POST, 'action')); // array-safe (lib/request.php)

// Mutating legacy actions require the same RBAC permission the portal enforces,
// resolved from the token's owning user. Read-only actions are not listed and
// stay reachable for any valid token.
$legacyActionPermissions = [
    'addVM' => 'vms.write',
    'createMission' => 'missions.write',
    'updateMission' => 'missions.write',
    'deleteMission' => 'missions.write',
    'createOS' => 'catalog.write',
    'updateOS' => 'catalog.write',
    'deleteOS' => 'catalog.write',
    'createVLAN' => 'catalog.write',
    'updateVLAN' => 'catalog.write',
    'deleteVLAN' => 'catalog.write',
    'deleteVM' => 'vms.write',
    'sendVMList' => 'vms.write',
    'vmListToCreate' => 'vms.write',
    'vmListToUpdate' => 'vms.write',
    'vmListToDelete' => 'vms.write',
];

$requiredPermission = $legacyActionPermissions[$action] ?? null;
if ($requiredPermission !== null && !role_has_permission(legacyTokenRole($token, $connection), $requiredPermission)) {
    machine_api_log_warning('access', 'RBAC denied action ' . $action . ' (requires ' . $requiredPermission . ')');
    legacy_json(['success' => false, 'message' => 'Forbidden.'], 403);
}

try {
    match ($action) {
        'expandToken' => legacy_json(expandToken($token, $connection)),
        'addVM' => legacy_json(createVM(
            legacy_string('vmName', 'post'),
            legacy_string('vmHostname', 'post'),
            legacy_string('vmIP', 'post'),
            legacy_string('vmSubnet', 'post'),
            legacy_string('vmGateway', 'post'),
            legacy_string('vmDNS1', 'post'),
            legacy_string('vmDNS2', 'post'),
            legacy_string('vmDomain', 'post'),
            legacy_string('vmVLAN', 'post'),
            legacy_string('vmRole', 'post'),
            legacy_string('vmStatus', 'post'),
            $token,
            $connection
        )),
        'getMissions' => legacy_json(getMissions($connection)),
        'getVMs' => legacy_json(getVMs($connection, legacy_int('missionId'))),
        'updateMission' => legacy_json(updateMission($connection, legacy_int('missionId'), legacy_body_json())),
        'getPackages' => legacy_json(getPackages($connection)),
        'deleteMission' => legacy_json(deleteMission(legacy_int('missionId'), $connection)),
        'createMission' => legacy_json(createMission(legacy_string('missionName'), $connection)),
        'getOS' => legacy_json(getOS($connection)),
        'createOS' => legacy_json(createOS(legacy_string('osName'), legacy_string('osStatus'), $connection)),
        'updateOS' => legacy_json(updateOS(legacy_int('osId'), legacy_string('osName'), legacy_string('osStatus'), $connection)),
        'deleteOS' => legacy_json(deleteOS(legacy_int('osId'), $connection)),
        'sendVMList' => legacy_vm_create_response($connection, legacy_int('missionId'), legacy_body_json(), $action, false),
        'getVLANs' => legacy_json(getVLAN($connection)),
        'deleteVLAN' => legacy_json(deleteVLAN(legacy_int('vlanId'), $connection)),
        'createVLAN' => legacy_json(createVLAN(legacy_string('vlanName'), $connection)),
        'updateVLAN' => legacy_json(updateVlan(legacy_int('vlanId'), legacy_string('vlanName'), $connection)),
        'deleteVM' => legacy_json(deleteVM(legacy_body_json(), $connection)),
        'vmListToCreate' => legacy_vm_create_response($connection, legacy_int('missionId'), legacy_body_json(), $action, true),
        'vmListToUpdate' => legacy_vm_update_response($connection, legacy_body_json(), $action),
        'vmListToDelete' => legacy_vm_delete_response($connection, legacy_body_json(), $action),
        default => legacy_json(['success' => false, 'message' => 'Unknown action.'], 404),
    };
} catch (Throwable $exception) {
    machine_api_log_warning('access', $exception::class . ': ' . $exception->getMessage());
    legacy_json(['success' => false, 'message' => 'Internal server error.'], 500);
}