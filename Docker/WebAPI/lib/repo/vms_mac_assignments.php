<?php

declare(strict_types=1);

require_once __DIR__ . '/../network_mac_constants.php';
require_once __DIR__ . '/../validate.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../vm_network_mac_assignments.php';

/**
 * Who may assign a MAC (K14/WM-06): the editor, the legacy writers and the
 * machine callback serialize on the same Runtime row and read the same
 * current owners before a new assignment. Read and lock only; the interface
 * writes stay in vm_network.php and vms_persistence.php.
 */

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
