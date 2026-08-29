<?php

declare(strict_types=1);

// The recovery diagnostics block of one job log (Etappe 13R).
//
// Deliberately NOT named deploy_*_panel.php: that shape is the owner glob of
// the deploy PAGE, and DeployFormStateContractTest walks it in both directions
// to prove every queue panel is required there. A block belonging to the job
// log would have been scanned as a queue form and its hidden ids read as
// fields the queue must carry.
//
// It renders only when the job actually HAS a durable remote execution and the
// reader may act on it. A permanently visible block that says "no remote
// execution" on every job would teach people to scroll past the one place this
// information ever appears; a block with disabled buttons would be worse, since
// a disabled control without a reason is a dead end.
//
// Every value shown here is a state, never a run token or a remote path: those
// are credentials to a machine this page has no business handing out.
require_once __DIR__ . '/deploy_recovery_actions.php';
require_once __DIR__ . '/remote_execution_constants.php';
// The labelled fact list is a shared presenter, and this block deliberately
// uses it rather than growing a second one: the System status card links here,
// and the same facts must not be laid out two different ways on the two pages.
// The require is what makes that legal - deploy_log.php loads no System status
// module, so without it the panel is a fatal the moment a job actually HAS a
// remote execution, which is a state no unit test and no other page produces.
require_once __DIR__ . '/system_status_shared_panels.php';

/**
 * The stored remote execution of one job, or null.
 *
 * @return array<string,mixed>|null
 */
function deploy_log_remote_execution(mysqli $db, int $jobId): ?array
{
    return repo_fetch_one(
        $db,
        'SELECT id, job_id, step_key, controller_state, effect_state, reconciliation_state, cleanup_state,
                cleanup_attempts, cleanup_auto_attempts, cleanup_last_error, result_sha256, recovery_count,
                last_observed_at, finished_at
         FROM deploy_remote_executions WHERE job_id = ? ORDER BY id DESC LIMIT 1',
        'i',
        [$jobId]
    );
}

/**
 * @param array<string,mixed> $job
 * @param array<string,mixed>|null $execution
 * @param array<string,mixed> $user
 */
function deploy_log_render_recovery(array $job, ?array $execution, array $user): void
{
    if ($execution === null || !can('system.config', $user)) {
        return;
    }
    $jobId = (int) $job['id'];
    $cleanupFailed = (string) $execution['cleanup_state'] === 'failed';
    ?>
    <section class="panel">
        <h2><?php echo h(__t('deploy.recovery_heading')); ?></h2>
        <p class="muted"><?php echo h(__t('deploy.recovery_hint')); ?></p>
        <?php
        echo system_status_fact_list([
            ['label' => __t('deploy.recovery_fact_controller'), 'html' => h((string) $execution['controller_state'])],
            ['label' => __t('deploy.recovery_fact_effect'), 'html' => h((string) $execution['effect_state'])],
            ['label' => __t('deploy.recovery_fact_reconciliation'), 'html' => h((string) $execution['reconciliation_state'])],
            ['label' => __t('deploy.recovery_fact_cleanup'), 'html' => h((string) $execution['cleanup_state'])],
            ['label' => __t('deploy.recovery_fact_attempts'), 'html' => h((string) $execution['cleanup_attempts'])],
        ]);
        ?>
        <?php if ($cleanupFailed) { ?>
            <?php // The result hash travels with the form: it is what the
                  // decision to retry was made against, and the repository
                  // refuses the retry if it moved in between. ?>
            <form class="inline-form" method="post" action="deploy.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="remote_cleanup_retry">
                <input type="hidden" name="job_id" value="<?php echo h((string) $jobId); ?>">
                <input type="hidden" name="execution_id" value="<?php echo h((string) $execution['id']); ?>">
                <input type="hidden" name="result_hash" value="<?php echo h((string) ($execution['result_sha256'] ?? '')); ?>">
                <button class="button button-secondary" type="submit"><?php echo h(__t('deploy.recovery_cleanup_retry')); ?></button>
            </form>
            <?php if (($execution['cleanup_last_error'] ?? null) !== null) { ?>
                <details class="technical-details"><summary><?php echo h(__t('common.technical_details')); ?></summary><pre><?php echo h((string) $execution['cleanup_last_error']); ?></pre></details>
            <?php } ?>
        <?php } ?>

        <h3><?php echo h(__t('deploy.recovery_document_heading')); ?></h3>
        <p class="muted"><?php echo h(__t('deploy.recovery_document_hint')); ?></p>
        <form class="form-grid" method="post" action="deploy.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="remote_review_document">
            <input type="hidden" name="job_id" value="<?php echo h((string) $jobId); ?>">
            <input type="hidden" name="execution_id" value="<?php echo h((string) $execution['id']); ?>">
            <label for="recovery-resolution"><?php echo h(__t('deploy.recovery_label_code')); ?>
                <select id="recovery-resolution" name="resolution_code" required>
                    <?php foreach (VIRTUSPHERE_RECOVERY_RESOLUTION_CODES as $code) { ?>
                        <option value="<?php echo h($code); ?>"><?php echo h(__t('deploy.recovery_code_' . $code)); ?></option>
                    <?php } ?>
                </select>
            </label>
            <label for="recovery-reason"><?php echo h(__t('deploy.recovery_label_reason')); ?>
                <textarea id="recovery-reason" name="reason" rows="3" required></textarea>
            </label>
            <label for="recovery-reference"><?php echo h(__t('deploy.recovery_label_reference')); ?>
                <input id="recovery-reference" name="reference" maxlength="255">
            </label>
            <div class="actions"><button class="button" type="submit"><?php echo h(__t('deploy.recovery_document_submit')); ?></button></div>
        </form>
    </section>
    <?php
}
