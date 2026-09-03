<?php

declare(strict_types=1);

/**
 * Owner registry for the VM repository.
 *
 * The historic repo/vms.php path is a compatibility facade. Static contracts
 * walk this list so a moved function cannot fall out of their scan silently.
 * Paths are relative to Docker/WebAPI and kept in facade load order.
 */
const VIRTUSPHERE_VM_REPO_MODULES = [
    'lib/repo/vms.php',
    'lib/repo/vm_network.php',
    'lib/repo/vm_rollout.php',
    'lib/repo/vms_validation.php',
    'lib/repo/vms_persistence.php',
    'lib/repo/vms_mecm_reset.php',
    'lib/repo/vms_operations.php',
    'lib/repo/vms_legacy.php',
];
