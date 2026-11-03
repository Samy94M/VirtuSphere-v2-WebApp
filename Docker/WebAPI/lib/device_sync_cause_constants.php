<?php

declare(strict_types=1);

// Device-sync cause report (MX1, FM-05): `mecm_report.php?action=reportDeviceCauses`.
// The MECM device sync reports, per VM it received from getDeviceList, the one
// cause that currently keeps that VM waiting. Display-only like reportRun; it
// never changes deploy_vms. Kept apart from lib/constants.php (size ceiling) and
// small enough for the PowerShell mirror to read: `$script:VsDeviceCauseCodes` in
// Powershell-MECM/mecm/VirtuSphere-Common.ps1 reads the code list below by regex,
// so its layout (one quoted code per line) is part of the contract.
const VIRTUSPHERE_DEVICE_SYNC_CAUSE_SCHEMA_VERSION = 1;
const VIRTUSPHERE_DEVICE_SYNC_CAUSE_MAX_ENTRIES = 500;
const VIRTUSPHERE_DEVICE_SYNC_CAUSE_MAX_BODY_BYTES = 65536;
const VIRTUSPHERE_DEVICE_SYNC_CAUSE_RETENTION_DAYS = 7;
const VIRTUSPHERE_DEVICE_SYNC_CAUSE_VM_ID_MAX = 2147483647;

// The 23 device-related codes of the sync vocabulary, in the order of
// `$script:VsRunCauseVocabulary`. Portal first: a new code is accepted here
// before the server scripts of a release send it.
const VIRTUSPHERE_DEVICE_SYNC_CAUSES = [
    'mission_missing',
    'mac_missing',
    'pxe_nic_ambiguous',
    'mac_conflict',
    'device_name_invalid',
    'device_identity_ambiguous',
    'previous_resource_present',
    'resource_id_missing',
    'resource_mac_conflict',
    'device_import_failed',
    'resource_id_pending',
    'stale_rollout_revision',
    'collection_missing',
    'collection_folder_failed',
    'collection_assign_failed',
    'collection_remove_failed',
    'membership_provenance_invalid',
    'membership_identity_ambiguous',
    'membership_query_failed',
    'membership_operation_uncertain',
    'membership_report_pending',
    'membership_report_failed',
    'resource_update_failed',
];

// Closed refusal tokens of the validator; every one answers 400. The byte bound
// is checked before decoding and answers the endpoint's existing 413 body.
const VIRTUSPHERE_DEVICE_SYNC_CAUSE_ERRORS = [
    'unsupported_schema',
    'missing_field',
    'unknown_field',
    'invalid_run_id',
    'invalid_complete',
    'invalid_causes',
    'too_many_causes',
    'invalid_cause',
    'invalid_vm_id',
    'invalid_rollout_revision',
    'unknown_cause_code',
    'duplicate_vm_id',
];
