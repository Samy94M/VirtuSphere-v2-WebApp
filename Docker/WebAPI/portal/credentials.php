<?php

declare(strict_types=1);

// Auth, RBAC and request shell only. The POST dispatch lives in
// lib/credentials_actions.php, the view model and both panels in
// lib/credentials_panels.php (ADR-0006). portal_guard_post() and the redirect
// stay here so the whole request shape is readable at the entry point.
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/credentials_actions.php';
require_once __DIR__ . '/../lib/credentials_panels.php';

/** @var mysqli $connection Provided by bootstrap.php. */
$user = portal_require_user($connection);
if (!can('credentials.manage', $user)) {
    portal_forbid($connection, $user, 'credentials.manage');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);
    redirect_to(credentials_handle_post($connection, $user));
}

credentials_render_page($connection, $user);
