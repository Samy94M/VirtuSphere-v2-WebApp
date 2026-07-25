<?php

declare(strict_types=1);

require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/esxi_datastore_health.php';
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
 * `free` goes through esxi_datastore_usable_free_bytes(), the single reader of
 * the two ways a number can be missing: the cache never had one, or the
 * datastore is in maintenance and its size is not space anybody can deploy onto.
 * Both render as "unknown", because both answer the operator identically.
 *
 * @param array<int, int> $credentialIds
 * @return array<int, array<string, array{free:?int, capacity:?int, unusable:bool}>>
 */
function deploy_datastore_capacity_map(mysqli $db, array $credentialIds): array
{
    $map = [];
    foreach (repo_esxi_inventory_datastore_rows($db, $credentialIds) as $row) {
        $map[(int) $row['credential_id']][esxi_inventory_name_key((string) $row['name'])] = [
            'free' => esxi_datastore_usable_free_bytes(
                $row['free_bytes'] !== null ? (int) $row['free_bytes'] : null,
                $row['meta_json'] ?? null
            ),
            'capacity' => $row['capacity_bytes'] !== null ? (int) $row['capacity_bytes'] : null,
            'unusable' => esxi_datastore_is_unusable($row['meta_json'] ?? null),
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
            // Carries its `:pct` placeholder on purpose: the percentage is only
            // known once the JS has recomputed the row. Substituted there, but
            // the sentence itself still comes from the catalog, never from a
            // German/English literal in the script (portal i18n rule).
            'usage_aria' => __t('deploy.storage_usage_aria'),
        ],
    ];
}

/**
 * Verdict for one datastore row. `unknown` whenever the chosen credential has no
 * usable number: an absent datastore, a never-pulled credential, a NULL free
 * value or a datastore in maintenance proves nothing, and this display must
 * never turn into a gate. The caller resolves the last two through
 * esxi_datastore_usable_free_bytes() before it gets here.
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
 * $live only says whether deploy.js keeps the table up to date; it no longer
 * decides whether the numbers are rendered at all. Both modes now render the
 * free space and the verdict of whatever credential is selected at render time,
 * because the previous split meant the schedule preview answered the question
 * and the immediate-job path, which is the more common one, left the same
 * columns empty for anyone without JavaScript. An empty $capacity is the
 * honest starting state (no credential chosen yet), not a mode.
 *
 * @param array<string, array{name:string, bytes:int, vm_count:int, per_vm:array<int,int>}> $rows
 * @param array<string, array{free:?int, capacity:?int}> $capacity what the chosen credential reports, [] when none is
 * @param bool $live whether deploy.js re-renders the cells on every change
 */
function deploy_render_storage_table(array $rows, array $capacity, bool $live = false): void
{
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
            $free = $known ? ($capacity[$key]['free'] ?? null) : null;
            $pct = $known ? deploy_storage_projected_pct($row['bytes'], $free, $capacity[$key]['capacity'] ?? null) : null;
            // Without a chosen credential nothing has been compared, so the cell
            // stays the empty-value placeholder rather than claiming "unknown",
            // which is a verdict about a comparison that did happen.
            $compared = $known && $capacity !== [];
            $pctLabel = $pct !== null ? __t('deploy.storage_usage_aria', ['pct' => (string) $pct]) : '';
            ?>
            <tr <?php echo $live ? 'data-storage-row="' . h($key) . '"' : ''; ?>>
                <td><?php echo $known ? h($row['name']) : '<span class="muted">' . h(__t('deploy.storage_no_datastore')) . '</span>'; ?></td>
                <td <?php echo $live ? 'data-storage-vms' : ''; ?>><?php echo h((string) $row['vm_count']); ?></td>
                <td class="nowrap" <?php echo $live ? 'data-storage-required' : ''; ?>><?php echo h(virtusphere_human_bytes($row['bytes'])); ?></td>
                <?php // The bar carries its warning in colour and width alone, so without a
                      // name it is invisible to a screen reader and to anyone who cannot
                      // separate the amber from the red. role="img" plus the percentage makes
                      // it one named node; deploy.js sets the same label when it recomputes. ?>
                <td class="nowrap">
                    <span <?php echo $live ? 'data-storage-free-text' : ''; ?>><?php echo $free !== null ? h(virtusphere_human_bytes($free)) : '&mdash;'; ?></span>
                    <span class="capacity-bar" role="img" <?php echo $live ? 'data-storage-bar' : ''; ?> <?php echo $pct !== null ? 'aria-label="' . h($pctLabel) . '" title="' . h($pctLabel) . '"' : 'hidden'; ?>><span class="capacity-fill" <?php echo $pct !== null ? 'data-capacity-pct="' . h((string) $pct) . '"' : ''; ?>></span></span>
                </td>
                <td <?php echo $live ? 'data-storage-verdict' : ''; ?>><?php echo $compared ? deploy_storage_verdict_badge(deploy_storage_state($row['bytes'], $free)) : '&mdash;'; ?></td>
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
