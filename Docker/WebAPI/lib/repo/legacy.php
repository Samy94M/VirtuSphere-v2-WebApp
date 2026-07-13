<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../permissions.php';
require_once __DIR__ . '/helpers.php';

function generateToken($username, $password, $connection)
{
    $stmt = $connection->prepare('SELECT id, name, password FROM deploy_users WHERE name = ? AND is_active = 1 LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row || !password_verify((string) $password, (string) $row['password'])) {
        addLog((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), VIRTUSPHERE_LOG_CATEGORY_LEGACY_API, 'generateToken', 'Invalid login credentials', $connection);
        return false;
    }

    $token = bin2hex(random_bytes(32));
    $expired = 0;
    $userId = (int) $row['id'];
    $stmt = $connection->prepare('INSERT INTO deploy_tokens (token, expired, user_id, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
    $stmt->bind_param('sii', $token, $expired, $userId);
    $stmt->execute();
    // The canonical name from the row, never the raw POST input: on the success
    // path they are equal (the lookup matched it), but reading it back keeps an
    // attacker-shaped value out of the log by construction.
    legacy_audit_token_success($connection, (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), (string) $row['name']);

    return $token;
}

/**
 * Audits a successful legacy-token issuance. The token itself is never logged
 * (only the actor and the masked marker). error_log records every issuance for
 * forensic completeness; the portal row is throttled per (user, IP) window so a
 * client that re-authenticates in a loop instead of caching its hour-valid token
 * cannot flood deploy_logs (OWASP-aligned: keep the success signal, collapse the
 * noise). Never throws into the token path.
 */
function legacy_audit_token_success(mysqli $connection, string $ip, string $username): void
{
    $request = 'generateToken (user: ' . $username . ')';
    error_log('[legacy generateToken] token issued user=' . $username . ' ip=' . $ip);

    try {
        $category = VIRTUSPHERE_LOG_CATEGORY_LEGACY_API;
        $message = 'Request: ' . $request . ' | Auth-Token: [redacted]';
        $stmt = $connection->prepare('SELECT created_at FROM deploy_logs WHERE category = ? AND ip = ? AND log_message = ? ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('sss', $category, $ip, $message);
        $stmt->execute();
        $last = $stmt->get_result()->fetch_assoc();
        if (is_array($last)) {
            $lastTs = strtotime((string) $last['created_at']);
            if ($lastTs !== false && (time() - $lastTs) < VIRTUSPHERE_LEGACY_TOKEN_AUDIT_THROTTLE_SECONDS) {
                return;
            }
        }

        addLog($ip, $category, $request, '[redacted]', $connection);
    } catch (Throwable $exception) {
        error_log('[legacy_audit_token_success] audit write failed: ' . $exception->getMessage());
    }
}

function verifyToken($token, $connection)
{
    if ((string) $token === '') {
        return false;
    }

    $expired = 0;
    $stmt = $connection->prepare('SELECT id FROM deploy_tokens WHERE token = ? AND expired = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE) LIMIT 1');
    $stmt->bind_param('si', $token, $expired);
    $stmt->execute();

    return $stmt->get_result()->num_rows > 0;
}

/**
 * Resolve the RBAC role of the user that owns a valid legacy token. Tokens
 * issued before the user binding (or by a since-deleted user) resolve to the
 * least-privileged role so mutating actions stay gated by default.
 */
function legacyTokenRole($token, $connection): string
{
    if ((string) $token === '') {
        return VIRTUSPHERE_ROLE_USER;
    }

    $expired = 0;
    $stmt = $connection->prepare(
        'SELECT u.role FROM deploy_tokens t '
        . 'JOIN deploy_users u ON u.id = t.user_id '
        . 'WHERE t.token = ? AND t.expired = ? AND t.created_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE) '
        . 'AND u.is_active = 1 LIMIT 1'
    );
    $stmt->bind_param('si', $token, $expired);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return role_normalize((string) ($row['role'] ?? VIRTUSPHERE_ROLE_USER));
}

function expandToken($token, $connection)
{
    $expired = 0;
    $stmt = $connection->prepare('UPDATE deploy_tokens SET expired = ?, created_at = NOW(), updated_at = NOW() WHERE token = ?');
    $stmt->bind_param('is', $expired, $token);
    $stmt->execute();

    return $connection->affected_rows > 0;
}

function createVM($vmName, $vmHostname, $vmIP, $vmSubnet, $vmGateway, $vmDNS1, $vmDNS2, $vmDomain, $vmVLAN, $vmRole, $vmStatus, $arg12 = null, $arg13 = null)
{
    $connection = $arg12 instanceof mysqli ? $arg12 : ($arg13 instanceof mysqli ? $arg13 : null);
    if ($connection instanceof mysqli) {
        addLog((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), VIRTUSPHERE_LOG_CATEGORY_LEGACY_API, 'createVM legacy unsupported', '[redacted]', $connection);
    }

    return false;
}
