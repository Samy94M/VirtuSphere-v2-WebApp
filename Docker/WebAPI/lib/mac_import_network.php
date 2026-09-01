<?php

declare(strict_types=1);

require_once __DIR__ . '/esxi_object_names.php';
require_once __DIR__ . '/mac.php';
require_once __DIR__ . '/mac_import_constants.php';

function mac_import_exact_name_key(string $name): string
{
    // PHP coerces numeric-looking array keys ("1" and "01") to integers.
    // Prefix the byte length so the grouping key preserves exact raw identity.
    return strlen($name) . ':' . $name;
}

/** @return array<string,list<array<string,mixed>>> */
function mac_import_interfaces_by_vlan(array $interfaces): array
{
    $groups = [];
    foreach ($interfaces as $interface) {
        $vlan = (string) ($interface['vlan'] ?? '');
        $groups[mac_import_exact_name_key($vlan)][] = $interface;
    }
    return $groups;
}

/** @return list<array{vlan:string,mac:string,normalized_mac:?string}> */
function mac_import_instance_nics(array $instance): array
{
    $nics = [];
    foreach ($instance as $key => $value) {
        if (!str_starts_with((string) $key, 'hw_eth')) {
            continue;
        }
        if (!is_array($value)) {
            $nics[] = ['vlan' => '', 'mac' => '', 'normalized_mac' => null];
            continue;
        }
        $mac = trim((string) ($value['macaddress'] ?? ''));
        $nics[] = [
            // ESXi owns this object name. Keep its bytes intact so a case or
            // boundary-whitespace difference can never become a match here.
            'vlan' => (string) ($value['summary'] ?? ''),
            'mac' => $mac,
            'normalized_mac' => virtusphere_normalize_mac($mac),
        ];
    }
    return $nics;
}

/** @return array<string,list<array{vlan:string,mac:string,normalized_mac:?string}>> */
function mac_import_nics_by_vlan(array $nics): array
{
    $groups = [];
    foreach ($nics as $nic) {
        $vlan = (string) $nic['vlan'];
        if (!esxi_object_name_classify_raw($vlan)['supported']) {
            continue;
        }
        $groups[mac_import_exact_name_key($vlan)][] = $nic;
    }
    return $groups;
}

function mac_import_ambiguity_source(int $portalCount, int $esxiCount): ?string
{
    if ($portalCount > 1 && $esxiCount > 1) {
        return 'both';
    }
    if ($portalCount > 1) {
        return 'portal';
    }
    if ($esxiCount > 1) {
        return 'esxi';
    }
    return null;
}

/** Adds the closed WDS/PXE callback errors to one VM plan. */
function mac_import_validate_wds(array &$vmPlan, array $interfaces, array $nics, string $missionWdsVlan): void
{
    $expected = $missionWdsVlan;
    $vmPlan['wds'] = [
        'configured_portgroup' => $expected,
        'portal_interface_id' => null,
        'verified' => false,
    ];
    if (trim($expected) === '') {
        mac_import_add_vm_error($vmPlan, VIRTUSPHERE_MAC_IMPORT_ERROR_MISSION_WDS_MISSING);
        return;
    }

    $portalExact = [];
    $portalSimilar = [];
    foreach ($interfaces as $interface) {
        $vlan = (string) ($interface['vlan'] ?? '');
        if ($vlan === $expected) {
            $portalExact[] = $interface;
        } elseif ($vlan !== '' && esxi_object_name_diagnostic_key($vlan) === esxi_object_name_diagnostic_key($expected)) {
            $portalSimilar[] = $interface;
        }
    }
    if (count($portalExact) > 1) {
        mac_import_add_vm_error($vmPlan, VIRTUSPHERE_MAC_IMPORT_ERROR_PORTAL_WDS_AMBIGUOUS, $expected);
    } elseif ($portalExact === []) {
        mac_import_add_vm_error(
            $vmPlan,
            $portalSimilar !== [] ? VIRTUSPHERE_MAC_IMPORT_ERROR_PORTAL_WDS_CASE : VIRTUSPHERE_MAC_IMPORT_ERROR_PORTAL_WDS_MISSING,
            $expected
        );
    } else {
        $vmPlan['wds']['portal_interface_id'] = (int) $portalExact[0]['id'];
    }

    $esxiExact = [];
    $esxiSimilar = [];
    foreach ($nics as $nic) {
        $vlan = (string) $nic['vlan'];
        // A printable raw name with boundary whitespace is retained by the
        // inventory for diagnosis but is not an operative VMware target. Do
        // not let byte equality turn an unsupported ESXi summary into a MAC
        // mapping; the closed missing code below is the fail-closed fallback.
        if (!esxi_object_name_classify_raw($vlan)['supported']) {
            continue;
        }
        if ($vlan === $expected) {
            $esxiExact[] = $nic;
        } elseif ($vlan !== '' && esxi_object_name_diagnostic_key($vlan) === esxi_object_name_diagnostic_key($expected)) {
            $esxiSimilar[] = $nic;
        }
    }
    if (count($esxiExact) > 1) {
        mac_import_add_vm_error($vmPlan, VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_WDS_AMBIGUOUS, $expected);
    } elseif ($esxiExact === []) {
        mac_import_add_vm_error(
            $vmPlan,
            $esxiSimilar !== [] ? VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_WDS_CASE : VIRTUSPHERE_MAC_IMPORT_ERROR_ESXI_WDS_MISSING,
            $expected
        );
    } elseif ((string) $esxiExact[0]['mac'] === '' || $esxiExact[0]['normalized_mac'] === null) {
        mac_import_add_vm_error($vmPlan, VIRTUSPHERE_MAC_IMPORT_ERROR_WDS_MAC_MISSING, $expected);
    }

    $vmPlan['wds']['verified'] = count($portalExact) === 1
        && count($esxiExact) === 1
        && (string) $esxiExact[0]['mac'] !== ''
        && $esxiExact[0]['normalized_mac'] !== null;
}

/** @param array<int,array> $vmPlans */
function mac_import_validate_duplicate_macs(mysqli $db, array &$vmPlans): void
{
    $planned = [];
    foreach ($vmPlans as $vmId => $vmPlan) {
        if ($vmPlan['errors'] !== []) {
            continue;
        }
        foreach ($vmPlan['updates'] as $update) {
            $planned[(string) $update['mac']][] = ['vm_id' => (int) $vmId, 'interface_id' => (int) $update['id']];
        }
    }
    ksort($planned, SORT_STRING);

    if ($planned === []) {
        return;
    }
    $macs = array_keys($planned);
    $lookup = $db->prepare(
        "SELECT id, vm_id, mac FROM deploy_interfaces WHERE mac IN ("
        . implode(',', array_fill(0, count($macs), '?')) . ") AND mac <> '' ORDER BY mac, id FOR UPDATE"
    );
    $lookup->bind_param(str_repeat('s', count($macs)), ...$macs);
    $lookup->execute();
    $existingByMac = [];
    foreach ($lookup->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $normalized = virtusphere_normalize_mac((string) $row['mac']) ?? (string) $row['mac'];
        $existingByMac[$normalized][] = $row;
    }
    foreach ($planned as $mac => $owners) {
        $existing = $existingByMac[$mac] ?? [];
        if (count($existing) > 1) {
            foreach ($owners as $owner) {
                mac_import_add_vm_error($vmPlans[$owner['vm_id']], VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_MAC, '', $mac, (int) $existing[0]['vm_id']);
            }
            continue;
        }
        if ($existing !== []) {
            $existingRow = $existing[0];
            foreach ($owners as $owner) {
                if ((int) $existingRow['id'] !== $owner['interface_id']) {
                    mac_import_add_vm_error($vmPlans[$owner['vm_id']], VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_MAC, '', $mac, (int) $existingRow['vm_id']);
                }
            }
            continue;
        }
        if (count($owners) > 1) {
            foreach ($owners as $owner) {
                $other = current(array_filter($owners, static fn (array $candidate): bool => $candidate['interface_id'] !== $owner['interface_id']));
                mac_import_add_vm_error($vmPlans[$owner['vm_id']], VIRTUSPHERE_MAC_IMPORT_ERROR_DUPLICATE_MAC, '', $mac, is_array($other) ? (int) $other['vm_id'] : null);
            }
        }
    }
}
