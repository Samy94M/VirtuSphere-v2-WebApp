<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repo/directory.php';

/**
 * A restored directory configuration must be proven against the restored
 * environment before it may accept logins. Advancing the revision invalidates
 * every controller validation while retaining the operator's configuration.
 */
function directory_restore_converge(mysqli $db): bool
{
    return repo_transaction($db, function () use ($db): bool {
        $config = repo_directory_config($db, true);
        if ($config === null) {
            return false;
        }
        $actor = $config['updated_by'] !== null ? (int) $config['updated_by'] : null;
        $stmt = $db->prepare(
            'UPDATE deploy_ad_config
             SET enabled = 0, revision = revision + 1,
                 automatic_bind_blocked_revision = NULL,
                 automatic_bind_blocked_at = NULL,
                 automatic_bind_blocked_reason = NULL,
                 updated_by = ?, updated_at = NOW()
             WHERE id = 1'
        );
        $stmt->bind_param('i', $actor);
        $stmt->execute();

        return true;
    });
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    fwrite(STDOUT, "[1/1] RUN directory restore convergence\n");
    try {
        $changed = directory_restore_converge(db());
        fwrite(STDOUT, '[1/1] PASS directory restore convergence (' . ($changed ? 'disabled; controller validation invalidated' : 'not configured') . ")\n");
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, '[1/1] FAIL directory restore convergence: ' . $exception->getMessage() . "\n");
        exit(1);
    }
}
