<?php

declare(strict_types=1);

require_once __DIR__ . '/app_key_rotate.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/envboot.php';
require_once __DIR__ . '/backup_status.php';
require_once __DIR__ . '/log_redaction.php';
require_once __DIR__ . '/repo/helpers.php';

/**
 * The command-line layer of the APP_KEY rotation tool (K22-E8): the --apply and --actor= argument checks, the
 * progress output of a run and the command entry. The entry point stays lib/app_key_rotate.php, which requires
 * this file and runs the command only when it is executed directly.
 */

/**
 * Splits the arguments into the `--apply` switch and the raw `--actor=` value. Each switch may appear once, in any order.
 * Anything else returns null, which the CLI answers with the usage line.
 *
 * @param list<string> $arguments the arguments after the script name
 * @return array{apply:bool,actor:?string}|null
 */
function app_key_rotate_parse_arguments(array $arguments): ?array
{
    $apply = false;
    $actor = null;
    foreach ($arguments as $argument) {
        if ($argument === '--apply' && !$apply) {
            $apply = true;
        } elseif (str_starts_with($argument, '--actor=') && $actor === null) {
            $actor = substr($argument, strlen('--actor='));
        } else {
            return null;
        }
    }

    return ['apply' => $apply, 'actor' => $actor];
}

/**
 * The user id of a raw `--actor=` value, or null. Only the plain decimal form of a positive id that fits the INT
 * column of deploy_logs.user_id is accepted: no sign, no leading zero, no whitespace.
 */
function app_key_rotate_actor_id(string $raw): ?int
{
    if (preg_match('/^[1-9][0-9]{0,9}$/', $raw) !== 1) {
        return null;
    }
    $id = (int) $raw;

    // mysqli binds an integer as a 32-bit value: a larger id would be stored as a different one.
    return $id <= 2147483647 ? $id : null;
}

/** Whether a deploy user with this id exists. The audit row names the user only when one does. */
function app_key_rotate_actor_exists(mysqli $db, int $userId): bool
{
    return repo_fetch_one($db, 'SELECT id FROM deploy_users WHERE id = ? LIMIT 1', 'i', [$userId]) !== null;
}

/**
 * Command line entry. The old APP_KEY arrives on STDIN; `--apply` commits, and no argument is a dry run.
 * `--actor=<user id>` names the deploy user the audit row is written for; without it the row names no user.
 *
 * `$backupStatusPath` is the backup status file the `--apply` gate reads. The entry point passes nothing, so the gate
 * reads the container mount (VIRTUSPHERE_BACKUP_STATUS_PATH); a test passes its own status file to run a successful apply.
 *
 * @param list<string> $arguments the arguments after the script name
 * @param string|null $backupStatusPath null for the container mount
 */
function app_key_rotate_cli(array $arguments, ?string $backupStatusPath = null): int
{
    $usage = 'usage: php lib/app_key_rotate.php [--apply] [--actor=<user id>] < file-with-the-old-APP_KEY' . PHP_EOL;
    $parsed = app_key_rotate_parse_arguments($arguments);
    if ($parsed === null) {
        fwrite(STDERR, $usage);

        return 2;
    }
    $apply = $parsed['apply'];

    // The actor is decided before anything else happens: a refused actor reads no key and changes nothing. An unknown
    // id would otherwise fail the audit row at the very end of the run, which rolls the whole rotation back.
    $actor = null;
    if ($parsed['actor'] !== null) {
        $actor = app_key_rotate_actor_id($parsed['actor']);
        if ($actor === null) {
            fwrite(STDERR, '--actor must be the positive integer id of a deploy user, without leading zeros' . PHP_EOL);

            return 2;
        }
        try {
            $exists = app_key_rotate_actor_exists(db(), $actor);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'could not check --actor: ' . virtusphere_redact_log_text($exception->getMessage()) . PHP_EOL);

            return 1;
        }
        if (!$exists) {
            fwrite(STDERR, 'no deploy user has the id ' . $actor . PHP_EOL);

            return 2;
        }
    }

    // The old key comes on STDIN, never as an argument: arguments show up in the process list and the shell history.
    $line = fgets(STDIN);
    if ($line === false || trim($line) === '') {
        fwrite(STDERR, $usage);

        return 2;
    }
    try {
        $oldKey = envboot_parse_app_key($line);
    } catch (RuntimeException) {
        fwrite(STDERR, 'the old key on STDIN is not a valid APP_KEY' . PHP_EOL);

        return 2;
    }
    app_key_rotate_wipe($line);

    try {
        $newKey = envboot_app_key_bytes();
    } catch (RuntimeException $exception) {
        app_key_rotate_wipe($oldKey);
        fwrite(STDERR, virtusphere_redact_log_text($exception->getMessage()) . PHP_EOL);

        return 1;
    }

    try {
        return app_key_rotate_run($oldKey, $newKey, $apply, $actor, $backupStatusPath);
    } finally {
        app_key_rotate_wipe($oldKey);
        app_key_rotate_wipe($newKey);
    }
}

/**
 * The two progress units of a run: the backup gate, then the re-encryption. The gate applies only to
 * an apply, because a dry run changes nothing. A refusal ends the run with a FAIL line and exit 1.
 *
 * @param string|null $backupStatusPath the backup status file of the gate; null for the container mount
 */
function app_key_rotate_run(string $oldKey, string $newKey, bool $apply, ?int $actor = null, ?string $backupStatusPath = null): int
{
    fwrite(STDOUT, '[1/2] RUN backup check' . PHP_EOL);
    if ($apply) {
        $blocker = app_key_rotate_backup_blocker(backup_status_read($backupStatusPath), time());
        if ($blocker !== null) {
            fwrite(STDERR, '[1/2] FAIL backup check: refused ' . $blocker . ' (take a backup with scripts/backup.sh first; the newest backup status must be ok and at most '
                . intdiv(VIRTUSPHERE_APP_KEY_ROTATE_BACKUP_MAX_AGE_SECONDS, 3600) . ' h old)' . PHP_EOL);

            return 1;
        }
        fwrite(STDOUT, '[1/2] PASS backup check' . PHP_EOL);
    } else {
        fwrite(STDOUT, '[1/2] SKIP backup check (dry run)' . PHP_EOL);
    }

    fwrite(STDOUT, '[2/2] RUN re-encrypt' . PHP_EOL);
    try {
        $result = app_key_rotate(db(), $oldKey, $newKey, $apply, null, $actor);
    } catch (AppKeyRotationRefused $exception) {
        fwrite(STDERR, '[2/2] FAIL re-encrypt: refused ' . $exception->reason . ': ' . virtusphere_redact_log_text($exception->getMessage()) . PHP_EOL);

        return 1;
    } catch (Throwable $exception) {
        fwrite(STDERR, '[2/2] FAIL re-encrypt: ' . virtusphere_redact_log_text($exception->getMessage()) . PHP_EOL);

        return 1;
    }

    fwrite(STDOUT, '[2/2] PASS re-encrypt (' . ($apply ? 'applied' : 'dry run') . '): credentials=' . $result['credentials']
        . ' directory=' . $result['directory'] . ' already=' . $result['already'] . PHP_EOL);

    return 0;
}
