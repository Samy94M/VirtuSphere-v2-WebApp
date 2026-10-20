<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_registry.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/audit_presenter_parts.php';

/**
 * The sentence of one audit row, rendered from lang/{de,en}/audit_log.php.
 *
 * The writers call this with the default locale 'en' and store the result, so the
 * stored log_message is English and canonical: search, the CSV export and the
 * saved filters read it. The logs page renders structured rows again in the
 * viewer's locale (lib/audit_log_view.php). A missing key renders as its own name,
 * which the catalog tests catch.
 *
 * @param array<string,mixed> $context The normalized context (lib/audit_registry.php).
 */
function audit_event_description(string $eventCode, string $objectType, ?string $objectId, string $result, array $context, string $locale = 'en'): string
{
    $id = $objectId ?? 'unknown';
    $action = (string) ($context['action'] ?? 'changed');
    $actionLabel = audit_token_label($locale, 'action_', $action);
    $unknown = audit_t($locale, 'unknown');
    $message = match ($eventCode) {
        VIRTUSPHERE_AUDIT_EVENT_AUTH_CSRF_REJECTED => audit_t($locale, 'csrf_rejected', ['page' => (string) ($context['page'] ?? $id)]),
        VIRTUSPHERE_AUDIT_EVENT_AUTH_ACCESS_DENIED => audit_t($locale, 'access_denied', ['permission' => (string) ($context['permission'] ?? $id)]),
        VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGOUT => audit_t($locale, 'logout'),
        // The result, not the code, says whether it happened: `changed own
        // password` on success, `rejected own password change` otherwise. One
        // neutral code carries both, so a refusal can never be filed under a
        // name that asserts the change went through.
        VIRTUSPHERE_AUDIT_EVENT_AUTH_PASSWORD_CHANGE_ATTEMPT => $result === VIRTUSPHERE_AUDIT_RESULT_SUCCESS
            ? audit_t($locale, 'password_changed', ['scope' => audit_scope_label($locale, $context)])
            : audit_t($locale, 'password_change_rejected', ['scope' => audit_scope_label($locale, $context), 'reason_part' => audit_description_reason($context, $locale)]),
        VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN => audit_login_description($id, $result, $context, $locale),
        VIRTUSPHERE_AUDIT_EVENT_AUTH_ACCOUNT_LOCKED => audit_t($locale, 'account_locked', ['duration' => (string) ($context['duration_minutes'] ?? 0), 'failures' => (string) ($context['failure_count'] ?? 0)]),
        VIRTUSPHERE_AUDIT_EVENT_AUTH_IP_RATE_LIMITED => audit_t($locale, 'ip_rate_limited', ['duration' => (string) ($context['duration_minutes'] ?? 0), 'failures' => (string) ($context['failure_count'] ?? 0)]),
        VIRTUSPHERE_AUDIT_EVENT_AUTH_SESSION_ENDED => audit_session_description($context, $locale),
        VIRTUSPHERE_AUDIT_EVENT_USER_CREATED => audit_t($locale, 'user_created', ['source' => (string) ($context['source'] ?? 'local'), 'id' => $id, 'named' => audit_description_named($context, $locale)]),
        VIRTUSPHERE_AUDIT_EVENT_USER_ACTIVE_CHANGED => audit_t($locale, 'user_active_changed', ['id' => $id, 'state' => audit_bool_word((bool) ($context['enabled'] ?? false), $locale)]),
        VIRTUSPHERE_AUDIT_EVENT_USER_ROLE_CHANGED => audit_t($locale, 'user_role_changed', ['id' => $id, 'role' => (string) ($context['role'] ?? $unknown)]),
        VIRTUSPHERE_AUDIT_EVENT_USER_EMAIL_CHANGED => audit_t($locale, 'user_email_changed', ['action' => $actionLabel, 'id' => $id]),
        VIRTUSPHERE_AUDIT_EVENT_USER_SECURITY_CHANGED => audit_t($locale, 'user_security_changed', ['action' => $actionLabel, 'id' => $id]),
        VIRTUSPHERE_AUDIT_EVENT_USER_DIRECTORY_IMPORTED => audit_t($locale, 'user_directory_imported', ['id' => $id]),
        VIRTUSPHERE_AUDIT_EVENT_USER_DIRECTORY_SYNCED => audit_t($locale, 'user_directory_synchronized', ['id' => $id]),
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONFIG_CHANGED => audit_t($locale, 'directory_config_changed', ['action' => $actionLabel, 'revision_part' => isset($context['revision']) ? audit_t($locale, 'revision_part', ['revision' => (string) $context['revision']]) : '']),
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONTROLLER_CHANGED => audit_directory_controller_description($id, $context, $locale),
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_LOGIN_CHANGED => audit_t($locale, 'directory_login_changed', ['state' => audit_bool_word((bool) ($context['enabled'] ?? false), $locale)]),
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONTROLLER_STATE => audit_t($locale, 'directory_controller_state', ['id' => $id, 'outcome' => (string) ($context['outcome'] ?? $unknown)]),
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONTROLLER_TESTED => audit_t($locale, 'directory_controller_tested', ['id' => $id, 'outcome' => (string) ($context['outcome'] ?? $result)]),
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_BIND_REJECTED => audit_t($locale, 'directory_bind_rejected'),
        VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_CHANGED => audit_t($locale, 'credential_changed', [
            'action' => $actionLabel,
            'id' => $id,
            'named' => audit_description_named($context, $locale),
            'host_part' => isset($context['host']) ? audit_t($locale, 'host_part', ['host' => (string) $context['host']]) : '',
            'changes_part' => audit_description_changes($context, $locale),
            'selection_part' => !empty($context['selection_cleared']) ? audit_t($locale, 'selection_part') : '',
        ]),
        VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_TESTED => audit_t($locale, !empty($context['scheduled']) ? 'credential_test_scheduled' : 'credential_tested', [
            'id' => $id,
            'outcome' => (string) ($context['outcome'] ?? $result),
            'component_part' => audit_description_component($context, $locale),
        ]),
        VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_INVENTORY_AUTOMATION => audit_t($locale, 'credential_inventory_automation', ['action' => $actionLabel, 'id' => $id, 'reason_part' => audit_description_reason($context, $locale)]),
        VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_APP_KEY_ROTATED => audit_t($locale, 'app_key_rotated', [
            'credential_count' => (string) ($context['credential_count'] ?? 0),
            'directory_count' => (string) ($context['directory_count'] ?? 0),
            'already_count' => (string) ($context['already_count'] ?? 0),
        ]),
        VIRTUSPHERE_AUDIT_EVENT_MISSION_CHANGED => audit_t($locale, 'mission_changed', [
            'action' => $actionLabel,
            'id' => $id,
            'named' => audit_description_named($context, $locale),
            'vm_part' => isset($context['vm_count']) ? audit_t($locale, 'vm_part', ['count' => (string) $context['vm_count']]) : '',
            'changes_part' => audit_description_changes($context, $locale),
        ]),
        VIRTUSPHERE_AUDIT_EVENT_MISSION_TRANSFERRED => audit_mission_transfer_description($id, $context, $locale),
        VIRTUSPHERE_AUDIT_EVENT_MISSION_LIST_EXPORTED => audit_t($locale, 'mission_list_exported', ['id' => $id, 'count' => (string) ($context['row_count'] ?? 0)]),
        VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED => audit_t($locale, 'vm_changed', [
            'action' => $actionLabel,
            'id' => $id,
            'named' => audit_description_named($context, $locale),
            'mission' => (string) ($context['mission_id'] ?? 0),
            'changes_part' => audit_description_changes($context, $locale),
        ]),
        VIRTUSPHERE_AUDIT_EVENT_VM_MECM_CHANGED => audit_t($locale, 'vm_mecm_changed', [
            'action' => $actionLabel,
            'id' => $id,
            'mission' => (string) ($context['mission_id'] ?? 0),
            'progress_part' => isset($context['progress_kind']) ? audit_t($locale, 'progress_part', ['kind' => (string) $context['progress_kind']]) : '',
        ]),
        VIRTUSPHERE_AUDIT_EVENT_VM_BULK_CHANGED => audit_t($locale, 'vm_bulk_changed', [
            'action' => $actionLabel,
            'count' => (string) ($context['affected_count'] ?? 0),
            'id' => $id,
            'ids_part' => audit_description_ids($context['vm_ids'] ?? []),
        ]),
        VIRTUSPHERE_AUDIT_EVENT_VM_LIST_EXPORTED => audit_t($locale, 'vm_list_exported', ['id' => $id, 'count' => (string) ($context['row_count'] ?? 0)]),
        VIRTUSPHERE_AUDIT_EVENT_VM_IDENTITY_ADOPTED => audit_t($locale, 'vm_identity_adopted', [
            'id' => $id,
            'credential' => (string) ($context['credential_id'] ?? 0),
            'moid' => (string) ($context['moid'] ?? ''),
            'instance_uuid' => (string) ($context['instance_uuid'] ?? ''),
        ]),
        // Etappe 14D. The effect is the load-bearing half: `current_pending`
        // means the rollout that is waiting picked the new name up, while
        // `next_rollout` means it did not and will not until a reset.
        VIRTUSPHERE_AUDIT_EVENT_VM_ROLLOUT_HOSTNAME => audit_t($locale, 'vm_rollout_hostname', [
            'id' => $id,
            'mission' => (string) ($context['mission_id'] ?? 0),
            'old_value' => (string) ($context['old_value'] ?? ''),
            'new_value' => (string) ($context['new_value'] ?? ''),
            'effect' => (string) ($context['effect'] ?? ''),
            'rollout_hostname' => (string) ($context['rollout_hostname'] ?? ''),
            'rollout_revision' => (string) ($context['rollout_revision'] ?? 0),
        ]),
        VIRTUSPHERE_AUDIT_EVENT_CATALOG_ITEM_DELETED => audit_t($locale, 'catalog_item_deleted', ['type' => audit_token_label($locale, 'object_', $objectType), 'id' => $id, 'named' => audit_description_named($context, $locale)]),
        VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED => audit_setting_description($id, $context, $locale),
        VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CERT_INSTALLED => audit_t($locale, 'setting_cert_installed', ['subject' => (string) ($context['subject'] ?? ''), 'valid_to' => (string) ($context['valid_to'] ?? '')]),
        VIRTUSPHERE_AUDIT_EVENT_SETTINGS_REPORT_TOKEN => audit_t($locale, $action === 'generated' ? 'report_token_generated' : 'report_token_cleared'),
        VIRTUSPHERE_AUDIT_EVENT_SETTINGS_MACHINE_IP => audit_t($locale, 'machine_ip', [
            'action' => $actionLabel,
            'id' => $id,
            'role_part' => isset($context['role']) ? audit_t($locale, 'role_part', [
                'role' => (string) $context['role'],
                'previous_part' => ($context['previous_role'] ?? '') !== '' ? audit_t($locale, 'previous_part', ['previous' => (string) ($context['previous_role'] ?? '')]) : '',
            ]) : '',
        ]),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_QUEUED => audit_t($locale, 'deploy_queued', [
            'label' => audit_deploy_object_label($objectType, $id, $locale),
            'job_count_part' => isset($context['job_count']) ? audit_t($locale, 'job_count_part', ['count' => (string) $context['job_count']]) : '',
            'scheduled_part' => !empty($context['scheduled']) ? audit_t($locale, 'scheduled_part') : '',
        ]),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCEL_REQUESTED => audit_t($locale, 'deploy_cancel_requested', ['id' => $id]),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_LOG_EXPORTED => audit_t($locale, 'deploy_log_exported', ['id' => $id]),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCELLED => audit_t($locale, 'deploy_cancelled', [
            'action' => $actionLabel,
            'label' => audit_deploy_object_label($objectType, $id, $locale),
            'job_count_part' => isset($context['job_count']) ? audit_t($locale, 'job_count_part', ['count' => (string) $context['job_count']]) : '',
        ]),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_RETRIED => audit_t($locale, 'deploy_retried', ['id' => $id, 'retry_of' => (string) ($context['retry_of_job_id'] ?? 0)]),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_OUTCOME => audit_t($locale, 'deploy_outcome', [
            'id' => $id,
            'mission' => (string) ($context['mission_id'] ?? 0),
            'mode' => (string) ($context['mode'] ?? ''),
            'status' => (string) ($context['status'] ?? $result),
            'reason_part' => audit_description_reason($context, $locale),
        ]),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_INVENTORY_REQUESTED => audit_t($locale, 'inventory_requested', [
            'id' => $id,
            'reason_part' => audit_description_reason($context, $locale),
            'job_part' => isset($context['job_id']) ? audit_t($locale, 'job_part', ['job' => (string) $context['job_id']]) : '',
        ]),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_INVENTORY_REFRESH => audit_inventory_refresh_description($context, $locale),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_VLAN_REASSIGNED => audit_t($locale, 'vlan_reassigned', [
            'id' => $id,
            'target_vlan' => (string) ($context['target_vlan'] ?? ''),
            'missions' => (string) ($context['mission_count'] ?? 0),
            'interfaces' => (string) ($context['interface_count'] ?? 0),
        ]),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CONVERGENCE => audit_t($locale, 'convergence_sweep', ['count' => (string) ($context['vm_count'] ?? 0), 'id' => $id, 'reason_part' => audit_description_reason($context, $locale)]),
        // The compatibility description names the state that was REACHED, not
        // the button that was pressed: a pause requested while a job runs lands
        // on pause_after_current, and a trail saying "paused" there would be
        // wrong for as long as that job kept running.
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CLAIM_PAUSED => audit_t($locale, 'claim_paused', [
            'state' => (string) ($context['new_state'] ?? 'paused'),
            'claim_job_part' => isset($context['job_id']) ? audit_t($locale, 'claim_job_part', ['job' => (string) $context['job_id']]) : '',
        ]),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CLAIM_RESUMED => audit_t($locale, 'claim_resumed'),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CLEANUP_RETRIED => audit_t(
            $locale,
            $result === VIRTUSPHERE_AUDIT_RESULT_SUCCESS ? 'cleanup_retry_queued' : 'cleanup_retry_refused',
            ['id' => $id, 'execution' => (string) ($context['execution_id'] ?? 0)]
        ),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_REMOTE_SECRET_CLEARED => audit_t($locale, 'secret_cleared', [
            'id' => $id,
            'outcome_part' => ($context['outcome'] ?? '') === 'absent' ? audit_t($locale, 'secret_absent') : audit_t($locale, 'secret_removed'),
            'address' => (string) ($context['host'] ?? $unknown) . ':' . (string) ($context['port'] ?? 0),
        ]),
        // K21-F. The object is the credential; 0 names one that was deleted. The
        // operator's reason stays in the context, not in this sentence.
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_REMOTE_SECRET_ACKNOWLEDGED => audit_t($locale, 'secret_acknowledged', [
            'count' => (string) ($context['count'] ?? 0),
            'address' => (string) ($context['host'] ?? $unknown) . ':' . (string) ($context['port'] ?? 0),
            'credential_part' => $id === '0' ? audit_t($locale, 'credential_deleted_part') : audit_t($locale, 'credential_id_part', ['id' => $id]),
        ]),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_RECOVERY_DOCUMENTED => audit_t($locale, 'recovery_documented', [
            'id' => $id,
            'resolution_code' => (string) ($context['resolution_code'] ?? audit_t($locale, 'inconclusive')),
            'resolution_id' => (string) ($context['resolution_id'] ?? 0),
        ]),
        // Etappe 14B-F. The sentence says what the operator ESTABLISHED, never
        // what this system checked: it cannot see the host, and a line reading
        // "verified" would claim a probe nobody ran.
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CREATE_RELEASED => $result === VIRTUSPHERE_AUDIT_RESULT_SUCCESS
            ? audit_t($locale, 'create_released', ['position' => (string) ($context['position'] ?? 0), 'id' => $id, 'resolution_id' => (string) ($context['resolution_id'] ?? 0)])
            : audit_t($locale, 'create_refused', ['position' => (string) ($context['position'] ?? 0), 'id' => $id, 'blocker' => (string) ($context['blocker'] ?? $unknown)]),
        // Etappe 14C. A refusal names the ONE condition it stopped at, because
        // that condition is the operator's next task; "not ready" would not be.
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_SUPERVISOR_CONTRACT => $result === VIRTUSPHERE_AUDIT_RESULT_SUCCESS
            ? audit_t($locale, 'supervisor_switched', ['previous' => (string) ($context['previous_contract'] ?? $unknown), 'target' => (string) ($context['target_contract'] ?? $unknown)])
            : audit_t($locale, 'supervisor_refused', ['target' => (string) ($context['target_contract'] ?? $unknown), 'blocker' => (string) ($context['blocker'] ?? $unknown)]),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_RECOVERY_REVIEWED => audit_t($locale, 'recovery_reviewed', [
            'reviewed' => (string) ($context['reviewed_count'] ?? 0),
            'requested' => (string) ($context['requested_count'] ?? 0),
            'manual' => (string) ($context['manual_count'] ?? 0),
        ]),
        VIRTUSPHERE_AUDIT_EVENT_INTEGRATION_STATE => audit_t($locale, 'integration_state', [
            'id' => $id,
            'old_state' => (string) ($context['old_state'] ?? $unknown),
            'new_state' => (string) ($context['new_state'] ?? $unknown),
        ]),
        VIRTUSPHERE_AUDIT_EVENT_SYSTEM_ERROR => audit_t($locale, 'system_error', ['id' => $id, 'error_class' => (string) ($context['error_class'] ?? 'Throwable')]),
        VIRTUSPHERE_AUDIT_EVENT_SYSTEM_RETENTION_PURGED => audit_t($locale, 'retention_purge', ['items' => audit_description_items($context, $locale)]),
        VIRTUSPHERE_AUDIT_EVENT_LOGS_CSV_EXPORTED => audit_logs_export_description($id, $context, $locale),
        VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_DENIED => audit_machine_api_denied($id, $context, $locale),
        VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_CALLBACK_REJECTED => audit_t($locale, 'callback_rejected', ['id' => $id, 'reason_code' => (string) ($context['reason_code'] ?? audit_t($locale, 'conflict'))]),
        VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_TOUCH_REFUSED => audit_t($locale, 'touch_refused', ['id' => $id, 'reason_code' => (string) ($context['reason_code'] ?? audit_t($locale, 'refused'))]),
        VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_FAILURE => audit_t($locale, 'machine_endpoint_failed', ['id' => $id, 'error_class' => (string) ($context['error_class'] ?? 'Throwable')]),
        VIRTUSPHERE_AUDIT_EVENT_MECM_UNKNOWN_VM => audit_t($locale, 'unknown_vm', [
            'report' => (string) ($context['report_type'] ?? audit_t($locale, 'report')),
            'id' => $id,
            'resource_part' => isset($context['resource_id']) ? audit_t($locale, 'resource_part', ['resource' => (string) $context['resource_id']]) : '',
        ]),
        // Etappe 14D. Reported revision 0 means the caller sent none at all,
        // which is the pre-cutover script or client; it is a different finding
        // from an outdated number and reads as such.
        VIRTUSPHERE_AUDIT_EVENT_MECM_ROLLOUT_REVISION_REFUSED => audit_t($locale, 'rollout_revision_refused', [
            'report' => (string) ($context['report_type'] ?? audit_t($locale, 'report')),
            'id' => $id,
            'reported' => (string) ($context['reported_revision'] ?? 0),
            'current' => (string) ($context['rollout_revision'] ?? 0),
        ]),
        VIRTUSPHERE_AUDIT_EVENT_MECM_CATALOG_REJECTED => audit_t($locale, 'catalog_rejected', [
            'id' => $id,
            'retire' => (string) ($context['retire_count'] ?? 0),
            'active' => (string) ($context['active_count'] ?? 0),
            'threshold' => (string) ($context['threshold_percent'] ?? 0),
        ]),
        VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGES_RELINKED => audit_t($locale, 'packages_relinked', ['items' => audit_description_items($context, $locale)]),
        VIRTUSPHERE_AUDIT_EVENT_MECM_CATALOG_CHANGED => audit_t($locale, 'catalog_changed', [
            'id' => $id,
            'created' => (string) ($context['created_count'] ?? 0),
            'retired' => (string) ($context['retired_count'] ?? 0),
        ]),
        VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGES_RELINK_SKIPPED => audit_t($locale, 'relink_skipped', ['items' => audit_description_items($context, $locale)]),
        VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGE_RETIRED_ASSIGNED => audit_t($locale, 'retired_assigned', ['items' => audit_description_items($context, $locale)]),
        VIRTUSPHERE_AUDIT_EVENT_MECM_REPORT_TOKEN_REJECTED => audit_t($locale, 'report_token_rejected', ['action' => (string) ($context['action'] ?? audit_t($locale, 'report'))]),
        VIRTUSPHERE_AUDIT_EVENT_MECM_CLIENT_CAP => audit_t($locale, 'client_cap', ['id' => $id]),
        VIRTUSPHERE_AUDIT_EVENT_MECM_REPORTER_UPGRADED => audit_t($locale, 'reporter_upgraded', ['id' => $id, 'version' => (string) ($context['report_version'] ?? VIRTUSPHERE_REPORT_CHANNEL_VERSION)]),
        default => throw new LogicException('No audit description for event ' . $eventCode),
    };
    $message .= audit_description_throttle($context, $locale);

    return virtusphere_log_text_bytes(virtusphere_redact_log_text($message), VIRTUSPHERE_AUDIT_MESSAGE_MAX_BYTES);
}
