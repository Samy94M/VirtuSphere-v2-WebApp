<?php

declare(strict_types=1);

require_once __DIR__ . '/mac.php';
require_once __DIR__ . '/repo/helpers.php';

/**
 * The shared current-read owner lookup for editor and callback MAC writes.
 * Callers hold deploy_runtime_identity first; FOR UPDATE makes this a current
 * read even when the transaction established an older REPEATABLE READ snapshot.
 *
 * @param list<string> $candidateMacs
 * @return array<string,list<array{id:int,vm_id:int,mac:string}>> keyed by normalized MAC
 */
function virtusphere_mac_assignment_existing_owners(mysqli $db, array $candidateMacs): array
{
    $normalizedCandidates = [];
    foreach ($candidateMacs as $candidate) {
        $normalized = virtusphere_normalize_mac((string) $candidate);
        if ($normalized !== null) {
            $normalizedCandidates[$normalized] = true;
        }
    }
    if ($normalizedCandidates === []) {
        return [];
    }

    $macs = array_keys($normalizedCandidates);
    sort($macs, SORT_STRING);
    $stmt = $db->prepare(
        'SELECT id, vm_id, mac FROM deploy_interfaces WHERE mac IN ('
        . implode(',', array_fill(0, count($macs), '?'))
        . ') AND mac <> ? ORDER BY mac, id FOR UPDATE'
    );
    $empty = '';
    $stmt->bind_param(str_repeat('s', count($macs)) . 's', ...array_merge($macs, [$empty]));
    $stmt->execute();

    $ownersByMac = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $normalized = virtusphere_normalize_mac((string) $row['mac']) ?? (string) $row['mac'];
        $ownersByMac[$normalized][] = [
            'id' => (int) $row['id'],
            'vm_id' => (int) $row['vm_id'],
            'mac' => (string) $row['mac'],
        ];
    }

    return $ownersByMac;
}
