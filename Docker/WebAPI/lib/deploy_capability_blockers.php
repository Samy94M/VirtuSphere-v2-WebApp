<?php

declare(strict_types=1);

/**
 * The deploy page's ESXi capability blockers: the read-only licence (DF-E2) and
 * the create verdict from the reported release (ADR-0023 amendment 7). Both are
 * the same verdicts the enqueue and the worker apply, over the same eligible
 * scope; the page only renders them. Split from deploy_blockers.php (ADR-0006
 * budget). deploy_action_blocker() comes from deploy_blockers.php, the only
 * caller.
 */

require_once __DIR__ . '/esxi_capabilities.php';
require_once __DIR__ . '/esxi_create_compatibility.php';
require_once __DIR__ . '/esxi_write_capability.php';
require_once __DIR__ . '/system_status_urls.php';
require_once __DIR__ . '/vm_urls.php';

/**
 * @param list<int> $eligibleVmIds
 * @return list<array<string,mixed>>
 */
function deploy_esxi_capability_blockers(mysqli $db, int $credentialId, string $mode, int $missionId, array $eligibleVmIds): array
{
    $blockers = [];
    $esxiWrite = esxi_write_preflight(repo_esxi_inventory_state($db, $credentialId), esxi_inventory_interval_hours($db), $mode);
    if ($esxiWrite['verdict'] === 'block') {
        $blockers[] = deploy_action_blocker(
            'esxi_write_license',
            __t('layout.err_esxi_write_license'),
            system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI),
            __t('deploy.identity_refresh_link')
        );
    }
    if ($missionId <= 0 || $eligibleVmIds === []) {
        return $blockers;
    }

    // One blocker per VM, so each links to the editor where its guest OS changes.
    $esxiCreate = deploy_esxi_create_compatibility($db, $credentialId, $mode, $missionId, $eligibleVmIds);
    if ($esxiCreate['reason'] === VIRTUSPHERE_ESXI_CREATE_BLOCK_HOST) {
        $blockers[] = deploy_action_blocker(
            VIRTUSPHERE_ESXI_CREATE_BLOCK_HOST,
            esxi_create_compatibility_message($esxiCreate),
            system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI),
            __t('deploy.identity_refresh_link')
        );
    }
    foreach ($esxiCreate['unsupported_guests'] as $guest) {
        $blockers[] = deploy_action_blocker(
            VIRTUSPHERE_ESXI_CREATE_BLOCK_GUEST,
            esxi_create_guest_finding_text($guest, (string) $esxiCreate['release']),
            vm_edit_url($missionId, (int) $guest['vm_id']),
            __t('deploy.identity_vm_link'),
            'vms.write'
        );
    }

    return $blockers;
}
