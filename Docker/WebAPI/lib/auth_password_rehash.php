<?php

declare(strict_types=1);

/**
 * Best-effort password modernization. A valid sign-in must not fail merely
 * because the optional replacement write fails; the old hash remains valid.
 */
function auth_rehash_password_if_needed(mysqli $db, int $userId, string $plaintext, string $storedHash): void
{
    if (!password_needs_rehash($storedHash, PASSWORD_DEFAULT) || strlen($plaintext) > VIRTUSPHERE_PASSWORD_MAX_BYTES) {
        return;
    }

    try {
        $hash = password_hash($plaintext, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE deploy_users SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $userId);
        $stmt->execute();
    } catch (Throwable) {
        // Deliberately silent: the user is signed in either way.
    }
}
