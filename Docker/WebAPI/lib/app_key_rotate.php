<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/envboot.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/backup_status.php';
require_once __DIR__ . '/log_redaction.php';
require_once __DIR__ . '/audit_event_definitions.php';
require_once __DIR__ . '/repo/helpers.php';
require_once __DIR__ . '/repo/log.php';

// The rotation needs a backup taken just before the key change: if the change goes wrong, the
// previous key and that backup are the way back. Two hours is one maintenance window.
const VIRTUSPHERE_APP_KEY_ROTATE_BACKUP_MAX_AGE_SECONDS = 7200;

/**
 * A refusal carries its reason, so the operator and the trail can tell "wait for the jobs" from
 * "a row needs a person" without reading the sentence.
 */
final class AppKeyRotationRefused extends RuntimeException
{
    public readonly string $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }
}

/**
 * Thrown at the end of a dry run so that repo_transaction() rolls the whole run back. Only
 * app_key_rotate() catches it; every other exception keeps propagating.
 */
final class AppKeyRotationDryRunRollback extends RuntimeException
{
}

/**
 * Why the rotation may not run on this backup status, or null when the newest run is ok and at
 * most VIRTUSPHERE_APP_KEY_ROTATE_BACKUP_MAX_AGE_SECONDS old. Only the newest run counts: an older
 * good backup does not protect a rotation that follows a failed one.
 *
 * @param array{state:string,last:array<string,mixed>|null} $status the result of backup_status_read()
 */
function app_key_rotate_backup_blocker(array $status, int $now): ?string
{
    $last = $status['last'];
    if (!is_array($last) || !isset($last['ts'])) {
        return 'backup_missing';
    }
    if (($last['status'] ?? '') !== 'ok') {
        return 'backup_failed';
    }
    if ($now - (int) $last['ts'] > VIRTUSPHERE_APP_KEY_ROTATE_BACKUP_MAX_AGE_SECONDS) {
        return 'backup_too_old';
    }

    return null;
}

/**
 * Re-encrypts every deploy_credentials and deploy_ad_config secret from $oldKey to $newKey.
 *
 * The running key is the new one: nothing is sealed with a key the configuration does not hold.
 * A dry run ($apply false) performs the same work and rolls it back, so its counts are exactly what
 * an apply would do. A refusal throws AppKeyRotationRefused with reason 'same_key', 'active_jobs' or
 * 'unreadable', and changes nothing. $afterRow runs after each rewritten row, inside the transaction;
 * the rollback test uses it to fail mid-run.
 *
 * @param (callable(string, int): void)|null $afterRow
 * @return array{applied:bool,credentials:int,directory:int,already:int}
 * @throws AppKeyRotationRefused
 * @throws LogicException when called inside a transaction
 */
function app_key_rotate(mysqli $db, string $oldKey, string $newKey, bool $apply, ?callable $afterRow = null): array
{
    // A nested run would not commit by itself: the outer transaction decides, and it may roll back.
    if (repo_transaction_depth($db) > 0) {
        throw new LogicException('app_key_rotate() must not run inside a transaction; its own commit decides whether the rotation stands.');
    }
    if (hash_equals($oldKey, $newKey)) {
        throw new AppKeyRotationRefused('same_key', 'The old and the new APP_KEY are identical; there is nothing to rotate.');
    }

    $counts = ['credentials' => 0, 'directory' => 0, 'already' => 0];
    try {
        repo_transaction($db, function () use ($db, $oldKey, $newKey, $apply, $afterRow, &$counts): void {
            $counts = app_key_rotate_in_transaction($db, $oldKey, $newKey, $apply, $afterRow);
            if (!$apply) {
                // Every write and check of the dry run has happened; this rolls them all back.
                throw new AppKeyRotationDryRunRollback();
            }
        });
    } catch (AppKeyRotationDryRunRollback $sentinel) {
        // Expected for a dry run: the counts are in $counts and the rows are back in their old state.
        // An apply never rolls back on purpose, so a sentinel there is a failure and must propagate.
        if ($apply) {
            throw $sentinel;
        }
    }

    return ['applied' => $apply] + $counts;
}

/**
 * The work of one rotation, inside its transaction: refuse while a job is running or cancelling, classify every row,
 * refuse before the first write if a row reads with neither key, rewrite, verify every rewritten row,
 * then write the audit row in the same transaction.
 *
 * @return array{credentials:int,directory:int,already:int}
 * @throws AppKeyRotationRefused
 */
function app_key_rotate_in_transaction(mysqli $db, string $oldKey, string $newKey, bool $apply, ?callable $afterRow): array
{
    // A running or cancelling job may hold secrets it decrypted with the old key and may read the credential
    // again, so a rewrite under it would fail that read. A queued job holds no decrypted secret and reads the
    // ciphertext only when a worker claims it, with that worker's key, so it does not block: otherwise the
    // maintenance worker's automatic inventory pulls and every ESXi save would block the rotation while the
    // service is paused. Locked like the deploy claim pause, so the check holds until the commit.
    $activeStatuses = [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, VIRTUSPHERE_DEPLOY_STATUS_CANCELLING];
    $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));
    $jobStmt = $db->prepare('SELECT id FROM deploy_jobs WHERE status IN (' . $placeholders . ') ORDER BY id FOR UPDATE');
    $jobStmt->bind_param(str_repeat('s', count($activeStatuses)), ...$activeStatuses);
    $jobStmt->execute();
    $activeJobs = count(repo_fetch_all($jobStmt->get_result()));
    if ($activeJobs > 0) {
        throw new AppKeyRotationRefused('active_jobs', $activeJobs . ' deploy job(s) are running or being cancelled; let them finish first.');
    }

    $credentialStmt = $db->prepare('SELECT id, secret_ciphertext FROM deploy_credentials ORDER BY id FOR UPDATE');
    $credentialStmt->execute();
    $credentialRows = repo_fetch_all($credentialStmt->get_result());
    $directoryStmt = $db->prepare('SELECT id, bind_secret_ciphertext FROM deploy_ad_config WHERE id = 1 FOR UPDATE');
    $directoryStmt->execute();
    $directoryRows = repo_fetch_all($directoryStmt->get_result());

    // The plaintext of each row to rewrite is held here until the run ends, then wiped in the finally (best effort, see app_key_rotate_wipe()).
    $pending = [];
    try {
        // Classify every row before the first write, so a row that reads with neither key refuses the run
        // while nothing has changed yet.
        $already = 0;
        $unreadable = [];
        foreach ([
            ['deploy_credentials', 'secret_ciphertext', $credentialRows],
            ['deploy_ad_config', 'bind_secret_ciphertext', $directoryRows],
        ] as [$table, $column, $rows]) {
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $stored = (string) $row[$column];
                $current = app_key_rotate_open($stored, $newKey);
                if ($current !== null) {
                    app_key_rotate_wipe($current);
                    $already++;
                    continue;
                }
                $plaintext = app_key_rotate_open($stored, $oldKey);
                if ($plaintext === null) {
                    $unreadable[] = $table . ':' . $id;
                    continue;
                }
                $pending[] = ['table' => $table, 'id' => $id, 'plaintext' => $plaintext];
                $plaintext = null;
            }
        }
        if ($unreadable !== []) {
            throw new AppKeyRotationRefused('unreadable', 'Rows readable with neither key: ' . implode(', ', $unreadable));
        }

        $rewritten = ['deploy_credentials' => 0, 'deploy_ad_config' => 0];
        foreach ($pending as $item) {
            $ciphertext = crypto_encrypt_secret_with_key($item['plaintext'], $newKey);
            // updated_at keeps its value: only the sealing changed, the configuration did not. config_revision
            // and revision are never touched, so no consumer sees a new configuration.
            if ($item['table'] === 'deploy_credentials') {
                $update = $db->prepare('UPDATE deploy_credentials SET secret_ciphertext = ?, updated_at = updated_at WHERE id = ?');
            } else {
                $update = $db->prepare('UPDATE deploy_ad_config SET bind_secret_ciphertext = ?, updated_at = updated_at WHERE id = ?');
            }
            $update->bind_param('si', $ciphertext, $item['id']);
            $update->execute();
            $rewritten[$item['table']]++;
            if ($afterRow !== null) {
                $afterRow($item['table'], $item['id']);
            }
        }

        // Every rewritten row must read back with the new key, to the plaintext it was sealed from.
        foreach ($pending as $item) {
            $stored = app_key_rotate_stored($db, $item['table'], $item['id']);
            $check = $stored === null ? null : app_key_rotate_open($stored, $newKey);
            if ($check === null || !hash_equals($item['plaintext'], $check)) {
                app_key_rotate_wipe($check);
                throw new RuntimeException('verification failed for ' . $item['table'] . ':' . $item['id']);
            }
            app_key_rotate_wipe($check);
        }

        $counts = [
            'credentials' => $rewritten['deploy_credentials'],
            'directory' => $rewritten['deploy_ad_config'],
            'already' => $already,
        ];
        // A domain write and its audit row commit or roll back together (webapi R19).
        if ($apply && $pending !== []) {
            audit_event_required($db, VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_APP_KEY_ROTATED, 'system', null, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'credential_count' => $counts['credentials'],
                'directory_count' => $counts['directory'],
                'already_count' => $counts['already'],
            ]);
        }

        return $counts;
    } finally {
        foreach ($pending as &$item) {
            app_key_rotate_wipe($item['plaintext']);
        }
        unset($item);
    }
}

/** The plaintext of $stored under $key, or null when $key does not open it. */
function app_key_rotate_open(string $stored, string $key): ?string
{
    try {
        return crypto_decrypt_secret_with_key($stored, $key);
    } catch (RuntimeException) {
        return null;
    }
}

/** The stored ciphertext of one row this rotation rewrites, or null when the row is gone. */
function app_key_rotate_stored(mysqli $db, string $table, int $id): ?string
{
    if ($table === 'deploy_credentials') {
        $row = repo_fetch_one($db, 'SELECT secret_ciphertext AS ciphertext FROM deploy_credentials WHERE id = ?', 'i', [$id]);
    } else {
        $row = repo_fetch_one($db, 'SELECT bind_secret_ciphertext AS ciphertext FROM deploy_ad_config WHERE id = ?', 'i', [$id]);
    }

    return $row === null ? null : (string) $row['ciphertext'];
}

/**
 * Overwrites a secret held in memory, where the sodium extension is available. Best effort only: sodium_memzero()
 * overwrites a string only while nothing else references it, and PHP may hold copies. It leaves the variable
 * null afterwards, so callers must not read it again.
 */
function app_key_rotate_wipe(?string &$secret): void
{
    if ($secret !== null && function_exists('sodium_memzero')) {
        sodium_memzero($secret);
    }
}

/**
 * Command line entry. The old APP_KEY arrives on STDIN; `--apply` commits, and no argument is a dry run.
 *
 * @param list<string> $arguments the arguments after the script name
 */
function app_key_rotate_cli(array $arguments): int
{
    $usage = 'usage: php lib/app_key_rotate.php [--apply] < file-with-the-old-APP_KEY' . PHP_EOL;
    if ($arguments !== [] && $arguments !== ['--apply']) {
        fwrite(STDERR, $usage);

        return 2;
    }
    $apply = $arguments === ['--apply'];

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
        return app_key_rotate_run($oldKey, $newKey, $apply);
    } finally {
        app_key_rotate_wipe($oldKey);
        app_key_rotate_wipe($newKey);
    }
}

/**
 * The two progress units of a run: the backup gate, then the re-encryption. The gate applies only to
 * an apply, because a dry run changes nothing. A refusal ends the run with a FAIL line and exit 1.
 */
function app_key_rotate_run(string $oldKey, string $newKey, bool $apply): int
{
    fwrite(STDOUT, '[1/2] RUN backup check' . PHP_EOL);
    if ($apply) {
        $blocker = app_key_rotate_backup_blocker(backup_status_read(), time());
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
        $result = app_key_rotate(db(), $oldKey, $newKey, $apply);
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

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(app_key_rotate_cli(array_slice($argv ?? [], 1)));
}
