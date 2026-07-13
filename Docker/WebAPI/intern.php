<?php

declare(strict_types=1);

// Legacy overview retired. This is now a pure redirect stub to the portal.
// Logout is only available via /portal/logout.php (POST + CSRF); the old
// GET-based ?action=logout flow has been removed.
header('Location: /portal/dashboard.php', true, 302);
exit;
