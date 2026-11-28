<?php

declare(strict_types=1);

/**
 * The last evidence of a VM behind a worker mark (VB4 "Phase aus Belegen",
 * 25.11.2026). Pure and read-only: no database, no writer.
 *
 * The deploy worker writes two lifecycle values that say nothing about what a
 * VM has reported: `deploying` when a job claims it, and `failed` when a job
 * that reached it on ESXi fails or is cancelled. With `failed` it also sets
 * the MECM axis to `deploy_failed` (lib/deploy_worker_vm_state.php,
 * lib/deploy_worker_vm_journal.php). None of these writes touches the stored
 * x/5 value `vm_status`.
 *
 * That value is written only together with a state, by the writers that
 * deliver evidence: creation (2/5), the MAC import and "reset MECM ID" (3/5),
 * the device sync (4/5) and the client ACK (5/5). Behind a worker mark it
 * therefore still names the last report that arrived, including one that
 * arrived while the job ran, and after a reset it names the new rollout. That
 * is why it is read here and not the journal's prestate (captured at the claim,
 * gone with the job) or the status history (best effort, age-limited).
 *
 * The value stays stored and on the wire as it is (ADR-0008); the machine API
 * keeps computing its own from the two axes. A stage below the MAC import, an
 * unknown string and a row that was read without the column are no evidence:
 * the phase then falls to the binding, as it always did.
 *
 * Not decided here: a Create that replaces a deleted instance (IDR-P02) leaves
 * lifecycle, MECM axis and x/5 with the operator. The phase after such a job is
 * the same whether it failed or succeeded.
 */

require_once __DIR__ . '/constants.php';

/** The lifecycle values the worker writes as a mark, never as evidence. */
const VIRTUSPHERE_VM_WORKER_MARK_LIFECYCLES = [
    VIRTUSPHERE_LIFECYCLE_DEPLOYING,
    VIRTUSPHERE_LIFECYCLE_FAILED,
];

/** The stored x/5 values that record a received report, lowest first. */
const VIRTUSPHERE_VM_EVIDENCE_STAGES = [
    VIRTUSPHERE_STATUS_DEPLOYED,
    VIRTUSPHERE_STATUS_OS_INSTALLING,
    VIRTUSPHERE_STATUS_OS_INSTALLED,
];

/**
 * The stored x/5 value of the last evidence behind a worker mark, or '' when
 * the lifecycle is no mark or the stored value records no report. Exact
 * comparison: the writers store the constants, nothing is trimmed or folded.
 *
 * @param array<string,mixed> $vm lifecycle_state, vm_status
 */
function virtusphere_vm_marked_stage(array $vm): string
{
    if (!in_array((string) ($vm['lifecycle_state'] ?? ''), VIRTUSPHERE_VM_WORKER_MARK_LIFECYCLES, true)) {
        return '';
    }
    $stage = (string) ($vm['vm_status'] ?? '');

    return in_array($stage, VIRTUSPHERE_VM_EVIDENCE_STAGES, true) ? $stage : '';
}

/**
 * The SQL twin of `virtusphere_vm_marked_stage($vm) === $stage` over deploy_vms
 * (alias $vm). The column is compared as bytes, so the database collation
 * cannot match a value the PHP rule does not. $stage must be one of
 * VIRTUSPHERE_VM_EVIDENCE_STAGES; both literal kinds are constants of this
 * module and are checked against a closed class before they enter the string.
 */
function virtusphere_vm_marked_stage_sql(string $vm, string $stage): string
{
    if (!in_array($stage, VIRTUSPHERE_VM_EVIDENCE_STAGES, true) || preg_match('/\A[1-5]\/5 [A-Za-z ]+\z/', $stage) !== 1) {
        throw new InvalidArgumentException('Not a stored evidence stage: ' . $stage);
    }
    $marks = [];
    foreach (VIRTUSPHERE_VM_WORKER_MARK_LIFECYCLES as $mark) {
        if (preg_match('/\A[a-z_]+\z/', $mark) !== 1) {
            throw new InvalidArgumentException('Not a SQL-safe state token: ' . $mark);
        }
        $marks[] = "'" . $mark . "'";
    }

    return '(' . $vm . '.lifecycle_state IN (' . implode(', ', $marks) . ')'
        . ' AND CAST(' . $vm . ".vm_status AS BINARY) = '" . $stage . "')";
}
