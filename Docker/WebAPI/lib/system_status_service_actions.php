<?php

declare(strict_types=1);

// The operator actions on the deploy service's claim axis (Etappe 13R).
//
// Both are pure database actions. Neither talks to the Ansible host, neither
// touches a running playbook and neither can stop work that is already changing
// ESXi: pausing means "take no further job", and that is the whole promise the
// text is allowed to make.
require_once __DIR__ . '/deploy_service_health.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/system_status.php';

/**
 * The two actions this module owns. The page dispatches on this list rather
 * than on a hand-written `in_array`, so a new action cannot be added here and
 * forgotten there.
 */
const VIRTUSPHERE_DEPLOY_SERVICE_ACTIONS = ['deploy_claim_pause', 'deploy_claim_resume', 'deploy_recovery_review'];

/**
 * Handles one claim action and redirects.
 *
 * The permission is `system.config`, the same one that owns every other switch
 * that changes how the installation behaves. It is checked here, next to the
 * write, and the buttons are gated on the same permission, so a hidden button
 * and a refused POST can never disagree.
 *
 * @param array<string,mixed> $user
 */
function system_status_handle_service_action(mysqli $connection, array $user, string $action): void
{
    if (!can('system.config', $user)) {
        portal_forbid($connection, $user, 'system.config');
    }
    $actorId = (int) ($user['id'] ?? 0);
    $target = system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEPLOY_SERVICE);

    if ($action === 'deploy_claim_pause') {
        // LG-09 / HA-23: the state change and its audit row are one transaction, so a row
        // that cannot be stored rolls the pause back instead of leaving it unattributed.
        // Exactly one audit line per real change. A second click lands on the
        // same state, writes nothing and says so: an idempotent action that
        // logs every attempt turns the trail into a click counter.
        // The registry's own vocabulary: this is a state machine transition, so
        // it is a `new_state`, not a field invented for one event.
        $transition = repo_transaction($connection, static function () use ($connection, $actorId): array {
            $transition = repo_deploy_request_claim_pause($connection, $actorId);
            if ($transition['changed']) {
                $context = ['new_state' => (string) $transition['state']];
                if (count($transition['job_ids']) === 1) {
                    $context['job_id'] = $transition['job_ids'][0];
                }
                audit_event_required(
                    $connection,
                    VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CLAIM_PAUSED,
                    'system',
                    null,
                    VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
                    $context,
                    $actorId
                );
            }

            return $transition;
        });
        $state = (string) $transition['state'];
        flash_set($transition['changed'] ? 'success' : 'info', __t(
            !$transition['changed']
                ? 'system_status.service_flash_pause_unchanged'
                : ($state === VIRTUSPHERE_DEPLOY_CLAIM_PAUSE_AFTER_CURRENT
                    ? 'system_status.service_flash_pause_after_current'
                    : 'system_status.service_flash_paused')
        ));
        redirect_to($target);
    }

    if ($action === 'deploy_recovery_review') {
        // Asking the policy to run NOW instead of waiting out a reaper interval.
        // It requests recovery; it does not perform one, because performing it
        // needs the remote host and an HTTP request cannot promise that.
        // LG-09 / HA-23: the requests and the row that says what the review found are one transaction.
        $counts = repo_transaction($connection, static function () use ($connection, $actorId): array {
            $counts = repo_deploy_review_recovery($connection);
            audit_event_required(
                $connection,
                VIRTUSPHERE_AUDIT_EVENT_DEPLOY_RECOVERY_REVIEWED,
                'system',
                null,
                VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
                [
                    'reviewed_count' => $counts['reviewed'],
                    'requested_count' => $counts['requested'],
                    'manual_count' => $counts['manual'],
                ],
                $actorId
            );

            return $counts;
        });
        // The flash reports what was FOUND. "Review started" would be a
        // sentence about the button; an operator needs to know whether anything
        // moved and whether anything is still theirs to do.
        flash_set($counts['manual'] > 0 ? 'warning' : 'success', __t('system_status.service_flash_reviewed', [
            'reviewed' => $counts['reviewed'],
            'requested' => $counts['requested'],
            'manual' => $counts['manual'],
        ]));
        redirect_to($target);
    }

    // LG-09 / HA-23: the resume and its audit row are one transaction.
    repo_transaction($connection, static function () use ($connection, $actorId): void {
        if (repo_deploy_resume_claims($connection, $actorId)) {
            audit_event_required(
                $connection,
                VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CLAIM_RESUMED,
                'system',
                null,
                VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
                ['new_state' => VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING],
                $actorId
            );
        }
    });
    flash_set('success', __t('system_status.service_flash_resumed'));
    redirect_to($target);
}
