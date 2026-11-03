<?php

declare(strict_types=1);

// The portal's shared status catalog. It names only what a human reads; the
// technical values stay in lib/constants.php and lib/deploy_constants.php and
// travel unchanged through the database, the worker, the retained job logs and
// every machine field (ADR-0014).
//
// Every set carries its own unknown entry so an unknown value is named
// neutrally instead of echoed raw: the raw value used to be the badge label,
// and `os_installing` next to `pending` reads for nobody except the schema that
// wrote it.
//
// The phase and the finding of a VM (lib/vm_phase.php) are derived when read
// from stored evidence: no column, no writer. Their texts are at the end of this
// catalog. The phase names the last evidence that arrived, never a progress; the
// finding names the next step.
return [
    // VM lifecycle (VIRTUSPHERE_LIFECYCLE_STATES).
    'lifecycle_initializing' => 'Preparing',
    'lifecycle_ready' => 'Ready',
    'lifecycle_deploying' => 'Deploying',
    'lifecycle_deployed' => 'Deployed',
    'lifecycle_os_installing' => 'Installing operating system',
    'lifecycle_os_installed' => 'Operating system installed',
    'lifecycle_failed' => 'Failed',
    'lifecycle_unknown' => 'Unknown state',

    // MECM sync of a VM (VIRTUSPHERE_MECM_SYNC_STATES).
    'mecm_not_ready' => 'Not ready yet',
    'mecm_pending' => 'Waiting for MECM',
    'mecm_registered' => 'Registered in MECM',
    'mecm_deploy_failed' => 'MECM sync open after failure',
    'mecm_unknown' => 'Unknown state',

    // Job status (the active and terminal sets from lib/deploy_constants.php).
    'job_queued' => 'Queued',
    'job_running' => 'Running',
    'job_cancelling' => 'Cancellation requested',
    'job_succeeded' => 'Succeeded',
    'job_failed' => 'Failed',
    'job_cancelled' => 'Cancelled',
    'job_partial' => 'Partially succeeded',
    'job_outcome_unknown' => 'Outcome unknown',
    'job_unknown' => 'Unknown status',

    // Deploy modes. The six postable ones are the technical set in
    // virtusphere_deploy_mode_labels(); the portal can SHOW `inventory` but
    // it is scheduled, manually refreshed, triggered by credential save/test,
    // or triggered after any create/full terminal outcome with a durable Create JID.
    'mode_full' => 'Full pipeline',
    'mode_create' => 'Create VMs',
    'mode_powercycle' => 'Power cycle and export MACs',
    'mode_export' => 'Export MAC addresses',
    'mode_start' => 'Start VMs',
    'mode_autostart' => 'Apply ESXi autostart policy',
    'mode_inventory' => 'Inventory pull',
    'mode_unknown' => 'Unknown mode',
    'mode_invalid_payload' => 'Job payload is not readable',
    'mode_scope_one' => ':count VM',
    'mode_scope_many' => ':count VMs',
    'mode_then_autostart' => 'then autostart',

    // Dashboard mission status. The column is a free VARCHAR, not an enum: only
    // `active` and empty carry a fixed meaning, and any other legacy value is
    // shown with its own text rather than read as something it never was.
    'mission_active' => 'Active',

    // VM phase (VIRTUSPHERE_VM_PHASES, lib/vm_phase.php). The names follow the
    // order of the phases; `phase_template` applies to templates.
    'phase_created' => 'Created',
    'phase_in_progress' => 'In progress',
    'phase_on_esxi' => 'On ESXi',
    'phase_mac_known' => 'MAC known',
    'phase_handed_to_mecm' => 'Handed to MECM',
    'phase_windows_reports' => 'Windows reports',
    'phase_client_done' => 'Client done',
    'phase_template' => 'Template',
    'phase_unknown' => 'Unknown phase',
    'phase_technical' => 'Technical: :status',

    // VM findings (VIRTUSPHERE_VM_FINDINGS, lib/vm_phase.php). The time part drops
    // out only when no valid server time exists; then the variant without time or
    // origin applies. `mecm_sync_deploy_failed` reuses `mecm_deploy_failed`.
    'finding_create_uncertain' => 'Create unresolved (job :job_id, unit :position)',
    'finding_job_failed' => 'Job failed: :mode, :time',
    'finding_create_failed' => 'Not created on ESXi: :mode, :time',
    'finding_job_failed_unknown' => 'Job failed',
    'finding_job_outcome_unknown' => 'Job outcome unknown: :mode, :time',
    'finding_missing_from_inventory' => 'No longer in the inventory (:host, as of :time)',
    'finding_missing_from_inventory_no_host' => 'No longer in the inventory (as of :time)',
    'finding_job_running' => 'Job running since :since (:mode)',
    'finding_job_running_unknown' => 'Job running',
    'finding_old_mecm_device' => 'Delete the old MECM device (ResourceID :id)',
    'finding_mecm_pending_overdue' => 'Waiting for MECM since :since, longer than :hours h',
    'finding_mecm_pending_since' => 'Waiting for MECM since :since',
    'finding_mecm_pending' => 'Waiting for MECM',
    'finding_powered_off' => 'Powered off according to the inventory (as of :time)',
    'finding_os_install_overdue' => 'OS installation observed for longer than :hours h',
    'finding_pxe_unwatched' => 'PXE evidence missing',
    'finding_os_install_watching' => 'Observation running since :since',
    'finding_client_phase_failed' => 'Client phase failed: :phase',
    'finding_client_phase_unconfirmed' => 'Client phase :phase unconfirmed for :minutes min',
    'finding_package_run_failed' => 'Package report failed: :package',
    'finding_unknown' => 'Unknown finding',
    'finding_more' => '+:count',
    'finding_more_title' => ':count more findings, all in the VM editor',
    // Short form per finding for the tables (SP4, F7): no time, number or name;
    // the full sentence is the tooltip and the VM editor's text.
    'finding_short_create_uncertain' => 'Create unresolved',
    'finding_short_job_failed' => 'Job failed',
    'finding_short_create_failed' => 'Not created',
    'finding_short_job_outcome_unknown' => 'Outcome unknown',
    'finding_short_missing_from_inventory' => 'Not in the inventory',
    'finding_short_job_running' => 'Job running',
    'finding_short_old_mecm_device' => 'Old MECM device',
    'finding_short_mecm_pending_overdue' => 'MECM overdue',
    'finding_short_mecm_pending' => 'Waiting for MECM',
    'finding_short_powered_off' => 'Powered off',
    'finding_short_os_install_overdue' => 'OS installation overdue',
    'finding_short_pxe_unwatched' => 'PXE evidence missing',
    'finding_short_os_install_watching' => 'Observation running',
    'finding_short_client_phase_failed' => 'Client phase failed',
    'finding_short_client_phase_unconfirmed' => 'Client phase unconfirmed',
    'finding_short_package_run_failed' => 'Package report failed',
    'finding_short_mecm_sync_deploy_failed' => 'MECM sync open',
    // The device sync's cause at the finding "Waiting for MECM" (MX1). It is part of
    // the full sentence (VM editor and table cell tooltip), never of the short
    // form; past a certain age with "as of" and the observation time.
    'finding_mecm_cause' => ':finding; cause: :cause',
    'finding_mecm_cause_as_of' => ':finding; cause (as of :time): :cause',
    // One phrase per device code (VIRTUSPHERE_DEVICE_SYNC_CAUSES) saying what holds
    // the VM up. The system status help names the action.
    'sync_cause_mission_missing' => 'VM without a mission',
    'sync_cause_mac_missing' => 'no MAC on the WDS port group',
    'sync_cause_pxe_nic_ambiguous' => 'several network adapters on the WDS port group',
    'sync_cause_mac_conflict' => 'name and MAC point to different MECM devices',
    'sync_cause_device_name_invalid' => 'Windows hostname is not a valid device name',
    'sync_cause_device_identity_ambiguous' => 'several MECM records match the VM',
    'sync_cause_previous_resource_present' => 'old MECM device still exists',
    'sync_cause_resource_id_missing' => 'bound MECM device was deleted',
    'sync_cause_resource_mac_conflict' => 'bound MECM device carries a different MAC',
    'sync_cause_device_import_failed' => 'import into MECM failed',
    'sync_cause_resource_id_pending' => 'MECM has not assigned a ResourceID yet',
    'sync_cause_stale_rollout_revision' => 'scan used an outdated state, the next one starts over',
    'sync_cause_collection_missing' => 'a collection of the VM is missing in MECM',
    'sync_cause_collection_folder_failed' => 'folder of a collection not created or not moved',
    'sync_cause_collection_assign_failed' => 'assignment to a collection failed',
    'sync_cause_collection_remove_failed' => 'own collection assignment not removed',
    'sync_cause_membership_provenance_invalid' => 'origin of a collection assignment incomplete',
    'sync_cause_membership_identity_ambiguous' => 'collection not unique',
    'sync_cause_membership_query_failed' => 'collection memberships not reliably readable',
    'sync_cause_membership_operation_uncertain' => 'collection assignment unconfirmed after an abort',
    'sync_cause_membership_report_pending' => 'collection assignment confirmed, report follows',
    'sync_cause_membership_report_failed' => 'reporting the collection assignment to the portal failed',
    'sync_cause_resource_update_failed' => 'reporting the ResourceID to the portal failed',
];
