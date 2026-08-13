<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/directory_constants.php';
require_once __DIR__ . '/auth_schema.php';

const VIRTUSPHERE_LOGIN_USER_FAILURE_LIMIT = 5;
const VIRTUSPHERE_LOGIN_IP_FAILURE_LIMIT = 25;
const VIRTUSPHERE_LOGIN_RESULT_PENDING = 'pending';
const VIRTUSPHERE_LOGIN_RESULT_SUCCESS = 'success';
const VIRTUSPHERE_LOGIN_RESULT_CREDENTIAL_FAILURE = 'credential_failure';
const VIRTUSPHERE_LOGIN_RESULT_INFRASTRUCTURE = 'infrastructure';

function auth_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

/** @return list<string> */
function auth_login_lock_names(string $username, string $source, string $ip): array
{
    $names = [
        'vs_login_' . substr(hash('sha256', 'ip:' . $ip), 0, 48),
        'vs_login_' . substr(hash('sha256', 'user:' . $source . ':' . mb_strtolower($username, 'UTF-8')), 0, 48),
    ];
    sort($names, SORT_STRING);

    return $names;
}

/** @param list<string> $names */
function auth_acquire_login_locks(mysqli $db, array $names): void
{
    foreach ($names as $name) {
        $stmt = $db->prepare('SELECT GET_LOCK(?, 2) AS acquired');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        if ((int) ($stmt->get_result()->fetch_assoc()['acquired'] ?? 0) !== 1) {
            auth_release_login_locks($db, $names);
            throw new RuntimeException('login_rate_limit_lock_unavailable');
        }
    }
}

/** @param list<string> $names */
function auth_release_login_locks(mysqli $db, array $names): void
{
    foreach (array_reverse($names) as $name) {
        try {
            $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->bind_param('s', $name);
            $stmt->execute();
        } catch (Throwable) {
            // Connection-scoped locks are released automatically on disconnect.
        }
    }
}

/**
 * Reserves exactly one attempt before a password verifier or LDAP bind runs.
 * Named locks serialize the two independently enforced budgets without holding
 * an InnoDB transaction open across network I/O.
 *
 * @return array{ok:bool,id:int,reason:string}
 */
function auth_reserve_login_attempt(mysqli $db, string $username, string $source): array
{
    if (!auth_attempt_source_schema_available($db) || !auth_attempt_result_schema_available($db)) {
        if (auth_failed_ip_attempt_count($db) >= VIRTUSPHERE_LOGIN_IP_FAILURE_LIMIT) {
            return ['ok' => false, 'id' => 0, 'reason' => 'ip_locked'];
        }
        if (auth_failed_attempt_count($db, $username, $source) >= VIRTUSPHERE_LOGIN_USER_FAILURE_LIMIT) {
            return ['ok' => false, 'id' => 0, 'reason' => 'rate_limited'];
        }

        return ['ok' => true, 'id' => 0, 'reason' => ''];
    }

    $ip = auth_client_ip();
    $locks = auth_login_lock_names($username, $source, $ip);
    auth_acquire_login_locks($db, $locks);
    try {
        if (auth_failed_ip_attempt_count($db) >= VIRTUSPHERE_LOGIN_IP_FAILURE_LIMIT) {
            return ['ok' => false, 'id' => 0, 'reason' => 'ip_locked'];
        }
        if (auth_failed_attempt_count($db, $username, $source) >= VIRTUSPHERE_LOGIN_USER_FAILURE_LIMIT) {
            return ['ok' => false, 'id' => 0, 'reason' => 'rate_limited'];
        }

        $pending = VIRTUSPHERE_LOGIN_RESULT_PENDING;
        $failed = 0;
        $stmt = $db->prepare('INSERT INTO deploy_login_attempts (username, auth_source, ip_address, success, result) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssis', $username, $source, $ip, $failed, $pending);
        $stmt->execute();

        return ['ok' => true, 'id' => (int) $db->insert_id, 'reason' => ''];
    } finally {
        auth_release_login_locks($db, $locks);
    }
}

function auth_finish_login_attempt(mysqli $db, int $attemptId, string $result): void
{
    if ($attemptId <= 0 || !in_array($result, [
        VIRTUSPHERE_LOGIN_RESULT_SUCCESS,
        VIRTUSPHERE_LOGIN_RESULT_CREDENTIAL_FAILURE,
        VIRTUSPHERE_LOGIN_RESULT_INFRASTRUCTURE,
    ], true)) {
        return;
    }
    $success = $result === VIRTUSPHERE_LOGIN_RESULT_SUCCESS ? 1 : 0;
    $stmt = $db->prepare('UPDATE deploy_login_attempts SET success = ?, result = ? WHERE id = ? AND result = ?');
    $pending = VIRTUSPHERE_LOGIN_RESULT_PENDING;
    $stmt->bind_param('isis', $success, $result, $attemptId, $pending);
    $stmt->execute();
}

function auth_record_login_attempt(mysqli $db, string $username, bool $success, string $source = VIRTUSPHERE_AUTH_SOURCE_LOCAL): void
{
    $ip = auth_client_ip();
    $successInt = $success ? 1 : 0;
    if (auth_attempt_source_schema_available($db)) {
        $stmt = $db->prepare('INSERT INTO deploy_login_attempts (username, auth_source, ip_address, success) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('sssi', $username, $source, $ip, $successInt);
    } else {
        $stmt = $db->prepare('INSERT INTO deploy_login_attempts (username, ip_address, success) VALUES (?, ?, ?)');
        $stmt->bind_param('ssi', $username, $ip, $successInt);
    }
    $stmt->execute();
}

function auth_failed_attempt_count(mysqli $db, string $username, string $source = VIRTUSPHERE_AUTH_SOURCE_LOCAL): int
{
    $window = VIRTUSPHERE_LOGIN_FAILURE_WINDOW_MINUTES;
    if (auth_attempt_source_schema_available($db) && auth_attempt_result_schema_available($db)) {
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM deploy_login_attempts WHERE username = ? AND auth_source = ? AND result IN ('pending','credential_failure') AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        $stmt->bind_param('ssi', $username, $source, $window);
    } elseif (auth_attempt_source_schema_available($db)) {
        $ip = auth_client_ip();
        $stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_login_attempts WHERE username = ? AND auth_source = ? AND ip_address = ? AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)');
        $stmt->bind_param('sssi', $username, $source, $ip, $window);
    } else {
        $ip = auth_client_ip();
        $stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_login_attempts WHERE username = ? AND ip_address = ? AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)');
        $stmt->bind_param('ssi', $username, $ip, $window);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return (int) ($row['c'] ?? 0);
}

function auth_failed_ip_attempt_count(mysqli $db): int
{
    $ip = auth_client_ip();
    $window = VIRTUSPHERE_LOGIN_FAILURE_WINDOW_MINUTES;
    $resultFilter = auth_attempt_result_schema_available($db)
        ? "result IN ('pending','credential_failure')"
        : 'success = 0';
    // csp-allow: interpolated-sql
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_login_attempts WHERE ip_address = ? AND ' . $resultFilter . ' AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)');
    $stmt->bind_param('si', $ip, $window);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return (int) ($row['c'] ?? 0);
}

/** Records the failure and emits only the IP-limit onset audit. */
function auth_record_failed_login(mysqli $db, string $username, string $source = VIRTUSPHERE_AUTH_SOURCE_LOCAL): void
{
    $before = auth_failed_ip_attempt_count($db);
    auth_record_login_attempt($db, $username, false, $source);
    if ($before < VIRTUSPHERE_LOGIN_IP_FAILURE_LIMIT && auth_failed_ip_attempt_count($db) >= VIRTUSPHERE_LOGIN_IP_FAILURE_LIMIT) {
        audit_auth($db, sprintf(
            'ip rate limited for %d minutes after %d failed sign-ins',
            VIRTUSPHERE_LOGIN_FAILURE_WINDOW_MINUTES,
            VIRTUSPHERE_LOGIN_IP_FAILURE_LIMIT
        ));
    }
}

function auth_finish_failed_login(mysqli $db, int $attemptId, string $username, string $source): void
{
    if ($attemptId > 0) {
        $stmt = $db->prepare('SELECT username, auth_source, ip_address FROM deploy_login_attempts WHERE id = ? AND result = ? LIMIT 1');
        $pending = VIRTUSPHERE_LOGIN_RESULT_PENDING;
        $stmt->bind_param('is', $attemptId, $pending);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        if (is_array($attempt)) {
            $locks = auth_login_lock_names((string) $attempt['username'], (string) $attempt['auth_source'], (string) $attempt['ip_address']);
            auth_acquire_login_locks($db, $locks);
            try {
                $atThreshold = auth_failed_ip_attempt_count($db) >= VIRTUSPHERE_LOGIN_IP_FAILURE_LIMIT;
                auth_finish_login_attempt($db, $attemptId, VIRTUSPHERE_LOGIN_RESULT_CREDENTIAL_FAILURE);
                $window = VIRTUSPHERE_LOGIN_FAILURE_WINDOW_MINUTES;
                $message = 'ip rate limited%';
                $auditStmt = $db->prepare("SELECT COUNT(*) AS c FROM deploy_logs WHERE category = 'auth' AND log_message LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
                $auditStmt->bind_param('si', $message, $window);
                $auditStmt->execute();
                $alreadyAudited = (int) ($auditStmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
                if ($atThreshold && !$alreadyAudited) {
                    audit_auth($db, sprintf(
                        'ip rate limited for %d minutes after %d failed sign-ins',
                        VIRTUSPHERE_LOGIN_FAILURE_WINDOW_MINUTES,
                        VIRTUSPHERE_LOGIN_IP_FAILURE_LIMIT
                    ));
                }
            } finally {
                auth_release_login_locks($db, $locks);
            }
            return;
        }
    }
    auth_record_failed_login($db, $username, $source);
}

function auth_finish_infrastructure_login(mysqli $db, int $attemptId): void
{
    auth_finish_login_attempt($db, $attemptId, VIRTUSPHERE_LOGIN_RESULT_INFRASTRUCTURE);
}
