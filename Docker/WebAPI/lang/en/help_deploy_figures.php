<?php

declare(strict_types=1);

// Texts of the two figures in the deploy help (lib/help_deploy_modes.php,
// lib/help_deploy_job_states.php). Mode and status names come from status.php.
return [
    'modes_heading' => 'What each deploy mode runs',
    'modes_intro' => 'Each row shows one mode and the steps it starts on ESXi, in their order. The numbers are listed in the legend at the top of the figure.',
    'modes_legend' => 'Steps',
    // One name per step; keys follow VIRTUSPHERE_PLAYBOOKS.
    'step_create' => 'Create VMs',
    'step_powercycle' => 'Power on and off briefly',
    'step_export' => 'Read MAC addresses',
    'step_start' => 'Start VMs',
    'step_autostart' => 'Write autostart policy',
    'modes_desc' => 'The steps in their order: :steps. :modes',
    'modes_desc_mode' => ':mode: :steps.',
    'modes_desc_conditional' => ':step only with the mission autostart',
    'modes_note' => 'A dashed step runs only when the mission has autostart enabled. A start job marked "then autostart" resumes a full pipeline.',
    'job_states_heading' => 'States of a job',
    'job_states_intro' => 'A job always has exactly one status. The figure shows which status a job can move to from which other status, and when it is finished. What "Partially succeeded" and "Outcome unknown" mean is explained under "Running a failed job again" and "Result and completion metadata".',
    'group_active' => 'Active',
    'group_terminal' => 'Finished',
    'state_cancellable' => 'Can be canceled',
    'job_states_desc' => 'Active statuses: :active. Can be canceled: :cancellable. Final states: :terminal. Transitions: :transitions.',
    'job_states_transition' => 'from :from to :to',
    'job_states_note' => 'No way leads back out of a final state; "Run again" creates a new job. The stale-job cleanup sets "Outcome unknown" when a running mission job stops reporting its heartbeat, and the deploy service sets it when the connection to the Ansible host drops in the middle of a step.',
];
