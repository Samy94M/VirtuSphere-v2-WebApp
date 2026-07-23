<?php

declare(strict_types=1);

return [
    'title' => 'Dashboard',
    'key_metrics' => 'Key metrics',
    'kpi_missions' => 'Missions',
    'kpi_templates' => 'Templates',
    'kpi_vms' => 'VMs',
    'kpi_mecm_pending' => 'MECM pending',
    'kpi_system_status' => 'MECM system status',
    'kpi_hypervisor' => 'Hypervisor',
    'kpi_active_deploys' => 'Active deploys',
    'recent_missions' => 'Recent missions',
    'all_missions' => 'All missions',
    'create_mission' => 'Create mission',
    'empty' => 'No missions found.',
    'backup_banner_title' => 'Backup notice:',
    'backup_banner_failed' => 'The last backup failed. Please check the backup log and the cron job.',
    'backup_banner_stale' => 'The expected backup run did not happen, grace window included. Is the cron job on the host running?',
    'backup_banner_disk_low' => 'Low free space on the backup volume. Check retention or disk space.',
    'backup_banner_unknown' => 'No backup status available. The script has never run or the status mount is missing.',
    'backup_banner_link' => 'Go to backup card',
];
