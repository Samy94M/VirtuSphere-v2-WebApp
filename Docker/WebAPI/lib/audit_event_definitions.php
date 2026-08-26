<?php

declare(strict_types=1);

const VIRTUSPHERE_AUDIT_EVENT_AUTH_CSRF_REJECTED = 'auth.csrf_rejected';
const VIRTUSPHERE_AUDIT_EVENT_AUTH_ACCESS_DENIED = 'auth.access_denied';
const VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGOUT = 'auth.logout';
const VIRTUSPHERE_AUDIT_EVENT_AUTH_PASSWORD_CHANGE_ATTEMPT = 'auth.password_change_attempt';
const VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN = 'auth.login';
const VIRTUSPHERE_AUDIT_EVENT_AUTH_ACCOUNT_LOCKED = 'auth.account_locked';
const VIRTUSPHERE_AUDIT_EVENT_AUTH_IP_RATE_LIMITED = 'auth.ip_rate_limited';
const VIRTUSPHERE_AUDIT_EVENT_AUTH_SESSION_ENDED = 'auth.session_ended';
const VIRTUSPHERE_AUDIT_EVENT_USER_CREATED = 'user.created';
const VIRTUSPHERE_AUDIT_EVENT_USER_ACTIVE_CHANGED = 'user.active_state_changed';
const VIRTUSPHERE_AUDIT_EVENT_USER_ROLE_CHANGED = 'user.role_changed';
const VIRTUSPHERE_AUDIT_EVENT_USER_SECURITY_CHANGED = 'user.security_state_changed';
const VIRTUSPHERE_AUDIT_EVENT_USER_DIRECTORY_IMPORTED = 'user.directory_imported';
const VIRTUSPHERE_AUDIT_EVENT_USER_DIRECTORY_SYNCED = 'user.directory_synchronized';
const VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONFIG_CHANGED = 'directory.configuration_changed';
const VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONTROLLER_CHANGED = 'directory.controller_changed';
const VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_LOGIN_CHANGED = 'directory.login_changed';
const VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONTROLLER_STATE = 'directory.controller_state_changed';
const VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONTROLLER_TESTED = 'directory.controller_tested';
const VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_BIND_REJECTED = 'directory.bind_rejected';
const VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_CHANGED = 'credential.changed';
const VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_TESTED = 'credential.tested';
const VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_INVENTORY_AUTOMATION = 'credential.inventory_automation_changed';
const VIRTUSPHERE_AUDIT_EVENT_MISSION_CHANGED = 'mission.changed';
const VIRTUSPHERE_AUDIT_EVENT_MISSION_TRANSFERRED = 'mission.transferred';
const VIRTUSPHERE_AUDIT_EVENT_MISSION_LIST_EXPORTED = 'mission.list_exported';
const VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED = 'vm.changed';
const VIRTUSPHERE_AUDIT_EVENT_VM_MECM_CHANGED = 'vm.mecm_state_changed';
const VIRTUSPHERE_AUDIT_EVENT_VM_BULK_CHANGED = 'vm.bulk_changed';
const VIRTUSPHERE_AUDIT_EVENT_VM_LIST_EXPORTED = 'vm.list_exported';
const VIRTUSPHERE_AUDIT_EVENT_VM_IDENTITY_ADOPTED = 'vm.identity_adopted';
const VIRTUSPHERE_AUDIT_EVENT_CATALOG_ITEM_DELETED = 'catalog.item_deleted';
const VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED = 'settings.changed';
const VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CERT_INSTALLED = 'settings.certificate_installed';
const VIRTUSPHERE_AUDIT_EVENT_SETTINGS_REPORT_TOKEN = 'settings.report_token_changed';
const VIRTUSPHERE_AUDIT_EVENT_SETTINGS_MACHINE_IP = 'settings.machine_ip_allowlist_changed';
const VIRTUSPHERE_AUDIT_EVENT_DEPLOY_QUEUED = 'deploy.queued';
const VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCEL_REQUESTED = 'deploy.cancel_requested';
const VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCELLED = 'deploy.cancelled';
const VIRTUSPHERE_AUDIT_EVENT_DEPLOY_RETRIED = 'deploy.retried';
const VIRTUSPHERE_AUDIT_EVENT_DEPLOY_OUTCOME = 'deploy.outcome';
const VIRTUSPHERE_AUDIT_EVENT_DEPLOY_INVENTORY_REQUESTED = 'deploy.inventory_requested';
const VIRTUSPHERE_AUDIT_EVENT_DEPLOY_INVENTORY_REFRESH = 'deploy.inventory_refresh_requested';
const VIRTUSPHERE_AUDIT_EVENT_DEPLOY_VLAN_REASSIGNED = 'deploy.vlan_reassigned';
const VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CONVERGENCE = 'deploy.convergence_sweep';
const VIRTUSPHERE_AUDIT_EVENT_INTEGRATION_STATE = 'integration.state_changed';
const VIRTUSPHERE_AUDIT_EVENT_SYSTEM_ERROR = 'system.unhandled_error';
const VIRTUSPHERE_AUDIT_EVENT_LOGS_CSV_EXPORTED = 'logs.csv_exported';
const VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_DENIED = 'machine_api.access_denied';
const VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_CALLBACK_REJECTED = 'machine_api.callback_rejected';
const VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_FAILURE = 'machine_api.internal_failure';
const VIRTUSPHERE_AUDIT_EVENT_MECM_UNKNOWN_VM = 'mecm.unknown_vm_reported';
const VIRTUSPHERE_AUDIT_EVENT_MECM_CATALOG_REJECTED = 'mecm.catalog_sync_rejected';
const VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGES_RELINKED = 'mecm.packages_relinked';
const VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGES_RELINK_SKIPPED = 'mecm.packages_relink_skipped';
const VIRTUSPHERE_AUDIT_EVENT_MECM_REPORT_TOKEN_REJECTED = 'mecm.report_token_rejected';
const VIRTUSPHERE_AUDIT_EVENT_MECM_CLIENT_CAP = 'mecm.client_event_cap_reached';
const VIRTUSPHERE_AUDIT_EVENT_MECM_REPORTER_UPGRADED = 'mecm.reporter_upgraded';

/**
 * Registry owner for every persisted first-party event.
 *
 * `required` and `optional` are disjoint. `required_by_result` adds conditional
 * requirements after the result was validated. An absent optional field is the
 * only way to omit it: a present null is invalid.
 *
 * @return array<string,array<string,mixed>>
 */
function audit_event_registry(): array
{
    $success = [VIRTUSPHERE_AUDIT_RESULT_SUCCESS];
    $change = [VIRTUSPHERE_AUDIT_RESULT_SUCCESS, VIRTUSPHERE_AUDIT_RESULT_WARNING];
    $throttle = ['suppressed_count', 'throttle_seconds'];
    // Object-id allowlists for the events whose subject really is a closed set.
    // Where the set is closed, leaving it open would let a typo open a second
    // bucket that reads as a different subject and that no filter lists.
    $endpoints = VIRTUSPHERE_MACHINE_API_ENDPOINTS;
    $catalogs = ['packages', 'task_sequences'];
    $directoryConfig = ['active'];
    $logTabs = array_keys(VIRTUSPHERE_LOG_TABS);

    return [
        VIRTUSPHERE_AUDIT_EVENT_AUTH_CSRF_REJECTED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_AUTH, ['request'], [VIRTUSPHERE_AUDIT_RESULT_DENIED], ['page']),
        VIRTUSPHERE_AUDIT_EVENT_AUTH_ACCESS_DENIED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_AUTH, ['request'], [VIRTUSPHERE_AUDIT_RESULT_DENIED], ['permission']),
        VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGOUT => audit_definition(VIRTUSPHERE_LOG_CATEGORY_AUTH, ['user'], $success, ['source'], [], 'nullable'),
        VIRTUSPHERE_AUDIT_EVENT_AUTH_PASSWORD_CHANGE_ATTEMPT => audit_definition(VIRTUSPHERE_LOG_CATEGORY_AUTH, ['user'], [VIRTUSPHERE_AUDIT_RESULT_SUCCESS, VIRTUSPHERE_AUDIT_RESULT_DENIED], ['scope'], ['reason'], 'required', [VIRTUSPHERE_AUDIT_RESULT_DENIED => ['reason']]),
        VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN => audit_definition(VIRTUSPHERE_LOG_CATEGORY_AUTH, ['user'], [VIRTUSPHERE_AUDIT_RESULT_SUCCESS, VIRTUSPHERE_AUDIT_RESULT_DENIED], ['source'], ['reason', 'username', 'controller_id'], 'nullable', [VIRTUSPHERE_AUDIT_RESULT_DENIED => ['reason']]),
        VIRTUSPHERE_AUDIT_EVENT_AUTH_ACCOUNT_LOCKED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_AUTH, ['user'], [VIRTUSPHERE_AUDIT_RESULT_DENIED], ['duration_minutes', 'failure_count']),
        VIRTUSPHERE_AUDIT_EVENT_AUTH_IP_RATE_LIMITED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_AUTH, ['client_ip'], [VIRTUSPHERE_AUDIT_RESULT_DENIED], ['duration_minutes', 'failure_count']),
        VIRTUSPHERE_AUDIT_EVENT_AUTH_SESSION_ENDED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_AUTH, ['user'], [VIRTUSPHERE_AUDIT_RESULT_SUCCESS, VIRTUSPHERE_AUDIT_RESULT_DENIED], ['reason'], ['duration_seconds']),
        VIRTUSPHERE_AUDIT_EVENT_USER_CREATED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_USERS, ['user'], $success, ['source'], ['name']),
        VIRTUSPHERE_AUDIT_EVENT_USER_ACTIVE_CHANGED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_USERS, ['user'], $success, ['enabled']),
        VIRTUSPHERE_AUDIT_EVENT_USER_ROLE_CHANGED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_USERS, ['user'], $success, ['role']),
        VIRTUSPHERE_AUDIT_EVENT_USER_SECURITY_CHANGED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_USERS, ['user'], $success, ['action']),
        VIRTUSPHERE_AUDIT_EVENT_USER_DIRECTORY_IMPORTED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_USERS, ['user'], $success),
        VIRTUSPHERE_AUDIT_EVENT_USER_DIRECTORY_SYNCED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_USERS, ['user'], $success),
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONFIG_CHANGED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, ['directory_config'], $success, ['action'], ['revision'], 'required', [], [], [], $directoryConfig),
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONTROLLER_CHANGED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, ['directory_controller'], $success, ['action'], ['host', 'port', 'enabled', 'direction']),
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_LOGIN_CHANGED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, ['directory_config'], $success, ['enabled'], [], 'required', [], [], [], $directoryConfig),
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONTROLLER_STATE => audit_definition(VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, ['directory_controller'], [VIRTUSPHERE_AUDIT_RESULT_WARNING, VIRTUSPHERE_AUDIT_RESULT_RECOVERED], ['outcome']),
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_CONTROLLER_TESTED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, ['directory_controller'], [VIRTUSPHERE_AUDIT_RESULT_SUCCESS, VIRTUSPHERE_AUDIT_RESULT_FAILURE], ['outcome']),
        VIRTUSPHERE_AUDIT_EVENT_DIRECTORY_BIND_REJECTED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, ['directory_config'], [VIRTUSPHERE_AUDIT_RESULT_WARNING], ['action'], [], 'required', [], [], [], $directoryConfig),
        VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_CHANGED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS, ['credential'], $success, ['action'], ['changes', 'trust_mode', 'selection_cleared']),
        VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_TESTED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS, ['credential'], [VIRTUSPHERE_AUDIT_RESULT_SUCCESS, VIRTUSPHERE_AUDIT_RESULT_WARNING, VIRTUSPHERE_AUDIT_RESULT_FAILURE], ['outcome'], ['component', 'ip']),
        VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_INVENTORY_AUTOMATION => audit_definition(VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS, ['credential'], [VIRTUSPHERE_AUDIT_RESULT_WARNING, VIRTUSPHERE_AUDIT_RESULT_RECOVERED], ['action', 'reason']),
        VIRTUSPHERE_AUDIT_EVENT_MISSION_CHANGED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_MISSIONS, ['mission'], $success, ['action'], ['name', 'changes', 'vm_count']),
        VIRTUSPHERE_AUDIT_EVENT_MISSION_TRANSFERRED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_MISSIONS, ['mission'], $success, ['action'], ['name', 'target_mission_id']),
        VIRTUSPHERE_AUDIT_EVENT_MISSION_LIST_EXPORTED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_MISSIONS, ['mission_list'], $success, ['row_count'], [], 'required', [], [], [], ['missions', 'templates']),
        VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_VMS, ['vm'], $success, ['action', 'mission_id'], ['changes']),
        VIRTUSPHERE_AUDIT_EVENT_VM_MECM_CHANGED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_VMS, ['vm'], $success, ['action', 'mission_id'], ['progress_kind']),
        VIRTUSPHERE_AUDIT_EVENT_VM_BULK_CHANGED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_VMS, ['mission'], $success, ['action', 'affected_count', 'vm_ids']),
        VIRTUSPHERE_AUDIT_EVENT_VM_LIST_EXPORTED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_VMS, ['mission'], $success, ['row_count']),
        VIRTUSPHERE_AUDIT_EVENT_VM_IDENTITY_ADOPTED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_VMS, ['vm'], $success, ['credential_id', 'mission_id', 'moid', 'instance_uuid']),
        VIRTUSPHERE_AUDIT_EVENT_CATALOG_ITEM_DELETED => audit_definition(null, ['operating_system', 'vlan'], $success, [], [], 'required', [], ['operating_system' => VIRTUSPHERE_LOG_CATEGORY_OS, 'vlan' => VIRTUSPHERE_LOG_CATEGORY_VLANS]),
        VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_SETTINGS, ['setting'], $success, ['action'], ['old_value', 'new_value', 'enabled', 'redirect_disabled']),
        VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CERT_INSTALLED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_SETTINGS, ['setting'], $success, ['subject', 'valid_to'], [], 'required', [], [], [], ['https_certificate']),
        VIRTUSPHERE_AUDIT_EVENT_SETTINGS_REPORT_TOKEN => audit_definition(VIRTUSPHERE_LOG_CATEGORY_SETTINGS, ['setting'], $success, ['action'], [], 'required', [], [], [], ['machine_report_token']),
        VIRTUSPHERE_AUDIT_EVENT_SETTINGS_MACHINE_IP => audit_definition(VIRTUSPHERE_LOG_CATEGORY_SETTINGS, ['client_ip'], $success, ['action']),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_QUEUED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_DEPLOY, ['deploy_job', 'deploy_group'], $success, ['mission_id', 'scheduled'], ['job_count']),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCEL_REQUESTED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_DEPLOY, ['deploy_job'], $success),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CANCELLED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_DEPLOY, ['deploy_job', 'deploy_group'], $success, ['action'], ['job_count']),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_RETRIED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_DEPLOY, ['deploy_job'], $success, ['retry_of_job_id']),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_OUTCOME => audit_definition(VIRTUSPHERE_LOG_CATEGORY_DEPLOY, ['deploy_job'], [VIRTUSPHERE_AUDIT_RESULT_SUCCESS, VIRTUSPHERE_AUDIT_RESULT_WARNING, VIRTUSPHERE_AUDIT_RESULT_FAILURE], ['mission_id', 'mode', 'status'], ['reason']),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_INVENTORY_REQUESTED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_DEPLOY, ['credential'], $change, ['reason'], ['job_id']),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_INVENTORY_REFRESH => audit_definition(VIRTUSPHERE_LOG_CATEGORY_DEPLOY, ['system'], $change, ['target_ids', 'job_ids', 'queued_count', 'open_count', 'paused_count', 'failed_count'], [], 'required', [], [], [], ['esxi_inventory']),
        // The only `name` object id in the registry: a VLAN has no numeric id,
        // the operator types the source name, and it may legitimately contain a
        // space. See audit_event_object_id_kind().
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_VLAN_REASSIGNED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_MISSIONS, ['vlan'], $change, ['target_vlan', 'mission_count', 'interface_count'], [], 'required', [], [], [], [], 'name'),
        VIRTUSPHERE_AUDIT_EVENT_DEPLOY_CONVERGENCE => audit_definition(VIRTUSPHERE_LOG_CATEGORY_DEPLOY, ['mission'], [VIRTUSPHERE_AUDIT_RESULT_WARNING], ['vm_count', 'vm_ids', 'reason']),
        // The only event whose category depends on its object id. Its allowlist
        // is derived from the same map, so an unknown source is refused as an
        // unknown OBJECT rather than as an unresolvable category: the two would
        // fail identically, but only one of them names the actual mistake.
        VIRTUSPHERE_AUDIT_EVENT_INTEGRATION_STATE => audit_definition(null, ['integration_source'], [VIRTUSPHERE_AUDIT_RESULT_WARNING, VIRTUSPHERE_AUDIT_RESULT_RECOVERED], ['old_state', 'new_state'], [], 'required', [], [], audit_integration_category_map(), array_keys(audit_integration_category_map())),
        VIRTUSPHERE_AUDIT_EVENT_SYSTEM_ERROR => audit_definition(VIRTUSPHERE_LOG_CATEGORY_SYSTEM, ['error'], [VIRTUSPHERE_AUDIT_RESULT_FAILURE], ['error_class']),
        VIRTUSPHERE_AUDIT_EVENT_LOGS_CSV_EXPORTED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_SYSTEM, ['log_view'], $success, ['filter_fingerprint', 'rows_exported', 'total_rows', 'limit', 'truncated'], [], 'required', [], [], [], $logTabs),
        VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_DENIED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, ['machine_endpoint'], [VIRTUSPHERE_AUDIT_RESULT_DENIED], [], ['action', ...$throttle], 'required', [], [], [], $endpoints),
        VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_CALLBACK_REJECTED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, ['deploy_job'], [VIRTUSPHERE_AUDIT_RESULT_DENIED], ['reason_code'], $throttle),
        VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_FAILURE => audit_definition(VIRTUSPHERE_LOG_CATEGORY_MACHINE_API, ['machine_endpoint'], [VIRTUSPHERE_AUDIT_RESULT_FAILURE], ['error_class'], $throttle, 'required', [], [], [], $endpoints),
        VIRTUSPHERE_AUDIT_EVENT_MECM_UNKNOWN_VM => audit_definition(VIRTUSPHERE_LOG_CATEGORY_MECM, ['vm'], [VIRTUSPHERE_AUDIT_RESULT_WARNING], ['report_type'], ['resource_id', ...$throttle]),
        VIRTUSPHERE_AUDIT_EVENT_MECM_CATALOG_REJECTED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_MECM, ['catalog'], [VIRTUSPHERE_AUDIT_RESULT_DENIED], ['retire_count', 'active_count', 'threshold_percent'], $throttle, 'required', [], [], [], $catalogs),
        VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGES_RELINKED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_MECM, ['catalog'], $success, ['items', 'item_count'], [], 'required', [], [], [], $catalogs),
        VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGES_RELINK_SKIPPED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_MECM, ['catalog'], [VIRTUSPHERE_AUDIT_RESULT_WARNING], ['items', 'item_count'], [], 'required', [], [], [], $catalogs),
        VIRTUSPHERE_AUDIT_EVENT_MECM_REPORT_TOKEN_REJECTED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_MECM, ['machine_endpoint'], [VIRTUSPHERE_AUDIT_RESULT_DENIED], ['action'], $throttle, 'required', [], [], [], $endpoints),
        VIRTUSPHERE_AUDIT_EVENT_MECM_CLIENT_CAP => audit_definition(VIRTUSPHERE_LOG_CATEGORY_MECM, ['vm'], [VIRTUSPHERE_AUDIT_RESULT_WARNING], [], $throttle),
        VIRTUSPHERE_AUDIT_EVENT_MECM_REPORTER_UPGRADED => audit_definition(VIRTUSPHERE_LOG_CATEGORY_MECM, ['integration_source'], [VIRTUSPHERE_AUDIT_RESULT_RECOVERED], ['report_version'], $throttle, 'required', [], [], [], VIRTUSPHERE_INTEGRATION_RUN_SOURCES),
    ];
}

/** @return array<string,string> */
function audit_integration_category_map(): array
{
    return [
        VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC => VIRTUSPHERE_LOG_CATEGORY_MECM,
        VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC => VIRTUSPHERE_LOG_CATEGORY_MECM,
        VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER => VIRTUSPHERE_LOG_CATEGORY_MECM,
        VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH => VIRTUSPHERE_LOG_CATEGORY_MECM,
        VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE => VIRTUSPHERE_LOG_CATEGORY_SYSTEM,
        VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER => VIRTUSPHERE_LOG_CATEGORY_SYSTEM,
    ];
}

/** @return array<string,mixed> */
function audit_definition(
    ?string $category,
    array $objects,
    array $results,
    array $required = [],
    array $optional = [],
    string $objectId = 'required',
    array $requiredByResult = [],
    array $categories = [],
    array $categoryById = [],
    array $objectIds = [],
    string $objectIdKind = 'identifier'
): array {
    return compact('category', 'objects', 'results', 'required', 'optional', 'objectId', 'requiredByResult', 'categories', 'categoryById', 'objectIds', 'objectIdKind');
}
