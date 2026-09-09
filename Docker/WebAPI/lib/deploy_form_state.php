<?php

declare(strict_types=1);

require_once __DIR__ . '/forms.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/repo/deploy_job_input.php';

/**
 * Where the deploy queue form takes its field values from.
 *
 * The form re-renders on three paths and only one of them was ever restored:
 *
 *  - after a failed validation, from the sticky stash the redirect left behind
 *    (form_remember()),
 *  - on the schedule preview, which answers the POST directly instead of
 *    redirecting, so the request itself is the newest truth,
 *  - after queue-form navigation, which deploy_form.js turns into a GET because
 *    the VM list, the storage table and the per-host warnings are rendered
 *    server-side and only exist for the selected mission.
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
 * The form's other controls are deliberately absent: `action` is the dispatch
 * key the form sets itself, `verbose` is a checkbox whose absence is its "off"
 * value, `vm_ids[]` is a selection bound to one mission, and the selection's
 * mission marker is provenance rather than a job field. See
 * deploy_form_vm_selection(); tests/Static/DeployFormStateContractTest.php
 * pins the list against the form's real controls in both directions.
 */
const VIRTUSPHERE_DEPLOY_QUEUE_FIELDS = [
    'mission_id',
    'credential_esxi_id',
    'credential_ansible_id',
    'mode',
    'powercycle_wait',
    'start_wait',
    'start_mode',
    'scheduled_at',
    'stagger_minutes',
];

/**
 * Form/query provenance for a carried VM checkbox selection. Its value is the
 * mission whose checkbox rows produced `vm_ids[]`; it is deliberately outside
 * VIRTUSPHERE_DEPLOY_QUEUE_FIELDS and therefore never enters a job payload.
 */
const VIRTUSPHERE_DEPLOY_VM_SELECTION_MISSION_FIELD = 'vm_selection_mission_id';

/**
 * Canonical queue input shared by the HTML handler, the read-only live blocker
 * endpoint and the final backend recheck.
 *
 * `vm_selection_explicit` is true only for the portal form's mission-bound
 * checkbox list. Repository/internal callers that omit the marker retain the
 * established `vm_ids=[]` meaning of the whole mission.
 *
 * @return array{mission_id:int,credential_esxi_id:int,credential_ansible_id:int,mode:string,powercycle_wait:mixed,start_wait:mixed,start_mode:string,scheduled_at:string,stagger_minutes:mixed,verbose:bool,vm_ids:list<int>,vm_selection_explicit:bool}
 */
function deploy_queue_normalize_input(array $input): array
{
    $missionId = max(0, request_int($input, 'mission_id'));
    $selectionMissionId = max(0, request_int($input, VIRTUSPHERE_DEPLOY_VM_SELECTION_MISSION_FIELD));
    $selectionExplicit = filter_var($input['vm_selection_explicit'] ?? false, FILTER_VALIDATE_BOOLEAN)
        || ($missionId > 0 && $selectionMissionId === $missionId);
    $vmIds = [];
    $submittedVmIds = is_array($input['vm_ids'] ?? null) ? $input['vm_ids'] : [];
    foreach ($submittedVmIds as $vmId) {
        if (is_scalar($vmId) && (int) $vmId > 0) {
            $vmIds[(int) $vmId] = true;
        }
    }

    return [
        'mission_id' => $missionId,
        'credential_esxi_id' => max(0, request_int($input, 'credential_esxi_id')),
        'credential_ansible_id' => max(0, request_int($input, 'credential_ansible_id')),
        'mode' => deploy_job_normalize_mission_mode(request_string($input, 'mode', VIRTUSPHERE_DEPLOY_MODE_FULL)),
        'powercycle_wait' => $input['powercycle_wait'] ?? VIRTUSPHERE_POWERCYCLE_WAIT_DEFAULT,
        'start_wait' => $input['start_wait'] ?? VIRTUSPHERE_START_WAIT_SECONDS_DEFAULT,
        'start_mode' => request_string($input, 'start_mode', 'now'),
        'scheduled_at' => request_string($input, 'scheduled_at'),
        'stagger_minutes' => $input['stagger_minutes'] ?? '',
        'verbose' => filter_var($input['verbose'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'vm_ids' => array_keys($vmIds),
        // Keep normalization idempotent: the live endpoint and final queue gate
        // pass their already-normalized state into deploy_queue_blockers().
        'vm_selection_explicit' => $selectionExplicit,
    ];
}

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
 * A GET can be either a same-mission navigation (for example the job filter) or
 * a real mission change. deploy_form.js marks only the former with the mission
 * that owns the carried checkbox rows. Matching provenance restores the exact
 * selection, including an absent `vm_ids` key as explicitly empty; missing or
 * mismatched provenance returns null so a new mission starts fully checked.
 *
 * POST and sticky state always belong to the mission in that one chosen source
 * and must reflect exactly what was submitted, or a corrected resubmit silently
 * widens the deploy to the whole mission, which is what the preview render did
 * while the preview above it listed the subset.
 *
 * @return array<int, true>|null
 */
function deploy_form_vm_selection(): ?array
{
    $state = deploy_form_state();
    if ($state['kind'] === 'query') {
        $missionId = max(0, request_int($state['values'], 'mission_id'));
        $selectionMissionId = max(0, request_int(
            $state['values'],
            VIRTUSPHERE_DEPLOY_VM_SELECTION_MISSION_FIELD
        ));
        if ($missionId === 0 || $selectionMissionId !== $missionId) {
            return null;
        }
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
