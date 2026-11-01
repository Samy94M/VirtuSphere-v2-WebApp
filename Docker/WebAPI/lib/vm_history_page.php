<?php

declare(strict_types=1);

/**
 * The view of the VM chronicle (SP3, F03). Read-only: the page reads through
 * repo_vm_history_page() after the permission decision, and this file only renders
 * its result. The filter form is a GET form; it never carries the cursor, so a
 * changed filter always opens a new snapshot.
 */

require_once __DIR__ . '/vm_history.php';
require_once __DIR__ . '/vm_edit_status.php';
require_once __DIR__ . '/audit_log_view.php';
require_once __DIR__ . '/forms.php';
require_once __DIR__ . '/portal_time.php';

const VIRTUSPHERE_VM_HISTORY_FILTER_FORM = 'vm_history_filter';

function vm_history_source_label(string $source): string
{
    return $source === VIRTUSPHERE_VM_HISTORY_SOURCE_AUDIT
        ? __t('vm_history.source_audit')
        : __t('vm_timeline.source_' . $source);
}

/**
 * @param array{sources:list<string>, from:string, to:string, errors:list<string>} $filter
 * @param list<string> $permitted every source this reader may select
 * @param array<string,mixed> $workContext
 */
function vm_history_render_filter(array $filter, array $permitted, int $missionId, int $vmId, array $workContext, bool $allSelected): void
{
    $form = VIRTUSPHERE_VM_HISTORY_FILTER_FORM;
    $dateError = static fn (string $field): string => in_array($field, $filter['errors'], true) ? __t('vm_history.error_date') : '';
    $date = static function (string $field, string $label) use ($form, $filter, $dateError): string {
        $error = $dateError($field);

        return '<label>' . h($label) . '<input type="date" name="' . $field . '" value="' . h($filter[$field]) . '"'
            . form_control_attrs($form, $field, null, false, $error) . '>' . form_error_html($form, $field, null, $error) . '</label>';
    };
    ?>
    <form class="stack" method="get" action="vm_history.php" data-vm-history-filter>
        <input type="hidden" name="mission_id" value="<?php echo $missionId; ?>">
        <input type="hidden" name="vm_id" value="<?php echo $vmId; ?>">
        <?php foreach (portal_work_context($workContext) as $key => $value) { ?>
            <input type="hidden" name="<?php echo h($key); ?>" value="<?php echo h($value); ?>">
        <?php } ?>
        <fieldset>
            <legend><?php echo h(__t('vm_history.filter_sources')); ?></legend>
            <p class="hint"><?php echo h(__t('vm_history.filter_sources_hint')); ?></p>
            <div class="checkbox-grid">
                <?php foreach ($permitted as $source) { ?>
                    <label class="checkbox-item"><input type="checkbox" name="sources[]" value="<?php echo h($source); ?>"<?php echo !$allSelected && in_array($source, $filter['sources'], true) ? ' checked' : ''; ?>> <?php echo h(vm_history_source_label($source)); ?></label>
                <?php } ?>
            </div>
        </fieldset>
        <div class="form-grid">
            <?php echo $date('from', __t('vm_history.filter_from')) . $date('to', __t('vm_history.filter_to')); ?>
        </div>
        <p class="hint"><?php echo h(__t('vm_history.filter_date_hint', ['tz' => portal_timezone()])); ?></p>
        <?php if (in_array('range', $filter['errors'], true)) { ?>
            <p class="alert alert-warning" role="status"><?php echo h(__t('vm_history.error_range')); ?></p>
        <?php } ?>
        <div class="actions">
            <button class="button" type="submit"><?php echo h(__t('vm_history.filter_apply')); ?></button>
            <a class="button button-secondary" href="<?php echo h(vm_history_url($missionId, $vmId, $workContext)); ?>"><?php echo h(__t('vm_history.filter_reset')); ?></a>
        </div>
    </form>
    <?php
}

/**
 * The event cell of a chronicle entry: the editor's wording for the four timeline
 * sources, the localized audit description for the change log.
 *
 * @param array{kind:string,row:array<string,mixed>} $entry
 * @param array<string,mixed> $workContext
 */
function vm_history_event_html(array $entry, int $missionId, int $vmId, array $workContext, bool $canOpenJobLog): string
{
    if ($entry['kind'] !== VIRTUSPHERE_VM_HISTORY_AUDIT) {
        return vm_timeline_event_html($entry, $missionId, $vmId, $workContext, $canOpenJobLog);
    }

    return h(audit_log_display((array) $entry['row'])['message']);
}

/**
 * @param array{entries:list<array<string,mixed>>, more:bool, next:?string, failed:list<string>} $page
 * @param array<string,mixed> $workContext
 * @param array{sources:list<string>, from:string, to:string} $linkFilter the filter as the links carry it
 */
function vm_history_render_entries(array $page, int $missionId, int $vmId, array $workContext, array $linkFilter, bool $continued, bool $canOpenJobLog): void
{
    foreach ($page['failed'] as $source) { ?>
        <p class="alert alert-warning" role="status"><?php echo h(__t('vm_history.source_failed', ['source' => vm_history_source_label($source)])); ?></p>
    <?php }
    if ($continued) { ?>
        <p class="hint"><?php echo h(__t('vm_history.snapshot_hint')); ?></p>
    <?php } ?>
    <div class="table-wrap" tabindex="0">
        <table data-vm-history-table>
            <thead><tr><th><?php echo h(__t('vm_edit.th_time')); ?></th><th><?php echo h(__t('vm_timeline.th_source')); ?></th><th><?php echo h(__t('vm_edit.th_event')); ?></th><th><?php echo h(__t('vm_edit.status_history_actor')); ?></th></tr></thead>
            <tbody>
            <?php if ($page['entries'] === []) { ?>
                <tr><td colspan="4" class="muted"><?php echo h(__t('vm_history.empty')); ?></td></tr>
            <?php }
            foreach ($page['entries'] as $entry) {
                $actor = (string) ($entry['row']['actor_name'] ?? ''); ?>
                <tr data-vm-history-entry="<?php echo h($entry['kind'] . '-' . $entry['id']); ?>">
                    <td class="nowrap"><?php echo h(portal_format_timestamp($entry['at'])); ?></td>
                    <td><?php echo h(vm_history_source_label($entry['source'])); ?></td>
                    <td><?php echo vm_history_event_html($entry, $missionId, $vmId, $workContext, $canOpenJobLog); ?></td>
                    <td><?php echo h($actor !== '' ? $actor : '—'); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <?php if ($continued || $page['next'] !== null) { ?>
        <nav class="pagination">
            <?php if ($continued) { ?>
                <a class="button button-secondary" href="<?php echo h(vm_history_url($missionId, $vmId, $workContext, $linkFilter)); ?>">&laquo; <?php echo h(__t('vm_history.newest')); ?></a>
            <?php } ?>
            <?php if ($page['next'] !== null) { ?>
                <a class="button button-secondary" href="<?php echo h(vm_history_url($missionId, $vmId, $workContext, $linkFilter, $page['next'])); ?>" data-vm-history-older><?php echo h(__t('vm_history.older')); ?> &raquo;</a>
            <?php } ?>
        </nav>
    <?php }
}

/** @param list<string> $sources */
function vm_history_render_retention(array $sources): void
{
    ?>
    <section class="panel" aria-labelledby="vm-history-retention">
        <h2 id="vm-history-retention"><?php echo h(__t('vm_history.retention_heading')); ?></h2>
        <ul>
            <?php foreach ($sources as $source) {
                $days = virtusphere_vm_history_retention_days($source);
                $label = vm_history_source_label($source); ?>
                <li><?php echo h($days === null
                    ? __t('vm_history.retention_none', ['source' => $label])
                    : __t('vm_history.retention_days', ['source' => $label, 'days' => $days])); ?></li>
            <?php } ?>
        </ul>
    </section>
    <?php
}
