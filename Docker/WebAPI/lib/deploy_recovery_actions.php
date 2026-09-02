<?php

declare(strict_types=1);

// The per-job recovery actions an operator can take from a job log (Etappe 13R,
// extended by Etappe 14B-F). They post to deploy.php like the cancel form on the
// same page, so the job log keeps its single POST target and needs no dispatcher
// of its own.
//
// All of them are database-only. None reaches the Ansible host: "retry cleanup"
// makes the worker's next pass pick the case up again, and "document an
// external check" records what a person established by looking somewhere this
// system cannot see. Promising a remote effect from an HTTP request would be
// promising exactly what is unavailable in the situation these exist for.
require_once __DIR__ . '/deploy_urls.php';
require_once __DIR__ . '/remote_execution_constants.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/repo/deploy_create_identity.php';

/** The actions this module owns, dispatched from deploy_handle_post(). */
const VIRTUSPHERE_DEPLOY_RECOVERY_ACTIONS = ['remote_cleanup_retry', 'remote_review_document', 'create_unit_release'];

/**
 * Handles one per-job recovery action and redirects back to the job log.
 *
 * `system.config` is the floor for all of them, not `deploy.run`: each changes
 * how the installation treats a case that could not be resolved automatically,
 * and that is an administrative decision rather than part of running a deploy.
 * The panel that renders the forms is gated on the same permission. The create
 * release of Etappe 14B-F adds two more permissions of its own.
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

    if ($action === 'create_unit_release') {
        deploy_handle_create_unit_release($connection, $user, $jobId, $target);
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

/**
 * The operator release of one unresolved create unit (Etappe 14B-F, plan 10.5).
 *
 * Three permissions, and each of them is a different half of what this does.
 * `system.config` because it overrides how the installation treats a case it
 * could not resolve; `deploy.run` because its only effect is to let a deploy be
 * started again; `vms.write` because the VM it is about is a portal object
 * whose state the decision changes. Requiring all three is deliberate: the
 * action's failure mode is a second VM on a production host.
 *
 * The Recent-Tasks attestation is a checkbox, not a check. VirtuSphere cannot
 * see the ESXi task list, so it records what the operator states, and the text
 * around it says exactly that rather than calling it a verified task probe.
 *
 * @param array<string,mixed> $user
 */
function deploy_handle_create_unit_release(mysqli $connection, array $user, int $jobId, string $target): void
{
    foreach (['deploy.run', 'vms.write'] as $permission) {
        if (!can($permission, $user)) {
            portal_forbid($connection, $user, $permission);
        }
    }
    $position = request_int($_POST, 'position');
    $actorId = (int) ($user['id'] ?? 0);
    if (request_string($_POST, 'recent_tasks_checked') !== '1') {
        throw new ValidationException(['recent_tasks_checked' => __t('validate.required')]);
    }

    $outcome = repo_deploy_create_release_unit(
        $connection,
        $jobId,
        $position,
        $actorId,
        request_string($_POST, 'reason'),
        request_trimmed($_POST, 'reference') === '' ? null : mb_substr(request_trimmed($_POST, 'reference'), 0, 255)
    );
    $context = ['position' => $position];
    if ($outcome['released']) {
        $context['resolution_id'] = (int) $outcome['resolution_id'];
    } else {
        // The first blocker, not all of them: the audit row says why the
        // request was refused, and the page shows the operator the full list.
        $context['blocker'] = (string) ($outcome['blockers'][0] ?? 'release_unit_not_uncertain');
    }
    audit_event(
        $connection,
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CREATE_RELEASED,
        'deploy_job',
        $jobId,
        $outcome['released'] ? VIRTUSPHERE_AUDIT_RESULT_SUCCESS : VIRTUSPHERE_AUDIT_RESULT_FAILURE,
        $context,
        $actorId
    );
    // A refusal is reported as one. Saying "released" for a request the
    // evidence declined would send somebody straight into a retry that is
    // still - correctly - blocked.
    flash_set(
        $outcome['released'] ? 'success' : 'warning',
        __t($outcome['released'] ? 'deploy.create_release_flash_done' : 'deploy.create_release_flash_refused')
    );
    redirect_to($target);
}
