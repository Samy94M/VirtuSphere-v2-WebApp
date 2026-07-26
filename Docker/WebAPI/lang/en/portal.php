<?php

declare(strict_types=1);

/**
 * Portal-wide guard and error messages (ADR-0014).
 * Portal user-facing text only. Machine/API wire fields are not localized.
 */
return [
    'invalid_request' => 'Invalid logout request.',
    'invalid_csrf' => 'Invalid CSRF token.',
    'forbidden' => 'Forbidden.',
    'mission_not_found' => 'Mission not found.',
    'vm_not_found' => 'VM not found.',
    'deploy_not_found' => 'Deploy job not found.',
    'vm_guest_os_label' => 'Guest OS',
    'vm_guest_os_windows_server_2019' => 'Windows Server 2019',
    'vm_guest_os_windows_11' => 'Windows 11',
    'vm_guest_os_windows_server_2022' => 'Windows Server 2022',
    'vm_guest_os_windows_server_2025' => 'Windows Server 2025',
    'vm_guest_os_unknown' => 'Unknown guest OS',
    'vm_guest_os_legacy' => 'Legacy Guest ID: :guest_id',
    'vm_mecm_reset_button' => 'Reset MECM ID',
    'vm_mecm_reset_confirm' => 'Reset the MECM ID of VM :name and queue it for MECM again?',
    'vm_mecm_reset_success' => 'MECM ID was reset; the VM is queued for MECM again.',
    'vm_mecm_reset_template_blocked' => 'Templates cannot be queued for MECM.',
    'vm_mecm_reset_no_mac' => 'Reset is not possible yet: the VM has no imported MAC address.',
    // An explicit action rather than a silent state change: the portal is the
    // intent before the rollout, MECM is the truth after it.
    'vm_mecm_transfer_button' => 'Transfer assignments to MECM',
    'vm_mecm_transfer_confirm' => 'Transfer the operating system and package assignments of VM :name to MECM now? The VM is queued for the device-sync again; its installation state stays as it is.',
    'vm_mecm_transfer_success' => 'The VM is queued for the transfer. The device-sync adds the missing collection memberships on its next run; existing memberships are never removed.',
];