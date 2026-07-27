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

final class MacImportConflictException extends RuntimeException
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

try {
    $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
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
        machine_api_log_warning('db_importMAC', 'Rejected MAC import without mission_id from ' . $clientIp . '.');
        machine_api_json([
            'error' => 'mission_id is required for MAC import payload',
            'legacy_payload' => $legacyPayload,
        ], 400);
    }
    if (array_key_exists('job_id', $payload) && $jobId === null) {
        machine_api_json(['error' => 'job_id must be a positive integer'], 400);
    }
    if ($results === []) {
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
            machine_api_log_warning('db_importMAC', 'Ignored invalid correlation_id from ' . $clientIp . '.');
        }
    }

    // The authoritative 409 gate is deliberately before begin_transaction().
    // A terminal, unknown or mission-foreign callback cannot write any row.
    // The window follows the machine, not the wish (ADR-0033): a `cancelling`
    // job's playbook is still finishing its current step, and that step's own
    // MAC upload is exactly this request - bouncing it threw away addresses
    // the sequence had really assigned. Only the confirmed end states refuse.
    $job = null;
    $jobScopeIds = null;
    if ($jobId !== null) {
        $job = mac_import_job($connection, $jobId);
        if (!is_array($job)
            || (int) ($job['mission_id'] ?? 0) !== $missionId
            || !in_array((string) ($job['status'] ?? ''), [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, VIRTUSPHERE_DEPLOY_STATUS_CANCELLING], true)) {
            throw new MacImportConflictException('Deploy job does not accept MAC imports for this mission.');
        }
        $jobScopeIds = mac_import_job_scope_ids($job);
    }

    // Sole outer request transaction: no repo_transaction()-wrapped function is
    // called until commit. Planning locks and validates every row before phase 2.
    $connection->begin_transaction();
    $transactionStarted = true;

    if ($jobId !== null) {
        // Close the race between the pre-transaction 409 gate and this lock. A
        // worker that already made the job terminal still produces no writes;
        // running and cancelling both accept (same window as the gate above).
        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $cancelling = VIRTUSPHERE_DEPLOY_STATUS_CANCELLING;
        $stmt = $connection->prepare('SELECT id FROM deploy_jobs WHERE id = ? AND mission_id = ? AND status IN (?, ?) LIMIT 1 FOR UPDATE');
        $stmt->bind_param('iiss', $jobId, $missionId, $running, $cancelling);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            throw new MacImportConflictException('Deploy job became terminal before the callback was locked.');
        }
    }

    $plan = mac_import_build_plan($connection, $missionId, $results, $jobId !== null, $jobScopeIds);
    $resultContract = mac_import_result_contract($plan);

    $updateInterface = $connection->prepare('UPDATE deploy_interfaces SET mac = ? WHERE id = ? AND vm_id = ?');
    $updateVm = $connection->prepare('UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, vm_status = ?, updated = 1, updated_at = NOW() WHERE id = ?');
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

    if ($jobId !== null) {
        // result_json is part of the same raw transaction as NIC and VM state.
        // This is intentionally a raw prepared statement, never a repo helper.
        $resultJson = json_encode($resultContract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $cancelling = VIRTUSPHERE_DEPLOY_STATUS_CANCELLING;
        $stmt = $connection->prepare('UPDATE deploy_jobs SET result_json = ?, updated_at = NOW() WHERE id = ? AND mission_id = ? AND status IN (?, ?)');
        $stmt->bind_param('siiss', $resultJson, $jobId, $missionId, $running, $cancelling);
        $stmt->execute();
    }

    $connection->commit();
    $transactionStarted = false;

    $diagnostics = mac_import_legacy_diagnostics($plan['errors']);
    if ($plan['outcome'] !== 'success') {
        machine_api_log_warning('db_importMAC', sprintf(
            'MAC import mission_id=%d job_id=%s outcome=%s successful_vms=%d failed_vms=%d errors=%d.',
            $missionId,
            $jobId === null ? 'legacy' : (string) $jobId,
            $plan['outcome'],
            $plan['counts']['successful_vms'],
            $plan['counts']['failed_vms'],
            count($plan['errors'])
        ));
    }

    $response = [
        'success' => $plan['outcome'] === 'success',
        'legacy_payload' => $legacyPayload,
        'updated_interfaces' => $plan['counts']['updated_interfaces'],
        'updated_vms' => $plan['counts']['successful_vms'],
        'missing_vms' => $diagnostics['missing_vms'],
        'unmatched_interfaces' => $diagnostics['unmatched_interfaces'],
        'duplicate_macs' => $diagnostics['duplicate_macs'],
        'result_version' => VIRTUSPHERE_MAC_IMPORT_RESULT_VERSION,
        'outcome' => $plan['outcome'],
        'job_id' => $jobId,
        'correlation_id' => $correlationId,
        'vm_results' => $plan['vm_results'],
        'counts' => $plan['counts'],
        'errors' => $plan['errors'],
    ];
    if ($plan['outcome'] !== 'success') {
        $response['error'] = 'MAC import completed with unmatched entries';
    }

    machine_api_json($response);
} catch (MacImportConflictException $exception) {
    if ($transactionStarted) {
        $connection->rollback();
    }
    machine_api_log_warning('db_importMAC', 'Rejected callback conflict: ' . $exception->getMessage());
    // The rejection must be findable where the operator looks (ADR-0033): one
    // line in the job log the caller named, one throttled portal audit row.
    // Raw prepared statement on purpose (this file's transaction rule) and
    // after the rollback, so the trace survives independently of the request.
    // Only for an EXISTING job row - the FK would refuse anything else, and a
    // rejected callback must never be able to crash into a 500.
    if (isset($jobId, $job) && $jobId !== null && is_array($job)) {
        try {
            $stream = 'system';
            $line = 'Rejected a MAC callback: ' . $exception->getMessage()
                . ' (job status ' . (string) ($job['status'] ?? '?') . ', caller ' . $clientIp . ')';
            $stmt = $connection->prepare(
                'INSERT INTO deploy_job_logs (job_id, seq, stream, line)
                 SELECT ?, COALESCE(MAX(seq), 0) + 1, ?, ? FROM deploy_job_logs WHERE job_id = ?'
            );
            $stmt->bind_param('issi', $jobId, $stream, $line, $jobId);
            $stmt->execute();
        } catch (Throwable $traceError) {
            error_log('[db_importMAC] conflict trace failed: ' . $traceError->getMessage());
        }
        machine_api_audit_warning(
            $connection,
            'db_importMAC',
            'MAC callback rejected for job id ' . $jobId . ': ' . $exception->getMessage(),
            $clientIp,
            VIRTUSPHERE_LOG_CATEGORY_MACHINE_API,
            'job-' . $jobId
        );
    }
    machine_api_json(['error' => 'Deploy job does not accept this MAC import', 'job_id' => $jobId ?? null], 409);
} catch (Throwable $exception) {
    if ($transactionStarted) {
        $connection->rollback();
    }
    machine_api_log_warning('db_importMAC', $exception::class . ': ' . $exception->getMessage());
    machine_api_json(['error' => 'Interner Serverfehler'], 500);
}
