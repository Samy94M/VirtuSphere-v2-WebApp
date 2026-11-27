<?php

declare(strict_types=1);

return [
    'heading' => 'Used in',
    'link' => 'Used in',
    'link_label' => 'Used in: :name',
    'shown' => 'Showing :shown of :total.',
    'job_label' => 'Job #:id',
    'job_system' => 'System job',
    'object_template' => 'Template',
    'object_mission' => 'Mission',
    'group_direct_package' => 'Assigned to VMs',
    'group_direct_os' => 'Recorded on VMs',
    'group_direct_vlan' => 'Recorded on network adapters of VMs',
    'group_direct_credential' => 'VMs created or identified through this credential',
    'group_mission_vlan' => 'Recorded as WDS port group in missions',
    'group_job_open' => 'Active or planned jobs',
    'group_job_history' => 'Earlier jobs with a mission',
    'empty_package' => 'No VM uses this package.',
    'empty_os' => 'No VM uses this operating system.',
    'empty_vlan' => 'No network adapter and no mission uses this port group.',
    'empty_credential_esxi' => 'No job and no VM uses this credential.',
    'empty_credential_ansible' => 'No job uses this credential.',
    'empty_generic' => 'No use found.',
    'note_vm_binding' => 'A VM counts once it was created through this credential or its MAC address was exported through it. Older VMs without this detail are missing here even if they use the credential.',
    'note_ansible_jobs' => 'For Ansible credentials only jobs count.',
];
