<?php

declare(strict_types=1);

/**
 * VM-editor status (plan ST-2, section 4): the derived phase with its findings,
 * the client phases, the technical block with the stored values and the timeline.
 * Read-only. The phase, findings, inventory reading and timeline come from their
 * pure owners (lib/vm_phase.php, lib/vm_inventory_observation.php,
 * lib/vm_timeline.php); nothing here decides or writes a state.
 */

require_once __DIR__ . '/vm_phase.php';
require_once __DIR__ . '/vm_phase_cells.php';
require_once __DIR__ . '/vm_timeline.php';
require_once __DIR__ . '/vm_inventory_observation.php';
require_once __DIR__ . '/deploy_display.php';
require_once __DIR__ . '/deploy_mode_labels.php';
require_once __DIR__ . '/layout_presenters.php';

function vm_guest_os_options_for_value(string $guestId): array
{
    $options = VIRTUSPHERE_GUEST_OS_OPTIONS;
    if ($guestId !== '' && !in_array($guestId, virtusphere_guest_os_ids(), true)) {
        $options[] = ['guest_id' => $guestId, 'legacy' => true];
    }

    return $options;
}

function vm_guest_os_option_label(array $option): string
{
    $guestId = (string) ($option['guest_id'] ?? '');
    if (!empty($option['legacy'])) {
        return __t('portal.vm_guest_os_legacy', ['guest_id' => $guestId]);
    }

    return __t((string) ($option['label_key'] ?? 'portal.vm_guest_os_unknown')) . ' (' . $guestId . ')';
}

/**
 * Read-only status panel: the derived phase and its findings, the client phase
 * track and the technical block with the stored values. The caller owns the
 * visibility guard (a real VM, not a template), matching how the row renderers
 * leave permission checks to the page.
 *
 * @param array<string, mixed> $vm
 * @param list<array<string, mixed>> $findings in severity order
 * @param array<string, array<string, mixed>|null> $clientPhaseSummary latest event per phase
 * @param array<string, mixed>|null $inventory the bound access's inventory, or null
 * @param array<string, mixed> $workContext
 */
function vm_edit_render_status_panel(array $vm, string $phase, array $findings, array $clientPhaseSummary, ?array $inventory, int $missionId, array $workContext): void
{
    $vmId = (int) ($vm['id'] ?? 0);
    $notSet = '<span class="muted">' . h(__t('vm_edit.technical_none')) . '</span>';
    $text = static fn (mixed $raw): string => trim((string) $raw) === '' ? $notSet : h((string) $raw);
    $code = static fn (mixed $raw): string => trim((string) $raw) === '' ? $notSet : '<code>' . h((string) $raw) . '</code>';
    $item = static fn (string $labelKey, string $valueHtml): string => '<div class="diagnostics-item"><dt>' . h(__t($labelKey)) . '</dt><dd>' . $valueHtml . '</dd></div>';
    ?>
        <section class="panel">
            <div class="vm-status-head">
                <h2><?php echo h(__t('vm_edit.heading_status')); ?></h2>
                <?php echo vm_phase_badge($phase); ?>
            </div>
            <h3><?php echo h(__t('vm_edit.findings_heading')); ?></h3>
            <?php if ($findings === []) { ?>
                <p class="muted"><?php echo h(__t('vm_edit.findings_none')); ?></p>
            <?php } else { ?>
                <ul class="vm-finding-list">
                    <?php foreach ($findings as $finding) { ?>
                        <li><a href="<?php echo h(vm_edit_finding_href($finding, $missionId, $vmId, $workContext)); ?>"><?php echo vm_finding_badge($finding); ?></a></li>
                    <?php } ?>
                </ul>
            <?php } ?>
            <h3 id="vm-client-phases"><?php echo h(__t('vm_edit.heading_client_phases')); ?></h3>
            <ol class="phase-track">
                <?php foreach (VIRTUSPHERE_CLIENT_PHASES as $index => $clientPhase) {
                    $latestEvent = $clientPhaseSummary[$clientPhase] ?? null;
                    $phaseState = virtusphere_client_phase_state($latestEvent);
                    ?>
                    <li class="phase-step phase-step-<?php echo h($phaseState); ?>">
                        <span class="phase-step-index"><?php echo h((string) ($index + 1)); ?></span>
                        <span class="phase-step-body">
                            <span class="phase-step-name"><?php echo h(client_phase_label($clientPhase)); ?></span>
                            <span class="phase-step-state">
                                <?php echo client_phase_badge($phaseState); ?>
                                <?php if ($latestEvent !== null) { ?>
                                    <span class="muted"><?php echo h(portal_format_timestamp((string) $latestEvent['created_at'])); ?></span>
                                <?php } ?>
                            </span>
                        </span>
                    </li>
                <?php } ?>
            </ol>
            <h3 id="vm-technical"><?php echo h(__t('vm_edit.technical_heading')); ?></h3>
            <dl class="diagnostics">
                <?php
                echo $item('vm_edit.technical_stage', status_badge((string) ($vm['vm_status'] ?? VIRTUSPHERE_STATUS_REGISTERED)));
                echo $item('vm_edit.diagnostics_lifecycle', lifecycle_badge((string) ($vm['lifecycle_state'] ?? '')));
                echo $item('vm_edit.diagnostics_mecm', mecm_sync_badge((string) ($vm['mecm_sync_state'] ?? '')));
                echo $item('vm_edit.diagnostics_updated', h(mecm_updated_display($vm['updated'] ?? 0)));
                echo $item('vm_edit.technical_resource_id', $code($vm['mecm_id'] ?? null));
                echo $item('vm_edit.technical_tombstone', $code($vm['mecm_previous_id'] ?? null));
                echo $item('vm_edit.technical_rollout_revision', $text($vm['mecm_rollout_revision'] ?? null));
                echo $item('vm_edit.technical_instance_uuid', $code($vm['vm_instance_uuid'] ?? null));
                echo $item('vm_edit.technical_moid', $code($vm['vm_moid'] ?? null));
                echo $item('vm_edit.technical_host', $text($vm['vm_bound_esxi_host'] ?? null));
                echo $item('vm_edit.technical_inventory', h(vm_edit_inventory_text(virtusphere_vm_inventory_observation($vm, $inventory))));
                ?>
            </dl>
        </section>
    <?php
}

/**
 * The link of one finding in the VM editor. A finding whose target is the editor
 * lands on the section that explains it on this page; every other target resolves
 * exactly as in the VM list (lib/vm_phase_cells.php).
 *
 * @param array<string, mixed> $finding one entry of virtusphere_vm_findings()
 * @param array<string, mixed> $workContext
 */
function vm_edit_finding_href(array $finding, int $missionId, int $vmId, array $workContext): string
{
    $link = (array) ($finding['link'] ?? []);
    if ((string) ($link['target'] ?? '') !== VIRTUSPHERE_VM_FINDING_LINK_VM_EDITOR) {
        return vm_finding_href($finding, $missionId, $vmId, $workContext);
    }

    return match ((string) ($finding['kind'] ?? '')) {
        VIRTUSPHERE_VM_FINDING_CLIENT_PHASE_FAILED, VIRTUSPHERE_VM_FINDING_CLIENT_PHASE_UNCONFIRMED => '#vm-client-phases',
        VIRTUSPHERE_VM_FINDING_PXE_UNWATCHED, VIRTUSPHERE_VM_FINDING_OS_INSTALL_WATCHING, VIRTUSPHERE_VM_FINDING_OS_INSTALL_OVERDUE => '#vm-progress-watch',
        default => '#vm-technical',
    };
}

/**
 * The inventory line of the technical block: the state of the reading and, where
 * it counts as evidence, the time of that inventory. Exhaustive over the closed
 * states of lib/vm_inventory_observation.php; an unknown state gets the neutral
 * sentence, never its token.
 *
 * @param array{state:string, power:?string, time:string} $observation
 */
function vm_edit_inventory_text(array $observation): string
{
    $time = $observation['time'];
    $at = $time === '' ? '' : portal_format_timestamp($time);

    return match ((string) $observation['state']) {
        VIRTUSPHERE_VM_INVENTORY_UNBOUND => __t('vm_edit.inventory_unbound'),
        VIRTUSPHERE_VM_INVENTORY_NONE => __t('vm_edit.inventory_none'),
        VIRTUSPHERE_VM_INVENTORY_UNANSWERED => __t('vm_edit.inventory_unanswered'),
        VIRTUSPHERE_VM_INVENTORY_STALE => $at === ''
            ? __t('vm_edit.inventory_stale_no_time')
            : __t('vm_edit.inventory_stale', ['time' => $at]),
        VIRTUSPHERE_VM_INVENTORY_MISSING => __t('vm_edit.inventory_missing', ['time' => $at]),
        VIRTUSPHERE_VM_INVENTORY_PRESENT => match ((string) ($observation['power'] ?? '')) {
            'poweredOn' => __t('vm_edit.inventory_powered_on', ['time' => $at]),
            'poweredOff' => __t('vm_edit.inventory_powered_off', ['time' => $at]),
            'suspended' => __t('vm_edit.inventory_suspended', ['time' => $at]),
            default => __t('vm_edit.inventory_power_unknown', ['time' => $at]),
        },
        default => __t('vm_edit.inventory_unknown'),
    };
}

/**
 * The VM timeline (plan ST-2, section 4.4): status events, deploy jobs, package runs
 * and client events in one table, open without a click and hidden without entries.
 * The caller owns the visibility guard like the panel above.
 *
 * @param array{entries:list<array<string,mixed>>,truncated:bool} $timeline from virtusphere_vm_timeline()
 * @param array<string, mixed> $workContext
 */
function render_vm_status_history(array $timeline, int $missionId, int $vmId, array $workContext): void
{
    if ($timeline['entries'] === []) {
        return;
    }
    $link = static fn (string $url, string $text): string => '<a href="' . h($url) . '">' . h($text) . '</a>';
    $clientEvent = static fn (string $event): string => match ($event) {
        VIRTUSPHERE_CLIENT_EVENT_STARTED => __t('vm_timeline.client_started'),
        VIRTUSPHERE_CLIENT_EVENT_FINISHED => __t('vm_timeline.client_finished'),
        VIRTUSPHERE_CLIENT_EVENT_FAILED => __t('vm_timeline.client_failed'),
        default => __t('vm_timeline.client_unknown'),
    };
    $event = static function (array $entry) use ($missionId, $vmId, $workContext, $link, $clientEvent): string {
        $row = (array) $entry['row'];
        $jobId = (int) ($row['id'] ?? 0);
        $mode = (string) ($row['mode'] ?? '');
        $package = trim((string) ($row['project_name'] ?? '') . ' ' . (string) ($row['package_version'] ?? ''));
        $runUrl = static fn (): string => package_report_detail_url($missionId, $vmId, (string) ($row['run_id'] ?? ''), $workContext);
        $failed = (int) ($row['reported_failures'] ?? 0) > 0 || (string) ($row['wrapper_result'] ?? '') === 'failed';
        $detail = (string) ($row['detail'] ?? '');
        $note = (string) $entry['kind'] === VIRTUSPHERE_VM_TIMELINE_STATUS
            ? virtusphere_vm_status_note_text((string) ($row['note'] ?? ''), Lang::locale())
            : '';

        return match ((string) $entry['kind']) {
            VIRTUSPHERE_VM_TIMELINE_STATUS => lifecycle_badge((string) ($row['lifecycle_state'] ?? ''))
                . ' ' . mecm_sync_badge((string) ($row['mecm_sync_state'] ?? ''))
                . ($note === '' ? '' : ' <span>' . h($note) . '</span>'),
            VIRTUSPHERE_VM_TIMELINE_JOB_STARTED => $link(deploy_job_log_url($jobId), $mode === ''
                ? __t('vm_timeline.job_started_no_mode', ['id' => $jobId])
                : __t('vm_timeline.job_started', ['id' => $jobId, 'mode' => deploy_mode_label($mode)])),
            VIRTUSPHERE_VM_TIMELINE_JOB_FINISHED => $link(deploy_job_log_url($jobId), __t('vm_timeline.job_finished', ['id' => $jobId]))
                . ' ' . deploy_job_status_badge((string) ($row['status'] ?? '')),
            VIRTUSPHERE_VM_TIMELINE_PACKAGE_RECEIVED => $link($runUrl(), __t('vm_timeline.package_received', ['package' => $package])),
            VIRTUSPHERE_VM_TIMELINE_PACKAGE_COMPLETED => $link($runUrl(), __t('vm_timeline.package_completed', ['package' => $package]))
                . ' ' . ($failed
                    ? portal_badge('warning', __t('vm_timeline.package_failed'))
                    : portal_badge('success', __t('vm_timeline.package_ok'))),
            VIRTUSPHERE_VM_TIMELINE_CLIENT => h(client_phase_label((string) ($row['phase'] ?? '')) . ': ' . $clientEvent((string) ($row['event'] ?? '')))
                . ($detail === '' ? '' : ' <span class="muted">' . h($detail) . '</span>'),
            default => '',
        };
    };
    ?>
        <section class="panel" aria-labelledby="vm-timeline-heading">
            <h2 id="vm-timeline-heading"><?php echo h(__t('vm_timeline.heading')); ?></h2>
            <p class="muted"><?php echo h(__t('vm_timeline.hint', ['days' => VIRTUSPHERE_STATUS_EVENT_RETENTION_DAYS])); ?></p>
            <?php if ($timeline['truncated']) { ?>
                <p class="muted"><?php echo h(__t('vm_timeline.truncated', ['limit' => VIRTUSPHERE_VM_TIMELINE_LIMIT])); ?></p>
            <?php } ?>
            <div class="table-wrap" tabindex="0">
                <table>
                    <thead><tr><th><?php echo h(__t('vm_edit.th_time')); ?></th><th><?php echo h(__t('vm_timeline.th_source')); ?></th><th><?php echo h(__t('vm_edit.th_event')); ?></th><th><?php echo h(__t('vm_edit.status_history_actor')); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($timeline['entries'] as $entry) {
                        $at = (string) $entry['at'];
                        $row = (array) $entry['row'];
                        $actor = $entry['kind'] === VIRTUSPHERE_VM_TIMELINE_STATUS ? (string) ($row['actor_name'] ?? '') : '';
                        ?>
                        <tr>
                            <td class="nowrap"><?php echo h($at === '' ? '—' : portal_format_timestamp($at)); ?></td>
                            <td><?php echo h(__t('vm_timeline.source_' . $entry['source'])); ?></td>
                            <td><?php echo $event($entry); ?></td>
                            <td><?php echo h($actor !== '' ? $actor : '—'); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php
}
