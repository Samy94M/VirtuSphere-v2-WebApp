<?php

declare(strict_types=1);

/**
 * Parses the table-navigation cursor independently of the row filter.
 *
 * A cursor is a positive database id, and exactly one direction may be named.
 * Invalid input is not rounded, clamped or ignored: silently falling back to
 * the newest window would make a stale bookmark look as though it still named
 * the requested position. The page renders an explicit recovery link instead.
 *
 * @param array<string,mixed> $query
 * @return array{before:?int,after:?int,supplied:bool,invalid:bool}
 */
function log_cursor_from_query(array $query): array
{
    $keys = array_values(array_filter(
        ['before', 'after'],
        static fn (string $key): bool => array_key_exists($key, $query)
    ));
    if ($keys === []) {
        return ['before' => null, 'after' => null, 'supplied' => false, 'invalid' => false];
    }
    if (count($keys) !== 1) {
        return ['before' => null, 'after' => null, 'supplied' => true, 'invalid' => true];
    }

    $key = $keys[0];
    $value = $query[$key];
    if (!is_string($value) && !is_int($value)) {
        return ['before' => null, 'after' => null, 'supplied' => true, 'invalid' => true];
    }

    $raw = trim((string) $value);
    if ($raw === '' || preg_match('/^[0-9]+$/D', $raw) !== 1) {
        return ['before' => null, 'after' => null, 'supplied' => true, 'invalid' => true];
    }

    $normalized = ltrim($raw, '0');
    $max = (string) PHP_INT_MAX;
    if ($normalized === '' || strlen($normalized) > strlen($max)
        || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
        return ['before' => null, 'after' => null, 'supplied' => true, 'invalid' => true];
    }

    $cursor = (int) $normalized;
    return [
        'before' => $key === 'before' ? $cursor : null,
        'after' => $key === 'after' ? $cursor : null,
        'supplied' => true,
        'invalid' => false,
    ];
}
