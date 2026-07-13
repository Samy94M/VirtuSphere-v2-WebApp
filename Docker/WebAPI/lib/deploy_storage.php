<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/format.php';
require_once __DIR__ . '/repo/esxi_inventory.php';

/**
 * Storage requirement of a deploy, per target datastore (ADR-0023 amendment).
 *
 * The requirement comes from ansible_storage_by_datastore(), i.e. from the very
 * functions that build the serverlist, so the number the operator sees and the
 * number the create playbook provisions cannot drift apart. It is compared
 * against the free space the chosen ESXi credential reported at its last pull.
 *
 * This is a display, never a gate: the cache is a mirror that can be stale or
 * absent, and a deploy is never blocked by it. Every unusable number (no
 * credential chosen, datastore absent from that host, NULL free bytes) renders
 * as "unknown", not as a refusal.
 */

/**
 * The VMs a deploy would actually touch: the checked selection, or the whole
 * mission when nothing is checked. Mirrors repo_deploy_group_vm_list().
 *
 * @param array<int, array<string, mixed>> $missionVms
 * @param array<int, mixed> $vmIds
 * @return array<int, array<string, mixed>>
 */
function deploy_selected_vms(array $missionVms, array $vmIds): array
{
    $wanted = [];
    foreach ($vmIds as $vmId) {
        $wanted[(int) $vmId] = true;
    }
    if ($wanted === []) {
        return $missionVms;
    }

    return array_values(array_filter($missionVms, static fn (array $vm): bool => isset($wanted[(int) ($vm['id'] ?? 0)])));
}

/**
 * Free and total bytes of the cached datastores of several ESXi credentials,
 * keyed by credential id and then by esxi_inventory_name_key() so a stored
 * value that differs in case still finds its row. One query for the whole
 * picker; both numbers are as old as the last successful pull.
 *
 * @param array<int, int> $credentialIds
 * @return array<int, array<string, array{free:?int, capacity:?int}>>
 */
function deploy_datastore_capacity_map(mysqli $db, array $credentialIds): array
{
    $map = [];
    foreach (repo_esxi_inventory_datastore_rows($db, $credentialIds) as $row) {
        $map[(int) $row['credential_id']][esxi_inventory_name_key((string) $row['name'])] = [
            'free' => $row['free_bytes'] !== null ? (int) $row['free_bytes'] : null,
            'capacity' => $row['capacity_bytes'] !== null ? (int) $row['capacity_bytes'] : null,
        ];
    }

    return $map;
}

/**
 * Single-credential convenience over deploy_datastore_capacity_map(), for the
 * schedule preview where the credential is already chosen.
 *
 * @return array<string, array{free:?int, capacity:?int}>
 */
function deploy_datastore_capacity(mysqli $db, int $esxiCredentialId): array
{
    if ($esxiCredentialId <= 0) {
        return [];
    }

    return deploy_datastore_capacity_map($db, [$esxiCredentialId])[$esxiCredentialId] ?? [];
}

/**
 * Everything deploy.js needs to keep the queue-form table live: the provisioned
 * bytes of every VM (keyed by its target datastore, driven by the VM
 * checkboxes) and the free space of every ESXi credential (driven by the
 * credential select). Null when there is nothing to show. All labels are
 * pre-localized; the JS only picks by id.
 *
 * @param array<string, array{name:string, bytes:int, vm_count:int, per_vm:array<int,int>}> $storageRows
 * @param array<int, array<string, mixed>> $esxiCredentials
 */
function deploy_storage_island(mysqli $db, array $storageRows, array $esxiCredentials): ?array
{
    if ($storageRows === [] || $esxiCredentials === []) {
        return null;
    }

    $perVm = [];
    foreach ($storageRows as $key => $row) {
        foreach ($row['per_vm'] as $vmId => $bytes) {
            $perVm[(string) $vmId] = ['key' => $key, 'bytes' => $bytes];
        }
    }
    // Every credential id keeps its entry even without cached rows, so the JS
    // "credential chosen but nothing known" path renders `unknown`, not the
    // no-credential placeholder.
    $free = [];
    foreach ($esxiCredentials as $credential) {
        $free[(string) $credential['id']] = [];
    }
    foreach (deploy_datastore_capacity_map($db, array_map(static fn (array $c): int => (int) $c['id'], $esxiCredentials)) as $credentialId => $capacity) {
        $free[(string) $credentialId] = $capacity;
    }

    return [
        'perVm' => $perVm,
        'free' => $free,
        'labels' => [
            'ok' => __t('deploy.storage_ok'),
            'insufficient' => __t('deploy.storage_insufficient'),
            'unknown' => __t('deploy.storage_unknown'),
        ],
    ];
}

/**
 * Verdict for one datastore row. `unknown` whenever the chosen credential has no
 * usable number: an absent datastore, a never-pulled credential or a NULL free
 * value proves nothing, and this display must never turn into a gate.
 */
function deploy_storage_state(int $requiredBytes, ?int $freeBytes): string
{
    if ($freeBytes === null) {
        return 'unknown';
    }

    return $freeBytes >= $requiredBytes ? 'ok' : 'insufficient';
}

/** Percent of a datastore in use after this deploy; null when the size is unknown. */
function deploy_storage_projected_pct(int $requiredBytes, ?int $freeBytes, ?int $capacityBytes): ?int
{
    if ($freeBytes === null || $capacityBytes === null || $capacityBytes <= 0) {
        return null;
    }

    return (int) max(0, min(100, round(($capacityBytes - $freeBytes + $requiredBytes) / $capacityBytes * 100)));
}

/** Badge for one verdict; the single mapping both tables and deploy.js agree on. */
function deploy_storage_verdict_badge(string $state): string
{
    $badge = ['ok' => 'success', 'insufficient' => 'warning', 'unknown' => 'neutral'][$state];
    $label = ['ok' => 'deploy.storage_ok', 'insufficient' => 'deploy.storage_insufficient', 'unknown' => 'deploy.storage_unknown'][$state];

    return portal_badge($badge, __t($label));
}

/**
 * The requirement table, shared by the queue form and the schedule preview.
 *
 * The preview knows the chosen credential and the VM selection, so it renders the
 * verdict server-side and stays authoritative without JavaScript. The queue form
 * renders the requirement (all VMs start checked) and leaves the free/verdict
 * cells to deploy.js, which refills them on every credential or VM change.
 *
 * @param array<string, array{name:string, bytes:int, vm_count:int, per_vm:array<int,int>}> $rows
 * @param array<string, array{free:?int, capacity:?int}>|null $capacity null = live mode, deploy.js fills it
 */
function deploy_render_storage_table(array $rows, ?array $capacity): void
{
    $live = $capacity === null;
    $totalBytes = 0;
    $totalVms = 0;
    foreach ($rows as $row) {
        $totalBytes += $row['bytes'];
        $totalVms += $row['vm_count'];
    }
    ?>
    <div class="table-wrap" tabindex="0"><table>
        <thead><tr>
            <th><?php echo h(__t('deploy.storage_th_datastore')); ?></th>
            <th><?php echo h(__t('deploy.storage_th_vms')); ?></th>
            <th><?php echo h(__t('deploy.storage_th_required')); ?></th>
            <th><?php echo h(__t('deploy.storage_th_free')); ?></th>
            <th><?php echo h(__t('deploy.storage_th_verdict')); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $key => $row) {
            // An empty key means no datastore is set at all (a template, or a
            // mission that never got one). There is nothing to compare against.
            $known = $key !== '';
            $free = $known && !$live ? ($capacity[$key]['free'] ?? null) : null;
            $pct = $known && !$live ? deploy_storage_projected_pct($row['bytes'], $free, $capacity[$key]['capacity'] ?? null) : null;
            ?>
            <tr <?php echo $live ? 'data-storage-row="' . h($key) . '"' : ''; ?>>
                <td><?php echo $known ? h($row['name']) : '<span class="muted">' . h(__t('deploy.storage_no_datastore')) . '</span>'; ?></td>
                <td <?php echo $live ? 'data-storage-vms' : ''; ?>><?php echo h((string) $row['vm_count']); ?></td>
                <td class="nowrap" <?php echo $live ? 'data-storage-required' : ''; ?>><?php echo h(virtusphere_human_bytes($row['bytes'])); ?></td>
                <?php if ($live) { ?>
                    <td class="nowrap"><span data-storage-free-text>&mdash;</span> <span class="capacity-bar" data-storage-bar hidden><span class="capacity-fill"></span></span></td>
                    <td data-storage-verdict>&mdash;</td>
                <?php } else { ?>
                    <td class="nowrap">
                        <?php echo $free !== null ? h(virtusphere_human_bytes($free)) : '&mdash;'; ?>
                        <?php if ($pct !== null) { ?>
                            <span class="capacity-bar"><span class="capacity-fill" data-capacity-pct="<?php echo h((string) $pct); ?>"></span></span>
                        <?php } ?>
                    </td>
                    <td><?php echo $known ? deploy_storage_verdict_badge(deploy_storage_state($row['bytes'], $free)) : '&mdash;'; ?></td>
                <?php } ?>
            </tr>
        <?php } ?>
        <?php if (count($rows) > 1) { ?>
            <tr <?php echo $live ? 'data-storage-total' : ''; ?>>
                <td><strong><?php echo h(__t('deploy.storage_total')); ?></strong></td>
                <td <?php echo $live ? 'data-storage-vms' : ''; ?>><?php echo h((string) $totalVms); ?></td>
                <td class="nowrap" <?php echo $live ? 'data-storage-required' : ''; ?>><strong><?php echo h(virtusphere_human_bytes($totalBytes)); ?></strong></td>
                <td>&mdash;</td>
                <td>&mdash;</td>
            </tr>
        <?php } ?>
        </tbody>
    </table></div>
    <?php
}
