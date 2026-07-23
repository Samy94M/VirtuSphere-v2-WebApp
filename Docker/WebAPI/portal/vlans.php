<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/repo/catalog.php';
require_once __DIR__ . '/../lib/repo/esxi_inventory.php';
require_once __DIR__ . '/../lib/repo/log.php';

// VLAN catalog is ESXi-owned (ADR-0023): read-only, synced from the ESXi
// portgroups. No create/edit; an admin cleanup-delete is kept for retired rows
// (the next successful sync re-creates a portgroup that still exists).
$user = portal_require_user($connection);
$canWrite = can('catalog.write', $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_guard_post($connection, $user);
    if (!$canWrite) {
        portal_forbid($connection, $user, 'catalog.write');
    }

    try {
        if (request_string($_POST, 'action') === 'delete') {
            deleteVLAN(request_int($_POST, 'vlan_id'), $connection);
            audit($connection, VIRTUSPHERE_LOG_CATEGORY_VLANS, 'deleted retired vlan id ' . request_int($_POST, 'vlan_id'), (int) $user['id']);
            flash_set('success', __t('vlans.flash_deleted'));
        }
    } catch (Throwable $exception) {
        flash_set('error', portal_error_message($exception));
    }
    redirect_to('vlans.php');
}

$statusFilter = request_string($_GET, 'status', 'active');
$statusFilter = in_array($statusFilter, VIRTUSPHERE_CATALOG_FILTERS, true) ? $statusFilter : 'active';

$rows = array_values(array_filter(getVLAN($connection), static function (array $row) use ($statusFilter): bool {
    $retired = $row['retired_at'] !== null;
    return match ($statusFilter) {
        'retired' => $retired,
        'all' => true,
        default => !$retired,
    };
}));
$presenceReport = repo_esxi_vlan_presence_report($connection);
// Denominator for "on X of Y hosts": only credentials that ever pulled
// successfully can prove presence or absence (ADR-0023).
$eligibleHosts = $presenceReport['eligible'];
$hostTotal = count($eligibleHosts);

layout_header(__t('vlans.title'), $user, 'vlans', 'system-status');
?>
<div class="stack">
    <section class="panel">
        <p class="muted"><?php echo h(__t('vlans.catalog_hint')); ?></p>
        <div class="actions">
            <?php
            // Static labels (not __t('vlans.status_'.$v)) so the lang catalog
            // test sees the keys; tokens still come from the shared constant.
            $filterLabels = [
                'active' => __t('vlans.status_active'),
                'retired' => __t('vlans.status_retired'),
                'all' => __t('vlans.status_all'),
            ];
            foreach (VIRTUSPHERE_CATALOG_FILTERS as $filterValue) { ?>
                <a class="button <?php echo $statusFilter === $filterValue ? '' : 'button-secondary'; ?>" href="vlans.php?status=<?php echo h($filterValue); ?>"><?php echo h($filterLabels[$filterValue]); ?></a>
            <?php } ?>
        </div>
        <div class="table-wrap" tabindex="0"><table>
            <thead><tr>
                <th><?php echo h(__t('common.name')); ?></th>
                <th><?php echo h(__t('common.status')); ?></th>
                <th><?php echo h(__t('vlans.th_hosts')); ?></th>
                <th><?php echo h(__t('vlans.th_vlan_id')); ?></th>
                <?php if ($canWrite) { ?><th><?php echo h(__t('common.actions')); ?></th><?php } ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row) {
                $retired = $row['retired_at'] !== null;
                $nameKey = esxi_inventory_name_key((string) $row['vlan_name']);
                $hosts = $presenceReport['by_name'][$nameKey] ?? [];
                ?>
                <tr>
                    <td><?php echo h((string) ($row['vlan_name'] ?? '')); ?></td>
                    <td>
                        <?php echo portal_badge($retired ? 'neutral' : 'success', $retired ? __t('vlans.status_retired') : __t('vlans.status_active')); ?>
                        <?php if ($retired) { ?><span class="muted nowrap"><?php echo h(portal_format_timestamp((string) $row['retired_at'])); ?></span><?php } ?>
                    </td>
                    <td>
                        <?php
                        // Full presence stays quiet (muted); partial presence is
                        // the operationally interesting drift signal and names
                        // the MISSING hosts (the shorter, relevant list).
                        $presentOnEligible = array_values(array_intersect($eligibleHosts, $hosts));
                        $missingHosts = array_values(array_diff($eligibleHosts, $hosts));
                        if ($hosts === []) {
                            echo '<span class="muted">' . h(__t('vlans.no_hosts')) . '</span>';
                        } elseif ($hostTotal === 0) {
                            echo h(implode(', ', $hosts));
                        } elseif ($missingHosts === []) {
                            echo '<span class="muted">' . h(__t('vlans.hosts_all', ['total' => $hostTotal])) . '</span>';
                        } else {
                            echo portal_badge('warning', __t('vlans.hosts_partial', ['count' => count($presentOnEligible), 'total' => $hostTotal])) . ' ';
                            echo '<span class="muted">' . h(__t('vlans.hosts_missing', ['hosts' => implode(', ', $missingHosts)])) . '</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        // VLAN-ID consistency: same name must mean the same
                        // network everywhere. Different integer IDs across
                        // hosts are a probable misconfiguration (danger);
                        // trunks/ranges never enter the comparison.
                        $idMap = $presenceReport['ids'][$nameKey] ?? [];
                        $trunkHosts = $presenceReport['trunks'][$nameKey] ?? [];
                        if ($idMap === [] && $trunkHosts === []) {
                            echo '<span class="muted">&mdash;</span>';
                        } elseif (count($idMap) > 1) {
                            ksort($idMap);
                            $parts = [];
                            foreach ($idMap as $vlanId => $idHosts) {
                                $parts[] = __t('vlans.vlan_id_one_hosts', ['id' => $vlanId, 'hosts' => implode(', ', $idHosts)]);
                            }
                            echo portal_badge('danger', __t('vlans.vlan_id_mismatch')) . ' ';
                            echo '<span class="muted">' . h(implode('; ', $parts)) . '</span>';
                        } else {
                            $bits = [];
                            if ($idMap !== []) {
                                $bits[] = __t('vlans.vlan_id_one', ['id' => array_key_first($idMap)]);
                            }
                            if ($trunkHosts !== []) {
                                $bits[] = __t('vlans.vlan_id_trunk', ['hosts' => implode(', ', $trunkHosts)]);
                            }
                            echo '<span class="muted">' . h(implode('; ', $bits)) . '</span>';
                        }
                        ?>
                    </td>
                    <?php if ($canWrite) { ?>
                        <td class="actions">
                            <?php if ($retired) { ?>
                                <form class="inline-form" method="post" action="vlans.php">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="vlan_id" value="<?php echo h((string) $row['id']); ?>">
                                    <button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('vlans.confirm_delete', ['name' => (string) ($row['vlan_name'] ?? '')])); ?>"><?php echo h(__t('common.delete')); ?></button>
                                </form>
                            <?php } else { ?>
                                <span class="muted">&mdash;</span>
                            <?php } ?>
                        </td>
                    <?php } ?>
                </tr>
            <?php } ?>
            <?php if ($rows === []) { ?><tr><td colspan="<?php echo $canWrite ? 5 : 4; ?>"><?php echo h(__t('vlans.empty_catalog')); ?></td></tr><?php } ?>
            </tbody>
        </table></div>
    </section>
</div>
<?php layout_footer(); ?>
