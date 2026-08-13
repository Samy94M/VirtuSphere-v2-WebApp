<?php

declare(strict_types=1);

const VIRTUSPHERE_USERS_VIEW_ACCOUNTS = 'accounts';
const VIRTUSPHERE_USERS_VIEW_DIRECTORY = 'directory';
const VIRTUSPHERE_USERS_VIEWS = [
    VIRTUSPHERE_USERS_VIEW_ACCOUNTS,
    VIRTUSPHERE_USERS_VIEW_DIRECTORY,
];
const VIRTUSPHERE_USERS_ANCHORS = [
    'directory-config',
    'directory-controllers',
    'directory-search',
];

function users_view_normalize(string $view): string
{
    return in_array($view, VIRTUSPHERE_USERS_VIEWS, true)
        ? $view
        : VIRTUSPHERE_USERS_VIEW_ACCOUNTS;
}

function users_url(string $view = VIRTUSPHERE_USERS_VIEW_ACCOUNTS, string $anchor = ''): string
{
    // csp-allow: interpolated-sql (URL query builder, no database operation)
    $view = users_view_normalize($view);
    $url = 'users.php?' . http_build_query(['view' => $view]);
    if ($anchor !== '') {
        if (!in_array($anchor, VIRTUSPHERE_USERS_ANCHORS, true)) {
            throw new InvalidArgumentException('Unknown users page anchor.');
        }
        $url .= '#' . $anchor;
    }

    return $url;
}
