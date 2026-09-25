<?php

declare(strict_types=1);

/**
 * Stream the already sorted mission list as CSV when the page requests it.
 *
 * @param array<string, mixed> $user
 * @param list<array<string, mixed>> $rows
 */
function missions_export_csv_if_requested(mysqli $connection, array $user, array $rows, bool $isTemplateView): void
{
    if (($_GET['export'] ?? '') !== 'csv') {
        return;
    }

    // Location fields intentionally live in the export only: the rendered list
    // stays compact while the download answers the collective placement query.
    $header = [
        __t('common.name'), __t('common.status'), __t('missions.th_datastore'),
        __t('missions.th_datacenter'), __t('common.vms'), __t('missions.th_attention'), __t('common.updated'),
    ];
    $csvRows = [];
    foreach ($rows as $mission) {
        $csvRows[] = [
            (string) ($mission['mission_name'] ?? ''),
            (string) ($mission['mission_status'] ?? ''),
            (string) ($mission['hypervisor_datastorage'] ?? ''),
            (string) ($mission['hypervisor_datacenter'] ?? ''),
            (string) ($mission['vm_count'] ?? 0),
            (string) ($mission['progress_attention_count'] ?? 0),
            portal_format_timestamp($mission['updated_at'] ?? ''),
        ];
    }
    audit_event($connection, VIRTUSPHERE_AUDIT_EVENT_MISSION_LIST_EXPORTED, 'mission_list', $isTemplateView ? 'templates' : 'missions', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, [
        'row_count' => count($csvRows),
    ], (int) $user['id']);
    portal_send_csv($isTemplateView ? 'vorlagen' : 'missionen', $header, $csvRows);
}
