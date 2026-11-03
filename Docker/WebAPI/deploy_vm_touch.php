<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/errors.php';
virtusphere_error_response_mode('json');
require_once __DIR__ . '/mysql.php';
require_once __DIR__ . '/lib/machine_api.php';
require_once __DIR__ . '/lib/repo/deploy_job_vm_states.php';

$clientIp = machine_api_client_ip();
if (!machine_api_ip_allowed($connection, $clientIp, 'vm_touch')) {
    machine_api_forbidden($clientIp, $connection);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    machine_api_json(['error' => 'Method not allowed', 'reason_code' => 'touch_refused'], 405);
}
$raw = (string) file_get_contents('php://input', false, null, 0, 4097);
if (strlen($raw) > 4096) {
    machine_api_json(['error' => 'Touch request exceeds the limit', 'reason_code' => 'touch_refused'], 413);
}
try {
    $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    machine_api_json(['error' => 'Invalid JSON body', 'reason_code' => 'touch_refused'], 400);
}
if (!is_array($payload) || count($payload) !== 5) {
    machine_api_json(['error' => 'Invalid touch request', 'reason_code' => 'touch_refused'], 400);
}
$jobId = $payload['job_id'] ?? null;
$vmId = $payload['vm_id'] ?? null;
$attempt = $payload['attempt'] ?? null;
$generation = $payload['generation'] ?? null;
$phase = $payload['phase'] ?? null;
if (!is_int($jobId) || $jobId <= 0 || !is_int($vmId) || $vmId <= 0 || !is_int($attempt) || $attempt <= 0
    || !is_string($generation) || !is_string($phase)) {
    machine_api_json(['error' => 'Invalid touch fields', 'reason_code' => 'touch_refused'], 400);
}
$refusal = repo_deploy_job_vm_touch_refusal($connection, $jobId, $vmId, $attempt, $generation, $phase);
if ($refusal !== null) {
    // MA-08: one throttled audit row per (job, closed cause), an event of its own
    // so the log never calls a refused VM access a MAC callback. The raw request is not logged.
    machine_api_audit_warning(
        $connection,
        VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_TOUCH_REFUSED,
        'deploy_job',
        $jobId,
        VIRTUSPHERE_AUDIT_RESULT_DENIED,
        ['reason_code' => $refusal],
        $clientIp,
        'job:' . $jobId . ':' . $refusal
    );
    machine_api_json(['error' => 'Deploy job does not admit this VM step', 'reason_code' => 'touch_refused', 'cause' => $refusal], 409);
}
machine_api_json(['success' => true, 'job_id' => $jobId, 'vm_id' => $vmId, 'attempt' => $attempt, 'generation' => $generation, 'phase' => $phase]);
