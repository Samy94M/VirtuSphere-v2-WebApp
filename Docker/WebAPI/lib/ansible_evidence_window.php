<?php

declare(strict_types=1);

// How long a passed Ansible full test stays evidence (TS1-K3, finding K5 of the
// text review: P2a-1, P2a-2). The one owner of the window: the Ampel
// (ansible_preflight_ampel(), lib/status.php) and every sentence that names the
// window read it. Kept dependency-free apart from the constants, because both
// the status presenters and the credentials page load it.
require_once __DIR__ . '/constants.php';

/**
 * The longer of VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS and twice the
 * configured interval, so one missed automatic run does not grey the credential
 * out. An interval of 0 (automatic test off) leaves the fixed days.
 */
function ansible_preflight_evidence_valid_seconds(int $intervalHours): int
{
    return max(VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS * 86400, 2 * max(0, $intervalHours) * 3600);
}

/**
 * The window in whole days, for the sentences that name it in days ("valid for
 * :days days", "more than :days days old"). Rounded DOWN, like
 * portal_format_relative(): an interval that is not a multiple of 12 h gives a
 * window with a part day (100 h: 8 days 8 hours), and the lower whole day is the
 * only number both sentences state truthfully. The division used to print
 * "8.3333333333333".
 */
function ansible_preflight_evidence_valid_days(int $intervalHours): int
{
    return intdiv(ansible_preflight_evidence_valid_seconds($intervalHours), 86400);
}
