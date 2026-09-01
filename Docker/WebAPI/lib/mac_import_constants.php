<?php

declare(strict_types=1);

const VIRTUSPHERE_MAC_IMPORT_RESULT_VERSION = 2;
const VIRTUSPHERE_MAC_IMPORT_LEGACY_RESULT_VERSION = 1;
const VIRTUSPHERE_MAC_IMPORT_RESULT_KIND = 'mac_import';

const VIRTUSPHERE_MAC_IMPORT_ERROR_INTERFACE_NOT_FOUND = 'interface_not_found';
const VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_MAC = 'duplicate_mac';
const VIRTUSPHERE_MAC_IMPORT_ERROR_INVALID_MAC = 'invalid_mac';
const VIRTUSPHERE_MAC_IMPORT_ERROR_AMBIGUOUS_VLAN = 'ambiguous_vlan';
const VIRTUSPHERE_MAC_IMPORT_ERROR_VM_NOT_IN_MISSION = 'vm_not_in_mission';
const VIRTUSPHERE_MAC_IMPORT_ERROR_VM_NOT_IN_JOB_SCOPE = 'vm_not_in_job_scope';
const VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NAME = 'missing_name';
const VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NIC_DATA = 'missing_nic_data';
const VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_QUERY_FAILED = 'esxi_query_failed';
const VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_RESULT = 'duplicate_result';
const VIRTUSPHERE_MAC_IMPORT_ERROR_IDENTITY_MISMATCH = 'identity_mismatch';
const VIRTUSPHERE_MAC_IMPORT_ERROR_MISSION_WDS_MISSING = 'mission_wds_missing';
const VIRTUSPHERE_MAC_IMPORT_ERROR_PORTAL_WDS_MISSING = 'portal_wds_interface_missing';
const VIRTUSPHERE_MAC_IMPORT_ERROR_PORTAL_WDS_CASE = 'portal_wds_interface_case_mismatch';
const VIRTUSPHERE_MAC_IMPORT_ERROR_PORTAL_WDS_AMBIGUOUS = 'portal_wds_interface_ambiguous';
const VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_WDS_MISSING = 'esxi_wds_interface_missing';
const VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_WDS_CASE = 'esxi_wds_interface_case_mismatch';
const VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_WDS_AMBIGUOUS = 'esxi_wds_interface_ambiguous';
const VIRTUSPHERE_MAC_IMPORT_ERROR_WDS_MAC_MISSING = 'wds_mac_missing';

const VIRTUSPHERE_MAC_IMPORT_ERROR_CODES = [
    VIRTUSPHERE_MAC_IMPORT_ERROR_INTERFACE_NOT_FOUND,
    VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_MAC,
    VIRTUSPHERE_MAC_IMPORT_ERROR_INVALID_MAC,
    VIRTUSPHERE_MAC_IMPORT_ERROR_AMBIGUOUS_VLAN,
    VIRTUSPHERE_MAC_IMPORT_ERROR_VM_NOT_IN_MISSION,
    VIRTUSPHERE_MAC_IMPORT_ERROR_VM_NOT_IN_JOB_SCOPE,
    VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NAME,
    VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NIC_DATA,
    VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_QUERY_FAILED,
    VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_RESULT,
    VIRTUSPHERE_MAC_IMPORT_ERROR_IDENTITY_MISMATCH,
    VIRTUSPHERE_MAC_IMPORT_ERROR_MISSION_WDS_MISSING,
    VIRTUSPHERE_MAC_IMPORT_ERROR_PORTAL_WDS_MISSING,
    VIRTUSPHERE_MAC_IMPORT_ERROR_PORTAL_WDS_CASE,
    VIRTUSPHERE_MAC_IMPORT_ERROR_PORTAL_WDS_AMBIGUOUS,
    VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_WDS_MISSING,
    VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_WDS_CASE,
    VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_WDS_AMBIGUOUS,
    VIRTUSPHERE_MAC_IMPORT_ERROR_WDS_MAC_MISSING,
];

// One exhaustive producer/presenter/retry registry. Technical codes stay on
// the machine wire; only the portal resolves their message/action keys.
const VIRTUSPHERE_MAC_IMPORT_ERROR_META = [
    VIRTUSPHERE_MAC_IMPORT_ERROR_INTERFACE_NOT_FOUND => ['source' => 'external', 'retry' => 'external_confirm', 'key' => 'interface_not_found'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_MAC => ['source' => 'identity', 'retry' => 'manual', 'key' => 'duplicate_mac'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_INVALID_MAC => ['source' => 'external', 'retry' => 'manual', 'key' => 'invalid_mac'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_AMBIGUOUS_VLAN => ['source' => 'network', 'retry' => 'conditional', 'key' => 'ambiguous_vlan'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_VM_NOT_IN_MISSION => ['source' => 'protocol', 'retry' => 'manual', 'key' => 'vm_not_in_mission'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_VM_NOT_IN_JOB_SCOPE => ['source' => 'protocol', 'retry' => 'manual', 'key' => 'vm_not_in_job_scope'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NAME => ['source' => 'protocol', 'retry' => 'manual', 'key' => 'missing_name'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_MISSING_NIC_DATA => ['source' => 'external', 'retry' => 'external_confirm', 'key' => 'missing_nic_data'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_QUERY_FAILED => ['source' => 'external', 'retry' => 'external_confirm', 'key' => 'esxi_query_failed'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_RESULT => ['source' => 'protocol', 'retry' => 'manual', 'key' => 'duplicate_result'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_IDENTITY_MISMATCH => ['source' => 'identity', 'retry' => 'identity_blocked', 'key' => 'identity_mismatch'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_MISSION_WDS_MISSING => ['source' => 'network', 'retry' => 'portal_blocked', 'key' => 'mission_wds_missing'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_PORTAL_WDS_MISSING => ['source' => 'network', 'retry' => 'portal_blocked', 'key' => 'portal_wds_interface_missing'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_PORTAL_WDS_CASE => ['source' => 'network', 'retry' => 'portal_blocked', 'key' => 'portal_wds_interface_case_mismatch'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_PORTAL_WDS_AMBIGUOUS => ['source' => 'network', 'retry' => 'portal_blocked', 'key' => 'portal_wds_interface_ambiguous'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_WDS_MISSING => ['source' => 'external', 'retry' => 'external_confirm', 'key' => 'esxi_wds_interface_missing'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_WDS_CASE => ['source' => 'external', 'retry' => 'external_confirm', 'key' => 'esxi_wds_interface_case_mismatch'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_WDS_AMBIGUOUS => ['source' => 'external', 'retry' => 'external_confirm', 'key' => 'esxi_wds_interface_ambiguous'],
    VIRTUSPHERE_MAC_IMPORT_ERROR_WDS_MAC_MISSING => ['source' => 'external', 'retry' => 'external_confirm', 'key' => 'wds_mac_missing'],
];

const VIRTUSPHERE_MAC_IMPORT_RETRY_BLOCKING_CLASSES = ['manual', 'identity_blocked', 'portal_blocked'];

const VIRTUSPHERE_MAC_IMPORT_CALLBACK_REASON_META = [
    'request_too_large' => ['http' => 413, 'observability' => 'infrastructure_only'],
    'callback_job_not_active' => ['http' => 409, 'observability' => 'job_and_audit'],
    'callback_mode_rejected' => ['http' => 409, 'observability' => 'job_and_audit'],
    'callback_execution_contract_missing' => ['http' => 409, 'observability' => 'job_and_audit'],
    'callback_generation_mismatch' => ['http' => 409, 'observability' => 'job_and_audit'],
    'callback_remote_handle_mismatch' => ['http' => 409, 'observability' => 'job_and_audit'],
    'callback_result_conflict' => ['http' => 409, 'observability' => 'job_and_audit'],
    'result_contract_too_large' => ['http' => 409, 'observability' => 'job_and_audit'],
    'response_contract_too_large' => ['http' => 409, 'observability' => 'job_and_audit'],
];

/** @return list<string> */
function virtusphere_mac_import_callback_reasons(): array
{
    return array_keys(VIRTUSPHERE_MAC_IMPORT_CALLBACK_REASON_META);
}

const VIRTUSPHERE_MAC_IMPORT_CALLBACK_OBSERVABILITY_THROTTLE_SECONDS = 3600;
