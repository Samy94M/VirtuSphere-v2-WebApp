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
    // Two confirmations for one action, deliberately.
    //
    // In the VM list the Windows hostname is nowhere on screen, so the question
    // names it: it is what gets activated. In the VM editor an EDITABLE hostname
    // field sits right there, and a server-rendered name would state a value the
    // editor may have typed over without saving. There the question says what
    // applies instead of which value.
    'vm_mecm_reset_confirm' => 'Reset the MECM ID of VM :name? The next rollout registers it with MECM as ":hostname". The old MECM device is not deleted automatically.',
    'vm_mecm_reset_confirm_editing' => 'Reset the MECM ID of VM :name? What gets activated is the last SAVED Windows hostname, not an unsaved change in the field. The old MECM device is not deleted automatically.',
    'vm_mecm_reset_template_blocked' => 'Templates cannot be queued for MECM.',
    'vm_mecm_reset_no_mac' => 'Reset is not possible yet: the VM has no imported MAC address.',
    // Etappe 14D: the reset is the only point at which a new rollout name takes
    // effect. Every success sentence names the name that is now armed and
    // repeats the unchanged operator duty: VirtuSphere deletes nothing in MECM.
    'vm_mecm_reset_success' => 'MECM ID was reset. The next rollout registers this VM with MECM as ":hostname". The old MECM device is not deleted automatically; please remove it in the MECM console.',
    'vm_mecm_reset_already_pending' => 'Nothing to do: ":hostname" is already waiting as the next rollout name and the VM is queued for MECM.',
    'vm_mecm_reset_already_pending_reason' => 'already queued',
    'vm_mecm_reset_active_job' => 'Reset is not possible: a deploy job of this mission is currently running. Please wait until it has finished.',
    'vm_mecm_reset_invalid_hostname' => 'Reset is not possible: the Windows hostname cannot be used as a MECM device name. At most :max characters, only letters, digits and internal hyphens, no dot. Please correct it in the VM editor first.',
    // A state, not a refusal (decision of 2026-09-03): VirtuSphere deletes nothing
    // in MECM, so after a reset exactly one step is left and it belongs to a
    // person. The sentence therefore sits on the VM, not inside an error only
    // somebody clicking a second time would ever see.
    'vm_mecm_reset_previous_device' => 'Still open: the MECM device of the previous rollout (ResourceID :resource_id) has not been deleted. Please remove it in the MECM console; the next device sync then imports the new name by itself.',
    'vm_mecm_reset_error' => 'Reset is not possible: unexpected error.',
    // Read-only comparison in the VM editor. It appears ONLY while the desired
    // value and the frozen snapshot name different machines; as long as both
    // name the same one, the compact display is the truth.
    'vm_rollout_current_label' => 'Current rollout name',
    'vm_rollout_next_label' => 'Next rollout name',
    'vm_rollout_frozen_hint' => 'This VM was already handed to MECM as ":current". The changed name only applies to the next rollout: delete the old device in MECM, then use "Reset MECM ID".',
    // An explicit action rather than a silent state change: the portal is the
    // intent before the rollout, MECM is the truth after it.
    'vm_mecm_transfer_button' => 'Transfer assignments to MECM',
    'vm_mecm_transfer_confirm' => 'Transfer the operating system and package assignments of VM :name to MECM now? The VM is queued for the device-sync again; its installation state stays as it is. This does not start an installation.',
    'vm_mecm_transfer_success' => 'The VM is queued for the transfer. The device-sync reconciles the memberships on its next run: missing ones are added, obsolete own rules are removed. Rules created by hand in MECM always stay untouched.',
    'vm_mecm_transfer_stale' => 'The assignments changed since this page was loaded. Reload the page and check the preview again.',
    'vm_mecm_preview_add' => 'On the next transfer the VM is added to: :names',
    'vm_mecm_preview_remove' => 'Own rules no longer assigned are removed: :names. Rules created by hand in MECM stay untouched.',
    'vm_mecm_preview_none' => 'From the portal\'s view no changes to the own memberships are pending; the transfer still checks the state in MECM.',
];