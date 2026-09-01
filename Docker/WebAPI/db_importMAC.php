<?php

declare(strict_types=1);

// JSON on the wire even for an uncaught error; must precede mysql.php, which
// connects while it loads (see virtusphere_error_response_mode).
require_once __DIR__ . '/lib/errors.php';
virtusphere_error_response_mode('json');

require_once __DIR__ . '/mysql.php';
require_once __DIR__ . '/lib/machine_api.php';
require_once __DIR__ . '/lib/mac_import.php';

header('Content-Type: application/json; charset=utf-8');

class MacImportConflictException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        if (!in_array($reasonCode, virtusphere_mac_import_callback_reasons(), true)) {
            throw new InvalidArgumentException('Unknown MAC callback rejection reason.');
        }
        parent::__construct('Deploy job does not accept this MAC import.');
    }
}

final class MacImportBoundsException extends MacImportConflictException
{
}

$clientIp = machine_api_client_ip();
if (!machine_api_ip_allowed($connection, $clientIp)) {
    machine_api_forbidden($clientIp, $connection);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    machine_api_json(['error' => 'Method not allowed'], 405);
}

if (request_string($_GET, 'action') !== 'updateInterface') { // array-safe (lib/request.php)
    machine_api_json(['message' => 'Invalid action specified'], 400);
}

$contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
if (is_int($contentLength) && $contentLength > VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES) {
    machine_api_json(['error' => 'MAC import payload exceeds the request limit', 'reason_code' => 'request_too_large'], 413);
}
$requestBody = (string) file_get_contents('php://input', false, null, 0, VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES + 1);
if (strlen($requestBody) > VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES) {
    machine_api_json(['error' => 'MAC import payload exceeds the request limit', 'reason_code' => 'request_too_large'], 413);
}
try {
    $payload = json_decode($requestBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    machine_api_json(['error' => 'Invalid JSON body'], 400);
}
if (!is_array($payload) || $payload === []) {
    machine_api_json(['error' => 'No data received'], 400);
}

$transactionStarted = false;
try {
    [$missionId, $jobId, $results, $legacyPayload] = mac_import_normalize_payload($payload);
    if ($missionId <= 0) {
        machine_api_json([
            'error' => 'mission_id is required for MAC import payload',
            'legacy_payload' => $legacyPayload,
        ], 400);
    }
    if (array_key_exists('job_id', $payload) && $jobId === null) {
        machine_api_json(['error' => 'job_id must be a positive integer'], 400);
    }
    if ($jobId === null) {
        // ADR-0035: the job_id-less callback fell with the desktop client. An
        // unscoped import could rewrite rows no running deploy owns, which is
        // exactly the surface the E3 retirement removed.
        machine_api_json([
            'error' => 'job_id is required for MAC import payload',
            'legacy_payload' => $legacyPayload,
        ], 400);
    }
    // A payload whose `results` is absent or not a list carries no statement at
    // all and keeps its legacy 400. An explicitly EMPTY list does carry one,
    // and only since ADR-0035 made `job_id` mandatory: the job names the
    // expected VMs, so "the export produced nothing" is a per-VM
    // `missing_nic_data` verdict, which mac_import_build_plan() already
    // synthesizes for every expected VM without an input row. Rejecting it here
    // meant the endpoint refused a statement its own planner can answer.
    //
    // Ansible/upload_mac_list.py still aborts locally on an empty list and
    // therefore never reaches this branch today; that guard is the uploader's
    // own and is not changed here. This is the endpoint's contract, not a new
    // caller behaviour: the two must not disagree about what an empty result
    // set means.
    if (!is_array($payload['results'] ?? null)) {
        machine_api_json(['error' => 'No result entries received'], 400);
    }

    // ADR-0032: diagnostic only. A valid id is adopted so this request's
    // audit/log lines carry the caller's trace, and it is echoed back; an
    // invalid one is noted and ignored, never a 4xx - the id must not be able
    // to break an import, and it grants nothing.
    $correlationId = null;
    if (array_key_exists('correlation_id', $payload)) {
        $rawCorrelation = is_string($payload['correlation_id']) ? $payload['correlation_id'] : '';
        if (virtusphere_correlation_id_is_valid($rawCorrelation)) {
            $correlationId = $rawCorrelation;
            virtusphere_correlation_adopt($correlationId);
        } else {
            // Diagnostic-only input: invalid values are ignored and never logged,
            // because the supplied value may itself be sensitive data.
        }
    }

    // Non-locking trace context only. The authoritative decision below follows
    // Mission -> Job -> Runtime -> Remote handle -> VMs -> Interfaces.
    $traceJob = mac_import_job($connection, $jobId);
    if (!is_array($traceJob)
        || (int) ($traceJob['mission_id'] ?? 0) !== $missionId
        || !in_array((string) ($traceJob['status'] ?? ''), [
            VIRTUSPHERE_DEPLOY_STATUS_RUNNING,
            VIRTUSPHERE_DEPLOY_STATUS_CANCELLING,
        ], true)) {
        throw new MacImportConflictException('callback_job_not_active');
    }
    try {
        mac_import_callback_job_payload($traceJob);
    } catch (Throwable) {
        throw new MacImportConflictException('callback_mode_rejected');
    }

    // Sole outer request transaction: no nested repository transaction wrapper
    // is called until commit. Planning locks and validates every row before phase 2.
    $connection->begin_transaction();
    $transactionStarted = true;

    $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
    $cancelling = VIRTUSPHERE_DEPLOY_STATUS_CANCELLING;

    $stmt = $connection->prepare('SELECT id, wds_vlan FROM deploy_missions WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $missionId);
    $stmt->execute();
    $mission = $stmt->get_result()->fetch_assoc();
    if (!is_array($mission)) {
        throw new MacImportConflictException('callback_job_not_active');
    }

    $stmt = $connection->prepare(
        'SELECT id, mission_id, status, payload_json, result_json, attempts, execution_contract, '
        . 'LOWER(HEX(execution_generation_id)) AS execution_generation_id '
        . 'FROM deploy_jobs WHERE id = ? AND mission_id = ? AND status IN (?, ?) LIMIT 1 FOR UPDATE'
    );
    $stmt->bind_param('iiss', $jobId, $missionId, $running, $cancelling);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc();
    if (!is_array($job)) {
        throw new MacImportConflictException('callback_job_not_active');
    }

    try {
        $jobPayload = mac_import_callback_job_payload($job);
    } catch (Throwable) {
        throw new MacImportConflictException('callback_mode_rejected');
    }

    $runtime = $connection->query(
        'SELECT LOWER(HEX(current_generation_id)) AS generation_id FROM deploy_runtime_identity WHERE id = 1 FOR UPDATE'
    )->fetch_assoc();
    $runtimeGeneration = (string) ($runtime['generation_id'] ?? '');
    $remoteHandle = null;
    if ((string) ($job['execution_contract'] ?? '') === VIRTUSPHERE_EXECUTION_CONTRACT_REMOTE) {
        $attempt = (int) $job['attempts'];
        $stepKey = 'export';
        $stmt = $connection->prepare(
            'SELECT job_attempt, step_key, LOWER(HEX(generation_id)) AS generation_id '
            . 'FROM deploy_remote_executions WHERE job_id = ? AND job_attempt = ? AND step_key = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->bind_param('iis', $jobId, $attempt, $stepKey);
        $stmt->execute();
        $remoteHandle = $stmt->get_result()->fetch_assoc();
        if (is_array($remoteHandle)) {
            try {
                $remoteHandle['callback_expectation'] = remote_step_callback_expectation((string) $jobPayload['mode'], $stepKey);
            } catch (Throwable) {
                $remoteHandle['callback_expectation'] = '';
            }
        }
    }
    $fenceReason = mac_import_callback_fence_reason($job, $runtimeGeneration, is_array($remoteHandle) ? $remoteHandle : null);
    if ($fenceReason !== null) {
        throw new MacImportConflictException($fenceReason);
    }

    $plan = mac_import_build_plan(
        $connection,
        $missionId,
        $results,
        true,
        $jobPayload['scope_ids'],
        (string) ($mission['wds_vlan'] ?? '')
    );
    $expectedVmIds = array_map(static fn (array $vmResult): int => (int) $vmResult['vm_id'], (array) $plan['vm_results']);
    $callbackFingerprint = mac_import_callback_fingerprint($missionId, $jobId, $results, $expectedVmIds);
    $existingResult = trim((string) ($job['result_json'] ?? ''));
    if ($existingResult !== '') {
        $decodedExisting = mac_import_decode_result($existingResult);
        if ($decodedExisting === null
            || (int) ($decodedExisting['version'] ?? 0) !== VIRTUSPHERE_MAC_IMPORT_RESULT_VERSION
            || !hash_equals((string) $decodedExisting['callback_fingerprint'], $callbackFingerprint)) {
            throw new MacImportConflictException('callback_result_conflict');
        }
        $connection->rollback();
        $transactionStarted = false;
        machine_api_json(mac_import_response($decodedExisting, $jobId, $legacyPayload, $correlationId));
    }

    $resultContract = mac_import_result_contract($plan, $callbackFingerprint);
    $resultJson = json_encode($resultContract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $response = mac_import_response($plan, $jobId, $legacyPayload, $correlationId);
    $responseJson = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $boundReason = mac_import_contract_bound_reason($resultJson, $responseJson);
    if ($boundReason !== null) {
        throw new MacImportBoundsException($boundReason);
    }

    $updateInterface = $connection->prepare('UPDATE deploy_interfaces SET mac = ? WHERE id = ? AND vm_id = ?');
    $updateVm = $connection->prepare('UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, vm_status = ?, updated = 1, mecm_pending_since = COALESCE(mecm_pending_since, NOW()), os_install_watch_started_at = NULL, updated_at = NOW() WHERE id = ?');
    // Entscheidung 6: persist the hypervisor identity the export proved. NULLIF
    // keeps the stored value when a pre-identity playbook result omits a field;
    // a mismatching UUID never reaches this write (the plan fails that VM).
    $updateIdentity = $connection->prepare("UPDATE deploy_vms SET vm_moid = COALESCE(NULLIF(?, ''), vm_moid), vm_instance_uuid = COALESCE(NULLIF(?, ''), vm_instance_uuid) WHERE id = ?");
    $insertStatusEvent = $connection->prepare('INSERT INTO deploy_vm_status_events (vm_id, lifecycle_state, mecm_sync_state, legacy_status, note) VALUES (?, ?, ?, ?, ?)');
    $lifecycle = VIRTUSPHERE_LIFECYCLE_DEPLOYED;
    $mecmState = VIRTUSPHERE_MECM_SYNC_PENDING;
    $legacyStatus = VIRTUSPHERE_STATUS_DEPLOYED;
    $note = 'ansible mac import';

    foreach ($plan['successful_vm_ids'] as $vmId) {
        $vmPlan = $plan['vm_plans'][$vmId];
        foreach ($vmPlan['updates'] as $update) {
            $mac = (string) $update['mac'];
            $interfaceId = (int) $update['id'];
            $updateInterface->bind_param('sii', $mac, $interfaceId, $vmId);
            $updateInterface->execute();
        }

        $identity = is_array($vmPlan['identity'] ?? null) ? $vmPlan['identity'] : null;
        if ($identity !== null && ($identity['moid'] !== '' || $identity['instance_uuid'] !== '')) {
            $updateIdentity->bind_param('ssi', $identity['moid'], $identity['instance_uuid'], $vmId);
            $updateIdentity->execute();
        }

        $vm = $vmPlan['vm'];
        $stateChanged = (string) $vm['lifecycle_state'] !== $lifecycle
            || (string) $vm['mecm_sync_state'] !== $mecmState
            || (string) $vm['vm_status'] !== $legacyStatus
            || (int) $vm['updated'] !== 1;
        if ($stateChanged) {
            $updateVm->bind_param('sssi', $lifecycle, $mecmState, $legacyStatus, $vmId);
            $updateVm->execute();
            $insertStatusEvent->bind_param('issss', $vmId, $lifecycle, $mecmState, $legacyStatus, $note);
            $insertStatusEvent->execute();
        }
    }

    // result_json is part of the same raw transaction as NIC and VM state.
    // This is intentionally a raw prepared statement, never a repo helper.
    $stmt = $connection->prepare('UPDATE deploy_jobs SET result_json = ?, updated_at = NOW() WHERE id = ? AND mission_id = ? AND status IN (?, ?)');
    $stmt->bind_param('siiss', $resultJson, $jobId, $missionId, $running, $cancelling);
    $stmt->execute();

    $connection->commit();
    $transactionStarted = false;

    if ($plan['outcome'] !== 'success') {
        machine_api_log_warning('db_importMAC', sprintf(
            'MAC import mission_id=%d job_id=%d outcome=%s successful_vms=%d failed_vms=%d errors=%d.',
            $missionId,
            $jobId,
            $plan['outcome'],
            $plan['counts']['successful_vms'],
            $plan['counts']['failed_vms'],
            count($plan['errors'])
        ));
    }

    machine_api_json($response);
} catch (MacImportConflictException $exception) {
    if ($transactionStarted) {
        $connection->rollback();
    }
    // The rejection must be findable where the operator looks (ADR-0033): one
    // throttled line in the job log the caller named, one throttled portal audit row.
    // Raw prepared statement on purpose (this file's transaction rule) and
    // after the rollback, so the trace survives independently of the request.
    // Only for an EXISTING job row - the FK would refuse anything else, and a
    // rejected callback must never be able to crash into a 500.
    if (isset($jobId, $traceJob) && is_array($traceJob)) {
        try {
            $stream = 'system';
            $line = 'Rejected a MAC callback (' . $exception->reasonCode . ').';
            $connection->begin_transaction();
            $stmt = $connection->prepare('SELECT id FROM deploy_jobs WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
            $knownJob = $stmt->get_result()->fetch_assoc();
            if (is_array($knownJob)) {
                $stmt = $connection->prepare(
                    'SELECT id FROM deploy_job_logs WHERE job_id = ? AND stream = ? AND line = ? '
                    . 'AND TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP()) < ? LIMIT 1'
                );
                $throttleSeconds = VIRTUSPHERE_MAC_IMPORT_CALLBACK_OBSERVABILITY_THROTTLE_SECONDS;
                $stmt->bind_param('issi', $jobId, $stream, $line, $throttleSeconds);
                $stmt->execute();
                if ($stmt->get_result()->fetch_assoc() === null) {
                    $stmt = $connection->prepare(
                        'INSERT INTO deploy_job_logs (job_id, seq, stream, line) '
                        . 'SELECT ?, COALESCE(MAX(seq), 0) + 1, ?, ? FROM deploy_job_logs WHERE job_id = ?'
                    );
                    $stmt->bind_param('issi', $jobId, $stream, $line, $jobId);
                    $stmt->execute();
                }
            }
            $connection->commit();
        } catch (Throwable $traceError) {
            try {
                $connection->rollback();
            } catch (Throwable) {
            }
            machine_api_log_warning('db_importMAC', 'Conflict trace failed (' . $traceError::class . ').');
        }
        machine_api_audit_warning(
            $connection,
            VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_CALLBACK_REJECTED,
            'deploy_job',
            $jobId,
            VIRTUSPHERE_AUDIT_RESULT_DENIED,
            ['reason_code' => $exception->reasonCode],
            $clientIp,
            'job:' . $jobId . ':' . $exception->reasonCode
        );
    }
    machine_api_json([
        'error' => 'Deploy job does not accept this MAC import',
        'reason_code' => $exception->reasonCode,
        'job_id' => $jobId ?? null,
    ], 409);
} catch (Throwable $exception) {
    if ($transactionStarted) {
        $connection->rollback();
    }
    machine_api_audit_warning(
        $connection,
        VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_FAILURE,
        'machine_endpoint',
        'db_importMAC.php',
        VIRTUSPHERE_AUDIT_RESULT_FAILURE,
        ['error_class' => $exception::class],
        $clientIp
    );
    machine_api_json(['error' => 'Interner Serverfehler'], 500);
}
