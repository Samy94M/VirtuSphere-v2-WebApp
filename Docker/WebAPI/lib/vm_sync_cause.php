<?php

declare(strict_types=1);

/**
 * The device-sync cause of a waiting VM as finding parameters (MX1, FM-05).
 * Pure: no database, no clock of its own, no writer. The stored row comes from
 * repo_vm_sync_causes_current() (lib/repo/vm_sync_causes.php) through the
 * evidence slice `sync_cause`; lib/vm_phase.php attaches the result to the
 * findings `mecm_pending` and `mecm_pending_overdue` only (Q7: shown at a
 * pending VM, never at `mecm_sync_deploy_failed`).
 *
 * The cause is shown at once (F13); the severity stays with the 2-hour rule of
 * the finding. A cause is never hidden for its age (Q2): once it is older than
 * VIRTUSPHERE_VM_SYNC_CAUSE_AS_OF_AFTER_SECONDS the text names its observation
 * time ("Stand"), so an old report is not read as a fresh one. A code outside
 * the closed device-sync list, which the validator never stores, yields no
 * cause rather than a raw token.
 */

require_once __DIR__ . '/device_sync_cause_constants.php';
require_once __DIR__ . '/status_evidence.php';

const VIRTUSPHERE_VM_SYNC_CAUSE_AS_OF_AFTER_SECONDS = 15 * 60;

/**
 * @param mixed $cause the evidence slice: a row with `code`, `rollout_revision`
 *   and `observed_at`, or null when the sync reported none
 * @return array{}|array{cause:string,cause_revision:int,cause_observed_at:string,cause_as_of:bool}
 */
function virtusphere_vm_sync_cause_params(mixed $cause, int $now): array
{
    if (!is_array($cause) || !in_array($cause['code'] ?? null, VIRTUSPHERE_DEVICE_SYNC_CAUSES, true)) {
        return [];
    }
    $observedAt = (string) ($cause['observed_at'] ?? '');
    $observed = virtusphere_evidence_timestamp($observedAt, $now);

    return [
        'cause' => (string) $cause['code'],
        'cause_revision' => (int) ($cause['rollout_revision'] ?? 0),
        // An unusable time is not claimed; the cause itself still shows.
        'cause_observed_at' => $observed === null ? '' : $observedAt,
        'cause_as_of' => $observed !== null && $now - $observed >= VIRTUSPHERE_VM_SYNC_CAUSE_AS_OF_AFTER_SECONDS,
    ];
}
