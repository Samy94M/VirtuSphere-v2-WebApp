<?php

declare(strict_types=1);

/**
 * Pure validation of one device-sync cause report (MX1, FM-05).
 *
 * The schema is closed like reportPackageRun (ADR-0044 decisions 2 and 7): an
 * unknown field, a numeric string where an integer belongs, a code outside the
 * device set or a VM named twice refuses the whole report with 400 and one of
 * the closed tokens in VIRTUSPHERE_DEVICE_SYNC_CAUSE_ERRORS. An old portal that
 * silently dropped part of a report would make "delivered" and "discarded" look
 * the same to the script, which is the conclusion from silence ADR-0038 rules out.
 *
 * Deliberately not mecm_rollout_fence_reported_revision(): that reader accepts a
 * numeric string and treats an absent value as the pre-cutover caller. Here the
 * revision is always present and always a JSON integer. Whether it is CURRENT is
 * the repository's decision (lib/repo/vm_sync_causes.php), per entry.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/device_sync_cause_constants.php';

/**
 * @return array{error:string,status:int}|array{report:array{schema_version:int,run_id:string,complete:bool,causes:list<array{vm_id:int,rollout_revision:int,code:string}>}}
 */
function device_sync_causes_validate(array $data): array
{
    $fields = ['schema_version', 'run_id', 'complete', 'causes'];
    if (array_diff(array_keys($data), $fields) !== []) {
        return device_sync_causes_error('unknown_field');
    }
    if (array_diff($fields, array_keys($data)) !== []) {
        return device_sync_causes_error('missing_field');
    }
    if ($data['schema_version'] !== VIRTUSPHERE_DEVICE_SYNC_CAUSE_SCHEMA_VERSION) {
        return device_sync_causes_error('unsupported_schema');
    }
    $runId = $data['run_id'];
    if (!is_string($runId) || preg_match(VIRTUSPHERE_RUN_ID_PATTERN, $runId) !== 1) {
        return device_sync_causes_error('invalid_run_id');
    }
    if (!is_bool($data['complete'])) {
        return device_sync_causes_error('invalid_complete');
    }
    $entries = $data['causes'];
    if (!is_array($entries) || !array_is_list($entries)) {
        return device_sync_causes_error('invalid_causes');
    }
    if (count($entries) > VIRTUSPHERE_DEVICE_SYNC_CAUSE_MAX_ENTRIES) {
        return device_sync_causes_error('too_many_causes');
    }

    $causes = [];
    $seen = [];
    foreach ($entries as $entry) {
        $cause = device_sync_causes_entry($entry);
        if (is_string($cause)) {
            return device_sync_causes_error($cause);
        }
        // One cause per VM (Q4): a second entry for the same VM is a broken
        // producer, not a second opinion the portal could choose between.
        if (isset($seen[$cause['vm_id']])) {
            return device_sync_causes_error('duplicate_vm_id');
        }
        $seen[$cause['vm_id']] = true;
        $causes[] = $cause;
    }

    return ['report' => [
        'schema_version' => VIRTUSPHERE_DEVICE_SYNC_CAUSE_SCHEMA_VERSION,
        'run_id' => $runId,
        'complete' => $data['complete'],
        'causes' => $causes,
    ]];
}

/** @return array{vm_id:int,rollout_revision:int,code:string}|string the entry, or its refusal token */
function device_sync_causes_entry(mixed $entry): array|string
{
    $fields = ['vm_id', 'rollout_revision', 'code'];
    if (!is_array($entry) || ($entry !== [] && array_is_list($entry))
        || array_diff(array_keys($entry), $fields) !== [] || array_diff($fields, array_keys($entry)) !== []
    ) {
        return 'invalid_cause';
    }
    $vmId = $entry['vm_id'];
    if (!is_int($vmId) || $vmId < 1 || $vmId > VIRTUSPHERE_DEVICE_SYNC_CAUSE_VM_ID_MAX) {
        return 'invalid_vm_id';
    }
    $revision = $entry['rollout_revision'];
    if (!is_int($revision) || $revision < 1) {
        return 'invalid_rollout_revision';
    }
    $code = $entry['code'];
    if (!is_string($code) || !in_array($code, VIRTUSPHERE_DEVICE_SYNC_CAUSES, true)) {
        return 'unknown_cause_code';
    }

    return ['vm_id' => $vmId, 'rollout_revision' => $revision, 'code' => $code];
}

/** @return array{error:string,status:int} */
function device_sync_causes_error(string $code): array
{
    return ['error' => $code, 'status' => 400];
}
