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
    } catch (Throwable $exception) {
        // This write runs inside the login's repo_transaction(). A deadlock (1213) or a
        // lock wait timeout (1205) has discarded or left in doubt that transaction, so
        // the caller must see it rather than commit the rest of the login without this step.
        if ($exception instanceof mysqli_sql_exception && in_array($exception->getCode(), [1213, 1205], true)) {
            throw $exception;
        }
        // Anything else leaves the old hash in place, which stays valid; one line says so.
        error_log('[auth] password rehash skipped: ' . $exception::class);
    }
}
