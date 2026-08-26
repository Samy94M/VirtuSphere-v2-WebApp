<?php

declare(strict_types=1);

require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/csrf.php';

/**
 * Portal security guards and field-level diff helpers. Persisted events route
 * through audit_event(), whose registry owns category, object, result and the
 * typed context schema.
 *
 * Compatibility descriptions stay English and are escaped at display. This
 * module bounds diff fragments before the registry redacts and persists them.
 */

/** Longest single value rendered into a diff before it is cut. */
const VIRTUSPHERE_AUDIT_VALUE_MAX = 48;

/** Longest whole diff summary; the rest is reported as a count. */
const VIRTUSPHERE_AUDIT_SUMMARY_MAX = 480;

/**
 * Refuses a request that lacks the permission, and records it. Repeated refusals
 * for one account are the signature of a stolen session or someone probing for
 * rights, which is invisible while every page merely exits.
 *
 * HTML callers keep the previous 403 plain-text body. Polling endpoints may
 * request the equivalent JSON envelope so an authorization loss never turns
 * into a parser retry loop; both paths write the same audit event once.
 */
function portal_forbid(mysqli $db, ?array $user, string $permission, bool $json = false): never
{
    $userId = isset($user['id']) ? (int) $user['id'] : null;
    audit_event(
        $db,
        VIRTUSPHERE_AUDIT_EVENT_AUTH_ACCESS_DENIED,
        'request',
        'portal',
        VIRTUSPHERE_AUDIT_RESULT_DENIED,
        ['permission' => audit_snippet($permission, 128)],
        $userId
    );

    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => __t('portal.forbidden')], JSON_THROW_ON_ERROR);
        exit;
    }
    exit(__t('portal.forbidden'));
}

/**
 * Refuses a request whose CSRF token did not verify, and records it. A burst of
 * these is either an attack or a broken reverse proxy; a single one is usually a
 * tab that sat open past the session lifetime. Either way it should be visible.
 *
 * $context names the page/action so the entry is useful without a stack trace.
 * $body keeps the caller's original response text (logout.php answers with
 * `portal.invalid_request`, every other page with `portal.invalid_csrf`).
 */
function portal_reject_csrf(mysqli $db, ?array $user, string $context, ?string $body = null): never
{
    $userId = isset($user['id']) ? (int) $user['id'] : null;
    $page = audit_snippet($context, 64);
    audit_event(
        $db,
        VIRTUSPHERE_AUDIT_EVENT_AUTH_CSRF_REJECTED,
        'request',
        $page,
        VIRTUSPHERE_AUDIT_RESULT_DENIED,
        ['page' => $page],
        $userId
    );

    http_response_code(400);
    exit($body ?? __t('portal.invalid_csrf'));
}

/**
 * The CSRF half of a POST prologue, shared by the standard portal pages so the
 * check cannot drift or be forgotten on a new page. Derives the audit context
 * from the running script, which is exactly the page-name literal every page
 * passed by hand. Permission gates stay page/action-local (they differ per
 * action) and are not folded in here. Not for login.php (soft redirect),
 * logout.php (custom body) or session_ping.php (JSON contract).
 */
function portal_guard_post(mysqli $db, ?array $user): void
{
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        portal_reject_csrf($db, $user, $script !== '' ? $script : 'portal');
    }
}

/** Trims, collapses newlines and bounds a value before it enters a log line. */
function audit_snippet(mixed $value, int $max = VIRTUSPHERE_AUDIT_VALUE_MAX): string
{
    $text = trim((string) $value);
    $text = (string) preg_replace('/\s+/', ' ', $text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }

    return mb_substr($text, 0, $max - 1) . '…';
}

/** A single diff side: quoted, bounded, with an explicit marker for "was empty". */
function audit_diff_value(mixed $value): string
{
    $text = audit_snippet($value);

    return $text === '' ? '(empty)' : '"' . $text . '"';
}

/**
 * Which fields of a record changed, and from what to what.
 *
 * Only keys present in $after are compared, so a caller lists exactly the columns
 * it wrote. Both sides are normalized to trimmed strings: a NULL datacenter and
 * an empty one are the same value to a reader, and every column here is either a
 * string or a scalar the form posted as one.
 *
 * $opaque names fields whose *value* must never reach the log: secrets, and free
 * prose like notes, which would flood the line and can carry information the
 * audit trail has no business duplicating. Those report "changed" only.
 *
 * @param array<string, mixed> $before stored row
 * @param array<string, mixed> $after  submitted values
 * @param array<int, string> $opaque
 * @return string '' when nothing changed
 */
function audit_change_summary(array $before, array $after, array $opaque = []): string
{
    $parts = [];
    foreach ($after as $field => $newValue) {
        $old = trim((string) ($before[$field] ?? ''));
        $new = trim((string) ($newValue ?? ''));
        if ($old === $new) {
            continue;
        }
        $parts[] = in_array($field, $opaque, true)
            ? $field . ': changed'
            : $field . ': ' . audit_diff_value($old) . ' -> ' . audit_diff_value($new);
    }

    return audit_join_summary($parts);
}

/**
 * Joins diff parts under a length cap. A truncated summary says how many fields
 * it dropped, so a reader is never left believing the list is complete.
 *
 * @param array<int, string> $parts
 */
function audit_join_summary(array $parts): string
{
    $summary = '';
    foreach ($parts as $index => $part) {
        $candidate = $summary === '' ? $part : $summary . '; ' . $part;
        if (mb_strlen($candidate) > VIRTUSPHERE_AUDIT_SUMMARY_MAX) {
            return $summary . '; +' . (count($parts) - $index) . ' more field(s)';
        }
        $summary = $candidate;
    }

    return $summary;
}
