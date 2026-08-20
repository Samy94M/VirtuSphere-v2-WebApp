<?php

declare(strict_types=1);

require_once __DIR__ . '/remote_execution_constants.php';

function remote_protocol_schema_path(?string $override = null): string
{
    $candidates = array_values(array_filter([
        $override,
        dirname(__DIR__, 2) . '/Ansible/runner/protocol-v1.json',
        '/var/www/ansible-src/runner/protocol-v1.json',
    ], static fn (mixed $path): bool => is_string($path) && $path !== ''));
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    throw new RuntimeException('Remote protocol schema is unavailable.');
}

/** @return array<string, mixed> */
function remote_protocol_schema(?string $override = null): array
{
    $raw = file_get_contents(remote_protocol_schema_path($override));
    if (!is_string($raw) || strlen($raw) > VIRTUSPHERE_REMOTE_PROTOCOL_DOCUMENT_MAX_BYTES) {
        throw new RuntimeException('Remote protocol schema is empty or oversized.');
    }
    try {
        $schema = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Remote protocol schema is malformed.', 0, $exception);
    }
    if (!is_array($schema) || ($schema['protocol'] ?? null) !== VIRTUSPHERE_REMOTE_PROTOCOL_VERSION || !is_array($schema['schemas'] ?? null)) {
        throw new RuntimeException('Remote protocol schema root is unsupported.');
    }
    return $schema;
}

/** @param array<string, mixed> $rule */
function remote_protocol_validate_value(mixed $value, array $rule, string $path): void
{
    if (array_key_exists('const', $rule) && $value !== $rule['const']) {
        throw new RuntimeException($path . ' violates a protocol constant.');
    }
    $type = $rule['type'] ?? null;
    $validType = match ($type) {
        null => true,
        'object' => is_array($value) && !array_is_list($value),
        'array' => is_array($value) && array_is_list($value),
        'string' => is_string($value),
        'integer' => is_int($value),
        'boolean' => is_bool($value),
        default => false,
    };
    if (!$validType) {
        throw new RuntimeException($path . ' has an invalid protocol type.');
    }
    if (isset($rule['enum']) && (!is_array($rule['enum']) || !in_array($value, $rule['enum'], true))) {
        throw new RuntimeException($path . ' is outside the closed protocol enum.');
    }
    if (is_array($value) && !array_is_list($value)) {
        $properties = is_array($rule['properties'] ?? null) ? $rule['properties'] : [];
        foreach ((array) ($rule['required'] ?? []) as $required) {
            if (!is_string($required) || !array_key_exists($required, $value)) {
                throw new RuntimeException($path . ' is missing a required field.');
            }
        }
        if (($rule['additionalProperties'] ?? null) === false && array_diff(array_keys($value), array_keys($properties)) !== []) {
            throw new RuntimeException($path . ' contains an unknown field.');
        }
        foreach ($value as $key => $child) {
            if (isset($properties[$key]) && is_array($properties[$key])) {
                remote_protocol_validate_value($child, $properties[$key], $path . '.' . $key);
            }
        }
        return;
    }
    if (is_array($value)) {
        $count = count($value);
        if ($count < (int) ($rule['minItems'] ?? 0) || $count > (int) ($rule['maxItems'] ?? PHP_INT_MAX)) {
            throw new RuntimeException($path . ' has an invalid protocol item count.');
        }
        $itemRule = is_array($rule['items'] ?? null) ? $rule['items'] : [];
        foreach ($value as $index => $child) {
            remote_protocol_validate_value($child, $itemRule, $path . '[' . $index . ']');
        }
        return;
    }
    if (is_string($value)) {
        $length = strlen($value);
        if ($length < (int) ($rule['minLength'] ?? 0) || $length > (int) ($rule['maxLength'] ?? PHP_INT_MAX)) {
            throw new RuntimeException($path . ' has an invalid protocol length.');
        }
        if (isset($rule['pattern']) && (!is_string($rule['pattern']) || preg_match('~' . str_replace('~', '\\~', $rule['pattern']) . '~D', $value) !== 1)) {
            throw new RuntimeException($path . ' violates the protocol pattern.');
        }
        return;
    }
    if (is_int($value) && ($value < (int) ($rule['minimum'] ?? PHP_INT_MIN) || $value > (int) ($rule['maximum'] ?? PHP_INT_MAX))) {
        throw new RuntimeException($path . ' is outside protocol bounds.');
    }
}

/** @param array<string, string> $expected @return array<string, mixed> */
function remote_protocol_decode(string $document, string $json, array $expected, ?string $schemaPath = null): array
{
    $maxBytes = $document === 'observation' ? VIRTUSPHERE_REMOTE_OBSERVATION_MAX_BYTES : VIRTUSPHERE_REMOTE_PROTOCOL_DOCUMENT_MAX_BYTES;
    if ($json === '' || strlen($json) > $maxBytes) {
        throw new RuntimeException('Remote protocol document is empty or oversized.');
    }
    try {
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Remote protocol document is malformed.', 0, $exception);
    }
    $schemas = remote_protocol_schema($schemaPath)['schemas'];
    $rule = is_array($schemas[$document] ?? null) ? $schemas[$document] : null;
    if (!is_array($value) || array_is_list($value) || $rule === null) {
        throw new RuntimeException('Remote protocol document kind is unsupported.');
    }
    remote_protocol_validate_value($value, $rule, $document);
    foreach ($expected as $key => $expectedValue) {
        if (!array_key_exists($key, $value) || !hash_equals($expectedValue, (string) $value[$key])) {
            throw new RuntimeException('Remote protocol identity mismatch: ' . $key . '.');
        }
    }
    return $value;
}
