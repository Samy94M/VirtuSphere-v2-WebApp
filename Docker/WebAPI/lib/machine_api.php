<?php

declare(strict_types=1);

// Shared building blocks for the legacy machine API surface (MECM / PowerShell /
// Ansible clients). The JSON envelope, status codes, the German "Zugriff
// verweigert" string and the IP allowlist are part of the wire contract and
// must not change without an E3 retirement decision.

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/mac.php';
require_once __DIR__ . '/request.php';

function machine_api_json(mixed $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function machine_api_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

function machine_api_ip_allowed(mysqli $db, string $ip): bool
{
    $stmt = $db->prepare('SELECT id FROM deploy_accessToWebAPI WHERE ipAddress = ? LIMIT 1');
    $stmt->bind_param('s', $ip);
    $stmt->execute();

    return $stmt->get_result()->num_rows > 0;
}

function machine_api_mac_allowed(mysqli $db, string $mac): bool
{
    if (!filter_var($mac, FILTER_VALIDATE_MAC)) {
        return false;
    }

    // Canonical lookup (E2): any valid separator/case matches the stored
    // canonical form - strictly more permissive than before, wire-compatible.
    $mac = virtusphere_normalize_mac($mac) ?? $mac;
    $stmt = $db->prepare('SELECT id FROM deploy_interfaces WHERE mac = ? LIMIT 1');
    $stmt->bind_param('s', $mac);
    $stmt->execute();

    return $stmt->get_result()->num_rows > 0;
}

// Emits the exact legacy 403 response (including the client IP echo) and exits.
function machine_api_forbidden(string $ip): void
{
    machine_api_json(['error' => 'Zugriff verweigert. Ihre IP: ' . $ip], 403);
}


function machine_api_prepared_result(mysqli $db, string $sql, string $types = '', array $params = []): mysqli_result
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();

    return $stmt->get_result();
}

function machine_api_log_warning(string $tag, string $message): void
{
    error_log('[' . $tag . '] ' . $message);
}

// Optional shared-token gate for mecm_report.php only (ADR-0018). The setting
// stores a SHA-256 hash; an empty setting keeps the endpoint token-free so
// existing scripts continue to work unchanged.
function machine_api_report_token_ok(mysqli $db, ?string $presented): bool
{
    require_once __DIR__ . '/repo/settings.php';
    $storedHash = repo_setting_value($db, VIRTUSPHERE_SETTING_MACHINE_REPORT_TOKEN_HASH, '');
    if ($storedHash === '') {
        return true;
    }
    if (!is_string($presented) || $presented === '') {
        return false;
    }

    return hash_equals($storedHash, hash('sha256', $presented));
}

/**
 * Writes to error_log always, and to the portal audit log at most once per
 * throttle window per (category, tag, scope), so a misbehaving sync loop cannot
 * flood the log while another client's first occurrence still gets through.
 * Never throws into the wire path.
 *
 * The predecessor had five defects, each one blocking before a second channel
 * could be put on it, and all five are what this signature and
 * machine_api_throttle_allows() answer:
 *
 *  - the key was the TAG alone, so one noisy caller suppressed that tag's lines
 *    for every other IP for an hour. It is (category, tag, $scope) now, and the
 *    scope is normally the client IP.
 *  - the category was hardcoded to `mecm`, so a new category could not use the
 *    helper at all. It is a parameter.
 *  - the lookup was a LIKE on the TEXT message column of deploy_logs:
 *    unindexable, and a tag never written before scanned the whole table before
 *    answering "no". On a path served every ten seconds the throttle cost more
 *    than what it throttled. It is a primary-key read on a dedicated store.
 *  - the suppressed events left no counter, so a burst and a single
 *    misconfiguration looked identical. The count is carried and reported with
 *    the next line that gets through.
 *  - two concurrent requests both passed the check and both wrote. The decision
 *    is a locking read inside one transaction now.
 *
 * $scope defaults to the client IP when one is passed, because "who" is the
 * dimension that must not be collapsed. Pass '' deliberately for a global event.
 */
function machine_api_audit_warning(mysqli $db, string $tag, string $message, ?string $ip = null, string $category = VIRTUSPHERE_LOG_CATEGORY_MECM, ?string $scope = null): void
{
    machine_api_log_warning($tag, $message);

    try {
        require_once __DIR__ . '/repo/log.php';
        $verdict = machine_api_throttle_allows($db, $category, $tag, $scope ?? (string) $ip);
        if (!$verdict['allowed']) {
            return;
        }

        // The suppressed count travels with the line that breaks the silence:
        // otherwise the operator reads one warning and cannot tell it apart from
        // a thousand.
        $suffix = $verdict['suppressed'] > 0
            ? ' (' . $verdict['suppressed'] . ' further occurrence(s) suppressed in the last ' . VIRTUSPHERE_MECM_AUDIT_THROTTLE_SECONDS . ' s)'
            : '';
        audit($db, $category, '[' . $tag . '] ' . $message . $suffix, null, $ip);
    } catch (Throwable $exception) {
        error_log('[machine_api_audit_warning] audit write failed: ' . $exception->getMessage());
    }
}

/**
 * May this (category, tag, scope) write an audit line now, and how many
 * occurrences were swallowed since the last one that did?
 *
 * The read is a primary-key lookup with FOR UPDATE inside one transaction, so
 * two concurrent requests serialise instead of both passing: the second one
 * finds the timestamp the first just wrote. A missing row gap-locks, which is
 * what makes the very first occurrence single-writer too.
 *
 * @return array{allowed: bool, suppressed: int}
 */
function machine_api_throttle_allows(mysqli $db, string $category, string $tag, string $scope): array
{
    require_once __DIR__ . '/repo/helpers.php';

    return repo_transaction($db, static function () use ($db, $category, $tag, $scope): array {
        $row = repo_fetch_one(
            $db,
            'SELECT UNIX_TIMESTAMP(last_written_at) AS written_at, suppressed FROM deploy_audit_throttle WHERE category = ? AND tag = ? AND scope = ? FOR UPDATE',
            'sss',
            [$category, $tag, $scope]
        );

        if ($row === null) {
            repo_execute(
                $db,
                'INSERT INTO deploy_audit_throttle (category, tag, scope, last_written_at, suppressed) VALUES (?, ?, ?, NOW(), 0)',
                'sss',
                [$category, $tag, $scope]
            );

            return ['allowed' => true, 'suppressed' => 0];
        }

        $age = time() - (int) $row['written_at'];
        if ($age < VIRTUSPHERE_MECM_AUDIT_THROTTLE_SECONDS) {
            repo_execute(
                $db,
                'UPDATE deploy_audit_throttle SET suppressed = suppressed + 1 WHERE category = ? AND tag = ? AND scope = ?',
                'sss',
                [$category, $tag, $scope]
            );

            return ['allowed' => false, 'suppressed' => (int) $row['suppressed'] + 1];
        }

        repo_execute(
            $db,
            'UPDATE deploy_audit_throttle SET last_written_at = NOW(), suppressed = 0 WHERE category = ? AND tag = ? AND scope = ?',
            'sss',
            [$category, $tag, $scope]
        );

        return ['allowed' => true, 'suppressed' => (int) $row['suppressed']];
    });
}
