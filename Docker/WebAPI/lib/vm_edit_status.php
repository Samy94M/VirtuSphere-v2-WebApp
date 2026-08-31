<?php

declare(strict_types=1);

/** VM-editor guest-OS options and read-only lifecycle/progress diagnostics. */

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
 * Read-only status/diagnostics panel: legacy status badge, the lifecycle/MECM
 * diagnostics and the client deploy-phase track with its recent-events table.
 * The caller owns the visibility guard (a real VM, not a template), matching how
 * the row renderers leave permission checks to the page.
 *
 * @param array<string, mixed> $vm
 * @param array<string, array<string, mixed>|null> $clientPhaseSummary latest event per phase
 * @param array<int, array<string, mixed>> $clientEvents
 */
function vm_edit_render_status_panel(array $vm, array $clientPhaseSummary, array $clientEvents): void
{
    ?>
        <section class="panel">
            <div class="vm-status-head">
                <h2><?php echo h(__t('vm_edit.heading_status')); ?></h2>
                <?php echo status_badge((string) ($vm['vm_status'] ?? VIRTUSPHERE_STATUS_REGISTERED)); ?>
            </div>
            <dl class="diagnostics">
                <div class="diagnostics-item">
                    <dt><?php echo h(__t('vm_edit.diagnostics_lifecycle')); ?></dt>
                    <dd><?php echo lifecycle_badge((string) ($vm['lifecycle_state'] ?? '')); ?></dd>
                </div>
                <div class="diagnostics-item">
                    <dt><?php echo h(__t('vm_edit.diagnostics_mecm')); ?></dt>
                    <dd><?php echo mecm_sync_badge((string) ($vm['mecm_sync_state'] ?? '')); ?></dd>
                </div>
                <div class="diagnostics-item">
                    <dt><?php echo h(__t('vm_edit.diagnostics_updated')); ?></dt>
                    <dd><?php echo h(mecm_updated_display($vm['updated'] ?? 0)); ?></dd>
                </div>
            </dl>
            <h3><?php echo h(__t('vm_edit.heading_client_phases')); ?></h3>
            <ol class="phase-track">
                <?php foreach (VIRTUSPHERE_CLIENT_PHASES as $index => $phase) {
                    $latestEvent = $clientPhaseSummary[$phase] ?? null;
                    $phaseState = virtusphere_client_phase_state($latestEvent);
                    ?>
                    <li class="phase-step phase-step-<?php echo h($phaseState); ?>">
                        <span class="phase-step-index"><?php echo h((string) ($index + 1)); ?></span>
                        <span class="phase-step-body">
                            <span class="phase-step-name"><?php echo h(client_phase_label($phase)); ?></span>
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
            <?php if ($clientEvents !== []) { ?>
                <details>
                    <summary><?php echo h(__t('vm_edit.client_events_summary')); ?></summary>
                    <div class="table-wrap" tabindex="0">
                        <table>
                            <thead><tr><th><?php echo h(__t('vm_edit.th_time')); ?></th><th><?php echo h(__t('vm_edit.th_phase')); ?></th><th><?php echo h(__t('vm_edit.th_event')); ?></th><th><?php echo h(__t('vm_edit.th_detail')); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($clientEvents as $event) { ?>
                                <tr>
                                    <td><?php echo h(portal_format_timestamp((string) $event['created_at'])); ?></td>
                                    <td><?php echo h(client_phase_label((string) $event['phase'])); ?></td>
                                    <td><?php echo h((string) $event['event']); ?></td>
                                    <td><?php echo h((string) ($event['detail'] ?? '')); ?></td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php } else { ?>
                <p class="muted"><?php echo h(__t('vm_edit.client_events_empty')); ?></p>
            <?php } ?>
        </section>
    <?php
}

/**
 * The VM's transition history (B11 rest): every state write records a row and
 * until Etappe 8 no page ever read one back, so the trail existed only for the
 * database. Read-only, collapsed by default, hidden entirely without events (a
 * fresh VM has nothing to explain). The caller owns the visibility guard like
 * the panel above.
 *
 * @param array<int, array<string, mixed>> $events newest first (repo_vm_status_events)
 */
function render_vm_status_history(array $events): void
{
    if ($events === []) {
        return;
    }
    ?>
        <section class="panel">
            <details>
                <summary><?php echo h(__t('vm_edit.status_history_heading')); ?></summary>
                <p class="muted"><?php echo h(__t('vm_edit.status_history_hint', [
                    'limit' => VIRTUSPHERE_STATUS_EVENT_HISTORY_LIMIT,
                    'days' => VIRTUSPHERE_STATUS_EVENT_RETENTION_DAYS,
                ])); ?></p>
                <div class="table-wrap" tabindex="0">
                    <table>
                        <thead><tr><th><?php echo h(__t('vm_edit.th_time')); ?></th><th><?php echo h(__t('vm_edit.diagnostics_lifecycle')); ?></th><th><?php echo h(__t('vm_edit.diagnostics_mecm')); ?></th><th><?php echo h(__t('vm_edit.th_detail')); ?></th><th><?php echo h(__t('vm_edit.status_history_actor')); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($events as $event) { ?>
                            <tr>
                                <td class="nowrap"><?php echo h(portal_format_timestamp((string) $event['created_at'])); ?></td>
                                <td><?php echo lifecycle_badge((string) $event['lifecycle_state']); ?></td>
                                <td><?php echo mecm_sync_badge((string) $event['mecm_sync_state']); ?></td>
                                <td><?php echo h((string) ($event['note'] ?? '') !== '' ? (string) $event['note'] : '—'); ?></td>
                                <td><?php echo h((string) ($event['actor_name'] ?? '') !== '' ? (string) $event['actor_name'] : '—'); ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </section>
    <?php
}
