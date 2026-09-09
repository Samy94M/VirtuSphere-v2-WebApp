<?php

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../defaults.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../validate.php';
require_once __DIR__ . '/../mac.php';
require_once __DIR__ . '/../vm_progress.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/edit_version.php';
require_once __DIR__ . '/status_events.php';
// The VM delete paths refuse to run while a deploy of the mission is in flight;
// that predicate and the mission lock it needs live in the job repo.
require_once __DIR__ . '/deploy_jobs.php';
require_once __DIR__ . '/vm_identity.php';
// Rollout hostname, revision fence and the global hostname claim (Etappe 14D).
require_once __DIR__ . '/vm_rollout.php';
require_once __DIR__ . '/vm_network.php';

const REPO_VM_COLUMNS = [
    'vm_name',
    'vm_hostname',
    'vm_domain',
    'vm_os',
    'vm_ram',
    'vm_cpu',
    'vm_disk',
    'vm_datastore',
    'vm_datacenter',
    'vm_guest_id',
    'vm_creator',
    'vm_notes',
    'cpu_hotplug',
    'ram_hotplug',
    'autostart_enabled',
    'autostart_start_delay',
    'autostart_stop_delay',
];

// Domain owners are deliberately loaded behind this historical public facade.
require_once __DIR__ . '/vms_validation.php';
require_once __DIR__ . '/vms_persistence.php';
require_once __DIR__ . '/vms_mecm_reset.php';
require_once __DIR__ . '/vms_operations.php';
require_once __DIR__ . '/vms_legacy.php';
