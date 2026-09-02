<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_create_constants.php';

/**
 * The create unit state machine and its summary, without a database.
 *
 * Everything here is a decision about rows, never a read or a write. The repo
 * layer (lib/repo/deploy_create_results.php) owns the SQL and performs each
 * transition as a compare-and-swap; it asks this module first whether the
 * transition exists and whether the row it is about to write would still be a
 * legal row. Keeping the two apart is what lets the whole machine be driven in
 * a unit test with no MySQL, including the transitions that are hard to
 * provoke against a real server.
 */

/**
 * The closed transition table (plan section 7.1). A status not listed as a key
 * is terminal inside its job: succeeded, failed and skipped never move again.
 *
 * Two entries are easy to misread and are deliberate. `running -> running` is
 * the poll refresh: it touches updated_at so the job does not look abandoned
 * between two healthy polls. And `uncertain` is not terminal, because it is the
 * one status that says VirtuSphere does not know the outcome; it leaves through
 * a resumed poll, through a verified live identity, or through an operator who
 * confirmed that nothing was created.
 *
 * @return array<string, list<string>>
 */
function deploy_create_transitions(): array
{
    return [
        VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING => [
            VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED,
        ],
        VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED => [
            VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING,
            VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
        ],
        VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING => [
            VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING,
            VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
        ],
        VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN => [
            VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING,
            VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
        ],
    ];
}

function deploy_create_transition_allowed(string $from, string $to): bool
{
    return in_array($to, deploy_create_transitions()[$from] ?? [], true);
}

/**
 * The outcome of a confirmed module result. There are exactly three legal
 * combinations, and the fourth one is the reason this is a function rather than
 * a ternary at three call sites: "nothing was there and nothing changed" is not
 * a quiet success. It means the module reported success for a VM that the live
 * check had not seen and did not create, which is identity_result_invalid.
 */
function deploy_create_outcome_for(bool $existedBefore, bool $changed): string
{
    if (!$existedBefore && $changed) {
        return VIRTUSPHERE_CREATE_OUTCOME_CREATED;
    }
    if ($existedBefore) {
        return $changed ? VIRTUSPHERE_CREATE_OUTCOME_UPDATED : VIRTUSPHERE_CREATE_OUTCOME_UNCHANGED;
    }

    throw new DomainException(VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID);
}

/**
 * Whether the fields offered for a transition make a legal row (invariants 2
 * to 5 and 15). The database repeats these as CHECK constraints; this is the
 * copy that can say WHICH field is missing, and it runs before a statement so
 * a caller gets a defect report instead of a constraint violation.
 *
 * @param array<string, mixed> $fields
 */
function deploy_create_assert_transition_fields(string $to, array $fields): void
{
    $missing = static function (string ...$keys) use ($fields): array {
        return array_values(array_filter(
            $keys,
            static fn (string $key): bool => ($fields[$key] ?? null) === null || $fields[$key] === ''
        ));
    };

    $required = match ($to) {
        VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED => $missing('existed_before'),
        // Invariant 2. The bound remote handle is NOT part of this list
        // (Etappe 14B, Teiletappe E): it belongs to the remote execution
        // contract, which stays locked until its site acceptance, so a create
        // unit under the legacy transport has none. What the invariant is
        // actually about - the async state of a running unit is durably
        // findable - is satisfied without it: the job id is stored here and the
        // directory is derived from the job's deterministic remote directory
        // (ansible_create_async_dir). The stage that binds a handle adds the
        // requirement back for jobs that carry one; requiring it now would
        // simply mean no unit could ever be started.
        VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING => $missing('async_jid', 'async_deadline_at'),
        VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED => $missing('outcome', 'changed', 'existed_before', 'vm_moid', 'vm_instance_uuid'),
        VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED => $missing('outcome', 'changed', 'existed_before', 'vm_moid', 'vm_instance_uuid', 'resumed_from_result_id'),
        VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
        VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN => $missing('error_code', 'error_detail'),
        default => [],
    };
    if ($required !== []) {
        throw new InvalidArgumentException('Create transition to ' . $to . ' is missing: ' . implode(', ', $required));
    }

    // `changed` is a boolean, so the emptiness test above would accept a false
    // that is genuinely present. It is checked separately against the outcome
    // it claims, which is also where the fourth illegal combination dies.
    if (in_array($to, VIRTUSPHERE_CREATE_RESULT_SUCCESSFUL_STATUSES, true)) {
        $expected = deploy_create_outcome_for((bool) $fields['existed_before'], (bool) $fields['changed']);
        if ($expected !== (string) $fields['outcome']) {
            throw new InvalidArgumentException('Create outcome ' . (string) $fields['outcome'] . ' contradicts its evidence.');
        }
    }
    if (in_array($to, [VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN], true)
        && !in_array((string) $fields['error_code'], VIRTUSPHERE_CREATE_ERROR_CODES, true)) {
        // Free Ansible prose never becomes a code: the code is chosen by the
        // controlled branch that established the failure, and an unknown one is
        // a defect in that branch rather than a new vocabulary entry.
        throw new InvalidArgumentException('Unknown create error code: ' . (string) $fields['error_code']);
    }
}

/**
 * Whether any unit of this job is still in flight. The next unit may only start
 * when this is false (invariant 10), which is what keeps one deploy job to one
 * async job even across a worker restart: the answer comes from the durable
 * rows, not from the worker's memory of what it launched.
 *
 * @param list<array<string, mixed>> $rows
 */
function deploy_create_has_inflight(array $rows): bool
{
    foreach ($rows as $row) {
        if (in_array((string) $row['status'], VIRTUSPHERE_CREATE_RESULT_INFLIGHT_STATUSES, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Position of the next unit to work on, or null when there is none. An in-flight
 * unit answers null even when pending rows exist: an uncertain unit stops the
 * job (invariant 11), and a prepared or running one is the unit itself.
 *
 * @param list<array<string, mixed>> $rows
 */
function deploy_create_next_position(array $rows): ?int
{
    if (deploy_create_has_inflight($rows)) {
        return null;
    }
    $next = null;
    foreach ($rows as $row) {
        if ((string) $row['status'] !== VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING) {
            continue;
        }
        $position = (int) $row['position'];
        if ($next === null || $position < $next) {
            $next = $position;
        }
    }

    return $next;
}

/**
 * The progress a person is shown, counted from the rows rather than from log
 * text (plan section 12.3). Every count describes units, and there is no
 * percentage inside the current unit: nothing reports how far along one
 * vmware_guest call is, and inventing a number would be the same lie the idle
 * timeout told.
 *
 * @param list<array<string, mixed>> $rows
 * @return array{total:int,processed:int,succeeded:int,skipped:int,failed:int,uncertain:int,not_started:int,created:int,updated:int,unchanged:int,current:?array{position:int,vm_name:string,status:string,started_at:?string}}
 */
function deploy_create_summary(array $rows): array
{
    $summary = [
        'total' => count($rows),
        'processed' => 0,
        'succeeded' => 0,
        'skipped' => 0,
        'failed' => 0,
        'uncertain' => 0,
        'not_started' => 0,
        'created' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'current' => null,
    ];
    foreach ($rows as $row) {
        $status = (string) $row['status'];
        if (in_array($status, VIRTUSPHERE_CREATE_RESULT_PROCESSED_STATUSES, true)) {
            $summary['processed']++;
            $summary[$status]++;
        } elseif ($status === VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING) {
            $summary['not_started']++;
        }
        $outcome = isset($row['outcome']) ? (string) $row['outcome'] : '';
        if ($outcome !== '' && in_array($outcome, VIRTUSPHERE_CREATE_OUTCOMES, true)) {
            $summary[$outcome]++;
        }
        // The current unit is the in-flight one. An uncertain unit stays the
        // current one on purpose: it is where the job stopped, and it is what
        // the operator has to decide about.
        if ($summary['current'] === null && in_array($status, VIRTUSPHERE_CREATE_RESULT_INFLIGHT_STATUSES, true)) {
            $summary['current'] = [
                'position' => (int) $row['position'],
                'vm_name' => (string) $row['vm_name'],
                'status' => $status,
                'started_at' => isset($row['started_at']) ? (string) $row['started_at'] : null,
            ];
        }
    }

    return $summary;
}

/**
 * What a retry of this job would have to do: which units it may take over as
 * proven, which it has to create again, and whether it may run at all.
 *
 * A source unit that is still in flight or unresolved blocks the retry
 * entirely instead of being re-created. That is the one thing a retry must
 * never do: start a second create for a VM whose first async job may still be
 * running on the host, which is exactly how the incident would have produced
 * two VMs instead of a missing one.
 *
 * @param list<array<string, mixed>> $rows
 * @return array{blocked:bool,blocking_positions:list<int>,verify:list<array<string,mixed>>,create:list<array<string,mixed>>}
 */
function deploy_create_retry_plan(array $rows): array
{
    $blocking = [];
    $verify = [];
    $create = [];
    foreach ($rows as $row) {
        $status = (string) $row['status'];
        if (in_array($status, VIRTUSPHERE_CREATE_RESULT_INFLIGHT_STATUSES, true)) {
            $blocking[] = (int) $row['position'];
            continue;
        }
        if (in_array($status, VIRTUSPHERE_CREATE_RESULT_SUCCESSFUL_STATUSES, true)) {
            $verify[] = $row;
            continue;
        }
        $create[] = $row;
    }

    return [
        'blocked' => $blocking !== [],
        'blocking_positions' => $blocking,
        'verify' => $verify,
        'create' => $create,
    ];
}

/**
 * Whether every unit of the job ended in a state that counts as created. This
 * is the gate for the following full-pipeline playbooks: power-cycle, export
 * and start must not begin while one VM is missing, failed or unresolved.
 *
 * @param list<array<string, mixed>> $rows
 */
function deploy_create_all_successful(array $rows): bool
{
    if ($rows === []) {
        return false;
    }
    foreach ($rows as $row) {
        if (!in_array((string) $row['status'], VIRTUSPHERE_CREATE_RESULT_SUCCESSFUL_STATUSES, true)) {
            return false;
        }
    }

    return true;
}
