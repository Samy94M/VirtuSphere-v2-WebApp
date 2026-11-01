<?php

declare(strict_types=1);

require_once __DIR__ . '/vm_phase.php';
require_once __DIR__ . '/portal_work_context.php';

/**
 * The per-mission VM counts of the mission list: the overdue observations of
 * the "check due" column and, for real missions, the phase distribution of the
 * "phases" column (SP4 R1). A template view reads neither: its VMs have no
 * progress and no phase.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function missions_with_vm_counts(mysqli $connection, array $rows, bool $isTemplateView): array
{
    $attentionCounts = $isTemplateView ? [] : repo_vm_progress_attention_counts_by_mission($connection);
    $phaseCounts = $isTemplateView ? [] : repo_vm_phase_counts_by_mission($connection);
    foreach ($rows as &$missionRow) {
        $missionRow['progress_attention_count'] = $attentionCounts[(int) $missionRow['id']] ?? 0;
        $missionRow['phase_counts'] = $phaseCounts[(int) $missionRow['id']] ?? [];
    }
    unset($missionRow);

    return $rows;
}

/**
 * The phase cell of one mission: one badge per reached phase in chain order,
 * coloured and named by the phase owners, with its VM count. All badges sit in
 * one link, because every phase would open the same page: the mission's VM
 * list sorted by phase, the VMs that lag behind first. No VM, no badge.
 *
 * @param array<string, mixed> $mission
 * @param array<string, mixed> $workContext
 */
function missions_phase_cell(array $mission, array $workContext): string
{
    $counts = (array) ($mission['phase_counts'] ?? []);
    if ($counts === []) {
        return '&mdash;';
    }
    $badges = [];
    foreach ($counts as $phase => $count) {
        $badges[] = portal_badge(
            (string) virtusphere_vm_phase_meta((string) $phase)['badge'],
            __t('missions.phase_count', ['phase' => portal_vm_phase_label((string) $phase), 'count' => (int) $count])
        );
    }
    $href = portal_work_context_vm_list_url((int) $mission['id'], portal_work_context_with_vm_list($workContext, 'status', 'asc'));

    return '<a class="mission-phases" href="' . h($href) . '" title="' . h(__t('missions.phases_link_title')) . '">'
        . implode(' ', $badges) . '</a>';
}

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

/**
 * The empty-list sentence of the mission and template table. The create form
 * sits above the table, so the sentence links to it, but only to a user who can
 * submit it: a reader without missions.write still learns that nothing exists.
 */
function missions_empty_list_html(bool $attentionOnly, bool $templateView, bool $canCreate): string
{
    if ($attentionOnly) {
        return h(__t('missions.attention_empty'));
    }
    $sentence = h(__t($templateView ? 'missions.empty_template' : 'missions.empty_mission'));
    if (!$canCreate) {
        return $sentence;
    }

    return $sentence . ' <a href="#mission-create">' . h(__t('missions.empty_create_link')) . '</a>';
}
