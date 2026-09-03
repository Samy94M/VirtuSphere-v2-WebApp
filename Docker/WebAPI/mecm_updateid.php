<?php

declare(strict_types=1);

// JSON on the wire even for an uncaught error; must precede mysql.php, which
// connects while it loads (see virtusphere_error_response_mode).
require_once __DIR__ . '/lib/errors.php';
virtusphere_error_response_mode('json');

require_once __DIR__ . '/mysql.php';
require_once __DIR__ . '/lib/machine_api.php';
require_once __DIR__ . '/lib/repo/status_events.php';
require_once __DIR__ . '/lib/repo/mecm_provenance.php';
require_once __DIR__ . '/lib/mecm_rollout_fence.php';

header('Content-Type: application/json; charset=utf-8');

$clientIp = machine_api_client_ip();
if (!machine_api_ip_allowed($connection, $clientIp)) {
    machine_api_forbidden($clientIp, $connection);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    machine_api_json(['error' => 'Method not allowed'], 405);
}

$action = request_string($_GET, 'action'); // array-safe (lib/request.php)
if (!in_array($action, ['updateDevice', 'reportMembership'], true)) {
    machine_api_json(['message' => 'Invalid action specified'], 400);
}

if ($action === 'reportMembership') {
    // Additive wire (ADR-0034): the device-sync reports the membership rules
    // it added or removed, and the portal keeps the provenance. Same envelope
    // family as updateDevice: 400 for a malformed report, 404 for an unknown
    // VM (the sync keeps the device queued and says so), 200 on success.
    try {
        $data = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        $vmId = (int) ($data['deviceid'] ?? 0);
        $rawMemberships = $data['memberships'] ?? null;
        if ($vmId <= 0 || !is_array($rawMemberships) || $rawMemberships === []
            || count($rawMemberships) > VIRTUSPHERE_MECM_RULE_REPORT_MAX_ENTRIES) {
            machine_api_json(['error' => 'Invalid data format'], 400);
        }

        $entries = [];
        foreach ($rawMemberships as $rawEntry) {
            $entry = mecm_rule_report_entry($rawEntry);
            if ($entry === null) {
                // The script builds the report from its own applied plan, so a
                // malformed entry is a coding error: reject the whole report
                // before anything is written.
                machine_api_json(['error' => 'Invalid data format'], 400);
            }
            $entries[] = $entry;
        }

        try {
            $reportedRevision = mecm_rollout_fence_reported_revision($data['rollout_revision'] ?? null);
        } catch (InvalidArgumentException) {
            machine_api_json(['error' => 'Invalid data format'], 400);
        }

        // Fence and provenance write share ONE transaction (Etappe 14D).
        //
        // The row lock is only worth something while a transaction holds it.
        // This endpoint runs in autocommit, where MySQL ends the implicit
        // transaction of a bare `SELECT ... FOR UPDATE` at the end of that
        // statement and releases the lock with it. A reset committing in the gap
        // would then be overwritten by exactly the stale scan iteration the
        // fence exists to stop, and the check would read as if it had held.
        //
        // Nothing inside the closure exits: machine_api_json() ends the process,
        // which rolls an open transaction back, so a success answered from in
        // there would discard its own write. The closure decides, the answers
        // are given below it.
        //
        // Membership carries no binding of its own, so the fence decides on the
        // revision alone and never answers `noop` here.
        $outcome = repo_transaction($connection, static function () use ($connection, $vmId, $entries, $clientIp, $reportedRevision): array {
            $vm = repo_fetch_one($connection, 'SELECT id, mecm_rollout_revision, mecm_previous_id FROM deploy_vms WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$vmId]);
            if ($vm === null) {
                return ['status' => 'unknown_vm', 'vm' => []];
            }

            $verdict = mecm_rollout_fence_decide(
                $reportedRevision,
                $vm['mecm_rollout_revision'] === null ? null : (int) $vm['mecm_rollout_revision'],
                null,
                null,
                $vm['mecm_previous_id'] === null ? null : (string) $vm['mecm_previous_id']
            );
            if ($verdict === VIRTUSPHERE_MECM_FENCE_STALE) {
                // Returned rather than thrown: no domain row was written, so
                // there is nothing to roll back, and the refusal's own audit row
                // is written outside where no rollback can take it away.
                return ['status' => 'stale', 'vm' => $vm];
            }

            repo_mecm_rules_apply_report($connection, $vmId, $entries, $clientIp);

            return ['status' => 'ok', 'vm' => $vm];
        });

        if ($outcome['status'] === 'unknown_vm') {
            machine_api_audit_warning(
                $connection,
                VIRTUSPHERE_AUDIT_EVENT_MECM_UNKNOWN_VM,
                'vm',
                $vmId,
                VIRTUSPHERE_AUDIT_RESULT_WARNING,
                ['report_type' => 'membership'],
                $clientIp
            );
            machine_api_json(['error' => 'Unknown VM id'], 404);
        }
        if ($outcome['status'] === 'stale') {
            machine_api_rollout_revision_refused($connection, $vmId, 'membership', $reportedRevision, $outcome['vm'], $clientIp);
        }

        machine_api_json(['success' => 'Data updated successfully']);
    } catch (JsonException) {
        machine_api_json(['error' => 'Invalid JSON body'], 400);
    } catch (Throwable $exception) {
        machine_api_audit_warning($connection, VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_FAILURE, 'machine_endpoint', 'mecm_updateid.php', VIRTUSPHERE_AUDIT_RESULT_FAILURE, ['error_class' => $exception::class], $clientIp);
        machine_api_json(['error' => 'Interner Serverfehler'], 500);
    }
}

try {
    $data = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    $mecmId = (string) ($data['deviceResourceID'] ?? '');
    $vmId = (int) ($data['deviceid'] ?? 0);

    if ($mecmId === '' || $vmId <= 0) {
        machine_api_json(['error' => 'Invalid data format'], 400);
    }

    try {
        $reportedRevision = mecm_rollout_fence_reported_revision($data['rollout_revision'] ?? null);
    } catch (InvalidArgumentException) {
        machine_api_json(['error' => 'Invalid data format'], 400);
    }

    // Revision fence, binding and tombstone release share ONE transaction
    // (Etappe 14D), for the reason spelled out at reportMembership above: in
    // autocommit a bare `SELECT ... FOR UPDATE` gives its lock straight back, so
    // the fence would decide on a revision that a reset can change before the
    // binding runs. Nothing inside the closure exits, because ending the process
    // rolls the transaction back and the success path has to commit.
    //
    // The tombstone is cleared HERE and only here: it may only go once a NEW
    // ResourceID is really bound. Clearing it at reset time would drop the one
    // thing that keeps the next hand-off fail-closed while the old device is
    // still sitting in MECM.
    $outcome = repo_transaction($connection, static function () use ($connection, $vmId, $mecmId, $reportedRevision): array {
        $vm = repo_fetch_one($connection, 'SELECT id, mecm_id, mecm_rollout_revision, mecm_previous_id FROM deploy_vms WHERE id = ? LIMIT 1 FOR UPDATE', 'i', [$vmId]);
        if ($vm === null) {
            return ['status' => 'unknown_vm', 'vm' => []];
        }

        $verdict = mecm_rollout_fence_decide(
            $reportedRevision,
            $vm['mecm_rollout_revision'] === null ? null : (int) $vm['mecm_rollout_revision'],
            $vm['mecm_id'] === null ? null : (string) $vm['mecm_id'],
            $mecmId,
            $vm['mecm_previous_id'] === null ? null : (string) $vm['mecm_previous_id']
        );
        if ($verdict === VIRTUSPHERE_MECM_FENCE_STALE) {
            return ['status' => 'stale', 'vm' => $vm];
        }
        if ($verdict === VIRTUSPHERE_MECM_FENCE_NOOP) {
            // The same current rollout re-reporting the ResourceID it already
            // bound. A duplicate, not a conflict: 200 with no second write and
            // no second status event, so a sync retrying after a network hiccup
            // does not fill the VM history with identical rows.
            return ['status' => 'noop', 'vm' => $vm];
        }

        // Forward-only: this endpoint used to write `os_installing`
        // unconditionally, so a VM that had already reported `os_installed` fell
        // visibly back to 4/5 every time the device-sync re-reported its
        // ResourceID. The ResourceID is still stored in that case; only the
        // lifecycle is not walked backwards.
        //
        // A false return means the VM id does not exist. The endpoint answered
        // 200 "Data updated successfully" for it, and the device-sync reads that
        // as "done": the device left the queue and was never reported again, for
        // a row that was deleted in the portal. 404 lets the sync keep it.
        if (!repo_set_vm_state_forward($connection, $vmId, VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, VIRTUSPHERE_STATUS_OS_INSTALLING, 0, 'mecm update id', $mecmId)) {
            return ['status' => 'unknown_vm', 'vm' => $vm];
        }

        // The binding succeeded, so the old device may stop blocking the
        // hand-off. Same transaction as the binding: a tombstone that outlived
        // its successful rebinding would refuse every future reset of this VM.
        repo_execute($connection, 'UPDATE deploy_vms SET mecm_previous_id = NULL, updated_at = updated_at WHERE id = ?', 'i', [$vmId]);

        return ['status' => 'ok', 'vm' => $vm];
    });

    if ($outcome['status'] === 'unknown_vm') {
        machine_api_audit_warning(
            $connection,
            VIRTUSPHERE_AUDIT_EVENT_MECM_UNKNOWN_VM,
            'vm',
            $vmId,
            VIRTUSPHERE_AUDIT_RESULT_WARNING,
            ['report_type' => 'resource_id', 'resource_id' => $mecmId],
            $clientIp
        );
        machine_api_json(['error' => 'Unknown VM id'], 404);
    }
    if ($outcome['status'] === 'stale') {
        machine_api_rollout_revision_refused($connection, $vmId, 'resource_id', $reportedRevision, $outcome['vm'], $clientIp);
    }

    machine_api_json(['success' => 'Data updated successfully']);
} catch (JsonException) {
    machine_api_json(['error' => 'Invalid JSON body'], 400);
} catch (Throwable $exception) {
    machine_api_audit_warning($connection, VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_FAILURE, 'machine_endpoint', 'mecm_updateid.php', VIRTUSPHERE_AUDIT_RESULT_FAILURE, ['error_class' => $exception::class], $clientIp);
    machine_api_json(['error' => 'Interner Serverfehler'], 500);
}
