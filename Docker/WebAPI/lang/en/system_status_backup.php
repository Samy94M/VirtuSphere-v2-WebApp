<?php

declare(strict_types=1);

// Host and backup section of System status
// (lib/system_status_backup_panels.php). Numbers arrive as placeholders from the
// constants in lib/backup_evidence.php and lib/constants.php.
return [
    'heading' => 'Host and backup',
    'hint' => 'The Docker host reports these values through the backup status channel. Without a report the value reads "No data". The overview card shows the worst row; "No data" never counts as OK there.',
    'row_backup' => 'Backup',
    'row_drill' => 'Restore test',
    'row_scrub' => 'Checksums',
    'row_https' => 'HTTPS certificate',
    'fact_last_run' => 'Last run',
    'fact_drill_last' => 'Last success',
    'fact_drill_backup' => 'Tested backup',
    'fact_scrub_last' => 'Last check',
    'fact_scrub_result' => 'Result',
    'fact_https_valid_to' => 'Valid until',
    'age_today' => 'today',
    'age_days_one' => ':days day ago',
    'age_days' => ':days days ago',
    'drill_unknown_hint' => 'No restore test on record. A drill run on another machine does not appear here. Drill with record:',
    'drill_due_hint' => 'The last recorded restore test is :days days old or older. Drill with record:',
    'scrub_unknown_hint' => 'No checksum check reported yet. Start a check:',
    'scrub_failed_hint' => 'These backups do not match their manifest and are not fit for a restore: :sets',
    'scrub_result' => 'Sets checked: :checked, mismatched: :failed',
    'https_expired_hint' => 'The HTTPS certificate has expired; browsers refuse the connection.',
];
