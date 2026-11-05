<?php

declare(strict_types=1);

/**
 * SEC3 (05.11.2026): the sign-in counter keeps a keyed hash of the typed name,
 * never the name itself, and a lock binds a local name to the failing address
 * instead of the whole account (C-04c).
 *
 *  - `deploy_login_attempts.username` holds `auth_login_name_key()` (64 hex
 *    characters, HMAC under APP_KEY) for every row the new code writes. Rows from
 *    before keep whatever was typed, possibly a password typed into the name
 *    field, for up to seven days; they are deleted here. The counter only looks
 *    back 15 minutes, so the one effect is a fresh failure budget at the update.
 *  - `deploy_users.locked_until` is no longer written or read; a lock is the
 *    per-address failure count. A lock that the old code set is cleared so it
 *    cannot outlive the update unseen. The column stays (the AD shape CHECK
 *    names it); dropping it would be a contract boundary for no gain.
 *
 * Class N in docs/operations/upgrade-recovery.md: the deleted rows are not
 * reconstructed. Older code still runs on the schema; it ignores the hashed
 * rows in its per-name count and keeps its own global lock. Idempotent: a rerun
 * finds only key-shaped rows and no lock.
 */
function migrate_0073_login_attempt_name_keys(mysqli $db): void
{
    if (migrator_table_exists($db, 'deploy_login_attempts')) {
        $db->query("DELETE FROM deploy_login_attempts WHERE username NOT REGEXP '^[0-9a-f]{64}$'");
    }
    if (migrator_column_exists($db, 'deploy_users', 'locked_until')) {
        $db->query('UPDATE deploy_users SET locked_until = NULL, updated_at = updated_at WHERE locked_until IS NOT NULL');
    }
}
