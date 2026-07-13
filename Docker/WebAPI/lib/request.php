<?php

declare(strict_types=1);

/**
 * Safe scalar accessors for request input ($_GET, $_POST).
 *
 * PHP arrays a query/body parameter whenever the client writes `key[]=x`, and a
 * `(string)` or `(int)` cast on that array raises "Array to string conversion".
 * The global error handler (lib/errors.php) turns that warning into a 500 and
 * writes a `system` audit row - so a single stray bracket in a URL takes a page
 * down and grows deploy_logs by one line. On the machine API, where the cast sits
 * ahead of the IP allowlist, any host could do it unauthenticated, one row per
 * request: the exact log-flood class the auth channel is bounded against.
 *
 * These helpers read the value only when it is a scalar and fall back to the
 * default otherwise. Behaviour for a normal scalar is identical to the raw cast,
 * so a migration changes nothing an operator sees; it only closes the array case.
 *
 * A parameter that is *meant* to be an array (bulk `vm_ids[]`) is read directly
 * with an is_array() guard at its own call site, not through these.
 */

/**
 * Reads a string parameter. Returns $default when the key is absent or the value
 * is not a scalar (an array, typically from `key[]=` in the URL or body).
 *
 * @param array<string,mixed> $source
 */
function request_string(array $source, string $key, string $default = ''): string
{
    $value = $source[$key] ?? null;

    return is_scalar($value) ? (string) $value : $default;
}

/**
 * Reads and trims a string parameter, the shape most filter/search reads want.
 *
 * @param array<string,mixed> $source
 */
function request_trimmed(array $source, string $key, string $default = ''): string
{
    $value = $source[$key] ?? null;

    return is_scalar($value) ? trim((string) $value) : $default;
}

/**
 * Reads an integer parameter. Returns $default when the key is absent or the
 * value is not a scalar. Scalar coercion matches the raw `(int)` cast exactly
 * ("12abc" -> 12, "abc" -> 0), so only the array case changes.
 *
 * @param array<string,mixed> $source
 */
function request_int(array $source, string $key, int $default = 0): int
{
    $value = $source[$key] ?? null;

    return is_scalar($value) ? (int) $value : $default;
}
