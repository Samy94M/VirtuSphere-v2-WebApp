<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/repo/helpers.php';
require_once __DIR__ . '/../lib/repo/missions.php';
require_once __DIR__ . '/../lib/repo/heartbeats.php';
require_once __DIR__ . '/../lib/integration_health.php';
require_once __DIR__ . '/../lib/system_status.php';

/** @var mysqli $connection Provided by bootstrap.php. */

$user = portal_require_user($connection);

$missionCount = (int) repo_scalar($connection, 'SELECT COUNT(*) FROM deploy_missions WHERE LEFT(mission_name, 1) <> ?', 's', [VIRTUSPHERE_TEMPLATE_PREFIX]);
$templateCount = (int) repo_scalar($connection, 'SELECT COUNT(*) FROM deploy_missions WHERE LEFT(mission_name, 1) = ?', 's', [VIRTUSPHERE_TEMPLATE_PREFIX]);
$vmCount = (int) repo_scalar($connection, 'SELECT COUNT(*) FROM deploy_vms');
$mecmPending = (int) repo_scalar($connection, 'SELECT COUNT(*) FROM deploy_vms WHERE updated = 1 OR mecm_sync_state = ?', 's', [VIRTUSPHERE_MECM_SYNC_PENDING]);
$healthSnapshot = integration_health_snapshot($connection);
// The MECM tile shows two separate signals and never a common worst-of: a
// critical MECM site must not present the data flow as failed, and a failed sync
// must not claim MECM itself is critical (ADR-0018).
$integrationState = (string) $healthSnapshot['mecm_sync']['state'];
$mecmSiteState = (string) $healthSnapshot['mecm_site']['state'];
// Null when no ESXi credential exists: a tile that is permanently grey for a
// feature nobody configured is noise, so it is not rendered at all.
$hypervisorWorst = $healthSnapshot['esxi']['state'];
// Mission deploys currently queued or running. Shown only to operators who can
// reach the deploy page, and only when at least one is in flight: a permanent
// "0" here would be the same noise the hypervisor tile above avoids.
$activeDeploys = can('deploy.run', $user)
    ? (int) repo_scalar($connection, 'SELECT COUNT(*) FROM deploy_jobs WHERE mission_id IS NOT NULL AND status IN (?, ?) AND cancelled_at IS NULL', 'ss', [VIRTUSPHERE_DEPLOY_STATUS_QUEUED, VIRTUSPHERE_DEPLOY_STATUS_RUNNING])
    : 0;

$stmt = $connection->prepare('SELECT m.*, (SELECT COUNT(*) FROM deploy_vms v WHERE v.mission_id = m.id) AS vm_count FROM deploy_missions m WHERE LEFT(m.mission_name, 1) <> ? ORDER BY m.updated_at DESC LIMIT 8');
$prefix = VIRTUSPHERE_TEMPLATE_PREFIX;
$stmt->bind_param('s', $prefix);
$stmt->execute();
$recentMissions = repo_fetch_all($stmt->get_result());

layout_header(__t('dashboard.title'), $user, 'dashboard');
?>
<div class="stack">
    <section class="grid" aria-label="<?php echo h(__t('dashboard.key_metrics')); ?>">
        <a class="card kpi" href="missions.php?type=missions"><span class="muted"><?php echo h(__t('dashboard.kpi_missions')); ?></span><span class="value"><?php echo h($missionCount); ?></span></a>
        <a class="card kpi" href="missions.php?type=templates"><span class="muted"><?php echo h(__t('dashboard.kpi_templates')); ?></span><span class="value"><?php echo h($templateCount); ?></span></a>
        <article class="card kpi"><span class="muted"><?php echo h(__t('dashboard.kpi_vms')); ?></span><span class="value"><?php echo h($vmCount); ?></span></article>
        <article class="card kpi"><span class="muted"><?php echo h(__t('dashboard.kpi_mecm_pending')); ?></span><span class="value<?php echo $mecmPending > 0 ? ' value-warning' : ''; ?>"><?php echo h($mecmPending); ?></span></article>
        <?php if ($activeDeploys > 0) { ?>
            <a class="card kpi" href="deploy.php"><span class="muted"><?php echo h(__t('dashboard.kpi_active_deploys')); ?></span><span class="value value-info"><?php echo h($activeDeploys); ?></span></a>
        <?php } ?>
        <a class="card kpi" href="<?php echo h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM)); ?>"><span class="muted"><?php echo h(__t('dashboard.kpi_system_status')); ?></span><span class="value value-signals"><?php echo portal_signal_row(__t('dashboard.kpi_integration'), $integrationState); ?><?php echo portal_signal_row(__t('dashboard.kpi_mecm_site'), $mecmSiteState); ?></span></a>
        <?php if ($hypervisorWorst !== null) { ?>
            <a class="card kpi" href="<?php echo h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI)); ?>"><span class="muted"><?php echo h(__t('dashboard.kpi_hypervisor')); ?></span><span class="value"><?php echo esxi_state_badge((string) $hypervisorWorst); ?></span></a>
        <?php } ?>
    </section>

    <section class="panel">
        <div class="actions">
            <h2><?php echo h(__t('dashboard.recent_missions')); ?></h2>
            <a class="button button-secondary" href="missions.php?type=missions"><?php echo h(__t('dashboard.all_missions')); ?></a>
            <?php if (can('missions.write', $user)) { ?><a class="button" href="missions.php?type=missions"><?php echo h(__t('dashboard.create_mission')); ?></a><?php } ?>
        </div>
        <div class="table-wrap" tabindex="0">
            <table>
                <thead><tr><th><?php echo h(__t('common.name')); ?></th><th><?php echo h(__t('common.status')); ?></th><th><?php echo h(__t('common.vms')); ?></th><th><?php echo h(__t('common.updated')); ?></th><th><?php echo h(__t('common.actions')); ?></th></tr></thead>
                <tbody>
                <?php foreach ($recentMissions as $mission) { ?>
                    <tr>
                        <td><?php echo h($mission['mission_name'] ?? ''); ?></td>
                        <td>
                            <?php $missionStatus = trim((string) ($mission['mission_status'] ?? '')); ?>
                            <?php if ($missionStatus !== '') { ?>
                                <?php echo portal_badge($missionStatus === VIRTUSPHERE_MISSION_STATUS_DEFAULT ? 'success' : 'neutral', $missionStatus); ?>
                            <?php } else { ?>
                                —
                            <?php } ?>
                        </td>
                        <td><?php echo h((string) ($mission['vm_count'] ?? 0)); ?></td>
                        <td><?php echo h(portal_format_timestamp($mission['updated_at'] ?? '')); ?></td>
                        <td class="actions">
                            <a class="button button-secondary" href="mission_details.php?id=<?php echo h((string) $mission['id']); ?>"><?php echo h(__t('common.details')); ?></a>
                            <a class="button button-secondary" href="vms.php?mission_id=<?php echo h((string) $mission['id']); ?>"><?php echo h(__t('common.vms')); ?></a>
                        </td>
                    </tr>
                <?php } ?>
                <?php if ($recentMissions === []) { ?>
                    <tr><td colspan="5"><?php echo h(__t('dashboard.empty')); ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php layout_footer(); ?>
