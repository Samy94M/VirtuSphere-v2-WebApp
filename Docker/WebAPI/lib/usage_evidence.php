<?php

declare(strict_types=1);

// Usage evidence ("Verwendet in", F08): the closed vocabulary and the pure rules
// that the reader (lib/repo/usage_evidence.php) and the section renderer
// (lib/usage_evidence_view.php) share. This file neither queries nor renders.
//
// A job is "open" when it is in VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES, the same
// set the credential change fence blocks on (lib/repo/deploy_create_fence.php).
// The evidence and the delete check therefore cannot disagree about which job
// still holds a credential.

require_once __DIR__ . '/deploy_constants.php';

const VIRTUSPHERE_USAGE_OBJECT_PACKAGE = 'package';
const VIRTUSPHERE_USAGE_OBJECT_OS = 'os';
const VIRTUSPHERE_USAGE_OBJECT_VLAN = 'vlan';
const VIRTUSPHERE_USAGE_OBJECT_CREDENTIAL = 'credential';

// Kinds of use in display order. "direct" is a stored assignment on a VM or a
// VM network adapter. "mission" is a mission-level setting that reaches every
// VM of that mission (the WDS port group). The two job groups keep an open job
// (queued, running or cancelling) apart from a finished one with a mission.
const VIRTUSPHERE_USAGE_GROUP_DIRECT = 'direct';
const VIRTUSPHERE_USAGE_GROUP_MISSION = 'mission';
const VIRTUSPHERE_USAGE_GROUP_JOB_OPEN = 'job_open';
const VIRTUSPHERE_USAGE_GROUP_JOB_HISTORY = 'job_history';
const VIRTUSPHERE_USAGE_GROUPS = [
    VIRTUSPHERE_USAGE_GROUP_DIRECT,
    VIRTUSPHERE_USAGE_GROUP_MISSION,
    VIRTUSPHERE_USAGE_GROUP_JOB_OPEN,
    VIRTUSPHERE_USAGE_GROUP_JOB_HISTORY,
];

// The source tokens name where a count came from. The renderer turns them into
// words, so a reader never hands a sentence to the page.
const VIRTUSPHERE_USAGE_SOURCE_VM_PACKAGES = 'vm_packages';
const VIRTUSPHERE_USAGE_SOURCE_VM_OS = 'vm_os';
const VIRTUSPHERE_USAGE_SOURCE_VM_INTERFACES = 'vm_interfaces';
const VIRTUSPHERE_USAGE_SOURCE_MISSION_WDS = 'mission_wds';
const VIRTUSPHERE_USAGE_SOURCE_VM_IDENTITY = 'vm_identity';
const VIRTUSPHERE_USAGE_SOURCE_DEPLOY_JOBS = 'deploy_jobs';

// Notes say what a reader could not establish. They are printed beside the
// result and never turn an absent reference into a claim of non-use.
const VIRTUSPHERE_USAGE_NOTE_VM_BINDING_UNPROVEN = 'vm_binding_unproven';
const VIRTUSPHERE_USAGE_NOTE_ANSIBLE_JOBS_ONLY = 'ansible_jobs_only';

// Rows shown per group. The total is always the complete count, so a short list
// says how much it leaves out instead of passing for the whole answer.
const VIRTUSPHERE_USAGE_ROW_LIMIT = 10;
const VIRTUSPHERE_USAGE_ROW_LIMIT_MAX = 50;

/**
 * The usage id a portal page accepts from its query string: one decimal number
 * inside the INT range. Anything else (an array, "12abc", a leading zero, an
 * overflow) is 0, which the page reads as "no row selected".
 */
function usage_evidence_requested_id(mixed $raw): int
{
    if (is_int($raw)) {
        return $raw > 0 && $raw <= 2147483647 ? $raw : 0;
    }
    if (!is_string($raw) || preg_match('/\A[1-9][0-9]{0,9}\z/', $raw) !== 1) {
        return 0;
    }
    $id = (int) $raw;

    return $id <= 2147483647 ? $id : 0;
}

function usage_evidence_clamp_limit(int $limit): int
{
    return max(1, min(VIRTUSPHERE_USAGE_ROW_LIMIT_MAX, $limit));
}

/** Rows that the bounded list does not show. Never negative. */
function usage_evidence_hidden_count(int $total, int $shown): int
{
    return max(0, $total - $shown);
}

/**
 * True when no applicable group holds a reference. The page then says that the
 * object has no known use, and the sources and notes say how far that claim goes.
 *
 * @param array<string, mixed> $evidence
 */
function usage_evidence_is_unused(array $evidence): bool
{
    foreach (VIRTUSPHERE_USAGE_GROUPS as $key) {
        $group = $evidence['groups'][$key] ?? null;
        if (is_array($group) && ($group['applies'] ?? false) === true && (int) ($group['total'] ?? 0) > 0) {
            return false;
        }
    }

    return true;
}

/**
 * The listed row an expansion belongs to. A page renders its expansion only when
 * the selected id is one of the rows it is showing under the current filter.
 *
 * @param list<array<string, mixed>> $rows
 * @return array<string, mixed>|null
 */
function usage_evidence_find_row(array $rows, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) === $id) {
            return $row;
        }
    }

    return null;
}

/** Anchor of a catalog expansion row. The link and the row use the same value. */
function usage_evidence_anchor(int $id): string
{
    return 'usage-' . $id;
}

/** Anchor of a credential's details row. The credential page names its rows this way. */
function usage_evidence_credential_anchor(int $id): string
{
    return 'credential-usage-' . $id;
}
