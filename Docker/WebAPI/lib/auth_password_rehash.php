<?php

declare(strict_types=1);

/**
 * Stand-in hash for a sign-in without a usable stored hash (unknown name,
 * locked or deactivated account). It is the hash of a discarded random value,
 * so no password matches it; it is not a credential. Its only job is to cost
 * exactly what a real comparison costs, otherwise the response time tells an
 * anonymous caller which names exist (SEC1, C-04). It therefore carries the
 * same algorithm and cost as PASSWORD_DEFAULT, the target every stored hash is
 * lifted to on sign-in; AuthBlindHashTest derives that through
 * password_needs_rehash() instead of pinning a cost number.
 */
const VIRTUSPHERE_AUTH_BLIND_PASSWORD_HASH = '$2y$12$A8vAyXEMBhtJ9EI9Sdbn3ebTMmlHo4WDdAT7Fathg2WVT8A87L/sm';

/**
 * One password comparison, real or blind, so every local sign-in path spends
 * the same work before it answers.
 */
function auth_password_verify_constant_cost(string $password, mixed $storedHash): bool
{
    $usable = is_string($storedHash) && password_get_info($storedHash)['algo'] !== null;

    return password_verify($password, $usable ? $storedHash : VIRTUSPHERE_AUTH_BLIND_PASSWORD_HASH) && $usable;
}

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
