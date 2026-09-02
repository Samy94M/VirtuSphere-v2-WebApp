<?php

declare(strict_types=1);

// The action box of an unresolved create unit (Etappe 14B, Teiletappe F).
//
// It renders only when a unit of this job actually ended unresolved and the
// reader holds every permission the action needs. A permanently visible block
// would teach people to scroll past the one place this ever appears, and a
// disabled button without a reason is a dead end - so when the evidence is not
// there yet, the block says WHICH evidence is missing and what produces it,
// instead of offering a control that would be refused.
//
// The panel decides nothing. deploy_create_release_blockers() is the one
// decision, and the transaction behind the button re-runs it under the lock:
// what a person saw is a statement about the moment they looked.
require_once __DIR__ . '/deploy_create_release.php';
require_once __DIR__ . '/deploy_recovery_actions.php';

/**
 * Renders the release box for the first unresolved create unit of this job.
 *
 * First, not all of them: an unresolved unit stops its job, so there is at most
 * one - and if a reaped job left several, they are released one at a time,
 * each with its own look at the host.
 *
 * @param array<string,mixed> $job
 * @param array<string,mixed> $user
 */
function deploy_log_render_create_release(mysqli $db, array $job, array $user): void
{
    $jobId = (int) ($job['id'] ?? 0);
    if ($jobId <= 0 || !can('system.config', $user) || !can('deploy.run', $user) || !can('vms.write', $user)) {
        return;
    }
    $unresolved = null;
    foreach (repo_deploy_create_results($db, $jobId) as $row) {
        if ((string) $row['status'] === VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN) {
            $unresolved = $row;
            break;
        }
    }
    if ($unresolved === null) {
        return;
    }
    $decision = deploy_create_release_blockers($db, $jobId, (int) $unresolved['position']);
    $vmName = (string) $unresolved['vm_name'];
    ?>
    <section class="panel">
        <h2><?php echo h(__t('deploy.create_release_heading')); ?></h2>
        <p class="muted"><?php echo h(__t('deploy.create_release_hint')); ?></p>
        <?php if (!$decision['eligible']) { ?>
            <div class="alert alert-warning">
                <p><strong><?php echo h(__t('deploy.create_release_blocked_heading')); ?></strong></p>
                <ul>
                    <?php foreach ($decision['blockers'] as $blocker) { ?>
                        <li><?php echo h(__t('deploy.create_release_blocker_' . $blocker)); ?></li>
                    <?php } ?>
                </ul>
            </div>
        <?php } else { ?>
            <form class="form-grid" method="post" action="deploy.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="create_unit_release">
                <input type="hidden" name="job_id" value="<?php echo h((string) $jobId); ?>">
                <input type="hidden" name="position" value="<?php echo h((string) $unresolved['position']); ?>">
                <label for="create-release-reason"><?php echo h(__t('deploy.create_release_label_reason')); ?>
                    <textarea id="create-release-reason" name="reason" rows="3" required></textarea>
                </label>
                <label for="create-release-reference"><?php echo h(__t('deploy.create_release_label_reference')); ?>
                    <input id="create-release-reference" name="reference" maxlength="255">
                </label>
                <?php // A statement, not a probe. The note under it says so, so
                      // the record cannot later be read as a task check this
                      // system performed. ?>
                <label for="create-release-attest">
                    <input id="create-release-attest" type="checkbox" name="recent_tasks_checked" value="1" required>
                    <?php echo h(__t('deploy.create_release_attest')); ?>
                </label>
                <p class="muted"><?php echo h(__t('deploy.create_release_attest_note')); ?></p>
                <div class="actions">
                    <button class="button" type="submit" data-confirm="<?php echo h(__t('deploy.create_release_confirm', ['name' => $vmName])); ?>"><?php echo h(__t('deploy.create_release_submit')); ?></button>
                </div>
            </form>
        <?php } ?>
    </section>
    <?php
}
