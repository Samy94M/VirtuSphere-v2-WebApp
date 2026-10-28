<?php

declare(strict_types=1);

require_once __DIR__ . '/layout_pages.php';

/*
 * Where a sign-in returns to (QL-04 A). require_login() and the countdown
 * sign-out form carry the target; login.php and logout.php accept it only
 * through login_return_validate(). A target is a page of the closed page
 * registry other than the sign-in itself, with an optional query of URL
 * characters, so it can never name a host, a scheme or another path; anything
 * else is null and the sign-in goes to the default page. The query passes
 * through unchanged: the target validates its own parameters (work context,
 * filters) and rechecks identity and permission like any other request. A
 * refused POST returns to its page by GET and keeps none of its input (F1).
 */

const VIRTUSPHERE_LOGIN_PAGE = 'login.php';
const VIRTUSPHERE_LOGIN_DEFAULT_PAGE = 'dashboard.php';
const VIRTUSPHERE_LOGIN_PASSWORD_CHANGE_PAGE = 'account.php';
const VIRTUSPHERE_LOGIN_RETURN_FIELD = 'next';
const VIRTUSPHERE_LOGIN_RETURN_MAX_LENGTH = 512;
const VIRTUSPHERE_LOGIN_REASON_FIELD = 'reason';
const VIRTUSPHERE_LOGIN_REASON_SESSION_EXPIRED = 'session_expired';
const VIRTUSPHERE_LOGIN_DISCARDED_FIELD = 'discarded';

/** @return list<string> */
function login_return_pages(): array
{
    return array_values(array_diff(VIRTUSPHERE_LAYOUT_PAGES, [VIRTUSPHERE_LOGIN_PAGE]));
}

function login_return_validate(?string $target): ?string
{
    if ($target === null || $target === '' || strlen($target) > VIRTUSPHERE_LOGIN_RETURN_MAX_LENGTH) {
        return null;
    }

    [$page, $query] = array_pad(explode('?', $target, 2), 2, '');
    // RFC 3986 query characters plus brackets (PHP array keys); a % only as a
    // complete escape. No space, quote, angle bracket, backslash, # or byte
    // outside printable ASCII, so nothing can end the header or the attribute.
    if (!in_array($page, login_return_pages(), true)
        || preg_match('/^(?:[A-Za-z0-9\-._~!$&\'()*+,;=:@\/?\[\]]|%[0-9A-Fa-f]{2})*$/D', $query) !== 1
    ) {
        return null;
    }

    return $query === '' ? $page : $page . '?' . $query;
}

/**
 * The page and query of the current request as a target; null for the default
 * page, which needs none, and for anything that is not a portal page.
 *
 * @param array<string, mixed> $server
 */
function login_return_target_from_request(array $server): ?string
{
    $page = basename(str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? '')));
    $query = (string) ($server['QUERY_STRING'] ?? '');
    $target = login_return_validate($query === '' ? $page : $page . '?' . $query);

    return $target === VIRTUSPHERE_LOGIN_DEFAULT_PAGE ? null : $target;
}

function login_return_login_url(?string $target, bool $sessionEnded = false, bool $inputDiscarded = false): string
{
    $query = array_filter([
        VIRTUSPHERE_LOGIN_REASON_FIELD => $sessionEnded ? VIRTUSPHERE_LOGIN_REASON_SESSION_EXPIRED : null,
        VIRTUSPHERE_LOGIN_DISCARDED_FIELD => $inputDiscarded ? '1' : null,
        VIRTUSPHERE_LOGIN_RETURN_FIELD => login_return_validate($target),
    ], static fn (?string $value): bool => $value !== null);

    return VIRTUSPHERE_LOGIN_PAGE . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
}

/** @param array<string, mixed> $server */
function login_return_login_url_for_request(array $server, bool $sessionEnded): string
{
    $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

    return login_return_login_url(login_return_target_from_request($server), $sessionEnded, !in_array($method, ['GET', 'HEAD'], true));
}

/** A pending password change always wins; the target is dropped, not deferred. */
function login_return_after_sign_in(?string $target, bool $mustChangePassword): string
{
    if ($mustChangePassword) {
        return VIRTUSPHERE_LOGIN_PASSWORD_CHANGE_PAGE;
    }

    return login_return_validate($target) ?? VIRTUSPHERE_LOGIN_DEFAULT_PAGE;
}
