<?php

declare(strict_types=1);

// The "Last restore test" row of the Backup card in the settings (operations
// analysis B3, N1b). It shows the same evidence as the restore-drill row of the
// System status (lib/backup_evidence.php), with the card's absolute timestamps:
// when the last passed drill ran and how long ago, a "due" badge from
// VIRTUSPHERE_RESTORE_DRILL_DUE_DAYS on, and the host command only while the
// evidence is missing or due. Missing evidence never reads as a date.
require_once __DIR__ . '/backup_evidence.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/portal_time.php';

/** "today", "1 day ago", "N days ago". */
function settings_backup_drill_age_label(int $days): string
{
    return match (true) {
        $days <= 0 => __t('settings.backup_drill_age_today'),
        $days === 1 => __t('settings.backup_drill_age_one'),
        default => __t('settings.backup_drill_age_days', ['days' => $days]),
    };
}

/**
 * Inner HTML of the table cell; every value is escaped here.
 *
 * @param array{state:string,ts:int|null,age_days:int|null,backup_ts:string} $drill from backup_drill_status_read()
 */
function settings_backup_drill_cell(array $drill): string
{
    $known = $drill['state'] !== VIRTUSPHERE_BACKUP_EVIDENCE_UNKNOWN
        && $drill['ts'] !== null
        && $drill['age_days'] !== null;
    $due = $known && $drill['state'] === VIRTUSPHERE_BACKUP_EVIDENCE_DUE;

    if ($known) {
        $html = h(portal_format_epoch((int) $drill['ts']))
            . ' <span class="muted">(' . h(settings_backup_drill_age_label((int) $drill['age_days'])) . ')</span>';
        if ($due) {
            $html .= ' ' . portal_badge('warning', __t('settings.backup_drill_due'));
        }
    } else {
        $html = h(__t('settings.backup_drill_unknown'));
    }

    if (!$known || $due) {
        $hint = $due
            ? __t('settings.backup_drill_due_hint', ['days' => VIRTUSPHERE_RESTORE_DRILL_DUE_DAYS])
            : __t('settings.backup_drill_unknown_hint');
        $html .= '<div class="muted" data-backup-drill-hint>' . h($hint)
            . ' <code>' . h(VIRTUSPHERE_RESTORE_DRILL_RECORD_COMMAND) . '</code></div>';
    }

    return $html;
}
