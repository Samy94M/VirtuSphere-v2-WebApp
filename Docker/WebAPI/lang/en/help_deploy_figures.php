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
    'modes_note' => 'A dashed step runs only when the mission has autostart enabled.',
    'job_states_heading' => 'States of a job',
    'job_states_intro' => 'A job always has exactly one status. The figure shows which status it can move to from which, and when it is finished.',
    'group_active' => 'Active',
    'group_terminal' => 'Finished',
    'state_cancellable' => 'Can be cancelled',
    'job_states_desc' => 'Active statuses: :active. Can be cancelled: :cancellable. Final states: :terminal. Transitions: :transitions.',
    'job_states_transition' => 'from :from to :to',
    'job_states_note' => 'No way leads back out of a final state; "Run again" creates a new job. Only the supervision sets "Outcome unknown", when a running job stops reporting its heartbeat.',
];
