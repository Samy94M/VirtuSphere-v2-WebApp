<?php

declare(strict_types=1);

/**
 * Dependency-free redaction shared by database audits and PHP/container logs.
 *
 * The logger cannot identify an arbitrary opaque secret by value, and pretending
 * otherwise would be the more dangerous claim. What it can do is remove the
 * syntaxes in which a secret is *named*: an auth header of any scheme, a
 * token-bearing query parameter, a JSON or form key-value pair, and the
 * URL-encoded form each of those takes after a round trip through a request
 * line. Everything that reaches a log sink runs through here, so an exception
 * message quoting a request, a stack frame carrying an argument and a raw
 * REQUEST_URI are all covered by one rule instead of three.
 *
 * This is the second boundary, not the first. Callers still must not hand a
 * whole payload or a secret value to a log API; the audit registry enforces
 * that independently by refusing the field outright. A redactor is what catches
 * the cases nobody classified.
 */

/** What a removed value is replaced by. */
const VIRTUSPHERE_REDACTED_MARK = '[redacted]';

/**
 * Credential-bearing PARAMETER names. Deliberately disjoint from the header
 * names below: the two passes must not both match the same text, or the second
 * one redacts the first one's marker and leaves `[redacted]]` behind.
 */
const VIRTUSPHERE_REDACTED_KEYS =
    'access_token|refresh_token|auth_token|api_key|apikey|client_secret|private_key|' .
    'password|passwd|pwd|secret|token';

/**
 * Credential-bearing HEADER names, in both the wire spelling and the `HTTP_*`
 * form a dumped `$_SERVER` produces.
 */
const VIRTUSPHERE_REDACTED_HEADERS =
    'Authorization|Proxy-Authorization|X-VirtuSphere-Token|Auth-Token|Set-Cookie|Cookie|' .
    'HTTP_AUTHORIZATION|HTTP_PROXY_AUTHORIZATION|HTTP_X_VIRTUSPHERE_TOKEN|HTTP_AUTH_TOKEN|HTTP_COOKIE';

function virtusphere_redact_log_text(string $text): string
{
    if ($text === '') {
        return '';
    }

    // Headers first, and through a callback, because the auth scheme is worth
    // keeping and a plain replacement cannot decide whether one is present.
    // "the client sent Basic where we expect Bearer" is most of what an
    // auth-failure investigation needs, and cutting the whole header value
    // destroys exactly that. The value stops at a quote, comma or semicolon so
    // the same rule works on a raw header line, on a serialised $_SERVER entry
    // and on a JSON object without swallowing the fields that follow it.
    $redacted = preg_replace_callback(
        '/\b(' . VIRTUSPHERE_REDACTED_HEADERS . ')("?\s*[:=]\s*"?)([^\r\n,;"\']*)/i',
        static function (array $match): string {
            $scheme = preg_match('/^([A-Za-z][A-Za-z0-9._-]*)\s+\S/', $match[3], $found) === 1
                ? $found[1] . ' '
                : '';

            return $match[1] . $match[2] . $scheme . VIRTUSPHERE_REDACTED_MARK;
        },
        $text
    );
    $redacted = is_string($redacted) ? $redacted : $text;

    $keys = VIRTUSPHERE_REDACTED_KEYS;
    $skip = '(?!' . preg_quote(VIRTUSPHERE_REDACTED_MARK, '/') . ')';
    $patterns = [
        // Query string, twice: once as it appears in a URI, once after the `=`
        // was percent-encoded, which is the shape a nested URL inside a
        // redirect parameter or a logged form body arrives in.
        '/([?&](?:' . $keys . ')=)' . $skip . '[^&#\s"\']*/i',
        '/((?:' . $keys . ')%3D)' . $skip . '(?:(?!%26|&|\s|["\']).)*/i',
        // JSON, form and PHP array key-value: "token": "x", token=x,
        // 'secret' => 'x'. The `=(?!>)` is load-bearing: with a plain `[:=]`
        // the engine answers a blocked `=>` by backtracking to the bare `=`
        // and consuming the `>` as the value, so a second pass over an already
        // redacted PHP array produced `"api_key" =[redacted] [redacted]`.
        '/(["\']?(?:' . $keys . ')["\']?\s*(?:=>|:|=(?!>))\s*)' . $skip . '(?:"[^"]*"|\'[^\']*\'|[^,;)}\]\s]+)/i',
    ];

    foreach ($patterns as $pattern) {
        $next = preg_replace($pattern, '$1' . VIRTUSPHERE_REDACTED_MARK, $redacted);
        // A pattern that fails (backtrack limit on a pathological line) returns
        // null. Keep the last good text rather than blanking the message, and
        // let the remaining patterns still run over it.
        $redacted = is_string($next) ? $next : $redacted;
    }

    return $redacted;
}

/** Byte-bounded UTF-8 text without splitting a multibyte character. */
function virtusphere_log_text_bytes(string $text, int $maxBytes): string
{
    if ($maxBytes < 1) {
        return '';
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    if (strlen($text) <= $maxBytes) {
        return $text;
    }

    return mb_strcut($text, 0, $maxBytes, 'UTF-8');
}
