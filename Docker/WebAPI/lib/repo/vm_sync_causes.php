<?php

declare(strict_types=1);

/**
 * deploy_vm_sync_causes (migration 0072, MX1/FM-05): the one cause per VM that
 * the MECM device sync last reported as keeping it waiting. This file is the
 * table's only writer, reader and purge.
 *
 * Display data, not a callback: nothing here touches deploy_vms. So the revision
 * fence (ADR-0043) decides PER ENTRY instead of refusing the report with 409. A
 * reset during a scan is planned, and one freshly reset VM must not throw away
 * the causes of every other VM in the same report. An entry whose revision is not
 * the current one, or whose VM is no longer on the device list, is counted as
 * `stale`; one whose VM is gone is counted as `unknown_vm`. Neither writes an
 * audit row (ADR-0043 amendment, docs/ai/contracts/machine.md A67).
 *
 * `complete = true` is the producer's explicit statement that the report is the
 * whole set after a full pass over its getDeviceList snapshot; only then is the
 * rest of the table replaced, in the same transaction. `complete = false` only
 * upserts. Arrival order is the truth; an identical replay changes nothing and is
 * answered with `deduplicated`.
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../device_sync_cause_constants.php';
require_once __DIR__ . '/../mecm_rollout_fence.php';
require_once __DIR__ . '/helpers.php';

/**
 * @param array{run_id:string,complete:bool,causes:list<array{vm_id:int,rollout_revision:int,code:string}>} $report
 *   a report validated by device_sync_causes_validate()
 * @return array{accepted:int,stale:int,unknown_vm:int,deduplicated:bool}
 */
function repo_vm_sync_causes_record(mysqli $db, array $report): array
{
    return repo_transaction($db, static function () use ($db, $report): array {
        $runId = (string) $report['run_id'];
        $causes = $report['causes'];
        $vmIds = array_map(static fn (array $cause): int => (int) $cause['vm_id'], $causes);
        $vms = repo_vm_sync_causes_vm_states($db, $vmIds);
        $stored = repo_vm_sync_causes_rows($db, $vmIds, true);

        $counts = ['accepted' => 0, 'stale' => 0, 'unknown_vm' => 0];
        $changed = false;
        foreach ($causes as $cause) {
            $vmId = (int) $cause['vm_id'];
            $vm = $vms[$vmId] ?? null;
            if ($vm === null) {
                $counts['unknown_vm']++;
                continue;
            }
            if (!repo_vm_sync_causes_admissible($vm, (int) $cause['rollout_revision'])) {
                $counts['stale']++;
                continue;
            }
            $row = $stored[$vmId] ?? null;
            if ($row !== null && $row['run_id'] === $runId && $row['code'] === $cause['code']
                && (int) $row['rollout_revision'] === (int) $cause['rollout_revision']
            ) {
                $counts['accepted']++;
                continue;
            }
            if (repo_vm_sync_causes_upsert($db, $cause, $runId)) {
                $counts['accepted']++;
                $changed = true;
                continue;
            }
            // The VM was deleted, reset or bound between the read above and this
            // write. The conditional write refused it; count what it is now.
            $counts[repo_vm_sync_causes_vm_states($db, [$vmId]) === [] ? 'unknown_vm' : 'stale']++;
        }

        if ($report['complete'] === true) {
            $stmt = $db->prepare('DELETE FROM deploy_vm_sync_causes WHERE run_id <> ?');
            $stmt->bind_param('s', $runId);
            $stmt->execute();
            $changed = $changed || $stmt->affected_rows > 0;
        }

        return $counts + ['deduplicated' => !$changed];
    });
}

/**
 * Whether an entry may be stored for this VM: its revision is the current one
 * (the same pure function every MECM callback uses) and the VM is one
 * getDeviceList hands the sync (`updated = 1 OR mecm_sync_state = pending`).
 *
 * @param array{mecm_rollout_revision:?int,mecm_previous_id:?string,updated:int,mecm_sync_state:string} $vm
 */
function repo_vm_sync_causes_admissible(array $vm, int $revision): bool
{
    return mecm_rollout_fence_revision_current($revision, $vm['mecm_rollout_revision'], $vm['mecm_previous_id'])
        && ($vm['updated'] === 1 || $vm['mecm_sync_state'] === VIRTUSPHERE_MECM_SYNC_PENDING);
}

/**
 * One conditional upsert. The INSERT ... SELECT repeats the admission predicate
 * against the row as it is at write time, so a VM deleted or reset meanwhile
 * gets no row and no foreign-key error. For a reported revision the fence
 * function reduces to "stored revision, or the initial one when NULL, equals the
 * reported one"; the tombstone only matters for a caller without a revision.
 *
 * @param array{vm_id:int,rollout_revision:int,code:string} $cause
 */
function repo_vm_sync_causes_upsert(mysqli $db, array $cause, string $runId): bool
{
    $vmId = (int) $cause['vm_id'];
    $revision = (int) $cause['rollout_revision'];
    $code = (string) $cause['code'];
    $initial = VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL;
    $pending = VIRTUSPHERE_MECM_SYNC_PENDING;
    $stmt = $db->prepare('INSERT INTO deploy_vm_sync_causes (vm_id, code, rollout_revision, run_id, observed_at)
        SELECT v.id, ?, ?, ?, CURRENT_TIMESTAMP
          FROM deploy_vms v
         WHERE v.id = ? AND COALESCE(v.mecm_rollout_revision, ?) = ? AND (v.updated = 1 OR v.mecm_sync_state = ?)
        ON DUPLICATE KEY UPDATE code = ?, rollout_revision = ?, run_id = ?, observed_at = CURRENT_TIMESTAMP');
    $stmt->bind_param('sisiiissis', $code, $revision, $runId, $vmId, $initial, $revision, $pending, $code, $revision, $runId);
    $stmt->execute();

    return $stmt->affected_rows > 0;
}

/**
 * @param list<int> $vmIds
 * @return array<int,array{mecm_rollout_revision:?int,mecm_previous_id:?string,updated:int,mecm_sync_state:string}>
 */
function repo_vm_sync_causes_vm_states(mysqli $db, array $vmIds): array
{
    if ($vmIds === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($vmIds), '?'));
    $stmt = $db->prepare('SELECT id, mecm_rollout_revision, mecm_previous_id, updated, mecm_sync_state
        FROM deploy_vms WHERE id IN (' . $in . ')');
    $stmt->bind_param(str_repeat('i', count($vmIds)), ...$vmIds);
    $stmt->execute();

    $states = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $states[(int) $row['id']] = [
            'mecm_rollout_revision' => $row['mecm_rollout_revision'] === null ? null : (int) $row['mecm_rollout_revision'],
            'mecm_previous_id' => $row['mecm_previous_id'] === null ? null : (string) $row['mecm_previous_id'],
            'updated' => (int) $row['updated'],
            'mecm_sync_state' => (string) $row['mecm_sync_state'],
        ];
    }

    return $states;
}

/**
 * @param list<int> $vmIds
 * @return array<int,array{code:string,rollout_revision:int,run_id:string,observed_at:string}>
 */
function repo_vm_sync_causes_rows(mysqli $db, array $vmIds, bool $forUpdate = false): array
{
    if ($vmIds === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($vmIds), '?'));
    $stmt = $db->prepare('SELECT vm_id, code, rollout_revision, run_id, observed_at
        FROM deploy_vm_sync_causes WHERE vm_id IN (' . $in . ')' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->bind_param(str_repeat('i', count($vmIds)), ...$vmIds);
    $stmt->execute();

    $rows = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $rows[(int) $row['vm_id']] = [
            'code' => (string) $row['code'],
            'rollout_revision' => (int) $row['rollout_revision'],
            'run_id' => (string) $row['run_id'],
            'observed_at' => (string) $row['observed_at'],
        ];
    }

    return $rows;
}

/**
 * The current cause of each given VM: only a row whose revision is the VM's
 * current rollout revision. A row left from before a reset is not a cause of
 * this rollout and is not returned (the next complete report or the retention
 * removes it). Whether a cause is SHOWN is the display's decision (Q7: only at
 * a pending VM); this reader does not filter on the sync state.
 *
 * @param list<int> $vmIds
 * @return array<int,array{code:string,rollout_revision:int,run_id:string,observed_at:string}>
 */
function repo_vm_sync_causes_current(mysqli $db, array $vmIds): array
{
    $vmIds = array_values(array_unique(array_filter(array_map('intval', $vmIds), static fn (int $id): bool => $id > 0)));
    $rows = repo_vm_sync_causes_rows($db, $vmIds);
    if ($rows === []) {
        return [];
    }
    $states = repo_vm_sync_causes_vm_states($db, array_keys($rows));

    $current = [];
    foreach ($rows as $vmId => $row) {
        $vm = $states[$vmId] ?? null;
        if ($vm !== null
            && mecm_rollout_fence_revision_current($row['rollout_revision'], $vm['mecm_rollout_revision'], $vm['mecm_previous_id'])
        ) {
            $current[$vmId] = $row;
        }
    }

    return $current;
}

/** Retention: a cause not observed again within the window is removed. */
function repo_purge_device_sync_causes(mysqli $db, int $retentionDays = VIRTUSPHERE_DEVICE_SYNC_CAUSE_RETENTION_DAYS): int
{
    $stmt = $db->prepare('DELETE FROM deploy_vm_sync_causes WHERE observed_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->bind_param('i', $retentionDays);
    $stmt->execute();

    return $stmt->affected_rows;
}
