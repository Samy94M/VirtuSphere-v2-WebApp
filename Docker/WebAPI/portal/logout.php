<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    exit(__t('portal.invalid_request'));
}

if (!csrf_verify($_POST['_csrf'] ?? null)) {
    // No session left: the token is stale because the session already went away.
    // Finish the sign-out quietly instead of refusing it; there is nothing to
    // protect and nothing worth recording.
    if (empty($_SESSION['user_id'])) {
        logout();
        header('Location: login.php');
        exit;
    }

    portal_reject_csrf($connection, ['id' => (int) $_SESSION['user_id']], 'logout.php', __t('portal.invalid_request'));
}

// Audit before logout(): it wipes the session this entry is attributed to.
audit_auth($connection, 'logout', (int) ($_SESSION['user_id'] ?? 0) ?: null);
logout();
header('Location: login.php');
exit;
