<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_job_result.php';
require_once __DIR__ . '/deploy_create_result.php';
require_once __DIR__ . '/ansible_command.php';

/**
 * A generic partial summary is evidence of a stopped Create section only when
 * its source job and complete materialized units agree. A MAC result has its
 * own protocol and never enters this branch. This identifies the result; the
 * shared retry gate still refuses every unresolved unit and missing VM.
 *
 * @param array<string,mixed> $job
 * @param array<string,mixed> $payload Normalized original payload.
 * @param list<array<string,mixed>> $rows In source position order.
 */
function deploy_create_retry_summary_is_valid(array $job, array $payload, array $rows): bool
{
    $summary = deploy_job_decode_terminal_result($job['result_json'] ?? null);
    if ($summary === null
        || $summary['outcome'] !== VIRTUSPHERE_DEPLOY_STATUS_PARTIAL
        || ($job['status'] ?? null) !== VIRTUSPHERE_DEPLOY_STATUS_PARTIAL
        || !ansible_mode_creates_vms((string) ($payload['mode'] ?? ''))
        || count($rows) < 2) {
        return false;
    }

    $ids = [];
    $resultIds = [];
    $successes = 0;
    foreach ($rows as $index => $row) {
        $status = $row['status'] ?? null;
        $vmId = (int) ($row['vm_id'] ?? 0);
        $id = (int) ($row['id'] ?? 0);
        if ((int) ($row['job_id'] ?? 0) !== (int) ($job['id'] ?? 0)
            || (int) ($job['id'] ?? 0) <= 0
            || $id <= 0 || isset($resultIds[$id])
            || $vmId <= 0 || isset($ids[$vmId])
            || (int) ($row['position'] ?? 0) !== $index + 1
            || (int) ($row['total'] ?? 0) !== count($rows)
            || !is_string($row['vm_name'] ?? null) || $row['vm_name'] === ''
            || !in_array($row['action'] ?? null, VIRTUSPHERE_CREATE_ACTIONS, true)
            || !in_array($status, VIRTUSPHERE_CREATE_RESULT_STATUSES, true)) {
            return false;
        }
        try {
            deploy_create_assert_transition_fields($status, $row);
        } catch (InvalidArgumentException | DomainException) {
            return false;
        }
        $successful = in_array($status, VIRTUSPHERE_CREATE_RESULT_SUCCESSFUL_STATUSES, true);
        $processed = in_array($status, VIRTUSPHERE_CREATE_RESULT_PROCESSED_STATUSES, true);
        if ($processed !== (is_string($row['finished_at'] ?? null) && $row['finished_at'] !== '')
            || (!$successful && ($row['outcome'] ?? null) !== null)) {
            return false;
        }
        if ($successful) {
            if (!in_array($row['changed'] ?? null, [0, 1, '0', '1'], true)
                || !in_array($row['existed_before'] ?? null, [0, 1, '0', '1'], true)) {
                return false;
            }
            $successes++;
        }
        $ids[$vmId] = $vmId;
        $resultIds[$id] = true;
    }

    // The queue persists an explicit scope. Its normalized input order may
    // differ from the unit order (vm_name, id); compare membership, never widen
    // an empty or incomplete payload to the mission's current VM selection.
    $expected = $payload['vm_ids'] ?? [];
    $actual = array_values($ids);
    sort($expected, SORT_NUMERIC);
    sort($actual, SORT_NUMERIC);

    return $expected !== [] && $expected === $actual
        && $successes > 0 && $successes < count($rows);
}
