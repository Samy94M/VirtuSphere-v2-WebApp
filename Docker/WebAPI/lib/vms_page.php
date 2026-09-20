<?php

declare(strict_types=1);

/** @param array<string, mixed> $vm */
function vms_page_location_override(array $vm): string
{
    $parts = array_filter([
        trim((string) ($vm['vm_datastore'] ?? '')),
        trim((string) ($vm['vm_datacenter'] ?? '')),
    ], static fn (string $value): bool => $value !== '');

    return implode(' / ', $parts);
}

/**
 * Stream the already sorted VM list as CSV when the page requests it.
 *
 * @param array<string, mixed> $user
 * @param array<string, mixed> $mission
 * @param list<array<string, mixed>> $rows
 * @param array<int, list<array<string, mixed>>> $networkIssuesByVm
 */
function vms_export_csv_if_requested(
    mysqli $connection,
    array $user,
    int $missionId,
    array $mission,
    array $rows,
    array $networkIssuesByVm
): void {
    if (($_GET['export'] ?? '') !== 'csv') {
        return;
    }

    // Override columns are stable in the export even when every value is empty.
    $header = [
        __t('vms.th_vm_name'), __t('vms.th_hostname'), __t('vms.th_os'), __t('vms.th_cpu'), __t('vms.csv_ram'),
        __t('common.status'), __t('vms.th_datastore_override'), __t('vms.th_datacenter_override'),
        __t('vms.th_mecm'), __t('vms.th_interfaces'), __t('vms.th_disks'), __t('vms.th_packages'),
        __t('vms.csv_network_status'), __t('vms.csv_network_detail'),
    ];
    $csvRows = [];
    foreach ($rows as $vm) {
        $networkIssues = $networkIssuesByVm[(int) $vm['id']] ?? [];
        $networkDetail = implode(';', array_map(static fn (array $issue): string => (string) $issue['code'] . ((string) $issue['vlan'] !== '' ? ':' . (string) $issue['vlan'] : ''), $networkIssues));
        $csvRows[] = [
            (string) ($vm['vm_name'] ?? ''),
            (string) ($vm['vm_hostname'] ?? ''),
            (string) ($vm['vm_os'] ?? ''),
            (string) ($vm['vm_cpu'] ?? ''),
            (string) ($vm['vm_ram'] ?? ''),
            (string) ($vm['vm_status'] ?? ''),
            (string) ($vm['vm_datastore'] ?? ''),
            (string) ($vm['vm_datacenter'] ?? ''),
            (string) ($vm['mecm_sync_state'] ?? ''),
            (string) count($vm['interfaces'] ?? []),
            (string) count($vm['disks'] ?? []),
            (string) count($vm['packages'] ?? []),
            $networkIssues === [] ? 'valid' : 'invalid',
            $networkDetail,
        ];
    }
    audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_VM_LIST_EXPORTED, 'mission', $missionId, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
        'row_count' => count($csvRows),
    ], (int) $user['id']);
    portal_send_csv('vms-' . (string) $mission['mission_name'], $header, $csvRows);
}
