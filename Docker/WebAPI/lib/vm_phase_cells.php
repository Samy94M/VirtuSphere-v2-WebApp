<?php

declare(strict_types=1);

/**
 * The phase and finding cells of the VM list (portal/vms.php) and of the
 * dashboard VM overview (lib/vm_overview_page.php). Both tables show the same
 * derived phase and the same findings, so the cells live here and cannot drift
 * apart. Words and badges come from lib/portal_status_display.php and
 * lib/layout_presenters.php; a finding's closed link target (lib/vm_phase.php)
 * becomes an href here, with the work context of the page (A40).
 */

require_once __DIR__ . '/layout_presenters.php';
require_once __DIR__ . '/vm_phase.php';
require_once __DIR__ . '/vm_urls.php';
require_once __DIR__ . '/deploy_urls.php';
require_once __DIR__ . '/deploy_log_filter.php';
require_once __DIR__ . '/system_status_urls.php';
require_once __DIR__ . '/package_report_page.php';

/**
 * The phase badge. The raw stage value (x/5) is the tooltip, so the operator
 * still finds the value the MECM interface reads. A template has no stage.
 *
 * @param array<string,mixed> $vm
 */
function vm_phase_cell(array $vm, string $phase): string
{
    $badge = vm_phase_badge($phase);
    if ($phase === VIRTUSPHERE_VM_PHASE_TEMPLATE) {
        return $badge;
    }

    return '<span title="' . h(__t('status.phase_technical', ['status' => (string) ($vm['vm_status'] ?? '')])) . '">'
        . $badge . '</span>';
}

/**
 * The page a finding links to, resolved from its closed target. An unknown
 * target lands in the VM editor, which lists every finding of the VM.
 *
 * @param array<string,mixed> $finding one entry of virtusphere_vm_findings()
 * @param array<string,mixed> $workContext
 */
function vm_finding_href(array $finding, int $missionId, int $vmId, array $workContext): string
{
    $link = (array) ($finding['link'] ?? []);

    return match ((string) ($link['target'] ?? '')) {
        // E1: a failed create unit opens that VM's FAIL lines, not the log top.
        VIRTUSPHERE_VM_FINDING_LINK_JOB_LOG => (int) ($link['create_position'] ?? 0) > 0
            ? deploy_log_create_unit_url((int) ($link['job_id'] ?? 0), [
                'position' => (int) $link['create_position'],
                'total' => (int) ($link['create_total'] ?? 0),
                'severity' => VIRTUSPHERE_DEPLOY_LOG_SEVERITY_FAIL,
            ])
            : deploy_job_log_url((int) ($link['job_id'] ?? 0)),
        VIRTUSPHERE_VM_FINDING_LINK_DEPLOY => deploy_mission_url($missionId),
        VIRTUSPHERE_VM_FINDING_LINK_SYSTEM_STATUS_MECM => system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM),
        VIRTUSPHERE_VM_FINDING_LINK_SYSTEM_STATUS_ESXI => system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI),
        VIRTUSPHERE_VM_FINDING_LINK_PACKAGE_RUN => package_report_detail_url(
            $missionId,
            $vmId,
            (string) ($link['run_id'] ?? ''),
            $workContext
        ),
        default => vm_edit_url($missionId, $vmId, null, $workContext),
    };
}

/**
 * The findings cell: the most severe finding as a link with its badge, and "+n"
 * for the rest, which leads to the VM editor where all of them are listed.
 *
 * @param list<array<string,mixed>> $findings in severity order
 * @param array<string,mixed> $workContext
 */
function vm_findings_cell(array $findings, int $missionId, int $vmId, array $workContext): string
{
    if ($findings === []) {
        return '&mdash;';
    }

    $first = $findings[0];
    $html = '<a href="' . h(vm_finding_href($first, $missionId, $vmId, $workContext))
        . '" title="' . h(portal_vm_finding_text($first)) . '">'
        . vm_finding_badge($first) . '</a>';

    $more = count($findings) - 1;
    if ($more > 0) {
        $html .= ' <a href="' . h(vm_edit_url($missionId, $vmId, null, $workContext))
            . '" title="' . h(__t('status.finding_more_title', ['count' => $more])) . '">'
            . h(__t('status.finding_more', ['count' => $more])) . '</a>';
    }

    return $html;
}
