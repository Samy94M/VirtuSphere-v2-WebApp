<?php

declare(strict_types=1);

/**
 * One reading of the cached ESXi `vm` inventory for a bound VM: whether it is
 * evidence at all and, if so, what it says about the VM. Pure: the findings of
 * lib/vm_phase.php and the technical block of the VM editor read this same rule,
 * so neither can claim an absence or a power state the other does not see.
 */

require_once __DIR__ . '/status_evidence.php';

// The six readings. The checks in virtusphere_vm_inventory_observation() run in
// this order and the first one that applies wins.
const VIRTUSPHERE_VM_INVENTORY_UNBOUND = 'unbound';
const VIRTUSPHERE_VM_INVENTORY_NONE = 'none';
const VIRTUSPHERE_VM_INVENTORY_UNANSWERED = 'unanswered';
const VIRTUSPHERE_VM_INVENTORY_STALE = 'stale';
const VIRTUSPHERE_VM_INVENTORY_MISSING = 'missing';
const VIRTUSPHERE_VM_INVENTORY_PRESENT = 'present';

const VIRTUSPHERE_VM_INVENTORY_STATES = [
    VIRTUSPHERE_VM_INVENTORY_UNBOUND,
    VIRTUSPHERE_VM_INVENTORY_NONE,
    VIRTUSPHERE_VM_INVENTORY_UNANSWERED,
    VIRTUSPHERE_VM_INVENTORY_STALE,
    VIRTUSPHERE_VM_INVENTORY_MISSING,
    VIRTUSPHERE_VM_INVENTORY_PRESENT,
];

/**
 * An unanswered pull is not stale, and an unbound VM is never looked up at all.
 *
 * @param array<string,mixed> $vm the stored VM row (vm_instance_uuid)
 * @param array<string,mixed>|null $inventory the access entry of repo_vm_evidence_inventory()
 *        (fresh, answered, fresh_at, vms as UUID => power_state|null), or null without one
 * @return array{state:string, power:?string, time:string} power is set only for a present VM
 */
function virtusphere_vm_inventory_observation(array $vm, ?array $inventory): array
{
    $uuid = (string) ($vm['vm_instance_uuid'] ?? '');
    if ($uuid === '') {
        return ['state' => VIRTUSPHERE_VM_INVENTORY_UNBOUND, 'power' => null, 'time' => ''];
    }
    if ($inventory === null) {
        return ['state' => VIRTUSPHERE_VM_INVENTORY_NONE, 'power' => null, 'time' => ''];
    }

    $time = (string) ($inventory['fresh_at'] ?? '');
    if (empty($inventory['answered'])) {
        return ['state' => VIRTUSPHERE_VM_INVENTORY_UNANSWERED, 'power' => null, 'time' => $time];
    }
    if (empty($inventory['fresh'])) {
        return ['state' => VIRTUSPHERE_VM_INVENTORY_STALE, 'power' => null, 'time' => $time];
    }

    $vms = (array) ($inventory['vms'] ?? []);
    if (!array_key_exists($uuid, $vms)) {
        return ['state' => VIRTUSPHERE_VM_INVENTORY_MISSING, 'power' => null, 'time' => $time];
    }
    $power = $vms[$uuid];

    return [
        'state' => VIRTUSPHERE_VM_INVENTORY_PRESENT,
        'power' => $power === null ? null : (string) $power,
        'time' => $time,
    ];
}
