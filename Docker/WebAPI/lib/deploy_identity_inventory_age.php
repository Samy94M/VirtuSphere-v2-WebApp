<?php

declare(strict_types=1);

require_once __DIR__ . '/layout_presenters.php';
require_once __DIR__ . '/layout_response.php';
require_once __DIR__ . '/status_evidence.php';

/** The cache continues to block safely, with the exact observation's age. */
function deploy_identity_inventory_age_message(?string $fetchedAt, ?int $now = null): string
{
    $current = $now ?? virtusphere_request_now();
    $timestamp = virtusphere_evidence_timestamp($fetchedAt, $current);
    if ($timestamp === null) {
        return __t('deploy.identity_inventory_age_unknown');
    }
    return __t('deploy.identity_inventory_age', [
        'time' => portal_format_timestamp($fetchedAt),
        'age' => portal_format_duration(max(0, $current - $timestamp)),
    ]);
}
