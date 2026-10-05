<?php

declare(strict_types=1);

require_once __DIR__ . '/esxi_capabilities.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/system_status_urls.php';

/**
 * DF-E2: a current free-licence fact makes the ESXi API read-only, so every
 * mode whose playbooks write is refused before a job row exists, and the
 * worker refuses again before it marks a VM (a scheduled job can start days
 * after it was queued). Stale or missing facts never refuse: ESXi stays the
 * authority (ADR-0023). The link points at the ESXi card, whose refresh lifts
 * the block after a licence change; the system status is open to every user.
 */
function deploy_assert_esxi_write_capability(mysqli $db, int $credentialId, string $mode): void
{
    $state = repo_esxi_inventory_state($db, $credentialId);
    $verdict = esxi_write_preflight($state, esxi_inventory_interval_hours($db), $mode);
    if ($verdict['verdict'] === 'block') {
        throw new ValidationException([], validator_text(
            'layout.err_esxi_write_license',
            'ESXi write operations are blocked: this host reports a current free licence whose API is read-only.'
        ), [
            'url' => system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI),
            'label_key' => 'deploy.identity_refresh_link',
            'permission' => '',
        ]);
    }
}
