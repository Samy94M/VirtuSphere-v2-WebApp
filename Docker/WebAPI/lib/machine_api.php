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

// Writes to error_log always and to deploy_logs (category mecm) at most once
// per throttle window per tag, so a misbehaving sync loop cannot flood the
// portal audit log. Never throws into the wire path.
function machine_api_audit_warning(mysqli $db, string $tag, string $message, ?string $ip = null): void
{
    machine_api_log_warning($tag, $message);

    try {
        require_once __DIR__ . '/repo/log.php';
        $needle = '[' . $tag . ']%';
        $stmt = $db->prepare('SELECT created_at FROM deploy_logs WHERE category = ? AND log_message LIKE ? ORDER BY id DESC LIMIT 1');
        $category = VIRTUSPHERE_LOG_CATEGORY_MECM;
        $stmt->bind_param('ss', $category, $needle);
        $stmt->execute();
        $last = $stmt->get_result()->fetch_assoc();
        if (is_array($last)) {
            $lastTs = strtotime((string) $last['created_at']);
            if ($lastTs !== false && (time() - $lastTs) < VIRTUSPHERE_MECM_AUDIT_THROTTLE_SECONDS) {
                return;
            }
        }

        audit($db, VIRTUSPHERE_LOG_CATEGORY_MECM, '[' . $tag . '] ' . $message, null, $ip);
    } catch (Throwable $exception) {
        error_log('[machine_api_audit_warning] audit write failed: ' . $exception->getMessage());
    }
}
