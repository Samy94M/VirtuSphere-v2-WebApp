<?php

declare(strict_types=1);

require_once __DIR__ . '/../vm_network_contract.php';
require_once __DIR__ . '/../defaults.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/vm_network.php';

final class VlanReassignScopeChangedException extends RuntimeException
{
}

final class VlanReassignTargetInactiveException extends RuntimeException
{
}

/**
 * Materializes the complete exact-name scope used by both preview and write.
 * The returned fingerprint includes every affected id, every effective network
 * bundle (including MACs), active conflicting jobs, and the active target row.
 *
 * @return array{
 *   version:int,fingerprint:string,source:string,target:string,target_active:bool,
 *   missions:list<int>,templates:list<int>,affected_missions:list<int>,vms:list<int>,interfaces:list<int>,
 *   active_jobs:list<array{id:int,status:string}>,counts:array{missions:int,templates:int,vms:int,interfaces:int,active_jobs:int}
 * }
 */
function repo_vlan_reassign_scope(mysqli $db, string $from, string $to, bool $lock = false): array
{
    if (trim($from) === '' || trim($to) === '' || $from === $to) {
        throw new InvalidArgumentException('Source and target VLAN are required and must differ.');
    }

    $catalog = repo_fetch_all($db->query($lock
        ? 'SELECT id, vlan_name, retired_at FROM deploy_vlan ORDER BY id FOR UPDATE'
        : 'SELECT id, vlan_name, retired_at FROM deploy_vlan ORDER BY id'));
    $targetRows = [];
    foreach ($catalog as $row) {
        if ((string) $row['vlan_name'] === $to && $row['retired_at'] === null) {
            $targetRows[] = (int) $row['id'];
        }
    }

    $missions = repo_fetch_all($db->query($lock
        ? 'SELECT id, mission_name, wds_vlan FROM deploy_missions ORDER BY id FOR UPDATE'
        : 'SELECT id, mission_name, wds_vlan FROM deploy_missions ORDER BY id'));
    $missionIds = [];
    $templateIds = [];
    foreach ($missions as $mission) {
        if ((string) ($mission['wds_vlan'] ?? '') !== $from) {
            continue;
        }
        $missionId = (int) $mission['id'];
        $missionIds[] = $missionId;
        if (mission_name_is_template((string) $mission['mission_name'])) {
            $templateIds[] = $missionId;
        }
    }

    // Read the candidate rows only after every mission row is locked. Network
    // writers take that same mission lock first, so this materialized scope is
    // stable while the active-job, VM and interface locks are acquired below.
    $vmRows = repo_fetch_all($db->query(
        'SELECT id, mission_id, vm_name FROM deploy_vms ORDER BY mission_id, id'
    ));
    $rows = repo_fetch_all($db->query(
        'SELECT i.id, i.vm_id, v.mission_id, v.vm_name, i.ip, i.subnet, i.gateway, i.dns1, i.dns2, i.vlan, i.mac, i.mode, i.type '
        . 'FROM deploy_interfaces i INNER JOIN deploy_vms v ON v.id = i.vm_id '
        . 'ORDER BY v.mission_id, i.vm_id, i.id'
    ));
    $missionSet = array_fill_keys($missionIds, true);
    $affectedVmIds = [];
    $vmMissions = [];
    $interfaceIds = [];
    $byVm = [];
    foreach ($vmRows as $vm) {
        $vmId = (int) $vm['id'];
        $missionId = (int) $vm['mission_id'];
        $vmMissions[$vmId] = $missionId;
        if (isset($missionSet[$missionId])) {
            $affectedVmIds[$vmId] = true;
        }
    }
    foreach ($rows as $row) {
        $vmId = (int) $row['vm_id'];
        $byVm[$vmId][] = $row;
        if ((string) $row['vlan'] === $from) {
            $interfaceIds[] = (int) $row['id'];
            $affectedVmIds[$vmId] = true;
        }
    }
    $vmIds = array_map('intval', array_keys($affectedVmIds));
    sort($vmIds, SORT_NUMERIC);
    sort($interfaceIds, SORT_NUMERIC);
    $affectedMissionIds = array_values(array_unique(array_merge($missionIds, array_map(
        static fn (int $vmId): int => (int) ($vmMissions[$vmId] ?? 0),
        $vmIds
    ))));
    $affectedMissionIds = array_values(array_filter($affectedMissionIds, static fn (int $id): bool => $id > 0));
    sort($affectedMissionIds, SORT_NUMERIC);

    $bundleFingerprints = [];
    foreach ($vmIds as $vmId) {
        $bundleFingerprints[(string) $vmId] = vm_network_bundle_fingerprint($byVm[$vmId] ?? []);
    }

    $activeJobs = [];
    if ($affectedMissionIds !== []) {
        $activeStatuses = [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, VIRTUSPHERE_DEPLOY_STATUS_CANCELLING];
        $missionPlaceholders = implode(',', array_fill(0, count($affectedMissionIds), '?'));
        $statusPlaceholders = implode(',', array_fill(0, count($activeStatuses), '?'));
        $stmt = $db->prepare($lock
            ? 'SELECT id, mission_id, status, payload_json FROM deploy_jobs WHERE mission_id IN (' . $missionPlaceholders . ') '
                . 'AND status IN (' . $statusPlaceholders . ') AND cancelled_at IS NULL ORDER BY id FOR UPDATE'
            : 'SELECT id, mission_id, status, payload_json FROM deploy_jobs WHERE mission_id IN (' . $missionPlaceholders . ') '
                . 'AND status IN (' . $statusPlaceholders . ') AND cancelled_at IS NULL ORDER BY id');
        $params = array_merge($affectedMissionIds, $activeStatuses);
        $stmt->bind_param(str_repeat('i', count($affectedMissionIds)) . str_repeat('s', count($activeStatuses)), ...$params);
        $stmt->execute();
        foreach (repo_fetch_all($stmt->get_result()) as $job) {
            $jobMissionId = (int) $job['mission_id'];
            $missionWide = in_array($jobMissionId, $missionIds, true);
            $missionVmIds = array_values(array_filter(
                $vmIds,
                static fn (int $vmId): bool => (int) ($vmMissions[$vmId] ?? 0) === $jobMissionId
            ));
            $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
            $jobVmIds = is_array($payload) && is_array($payload['vm_ids'] ?? null)
                ? array_values(array_unique(array_map('intval', $payload['vm_ids'])))
                : [];
            if ($missionWide || $jobVmIds === [] || array_intersect($jobVmIds, $missionVmIds) !== []) {
                $activeJobs[] = ['id' => (int) $job['id'], 'status' => (string) $job['status']];
            }
        }
    }

    if ($lock && $vmIds !== []) {
        $placeholders = implode(',', array_fill(0, count($vmIds), '?'));
        $stmt = $db->prepare('SELECT id FROM deploy_vms WHERE id IN (' . $placeholders . ') ORDER BY id FOR UPDATE');
        $stmt->bind_param(str_repeat('i', count($vmIds)), ...$vmIds);
        $stmt->execute();
        repo_fetch_all($stmt->get_result());

        $stmt = $db->prepare('SELECT id FROM deploy_interfaces WHERE vm_id IN (' . $placeholders . ') ORDER BY vm_id, id FOR UPDATE');
        $stmt->bind_param(str_repeat('i', count($vmIds)), ...$vmIds);
        $stmt->execute();
        repo_fetch_all($stmt->get_result());
    }

    $canonical = [
        'version' => 1,
        'source' => $from,
        'target' => $to,
        'target_rows' => $targetRows,
        'missions' => $missionIds,
        'templates' => $templateIds,
        'affected_missions' => $affectedMissionIds,
        'vms' => $vmIds,
        'vm_missions' => $vmMissions,
        'interfaces' => $interfaceIds,
        'bundles' => $bundleFingerprints,
        'active_jobs' => $activeJobs,
    ];
    $encoded = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return $canonical + [
        'fingerprint' => hash('sha256', 'virtusphere.vlan-reassign-scope.v1' . "\0" . $encoded),
        'target_active' => count($targetRows) === 1,
        'counts' => [
            'missions' => count($missionIds),
            'templates' => count($templateIds),
            'vms' => count($vmIds),
            'interfaces' => count($interfaceIds),
            'active_jobs' => count($activeJobs),
        ],
    ];
}

/** Read-only preview for the confirmation form. */
function repo_vlan_reassign_preview(mysqli $db, string $from, string $to): array
{
    return repo_vlan_reassign_scope($db, $from, $to);
}

/**
 * Guided exact-name mass reassignment. When an expected fingerprint is given,
 * any change since the preview aborts before a domain write.
 *
 * @return array{missions:int, interfaces:int}
 */
function repo_reassign_vlan(mysqli $db, string $from, string $to, ?string $expectedFingerprint = null): array
{
    return repo_transaction($db, static function () use ($db, $from, $to, $expectedFingerprint): array {
        $scope = repo_vlan_reassign_scope($db, $from, $to, true);
        if (!$scope['target_active']) {
            throw new VlanReassignTargetInactiveException('The target VLAN is no longer active.');
        }
        if ($expectedFingerprint !== null
            && (preg_match('/^[a-f0-9]{64}$/', $expectedFingerprint) !== 1
                || !hash_equals($scope['fingerprint'], $expectedFingerprint))
        ) {
            throw new VlanReassignScopeChangedException('The VLAN reassignment scope changed after preview.');
        }

        if ($scope['active_jobs'] !== []) {
            throw new VmNetworkScopeActiveException((int) $scope['active_jobs'][0]['id']);
        }

        $rows = repo_fetch_all($db->query(
            'SELECT i.id, i.vm_id, v.mission_id, v.vm_name, i.ip, i.subnet, i.gateway, i.dns1, i.dns2, i.vlan, i.mac, i.mode, i.type '
            . 'FROM deploy_interfaces i INNER JOIN deploy_vms v ON v.id = i.vm_id ORDER BY v.mission_id, i.vm_id, i.id FOR UPDATE'
        ));
        $byVm = [];
        foreach ($rows as $row) {
            $byVm[(int) $row['vm_id']][] = $row;
        }
        $issues = [];
        foreach ($scope['vms'] as $vmId) {
            $proposed = [];
            $missionId = 0;
            $vmName = '';
            foreach ($byVm[(int) $vmId] ?? [] as $row) {
                $missionId = (int) $row['mission_id'];
                $vmName = (string) $row['vm_name'];
                if ((string) $row['vlan'] === $from) {
                    $row['vlan'] = $to;
                }
                $proposed[] = $row;
            }
            if ($proposed !== []) {
                array_push($issues, ...vm_network_issues_for_interfaces($proposed, $missionId, (int) $vmId, $vmName));
            }
        }
        if ($issues !== []) {
            vm_network_sort_issues($issues);
            throw new VmNetworkPreflightException($issues);
        }

        $updateMission = $db->prepare('UPDATE deploy_missions SET wds_vlan = ? WHERE id = ?');
        foreach ($scope['missions'] as $missionId) {
            $updateMission->bind_param('si', $to, $missionId);
            $updateMission->execute();
        }
        repo_vm_network_update_vlan_ids($db, $scope['interfaces'], $to);

        return [
            'missions' => $scope['counts']['missions'],
            'interfaces' => $scope['counts']['interfaces'],
        ];
    });
}
