<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_urls.php';
require_once __DIR__ . '/deploy_display.php';
require_once __DIR__ . '/repo/deploy_job_queries.php';

/**
 * The deploy jobs one traced request enqueued, shown beside its audit rows.
 *
 * Only under an EXACT correlation search. The panel answers the second half of
 * "what did this request do": the audit rows say what was decided, the jobs say
 * what was then handed to the Ansible host, and an operator following an error
 * reference should not have to open the deploy page and match timestamps by eye
 * to connect them.
 *
 * Two boundaries are deliberate and neither is cosmetic.
 *
 * The permissions are separate. Reading this page is `users.manage`; that is
 * what decided the reader may see these audit rows and it decides the job ids
 * and statuses here too. Opening a job LOG is `deploy.run`, so the link is
 * rendered only for a holder of that permission while the row itself stays
 * visible: hiding the row from a user-administrator would make a trace look
 * incomplete, and handing them the log would widen what `users.manage` grants.
 *
 * The retention windows differ, and the panel says so. Audit rows outlive
 * mission job rows, and a mission-less system job is purged on its own
 * schedule, so a trace that is months old legitimately shows audit rows and no
 * jobs. Without that sentence the empty list reads as "this request enqueued
 * nothing", which is a different and wrong conclusion.
 */
function logs_render_correlation_jobs(mysqli $connection, array $filter, array $user): void
{
    $correlation = (string) ($filter['correlation'] ?? '');
    if ($correlation === '' || !log_filter_is_usable($filter)) {
        return;
    }

    $result = repo_deploy_jobs_by_correlation($connection, $correlation);
    $jobs = $result['jobs'];
    $mayOpenLog = can('deploy.run', $user);
    ?>
    <section class="panel">
        <h2><?php echo h(__t('logs.jobs_heading')); ?></h2>
        <p class="muted"><?php echo h(__t('logs.jobs_retention_note', [
            'audit_days' => VIRTUSPHERE_LOG_RETENTION_DAYS,
            'job_days' => VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS,
        ])); ?></p>
        <?php if ($result['truncated']) { ?>
            <p class="muted" data-jobs-truncated="1"><?php echo h(__t('logs.jobs_truncated', [
                'limit' => VIRTUSPHERE_LOG_CORRELATION_JOB_LIMIT,
            ])); ?></p>
        <?php } ?>
        <?php if ($jobs === []) { ?>
            <p class="muted"><?php echo h(__t('logs.jobs_none')); ?></p>
        <?php } else { ?>
        <div class="table-wrap" tabindex="0"><table>
            <thead><tr>
                <th><?php echo h(__t('logs.jobs_th_id')); ?></th>
                <th><?php echo h(__t('logs.jobs_th_scope')); ?></th>
                <th><?php echo h(__t('common.status')); ?></th>
                <th><?php echo h(__t('logs.jobs_th_created')); ?></th>
                <?php if ($mayOpenLog) { ?><th><?php echo h(__t('common.actions')); ?></th><?php } ?>
            </tr></thead>
            <tbody>
            <?php foreach ($jobs as $job) {
                $jobId = (int) $job['id'];
                $missionName = trim((string) ($job['mission_name'] ?? ''));
                ?>
                <tr>
                    <td><?php echo h((string) $jobId); ?></td>
                    <?php // A system job has no mission by design, so it is named
                          // as what it is rather than shown as a blank cell. ?>
                    <td><?php echo $missionName !== ''
                        ? h($missionName)
                        : '<span class="muted">' . h(__t('logs.jobs_system_job')) . '</span>'; ?></td>
                    <td><?php echo deploy_job_status_badge((string) ($job['status'] ?? '')); ?></td>
                    <td class="nowrap"><?php echo h(portal_format_timestamp((string) ($job['created_at'] ?? ''))); ?></td>
                    <?php if ($mayOpenLog) { ?>
                        <td><a class="button button-secondary" href="<?php echo h(deploy_job_log_url($jobId)); ?>"><?php echo h(__t('logs.jobs_open_log')); ?></a></td>
                    <?php } ?>
                </tr>
            <?php } ?>
            </tbody>
        </table></div>
        <?php } ?>
    </section>
    <?php
}
