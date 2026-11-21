<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
// The cells and regions are the pages' own renderers, which use the layout helpers.
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/vm_status_live.php';
require_once __DIR__ . '/../lib/vm_status_live_views.php';

/** @var mysqli $connection Provided by bootstrap.php. */

// The live status of the VM list, the VM overview, the VM editor and the
// dashboard tiles (VB2): read-only JSON, polled by assets/vm_status_live.js.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => __t('portal.invalid_request')], JSON_THROW_ON_ERROR);
    exit;
}

$user = current_user($connection);
$mustChangePassword = $user !== null && (int) ($user['must_change_password'] ?? 0) === 1;
// The permissions the editor's "check progress" block renders by. Read access
// itself is the signed-in user, as on the four pages.
$access = [
    'can_write' => $user !== null && can('vms.write', $user),
    'can_system_config' => $user !== null && can('system.config', $user),
];

// R35: identity, permission and locale are decided above (the bootstrap loaded
// the locale, current_user() may have persisted a lazy expiry). Release the
// session before the first status query: a poll every few seconds that held the
// lock would make every other page of this session wait behind it. Nothing
// below reads the session; a poll does not extend its lifetime either.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($user === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => __t('vm_live.session_expired')], JSON_THROW_ON_ERROR);
    exit;
}
if ($mustChangePassword) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => __t('vm_live.forbidden')], JSON_THROW_ON_ERROR);
    exit;
}

try {
    $request = vm_live_request($_GET);
    $payload = vm_live_payload($connection, $request, $access);
    echo json_encode(vm_live_envelope($request['view'], $payload, time()), JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => __t('portal.invalid_request')], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => __t('vm_live.failed')], JSON_THROW_ON_ERROR);
}
