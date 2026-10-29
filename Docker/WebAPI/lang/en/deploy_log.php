<?php
declare(strict_types=1);
// Job log display (E1/E2, 29.10.2026): counters, result per VM, technical
// details, error lines. Older keys of the page are in deploy.php.
return [
    'position_running' => ':processed of :total processed',
    'position_created' => ':created of :total created',
    'position_part_failed' => ':count failed',
    'position_part_uncertain' => ':count unresolved',
    'position_part_not_started' => ':count not started',
    'current_attempt' => 'Attempt :attempt of :max.',
    'finding_attempts' => ':name (position :position, attempt :attempt of :max): :reason',
    'reason_vm_not_powered_off' => 'A powered-on VM of this name was already running on the host; Create changed nothing on it.',
    'stored_detail' => 'Stored text:',
    'host_no_message' => 'The host gave no closer message.',
    'ansible_cause' => 'Last Ansible failure line:',
    'show_in_log' => 'Show in log',
    'retry_creates_missing' => '":action" creates only the VMs that are still missing; VMs already created are checked live.',
    'technical_vm' => ':name (position :position):',
    'technical_first_attempt' => 'First attempt:',
    'severity_fail' => 'Failed',
    'severity_hold' => 'Halted',
    'severity_fatal' => 'Ansible error',
    'filter_errors_only' => 'Errors only',
];
