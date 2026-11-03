<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';

/**
 * SSoT for fixed and per-credential links into the System status page.
 *
 * @param array<string,int|string> $query
 */
function system_status_url(string $anchor, array $query = []): string
{
    $fixedAnchor = in_array($anchor, [
        VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEPLOY_SERVICE,
        VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DIRECTORY,
        VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM,
        VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ANSIBLE,
        VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI,
        VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEVIATIONS,
        VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_INTERNAL,
        VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_REASSIGN,
    ], true);
    $dynamicCredential = preg_match('/\Acredential-([1-9][0-9]*)\z/D', $anchor, $match) === 1
        && filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    if (!$fixedAnchor && !$dynamicCredential) {
        throw new InvalidArgumentException('Invalid System status anchor.');
    }
    $url = 'system_status.php';
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    return $url . '#' . $anchor;
}
