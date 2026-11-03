<?php

declare(strict_types=1);

require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../deploy_create_result.php';
require_once __DIR__ . '/helpers.php';

/**
 * Retry side of the per-VM create results: resolving the success a
 * verify_skip unit stands for, and materializing a retry job from a source
 * job. Split from deploy_create_results.php (ADR-0006 size budget); that
 * module loads this one, so every caller keeps a single require.
 */

/**
 * Resolve a verify_skip source to the real success, never to another skip.
 * The bounded walk refuses broken or overlong chains.
 *
 * @return array<string, mixed>|null
 */
function repo_deploy_create_skip_source(mysqli $db, int $resultId, int $maxDepth = 8): ?array
{
    for ($depth = 0; $depth < $maxDepth && $resultId > 0; $depth++) {
        $row = repo_fetch_one(
            $db,
            'SELECT id, job_id, vm_id, vm_name, status, outcome, vm_moid, vm_instance_uuid, resumed_from_result_id'
            . ' FROM deploy_create_vm_results WHERE id = ? LIMIT 1',
            'i',
            [$resultId]
        );
        if ($row === null) {
            return null;
        }
        if ((string) $row['status'] === VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED) {
            return $row;
        }
        if ((string) $row['status'] !== VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED) {
            return null;
        }
        $resultId = $row['resumed_from_result_id'] === null ? 0 : (int) $row['resumed_from_result_id'];
    }

    return null;
}

/**
 * Materializes the retry of a create job: a confirmed success becomes a
 * verify_skip unit bound to the row that proved it, everything else becomes a
 * fresh create unit. Positions are renumbered over the whole original
 * selection, so the retry still creates the VMs in the same order.
 *
 * @param list<array<string, mixed>> $sourceRows
 */
function repo_deploy_create_materialize_retry(mysqli $db, int $newJobId, array $sourceRows): int
{
    if ($sourceRows === []) {
        throw new InvalidArgumentException('A create retry needs the source job rows.');
    }
    $plan = deploy_create_retry_plan($sourceRows);
    if ($plan['blocked']) {
        throw new DomainException('The source job still has unresolved create units.');
    }
    $total = count($sourceRows);
    $position = 0;
    foreach ($sourceRows as $row) {
        $position++;
        $successful = in_array((string) $row['status'], VIRTUSPHERE_CREATE_RESULT_SUCCESSFUL_STATUSES, true);
        repo_insert_from_values($db, 'deploy_create_vm_results', [
            'job_id' => $newJobId,
            'vm_id' => $row['vm_id'] !== null ? (int) $row['vm_id'] : null,
            'vm_name' => (string) $row['vm_name'],
            'position' => $position,
            'total' => $total,
            // A proven success is not copied as a success. It is queued as the
            // work of proving it again live, because "it existed an hour ago"
            // is not evidence that it exists now, and the skip is only written
            // once that check passed in THIS job.
            'action' => $successful ? VIRTUSPHERE_CREATE_ACTION_VERIFY_SKIP : VIRTUSPHERE_CREATE_ACTION_CREATE,
            'status' => VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING,
            'resumed_from_result_id' => $successful ? (int) $row['id'] : null,
        ]);
    }

    return $total;
}
