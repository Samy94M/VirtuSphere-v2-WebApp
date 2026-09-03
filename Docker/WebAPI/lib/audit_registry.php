<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/log_redaction.php';
require_once __DIR__ . '/audit_event_definitions.php';

const VIRTUSPHERE_AUDIT_CONTEXT_MAX_BYTES = 4096;
const VIRTUSPHERE_AUDIT_MESSAGE_MAX_BYTES = 2048;
const VIRTUSPHERE_AUDIT_OBJECT_ID_MAX_BYTES = 191;

/**
 * Longest typed id list a context may carry. A caller slices to this before it
 * hands the list over, so an over-long list is a truncated diagnostic rather
 * than a rejected audit: a bulk action over 300 VMs must still be recorded.
 */
const VIRTUSPHERE_AUDIT_ID_LIST_MAX = 200;

const VIRTUSPHERE_AUDIT_RESULT_SUCCESS = 'success';
const VIRTUSPHERE_AUDIT_RESULT_DENIED = 'denied';
const VIRTUSPHERE_AUDIT_RESULT_WARNING = 'warning';
const VIRTUSPHERE_AUDIT_RESULT_FAILURE = 'failure';
const VIRTUSPHERE_AUDIT_RESULT_RECOVERED = 'recovered';
const VIRTUSPHERE_AUDIT_RESULTS = [
    VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
    VIRTUSPHERE_AUDIT_RESULT_DENIED,
    VIRTUSPHERE_AUDIT_RESULT_WARNING,
    VIRTUSPHERE_AUDIT_RESULT_FAILURE,
    VIRTUSPHERE_AUDIT_RESULT_RECOVERED,
];

/** @return array<string,array{type:string,max?:int,length?:int,item_max?:int,count_max?:int}> */
function audit_context_field_registry(): array
{
    $int = ['type' => 'int'];
    $bool = ['type' => 'bool'];
    $id = ['type' => 'identifier', 'max' => 128];
    $idList = ['type' => 'int_list', 'count_max' => VIRTUSPHERE_AUDIT_ID_LIST_MAX];

    return [
        'action' => $id, 'source' => $id, 'scope' => $id, 'reason_code' => $id,
        'reason' => ['type' => 'string', 'max' => 256],
        'page' => ['type' => 'identifier', 'max' => 64],
        'permission' => ['type' => 'identifier', 'max' => 128],
        'username' => ['type' => 'string', 'max' => 191],
        'name' => ['type' => 'string', 'max' => 191],
        'role' => $id, 'outcome' => $id, 'component' => $id, 'direction' => $id,
        'trust_mode' => $id, 'progress_kind' => $id, 'mode' => $id,
        'status' => $id, 'old_state' => $id, 'new_state' => $id,
        'report_type' => $id, 'error_class' => ['type' => 'string', 'max' => 191],
        'changes' => ['type' => 'string', 'max' => 512],
        'host' => ['type' => 'string', 'max' => 253],
        'ip' => ['type' => 'string', 'max' => 45],
        'subject' => ['type' => 'string', 'max' => 255],
        'valid_to' => ['type' => 'identifier', 'max' => 32],
        'old_value' => ['type' => 'string', 'max' => 191],
        'new_value' => ['type' => 'string', 'max' => 191],
        'moid' => ['type' => 'identifier', 'max' => 128],
        'instance_uuid' => ['type' => 'identifier', 'max' => 64],
        'target_vlan' => ['type' => 'string', 'max' => 255],
        'resource_id' => ['type' => 'identifier', 'max' => 64],
        // Etappe 14D: the rollout fence. Two separate integers rather than one
        // "3 -> 1" sentence, because a saved search has to be able to ask "which
        // callbacks arrived below the current revision" without parsing prose.
        // 0 stands for "the caller sent none", which is the pre-cutover client;
        // it is a distinct answer from "sent an old one" and must stay readable
        // as such after the compatibility branch is eventually removed.
        'reported_revision' => $int, 'rollout_revision' => $int,
        'rollout_hostname' => ['type' => 'string', 'max' => 255],
        'previous_rollout_hostname' => ['type' => 'string', 'max' => 255],
        'previous_resource_id' => ['type' => 'identifier', 'max' => 64],
        'effect' => $id, 'blocked_reason' => $id,
        'filter_fingerprint' => ['type' => 'hex', 'length' => 64],
        'duration_minutes' => $int, 'duration_seconds' => $int, 'failure_count' => $int,
        'controller_id' => $int, 'revision' => $int, 'port' => $int,
        'mission_id' => $int, 'vm_count' => $int, 'row_count' => $int,
        'target_mission_id' => $int, 'affected_count' => $int, 'credential_id' => $int,
        'job_id' => $int, 'job_count' => $int, 'retry_of_job_id' => $int,
        'queued_count' => $int, 'open_count' => $int, 'paused_count' => $int,
        // Etappe 13R: what a recovery review looked at and what it moved. The
        // counts are separate fields rather than one sentence, because "nothing
        // changed" and "three cases still need a person" are different answers
        // and a saved search has to be able to tell them apart.
        'reviewed_count' => $int, 'requested_count' => $int, 'manual_count' => $int,
        'execution_id' => $int, 'resolution_id' => $int, 'resolution_code' => $id,
        // Etappe 14B-F: which create unit an operator released, and why the
        // release was refused when it was. The reason they typed is NOT here;
        // it lives in the append-only resolution behind system.config.
        'position' => $int, 'blocker' => $id,
        // Etappe 14C. Both are closed contract tokens, so `identifier` is the
        // right type: a value outside VIRTUSPHERE_SUPERVISOR_CONTRACTS cannot
        // reach here through the switch, and the type stops anything else.
        'target_contract' => $id, 'previous_contract' => $id,
        'failed_count' => $int, 'mission_count' => $int, 'interface_count' => $int,
        'rows_exported' => $int, 'total_rows' => $int, 'limit' => $int,
        'retire_count' => $int, 'active_count' => $int, 'threshold_percent' => $int,
        'item_count' => $int, 'report_version' => $int, 'suppressed_count' => $int,
        'throttle_seconds' => $int,
        'enabled' => $bool, 'selection_cleared' => $bool, 'scheduled' => $bool,
        'redirect_disabled' => $bool, 'truncated' => $bool,
        'vm_ids' => $idList, 'target_ids' => $idList, 'job_ids' => $idList,
        'items' => ['type' => 'string_list', 'count_max' => 100, 'item_max' => 128],
    ];
}

/** @return array<string,mixed> */
function audit_event_definition(string $eventCode, string $objectType, ?string $objectId, string $result): array
{
    $definition = audit_event_registry()[$eventCode] ?? null;
    if (!is_array($definition)) {
        throw new InvalidArgumentException('Unknown audit event code: ' . $eventCode);
    }
    if (!in_array($objectType, $definition['objects'], true)) {
        throw new InvalidArgumentException('Object type is not allowed for audit event ' . $eventCode);
    }
    if (!in_array($result, $definition['results'], true)) {
        throw new InvalidArgumentException('Result is not allowed for audit event ' . $eventCode);
    }
    $objectIdPolicy = $definition['objectId'] ?? null;
    if (!in_array($objectIdPolicy, ['required', 'nullable'], true)) {
        throw new LogicException('Audit registry has no valid object-id policy for ' . $eventCode);
    }
    if ($objectIdPolicy === 'required' && $objectId === null) {
        throw new InvalidArgumentException('Object id is required for audit event ' . $eventCode);
    }
    $objectIds = $definition['objectIds'] ?? null;
    if (!is_array($objectIds)) {
        throw new LogicException('Audit registry has no valid object-id allowlist for ' . $eventCode);
    }
    if ($objectIds !== [] && ($objectId === null || !in_array($objectId, $objectIds, true))) {
        throw new InvalidArgumentException('Object id is not allowed for audit event ' . $eventCode);
    }
    $category = $definition['category'] ?? null;
    if (!is_string($category)) {
        $category = $definition['categories'][$objectType] ?? null;
    }
    if (!is_string($category) && isset($definition['categoryById'])) {
        $category = $definition['categoryById'][$objectId ?? ''] ?? null;
    }
    if (!is_string($category) || !in_array($category, VIRTUSPHERE_LOG_CATEGORIES, true)) {
        throw new InvalidArgumentException('Audit registry has no valid category for event/object ' . $eventCode);
    }

    $definition['category'] = $category;
    return $definition;
}

/**
 * Which shape this event's object ids have. Almost everything the portal audits
 * is addressed by a machine id, a setting key, an endpoint file name or an IP,
 * all of which fit one closed charset. A VLAN is the exception: it has no id at
 * all, it is identified by its operator-typed name, and rejecting a name with a
 * space in it would turn a working reassign into a 500. So the difference is
 * declared per event rather than resolved by widening the charset for all of
 * them, which would let an unbounded value into every other object id too.
 */
function audit_event_object_id_kind(string $eventCode): string
{
    $definition = audit_event_registry()[$eventCode] ?? null;
    if (!is_array($definition)) {
        throw new InvalidArgumentException('Unknown audit event code: ' . $eventCode);
    }
    $kind = $definition['objectIdKind'] ?? null;

    return in_array($kind, ['identifier', 'name'], true)
        ? $kind
        : throw new LogicException('Audit registry has no valid object-id kind for ' . $eventCode);
}

/**
 * Over-length is a rejection, never a silent cut: a truncated id points at a
 * different row than the one the event happened to, and nothing downstream can
 * tell that it was shortened. A `name` id is still bounded and still stripped
 * of control characters and line breaks, which is what would let a forged log
 * line be spliced into the middle of a real one.
 */
function audit_object_id(string|int|null $value, string $kind = 'identifier'): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $id = trim((string) $value);
    if ($kind === 'name') {
        $id = trim((string) preg_replace('/\s+/u', ' ', virtusphere_redact_log_text($id)));
        if ($id === '' || !mb_check_encoding($id, 'UTF-8') || preg_match('/[\p{C}]/u', $id) === 1) {
            throw new InvalidArgumentException('Audit object name is not printable text');
        }
    } elseif (preg_match('/^[A-Za-z0-9_.:@-]+$/', $id) !== 1) {
        throw new InvalidArgumentException('Audit object id is not a closed identifier');
    }
    // Both branches above already guarantee a non-empty id: the name branch
    // rejects an empty one outright, and the identifier pattern needs at least
    // one character. Only the length is still open.
    if (strlen($id) > VIRTUSPHERE_AUDIT_OBJECT_ID_MAX_BYTES) {
        throw new InvalidArgumentException('Audit object id exceeds its byte limit');
    }

    return $id;
}

/**
 * The context key type is `array-key`, not `string`, on purpose: PHP coerces a
 * numeric-string array key to an int, so a caller passing `['5' => 'x']` really
 * does hand this an int key. Declaring the parameter as string-keyed would make
 * the `is_string()` guard below look redundant to a static analyser while it is
 * the only thing standing between that call and an unvalidated field name.
 *
 * @param array<array-key,mixed> $context
 * @param array<string,mixed> $definition
 * @return array<string,mixed>
 */
function audit_context_normalize(array $context, array $definition, string $result): array
{
    $fields = audit_context_field_registry();
    $required = $definition['required'] ?? null;
    $optional = $definition['optional'] ?? null;
    $requiredByResult = $definition['requiredByResult'] ?? null;
    if (!is_array($required) || !is_array($optional) || !is_array($requiredByResult)) {
        throw new LogicException('Audit registry context policy is incomplete');
    }
    $conditional = $requiredByResult[$result] ?? [];
    if (!is_array($conditional)) {
        throw new LogicException('Audit registry result context policy is invalid');
    }
    if (array_intersect($required, $optional) !== []) {
        throw new LogicException('Audit registry context fields overlap');
    }
    $required = array_values(array_unique([...$required, ...$conditional]));
    $allowed = array_values(array_unique([...$required, ...$optional]));
    foreach ($allowed as $field) {
        if (!is_string($field) || !isset($fields[$field])) {
            throw new LogicException('Audit registry references an unknown context field');
        }
    }

    $normalized = [];
    foreach ($context as $key => $value) {
        if (!is_string($key) || !in_array($key, $allowed, true) || !isset($fields[$key])) {
            throw new InvalidArgumentException('Audit context field is not allowed: ' . (string) $key);
        }
        if (preg_match('/password|secret|token|payload|exception|search|query|authorization|cookie|session/i', $key) === 1) {
            throw new InvalidArgumentException('Sensitive audit context field is forbidden: ' . $key);
        }
        if ($value === null) {
            throw new InvalidArgumentException('Audit context field must not be null: ' . $key);
        }
        $normalized[$key] = audit_context_value($key, $value, $fields[$key]);
    }
    foreach ($required as $key) {
        if (!array_key_exists($key, $normalized)) {
            throw new InvalidArgumentException('Required audit context field is missing: ' . $key);
        }
    }
    ksort($normalized);
    return $normalized;
}

/** @param array{type:string,max?:int,length?:int,item_max?:int,count_max?:int} $definition */
function audit_context_value(string $key, mixed $value, array $definition): mixed
{
    return match ($definition['type']) {
        'int' => audit_context_int($key, $value),
        'bool' => is_bool($value) ? $value : throw new InvalidArgumentException('Audit context field must be bool: ' . $key),
        'identifier' => audit_context_identifier($key, $value, (int) ($definition['max'] ?? 128)),
        'hex' => audit_context_hex($key, $value, isset($definition['length']) ? (int) $definition['length'] : null, (int) ($definition['max'] ?? 64)),
        'string' => audit_context_string($key, $value, (int) ($definition['max'] ?? 191)),
        'int_list' => audit_context_int_list($key, $value, (int) ($definition['count_max'] ?? 100)),
        'string_list' => audit_context_string_list($key, $value, (int) ($definition['count_max'] ?? 50), (int) ($definition['item_max'] ?? 128)),
        default => throw new LogicException('Unknown audit context type for ' . $key),
    };
}

function audit_context_int(string $key, mixed $value): int
{
    if (!is_int($value) || $value < 0) {
        throw new InvalidArgumentException('Audit context field must be a non-negative int: ' . $key);
    }
    return $value;
}

function audit_context_identifier(string $key, mixed $value, int $max): string
{
    if (!is_string($value) || strlen($value) > $max || preg_match('/^[A-Za-z0-9_.:+\/-]+$/', $value) !== 1) {
        throw new InvalidArgumentException('Audit context field must be an identifier: ' . $key);
    }
    return $value;
}

function audit_context_hex(string $key, mixed $value, ?int $length, int $max): string
{
    if (!is_string($value)
        || ($length !== null ? strlen($value) !== $length : strlen($value) > $max)
        || preg_match('/^[0-9a-f]+$/', $value) !== 1) {
        throw new InvalidArgumentException('Audit context field must be lowercase hex: ' . $key);
    }
    return $value;
}

/** @param array<string,mixed> $context */
function audit_context_json(array $context): ?string
{
    if ($context === []) {
        return null;
    }
    $json = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (strlen($json) > VIRTUSPHERE_AUDIT_CONTEXT_MAX_BYTES) {
        throw new LengthException('Audit context exceeds its byte limit');
    }

    return $json;
}

function audit_context_string(string $key, mixed $value, int $max): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('Audit context field must be a string: ' . $key);
    }
    $value = trim((string) preg_replace('/\s+/', ' ', virtusphere_redact_log_text($value)));
    if ($value === '') {
        throw new InvalidArgumentException('Audit context field must not be empty: ' . $key);
    }
    return virtusphere_log_text_bytes($value, $max);
}

/** @return list<int> */
function audit_context_int_list(string $key, mixed $value, int $max): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
        throw new InvalidArgumentException('Audit context field must be a bounded int list: ' . $key);
    }
    $result = [];
    foreach ($value as $item) {
        $result[] = audit_context_int($key, $item);
    }
    return array_values(array_unique($result));
}

/** @return list<string> */
function audit_context_string_list(string $key, mixed $value, int $countMax, int $itemMax): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > $countMax) {
        throw new InvalidArgumentException('Audit context field must be a bounded string list: ' . $key);
    }
    $result = [];
    foreach ($value as $item) {
        $result[] = audit_context_string($key, $item, $itemMax);
    }
    return $result;
}
