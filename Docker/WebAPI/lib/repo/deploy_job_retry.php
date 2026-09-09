<?php

declare(strict_types=1);

require_once __DIR__ . '/../mac_import.php';
require_once __DIR__ . '/../remote_execution_constants.php';
require_once __DIR__ . '/deploy_job_input.php';
require_once __DIR__ . '/deploy_create_results.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vm_identity.php';
require_once __DIR__ . '/vm_network.php';
require_once __DIR__ . '/../vm_network_preflight_result.php';
require_once __DIR__ . '/../deploy_create_retry_evidence.php';

final class DeployRetryBlockedException extends RuntimeException
{
    /** @param array<string,mixed> $evaluation */
    public function __construct(public readonly array $evaluation)
    {
        parent::__construct('Deploy retry is blocked by the current safety contract.');
    }
}

/** @return array<string,mixed>|null */
function repo_deploy_retry_job(mysqli $db, int $jobId, bool $lock = false): ?array
{
    return repo_fetch_one(
        $db,
        'SELECT id, mission_id, status, payload_json, result_json, credential_esxi_id, credential_ansible_id, '
        . 'execution_contract, LOWER(HEX(execution_generation_id)) AS execution_generation_id, '
        . 'recovery_requested_at, attempts '
        . 'FROM deploy_jobs WHERE id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
        'i',
        [$jobId]
    );
}

/**
 * The sole retry decision. Findings are emitted in invariant order: remote,
 * identity, current portal network, then confirmable external history.
 *
 * @return array{retryable:bool,allowed:bool,effective_mode:string,scope_vm_ids:list<int>,findings:list<array<string,mixed>>,blocking_findings:list<array<string,mixed>>,external_confirmation:bool,network_ready:bool,repair_vm_id:?int,plan:?array,source_create_rows:list<array<string,mixed>>}
 */
function deploy_retry_blockers(mysqli $db, int $jobId, bool $lock = false): array
{
    $preliminary = repo_deploy_retry_job($db, $jobId, false);
    $missionId = (int) ($preliminary['mission_id'] ?? 0);
    if ($preliminary === null || $missionId <= 0) {
        return deploy_retry_evaluation_unavailable('retry_job_missing');
    }

    $mission = repo_fetch_one(
        $db,
        'SELECT id, wds_vlan FROM deploy_missions WHERE id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
        'i',
        [$missionId]
    );
    $job = repo_deploy_retry_job($db, $jobId, $lock);
    if ($mission === null || $job === null || (int) $job['mission_id'] !== $missionId
        || !deploy_job_is_retryable((string) $job['status'], $missionId)) {
        return deploy_retry_evaluation_unavailable('retry_job_not_terminal');
    }

    $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
    if (!is_array($payload)) {
        return deploy_retry_evaluation_unavailable('retry_payload_unreadable');
    }
    try {
        $originalPayload = deploy_job_payload($payload);
    } catch (Throwable) {
        return deploy_retry_evaluation_unavailable('retry_payload_unreadable');
    }

    $rawResult = trim((string) ($job['result_json'] ?? ''));
    $result = mac_import_decode_result($rawResult === '' ? null : $rawResult);
    $networkPreflightResult = vm_network_preflight_decode_result($rawResult === '' ? null : $rawResult);
    $isValidNetworkPreflightResult = $networkPreflightResult !== null
        && (string) $job['status'] === VIRTUSPHERE_DEPLOY_STATUS_FAILED
        && (string) $networkPreflightResult['mode'] === (string) $originalPayload['mode'];
    $findings = [];

    // Remote execution owns the first decision. A retry cannot race a durable
    // handle or cross a runtime generation that is no longer ours.
    if ($job['recovery_requested_at'] !== null) {
        $findings[] = deploy_retry_finding('remote', 'retry_recovery_pending', true);
    }
    $runtime = repo_fetch_one(
        $db,
        'SELECT LOWER(HEX(current_generation_id)) AS generation_id FROM deploy_runtime_identity WHERE id = 1 LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
    );
    $runtimeGeneration = (string) ($runtime['generation_id'] ?? '');
    $stmt = $db->prepare(
        'SELECT controller_state, reconciliation_state, LOWER(HEX(generation_id)) AS generation_id '
        . 'FROM deploy_remote_executions WHERE job_id = ? ORDER BY id' . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $remoteRows = repo_fetch_all($stmt->get_result());
    foreach ($remoteRows as $remote) {
        $controller = (string) ($remote['controller_state'] ?? '');
        $reconciliation = (string) ($remote['reconciliation_state'] ?? '');
        $remoteGeneration = (string) ($remote['generation_id'] ?? '');
        $unresolved = in_array($controller, ['prepared', 'active', 'lost_after_start'], true)
            || in_array($reconciliation, ['pending', 'running', 'manual_required'], true);
        if (!$unresolved) {
            continue;
        }
        $foreignGeneration = preg_match('/^[a-f0-9]{32}$/', $runtimeGeneration) !== 1
            || preg_match('/^[a-f0-9]{32}$/', $remoteGeneration) !== 1
            || !hash_equals($runtimeGeneration, $remoteGeneration);
        $findings[] = deploy_retry_finding(
            'remote',
            $foreignGeneration ? 'retry_foreign_generation' : 'retry_remote_execution_unresolved',
            true
        );
        break;
    }

    // One source read and one plan serve the verdict, confirmation and write.
    // Runtime and remote handles precede Create units in the locking path.
    $sourceCreateRows = repo_deploy_create_results($db, $jobId, $lock);
    $isCreateSummary = deploy_create_retry_summary_is_valid($job, $originalPayload, $sourceCreateRows);
    $plan = $result === null ? null : deploy_job_retry_plan(
        (string) $job['status'],
        $result,
        $originalPayload['vm_ids']
    );
    $mode = $plan !== null ? (string) $plan['mode'] : (string) $originalPayload['mode'];
    if ($plan === null && ansible_mode_creates_vms($mode) && $sourceCreateRows !== []) {
        $plan = ['mode' => $mode, 'vm_ids' => deploy_create_retry_vm_ids($sourceCreateRows), 'scope' => 'create_units'];
    }
    $scopeIds = $plan !== null ? $plan['vm_ids'] : $originalPayload['vm_ids'];
    $resultProtocolError = ((string) $job['status'] === VIRTUSPHERE_DEPLOY_STATUS_PARTIAL || $rawResult !== '')
        && $result === null && !$isValidNetworkPreflightResult && !$isCreateSummary;
    if ($resultProtocolError) {
        $findings[] = deploy_retry_finding('identity', 'retry_result_protocol_error', true);
    }

    // The per-VM create evidence (Etappe 14B-F). It is asked before anything
    // about the network or the MAC result, because it answers a different
    // question: whether work of the SOURCE job may still be running on the
    // host. A retry that starts while one async create of this mission is
    // unresolved is how one VM becomes two.
    foreach (deploy_create_retry_findings($db, $missionId, $mode, $sourceCreateRows) as $createFinding) {
        $findings[] = $createFinding;
    }

    // The current identity view, not the historical red result, decides if an
    // adoption/recovery has already repaired this scope.
    foreach (repo_vm_identity_conflicts($db, $missionId, (int) $job['credential_esxi_id'], $scopeIds) as $conflict) {
        $findings[] = deploy_retry_finding('identity', 'retry_identity_unresolved', true, $conflict);
    }

    if ($result !== null) {
        foreach ((array) $result['errors'] as $error) {
            $code = (string) ($error['code'] ?? '');
            $meta = VIRTUSPHERE_MAC_IMPORT_ERROR_META[$code] ?? null;
            if (!is_array($meta)) {
                $findings[] = deploy_retry_finding('identity', 'retry_result_protocol_error', true, $error);
                continue;
            }
            $retryClass = (string) $meta['retry'];
            if (in_array($retryClass, ['manual', 'identity_blocked'], true)) {
                $findings[] = deploy_retry_finding('identity', $code, true, $error);
            }
        }
    }

    $preflight = repo_vm_network_preflight($db, $missionId, $scopeIds, (string) ($mission['wds_vlan'] ?? ''), $lock);
    $networkBlockers = repo_vm_network_preflight_blockers($preflight, $mode);
    foreach ($networkBlockers as $blocker) {
        $findings[] = deploy_retry_finding('network', (string) ($blocker['code'] ?? 'network_invalid'), true, $blocker);
    }
    $networkReady = $networkBlockers === [];

    $externalConfirmation = false;
    if ($result !== null) {
        foreach ((array) $result['errors'] as $error) {
            $code = (string) ($error['code'] ?? '');
            $meta = VIRTUSPHERE_MAC_IMPORT_ERROR_META[$code] ?? null;
            if (!is_array($meta)) {
                continue;
            }
            $external = (string) $meta['retry'] === 'external_confirm'
                || ($code === VIRTUSPHERE_MAC_IMPORT_ERROR_AMBIGUOUS_VLAN
                    && in_array((string) ($error['ambiguity_source'] ?? ''), ['', 'esxi', 'both'], true));
            if ($external) {
                $externalConfirmation = true;
                $findings[] = deploy_retry_finding('external', $code, false, $error);
            }
        }
    }

    $blocking = array_values(array_filter($findings, static fn (array $finding): bool => (bool) $finding['blocking']));
    $repairVmId = null;
    foreach ($findings as $finding) {
        if ($finding['kind'] === 'network' && (int) ($finding['vm_id'] ?? 0) > 0) {
            $repairVmId = (int) $finding['vm_id'];
            break;
        }
    }

    return [
        'retryable' => true,
        'allowed' => $blocking === [],
        'effective_mode' => $mode,
        'scope_vm_ids' => $scopeIds,
        'findings' => $findings,
        'blocking_findings' => $blocking,
        'external_confirmation' => $externalConfirmation,
        'network_ready' => $networkReady,
        'repair_vm_id' => $repairVmId,
        'plan' => $plan,
        'source_create_rows' => ansible_mode_creates_vms($mode) ? $sourceCreateRows : [],
    ];
}

/**
 * The context is merged UNDER the verdict, never over it.
 *
 * The other way round the context wins, and the one caller that must replace a
 * code is exactly the one that passes the offending value as context: an
 * unknown MAC error code is reported as `retry_result_protocol_error`, but
 * `array_merge(verdict, $error)` put `$error['code']` back on top and the
 * finding came out carrying the unknown code it was supposed to translate. The
 * retry gate then blocked with a code no presenter, no help text and no test
 * vocabulary knows. Every other caller derives its code FROM the context, so
 * for them the two orders are identical and this costs nothing.
 *
 * @return array<string,mixed>
 */
function deploy_retry_finding(string $kind, string $code, bool $blocking, array $context = []): array
{
    return array_merge($context, ['kind' => $kind, 'code' => $code, 'blocking' => $blocking]);
}

/** @param list<array<string,mixed>> $findings @param list<string> $kinds */
function deploy_retry_has_blocking_kind(array $findings, array $kinds): bool
{
    foreach ($findings as $finding) {
        if ((bool) ($finding['blocking'] ?? false) && in_array((string) ($finding['kind'] ?? ''), $kinds, true)) {
            return true;
        }
    }
    return false;
}

/** @return array<string,mixed> */
function deploy_retry_evaluation_unavailable(string $code): array
{
    $finding = deploy_retry_finding('identity', $code, true);
    return [
        'retryable' => false,
        'allowed' => false,
        'effective_mode' => '',
        'scope_vm_ids' => [],
        'findings' => [$finding],
        'blocking_findings' => [$finding],
        'external_confirmation' => false,
        'network_ready' => false,
        'repair_vm_id' => null,
        'plan' => null,
        'source_create_rows' => [],
    ];
}

/**
 * What the source job's create rows say about retrying it (plan section 10.2
 * and 10.4).
 *
 * Three closed answers, and the order is the order of certainty:
 *
 *  - a unit that is still prepared, running or unresolved blocks everything.
 *    Its async job may be alive on the Ansible host, and the only thing worse
 *    than a job that did not finish is two jobs creating the same VM;
 *  - a VM of the original selection that no longer exists blocks too, because
 *    the retry would materialize a unit the worker refuses at its first check;
 *  - a source job with NO create rows at all that would create VMs is refused
 *    fail-closed (10.4). It has no per-VM evidence, so a retry could only
 *    invent one; the operator checks ESXi, adopts what is really theirs, and
 *    queues a fresh job through the form.
 *
 * A source whose retry no longer creates anything (the export-only follow-up
 * of a partial job) is not asked the third question: it materializes no create
 * units and claims nothing about them.
 *
 * @return list<array<string,mixed>>
 */
function deploy_create_retry_findings(mysqli $db, int $missionId, string $mode, array $rows): array
{
    if ($rows === []) {
        return ansible_mode_creates_vms($mode)
            ? [deploy_retry_finding('create', 'retry_create_results_missing', true)]
            : [];
    }

    $plan = deploy_create_retry_plan($rows);
    if ($plan['blocked']) {
        return [deploy_retry_finding('create', 'retry_create_unresolved', true, [
            'position' => $plan['blocking_positions'][0],
            'unresolved_count' => count($plan['blocking_positions']),
        ])];
    }

    $sourceVmIds = array_values(array_filter(array_map(
        static fn (array $row): int => $row['vm_id'] === null ? 0 : (int) $row['vm_id'],
        $rows
    )));
    if (count($sourceVmIds) !== count($rows)
        || count(deploy_create_resolve_selection($db, $missionId, $sourceVmIds)) !== count($rows)) {
        return [deploy_retry_finding('create', 'retry_create_vm_missing', true, [
            'expected' => count($rows),
            'present' => count($sourceVmIds),
        ])];
    }

    return [];
}

/**
 * The VM ids a create retry runs over: the WHOLE original selection, in the
 * order the source job used (plan section 10.3).
 *
 * Not the failed ones. A full pipeline has to power-cycle, export and start
 * every VM of the mission it was asked for, including the ones the first job
 * already created; those become verify_skip units rather than disappearing
 * from the scope.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<int>
 */
function deploy_create_retry_vm_ids(array $rows): array
{
    return array_values(array_filter(array_map(
        static fn (array $row): int => $row['vm_id'] === null ? 0 : (int) $row['vm_id'],
        $rows
    )));
}
