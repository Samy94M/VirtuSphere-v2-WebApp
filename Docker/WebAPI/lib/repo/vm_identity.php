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
        parent::__construct('Foreign VM namesake blocks deployment: ' . implode(', ', $names) . '.');
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
