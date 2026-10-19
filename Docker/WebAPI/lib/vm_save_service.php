<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/mecm_plan.php';
require_once __DIR__ . '/mecm_hostname.php';
require_once __DIR__ . '/repo/helpers.php';
require_once __DIR__ . '/repo/log.php';
require_once __DIR__ . '/repo/vms.php';
require_once __DIR__ . '/vm_mecm_save.php';

/**
 * The one owner of an interactive VM save and its audit rows (MECM plan
 * decision 32, shared with the identity plan). The write, the vm.changed row
 * and, when the desired Windows name moved, the vm.rollout_hostname row commit
 * or roll back together: before this, repo_save_vm() committed first and a
 * failed audit left a saved change without a trace. A registered VM's MECM
 * queue (AV-R12, vm_mecm_save.php) joins that transaction.
 *
 * $before is the stored bundle read before the save (empty for a new VM). The
 * diff compares stored row against stored row, never raw POST values, because
 * repo_save_vm() normalizes on the way in (an emptied hostname falls back to the
 * VM name) and the audit trail must never claim a change that was not
 * persisted. The legacy vm_disk summary and the free-text notes are withheld;
 * interfaces, disks and packages are child rows and outside this scalar diff.
 *
 * $mecmMode is the submitted MECM save button ('' for the plain save, see
 * vm_mecm_save.php). It only matters for a registered VM with an OS deviation.
 *
 * @param array<string,mixed> $before
 * @param array<string,mixed> $vmData
 * @param list<array<string,mixed>> $interfaces
 * @param list<array<string,mixed>> $disks
 * @param list<array<string,mixed>> $packages
 * @return array{vm_id:int, frozen_snapshot:string, mecm: array{queued: bool, held: bool, added: list<string>, removed: list<string>}}
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
    int $userId,
    string $mecmMode = ''
): array {
    return repo_transaction($db, static function () use (
        $db, $missionId, $vmId, $before, $isTemplate, $vmData, $interfaces, $disks, $packages, $editVersion, $userId, $mecmMode
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
        // HA-24: the row names the VM as well. Read from the saved row, so it is the stored name.
        $savedName = trim((string) ($savedVm['vm_name'] ?? $vmData['vm_name'] ?? ''));
        if ($savedName !== '') {
            $auditContext['name'] = $savedName;
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

        // AV-R12: the decision reads the stored state after the write, under the
        // mission lock, so the queue and its audit row belong to this transaction.
        $mecm = $vmId > 0 && !$isTemplate
            ? vm_mecm_save_transfer($db, $missionId, $savedVmId, $savedVm, $mecmMode, $userId)
            : ['queued' => false, 'held' => false, 'added' => [], 'removed' => []];

        return ['vm_id' => $savedVmId, 'frozen_snapshot' => $frozenSnapshot, 'mecm' => $mecm];
    });
}
