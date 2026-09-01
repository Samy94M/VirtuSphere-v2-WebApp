<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_recovery_actions.php';

require_once __DIR__ . '/ansible.php';
require_once __DIR__ . '/deploy_form_state.php';
require_once __DIR__ . '/deploy_blockers.php';
require_once __DIR__ . '/deploy_page.php';
require_once __DIR__ . '/deploy_storage.php';
require_once __DIR__ . '/deploy_urls.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/repo/missions.php';

/**
 * Dispatches the guarded deploy POST and returns the schedule preview when the
 * request deliberately falls through to a render. Every successful mutation
 * redirects, so the page shell has no action-specific branch.
 *
 * @param array<string,mixed> $user
 * @return array<string,mixed>
 */
function deploy_handle_post(mysqli $connection, array $user, int $selectedMissionId): array
{
    $redirectBase = 'deploy.php' . ($selectedMissionId > 0 ? '?mission_id=' . $selectedMissionId : '');

    try {
        $action = request_string($_POST, 'action');
        // The per-job recovery actions post here like the cancel form on the
        // same page, so the job log keeps one POST target. They own their own
        // permission and audit line.
        if (in_array($action, VIRTUSPHERE_DEPLOY_RECOVERY_ACTIONS, true)) {
            deploy_handle_recovery_action($connection, $user, $action);
        }
        if ($action === 'start') {
            $queueInput = deploy_queue_normalize_input($_POST);
            $missionIdPost = $queueInput['mission_id'];
            $esxiId = $queueInput['credential_esxi_id'];
            $ansibleId = $queueInput['credential_ansible_id'];
            $mode = $queueInput['mode'];
            $payloadData = [
                'mode' => $mode,
                'verbose' => $queueInput['verbose'],
                'vm_ids' => $queueInput['vm_ids'],
                'powercycle_wait' => $queueInput['powercycle_wait'],
                'start_wait' => $queueInput['start_wait'],
            ];
            $schedule = deploy_parse_schedule($queueInput);
            $confirmed = ($_POST['confirmed'] ?? '') === '1';

            deploy_assert_queue_unblocked($connection, $queueInput);

            if ($schedule['has_schedule'] && !$confirmed) {
                $previewMission = repo_get_mission($connection, $missionIdPost);

                return [
                    'schedule' => $schedule,
                    'rows' => deploy_preview_rows($connection, $missionIdPost, $payloadData, $schedule),
                    'storage' => $previewMission !== null
                        ? ansible_storage_by_datastore($previewMission, deploy_selected_vms(getVMs($connection, $missionIdPost), $payloadData['vm_ids']))
                        : [],
                    'capacity' => deploy_datastore_capacity($connection, $esxiId),
                ];
            }

            if ($schedule['stagger'] !== null) {
                // Same normalized blocker list as the page and live endpoint,
                // immediately before the repository performs its locking gate.
                deploy_assert_queue_unblocked($connection, $queueInput);
                $result = repo_enqueue_deploy_group($connection, $missionIdPost, (int) $user['id'], $esxiId, $ansibleId, $payloadData, $schedule['base_utc'], $schedule['stagger']);
                audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_DEPLOY_QUEUED, 'deploy_group', (string) $result['group_id'], VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                    'mission_id' => $missionIdPost,
                    'job_count' => (int) $result['count'],
                    'scheduled' => $schedule['base_utc'] !== null,
                ], (int) $user['id']);
                flash_set('success', __t('deploy.flash_group_queued', ['count' => $result['count']]));
                redirect_to($redirectBase);
            }

            deploy_assert_queue_unblocked($connection, $queueInput);
            $jobId = repo_create_deploy_job($connection, $missionIdPost, (int) $user['id'], $esxiId, $ansibleId, $payloadData, $schedule['base_utc']);
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_DEPLOY_QUEUED, 'deploy_job', $jobId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'mission_id' => $missionIdPost,
                'scheduled' => $schedule['base_utc'] !== null,
            ], (int) $user['id']);
            if ($schedule['base_utc'] !== null) {
                flash_set('success', __t('deploy.flash_scheduled'));
                redirect_to($redirectBase);
            }
            flash_set('success', __t('deploy.flash_queued'));
            redirect_to(deploy_job_log_url($jobId));
        }

        if ($action === 'adopt_vm') {
            if (!can('vms.write', $user)) {
                portal_forbid($connection, $user, 'vms.write');
            }
            $missionIdPost = request_int($_POST, 'mission_id');
            $esxiId = request_int($_POST, 'credential_esxi_id');
            $vmId = request_int($_POST, 'vm_id');
            $adopted = repo_adopt_vm_identity($connection, $missionIdPost, $vmId, $esxiId);
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_VM_IDENTITY_ADOPTED, 'vm', $vmId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'credential_id' => $esxiId,
                'mission_id' => $missionIdPost,
                'moid' => (string) $adopted['vm_moid'],
                'instance_uuid' => (string) $adopted['vm_instance_uuid'],
            ], (int) $user['id']);
            flash_set('success', __t('deploy.identity_adopted', ['name' => $adopted['vm_name']]));
            redirect_to('deploy.php?mission_id=' . $missionIdPost . '&credential_esxi_id=' . $esxiId);
        }

        if ($action === 'cancel') {
            $jobId = request_int($_POST, 'job_id');
            $job = repo_deploy_job($connection, $jobId);
            if ($job !== null) {
                $redirectBase = deploy_job_cancel_redirect_url($job, request_string($_POST, 'origin'));
            }
            $cancelOutcome = repo_cancel_deploy_job($connection, $jobId, (int) $user['id']);
            if ($cancelOutcome === VIRTUSPHERE_DEPLOY_STATUS_CANCELLING) {
                audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCEL_REQUESTED, 'deploy_job', $jobId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [], (int) $user['id']);
                flash_set('success', __t('deploy.flash_cancel_requested'));
            } else {
                audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCELLED, 'deploy_job', $jobId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                    'action' => 'cancelled',
                ], (int) $user['id']);
                flash_set('success', __t('deploy.flash_cancelled'));
            }
            redirect_to($redirectBase);
        }

        if ($action === 'cancel_group') {
            $groupId = request_string($_POST, 'group_id');
            $count = repo_cancel_deploy_group($connection, $groupId, (int) $user['id']);
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCELLED, 'deploy_group', $groupId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'action' => 'cancelled',
                'job_count' => $count,
            ], (int) $user['id']);
            flash_set('success', __t('deploy.flash_group_cancelled', ['count' => $count]));
            redirect_to($redirectBase);
        }

        if ($action === 'retry') {
            $jobId = request_int($_POST, 'job_id');
            if (request_string($_POST, 'origin') === VIRTUSPHERE_DEPLOY_JOB_ORIGIN_LOG) {
                $redirectBase = deploy_job_log_url($jobId);
            }
            $newJobId = repo_retry_deploy_job($connection, $jobId, (int) $user['id']);
            audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_DEPLOY_RETRIED, 'deploy_job', $newJobId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
                'retry_of_job_id' => $jobId,
            ], (int) $user['id']);
            flash_set('success', __t('deploy.flash_retried'));
            redirect_to(deploy_job_log_url($newJobId));
        }

        redirect_to($redirectBase);
    } catch (DeployRetryBlockedException) {
        flash_set('error', __t('deploy.retry_blocked'));
        redirect_to($redirectBase);
    } catch (VmIdentityConflictException $exception) {
        form_remember('schedule', $_POST, []);
        flash_set('error', __t('deploy.identity_conflict_flash', [
            'names' => implode(', ', array_column($exception->conflicts(), 'vm_name')),
        ]));
        redirect_to($redirectBase);
    } catch (ValidationException $exception) {
        form_remember('schedule', $_POST, $exception->errors());
        flash_set('error', portal_error_message($exception));
        redirect_to($redirectBase);
    } catch (Throwable $exception) {
        form_remember('schedule', $_POST, []);
        flash_set('error', portal_error_message($exception));
        redirect_to($redirectBase);
    }
}
