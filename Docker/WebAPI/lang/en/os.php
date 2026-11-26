<?php

declare(strict_types=1);

return [
    'title' => 'Operating systems',
    'readonly_hint' => 'Operating systems are synced from MECM (as {mecm:task_sequences}); here they can only be deleted, not created or edited.',
    'th_vm_usage' => 'VMs',
    'confirm_delete_unused' => 'Operating system :name is used by no VM. Delete it? If the {mecm:task_sequence} still exists in MECM, the next sync re-creates the entry.',
    'confirm_delete_one' => 'Operating system :name is used by one VM. Really delete it? If the {mecm:task_sequence} still exists in MECM, the next sync re-creates the entry.',
    'confirm_delete_many' => 'Operating system :name is used by :count VMs. Really delete it? If the {mecm:task_sequence} still exists in MECM, the next sync re-creates the entry.',
    'empty' => 'No operating systems in the catalog yet. They appear once MECM has synced its {mecm:task_sequences}.',
    'empty_filtered' => 'No entry matches this status filter.',
    'show_all' => 'Show all entries',
    'flash_deleted' => 'Operating system deleted.',
    'th_retired_at' => 'Retired at',
    'filter_label' => 'Status filter',
    'filter_active' => 'Active',
    'filter_retired' => 'Retired',
    'filter_all' => 'All',
    'filter_apply' => 'Filter',
];
