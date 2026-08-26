<?php

declare(strict_types=1);

// Shared building blocks for the legacy machine API surface (MECM / PowerShell /
// Ansible clients). The JSON envelope, status codes, the German "Zugriff
// verweigert" string and the IP allowlist are part of the wire contract and
// must not change without an E3 retirement decision.

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/audit_registry.php';
require_once __DIR__ . '/log_redaction.php';
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

/**
 * Emits the exact legacy 403 response (including the client IP echo) and exits.
 *
 * It used to be that single line and nothing else: no audit row, no error_log, no
 * counter, and the function did not even take a database connection. Its 401
 * sibling audits. Six endpoints hang off it, and the consequence is that the
 * single commonest setup mistake in the whole product - a missing IP allowlist
 * entry - looked in the portal EXACTLY like a server on which MECM was never
 * installed: a grey "no data yet" row and silence. The error report could not
 * report it either, because reportRun sits behind the same gate.
 *
 * $db is optional so the legacy scripts that refuse before their connection
 * exists can still call it; without a connection the line still reaches
 * error_log, which is strictly more than before.
 *
 * The wire response is unchanged, byte for byte: the German sentence and the
 * echoed IP are the frozen contract that the Ansible preflight probe parses.
 */
/**
 * The endpoint name as the audit trail may store it.
 *
 * A value outside the closed surface becomes the sentinel instead of being
 * passed through: the object id of a security row is a filter key, and a
 * renamed or newly added file must not quietly open a second bucket. The
 * refusal itself is never dropped for that reason, and the raw name still
 * reaches the container log, where it can be read without being indexed.
 */
function machine_api_endpoint_name(string $endpoint): string
{
    if (in_array($endpoint, VIRTUSPHERE_MACHINE_API_ENDPOINTS, true)) {
        return $endpoint;
    }
    machine_api_log_warning('machine_api.endpoint_unknown', 'Machine endpoint outside the audited surface: ' . $endpoint);

    return VIRTUSPHERE_MACHINE_API_ENDPOINT_UNKNOWN;
}

function machine_api_forbidden(string $ip, ?mysqli $db = null, string $endpoint = ''): void
{
    $where = machine_api_endpoint_name($endpoint !== ''
        ? $endpoint
        : basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    // request_string, not a raw cast: this line runs BEFORE the IP gate is
    // passed, so `?action[]=x` from any host would turn a refusal into a 500 plus
    // an unauthenticated system audit row - one per request (lib/request.php).
    $action = request_string($_GET, 'action');
    $context = [];
    if ($action !== '' && strlen($action) <= 128 && preg_match('/^[A-Za-z0-9_.:+\/-]+$/', $action) === 1) {
        $context['action'] = $action;
    }

    if ($db instanceof mysqli) {
        // Throttled per (category, event code, IP): a task that polls every ten seconds
        // must not flood the log, and another host's first refusal must still get
        // through. The category is the security one, because that is the question
        // this answers.
        machine_api_audit_warning(
            $db,
            VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_DENIED,
            'machine_endpoint',
            $where,
            VIRTUSPHERE_AUDIT_RESULT_DENIED,
            $context,
            $ip,
            $ip
        );
    } else {
        machine_api_log_warning('machine_api.access_denied', 'Machine API access denied for endpoint ' . $where . '.');
    }

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
    $safeTag = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', $tag);
    error_log('[' . ($safeTag !== '' ? $safeTag : 'machine_api') . '] ' . virtusphere_redact_log_text($message));
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
 * throttle window per (category, event code, scope), so a misbehaving sync loop cannot
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
 * Category and compatibility text are resolved by the registry. There is no
 * parameter through which a caller can persist a free tag, message or category.
 */
function machine_api_audit_warning(
    mysqli $db,
    string $eventCode,
    string $objectType,
    string|int|null $objectId,
    string $result,
    array $context = [],
    ?string $ip = null,
    ?string $scope = null
): void
{
    try {
        require_once __DIR__ . '/repo/log.php';
        $normalizedObjectId = audit_object_id($objectId, audit_event_object_id_kind($eventCode));
        $definition = audit_event_definition($eventCode, $objectType, $normalizedObjectId, $result);
        audit_context_normalize($context, $definition, $result);
        $throttleScope = $scope ?? ($ip !== null && $ip !== '' ? $ip : ($normalizedObjectId ?? 'global'));
        if ($throttleScope === '' || strlen($throttleScope) > 191) {
            throw new InvalidArgumentException('Machine audit throttle scope is invalid');
        }
        $verdict = machine_api_throttle_allows($db, $definition['category'], $eventCode, $throttleScope);
        if (!$verdict['allowed']) {
            return;
        }

        $context['suppressed_count'] = $verdict['suppressed'];
        $context['throttle_seconds'] = VIRTUSPHERE_MECM_AUDIT_THROTTLE_SECONDS;
        $normalizedContext = audit_context_normalize($context, $definition, $result);
        machine_api_log_warning(
            $eventCode,
            audit_event_description($eventCode, $objectType, $normalizedObjectId, $result, $normalizedContext)
        );
        audit_event($db, $eventCode, $objectType, $normalizedObjectId, $result, $context, null, $ip);
    } catch (Throwable $exception) {
        machine_api_log_warning('machine_api_audit_warning', 'Structured audit write failed (' . $exception::class . ').');
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
function machine_api_throttle_allows(mysqli $db, string $category, string $eventCode, string $scope): array
{
    require_once __DIR__ . '/repo/helpers.php';

    return repo_transaction($db, static function () use ($db, $category, $eventCode, $scope): array {
        $row = repo_fetch_one(
            $db,
            'SELECT UNIX_TIMESTAMP(last_written_at) AS written_at, suppressed FROM deploy_audit_throttle WHERE category = ? AND tag = ? AND scope = ? FOR UPDATE',
            'sss',
            [$category, $eventCode, $scope]
        );

        if ($row === null) {
            repo_execute(
                $db,
                'INSERT INTO deploy_audit_throttle (category, tag, scope, last_written_at, suppressed) VALUES (?, ?, ?, NOW(), 0)',
                'sss',
                [$category, $eventCode, $scope]
            );

            return ['allowed' => true, 'suppressed' => 0];
        }

        $age = time() - (int) $row['written_at'];
        if ($age < VIRTUSPHERE_MECM_AUDIT_THROTTLE_SECONDS) {
            repo_execute(
                $db,
                'UPDATE deploy_audit_throttle SET suppressed = suppressed + 1 WHERE category = ? AND tag = ? AND scope = ?',
                'sss',
                [$category, $eventCode, $scope]
            );

            return ['allowed' => false, 'suppressed' => (int) $row['suppressed'] + 1];
        }

        repo_execute(
            $db,
            'UPDATE deploy_audit_throttle SET last_written_at = NOW(), suppressed = 0 WHERE category = ? AND tag = ? AND scope = ?',
            'sss',
            [$category, $eventCode, $scope]
        );

        return ['allowed' => true, 'suppressed' => (int) $row['suppressed']];
    });
}
