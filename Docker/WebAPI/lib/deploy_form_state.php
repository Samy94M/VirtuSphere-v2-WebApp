<?php

declare(strict_types=1);

require_once __DIR__ . '/forms.php';
require_once __DIR__ . '/request.php';

/**
 * Where the deploy queue form takes its field values from.
 *
 * The form re-renders on three paths and only one of them was ever restored:
 *
 *  - after a failed validation, from the sticky stash the redirect left behind
 *    (form_remember()),
 *  - on the schedule preview, which answers the POST directly instead of
 *    redirecting, so the request itself is the newest truth,
 *  - after a mission change, which deploy.js turns into a GET because the VM
 *    list, the storage table and the per-host warnings are rendered server-side
 *    and only exist for the selected mission.
 *
 * Only the first path had a reader, so changing the mission (or filtering the
 * job list, which writes the same mission_id) reset the credential pair, the
 * mode, the wait time and the whole schedule block to their defaults, and the
 * operator filled the form in again.
 *
 * Exactly one source wins per render, chosen once, never per field: an absent
 * key is how a checkbox says "off", so a per-field fallback would let an older
 * source re-check a box the operator had just cleared.
 */

/**
 * The queue form's scalar fields. Every one of them must survive all three
 * paths, and the schedule preview's confirm step re-posts exactly this list.
 *
 * The form's three other controls are deliberately absent: `action` is the
 * dispatch key the form sets itself, `verbose` is a checkbox whose absence is
 * its "off" value, and `vm_ids[]` is a selection bound to one mission (see
 * deploy_form_vm_selection()). tests/Static/DeployFormStateContractTest.php
 * pins the list against the form's real controls in both directions.
 */
const VIRTUSPHERE_DEPLOY_QUEUE_FIELDS = [
    'mission_id',
    'credential_esxi_id',
    'credential_ansible_id',
    'mode',
    'powercycle_wait',
    'start_mode',
    'scheduled_at',
    'stagger_minutes',
];

/**
 * The one source this render reads, plus the kind it is. Memoized, so the
 * choice cannot differ between two fields of the same form.
 *
 * @return array{kind: string, values: array<string, mixed>}
 */
function deploy_form_state(): array
{
    static $state = null;

    if ($state === null) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $state = ['kind' => 'post', 'values' => $_POST];
        } elseif (form_has_state('schedule')) {
            $state = ['kind' => 'sticky', 'values' => form_old_all('schedule')];
        } else {
            $state = ['kind' => 'query', 'values' => $_GET];
        }
    }

    return $state;
}

/** One scalar field of the queue form, or $default when this render carries none. */
function deploy_form_value(string $field, string $default = ''): string
{
    return request_string(deploy_form_state()['values'], $field, $default);
}

/**
 * The checked VM ids as a lookup, or null when this render carries no selection
 * at all and every VM of the mission starts checked.
 *
 * A mission change is the null case on purpose: those checkboxes named the VMs
 * of the mission the operator just left, and the whole new mission is the right
 * default. The two other paths must reflect exactly what was submitted, or a
 * corrected resubmit silently widens the deploy to the whole mission, which is
 * what the preview render did while the preview above it listed the subset.
 *
 * @return array<int, true>|null
 */
function deploy_form_vm_selection(): ?array
{
    $state = deploy_form_state();
    if ($state['kind'] === 'query') {
        return null;
    }

    $submitted = $state['values']['vm_ids'] ?? null;
    if (!is_array($submitted)) {
        // Mirrors form_old_array(): a scalar is not a checkbox list. An empty
        // selection posts no key at all and lands here as "nothing checked".
        return [];
    }

    $selection = [];
    foreach ($submitted as $vmId) {
        // Same normalization the repo applies to the very same payload
        // (deploy_job_normalize_vm_ids), so what the checkboxes show and what
        // an enqueue would use cannot disagree about a forged id.
        if (is_scalar($vmId) && (int) $vmId > 0) {
            $selection[(int) $vmId] = true;
        }
    }

    return $selection;
}
