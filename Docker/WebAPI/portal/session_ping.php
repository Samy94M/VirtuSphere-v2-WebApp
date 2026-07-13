<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify($_POST['_csrf'] ?? null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_request']);
    exit;
}

// current_user() also enforces the absolute lifetime: a session that already
// lapsed returns null here, so an expired user cannot revive it by pinging.
$user = current_user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'expired']);
    exit;
}

session_touch_expiry();

echo json_encode([
    'ok' => true,
    'expires_in' => session_lifetime_seconds($connection),
]);
