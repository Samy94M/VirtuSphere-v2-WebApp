<?php

declare(strict_types=1);

function repo_fetch_all(mysqli_result $result): array
{
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Nesting depth of repo_transaction() for one connection. $delta adjusts it.
 * MySQL has no nested transactions, so the depth is what tells a nested call to
 * join the running transaction instead of issuing a second BEGIN (which MySQL
 * answers by silently committing the outer one).
 */
function repo_transaction_depth(mysqli $db, int $delta = 0): int
{
    static $depths = [];

    $key = spl_object_id($db);
    $depth = max(0, ($depths[$key] ?? 0) + $delta);
    // Drop the entry at zero. spl_object_id() reuses the id of a freed object,
    // and db(true) frees the old connection, so a lingering count would tell the
    // next connection it is already inside a transaction.
    if ($depth === 0) {
        unset($depths[$key]);
    } else {
        $depths[$key] = $depth;
    }

    return $depth;
}

/**
 * Runs $work inside a transaction. Only the outermost call commits or rolls
 * back; a nested failure propagates so the outermost rolls everything back.
 *
 * Repos that rewrite a row set (DELETE followed by INSERT) must go through
 * this, so a crash between the two can never leave a committed hole that other
 * readers mistake for "these rows no longer exist".
 *
 * @template T
 * @param callable(): T $work
 * @return T
 */
function repo_transaction(mysqli $db, callable $work): mixed
{
    $outermost = repo_transaction_depth($db, 1) === 1;
    if ($outermost) {
        try {
            $db->begin_transaction();
        } catch (Throwable $exception) {
            // A lost connection here must not leave the depth raised, or every
            // later call on this connection would believe it is already nested
            // and would run its writes without a transaction.
            repo_transaction_depth($db, -1);

            throw $exception;
        }
    }

    try {
        $result = $work();
    } catch (Throwable $exception) {
        repo_transaction_depth($db, -1);
        if ($outermost) {
            try {
                $db->rollback();
            } catch (Throwable) {
                // A failed rollback (connection gone) must not mask the real
                // cause; the server discards the transaction either way.
            }
        }

        throw $exception;
    }

    repo_transaction_depth($db, -1);
    if ($outermost) {
        $db->commit();
    }

    return $result;
}

function repo_scalar(mysqli $db, string $sql, string $types = '', array $params = []): mixed
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();

    return $row[0] ?? null;
}

/**
 * Resolves a user id to the username stored in provenance columns
 * (deploy_vms.vm_creator, deploy_missions.mission_creator). Returns '' for an
 * unknown or absent user, so machine/legacy paths without a session simply
 * leave the field empty instead of inventing an author.
 */
function repo_creator_name(mysqli $db, ?int $userId): string
{
    if ($userId === null || $userId <= 0) {
        return '';
    }

    return (string) (repo_scalar($db, 'SELECT name FROM deploy_users WHERE id = ? LIMIT 1', 'i', [$userId]) ?? '');
}

function repo_execute(mysqli $db, string $sql, string $types = '', array $params = []): bool
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    return $stmt->execute();
}

function repo_object_get(object|array $source, string $key, mixed $default = null): mixed
{
    if (is_array($source)) {
        return array_key_exists($key, $source) ? $source[$key] : $default;
    }

    return property_exists($source, $key) ? $source->{$key} : $default;
}

function repo_object_has(object|array $source, string $key): bool
{
    return is_array($source) ? array_key_exists($key, $source) : property_exists($source, $key);
}

function repo_id(mixed $value): int
{
    return max(0, (int) $value);
}

function repo_bind_type(mixed $value): string
{
    return is_int($value) || is_bool($value) ? 'i' : 's';
}

function repo_allowed_columns(object|array $source, array $allowed): array
{
    $values = [];
    foreach ($allowed as $key) {
        if (repo_object_has($source, $key) && repo_object_get($source, $key) !== null) {
            $values[$key] = repo_object_get($source, $key);
        }
    }

    return $values;
}

function repo_log_failure(string $message): void
{
    $log = dirname(__DIR__, 2) . '/logs/fail.log';
    $dir = dirname($log);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_log('[repo_log_failure] Cannot create log directory: ' . $dir);
        return;
    }
    if (!is_writable($dir)) {
        error_log('[repo_log_failure] Log directory is not writable: ' . $dir);
        return;
    }

    $written = file_put_contents($log, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    if ($written === false) {
        error_log('[repo_log_failure] Cannot write log file: ' . $log);
    }
}

function repo_insert_from_values(mysqli $db, string $table, array $values): int
{
    if ($values === []) {
        throw new InvalidArgumentException('Cannot insert an empty value set.');
    }

    $columns = array_keys($values);
    $placeholders = array_fill(0, count($columns), '?');
    $types = implode('', array_map('repo_bind_type', array_values($values)));
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(', ', array_map(static fn (string $column): string => "`{$column}`", $columns)),
        implode(', ', $placeholders)
    );

    $stmt = $db->prepare($sql);
    $params = array_values($values);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    return (int) $db->insert_id;
}

function repo_update_from_values(mysqli $db, string $table, array $values, string $whereSql, string $whereTypes, array $whereParams): bool
{
    if ($values === []) {
        return true;
    }

    $sets = [];
    foreach (array_keys($values) as $column) {
        $sets[] = "`{$column}` = ?";
    }

    $params = array_values($values);
    $types = implode('', array_map('repo_bind_type', $params)) . $whereTypes;
    $params = array_merge($params, $whereParams);
    $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $sets), $whereSql);

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);

    return $stmt->execute();
}

function repo_fetch_one(mysqli $db, string $sql, string $types = '', array $params = []): ?array
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row ?: null;
}