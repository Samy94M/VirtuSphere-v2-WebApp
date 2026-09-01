<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_create_constants.php';

/**
 * The PHP half of the per-VM create marker (Etappe 14B, plan section 8.4).
 *
 * Ansible/emit_create_result.py writes exactly one line per control call:
 *
 *     ::virtusphere-create:: v1 <base64url without padding>
 *
 * and this module is the only place that reads it. Everything it accepts is
 * closed: the protocol version, the six events, each event's exact field set,
 * and every id against its own character class. A missing, duplicated,
 * oversized or contradictory marker is protocol_error and is NEVER
 * reconstructed from ordinary Ansible prose, because that prose changes with
 * an upstream release while a VM on a host does not.
 *
 * The two halves are deliberately symmetric and both are tested: the Python
 * side refuses to emit what this side refuses to read, so a payload can only
 * be wrong in one place at a time.
 */

// The decoded payload is remote input. The bound is the last defence rather
// than the first: the emitter caps it as well.
const VIRTUSPHERE_CREATE_MARKER_MAX_PAYLOAD_BYTES = 8192;
const VIRTUSPHERE_CREATE_MARKER_MAX_LINE_BYTES = 16384;

const VIRTUSPHERE_CREATE_EVENT_PREPARED = 'prepared';
const VIRTUSPHERE_CREATE_EVENT_LAUNCHED = 'launched';
const VIRTUSPHERE_CREATE_EVENT_RUNNING = 'running';
const VIRTUSPHERE_CREATE_EVENT_SUCCEEDED = 'succeeded';
const VIRTUSPHERE_CREATE_EVENT_FAILED = 'failed';
const VIRTUSPHERE_CREATE_EVENT_REJECTED = 'rejected';

/** Event => its exact field list, mirroring EVENTS in emit_create_result.py. */
const VIRTUSPHERE_CREATE_EVENT_FIELDS = [
    VIRTUSPHERE_CREATE_EVENT_PREPARED => ['portal_vm_id', 'vm_name', 'existed_before', 'precheck_moid', 'precheck_instance_uuid'],
    VIRTUSPHERE_CREATE_EVENT_LAUNCHED => ['portal_vm_id', 'vm_name', 'async_jid', 'async_dir', 'existed_before', 'precheck_moid', 'precheck_instance_uuid'],
    VIRTUSPHERE_CREATE_EVENT_RUNNING => ['portal_vm_id', 'async_jid'],
    VIRTUSPHERE_CREATE_EVENT_SUCCEEDED => ['portal_vm_id', 'vm_name', 'async_jid', 'changed', 'moid', 'instance_uuid', 'power_state'],
    VIRTUSPHERE_CREATE_EVENT_FAILED => ['portal_vm_id', 'async_jid', 'error'],
    VIRTUSPHERE_CREATE_EVENT_REJECTED => ['portal_vm_id', 'vm_name', 'error_code', 'error'],
];

/** Thrown for every violation; the caller turns it into protocol_error. */
final class CreateMarkerProtocolException extends RuntimeException
{
}

/**
 * The one marker in this control call's output.
 *
 * Scans the merged remote output and requires exactly one marker line. Zero and
 * two are both protocol errors: zero means the playbook did not reach its
 * result step, and two means two things claim to describe the same unit, which
 * a "last one wins" rule would silently resolve in favour of whichever ran
 * later.
 *
 * @return array<string, mixed> The validated payload, event included.
 */
function ansible_create_marker_extract(string $output): array
{
    $found = [];
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        $line = trim($line);
        if (!str_starts_with($line, VIRTUSPHERE_CREATE_MARKER_PREFIX)) {
            continue;
        }
        if (strlen($line) > VIRTUSPHERE_CREATE_MARKER_MAX_LINE_BYTES) {
            throw new CreateMarkerProtocolException('The create marker line is larger than the protocol allows.');
        }
        $found[] = $line;
    }
    if (count($found) !== 1) {
        throw new CreateMarkerProtocolException(
            count($found) === 0
                ? 'The control call produced no create marker.'
                : 'The control call produced ' . count($found) . ' create markers; exactly one is allowed.'
        );
    }

    return ansible_create_marker_parse($found[0]);
}

/**
 * Parses one marker line. Split out from the scan so a stored line can be
 * re-checked later without the surrounding output.
 *
 * @return array<string, mixed>
 */
function ansible_create_marker_parse(string $line): array
{
    $parts = explode(' ', trim($line));
    if (count($parts) !== 3 || $parts[0] !== VIRTUSPHERE_CREATE_MARKER_PREFIX) {
        throw new CreateMarkerProtocolException('The create marker is malformed.');
    }
    if ($parts[1] !== 'v' . VIRTUSPHERE_CREATE_PROTOCOL_VERSION) {
        // A future version is refused rather than guessed at: an unknown
        // protocol is exactly the situation in which reading fields "that look
        // familiar" produces a confident wrong answer.
        throw new CreateMarkerProtocolException('Unsupported create protocol version: ' . $parts[1]);
    }
    if (preg_match('/^[A-Za-z0-9_-]+$/', $parts[2]) !== 1) {
        throw new CreateMarkerProtocolException('The create marker payload is not base64url without padding.');
    }
    $decoded = base64_decode(strtr($parts[2], '-_', '+/') . str_repeat('=', (4 - strlen($parts[2]) % 4) % 4), true);
    if ($decoded === false || $decoded === '') {
        throw new CreateMarkerProtocolException('The create marker payload could not be decoded.');
    }
    if (strlen($decoded) > VIRTUSPHERE_CREATE_MARKER_MAX_PAYLOAD_BYTES) {
        throw new CreateMarkerProtocolException('The create marker payload is larger than the protocol allows.');
    }

    try {
        $payload = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new CreateMarkerProtocolException('The create marker payload is not valid JSON.');
    }
    if (!is_array($payload) || array_is_list($payload)) {
        throw new CreateMarkerProtocolException('The create marker payload is not an object.');
    }

    return ansible_create_marker_validate($payload);
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function ansible_create_marker_validate(array $payload): array
{
    $event = (string) ($payload['event'] ?? '');
    if (!isset(VIRTUSPHERE_CREATE_EVENT_FIELDS[$event])) {
        throw new CreateMarkerProtocolException('Unknown create event.');
    }
    $fields = VIRTUSPHERE_CREATE_EVENT_FIELDS[$event];
    $extra = array_diff(array_keys($payload), array_merge($fields, ['event']));
    if ($extra !== []) {
        throw new CreateMarkerProtocolException('Unexpected create marker field(s): ' . implode(', ', $extra));
    }
    $missing = array_diff($fields, array_keys($payload));
    if ($missing !== []) {
        throw new CreateMarkerProtocolException('Missing create marker field(s): ' . implode(', ', $missing));
    }

    $result = ['event' => $event];
    foreach ($fields as $field) {
        $result[$field] = ansible_create_marker_field($field, $payload[$field]);
    }
    if (($event === VIRTUSPHERE_CREATE_EVENT_PREPARED || $event === VIRTUSPHERE_CREATE_EVENT_LAUNCHED)) {
        $hasMoid = $result['precheck_moid'] !== null;
        $hasUuid = $result['precheck_instance_uuid'] !== null;
        if ($hasMoid !== $hasUuid || $result['existed_before'] !== ($hasMoid && $hasUuid)) {
            // Half an identity is what lets a name-based match look proven.
            throw new CreateMarkerProtocolException('existed_before contradicts the precheck identity.');
        }
    }

    return $result;
}

/** Validates one field against its own class; unknown names cannot occur. */
function ansible_create_marker_field(string $field, mixed $value): mixed
{
    return match ($field) {
        // is_int() already excludes a bool: PHP's true is not an int, unlike in
        // the Python emitter, where bool is an int subclass and the check there
        // has to be explicit.
        'portal_vm_id' => is_int($value) && $value > 0
            ? $value
            : throw new CreateMarkerProtocolException('portal_vm_id must be a positive integer.'),
        'existed_before', 'changed' => is_bool($value)
            ? $value
            : throw new CreateMarkerProtocolException($field . ' must be a boolean.'),
        'vm_name' => ansible_create_marker_text($field, $value, 191),
        'error' => ansible_create_marker_text($field, $value, 1024),
        'async_jid' => is_string($value) && deploy_create_jid_is_valid($value)
            ? $value
            : throw new CreateMarkerProtocolException('async_jid is outside its allowed character class.'),
        'moid' => ansible_create_marker_pattern($field, $value, '/^[A-Za-z0-9._-]{1,64}$/'),
        'instance_uuid' => ansible_create_marker_pattern($field, $value, '/^[A-Za-z0-9 :._-]{1,64}$/'),
        'power_state' => ansible_create_marker_pattern($field, $value, '/^[A-Za-z]{1,32}$/'),
        'precheck_moid' => $value === null ? null : ansible_create_marker_pattern($field, $value, '/^[A-Za-z0-9._-]{1,64}$/'),
        'precheck_instance_uuid' => $value === null ? null : ansible_create_marker_pattern($field, $value, '/^[A-Za-z0-9 :._-]{1,64}$/'),
        'async_dir' => is_string($value) && str_starts_with($value, '/') && !str_contains($value, '..') && strlen($value) <= 1024
            ? $value
            : throw new CreateMarkerProtocolException('async_dir must be an absolute path without traversal.'),
        'error_code' => is_string($value) && in_array($value, VIRTUSPHERE_CREATE_ERROR_CODES, true)
            ? $value
            : throw new CreateMarkerProtocolException('error_code is not one of the closed codes.'),
        default => throw new CreateMarkerProtocolException('Unknown create marker field: ' . $field),
    };
}

function ansible_create_marker_text(string $field, mixed $value, int $max): string
{
    if (!is_string($value) || $value === '' || strlen($value) > $max) {
        throw new CreateMarkerProtocolException($field . ' must be a non-empty string within its length limit.');
    }
    // No control characters: the marker is one line, and the value is later
    // rendered next to other fields in a log the operator reads.
    if (preg_match('/[\x00-\x1F]/', $value) === 1) {
        throw new CreateMarkerProtocolException($field . ' must not carry control characters.');
    }

    return $value;
}

function ansible_create_marker_pattern(string $field, mixed $value, string $pattern): string
{
    if (!is_string($value) || preg_match($pattern, $value) !== 1) {
        throw new CreateMarkerProtocolException($field . ' is outside its allowed character class.');
    }

    return $value;
}

/**
 * Whether a marker describes the unit the worker is currently driving.
 *
 * Kept apart from the shape validation because the two answer different
 * questions: the marker can be perfectly well formed and still belong to
 * another VM or another async job, which is what a resumed poll against a stale
 * result file would look like.
 *
 * @param array<string, mixed> $marker
 */
function ansible_create_marker_matches(array $marker, int $portalVmId, string $vmName, ?string $asyncJid = null): bool
{
    if ((int) ($marker['portal_vm_id'] ?? 0) !== $portalVmId) {
        return false;
    }
    if (array_key_exists('vm_name', $marker) && (string) $marker['vm_name'] !== $vmName) {
        return false;
    }
    if ($asyncJid !== null && array_key_exists('async_jid', $marker) && (string) $marker['async_jid'] !== $asyncJid) {
        return false;
    }

    return true;
}
