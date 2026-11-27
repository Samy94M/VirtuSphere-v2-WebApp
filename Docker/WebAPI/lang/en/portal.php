<?php

declare(strict_types=1);

/**
 * Portal-wide guard and error messages (ADR-0014).
 * Portal user-facing text only. Machine/API wire fields are not localized.
 */
return [
    'invalid_request' => 'Invalid request. Reload the page.',
    'invalid_csrf' => 'The request was rejected (invalid CSRF token). Reload the page and repeat the action; usually the page had been open too long.',
    'forbidden' => 'Access denied.',
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
    'vm_mecm_reset_confirm' => 'Reset the MECM ID of VM :name? The next rollout registers it with MECM as ":hostname". The old device is not deleted automatically: delete it in MECM first, if not done yet.',
    'vm_mecm_reset_confirm_editing' => 'Reset the MECM ID of VM :name? What gets activated is the last saved Windows hostname, not an unsaved change in the field. The old device is not deleted automatically: delete it in MECM first, if not done yet.',
    'vm_mecm_reset_template_blocked' => 'Templates cannot be queued for MECM.',
    'vm_mecm_reset_no_mac' => 'Reset is not possible yet: the VM has no imported MAC address.',
    // Etappe 14D: the reset is the only point at which a new rollout name takes
    // effect. Every success sentence names the name that is now armed and
    // repeats the unchanged operator duty: VirtuSphere deletes nothing in MECM.
    'vm_mecm_reset_success' => 'MECM ID was reset. The next rollout registers this VM with MECM as ":hostname". If not done yet: delete the old device in MECM; until then the device sync waits.',
    'vm_mecm_reset_already_pending' => 'Nothing to do: ":hostname" is already waiting as the next rollout name and the VM is queued for MECM.',
    'vm_mecm_reset_already_pending_reason' => 'already queued',
    'vm_mecm_reset_active_job' => 'Reset is not possible: a deploy job of this mission is currently running. Wait until it has finished.',
    'vm_mecm_reset_invalid_hostname' => 'Reset is not possible: the Windows hostname cannot be used as a rollout name in MECM. At most :max characters, only letters, digits and internal hyphens, no dot. Correct it in the VM editor first.',
    // A state, not a refusal (decision of 2026-09-03): VirtuSphere deletes nothing
    // in MECM, so after a reset exactly one step is left and it belongs to a
    // person. The sentence therefore sits on the VM, not inside an error only
    // somebody clicking a second time would ever see.
    'vm_mecm_reset_previous_device' => 'Still open: the MECM device of the previous rollout (ResourceID :resource_id) has not been deleted. If not done yet: delete the old device in MECM; until then the device sync waits. After that, the next device sync imports the new name by itself.',
    'vm_mecm_reset_error' => 'Reset is not possible: unexpected error.',
    // Read-only comparison in the VM editor. It appears ONLY while the desired
    // value and the frozen snapshot name different machines; as long as both
    // name the same one, the compact display is the truth.
    'vm_rollout_current_label' => 'Current rollout name',
    'vm_rollout_next_label' => 'Next rollout name',
    'vm_rollout_frozen_hint' => 'This VM was already handed to MECM as ":current". The changed name only applies to the next rollout: delete the old device in MECM, then use "Reset MECM ID".',
];