<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_registry.php';

/** @param array<string,mixed> $context */
function audit_event_description(string $eventCode, string $objectType, ?string $objectId, string $result, array $context): string
{
    $id = $objectId ?? 'unknown';
    $action = (string) ($context['action'] ?? 'changed');
    $message = match ($eventCode) {
        VIRTUSPHERE_AUDIT_EVENT_AUTH_CSRF_REJECTED => 'csrf token rejected on ' . ($context['page'] ?? $id),
        VIRTUSPHERE_AUDIT_EVENT_AUTH_ACCESS_DENIED => 'access denied: ' . ($context['permission'] ?? $id) . ' required',
        VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGOUT => 'logout',
        // The result, not the code, says whether it happened: `changed own
        // password` on success, `rejected own password change` otherwise. One
        // neutral code carries both, so a refusal can never be filed under a
        // name that asserts the change went through.
        VIRTUSPHERE_AUDIT_EVENT_AUTH_PASSWORD_CHANGE_ATTEMPT => $result === VIRTUSPHERE_AUDIT_RESULT_SUCCESS
            ? 'changed ' . ($context['scope'] ?? 'own') . ' password'
            : 'rejected ' . ($context['scope'] ?? 'own') . ' password change' . audit_description_reason($context),
        VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN => audit_login_description($id, $result, $context),
        VIRTUSPHERE_AUDIT_EVENT_AUTH_ACCOUNT_LOCKED => 'account locked for ' . ($context['duration_minutes'] ?? 0) . ' minutes after ' . ($context['failure_count'] ?? 0) . ' failed sign-ins',
        VIRTUSPHERE_AUDIT_EVENT_AUTH_IP_RATE_LIMITED => 'ip rate limited for ' . ($context['duration_minutes'] ?? 0) . ' minutes after ' . ($context['failure_count'] ?? 0) . ' failed sign-ins',
        VIRTUSPHERE_AUDIT_EVENT_AUTH_SESSION_ENDED => audit_session_description($context),
        VIRTUSPHERE_AUDIT_EVENT_USER_CREATED => 'created ' . ($context['source'] ?? 'local') . ' user id ' . $id . audit_description_named($context),
        VIRTUSPHERE_AUDIT_EVENT_USER_ACTIVE_CHANGED => 'changed active state for user id ' . $id . ' to ' . audit_bool_word((bool) ($context['enabled'] ?? false)),
        VIRTUSPHERE_AUDIT_EVENT_USER_ROLE_CHANGED => 'changed role for user id ' . $id . ' to ' . ($context['role'] ?? 'unknown'),
        VIRTUSPHERE_AUDIT_EVENT_USER_SECURITY_CHANGED => $action . ' for local user id ' . $id,
        VIRTUSPHERE_AUDIT_EVENT_USER_DIRECTORY_IMPORTED => 'imported Active Directory user id ' . $id,
        VIRTUSPHERE_AUDIT_EVENT_USER_DIRECTORY_SYNCED => 'synchronized Active Directory user id ' . $id,
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONFIG_CHANGED => $action . ' directory configuration' . (isset($context['revision']) ? ' revision ' . $context['revision'] : ''),
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONTROLLER_CHANGED => audit_directory_controller_description($id, $context),
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_LOGIN_CHANGED => audit_bool_word((bool) ($context['enabled'] ?? false)) . ' Active Directory login',
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONTROLLER_STATE => 'directory controller ' . $id . ' state changed to ' . ($context['outcome'] ?? 'unknown'),
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONTROLLER_TESTED => 'tested directory controller ' . $id . ': ' . ($context['outcome'] ?? $result),
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_BIND_REJECTED => 'directory search account bind rejected; automatic attempts paused',
        VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_CHANGED => $action . ' credential id ' . $id . audit_description_changes($context)
            . (!empty($context['selection_cleared']) ? '; inventory ansible selection cleared' : ''),
        VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_TESTED => 'tested credential id ' . $id . ': ' . ($context['outcome'] ?? $result) . audit_description_component($context),
        VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_INVENTORY_AUTOMATION => 'esxi inventory auto-pull ' . $action . ' for credential id ' . $id . audit_description_reason($context),
        VIRTUSPHERE_AUDIT_EVENT_MISSION_CHANGED => $action . ' mission id ' . $id . audit_description_named($context)
            . (isset($context['vm_count']) ? ' (' . $context['vm_count'] . ' vms)' : '') . audit_description_changes($context),
        VIRTUSPHERE_AUDIT_EVENT_MISSION_TRANSFERRED => audit_mission_transfer_description($id, $context),
        VIRTUSPHERE_AUDIT_EVENT_MISSION_LIST_EXPORTED => 'exported ' . $id . ' list as CSV (' . ($context['row_count'] ?? 0) . ' row(s))',
        VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED => $action . ' vm id ' . $id . ' in mission id ' . ($context['mission_id'] ?? 0) . audit_description_changes($context),
        VIRTUSPHERE_AUDIT_EVENT_VM_MECM_CHANGED => $action . ' for vm id ' . $id . ' in mission id ' . ($context['mission_id'] ?? 0) . (isset($context['progress_kind']) ? ' (' . $context['progress_kind'] . ')' : ''),
        VIRTUSPHERE_AUDIT_EVENT_VM_BULK_CHANGED => $action . ' ' . ($context['affected_count'] ?? 0) . ' vm(s) in mission id ' . $id . audit_description_ids($context['vm_ids'] ?? []),
        VIRTUSPHERE_AUDIT_EVENT_VM_LIST_EXPORTED => 'exported vm list of mission id ' . $id . ' as CSV (' . ($context['row_count'] ?? 0) . ' row(s))',
        VIRTUSPHERE_AUDIT_EVENT_VM_IDENTITY_ADOPTED => 'adopted ESXi identity for vm id ' . $id . ' from credential id ' . ($context['credential_id'] ?? 0) . ' (moid ' . ($context['moid'] ?? '') . ', instance uuid ' . ($context['instance_uuid'] ?? '') . ')',
        VIRTUSPHERE_AUDIT_EVENT_CATALOG_ITEM_DELETED => 'deleted retired ' . $objectType . ' id ' . $id,
        VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED => audit_setting_description($id, $context),
        VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CERT_INSTALLED => 'installed https certificate (CN=' . ($context['subject'] ?? '') . ', expires ' . ($context['valid_to'] ?? '') . ' UTC)',
        VIRTUSPHERE_AUDIT_EVENT_SETTINGS_REPORT_TOKEN => ($action === 'generated' ? 'generated' : 'cleared') . ' machine report token',
        VIRTUSPHERE_AUDIT_EVENT_SETTINGS_MACHINE_IP => $action . ' machine API ip ' . $id,
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_QUEUED => 'queued ' . audit_deploy_object_label($objectType, $id) . (isset($context['job_count']) ? ' (' . $context['job_count'] . ' jobs)' : '') . (!empty($context['scheduled']) ? ' (scheduled)' : ''),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCEL_REQUESTED => 'requested cancel of deploy job id ' . $id,
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCELLED => $action . ' ' . audit_deploy_object_label($objectType, $id) . (isset($context['job_count']) ? ' (' . $context['job_count'] . ' jobs)' : ''),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_RETRIED => 'queued deploy job id ' . $id . ' (retry of job id ' . ($context['retry_of_job_id'] ?? 0) . ')',
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_OUTCOME => 'deploy job id ' . $id . ' (mission id ' . ($context['mission_id'] ?? 0) . ', mode ' . ($context['mode'] ?? '') . ') ' . ($context['status'] ?? $result) . audit_description_reason($context),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_INVENTORY_REQUESTED => 'requested ESXi inventory pull for credential id ' . $id . audit_description_reason($context) . (isset($context['job_id']) ? '; job id ' . $context['job_id'] : ''),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_INVENTORY_REFRESH => audit_inventory_refresh_description($context),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_VLAN_REASSIGNED => 'reassigned vlan ' . $id . ' to ' . ($context['target_vlan'] ?? '') . ' (' . ($context['mission_count'] ?? 0) . ' missions, ' . ($context['interface_count'] ?? 0) . ' interfaces)',
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CONVERGENCE => '[maintenance-worker] convergence sweep marked ' . ($context['vm_count'] ?? 0) . ' VM(s) of mission id ' . $id . ' as failed' . audit_description_reason($context),
        // The compatibility description names the state that was REACHED, not
        // the button that was pressed: a pause requested while a job runs lands
        // on pause_after_current, and a trail saying "paused" there would be
        // wrong for as long as that job kept running.
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CLAIM_PAUSED => 'deploy job intake set to ' . ($context['new_state'] ?? 'paused')
            . (isset($context['job_id']) ? ' while job id ' . $context['job_id'] . ' was active' : ''),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CLAIM_RESUMED => 'deploy job intake resumed',
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CLEANUP_RETRIED => ($result === VIRTUSPHERE_AUDIT_RESULT_SUCCESS ? 'queued' : 'refused')
            . ' remote cleanup retry for deploy job id ' . $id . ' (execution ' . ($context['execution_id'] ?? 0) . ')',
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_RECOVERY_DOCUMENTED => 'documented external check for deploy job id ' . $id
            . ': ' . ($context['resolution_code'] ?? 'inconclusive') . ' (resolution ' . ($context['resolution_id'] ?? 0) . ')',
        // Etappe 14B-F. The sentence says what the operator ESTABLISHED, never
        // what this system checked: it cannot see the host, and a line reading
        // "verified" would claim a probe nobody ran.
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CREATE_RELEASED => ($result === VIRTUSPHERE_AUDIT_RESULT_SUCCESS
            ? 'released create unit ' . ($context['position'] ?? 0) . ' of deploy job id ' . $id
                . ' as not created (resolution ' . ($context['resolution_id'] ?? 0) . ')'
            : 'refused to release create unit ' . ($context['position'] ?? 0) . ' of deploy job id ' . $id
                . ': ' . ($context['blocker'] ?? 'unknown')),
        // Etappe 14C. A refusal names the ONE condition it stopped at, because
        // that condition is the operator's next task; "not ready" would not be.
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_SUPERVISOR_CONTRACT => ($result === VIRTUSPHERE_AUDIT_RESULT_SUCCESS
            ? 'switched the deploy process contract from ' . ($context['previous_contract'] ?? 'unknown')
                . ' to ' . ($context['target_contract'] ?? 'unknown')
            : 'refused to switch the deploy process contract to ' . ($context['target_contract'] ?? 'unknown')
                . ': ' . ($context['blocker'] ?? 'unknown')),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_RECOVERY_REVIEWED => 'recovery review over ' . ($context['reviewed_count'] ?? 0)
            . ' stale job(s): ' . ($context['requested_count'] ?? 0) . ' requested, ' . ($context['manual_count'] ?? 0) . ' left for manual review',
        VIRTUSPHERE_AUDIT_EVENT_INTEGRATION_STATE => '[maintenance-worker] integration ' . $id . ' state ' . ($context['old_state'] ?? 'unknown') . ' -> ' . ($context['new_state'] ?? 'unknown'),
        VIRTUSPHERE_AUDIT_EVENT_SYSTEM_ERROR => 'error [' . $id . '] ' . ($context['error_class'] ?? 'Throwable'),
        VIRTUSPHERE_AUDIT_EVENT_LOGS_CSV_EXPORTED => audit_logs_export_description($id, $context),
        VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_DENIED => 'Refused ' . $id . (isset($context['action']) ? '?action=' . $context['action'] : '') . ': not on the machine API IP allowlist (and no known MAC presented).',
        VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_CALLBACK_REJECTED => 'MAC callback rejected for job id ' . $id . ': ' . ($context['reason_code'] ?? 'conflict'),
        VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_FAILURE => 'Machine endpoint ' . $id . ' failed internally (' . ($context['error_class'] ?? 'Throwable') . ')',
        VIRTUSPHERE_AUDIT_EVENT_MECM_UNKNOWN_VM => ($context['report_type'] ?? 'report') . ' for unknown VM id ' . $id . (isset($context['resource_id']) ? ' (ResourceID ' . $context['resource_id'] . ')' : ''),
        VIRTUSPHERE_AUDIT_EVENT_MECM_CATALOG_REJECTED => 'Catalog sync rejected for ' . $id . ': payload would retire ' . ($context['retire_count'] ?? 0) . ' of ' . ($context['active_count'] ?? 0) . ' active entries (threshold ' . ($context['threshold_percent'] ?? 0) . '%).',
        VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGES_RELINKED => '[mecm_packages] package relink: ' . audit_description_items($context),
        VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGES_RELINK_SKIPPED => '[mecm_packages] retired without relink (no newer version in this payload): ' . audit_description_items($context),
        VIRTUSPHERE_AUDIT_EVENT_MECM_REPORT_TOKEN_REJECTED => 'Rejected ' . ($context['action'] ?? 'report') . ' with invalid token',
        VIRTUSPHERE_AUDIT_EVENT_MECM_CLIENT_CAP => 'Daily client event cap reached for vm ' . $id,
        VIRTUSPHERE_AUDIT_EVENT_MECM_REPORTER_UPGRADED => 'reporter ' . $id . ' switched from legacy heartbeats to V' . ($context['report_version'] ?? VIRTUSPHERE_REPORT_CHANNEL_VERSION) . ' result reports',
        default => throw new LogicException('No audit description for event ' . $eventCode),
    };
    $message .= audit_description_throttle($context);

    return virtusphere_log_text_bytes(virtusphere_redact_log_text($message), VIRTUSPHERE_AUDIT_MESSAGE_MAX_BYTES);
}

function audit_bool_word(bool $enabled): string
{
    return $enabled ? 'enabled' : 'disabled';
}

/**
 * How a deploy job and a deploy group name themselves in a description.
 *
 * They differ, and the difference is not cosmetic: the job carries "id" and the
 * group does not, because that is what these lines have said since the deploy
 * queue shipped, and existing operator filters and E2E assertions match on it.
 * A structured rewrite may change what a row STORES; changing what a decade of
 * saved searches read would be a migration of somebody else's data.
 */
function audit_deploy_object_label(string $objectType, string $id): string
{
    return $objectType === 'deploy_group' ? 'deploy group ' . $id : 'deploy job id ' . $id;
}

/** @param array<string,mixed> $context */
function audit_login_description(string $id, string $result, array $context): string
{
    $source = (string) ($context['source'] ?? 'local');
    if ($result === VIRTUSPHERE_AUDIT_RESULT_SUCCESS) {
        return 'login succeeded (' . $source . (isset($context['controller_id']) ? ', controller ' . $context['controller_id'] : '') . ')';
    }
    $reason = (string) ($context['reason'] ?? 'invalid credentials');
    $user = isset($context['username']) ? ' for user "' . $context['username'] . '"' : ($id !== 'unknown' ? ' for user id ' . $id : '');
    return 'login rejected' . $user . ': ' . $reason;
}

/** @param array<string,mixed> $context */
function audit_session_description(array $context): string
{
    $reason = (string) ($context['reason'] ?? 'ended');
    return $reason === 'expired' && isset($context['duration_seconds'])
        ? 'session expired after ' . $context['duration_seconds'] . ' seconds'
        : 'Active Directory session ended: ' . $reason;
}

/** @param array<string,mixed> $context */
function audit_directory_controller_description(string $id, array $context): string
{
    $action = (string) ($context['action'] ?? 'changed');
    $suffix = isset($context['host']) ? ' (' . $context['host'] . ':' . ($context['port'] ?? 0) . ')' : '';
    return $action . ' directory controller ' . $id . $suffix;
}

/** @param array<string,mixed> $context */
function audit_mission_transfer_description(string $id, array $context): string
{
    $action = (string) ($context['action'] ?? 'exported');
    if (isset($context['target_mission_id'])) {
        return $action . ' mission id ' . $id . ' to mission id ' . $context['target_mission_id'];
    }
    return $action . ' mission id ' . $id . audit_description_named($context);
}

/** @param array<string,mixed> $context */
function audit_setting_description(string $id, array $context): string
{
    $action = (string) ($context['action'] ?? 'updated');
    if (array_key_exists('enabled', $context)) {
        return audit_bool_word((bool) $context['enabled']) . ' ' . str_replace('_', ' ', $id)
            . (!empty($context['redirect_disabled']) ? ' (redirect auto-disabled)' : '');
    }
    $change = isset($context['old_value'], $context['new_value']) ? ' ' . $context['old_value'] . ' -> ' . $context['new_value'] : '';
    return $action . ' ' . str_replace('_', ' ', $id) . $change;
}

/** @param array<string,mixed> $context */
function audit_inventory_refresh_description(array $context): string
{
    return 'requested ESXi inventory refresh targets' . audit_description_ids($context['target_ids'] ?? [])
        . ' jobs' . audit_description_ids($context['job_ids'] ?? [])
        . ' (' . ($context['queued_count'] ?? 0) . ' queued, ' . ($context['open_count'] ?? 0) . ' open, '
        . ($context['paused_count'] ?? 0) . ' paused, ' . ($context['failed_count'] ?? 0) . ' failed)';
}

/** @param array<string,mixed> $context */
function audit_logs_export_description(string $tab, array $context): string
{
    $message = 'exported logs tab ' . $tab . ' as CSV (' . ($context['rows_exported'] ?? 0) . ' of ' . ($context['total_rows'] ?? 0) . ' row(s))';
    return !empty($context['truncated']) ? $message . '; truncated at ' . ($context['limit'] ?? 0) : $message;
}

/** @param array<string,mixed> $context */
function audit_description_reason(array $context): string
{
    return isset($context['reason']) && $context['reason'] !== '' ? ': ' . $context['reason'] : '';
}

/** @param array<string,mixed> $context */
function audit_description_named(array $context): string
{
    return isset($context['name']) && $context['name'] !== '' ? ' ("' . $context['name'] . '")' : '';
}

/** @param array<string,mixed> $context */
function audit_description_changes(array $context): string
{
    if (isset($context['changes']) && $context['changes'] !== '') {
        return ' (' . $context['changes'] . ')';
    }

    // An update that changed nothing says so rather than reading as an
    // unexplained write: under optimistic locking a no-op save is a real,
    // recorded event, and silence would look like a missing diff.
    return ($context['action'] ?? '') === 'updated' ? ' (no field changes)' : '';
}

/** @param array<string,mixed> $context */
function audit_description_component(array $context): string
{
    return isset($context['component']) && $context['component'] !== '' ? ' (' . $context['component'] . ')' : '';
}

/** @param list<int> $ids */
function audit_description_ids(array $ids): string
{
    return ' [' . implode(',', $ids) . ']';
}

/** @param array<string,mixed> $context */
function audit_description_items(array $context): string
{
    $items = is_array($context['items'] ?? null) ? $context['items'] : [];
    return $items !== [] ? implode('; ', $items) : (string) ($context['item_count'] ?? 0) . ' item(s)';
}

/** @param array<string,mixed> $context */
function audit_description_throttle(array $context): string
{
    $suppressed = (int) ($context['suppressed_count'] ?? 0);
    if ($suppressed <= 0) {
        return '';
    }
    return ' (' . $suppressed . ' further occurrence(s) suppressed in the last ' . ($context['throttle_seconds'] ?? 0) . ' s)';
}
