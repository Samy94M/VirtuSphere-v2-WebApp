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
    'mecm_failed' => 'MECM sync failed',
    'mecm_unknown' => 'Unknown state',

    // Job status (the active and terminal sets from lib/deploy_constants.php).
    'job_queued' => 'Queued',
    'job_running' => 'Running',
    'job_cancelling' => 'Cancellation requested',
    'job_succeeded' => 'Succeeded',
    'job_failed' => 'Failed',
    'job_cancelled' => 'Cancelled',
    'job_partial' => 'Partially succeeded',
    'job_unknown' => 'Unknown status',

    // Deploy modes. The six postable ones are the technical set in
    // virtusphere_deploy_mode_labels(); the portal can SHOW `inventory` but
    // nobody can queue it, because the scheduler is its only producer.
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

    // Dashboard mission status. The column is a free VARCHAR, not an enum: only
    // `active` and empty carry a fixed meaning, and any other legacy value is
    // shown with its own text rather than read as something it never was.
    'mission_active' => 'Active',
];
