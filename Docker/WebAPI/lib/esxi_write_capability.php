<?php

declare(strict_types=1);

require_once __DIR__ . '/esxi_capabilities.php';
require_once __DIR__ . '/esxi_create_compatibility.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/system_status_urls.php';
require_once __DIR__ . '/repo/helpers.php';

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

/**
 * Name and guest OS of the VMs a create verdict looks at, in id order. Only
 * VMs of this mission count; an id that vanished is someone else's finding.
 *
 * @param list<int> $vmIds
 * @return list<array{id:int, vm_name:string, vm_guest_id:string}>
 */
function repo_deploy_create_guest_rows(mysqli $db, int $missionId, array $vmIds): array
{
    $vmIds = array_values(array_unique(array_filter(array_map('intval', $vmIds), static fn (int $id): bool => $id > 0)));
    if ($missionId <= 0 || $vmIds === []) {
        return [];
    }
    $placeholders = implode(', ', array_fill(0, count($vmIds), '?'));
    $stmt = $db->prepare('SELECT id, vm_name, vm_guest_id FROM deploy_vms WHERE mission_id = ? AND id IN (' . $placeholders . ') ORDER BY id');
    $params = array_merge([$missionId], $vmIds);
    $stmt->bind_param(str_repeat('i', count($params)), ...$params);
    $stmt->execute();

    return array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'vm_name' => (string) $row['vm_name'],
        'vm_guest_id' => (string) ($row['vm_guest_id'] ?? ''),
    ], repo_fetch_all($stmt->get_result()));
}

/**
 * The create verdict for a credential and a VM scope, read from the stored
 * capability facts. One owner for the deploy page, every enqueue path and the
 * worker, so all three refuse the same jobs.
 *
 * @param list<int> $vmIds
 * @return array<string,mixed> esxi_create_compatibility()
 */
function deploy_esxi_create_compatibility(mysqli $db, int $credentialId, string $mode, int $missionId, array $vmIds): array
{
    if (!esxi_create_mode_applies($mode)) {
        return esxi_create_compatibility(null, 0, $mode, []);
    }

    return esxi_create_compatibility(
        repo_esxi_inventory_state($db, $credentialId),
        esxi_inventory_interval_hours($db),
        $mode,
        repo_deploy_create_guest_rows($db, $missionId, $vmIds)
    );
}

/**
 * Refuses a create-capable job the host cannot carry out (ADR-0023 amendment
 * 5): a current release below the lowest creatable hardware version, or a VM
 * whose guest OS that release does not know. Stale facts never refuse.
 *
 * @param list<int> $vmIds
 * @return array<string,mixed> the verdict, so the worker can use its version
 */
function deploy_assert_esxi_create_compatibility(mysqli $db, int $credentialId, string $mode, int $missionId, array $vmIds): array
{
    $verdict = deploy_esxi_create_compatibility($db, $credentialId, $mode, $missionId, $vmIds);
    if ($verdict['verdict'] === 'block') {
        throw new ValidationException([], esxi_create_compatibility_message($verdict), [
            'url' => system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI),
            'label_key' => 'deploy.identity_refresh_link',
            'permission' => '',
        ]);
    }

    return $verdict;
}
