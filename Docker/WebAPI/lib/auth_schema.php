<?php

declare(strict_types=1);

/**
 * Additive migrations may be installed immediately before the matching web
 * image. These helpers keep local sign-in available during that narrow rollout
 * window without treating an unknown row value as a local authentication
 * source.
 *
 * Try first, fall back second (C3). A reader runs its current SQL form
 * directly through auth_schema_attempt(). Only MySQL errors 1054 (unknown
 * column) and 1146 (unknown table) switch it to the older form, and only after
 * a name probe confirms that one of the schema features the reader named is
 * really missing, so a typo in the query itself is never masked. Every other
 * error propagates unchanged. The verdict is remembered per connection for the
 * rest of the request, so later decisions in the same request (the boolean
 * predicates below) answer from it without another query.
 *
 * A feature is `table.column` or a bare `table`. The probe asks MySQL to
 * resolve the names (`SELECT col FROM table LIMIT 0`, prepared, never
 * executed) instead of reading information_schema, so it sees exactly what the
 * reader's own statement would see on this connection.
 */

const VIRTUSPHERE_SCHEMA_USER_SOURCE = 'deploy_users.auth_source';
const VIRTUSPHERE_SCHEMA_ATTEMPT_SOURCE = 'deploy_login_attempts.auth_source';
const VIRTUSPHERE_SCHEMA_ATTEMPT_RESULT = 'deploy_login_attempts.result';
const VIRTUSPHERE_SCHEMA_AD_CONFIG = 'deploy_ad_config';
const VIRTUSPHERE_SCHEMA_AD_CONTROLLERS = 'deploy_ad_controllers';
const VIRTUSPHERE_SCHEMA_AD_CONTROLLER_STATE = 'deploy_ad_controller_state';

/** MySQL error numbers that mean "this name does not exist (yet)". */
const VIRTUSPHERE_SCHEMA_MISSING_NAME_ERRORS = [1054, 1146];

/**
 * Verdicts per connection. A WeakMap, not spl_object_id(): db(true) frees the
 * old connection and a reused id would hand its verdicts to the next one.
 *
 * @return WeakMap<mysqli, array<string,bool>>
 */
function auth_schema_memo(): WeakMap
{
    static $memo = null;

    return $memo ??= new WeakMap();
}

function auth_schema_known(mysqli $db, string $feature): ?bool
{
    $memo = auth_schema_memo();

    return isset($memo[$db]) ? ($memo[$db][$feature] ?? null) : null;
}

function auth_schema_remember(mysqli $db, string $feature, bool $present): void
{
    $memo = auth_schema_memo();
    $known = $memo[$db] ?? [];
    $known[$feature] = $present;
    $memo[$db] = $known;
}

function auth_schema_error_is_missing_name(mysqli_sql_exception $exception): bool
{
    return in_array($exception->getCode(), VIRTUSPHERE_SCHEMA_MISSING_NAME_ERRORS, true);
}

/** @return array{0:string,1:?string} table and column (null for a table feature) */
function auth_schema_feature_parts(string $feature): array
{
    if (preg_match('/^([a-z][a-z0-9_]*)(?:\.([a-z][a-z0-9_]*))?$/', $feature, $match) !== 1) {
        throw new InvalidArgumentException('invalid schema feature name');
    }

    return [$match[1], ($match[2] ?? '') !== '' ? $match[2] : null];
}

/**
 * Lets MySQL resolve the names of all $features in one prepared statement.
 * Only name resolution runs (prepare without execute), so nothing is read,
 * locked or written. Returns false on 1054/1146; any other error propagates.
 *
 * @param non-empty-list<string> $features
 */
function auth_schema_names_resolve(mysqli $db, array $features): bool
{
    $aliases = [];
    $columns = [];
    foreach ($features as $feature) {
        [$table, $column] = auth_schema_feature_parts($feature);
        $aliases[$table] ??= 't' . count($aliases);
        if ($column !== null) {
            $columns[] = $aliases[$table] . '.`' . $column . '`';
        }
    }
    $from = [];
    foreach ($aliases as $table => $alias) {
        $from[] = '`' . $table . '` ' . $alias;
    }
    // Identifiers come from validated feature names, never from input.
    $sql = 'SELECT ' . ($columns === [] ? '1' : implode(', ', $columns)) . ' FROM ' . implode(', ', $from) . ' LIMIT 0';
    try {
        // csp-allow: interpolated-sql
        $stmt = $db->prepare($sql);
        $stmt->close();
    } catch (mysqli_sql_exception $exception) {
        if (auth_schema_error_is_missing_name($exception)) {
            return false;
        }
        throw $exception;
    }

    return true;
}

/**
 * True when every feature exists. Answers from the request's verdicts; only
 * features nobody has tried yet are probed, all of them in one statement. When
 * that statement fails on a missing name, each unknown feature is probed on
 * its own so the remembered verdicts stay exact.
 *
 * @param non-empty-list<string> $features
 */
function auth_schema_features_present(mysqli $db, array $features): bool
{
    $unknown = [];
    foreach ($features as $feature) {
        $known = auth_schema_known($db, $feature);
        if ($known === false) {
            return false;
        }
        if ($known === null) {
            $unknown[] = $feature;
        }
    }
    if ($unknown === []) {
        return true;
    }
    if (auth_schema_names_resolve($db, $unknown)) {
        foreach ($unknown as $feature) {
            auth_schema_remember($db, $feature, true);
        }

        return true;
    }
    if (count($unknown) === 1) {
        auth_schema_remember($db, $unknown[0], false);

        return false;
    }
    $present = true;
    foreach ($unknown as $feature) {
        $exists = auth_schema_names_resolve($db, [$feature]);
        auth_schema_remember($db, $feature, $exists);
        $present = $present && $exists;
    }

    return $present;
}

/**
 * Runs $current, the reader's SQL form that uses every one of $features; on a
 * confirmed missing feature runs $legacy instead and remembers the verdict.
 *
 * $current must fail on the names BEFORE it changes anything: one statement,
 * whose prepare raises 1054/1146, and no write ahead of it. MySQL rejects such
 * a statement before execution and leaves an open transaction and any held
 * named lock as they were, so the caller's transaction boundary is unchanged.
 *
 * @template T
 * @param non-empty-list<string> $features
 * @param callable(): T $current
 * @param callable(): T $legacy
 * @return T
 */
function auth_schema_attempt(mysqli $db, array $features, callable $current, callable $legacy): mixed
{
    foreach ($features as $feature) {
        if (auth_schema_known($db, $feature) === false) {
            return $legacy();
        }
    }
    try {
        $result = $current();
    } catch (mysqli_sql_exception $exception) {
        // A missing name that is not one of ours is a bug in the statement.
        if (!auth_schema_error_is_missing_name($exception) || auth_schema_features_present($db, $features)) {
            throw $exception;
        }

        return $legacy();
    }
    foreach ($features as $feature) {
        auth_schema_remember($db, $feature, true);
    }

    return $result;
}

function auth_schema_column_exists(mysqli $db, string $table, string $column): bool
{
    return auth_schema_features_present($db, [$table . '.' . $column]);
}

function auth_schema_table_exists(mysqli $db, string $table): bool
{
    return auth_schema_features_present($db, [$table]);
}

function auth_user_source_schema_available(mysqli $db): bool
{
    return auth_schema_features_present($db, [VIRTUSPHERE_SCHEMA_USER_SOURCE]);
}

function auth_attempt_source_schema_available(mysqli $db): bool
{
    return auth_schema_features_present($db, [VIRTUSPHERE_SCHEMA_ATTEMPT_SOURCE]);
}

function auth_attempt_result_schema_available(mysqli $db): bool
{
    return auth_schema_features_present($db, [VIRTUSPHERE_SCHEMA_ATTEMPT_RESULT]);
}

function directory_schema_available(mysqli $db): bool
{
    return auth_schema_features_present($db, [
        VIRTUSPHERE_SCHEMA_USER_SOURCE,
        VIRTUSPHERE_SCHEMA_ATTEMPT_SOURCE,
        VIRTUSPHERE_SCHEMA_ATTEMPT_RESULT,
        VIRTUSPHERE_SCHEMA_AD_CONFIG,
        VIRTUSPHERE_SCHEMA_AD_CONTROLLERS,
        VIRTUSPHERE_SCHEMA_AD_CONTROLLER_STATE,
    ]);
}
