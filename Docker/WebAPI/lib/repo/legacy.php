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

/**
 * The one predicate that decides whether a legacy token may act, and who owns it.
 *
 * There used to be two. verifyToken() asked about the token alone (token,
 * expired flag, 60-minute age) while legacyTokenRole() additionally joined the
 * owner and required it active, so the two answered different questions about
 * the same string: a deactivated account's token passed authentication and only
 * lost its *role*, which then fell back to VIRTUSPHERE_ROLE_USER - a role whose
 * permission set is literally missions.write, vms.write and deploy.run. The
 * portal refuses that account on its next click (current_user() re-reads
 * is_active), so the gap was visible nowhere except here, and the confirmation
 * an admin reads before deactivating promised the opposite. expandToken() made
 * the window unbounded on top: a client that keeps polling keeps renewing.
 *
 * The join is strict on purpose. A token whose user_id is NULL cannot be
 * attributed to anybody: since migration 0010 that only happens when the owner
 * was deleted (ON DELETE SET NULL), and tokens live 60 minutes, so no legitimate
 * client holds an unbound one. An unattributable credential with write scopes is
 * worse than one forced re-login through api/login.php.
 *
 * @return array{id: int, role: string}|null null when the token may not act
 */
function legacy_token_owner($token, mysqli $connection): ?array
{
    if ((string) $token === '') {
        return null;
    }

    $expired = 0;
    $stmt = $connection->prepare(
        'SELECT t.id, u.role FROM deploy_tokens t '
        . 'JOIN deploy_users u ON u.id = t.user_id '
        . 'WHERE t.token = ? AND t.expired = ? AND t.created_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE) '
        . 'AND u.is_active = 1 LIMIT 1'
    );
    $stmt->bind_param('si', $token, $expired);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!is_array($row)) {
        return null;
    }

    return ['id' => (int) $row['id'], 'role' => role_normalize((string) $row['role'])];
}

function verifyToken($token, $connection)
{
    return legacy_token_owner($token, $connection) !== null;
}

/**
 * The RBAC role of the user that owns a usable legacy token, or null when no
 * active owner resolves.
 *
 * Null means refuse, not "assume the smallest role". The previous fallback
 * documented itself as "the least-privileged role so mutating actions stay
 * gated by default", which was empty: that role holds three write scopes. With
 * the shared predicate above, null is only reachable when the account is
 * deactivated between the two queries of one request, and a deny is the right
 * answer for exactly that race.
 */
function legacyTokenRole($token, $connection): ?string
{
    $owner = legacy_token_owner($token, $connection);

    return $owner === null ? null : $owner['role'];
}

/**
 * Expires every unexpired legacy token of one user and returns how many died.
 *
 * The read path above already refuses a deactivated owner, so this is the
 * second half: leaving usable-looking rows in the table means the next reader of
 * that table (a report, a future endpoint, an operator) sees credentials that
 * are supposed to be gone.
 */
function repo_legacy_expire_user_tokens(mysqli $connection, int $userId): int
{
    $expired = 1;
    $stmt = $connection->prepare('UPDATE deploy_tokens SET expired = ?, updated_at = NOW() WHERE user_id = ? AND expired = 0');
    $stmt->bind_param('ii', $expired, $userId);
    $stmt->execute();

    return $connection->affected_rows > 0 ? $connection->affected_rows : 0;
}

function expandToken($token, $connection)
{
    // `AND expired = 0` so this cannot resurrect a token somebody expired. Today
    // verifyToken() gates the only call site, but a repo function that trusts
    // its caller's ordering is a defect waiting for a second caller.
    $expired = 0;
    $stmt = $connection->prepare('UPDATE deploy_tokens SET expired = ?, created_at = NOW(), updated_at = NOW() WHERE token = ? AND expired = 0');
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
