<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/deploy_blockers.php';
require_once __DIR__ . '/../lib/deploy_preflight_bounds.php';

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
    // The complete decision for the complete scope. `count` and `can_queue` are
    // derived from it BEFORE any bound applies: a mission whose findings do not
    // fit into one response is still a mission that must not be queued.
    $blockers = deploy_queue_blockers($connection, $state);
    $warnings = deploy_queue_warnings($connection, $state);
    $count = count($blockers);

    $boundedBlockers = deploy_preflight_bounded_findings($blockers);
    $boundedWarnings = deploy_preflight_bounded_findings($warnings);
    $serialized = [];
    foreach ($boundedBlockers['items'] as $blocker) {
        $serialized[] = deploy_blocker_json($blocker, $user);
    }
    $serializedWarnings = [];
    foreach ($boundedWarnings['items'] as $warning) {
        $serializedWarnings[] = deploy_blocker_json($warning, $user);
    }

    $envelope = [
        'ok' => true,
        'count' => $count,
        'can_queue' => $count === 0,
        'blockers' => $serialized,
        'total' => $boundedBlockers['total'],
        'omitted_count' => $boundedBlockers['omitted_count'],
        'warnings' => $serializedWarnings,
        'warning_total' => $boundedWarnings['total'],
        'warning_omitted_count' => $boundedWarnings['omitted_count'],
        'labels' => [
            'prefix' => __t('deploy.blocker_prefix'),
            'count' => __t($count === 1 ? 'deploy.blocker_count_one' : 'deploy.blocker_count_many', ['count' => $count]),
            'jump' => __t('deploy.blocker_jump'),
            'warning_prefix' => __t('deploy.warning_prefix'),
            'omitted' => '',
        ],
    ];
    // Only the blocker list is shortened by the byte bound; `count` and
    // `can_queue` above are already fixed and stay the whole truth about the
    // scope, whatever fits below them.
    //
    // Two different numbers, on purpose: `omitted_count` says what this PAYLOAD
    // left out, `labels.omitted` says what the RENDERED list leaves out, and the
    // client paints at most the initial limit of what it was sent. Both are
    // computed inside the capping loop from the list that actually survived, so
    // neither can name a number the response no longer matches. The sentence is
    // localized here because a translated string must never be built in JS.
    echo deploy_preflight_bounded_json(
        $envelope,
        'blockers',
        $boundedBlockers['total'],
        VIRTUSPHERE_DEPLOY_PREFLIGHT_JSON_MAX_BYTES,
        'total',
        'omitted_count',
        static function (array $payload, int $omitted): array {
            $rendered = min(count((array) $payload['blockers']), VIRTUSPHERE_DEPLOY_PREFLIGHT_INITIAL_LIMIT);
            $notRendered = max(0, (int) $payload['total'] - $rendered);
            $payload['labels']['omitted'] = $notRendered > 0
                ? __t('deploy.blocker_omitted', ['count' => $notRendered])
                : '';
            return $payload;
        }
    )['json'];
} catch (InvalidArgumentException) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => __t('portal.invalid_request')], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => __t('deploy.blocker_refresh_failed')], JSON_THROW_ON_ERROR);
}
