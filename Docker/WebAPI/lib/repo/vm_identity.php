<?php

declare(strict_types=1);

require_once __DIR__ . '/../credentials.php';
require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/helpers.php';

final class VmIdentityConflictException extends RuntimeException
{
    /** @param array<int, array<string, mixed>> $identityConflicts */
    public function __construct(private readonly array $identityConflicts)
    {
        $names = array_map(static fn (array $row): string => (string) ($row['vm_name'] ?? ''), $identityConflicts);
        parent::__construct('Foreign or unadopted VM namesake blocks deployment: ' . implode(', ', $names) . '.');
    }

    /** @return array<int, array<string, mixed>> */
    public function conflicts(): array
    {
        return $this->identityConflicts;
    }
}

/**
 * Returns selected portal VMs whose name is occupied on this credential but
 * whose durable instance UUID does not prove it is the same VM. No inventory
 * row means no known collision here; the live playbook guard remains the final
 * authority before a mutation.
 *
 * @param array<int, int> $vmIds empty means the whole mission
 * @return array<int, array<string, mixed>>
 */
function repo_vm_identity_conflicts(mysqli $db, int $missionId, int $credentialId, array $vmIds = []): array
{
    if ($missionId <= 0 || $credentialId <= 0) {
        return [];
    }

    $vmIds = array_values(array_unique(array_filter(array_map('intval', $vmIds), static fn (int $id): bool => $id > 0)));
    $sql = 'SELECT v.id AS vm_id, v.vm_name, v.vm_moid AS stored_moid, v.vm_instance_uuid AS stored_instance_uuid, i.meta_json
            FROM deploy_vms v
            INNER JOIN deploy_esxi_inventory i
              ON i.credential_id = ? AND i.kind = ? AND i.name = v.vm_name
            WHERE v.mission_id = ?';
    $types = 'isi';
    $params = [$credentialId, VIRTUSPHERE_INVENTORY_KIND_VM, $missionId];
    if ($vmIds !== []) {
        $sql .= ' AND v.id IN (' . implode(',', array_fill(0, count($vmIds), '?')) . ')';
        $types .= str_repeat('i', count($vmIds));
        $params = array_merge($params, $vmIds);
    }
    $sql .= ' ORDER BY v.vm_name';

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $conflicts = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        $storedUuid = trim((string) ($row['stored_instance_uuid'] ?? ''));
        $inventoryUuid = trim((string) ($meta['instance_uuid'] ?? ''));
        if ($storedUuid !== '' && $inventoryUuid !== '' && strcasecmp($storedUuid, $inventoryUuid) === 0) {
            continue;
        }

        $conflicts[] = [
            'vm_id' => (int) $row['vm_id'],
            'vm_name' => (string) $row['vm_name'],
            'stored_moid' => trim((string) ($row['stored_moid'] ?? '')),
            'stored_instance_uuid' => $storedUuid,
            'inventory_moid' => trim((string) ($meta['moid'] ?? '')),
            'inventory_instance_uuid' => $inventoryUuid,
        ];
    }

    return $conflicts;
}

/** @param array<int, int> $vmIds */
function repo_deploy_assert_no_vm_identity_conflicts(mysqli $db, int $missionId, int $credentialId, array $vmIds = []): void
{
    $conflicts = repo_vm_identity_conflicts($db, $missionId, $credentialId, $vmIds);
    if ($conflicts !== []) {
        throw new VmIdentityConflictException($conflicts);
    }
}

/**
 * Identity-only write used inside the mission/job lock transaction. The
 * inventory row is the credential-scoped read-back from ESXi; no hardware
 * setting is copied and no remote operation is issued.
 *
 * @return array{vm_id:int, vm_name:string, vm_moid:string, vm_instance_uuid:string}
 */
function repo_vm_identity_adopt_locked(mysqli $db, int $missionId, int $vmId, int $credentialId): array
{
    if ($missionId <= 0 || $vmId <= 0 || $credentialId <= 0) {
        throw new InvalidArgumentException('Mission, VM and ESXi credential are required for adoption.');
    }

    $credential = repo_fetch_one($db, 'SELECT id, type FROM deploy_credentials WHERE id = ? LIMIT 1', 'i', [$credentialId]);
    if ($credential === null || (string) $credential['type'] !== VIRTUSPHERE_CREDENTIAL_TYPE_ESXI) {
        throw new RuntimeException('Selected ESXi credential not found.');
    }

    $vm = repo_fetch_one($db, 'SELECT id, vm_name FROM deploy_vms WHERE id = ? AND mission_id = ? LIMIT 1 FOR UPDATE', 'ii', [$vmId, $missionId]);
    if ($vm === null) {
        throw new RuntimeException('VM not found in mission.');
    }

    $inventory = repo_fetch_one(
        $db,
        'SELECT meta_json FROM deploy_esxi_inventory WHERE credential_id = ? AND kind = ? AND name = ? LIMIT 1',
        'iss',
        [$credentialId, VIRTUSPHERE_INVENTORY_KIND_VM, (string) $vm['vm_name']]
    );
    $meta = $inventory !== null ? json_decode((string) ($inventory['meta_json'] ?? ''), true) : null;
    $moid = is_array($meta) ? trim((string) ($meta['moid'] ?? '')) : '';
    $instanceUuid = is_array($meta) ? trim((string) ($meta['instance_uuid'] ?? '')) : '';
    if ($moid === '' || $instanceUuid === '') {
        throw new RuntimeException('The inventory namesake has no complete MOID and instance UUID; refresh the ESXi inventory before adoption.');
    }

    $stmt = $db->prepare('UPDATE deploy_vms SET vm_moid = ?, vm_instance_uuid = ?, updated_at = NOW() WHERE id = ? AND mission_id = ?');
    $stmt->bind_param('ssii', $moid, $instanceUuid, $vmId, $missionId);
    $stmt->execute();

    return [
        'vm_id' => $vmId,
        'vm_name' => (string) $vm['vm_name'],
        'vm_moid' => $moid,
        'vm_instance_uuid' => $instanceUuid,
    ];
}
