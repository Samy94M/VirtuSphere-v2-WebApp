<?php

declare(strict_types=1);

/**
 * The two blocks of the VM editor that follow without a reload (VB2): "check
 * progress" and the status panel. The page (lib/vm_edit_page.php,
 * lib/vm_edit_panels.php) and the poll (lib/vm_status_live_views.php) read the
 * same view functions and render through vm_edit_live_regions(), the only caller
 * of the two panel renderers (VmStatusLiveContractTest). The editor form is never
 * part of a region (A41, A71).
 */

require_once __DIR__ . '/vm_progress.php';
require_once __DIR__ . '/vm_phase.php';
require_once __DIR__ . '/vm_edit_status.php';
require_once __DIR__ . '/vm_status_live.php';
require_once __DIR__ . '/portal_work_context.php';
require_once __DIR__ . '/system_status_urls.php';
require_once __DIR__ . '/repo/vm_evidence.php';
require_once __DIR__ . '/repo/client_events.php';

const VIRTUSPHERE_VM_EDIT_LIVE_REGION_PROGRESS = 'progress';
const VIRTUSPHERE_VM_EDIT_LIVE_REGION_STATUS = 'status';

/**
 * What the status panel shows of a stored, non-template VM: the derived phase,
 * its findings, the inventory reading and the latest client event per phase.
 *
 * @param array<string,mixed> $vm
 * @return array{phase:string,findings:list<array<string,mixed>>,inventory:array<string,mixed>|null,client_phase_summary:array<string,array<string,mixed>|null>}
 */
function vm_edit_status_view(mysqli $db, array $vm): array
{
    $evidence = repo_vm_evidence_for_vm(repo_vm_evidence($db, [$vm]), $vm, false);

    return [
        'phase' => virtusphere_vm_phase($vm, $evidence),
        'findings' => virtusphere_vm_findings($vm, $evidence),
        'inventory' => is_array($evidence['inventory'] ?? null) ? $evidence['inventory'] : null,
        'client_phase_summary' => repo_client_phase_summary($db, (int) ($vm['id'] ?? 0)),
    ];
}

/**
 * What "check progress" shows: the running observation, whether it is overdue,
 * since when, and who set the OS-install clock (ADR-0038 amendment 2), read from
 * the status history; '' when it no longer says so. Exhaustive over the closed set.
 *
 * @param array<string,mixed> $vm
 * @param list<array<string,mixed>> $statusEvents
 * @return array{kind:?string,attention:array{kind:string,since:string,age_seconds:int,threshold_seconds:int}|null,since:string,show:bool,source_text:string}
 */
function vm_edit_progress_view(array $vm, array $statusEvents): array
{
    $kind = virtusphere_vm_progress_watch_kind($vm);
    $attention = virtusphere_vm_progress_attention($vm);
    $since = $kind === VIRTUSPHERE_VM_PROGRESS_MECM_PENDING
        ? (string) ($vm['mecm_pending_since'] ?? '')
        : (string) ($vm['os_install_watch_started_at'] ?? '');

    return [
        'kind' => $kind,
        'attention' => $attention,
        'since' => $since,
        'show' => $attention !== null || $kind === VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING,
        'source_text' => match ($kind === VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING
            ? virtusphere_vm_install_watch_source($statusEvents, $since)
            : null) {
            VIRTUSPHERE_VM_POWER_EVIDENCE_PORTAL_START => __t('vm_edit.progress_source_portal_start'),
            VIRTUSPHERE_VM_POWER_EVIDENCE_INVENTORY => __t('vm_edit.progress_source_inventory'),
            VIRTUSPHERE_VM_WATCH_SOURCE_OPERATOR => __t('vm_edit.progress_source_operator'),
            null => '',
        },
    ];
}

/**
 * The "check progress" block. Permissions arrive as decided values and the CSRF
 * field as markup, because the poll renders this after it released the session.
 *
 * @param array{kind:?string,attention:array<string,mixed>|null,since:string,show:bool,source_text:string} $view
 * @param array<string,mixed> $workContext
 * @param array{can_write:bool,can_system_config:bool} $access
 */
function vm_edit_render_progress_panel(array $view, int $missionId, int $vmId, array $workContext, array $access, string $csrfFieldHtml): void
{
    $kind = $view['kind'];
    $since = $view['since'];
    $overdue = $view['attention'] !== null;
    $unwatched = $kind === VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING && $since === '';
    ?>
        <section class="panel stack" id="vm-progress-watch" aria-labelledby="vm-progress-watch-heading" data-vm-live-region="<?php echo h(VIRTUSPHERE_VM_EDIT_LIVE_REGION_PROGRESS); ?>">
            <div class="actions">
                <h2 id="vm-progress-watch-heading"><?php echo h(__t('vm_edit.progress_heading')); ?></h2>
                <?php echo portal_badge($overdue ? 'warning' : 'info', $overdue
                    ? __t('vm_edit.progress_overdue_badge')
                    : __t('vm_edit.progress_observation_badge')); ?>
            </div>
            <?php if ($kind === VIRTUSPHERE_VM_PROGRESS_MECM_PENDING) { ?>
                <p><?php echo h(__t('vm_edit.progress_pending_overdue', [
                    'since' => portal_format_timestamp($since),
                    'hours' => intdiv(VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS, 3600),
                ])); ?><?php if ($access['can_system_config']) { ?> <a href="<?php echo h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM)); ?>"><?php echo h(__t('layout.nav_system_status')); ?></a><?php } ?></p>
            <?php } elseif ($since === '') { ?>
                <p><?php echo h(__t('vm_edit.progress_install_unwatched')); ?></p>
            <?php } elseif ($overdue) { ?>
                <p><?php echo h(__t('vm_edit.progress_install_overdue', [
                    'since' => portal_format_timestamp($since),
                    'hours' => intdiv(VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS, 3600),
                ])); ?></p>
            <?php } else { ?>
                <p><?php echo h(__t('vm_edit.progress_install_watched', [
                    'since' => portal_format_timestamp($since),
                    'hours' => intdiv(VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS, 3600),
                ])); ?></p>
            <?php } ?>
            <?php if ($view['source_text'] !== '') { ?><p data-progress-source><?php echo h($view['source_text']); ?></p><?php } ?>
            <p class="muted"><?php echo h(__t('vm_edit.progress_no_auto_failure')); ?></p>
            <?php if ($access['can_write']) { ?>
                <form class="inline-form" method="post" action="<?php echo h(portal_work_context_vm_editor_action_url($missionId, $workContext)); ?>">
                    <?php echo $csrfFieldHtml; ?>
                    <input type="hidden" name="action" value="restart_progress_watch">
                    <input type="hidden" name="vm_id" value="<?php echo h((string) $vmId); ?>">
                    <input type="hidden" name="return_to" value="editor">
                    <button class="button button-secondary" type="submit" data-confirm="<?php echo h(__t($unwatched
                        ? 'vm_edit.progress_confirm_start'
                        : 'vm_edit.progress_confirm_restart')); ?>"><?php echo h(__t($unwatched
                        ? 'vm_edit.progress_start_button'
                        : 'vm_edit.progress_restart_button')); ?></button>
                </form>
            <?php } ?>
        </section>
    <?php
}

/**
 * The CSRF field of a region the poll renders. The poll has released the session
 * and holds no token; the script copies the page's own token into this field.
 */
function vm_edit_live_csrf_placeholder(): string
{
    return '<input type="hidden" name="_csrf" value="" data-vm-live-csrf>';
}

/**
 * The editor's live regions in page order: "check progress" (absent while
 * nothing is observed) and the status panel. The page passes csrf_field(), the
 * poll vm_edit_live_csrf_placeholder(); the signature leaves the field out, so
 * both produce the same one.
 *
 * @param array<string,mixed> $vm a stored, non-template VM
 * @param array{phase:string,findings:list<array<string,mixed>>,inventory:array<string,mixed>|null,client_phase_summary:array<string,array<string,mixed>|null>} $statusView
 * @param array{kind:?string,attention:array<string,mixed>|null,since:string,show:bool,source_text:string} $progressView
 * @param array<string,mixed> $workContext
 * @param array{can_write:bool,can_system_config:bool} $access
 * @return list<array{key:string,sig:string,html:string}>
 */
function vm_edit_live_regions(array $vm, array $statusView, array $progressView, int $missionId, array $workContext, array $access, string $csrfFieldHtml): array
{
    $vmId = (int) ($vm['id'] ?? 0);
    $progress = '';
    if ($progressView['show']) {
        ob_start();
        vm_edit_render_progress_panel($progressView, $missionId, $vmId, $workContext, $access, $csrfFieldHtml);
        $progress = (string) ob_get_clean();
    }
    ob_start();
    vm_edit_render_status_panel($vm, $statusView['phase'], $statusView['findings'], $statusView['client_phase_summary'], $statusView['inventory'], $missionId, $workContext);
    $status = (string) ob_get_clean();

    return [
        vm_live_region(VIRTUSPHERE_VM_EDIT_LIVE_REGION_PROGRESS, $progress, str_replace($csrfFieldHtml, '', $progress)),
        vm_live_region(VIRTUSPHERE_VM_EDIT_LIVE_REGION_STATUS, $status),
    ];
}
