<?php

declare(strict_types=1);

require_once __DIR__ . '/../network_mac_constants.php';
require_once __DIR__ . '/../vm_network_contract.php';
require_once __DIR__ . '/../validate.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../vm_network_mac_assignments.php';

final class VmNetworkPreflightException extends RuntimeException
{
    /** @param list<array<string,mixed>> $findings */
    public function __construct(public readonly array $findings)
    {
        parent::__construct('VM network preflight blocked: ' . (string) ($findings[0]['code'] ?? 'unknown'));
    }
}

final class VmNetworkScopeActiveException extends RuntimeException
{
    public function __construct(public readonly int $jobId)
    {
        parent::__construct('A deploy job owns this VM network scope.');
    }
}

/**
 * Materializes the actual VM/interface scope in two bounded queries.
 * An empty VM-id list means the full mission; callers must filter a posted
 * non-empty selection before passing it here so a vanished selection never
 * widens silently.
 *
 * @param list<int> $vmIds
 * @return list<array<string,mixed>>
 */
function repo_vm_network_scope(mysqli $db, int $missionId, array $vmIds = [], bool $lock = false): array
{
    $vmIds = array_values(array_unique(array_filter(array_map('intval', $vmIds), static fn (int $id): bool => $id > 0)));
    sort($vmIds, SORT_NUMERIC);
    $params = [$missionId];
    $types = 'i';
    $where = 'mission_id = ?';
    if ($vmIds !== []) {
        $where .= ' AND id IN (' . implode(',', array_fill(0, count($vmIds), '?')) . ')';
        $params = array_merge($params, $vmIds);
        $types .= str_repeat('i', count($vmIds));
    }
    $stmt = $db->prepare('SELECT id, mission_id, vm_name FROM deploy_vms WHERE ' . $where . ' ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $vms = repo_fetch_all($stmt->get_result());
    if ($vms === []) {
        return [];
    }

    $ids = array_map(static fn (array $vm): int => (int) $vm['id'], $vms);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare('SELECT id, vm_id, ip, subnet, gateway, dns1, dns2, vlan, mac, mode, type FROM deploy_interfaces WHERE vm_id IN (' . $placeholders . ') ORDER BY vm_id, id' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $interfaces = [];
    foreach (repo_fetch_all($stmt->get_result()) as $interface) {
        $interfaces[(int) $interface['vm_id']][] = $interface;
    }

    foreach ($vms as &$vm) {
        $vm['interfaces'] = $interfaces[(int) $vm['id']] ?? [];
    }
    unset($vm);

    return $vms;
}

/**
 * @param list<int> $vmIds
 * @return list<array<string,mixed>>
 */
function repo_vm_network_issues_for_scope(mysqli $db, int $missionId, array $vmIds = [], bool $lock = false): array
{
    $issues = [];
    foreach (repo_vm_network_scope($db, $missionId, $vmIds, $lock) as $vm) {
        array_push($issues, ...vm_network_issues_for_interfaces(
            (array) $vm['interfaces'],
            $missionId,
            (int) $vm['id'],
            (string) $vm['vm_name']
        ));
    }
    vm_network_sort_issues($issues);

    return $issues;
}

/**
 * @param list<int> $vmIds
 * @return array{vms:list<array<string,mixed>>,network_issues:list<array<string,mixed>>,wds:list<array<string,mixed>>}
 */
function repo_vm_network_preflight(mysqli $db, int $missionId, array $vmIds, string $missionWdsVlan, bool $lock = false): array
{
    $vms = repo_vm_network_scope($db, $missionId, $vmIds, $lock);
    $networkIssues = [];
    $wds = [];
    foreach ($vms as $vm) {
        $interfaces = (array) $vm['interfaces'];
        array_push($networkIssues, ...vm_network_issues_for_interfaces($interfaces, $missionId, (int) $vm['id'], (string) $vm['vm_name']));
        $wds[] = vm_network_wds_verdict($interfaces, $missionWdsVlan, $missionId, (int) $vm['id'], (string) $vm['vm_name']);
    }

    return ['vms' => $vms, 'network_issues' => $networkIssues, 'wds' => $wds];
}

/**
 * Both lists merge two producers, so both end in the canonical order. Without
 * it "the first ten" would mean "all the VLAN findings, then whatever WDS
 * findings still fit", which is an artefact of concatenation and not a ranking
 * anybody chose; the bounded render, the JSON island and the stored result
 * would then also disagree about which findings survived.
 *
 * @param array{network_issues:list<array>,wds:list<array>} $preflight
 */
function repo_vm_network_preflight_blockers(array $preflight, string $mode): array
{
    $blockers = [];
    if (deploy_mode_requires_unique_network_mapping($mode)) {
        array_push($blockers, ...$preflight['network_issues']);
    }
    if (deploy_mode_requires_wds_ready($mode)) {
        foreach ($preflight['wds'] as $verdict) {
            if ((string) ($verdict['code'] ?? '') !== VIRTUSPHERE_WDS_READY) {
                $blockers[] = $verdict;
            }
        }
    }
    vm_network_sort_issues($blockers);

    return $blockers;
}

/** @param array{network_issues:list<array>,wds:list<array>} $preflight */
function repo_vm_network_preflight_warnings(array $preflight, string $mode): array
{
    $warnings = [];
    if (!deploy_mode_requires_unique_network_mapping($mode)) {
        array_push($warnings, ...$preflight['network_issues']);
    }
    if (!deploy_mode_requires_wds_ready($mode)) {
        foreach ($preflight['wds'] as $verdict) {
            if ((string) ($verdict['code'] ?? '') !== VIRTUSPHERE_WDS_READY) {
                $warnings[] = $verdict;
            }
        }
    }
    vm_network_sort_issues($warnings);

    return $warnings;
}

/**
 * The regular scope of ONE deploy job.
 *
 * It is a queue-time decision on purpose. The callback bounds are absolute: a
 * V2 MAC result above VIRTUSPHERE_MAC_IMPORT_RESULT_MAX_BYTES answers 409, and
 * by then the playbook has already created the VMs it is reporting about, so
 * the operator holds a failed job over changes that did happen and no split of
 * the selection can undo. Refusing the same scope before anything remote runs
 * costs one clear message instead.
 *
 * @param list<array<string,mixed>> $vms materialized scope with `interfaces`
 */
function repo_vm_network_assert_scope_within_bounds(array $vms): void
{
    if (count($vms) > VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_VMS) {
        $text = validator_text(
            'validate.deploy_scope_vm_limit',
            'A deploy job may cover at most :max VMs; :count were selected.',
            ['max' => VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_VMS, 'count' => count($vms)]
        );
        throw new ValidationException(['vm_ids' => $text], $text);
    }
    foreach ($vms as $vm) {
        $interfaces = count((array) ($vm['interfaces'] ?? []));
        if ($interfaces > VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_INTERFACES_PER_VM) {
            $text = validator_text(
                'validate.deploy_scope_interface_limit',
                'VM :name has :count network interfaces; at most :max are supported.',
                ['name' => (string) ($vm['vm_name'] ?? ''), 'count' => $interfaces, 'max' => VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_INTERFACES_PER_VM]
            );
            throw new ValidationException(['vm_ids' => $text], $text);
        }
    }
}

/**
 * @param list<int> $vmIds
 * @param bool $jobScope false while validating the UNION of a stagger group:
 *        that union becomes one job per VM, so the per-job scope cap does not
 *        apply to it and would reject a legitimate large group.
 */
function repo_vm_network_assert_deploy_ready(mysqli $db, int $missionId, array $vmIds, string $missionWdsVlan, string $mode, bool $lock = true, bool $jobScope = true): array
{
    $preflight = repo_vm_network_preflight($db, $missionId, $vmIds, $missionWdsVlan, $lock);
    if ($jobScope) {
        repo_vm_network_assert_scope_within_bounds($preflight['vms']);
    }
    $blockers = repo_vm_network_preflight_blockers($preflight, $mode);
    if ($blockers !== []) {
        throw new VmNetworkPreflightException($blockers);
    }

    return $preflight;
}

/**
 * Refuses a network change while a running/cancelling job owns the scope. An
 * empty VM list means the whole mission (used for mission WDS changes).
 * Mission row must already be locked by the caller; queued jobs remain editable
 * until their worker rechecks and claims them.
 */
function repo_vm_network_assert_scope_idle(mysqli $db, int $missionId, array $vmIds): void
{
    $vmIds = array_values(array_unique(array_filter(array_map('intval', $vmIds), static fn (int $id): bool => $id > 0)));
    $active = [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, VIRTUSPHERE_DEPLOY_STATUS_CANCELLING];
    $placeholders = implode(',', array_fill(0, count($active), '?'));
    $stmt = $db->prepare(
        'SELECT id, payload_json FROM deploy_jobs WHERE mission_id = ? AND status IN (' . $placeholders . ') AND cancelled_at IS NULL ORDER BY id FOR UPDATE'
    );
    $params = array_merge([$missionId], $active);
    $stmt->bind_param('i' . str_repeat('s', count($active)), ...$params);
    $stmt->execute();
    $rows = repo_fetch_all($stmt->get_result());
    foreach ($rows as $row) {
        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        $scope = is_array($payload) && is_array($payload['vm_ids'] ?? null)
            ? array_values(array_unique(array_map('intval', $payload['vm_ids'])))
            : [];
        if ($vmIds === [] || $scope === [] || array_intersect($scope, $vmIds) !== []) {
            throw new VmNetworkScopeActiveException((int) $row['id']);
        }
    }
}

/**
 * Serializes new MAC assignments with the machine callback's duplicate check.
 * db_importMAC.php already locks this singleton before it reads or writes NICs;
 * interface repository writes take the same row before their uniqueness read.
 * This is transaction-scoped and adds no second MAC registry or schema index.
 */
function repo_vm_network_lock_mac_assignments(mysqli $db): void
{
    if (repo_transaction_depth($db) === 0) {
        throw new LogicException('MAC assignment checks require the caller transaction.');
    }

    if (repo_fetch_one($db, 'SELECT id FROM deploy_runtime_identity WHERE id = 1 FOR UPDATE') === null) {
        throw new RuntimeException('Deploy runtime identity singleton is missing.');
    }
}

/**
 * Whether this posted bundle can introduce a MAC assignment. Callers use this
 * before taking a VM lock so the shared Runtime fence remains ahead of VM and
 * Interface locks. Existing callback-owned MACs in preserve mode are excluded.
 */
function repo_vm_network_has_new_mac_assignments(mixed $interfaces, bool $preserveExistingMacs): bool
{
    if (!is_iterable($interfaces)) {
        return false;
    }

    foreach ($interfaces as $interface) {
        $interfaceId = repo_id(repo_object_get($interface, 'id', repo_object_get($interface, 'Id')));
        $mac = trim((string) repo_object_get($interface, 'mac', ''));
        if ($mac !== '' && (!$preserveExistingMacs || $interfaceId <= 0)) {
            return true;
        }
    }

    return false;
}

/**
 * Locks every active job row for one already-locked mission without deciding
 * whether a newly-created VM intersects that job. The normal bundle writer
 * performs the existing scope decision after the VM id is known.
 */
function repo_vm_network_lock_active_jobs_for_mac_assignment(mysqli $db, int $missionId): void
{
    $active = [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, VIRTUSPHERE_DEPLOY_STATUS_CANCELLING];
    $placeholders = implode(',', array_fill(0, count($active), '?'));
    $stmt = $db->prepare(
        'SELECT id FROM deploy_jobs WHERE mission_id = ? AND status IN (' . $placeholders . ') AND cancelled_at IS NULL ORDER BY id FOR UPDATE'
    );
    $params = array_merge([$missionId], $active);
    $stmt->bind_param('i' . str_repeat('s', count($active)), ...$params);
    $stmt->execute();
    // Consume the result while retaining the row locks through the transaction.
    repo_fetch_all($stmt->get_result());
}

/**
 * Refuses MACs newly introduced by an interface bundle when any retained or
 * other VM already owns them. Unchanged legacy assignments are grandfathered.
 * The caller holds the mission lock and its transaction.
 *
 * @param list<array<string,mixed>> $interfaces effective, normalized bundle
 */
function repo_vm_network_assert_new_macs_unique(mysqli $db, int $vmId, array $interfaces, bool $preserveExistingMacs): void
{
    if (!repo_vm_network_has_new_mac_assignments($interfaces, $preserveExistingMacs)) {
        return;
    }

    repo_vm_network_lock_mac_assignments($db);

    $currentById = [];
    $stmt = $db->prepare('SELECT id, mac FROM deploy_interfaces WHERE vm_id = ? ORDER BY id FOR UPDATE');
    $stmt->bind_param('i', $vmId);
    $stmt->execute();
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $currentById[(int) $row['id']] = virtusphere_normalize_mac((string) ($row['mac'] ?? ''));
    }

    $retainedIds = [];
    $candidates = [];
    $firstCandidateByMac = [];
    foreach ($interfaces as $index => $interface) {
        $interfaceId = (int) ($interface['id'] ?? 0);
        if ($interfaceId > 0) {
            $retainedIds[$interfaceId] = true;
        }
        $mac = virtusphere_normalize_mac((string) ($interface['mac'] ?? ''));
        if ($mac === null || ($preserveExistingMacs && $interfaceId > 0)) {
            continue;
        }
        if ($interfaceId > 0 && ($currentById[$interfaceId] ?? null) === $mac) {
            continue;
        }
        if (isset($firstCandidateByMac[$mac])) {
            $field = 'interfaces.' . (int) $index . '.mac';
            $message = validator_text(
                'validate.mac_duplicate',
                ':field is already assigned to another interface.',
                ['field' => validator_label('interface_mac', 'Interface MAC')]
            );
            throw new ValidationException([$field => $message], $message);
        }
        $firstCandidateByMac[$mac] = (int) $index;
        $candidates[$mac][] = ['interface_id' => $interfaceId, 'index' => (int) $index];
    }
    if ($candidates === []) {
        return;
    }

    ksort($candidates, SORT_STRING);
    $existingByMac = virtusphere_mac_assignment_existing_owners($db, array_keys($candidates));
    foreach ($candidates as $mac => $macCandidates) {
        foreach ($existingByMac[$mac] ?? [] as $row) {
            $rowId = (int) $row['id'];
            $rowVmId = (int) $row['vm_id'];
            if ($rowVmId === $vmId && !isset($retainedIds[$rowId])) {
                continue;
            }
            foreach ($macCandidates as $candidate) {
                if ($candidate['interface_id'] === $rowId && $rowVmId === $vmId) {
                    continue;
                }
                $field = 'interfaces.' . $candidate['index'] . '.mac';
                $message = validator_text(
                    'validate.mac_duplicate',
                    ':field is already assigned to another interface.',
                    ['field' => validator_label('interface_mac', 'Interface MAC')]
                );
                throw new ValidationException([$field => $message], $message);
            }
        }
    }
}

/**
 * Executes an already simulated exact VLAN reassignment. The caller owns the
 * transaction and the Mission -> active Job -> VM -> Interface lock order.
 * Keeping the raw UPDATE here makes this module the sole production VLAN owner.
 *
 * @param list<int> $interfaceIds
 */
function repo_vm_network_update_vlan_ids(mysqli $db, array $interfaceIds, string $targetVlan): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $interfaceIds), static fn (int $id): bool => $id > 0)));
    sort($ids, SORT_NUMERIC);
    $updated = 0;
    $stmt = $db->prepare('UPDATE deploy_interfaces SET vlan = ? WHERE id = ?');
    foreach ($ids as $id) {
        $stmt->bind_param('si', $targetVlan, $id);
        $stmt->execute();
        $updated += $stmt->affected_rows;
        $vmId = (int) repo_scalar($db, 'SELECT vm_id FROM deploy_interfaces WHERE id = ?', 'i', [$id]);
        repo_advance_vm_edit_version($db, $vmId);
    }
    return $updated;
}

/**
 * IDR-P02: forgets the MACs an externally deleted VM reported once its binding
 * was replaced by a newly created instance. Create never configures a MAC, so
 * every stored value describes the deleted VM; keeping one would make the
 * power-cycle skip the new VM (`needs_mac`) and hand MECM a foreign address.
 * The caller owns the transaction and holds the job and VM locks, so the lock
 * order stays Job -> VM -> Interfaces. A changed row advances the edit version
 * like the VLAN writer above, so an editor opened before the replacement
 * cannot save the old addresses back.
 */
function repo_vm_network_forget_observed_macs(mysqli $db, int $vmId): int
{
    if ($vmId <= 0) {
        throw new InvalidArgumentException('VM id is required.');
    }
    $stmt = $db->prepare("UPDATE deploy_interfaces SET mac = '' WHERE vm_id = ? AND mac IS NOT NULL AND mac <> ''");
    $stmt->bind_param('i', $vmId);
    $stmt->execute();
    $forgotten = $stmt->affected_rows;
    if ($forgotten > 0) {
        repo_advance_vm_edit_version($db, $vmId);
    }

    return $forgotten;
}

/**
 * Central writer gate. The caller owns the transaction and has locked the
 * mission row before this function takes the active job, VM and interface
 * locks. An unchanged invalid legacy bundle is grandfathered; every changed
 * bundle must satisfy the current network contract in full.
 *
 * @param list<array<string,mixed>> $proposedInterfaces validated effective rows
 */
function repo_vm_network_assert_bundle_write_allowed(mysqli $db, int $missionId, int $vmId, string $vmName, array $proposedInterfaces): void
{
    if ($missionId <= 0 || $vmId <= 0) {
        throw new InvalidArgumentException('Mission and VM are required for a network write.');
    }
    repo_vm_network_assert_scope_idle($db, $missionId, [$vmId]);

    $currentVm = repo_fetch_one(
        $db,
        'SELECT id, vm_name FROM deploy_vms WHERE id = ? AND mission_id = ? FOR UPDATE',
        'ii',
        [$vmId, $missionId]
    );
    if ($currentVm === null) {
        throw new RuntimeException('VM not found for network write.');
    }
    $stmt = $db->prepare('SELECT id, vm_id, ip, subnet, gateway, dns1, dns2, vlan, mac, mode, type FROM deploy_interfaces WHERE vm_id = ? ORDER BY id FOR UPDATE');
    $stmt->bind_param('i', $vmId);
    $stmt->execute();
    $current = repo_fetch_all($stmt->get_result());
    if ($current !== [] && hash_equals(vm_network_bundle_fingerprint($current), vm_network_bundle_fingerprint($proposedInterfaces))) {
        return;
    }

    $issues = vm_network_issues_for_interfaces($proposedInterfaces, $missionId, $vmId, $vmName !== '' ? $vmName : (string) $currentVm['vm_name']);
    if ($issues === []) {
        return;
    }
    $errors = [];
    foreach ($issues as $issue) {
        foreach ((array) $issue['row_indexes'] as $rowIndex) {
            $field = 'interfaces.' . (int) $rowIndex . '.vlan';
            $errors[$field] = validator_text(
                (string) $issue['code'] === VIRTUSPHERE_VM_NETWORK_EMPTY ? 'validate.interface_vlan_required' : 'validate.interface_vlan_unique',
                (string) $issue['code'] === VIRTUSPHERE_VM_NETWORK_EMPTY
                    ? 'Every stored network interface requires a VLAN.'
                    : 'Each network interface of a VM requires a different VLAN.',
                ['vlan' => (string) $issue['vlan']]
            );
        }
    }
    throw new ValidationException($errors, reset($errors) ?: 'VM network configuration is invalid.');
}
