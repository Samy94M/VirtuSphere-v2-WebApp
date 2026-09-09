<?php

declare(strict_types=1);

require_once __DIR__ . '/../credentials.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/../validate.php';
require_once __DIR__ . '/helpers.php';

/**
 * Historical Create effects outlive the job's terminal status. All admission
 * paths consume this owner; neither a fresh job nor another credential for the
 * same endpoint establishes that an earlier remote operation ended.
 */
final class DeployCreateHistoryBlocked extends RuntimeException
{
    /** @param list<array<string,mixed>> $findings */
    public function __construct(public readonly array $findings)
    {
        $first = $findings[0];
        parent::__construct(validator_text(
            'deploy.blocker_create_history',
            'The earlier create operation for :name in job #:job is unresolved. Review its create result before starting another job.',
            ['name' => (string) $first['vm_name'], 'job' => (int) $first['job_id']]
        ));
    }
}

/**
 * A credential alias is the same parsed endpoint. VM object names never pass
 * through this normalizer. DNS/IP aliases are deliberately not guessed.
 *
 * @return array{scheme:string,hostname:string,port:int}|null
 */
function deploy_create_fence_endpoint(array $credential): ?array
{
    return credential_esxi_normalize((string) ($credential['host'] ?? ''), $credential['port'] ?? null);
}

/** @return list<array<string,mixed>> */
function repo_deploy_create_fence_sources(mysqli $db, bool $lock = false, int $currentJobId = 0): array
{
    $states = VIRTUSPHERE_CREATE_RESULT_INFLIGHT_STATUSES;
    $stmt = $db->prepare(
        'SELECT r.job_id, j.credential_esxi_id, r.id AS result_id, r.vm_id, r.vm_name,'
        . ' r.position, r.status, r.precheck_instance_uuid, r.vm_instance_uuid, c.host, c.port'
        . ' FROM deploy_create_vm_results r LEFT JOIN deploy_jobs j ON j.id = r.job_id'
        . ' LEFT JOIN deploy_credentials c ON c.id = j.credential_esxi_id'
        . ' WHERE r.status IN (' . implode(',', array_fill(0, count($states), '?')) . ')'
        . ' AND r.job_id <> ? ORDER BY r.job_id, r.position' . ($lock ? ' FOR UPDATE OF r' : '')
    );
    $params = array_merge($states, [$currentJobId]);
    $stmt->bind_param(str_repeat('s', count($states)) . 'i', ...$params);
    $stmt->execute();
    return repo_fetch_all($stmt->get_result());
}

/**
 * Called after the mission/job lock (and Runtime when claiming) and before
 * locking the selected VM rows. ONLY the Create rows are locked here: locking
 * historical parent jobs after the current job would invert the ascending job
 * order used by service pause and credential changes. Parent/credential joins
 * are descriptive; absent metadata cannot remove a row or prove disjoint scope.
 * A write uses a current locking read: a pre-existing REPEATABLE READ snapshot
 * must not hide an uncertainty committed while admission waited for a lock.
 * The current job's own rows are excluded, so its resumed poll remains legal.
 *
 * @param list<int> $vmIds empty means the full mission
 * @return list<array<string,mixed>>
 */
function repo_deploy_create_fence_conflicts(
    mysqli $db,
    int $missionId,
    int $credentialId,
    array $vmIds = [],
    int $currentJobId = 0,
    bool $lock = false
): array {
    $sources = repo_deploy_create_fence_sources($db, $lock, $currentJobId);
    if ($sources === []) {
        return [];
    }
    $credential = repo_fetch_one($db, 'SELECT host, port FROM deploy_credentials WHERE id = ?'
        . ($lock ? ' FOR UPDATE' : ''), 'i', [$credentialId]);
    $target = deploy_create_fence_endpoint($credential ?? []);
    $ids = array_values(array_unique(array_filter(array_map('intval', $vmIds), static fn (int $id): bool => $id > 0)));
    $sql = 'SELECT id, vm_name, vm_instance_uuid FROM deploy_vms WHERE mission_id = ?';
    $types = 'i';
    $params = [$missionId];
    if ($ids !== []) {
        $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $types .= str_repeat('i', count($ids));
        $params = array_merge($params, $ids);
    }
    $stmt = $db->prepare($sql . ' ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $vms = repo_fetch_all($stmt->get_result());
    $findings = [];
    foreach ($sources as $source) {
        $sourceTarget = deploy_create_fence_endpoint($source);
        foreach ($vms as $vm) {
            // The portal identity survives a rename, move or changed target.
            // Unknown historical endpoints cannot prove disjoint host scope.
            $sameVm = (int) ($source['vm_id'] ?? 0) === (int) $vm['id'];
            // HTTP/HTTPS and alternate ports do not prove another standalone
            // host. DNS host spelling is insensitive; ESXi VM names are not.
            $sameHost = $target === null || $sourceTarget === null
                || strcasecmp($target['hostname'], $sourceTarget['hostname']) === 0;
            $uuid = (string) ($vm['vm_instance_uuid'] ?? '');
            $sameUuid = $uuid !== '' && in_array($uuid, [
                (string) ($source['precheck_instance_uuid'] ?? ''),
                (string) ($source['vm_instance_uuid'] ?? ''),
            ], true);
            if (!$sameVm && !($sameHost && ((string) $vm['vm_name'] === (string) $source['vm_name'] || $sameUuid))) {
                continue;
            }
            $findings[] = [
                'code' => 'create_history_unresolved',
                'vm_id' => (int) $vm['id'],
                'vm_name' => (string) $vm['vm_name'],
                'job_id' => (int) $source['job_id'],
                'position' => (int) $source['position'],
                'result_id' => (int) $source['result_id'],
            ];
        }
    }
    return $findings;
}

/** @param list<int> $vmIds */
function repo_deploy_assert_create_history_resolved(mysqli $db, int $missionId, int $credentialId, array $vmIds = [], int $currentJobId = 0, bool $lock = true): void
{
    $findings = repo_deploy_create_fence_conflicts($db, $missionId, $credentialId, $vmIds, $currentJobId, $lock);
    if ($findings !== []) {
        throw new DeployCreateHistoryBlocked($findings);
    }
}

/**
 * Keep the endpoint evidence behind every unresolved row available. Access
 * repair (password, username, trust) is allowed; moving/deleting the target is
 * refused in the same transaction as the credential write. Active jobs also
 * hold this evidence before their first Create row becomes prepared.
 */
function repo_deploy_create_fence_credential_change(mysqli $db, int $id, ?array $replacement): void
{
    // Lock referenced jobs once in ascending order, including terminal ones.
    // This current read is also the credential association: the descriptive
    // joined job in a pinned RR snapshot may not exist there yet.
    $stmt = $db->prepare('SELECT id, status, credential_esxi_id FROM deploy_jobs'
        . ' WHERE credential_esxi_id = ? OR credential_ansible_id = ? ORDER BY id FOR UPDATE');
    $stmt->bind_param('ii', $id, $id);
    $stmt->execute();
    $jobs = repo_fetch_all($stmt->get_result());
    $active = array_filter($jobs, static fn (array $job): bool => in_array($job['status'], VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES, true));
    $esxiJobs = array_fill_keys(array_map(static fn (array $job): int => (int) $job['id'], array_filter(
        $jobs, static fn (array $job): bool => (int) ($job['credential_esxi_id'] ?? 0) === $id
    )), true);
    $sources = repo_deploy_create_fence_sources($db, true);
    $current = repo_fetch_one($db, 'SELECT type, host, port FROM deploy_credentials WHERE id = ? FOR UPDATE', 'i', [$id]);
    if ($current === null) {
        throw new RuntimeException('Credential not found.');
    }
    $sameTarget = $replacement !== null && $current['type'] === $replacement['type']
        && ($current['type'] === VIRTUSPHERE_CREDENTIAL_TYPE_ESXI
            ? deploy_create_fence_endpoint($current) === deploy_create_fence_endpoint($replacement)
            : (string) $current['host'] === (string) $replacement['host']
                && credential_ssh_port($current['port'] ?? null) === credential_ssh_port($replacement['port'] ?? null));
    if ($sameTarget) {
        return;
    }
    $hasHistory = array_filter($sources, static fn (array $source): bool => isset($esxiJobs[(int) $source['job_id']])) !== [];
    if ($active !== [] || $hasHistory) {
        throw new ValidationException([], validator_text(
            'deploy.blocker_create_credential_target',
            'This credential still identifies an active deployment job or an unresolved VM creation. Its target cannot currently be changed or deleted.'
        ));
    }
}
