<?php

declare(strict_types=1);

require_once __DIR__ . '/vm_urls.php';
require_once __DIR__ . '/deploy_retry_confirmation.php';

/** @var list<array<string,mixed>> $missions */
/** @var int $selectedMissionId */
/** @var list<array<string,mixed>> $jobs */
/** @var array<int,array{int,int}> $groupPositions */
/** @var array<int,array<string,mixed>> $retryEvaluations */

?>
<section class="panel">
    <div class="actions">
        <h2><?php echo h(__t('deploy.jobs_heading')); ?></h2>
        <form class="inline-form" method="get" action="deploy.php" data-deploy-filter>
            <label class="filter-field"><?php echo h(__t('deploy.filter')); ?>
                <select name="mission_id">
                    <option value="0"><?php echo h(__t('deploy.all_missions')); ?></option>
                    <?php foreach ($missions as $mission) { ?>
                        <option value="<?php echo h((string) $mission['id']); ?>" <?php echo (int) $mission['id'] === $selectedMissionId ? 'selected' : ''; ?>><?php echo h($mission['mission_name'] ?? ''); ?></option>
                    <?php } ?>
                </select>
            </label>
            <button class="button button-secondary" type="submit"><?php echo h(__t('deploy.apply')); ?></button>
        </form>
    </div>
    <div class="table-wrap" tabindex="0"><table>
        <thead><tr><th><?php echo h(__t('deploy.th_id')); ?></th><th><?php echo h(__t('common.mission')); ?></th><th><?php echo h(__t('common.status')); ?></th><th><?php echo h(__t('deploy.label_mode')); ?></th><th><?php echo h(__t('deploy.th_credentials')); ?></th><th><?php echo h(__t('deploy.th_user')); ?></th><th><?php echo h(__t('common.updated')); ?></th><th><?php echo h(__t('common.actions')); ?></th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $job) { ?>
            <tr>
                <td><?php echo h((string) $job['id']); ?></td>
                <td><?php if ((int) ($job['mission_id'] ?? 0) > 0) { ?><a href="<?php echo h(mission_details_url((int) $job['mission_id'])); ?>"><?php echo h($job['mission_name'] ?? ''); ?></a><?php } else { echo h($job['mission_name'] ?? ''); } ?></td>
                <td>
                    <?php echo deploy_job_status_badge((string) ($job['status'] ?? '')); ?>
                    <?php if (($job['group_id'] ?? '') !== '' && isset($groupPositions[(int) $job['id']])) { [$gpos, $gtot] = $groupPositions[(int) $job['id']]; ?>
                        <?php echo portal_badge('info', __t('deploy.group_slot', ['pos' => $gpos, 'total' => $gtot])); ?>
                    <?php } ?>
                    <?php if (!empty($job['scheduled_at'])) { ?>
                        <div class="muted nowrap"><?php echo h(__t('deploy.scheduled_for', ['time' => portal_format_timestamp((string) $job['scheduled_at'])])); ?></div>
                    <?php } ?>
                </td>
                <td><?php echo h(deploy_job_payload_display($job['payload_json'] ?? null)); ?></td>
                <td><?php echo h(($job['esxi_credential_name'] ?? 'ESXi ?') . ' / ' . ($job['ansible_credential_name'] ?? 'Ansible ?')); ?></td>
                <td><?php echo h($job['user_name'] ?? ($job['user_id'] ?? '')); ?></td>
                <td><?php echo h(portal_format_timestamp($job['updated_at'] ?? '')); ?></td>
                <td class="actions">
                    <a class="button button-secondary" href="<?php echo h(deploy_job_log_url((int) $job['id'])); ?>"><?php echo h(__t('deploy.log')); ?></a>
                    <?php if (in_array((string) $job['status'], VIRTUSPHERE_DEPLOY_JOB_CANCELLABLE_STATUSES, true)) { ?>
                        <form class="inline-form" method="post" action="deploy.php<?php echo $selectedMissionId > 0 ? '?mission_id=' . h((string) $selectedMissionId) : ''; ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="job_id" value="<?php echo h((string) $job['id']); ?>">
                            <button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('deploy.confirm_cancel', ['name' => (int) ($job['mission_id'] ?? 0) > 0 ? (string) ($job['mission_name'] ?? '') : __t('deploy.system_job')])); ?>" data-confirm-action="<?php echo h(__t('deploy.cancel_job')); ?>"><?php echo h(__t('common.cancel')); ?></button>
                        </form>
                    <?php } ?>
                    <?php if (deploy_job_is_retryable((string) $job['status'], $job['mission_id'] !== null ? (int) $job['mission_id'] : null)) {
                        $retryEvaluation = $retryEvaluations[(int) $job['id']] ?? null;
                        $retryName = (string) ($job['mission_name'] ?? '');
                        $retryConfirm = deploy_retry_confirmation($retryEvaluation ?? [], $retryName);
                        ?>
                        <?php if (is_array($retryEvaluation) && !empty($retryEvaluation['allowed'])) { ?>
                            <form class="inline-form" method="post" action="deploy.php<?php echo $selectedMissionId > 0 ? '?mission_id=' . h((string) $selectedMissionId) : ''; ?>">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="retry">
                                <input type="hidden" name="job_id" value="<?php echo h((string) $job['id']); ?>">
                                <button class="button button-secondary" type="submit" data-confirm="<?php echo h($retryConfirm); ?>"><?php echo h(__t('deploy.retry')); ?></button>
                            </form>
                        <?php } elseif (is_array($retryEvaluation) && (int) ($retryEvaluation['repair_vm_id'] ?? 0) > 0 && can('vms.write')) { ?>
                            <a class="button button-secondary" href="<?php echo h(vm_edit_url((int) $job['mission_id'], (int) $retryEvaluation['repair_vm_id'], 'interfaces')); ?>"><?php echo h(__t('deploy.retry_fix_configuration')); ?></a>
                        <?php } else { ?>
                            <span class="muted"><?php echo h(__t('deploy.retry_blocked_short')); ?></span>
                        <?php } ?>
                    <?php } ?>
                    <?php if (($job['group_id'] ?? '') !== '' && isset($groupPositions[(int) $job['id']]) && $groupPositions[(int) $job['id']][0] === 1) { ?>
                        <form class="inline-form" method="post" action="deploy.php<?php echo $selectedMissionId > 0 ? '?mission_id=' . h((string) $selectedMissionId) : ''; ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="cancel_group">
                            <input type="hidden" name="group_id" value="<?php echo h((string) $job['group_id']); ?>">
                            <button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('deploy.confirm_cancel_group')); ?>"><?php echo h(__t('deploy.cancel_group')); ?></button>
                        </form>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
        <?php if ($jobs === []) { ?><tr><td colspan="8"><?php echo h(__t('deploy.empty')); ?></td></tr><?php } ?>
        </tbody>
    </table></div>
</section>
