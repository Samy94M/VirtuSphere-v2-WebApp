<?php

declare(strict_types=1);

/**
 * K20 (VT-01): every IP allowlist entry of the machine API carries one role,
 * and every machine endpoint accepts only its roles. Before, one entry opened
 * every endpoint: the Ansible host that is listed for the MAC callback could
 * also call updateDevice, reportMembership and the package catalog, and the
 * MECM server the MAC callback.
 *
 * An entry without a role (every entry from before migration 0063) keeps
 * opening every endpoint until an administrator assigns one; System status
 * names how many such entries remain. A role is never guessed.
 *
 * The map below is the one place that says which caller a gate expects. Each
 * gate names its key; MachineApiRoleContractTest derives the gates from the
 * endpoint files and refuses a key that is not in this map.
 */

const VIRTUSPHERE_MACHINE_API_ROLE_MECM_SERVER = 'mecm_server';
const VIRTUSPHERE_MACHINE_API_ROLE_ANSIBLE_HOST = 'ansible_host';
const VIRTUSPHERE_MACHINE_API_ROLE_CLIENT = 'client';
const VIRTUSPHERE_MACHINE_API_ROLES = [
    VIRTUSPHERE_MACHINE_API_ROLE_MECM_SERVER,
    VIRTUSPHERE_MACHINE_API_ROLE_ANSIBLE_HOST,
    VIRTUSPHERE_MACHINE_API_ROLE_CLIENT,
];

/** Gate key => the roles whose IPs that gate admits. */
const VIRTUSPHERE_MACHINE_API_GATE_ROLES = [
    // Ansible host: the export playbook's MAC callback and the per-VM ESXi
    // touch admission of power-cycle and export (K15).
    'mac_import' => [VIRTUSPHERE_MACHINE_API_ROLE_ANSIBLE_HOST],
    'vm_touch' => [VIRTUSPHERE_MACHINE_API_ROLE_ANSIBLE_HOST],
    // MECM server: device sync, package/task-sequence sync, site health and
    // the token-gated server reports.
    'device_list' => [VIRTUSPHERE_MACHINE_API_ROLE_MECM_SERVER],
    'device_update' => [VIRTUSPHERE_MACHINE_API_ROLE_MECM_SERVER],
    'package_catalog' => [VIRTUSPHERE_MACHINE_API_ROLE_MECM_SERVER],
    'server_report' => [VIRTUSPHERE_MACHINE_API_ROLE_MECM_SERVER],
    // Deploy clients. Three of these also accept a known MAC instead of the IP;
    // that path is untouched, the role only governs the IP path.
    'device_infos' => [VIRTUSPHERE_MACHINE_API_ROLE_CLIENT],
    'client_phase' => [VIRTUSPHERE_MACHINE_API_ROLE_CLIENT],
    'client_ack' => [VIRTUSPHERE_MACHINE_API_ROLE_CLIENT],
    'package_report' => [VIRTUSPHERE_MACHINE_API_ROLE_CLIENT],
];

// Closed refusal causes for the audit trail. The 403 body stays the frozen
// legacy sentence; the cause is recorded, never sent.
const VIRTUSPHERE_MACHINE_API_DENY_NOT_ALLOWLISTED = 'ip_not_allowlisted';
const VIRTUSPHERE_MACHINE_API_DENY_ROLE = 'ip_role_not_permitted';

/**
 * The IP decision for one gate. `allowed` is true when an entry for the IP has
 * no role (legacy, every gate) or one of the gate's roles. Otherwise `reason`
 * says whether the IP is unknown or known only under another role.
 *
 * @return array{allowed:bool,reason:?string}
 */
function machine_api_ip_decision(mysqli $db, string $ip, string $gate): array
{
    if (!array_key_exists($gate, VIRTUSPHERE_MACHINE_API_GATE_ROLES)) {
        throw new InvalidArgumentException('Unknown machine API gate: ' . $gate);
    }
    $stmt = $db->prepare('SELECT role FROM deploy_accessToWebAPI WHERE ipAddress = ?');
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if ($rows === []) {
        return ['allowed' => false, 'reason' => VIRTUSPHERE_MACHINE_API_DENY_NOT_ALLOWLISTED];
    }
    foreach ($rows as $row) {
        $role = $row['role'] === null ? null : (string) $row['role'];
        if ($role === null || in_array($role, VIRTUSPHERE_MACHINE_API_GATE_ROLES[$gate], true)) {
            return ['allowed' => true, 'reason' => null];
        }
    }

    return ['allowed' => false, 'reason' => VIRTUSPHERE_MACHINE_API_DENY_ROLE];
}
