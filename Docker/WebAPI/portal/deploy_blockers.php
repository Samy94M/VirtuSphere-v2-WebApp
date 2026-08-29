<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/deploy_blockers.php';

/** @var mysqli $connection Provided by bootstrap.php. */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => __t('portal.invalid_request')], JSON_THROW_ON_ERROR);
    exit;
}

$user = current_user($connection);
if ($user === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => __t('deploy.blocker_session_expired')], JSON_THROW_ON_ERROR);
    exit;
}
if ((int) ($user['must_change_password'] ?? 0) === 1 || !can('deploy.run', $user)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => __t('deploy.blocker_forbidden')], JSON_THROW_ON_ERROR);
    exit;
}

try {
    $state = deploy_queue_normalize_input($_GET);
    $blockers = deploy_queue_blockers($connection, $state);
    $serialized = [];
    foreach ($blockers as $blocker) {
        $serialized[] = deploy_blocker_json($blocker, $user);
    }

    $count = count($serialized);
    echo json_encode([
        'ok' => true,
        'count' => $count,
        'can_queue' => $count === 0,
        'blockers' => $serialized,
        'labels' => [
            'prefix' => __t('deploy.blocker_prefix'),
            'count' => __t($count === 1 ? 'deploy.blocker_count_one' : 'deploy.blocker_count_many', ['count' => $count]),
            'jump' => __t('deploy.blocker_jump'),
        ],
    ], JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => __t('portal.invalid_request')], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => __t('deploy.blocker_refresh_failed')], JSON_THROW_ON_ERROR);
}
