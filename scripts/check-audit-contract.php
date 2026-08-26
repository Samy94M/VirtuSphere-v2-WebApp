<?php

declare(strict_types=1);

/**
 * Etappe 10C guard: the audit trail has exactly one owner.
 *
 * Before 10C an audit line was a free English sentence plus a category, chosen
 * at the call site. That is not a contract, it is a convention, and a convention
 * degrades silently: two producers of the same event drifted into two different
 * sentences, a reader who wanted "every refused machine access" had to match on
 * prose, and the one place that did match on prose (the auth flood check) was a
 * LIKE over a TEXT column that a renamed sentence would have quietly disarmed.
 *
 * So the shape of a persisted event is decided in `lib/audit_event_definitions.php`
 * and enforced by `lib/audit_registry.php`, and this guard proves that nothing
 * writes past them. Every finding carries a stable [audit-contract.*] id.
 *
 *   free-producer      a call passing a category/message instead of an event
 *   unknown-event      an event code that is not in the registry
 *   registry-bypass    a direct INSERT into deploy_logs outside the owner
 *   forbidden-field    a context key that may carry a secret or a payload
 *   dead-sink          addLog()/audit_auth() reintroduced
 *   token-sink         a log/audit signature taking a token as a parameter
 *   sentinel           a hardcoded secret-shaped literal in a log call
 *   unredacted-sink    an exception message reaches a process log unredacted
 *   unused-event       a registry entry no producer writes
 *   zero-match         the scan found no producers at all
 *
 * The last one is the one that matters most in a year: every other rule is a
 * search for something bad, and a search that silently matches nothing reads
 * exactly like a clean repository.
 *
 * Usage: php scripts/check-audit-contract.php [--ci|--quiet|-q]
 * VIRTUSPHERE_CHECK_ROOT overrides the repo root (guard harness fixtures).
 */

$quiet = false;
foreach (array_slice(array_values((array) ($_SERVER['argv'] ?? [])), 1) as $arg) {
    if ($arg === '--quiet' || $arg === '-q') {
        $quiet = true;
    }
}

$envRoot = getenv('VIRTUSPHERE_CHECK_ROOT');
$root = is_string($envRoot) && $envRoot !== '' ? $envRoot : dirname(__DIR__);
$app = $root . '/Docker/WebAPI';

/** The two files that own persistence. Every other file is a producer. */
const AUDIT_OWNER_FILES = [
    'Docker/WebAPI/lib/repo/log.php',
];

/** Where the registry lives. */
const AUDIT_REGISTRY_FILE = 'Docker/WebAPI/lib/audit_event_definitions.php';

/**
 * Context keys a producer may never pass, whatever the registry says.
 *
 * The registry refuses these at runtime too. Both exist on purpose: the runtime
 * check protects production, this one fails the build, and a rule that is only
 * enforced at runtime is one whose violation is first seen by a customer.
 */
const AUDIT_FORBIDDEN_CONTEXT_KEYS = [
    'password', 'passwd', 'secret', 'token', 'payload', 'body', 'exception',
    'trace', 'stack', 'search', 'query', 'authorization', 'cookie', 'session',
    'credentials', 'private_key',
];

/** Names whose reintroduction means the free-text sink is back. */
const AUDIT_DEAD_SINKS = ['addLog', 'audit_auth'];

/**
 * Parameter names that would make a log/audit function able to STORE a token.
 * The dead `addLog($db, $token, ...)` was exactly this: never called, and one
 * call away from persisting an auth token in a table with a 365-day window.
 */
const AUDIT_TOKEN_SINK_PARAMS = [
    'token', 'authToken', 'auth_token', 'apiKey', 'api_key', 'secret',
    'password', 'bearer', 'credential',
];

$findings = [];
$producerCalls = 0;
$usedEvents = [];

$registryPath = $root . '/' . AUDIT_REGISTRY_FILE;
$registrySource = is_file($registryPath) ? (string) file_get_contents($registryPath) : '';
if ($registrySource === '') {
    echo "check-audit-contract: the event registry is unreadable.\n";
    echo '  [audit-contract.zero-match] ' . AUDIT_REGISTRY_FILE . " is missing or empty; the whole scan would be vacuously green.\n";
    exit(1);
}

preg_match_all("/^const (VIRTUSPHERE_AUDIT_EVENT_[A-Z0-9_]+) = '([a-z0-9_.]+)';/m", $registrySource, $matches, PREG_SET_ORDER);
$registeredConstants = [];
$registeredCodes = [];
foreach ($matches as $match) {
    $registeredConstants[$match[1]] = $match[2];
    $registeredCodes[$match[2]] = $match[1];
}
if ($registeredConstants === []) {
    echo "check-audit-contract: no event codes found in the registry.\n";
    echo "  [audit-contract.zero-match] the event-constant pattern matched nothing; the scan cannot be trusted.\n";
    exit(1);
}

/**
 * Source with comments and doc blocks removed, string literals kept.
 *
 * Without this the guard reads its own explanations: the constants file
 * documents which call sites must pass a category and named `addLog()` while
 * describing why it was removed, and a prose mention of a forbidden name is not
 * a reintroduction of it. Strings stay because a producer's event code and a
 * sentinel literal are both strings, and both are exactly what is being looked
 * for.
 */
function audit_contract_code(string $source): string
{
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                // Keep the line count so a finding's context stays readable.
                ? str_repeat("\n", substr_count($token[1], "\n"))
                : $token[1];
            continue;
        }
        $out .= $token;
    }

    return $out;
}

/**
 * Is this a logging/audit function name, as opposed to a name that merely
 * contains those letters?
 *
 * A substring match calls `login`, `logout` and `ssh_sftp_login` logging
 * functions, and then reports every one of them for taking a password, which is
 * the one parameter each of them obviously must take. The parts of the snake
 * case are the words, so `log` and `audit` have to BE one of them.
 */
function audit_contract_is_log_name(string $name): bool
{
    return array_intersect(explode('_', strtolower($name)), ['audit', 'log', 'logs']) !== [];
}

/** @return list<string> every first-party PHP file outside tests and vendor */
function audit_contract_files(string $app): array
{
    if (!is_dir($app)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (!str_ends_with($path, '.php')
            || str_contains($path, '/tests/')
            || str_contains($path, '/vendor/')
            || str_contains($path, '/lang/')) {
            continue;
        }
        $files[] = $path;
    }
    sort($files);

    return $files;
}

$files = audit_contract_files($app);
if ($files === []) {
    echo "check-audit-contract: no first-party PHP files in scope.\n";
    echo "  [audit-contract.zero-match] the file scan matched nothing; an empty scope is never proof of a clean tree.\n";
    exit(1);
}

$rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
foreach ($files as $path) {
    // A prefix strip, not str_replace: the repo root is often a short word that
    // also occurs INSIDE the path (`/repo/Docker/WebAPI/lib/repo/log.php`), and
    // replacing every occurrence rewrote the audit owner's own path into one
    // that no longer matched the owner list, so the owner reported itself.
    $relative = str_starts_with($path, $rootPrefix) ? substr($path, strlen($rootPrefix)) : $path;
    $source = audit_contract_code((string) file_get_contents($path));
    $isOwner = in_array($relative, AUDIT_OWNER_FILES, true);

    // A registry-owned write is `audit_event(`/`audit(`/`machine_api_audit_warning(`
    // whose SECOND argument is an event-code constant. Anything else in those
    // positions is the old free-text shape. `(?<!function )` keeps the owner's
    // own declaration out of it: a signature's parameter list has the same
    // shape as a call's argument list.
    preg_match_all(
        '/(?<!function )\b(audit_event|audit|machine_api_audit_warning)\s*\(\s*([^,()]{1,80}),\s*([A-Za-z_$][A-Za-z0-9_$\']{0,80})/s',
        $source,
        $calls,
        PREG_SET_ORDER
    );
    foreach ($calls as $call) {
        $second = trim($call[3]);
        if (str_starts_with($second, 'VIRTUSPHERE_AUDIT_EVENT_')) {
            $producerCalls++;
            $constant = rtrim($second, ',');
            if (!isset($registeredConstants[$constant])) {
                $findings[] = sprintf(
                    '  [audit-contract.unknown-event] %s calls %s() with %s, which the registry does not define.',
                    $relative,
                    $call[1],
                    $constant
                );
                continue;
            }
            $usedEvents[$constant] = true;
            continue;
        }
        // A definition or a pass-through inside the owner is not a producer.
        if ($isOwner || str_starts_with($second, '$eventCode')) {
            continue;
        }
        $findings[] = sprintf(
            '  [audit-contract.free-producer] %s calls %s() with %s instead of a VIRTUSPHERE_AUDIT_EVENT_* constant.',
            $relative,
            $call[1],
            $second
        );
    }

    // Direct writes into the audit table.
    if (!$isOwner && preg_match('/(INSERT\s+INTO|REPLACE\s+INTO)\s+deploy_logs/i', $source) === 1) {
        $findings[] = sprintf(
            '  [audit-contract.registry-bypass] %s writes into deploy_logs directly; audit_event() is the only writer.',
            $relative
        );
    }

    // Forbidden context keys in an audit context literal.
    foreach (AUDIT_FORBIDDEN_CONTEXT_KEYS as $key) {
        if (preg_match("/'" . preg_quote($key, '/') . "'\s*=>/i", $source) !== 1) {
            continue;
        }
        // Only inside a call to one of the audit entry points; the same key in
        // an unrelated array (a credential form, an SSH option map) is fine.
        if (preg_match(
            "/\b(?:audit_event|audit|machine_api_audit_warning)\s*\((?:[^;]{0,2000}?)'" . preg_quote($key, '/') . "'\s*=>/s",
            $source
        ) === 1) {
            $findings[] = sprintf(
                '  [audit-contract.forbidden-field] %s passes the audit context key "%s", which may carry a secret or a whole payload.',
                $relative,
                $key
            );
        }
    }

    // Dead free-text sinks.
    foreach (AUDIT_DEAD_SINKS as $sink) {
        if (preg_match('/\b' . preg_quote($sink, '/') . '\s*\(/', $source) === 1) {
            $findings[] = sprintf(
                '  [audit-contract.dead-sink] %s references %s(), which Etappe 10C removed; use audit_event().',
                $relative,
                $sink
            );
        }
    }

    // A log/audit signature that can be HANDED a token.
    preg_match_all(
        '/function\s+([A-Za-z0-9_]+)\s*\(([^)]{0,600})\)/i',
        $source,
        $signatures,
        PREG_SET_ORDER
    );
    foreach ($signatures as $signature) {
        if (!audit_contract_is_log_name($signature[1])) {
            continue;
        }
        foreach (AUDIT_TOKEN_SINK_PARAMS as $param) {
            if (preg_match('/\$' . preg_quote($param, '/') . '\b/i', $signature[2]) === 1) {
                $findings[] = sprintf(
                    '  [audit-contract.token-sink] %s: %s() takes $%s. A log signature that can be handed a secret will eventually store one.',
                    $relative,
                    $signature[1],
                    $param
                );
            }
        }
    }

    // A secret-shaped literal handed straight to a log call.
    if (preg_match(
        '/\b(?:audit_event|audit|machine_api_audit_warning|machine_api_log_warning|error_log)\s*\([^;]{0,400}?(Bearer\s+[A-Za-z0-9._-]{8,}|Basic\s+[A-Za-z0-9+\/=]{12,})/i',
        $source,
        $sentinel
    ) === 1) {
        $findings[] = sprintf(
            '  [audit-contract.sentinel] %s writes a credential-shaped literal (%s) into a log call.',
            $relative,
            substr($sentinel[1], 0, 24)
        );
    }

    // Exception messages are attacker/provider-controlled text. Every direct
    // process-log sink must cross the central named-secret redactor (the deploy
    // helper is accepted because it delegates to that same boundary).
    preg_match_all(
        '/(?:(?<![A-Za-z0-9_])error_log\s*\(|fwrite\s*\(\s*STDERR\s*,)[^;]{0,1600}?->getMessage\s*\(\s*\)[^;]{0,400}?\);/s',
        $source,
        $exceptionSinks,
        PREG_SET_ORDER
    );
    foreach ($exceptionSinks as $sink) {
        if (preg_match(
            '/(?:virtusphere_redact_log_text|deploy_worker_redact_secrets)\s*\([^;]*->getMessage\s*\(\s*\)/s',
            $sink[0]
        ) === 1) {
            continue;
        }
        $findings[] = sprintf(
            '  [audit-contract.unredacted-sink] %s writes an exception message to a process log without virtusphere_redact_log_text().',
            $relative
        );
    }
}

if ($producerCalls === 0) {
    echo "check-audit-contract: no structured audit producers found.\n";
    echo "  [audit-contract.zero-match] the producer pattern matched nothing; a rewritten call shape must fail here, not pass silently.\n";
    exit(1);
}

// A registry entry nothing writes is either a producer that was removed without
// its event, or an event somebody defined and then wired up differently. Both
// leave a code in the vocabulary that a filter offers and no row ever carries.
foreach ($registeredConstants as $constant => $code) {
    if (!isset($usedEvents[$constant])) {
        $findings[] = sprintf(
            '  [audit-contract.unused-event] %s (%s) is defined but no producer writes it; remove it or wire it up.',
            $constant,
            $code
        );
    }
}

if ($findings !== []) {
    echo "check-audit-contract: the Etappe 10C audit contract is broken.\n";
    echo "Persisted events are owned by lib/audit_event_definitions.php and written only\n";
    echo "through audit_event(); a free sentence or a direct INSERT is not auditable.\n\n";
    sort($findings);
    foreach ($findings as $finding) {
        echo $finding . "\n";
    }
    echo "\nFix: add or reuse an event in the registry, pass its constant plus a typed\n";
    echo "context, and let the presenter render the human-readable description.\n";
    exit(1);
}

if (!$quiet) {
    printf(
        "check-audit-contract: %d Dateien, %d Producer-Aufrufe, %d registrierte Ereignisse, alle zugeordnet.\n",
        count($files),
        $producerCalls,
        count($registeredConstants)
    );
}
exit(0);
