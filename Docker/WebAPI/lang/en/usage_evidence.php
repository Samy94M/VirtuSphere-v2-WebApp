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
    'group_direct_vlan' => 'Recorded on VM network adapters',
    'group_direct_credential' => 'VMs with a proven binding',
    'group_mission_vlan' => 'Recorded as WDS portgroup in missions',
    'group_job_open' => 'Active or planned jobs',
    'group_job_history' => 'Earlier jobs with a mission',
    'empty_package' => 'No VM uses this package.',
    'empty_os' => 'No VM uses this operating system.',
    'empty_vlan' => 'No VM network adapter and no mission uses this VLAN.',
    'empty_credential_esxi' => 'No job and no VM with a proven binding uses this access.',
    'empty_credential_ansible' => 'No job uses this access.',
    'empty_generic' => 'No known use.',
    'note_vm_binding' => 'A VM counts only when its binding to this access is proven. Without proof, a use is not ruled out.',
    'note_ansible_jobs' => 'Ansible accesses are proven through jobs only.',
];
