<?php

declare(strict_types=1);

// The backup encryption row of "Host und Backup" (SY3). It used to be a banner
// above the overview that no card counted, so an unencrypted backup sat next to
// a green host card (SY1 follow-up). The finding itself is
// backup_encryption_finding() (lib/backup_encryption.php), the one derivation
// the settings backup card shares; this module only renders it as a row.
require_once __DIR__ . '/backup_encryption.php';
require_once __DIR__ . '/settings_page.php';

/** The row of an encryption finding, with the way to the settings card. */
function system_status_backup_encryption_row(string $finding, string $tone, array $user): string
{
    $hint = h(backup_encryption_finding_message($finding));
    if (can('system.config', $user)) {
        $hint .= ' <a href="' . h(settings_url('backup')) . '">' . h(__t('settings.backup_title')) . '</a>';
    }

    // `data-backup-encryption-finding` stays the hook the K19 browser test and
    // the help name for this finding, now on its row.
    return str_replace(
        'data-host-state="backup-encryption"',
        'data-host-state="backup-encryption" data-backup-encryption-finding',
        system_status_backup_row('backup-encryption', __t('system_status_cards.host_encryption'), $tone, [], $hint)
    );
}
