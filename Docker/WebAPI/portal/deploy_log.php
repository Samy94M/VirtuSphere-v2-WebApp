<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/repo/deploy_jobs.php';

$user = portal_require_user($connection);
if (!can('deploy.run', $user)) {
    portal_forbid($connection, $user, 'deploy.run');
}

$jobId = request_int($_GET, 'id');
$job = $jobId > 0 ? repo_deploy_job($connection, $jobId) : null;
$format = request_string($_GET, 'format', 'html');

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    if ($job === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Deploy job not found.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $afterSeq = request_int($_GET, 'after_seq');
    $logs = repo_deploy_job_logs($connection, (int) $job['id'], $afterSeq, 500);
    foreach ($logs as &$logEntry) {
        $logEntry['created_at'] = portal_format_timestamp($logEntry['created_at'] ?? null);
    }
    unset($logEntry);
    echo json_encode([
        'ok' => true,
        'job' => [
            'id' => (int) $job['id'],
            'status' => (string) $job['status'],
            'badge' => deploy_job_status_badge_class((string) $job['status']),
            'updated_at' => portal_format_timestamp((string) $job['updated_at']),
            'terminal' => in_array((string) $job['status'], VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true),
        ],
        'logs' => $logs,
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($job === null) {
    flash_set('error', __t('portal.deploy_not_found'));
    redirect_to('deploy.php');
}

$logs = repo_deploy_job_logs($connection, (int) $job['id'], 0, 1000);
$lastSeq = 0;
foreach ($logs as $log) {
    $lastSeq = max($lastSeq, (int) $log['seq']);
}
$isTerminal = in_array((string) $job['status'], VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true);
// An empty log on an old finished job is almost certainly the retention prune,
// not a job that printed nothing. Saying so beats an unexplained empty table.
// The ' UTC' suffix is the house rule for DB timestamps: today PHP and MySQL
// both run on UTC, but a date.timezone in php.ini would silently shift this.
$logsPruned = $logs === []
    && $isTerminal
    && (int) strtotime((string) $job['updated_at'] . ' UTC') < time() - VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS * 86400;

layout_header(__t('deploy.log_title'), $user, 'deploy');
?>
<div class="stack" data-deploy-log data-job-id="<?php echo h((string) $job['id']); ?>" data-after-seq="<?php echo h((string) $lastSeq); ?>" data-terminal="<?php echo $isTerminal ? '1' : '0'; ?>">
    <section class="panel">
        <div class="actions">
            <a class="button button-secondary" href="deploy.php<?php echo (int) $job['mission_id'] > 0 ? '?mission_id=' . h((string) $job['mission_id']) : ''; ?>"><?php echo h(__t('common.back')); ?></a>
            <?php // A system job (ESXi inventory) has no mission; the link would have gone to id=0. ?>
            <?php if ((int) $job['mission_id'] > 0) { ?>
                <a class="button button-secondary" href="mission_details.php?id=<?php echo h((string) $job['mission_id']); ?>"><?php echo h(__t('common.mission')); ?></a>
            <?php } ?>
            <a class="button button-secondary" href="deploy_log.php?id=<?php echo h((string) $job['id']); ?>"><?php echo h(__t('common.refresh')); ?></a>
            <?php if (in_array((string) $job['status'], VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES, true)) { ?>
                <form class="inline-form" method="post" action="deploy.php?mission_id=<?php echo h((string) $job['mission_id']); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="job_id" value="<?php echo h((string) $job['id']); ?>">
                    <?php // A system job (ESXi inventory) has no mission, so it names itself. ?>
                    <button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('deploy.confirm_cancel', ['name' => (int) ($job['mission_id'] ?? 0) > 0 ? (string) ($job['mission_name'] ?? '') : __t('deploy.system_job')])); ?>" data-confirm-action="<?php echo h(__t('deploy.cancel_job')); ?>"><?php echo h(__t('common.cancel')); ?></button>
                </form>
            <?php } ?>
        </div>
    </section>

    <section class="grid" aria-label="<?php echo h(__t('deploy.log_title')); ?>">
        <article class="card kpi"><span class="muted"><?php echo h(__t('deploy.kpi_job')); ?></span><span class="value"><?php echo h((string) $job['id']); ?></span></article>
        <article class="card kpi"><span class="muted"><?php echo h(__t('common.status')); ?></span><span class="value"><span data-deploy-status class="badge badge-<?php echo h(deploy_job_status_badge_class((string) $job['status'])); ?>"><?php echo h($job['status'] ?? ''); ?></span></span></article>
        <article class="card kpi"><span class="muted"><?php echo h(__t('deploy.label_mode')); ?></span><span class="value value-small"><?php echo h(deploy_job_payload_summary($job['payload_json'] ?? null)); ?></span></article>
        <article class="card kpi"><span class="muted"><?php echo h(__t('common.mission')); ?></span><span class="value value-small"><?php echo h((int) $job['mission_id'] > 0 ? (string) ($job['mission_name'] ?? '') : __t('deploy.system_job')); ?></span></article>
    </section>

    <section class="panel">
        <h2><?php echo h(__t('deploy.output')); ?></h2>
        <div class="table-wrap" tabindex="0"><table>
            <thead><tr><th><?php echo h(__t('deploy.th_seq')); ?></th><th><?php echo h(__t('deploy.th_time')); ?></th><th><?php echo h(__t('deploy.th_stream')); ?></th><th><?php echo h(__t('deploy.th_line')); ?></th></tr></thead>
            <tbody data-deploy-log-body>
            <?php foreach ($logs as $log) { ?>
                <tr data-log-seq="<?php echo h((string) $log['seq']); ?>">
                    <td><?php echo h((string) $log['seq']); ?></td>
                    <td><?php echo h(portal_format_timestamp($log['created_at'] ?? '')); ?></td>
                    <td><?php echo h($log['stream'] ?? ''); ?></td>
                    <td><code class="log-line"><?php echo h($log['line'] ?? ''); ?></code></td>
                </tr>
            <?php } ?>
            <?php if ($logs === []) { ?><tr data-empty-log><td colspan="4"><?php echo h($logsPruned ? __t('deploy.output_pruned', ['days' => VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS]) : __t('deploy.no_output')); ?></td></tr><?php } ?>
            </tbody>
        </table></div>
    </section>

    <?php if (!empty($job['last_error'])) { ?>
        <section class="panel">
            <h2><?php echo h(__t('deploy.last_error')); ?></h2>
            <code class="log-line"><?php echo h($job['last_error']); ?></code>
        </section>
    <?php } ?>
</div>
<?php layout_footer(); ?>
