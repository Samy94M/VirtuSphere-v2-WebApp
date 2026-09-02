<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_create_result.php';
require_once __DIR__ . '/repo/deploy_create_results.php';
require_once __DIR__ . '/repo/helpers.php';

/**
 * Whether an operator may release an unresolved create unit (Etappe 14B,
 * Teiletappe F; plan section 10.5).
 *
 * The release is fail-closed, and it is worth being precise about what it does
 * and does not do. It deletes no VM, changes no hardware and adopts nothing. It
 * records that a person looked at the ESXi host and established that the VM was
 * NOT created, and it turns the unit from "nobody knows" into "confirmed not
 * created" so that a later retry may create it again. If that judgement is
 * wrong, the retry creates a second VM - which is exactly why every condition
 * below has to hold before the action is even offered.
 *
 * The conditions are evidence, not ceremony:
 *
 *  - a successful inventory pull of THIS job's ESXi credential, whose VM query
 *    demonstrably answered, and which is strictly newer than both the job and
 *    the unresolved result. An older pull cannot say anything about a VM that
 *    may have appeared since;
 *  - neither the snapshot name nor the VM's stored instance UUID present in
 *    that pull. Either one would mean something IS there;
 *  - no other active job of the mission, so nothing is creating while this is
 *    being decided.
 *
 * The Recent-Tasks attestation is deliberately not in this list: it is not
 * something this system can check, it is something the operator states. It is
 * required by the action and recorded as their statement, never as a fact
 * VirtuSphere established.
 */

/** The closed reasons a release is refused. */
const VIRTUSPHERE_CREATE_RELEASE_BLOCKERS = [
    'release_unit_not_uncertain',
    'release_job_active',
    'release_mission_busy',
    'release_inventory_never_succeeded',
    'release_inventory_vm_kind_unanswered',
    'release_inventory_stale',
    'release_inventory_name_present',
    'release_inventory_uuid_present',
];

/**
 * @return array{eligible:bool,blockers:list<string>,unit:?array<string,mixed>,evidence:array<string,mixed>}
 */
function deploy_create_release_blockers(mysqli $db, int $jobId, int $position, bool $lock = false): array
{
    $unit = null;
    foreach (repo_deploy_create_results($db, $jobId, $lock) as $row) {
        if ((int) $row['position'] === $position) {
            $unit = $row;
            break;
        }
    }
    $job = repo_fetch_one(
        $db,
        'SELECT id, mission_id, status, credential_esxi_id, updated_at FROM deploy_jobs WHERE id = ? LIMIT 1'
        . ($lock ? ' FOR UPDATE' : ''),
        'i',
        [$jobId]
    );
    if ($unit === null || $job === null) {
        return ['eligible' => false, 'blockers' => ['release_unit_not_uncertain'], 'unit' => null, 'evidence' => []];
    }

    $blockers = [];
    if ((string) $unit['status'] !== VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN) {
        $blockers[] = 'release_unit_not_uncertain';
    }
    if (!in_array((string) $job['status'], VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true)) {
        $blockers[] = 'release_job_active';
    }
    $missionId = (int) $job['mission_id'];
    if ($missionId > 0 && repo_deploy_active_job_exists($db, $missionId)) {
        $blockers[] = 'release_mission_busy';
    }

    $evidence = deploy_create_release_inventory_evidence(
        $db,
        (int) $job['credential_esxi_id'],
        $missionId,
        $unit,
        max(
            deploy_create_release_timestamp((string) ($job['updated_at'] ?? '')),
            deploy_create_release_timestamp((string) ($unit['updated_at'] ?? ''))
        )
    );
    foreach ($evidence['blockers'] as $blocker) {
        $blockers[] = $blocker;
    }

    return [
        'eligible' => $blockers === [],
        'blockers' => array_values(array_unique($blockers)),
        'unit' => $unit,
        'evidence' => $evidence,
    ];
}

/**
 * The inventory half of the decision.
 *
 * Freshness is compared STRICTLY: a pull that ran in the same second as the
 * result was written may have started before it, and "at least as new" is the
 * kind of boundary that turns into a second VM once.
 *
 * @param array<string,mixed> $unit
 * @return array{blockers:list<string>,pull_at:?string,vm_kind_at:?string,not_before:?string}
 */
function deploy_create_release_inventory_evidence(
    mysqli $db,
    int $credentialId,
    int $missionId,
    array $unit,
    ?int $notBefore
): array {
    $blockers = [];
    $state = $credentialId > 0
        ? repo_fetch_one(
            $db,
            'SELECT last_success_at, kind_freshness_json FROM deploy_esxi_inventory_state WHERE credential_id = ? LIMIT 1',
            'i',
            [$credentialId]
        )
        : null;
    $lastSuccess = $state === null ? null : ($state['last_success_at'] === null ? null : (string) $state['last_success_at']);
    $freshness = $state === null ? [] : json_decode((string) ($state['kind_freshness_json'] ?? ''), true);
    $freshness = is_array($freshness) ? $freshness : [];
    // A kind appears here only when EVERY inventory query of that kind answered
    // in the pull. Its absence is what tells "the VM list of that pull is not
    // evidence" apart from "the pull found no VMs".
    $vmKindAt = isset($freshness[VIRTUSPHERE_INVENTORY_KIND_VM])
        ? (string) $freshness[VIRTUSPHERE_INVENTORY_KIND_VM]
        : null;

    if ($lastSuccess === null) {
        $blockers[] = 'release_inventory_never_succeeded';
    }
    if ($vmKindAt === null) {
        $blockers[] = 'release_inventory_vm_kind_unanswered';
    }
    if ($lastSuccess !== null && $vmKindAt !== null && $notBefore !== null) {
        $pull = min(
            deploy_create_release_timestamp($lastSuccess) ?? PHP_INT_MAX,
            deploy_create_release_timestamp($vmKindAt) ?? PHP_INT_MAX
        );
        if ($pull <= $notBefore) {
            $blockers[] = 'release_inventory_stale';
        }
    }

    if ($blockers === []) {
        if (deploy_create_release_name_present($db, $credentialId, (string) $unit['vm_name'])) {
            $blockers[] = 'release_inventory_name_present';
        }
        $storedUuid = $missionId > 0 && $unit['vm_id'] !== null
            ? trim((string) repo_scalar(
                $db,
                'SELECT COALESCE(vm_instance_uuid, \'\') FROM deploy_vms WHERE id = ? AND mission_id = ? LIMIT 1',
                'ii',
                [(int) $unit['vm_id'], $missionId]
            ))
            : '';
        if ($storedUuid !== '' && deploy_create_release_uuid_present($db, $credentialId, $storedUuid)) {
            $blockers[] = 'release_inventory_uuid_present';
        }
    }

    return [
        'blockers' => $blockers,
        'pull_at' => $lastSuccess,
        'vm_kind_at' => $vmKindAt,
        'not_before' => $notBefore === null ? null : gmdate('Y-m-d H:i:s', $notBefore),
    ];
}

/**
 * Whether the cache of this credential holds a VM of exactly this name.
 *
 * The column is binary-collated, so this comparison is the one ESXi itself
 * makes: two names that differ only in case are two names, and folding them
 * here would report a namesake the host does not have.
 */
function deploy_create_release_name_present(mysqli $db, int $credentialId, string $name): bool
{
    if ($credentialId <= 0 || trim($name) === '') {
        return false;
    }
    $kind = VIRTUSPHERE_INVENTORY_KIND_VM;

    return (int) repo_scalar(
        $db,
        'SELECT COUNT(*) FROM deploy_esxi_inventory WHERE credential_id = ? AND kind = ? AND name = ?',
        'iss',
        [$credentialId, $kind, $name]
    ) > 0;
}

/** Whether any cached VM of this credential carries that instance UUID. */
function deploy_create_release_uuid_present(mysqli $db, int $credentialId, string $instanceUuid): bool
{
    if ($credentialId <= 0 || trim($instanceUuid) === '') {
        return false;
    }
    $kind = VIRTUSPHERE_INVENTORY_KIND_VM;
    $stmt = $db->prepare(
        'SELECT meta_json FROM deploy_esxi_inventory WHERE credential_id = ? AND kind = ? AND meta_json IS NOT NULL'
    );
    $stmt->bind_param('is', $credentialId, $kind);
    $stmt->execute();
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $meta = json_decode((string) $row['meta_json'], true);
        if (!is_array($meta)) {
            continue;
        }
        if (strcasecmp(trim((string) ($meta['instance_uuid'] ?? '')), $instanceUuid) === 0) {
            return true;
        }
    }

    return false;
}

/** A stored UTC datetime as a timestamp, or null when it is unusable. */
function deploy_create_release_timestamp(string $value): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $parsed = strtotime($value . ' UTC');

    return $parsed === false ? null : $parsed;
}
