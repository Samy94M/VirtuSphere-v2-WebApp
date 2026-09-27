<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/mecm_plan.php';
require_once __DIR__ . '/mecm_hostname.php';
require_once __DIR__ . '/repo/helpers.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/repo/vms.php';

/**
 * The one owner of an interactive VM save and its audit rows (MECM plan
 * decision 32, shared with the identity plan). The write, the vm.changed row
 * and, when the desired Windows name moved, the vm.rollout_hostname row commit
 * or roll back together: before this, repo_save_vm() committed first and a
 * failed audit left a saved change without a trace.
 *
 * $before is the stored bundle read before the save (empty for a new VM). The
 * diff compares stored row against stored row, never raw POST values, because
 * repo_save_vm() normalizes on the way in (an emptied hostname falls back to the
 * VM name) and the audit trail must never claim a change that was not
 * persisted. The legacy vm_disk summary and the free-text notes are withheld;
 * interfaces, disks and packages are child rows and outside this scalar diff.
 *
 * @param array<string,mixed> $before
 * @param array<string,mixed> $vmData
 * @param list<array<string,mixed>> $interfaces
 * @param list<array<string,mixed>> $disks
 * @param list<array<string,mixed>> $packages
 * @return array{vm_id:int, frozen_snapshot:string}
 */
function vm_save_with_audit(
    mysqli $db,
    int $missionId,
    int $vmId,
    array $before,
    bool $isTemplate,
    array $vmData,
    array $interfaces,
    array $disks,
    array $packages,
    string $editVersion,
    int $userId
): array {
    return repo_transaction($db, static function () use (
        $db, $missionId, $vmId, $before, $isTemplate, $vmData, $interfaces, $disks, $packages, $editVersion, $userId
    ): array {
        $savedVmId = repo_save_vm($db, $missionId, $vmId > 0 ? $vmId : null, $vmData, $interfaces, $disks, $packages, $editVersion, $userId, requireVersion: true);

        $auditContext = ['action' => $vmId > 0 ? 'updated' : 'created', 'mission_id' => $missionId];
        $savedVm = [];
        if ($vmId > 0) {
            $savedVm = repo_get_vm_bundle($db, $savedVmId) ?? [];
            $auditColumns = array_diff_key($vmData, array_flip(['vm_disk', 'vm_notes']));
            $changes = audit_change_summary($before, array_intersect_key($savedVm, $auditColumns));
            // A no-op save is a real event under optimistic locking, it just
            // carries no diff: the registry refuses an empty `changes` value.
            if ($changes !== '') {
                $auditContext['changes'] = $changes;
            }
        }
        audit_event_required($db, VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED, 'vm', $savedVmId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, $auditContext, $userId);

        // Etappe 14D: a moved desired Windows name gets its own event, because
        // the question it answers is "did this rollout take it": current_pending
        // says the waiting hand-off picked it up, next_rollout that the snapshot
        // is frozen and only a reset activates it. Compared by normalised key,
        // so a pure change of spelling stays a vm.changed diff only.
        $frozenSnapshot = '';
        if ($vmId > 0 && !$isTemplate) {
            $oldHostname = (string) ($before['vm_hostname'] ?? '');
            $newHostname = (string) ($savedVm['vm_hostname'] ?? '');
            if (mecm_hostname_key($oldHostname) !== mecm_hostname_key($newHostname)) {
                $snapshot = (string) ($savedVm['mecm_rollout_hostname'] ?? '');
                $effect = mecm_hostname_same($snapshot, $newHostname) ? 'current_pending' : 'next_rollout';
                if ($effect === 'next_rollout') {
                    $frozenSnapshot = $snapshot;
                }
                // Optional fields are omitted when empty: the registry refuses a
                // blank context value.
                $rolloutContext = [
                    'action' => 'updated',
                    'mission_id' => $missionId,
                    'effect' => $effect,
                    'new_value' => $newHostname,
                    'rollout_revision' => (int) ($savedVm['mecm_rollout_revision'] ?? 0),
                ];
                if ($oldHostname !== '') {
                    $rolloutContext['old_value'] = $oldHostname;
                }
                if ($snapshot !== '') {
                    $rolloutContext['rollout_hostname'] = $snapshot;
                }
                audit_event_required($db, VIRTUSPHERE_AUDIT_EVENT_VM_ROLLOUT_HOSTNAME, 'vm', $savedVmId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, $rolloutContext, $userId);
            }
        }

        return ['vm_id' => $savedVmId, 'frozen_snapshot' => $frozenSnapshot];
    });
}

/**
 * Queues a registered VM's current assignments for MECM and records the
 * operator action in the same transaction. The mission lock also serializes
 * the revision check with repo_save_vm(), which takes that lock before it
 * rewrites packages or the OS. A stale preview cannot authorize a newer save.
 */
function vm_queue_mecm_transfer_with_audit(
    mysqli $db,
    int $missionId,
    int $vmId,
    string $expectedRevision,
    int $userId
): void {
    repo_transaction($db, static function () use ($db, $missionId, $vmId, $expectedRevision, $userId): void {
        $mission = repo_deploy_lock_mission($db, $missionId);
        if ($mission === null) {
            throw new RuntimeException('Mission not found.');
        }
        if (mission_name_is_template((string) $mission['mission_name'])) {
            throw new RuntimeException(__t('portal.vm_mecm_reset_template_blocked'));
        }

        $transferState = mecm_transfer_state($db, $missionId, $vmId);
        if (!hash_equals($transferState['revision'], $expectedRevision)) {
            throw new RuntimeException(__t('portal.vm_mecm_transfer_stale'));
        }

        repo_mark_vm_for_mecm_resync($db, $missionId, $vmId, $userId);
        audit_event_required($db, VIRTUSPHERE_AUDIT_EVENT_VM_MECM_CHANGED, 'vm', $vmId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
            'action' => 'queued_mecm_transfer',
            'mission_id' => $missionId,
        ], $userId);
    });
}
