<?php

declare(strict_types=1);

// The two per-job recovery actions an operator can take from a job log
// (Etappe 13R). They post to deploy.php like the cancel form on the same page,
// so the job log keeps its single POST target and needs no dispatcher of its
// own.
//
// Both are database-only. Neither reaches the Ansible host: "retry cleanup"
// makes the worker's next pass pick the case up again, and "document an
// external check" records what a person established by looking somewhere this
// system cannot see. Promising a remote effect from an HTTP request would be
// promising exactly what is unavailable in the situation these exist for.
require_once __DIR__ . '/deploy_urls.php';
require_once __DIR__ . '/remote_execution_constants.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/log.php';

/** The actions this module owns, dispatched from deploy_handle_post(). */
const VIRTUSPHERE_DEPLOY_RECOVERY_ACTIONS = ['remote_cleanup_retry', 'remote_review_document'];

/**
 * Handles one per-job recovery action and redirects back to the job log.
 *
 * The permission is `system.config`, not `deploy.run`: both actions change how
 * the installation treats a case that could not be resolved automatically, and
 * that is an administrative decision rather than part of running a deploy. The
 * panel that renders the forms is gated on the same permission.
 *
 * @param array<string,mixed> $user
 */
function deploy_handle_recovery_action(mysqli $connection, array $user, string $action): void
{
    if (!can('system.config', $user)) {
        portal_forbid($connection, $user, 'system.config');
    }
    $jobId = request_int($_POST, 'job_id');
    $executionId = request_int($_POST, 'execution_id');
    $actorId = (int) ($user['id'] ?? 0);
    $target = deploy_job_log_url($jobId);

    if ($action === 'remote_cleanup_retry') {
        // The hash the operator's decision was made against travels with the
        // form. A cleanup whose evidence changed in between is a different case
        // and must not be restarted on the strength of a look at the old one.
        $expected = request_string($_POST, 'result_hash');
        $retried = repo_deploy_retry_remote_cleanup($connection, $executionId, $expected === '' ? null : $expected);
        audit_event(
            $connection,
            VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CLEANUP_RETRIED,
            'deploy_job',
            $jobId,
            $retried ? VIRTUSPHERE_AUDIT_RESULT_SUCCESS : VIRTUSPHERE_AUDIT_RESULT_FAILURE,
            ['execution_id' => $executionId],
            $actorId
        );
        // A refusal is reported as one. Saying "queued" for a request the
        // database declined would leave somebody waiting for a retry that is
        // not going to happen.
        flash_set(
            $retried ? 'success' : 'warning',
            __t($retried ? 'deploy.recovery_flash_cleanup_queued' : 'deploy.recovery_flash_cleanup_refused')
        );
        redirect_to($target);
    }

    $resolutionId = repo_deploy_record_external_review(
        $connection,
        $jobId,
        $executionId > 0 ? $executionId : null,
        $actorId,
        request_string($_POST, 'resolution_code'),
        request_string($_POST, 'reason'),
        request_trimmed($_POST, 'reference') === '' ? null : mb_substr(request_trimmed($_POST, 'reference'), 0, 255)
    );
    audit_event(
        $connection,
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_RECOVERY_DOCUMENTED,
        'deploy_job',
        $jobId,
        VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
        // The verdict is in the trail; the operator's own words are NOT. They
        // are free text about a production incident and belong in the append
        // only evidence table, behind the same permission, not in a row every
        // `users.manage` holder reads.
        ['resolution_code' => request_string($_POST, 'resolution_code'), 'resolution_id' => $resolutionId],
        $actorId
    );
    flash_set('success', __t('deploy.recovery_flash_documented'));
    redirect_to($target);
}
