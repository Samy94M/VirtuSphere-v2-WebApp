<?php

declare(strict_types=1);

require_once __DIR__ . '/copy_control.php';
require_once __DIR__ . '/portal_work_context.php';
require_once __DIR__ . '/package_report_filter.php';

/** The receipt is evidence, never a live installation state. */
function package_report_result_label(array $run): string
{
    if ($run['completed_received_at'] === null) {
        return __t((int) ($run['reported_failures'] ?? 0) > 0
            ? 'package_report.step_failure_no_completion' : 'package_report.no_completion');
    }

    return match ((string) $run['wrapper_result']) {
        'ok' => __t('package_report.wrapper_ok'),
        'failed' => __t('package_report.wrapper_failed'),
        'reboot_required' => __t('package_report.reboot_required'),
        'reboot_initiated' => __t('package_report.reboot_initiated'),
        default => __t('package_report.wrapper_unknown'),
    };
}

function package_report_result_badge(array $run): string
{
    $variant = $run['completed_received_at'] === null
        ? ((int) ($run['reported_failures'] ?? 0) > 0 ? 'danger' : 'neutral')
        : match ((string) $run['wrapper_result']) {
        'ok' => 'success',
        'failed' => 'danger',
        default => 'warning',
    };

    return portal_badge($variant, package_report_result_label($run));
}

/** @param list<array<string, mixed>> $runs */
function vm_edit_render_package_reports(array $runs, int $missionId, int $vmId, array $workContext): void
{
    ?>
    <section class="panel" id="package-reports">
        <h3><?php echo h(__t('package_report.vm_heading')); ?></h3>
        <p class="muted"><?php echo h(__t('package_report.vm_hint')); ?></p>
        <?php if ($runs === []) { ?>
            <p class="muted"><?php echo h(__t('package_report.none')); ?></p>
        <?php } else { ?>
            <div class="table-wrap" tabindex="0">
                <table>
                    <thead><tr>
                        <th><?php echo h(__t('package_report.package')); ?></th>
                        <th><?php echo h(__t('package_report.result')); ?></th>
                        <th><?php echo h(__t('package_report.last_step')); ?></th>
                        <th><?php echo h(__t('package_report.received')); ?></th>
                        <th><?php echo h(__t('package_report.details')); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($runs as $run) {
                        $url = package_report_detail_url($missionId, $vmId, (string) $run['run_id'], $workContext);
                        ?>
                        <tr>
                            <td><?php echo h((string) $run['project_name'] . ' ' . (string) $run['package_version']); ?></td>
                            <td><?php echo package_report_result_badge($run); ?></td>
                            <td><?php echo $run['last_reported_step'] === null
                                ? h(__t('package_report.no_step'))
                                : h((string) $run['last_reported_step'] . ' — '
                                    . __t('package_report.step_' . (string) $run['last_reported_result'])); ?></td>
                            <td><?php echo h(portal_format_timestamp((string) $run['last_evidence_at'])); ?></td>
                            <td><a href="<?php echo h($url); ?>"><?php echo h(__t('package_report.details')); ?></a></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    </section>
    <?php
}

function package_report_detail_url(int $missionId, int $vmId, string $runId, array $workContext = [], array $dates = [], array $listFilter = []): string
{
    $url = portal_work_context_append_url('package_run.php?mission_id=' . $missionId . '&vm_id=' . $vmId
        . '&run_id=' . rawurlencode($runId), $workContext);
    if ($listFilter !== []) {
        $query = package_report_filter_query($listFilter);
        return $url . '&list=1' . ($query === '' ? '' : '&' . $query);
    }
    foreach (['from', 'to'] as $key) {
        if (isset($dates[$key]) && is_string($dates[$key]) && $dates[$key] !== '') {
            $url .= '&' . $key . '=' . rawurlencode($dates[$key]);
        }
    }
    return $url;
}

/** Support text is a snapshot of this read, not a live link or a command. */
function package_report_support_text(array $run, ?array $firstFailure, string $vmName, string $asOf): string
{
    $line = static fn (string $label, mixed $value): string => $label . ': '
        . str_replace(["\r", "\n"], ' ', (string) $value);
    $lines = [
        $line(__t('package_report.support_as_of'), portal_format_timestamp($asOf)),
        $line(__t('package_report.vm'), $vmName),
        $line(__t('package_report.package'), (string) $run['project_name'] . ' ' . (string) $run['package_version']),
        $line(__t('package_report.run_id'), $run['run_id']),
        $line(__t('package_report.result'), package_report_result_label($run)),
        $line(__t('package_report.first_received'), portal_format_timestamp((string) $run['first_received_at'])),
        $line(__t('package_report.received'), portal_format_timestamp((string) $run['last_evidence_at'])),
    ];
    $lines[] = $run['processed_count'] === null
        ? __t('package_report.count_unknown')
        : __t('package_report.steps_known', [
            'stored' => (int) $run['stored_steps'], 'processed' => (int) $run['processed_count'],
        ]);
    if ((int) $run['stored_starts'] === 0) {
        $lines[] = __t('package_report.start_missing');
    }
    if ($run['wrapper_exit_code'] !== null) {
        $lines[] = $line(__t('package_report.wrapper_exit'), $run['wrapper_exit_code']);
    }
    if ($firstFailure !== null) {
        $lines[] = $line(__t('package_report.first_failure'), (string) $firstFailure['step_index']
            . ' / ' . (string) $firstFailure['script_name']);
        foreach (['error_category' => 'error', 'child_exit_code' => 'child_exit',
            'detail_path' => 'detail_log'] as $key => $label) {
            if ($firstFailure[$key] !== null && $firstFailure[$key] !== '') {
                $lines[] = $line(__t('package_report.' . $label), $firstFailure[$key]);
            }
        }
    }
    foreach (['wrapper_log_path' => 'wrapper_log', 'reporting_log_path' => 'reporting_log'] as $key => $label) {
        if ($run[$key] !== null && $run[$key] !== '') {
            $lines[] = $line(__t('package_report.' . $label), $run[$key]);
        }
    }
    return implode("\n", $lines);
}

/** @param list<array<string, mixed>> $steps */
function package_report_render_detail(array $run, array $steps, string $returnUrl, string $canonicalUrl, int $missionId, int $vmId, string $vmName, array $dates = [], array $workContext = [], array $listFilter = []): void
{
    $firstFailure = null;
    foreach ($steps as $step) {
        if ((int) $step['is_first_failure'] === 1) {
            $firstFailure = $step;
            break;
        }
    }
    $comparison = [
        'scope' => 'package', 'project' => (string) $run['project_name'],
        'version' => (string) $run['package_version'], 'exclude_vm' => $vmId,
    ];
    $datesValid = ($dates['errors'] ?? []) === [];
    if ($datesValid) {
        foreach (['from', 'to'] as $key) {
            if (($dates[$key] ?? '') !== '') {
                $comparison[$key] = $dates[$key];
            }
        }
    }
    $hasCategory = $firstFailure !== null && is_string($firstFailure['error_category'])
        && $firstFailure['error_category'] !== '';
    if ($hasCategory) {
        $comparison['error_category'] = $firstFailure['error_category'];
        if ($firstFailure['child_exit_code'] !== null) {
            $comparison['child_exit'] = (int) $firstFailure['child_exit_code'];
        }
        if ($run['wrapper_exit_code'] !== null) {
            $comparison['wrapper_exit'] = (int) $run['wrapper_exit_code'];
        }
    }
    $comparisonUrl = 'package_reports.php?' . http_build_query($comparison, '', '&', PHP_QUERY_RFC3986);
    $asOf = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    $supportText = package_report_support_text($run, $firstFailure, $vmName, $asOf);
    ?>
    <section class="panel">
        <p><a href="<?php echo h($returnUrl); ?>"><?php echo h(__t($listFilter !== []
            ? 'package_report.back_list' : 'package_report.back_vm')); ?></a></p>
        <h1><?php echo h(__t('package_report.detail_heading')); ?></h1>
        <p><?php echo h((string) $run['project_name'] . ' ' . (string) $run['package_version']); ?></p>
        <p><?php echo package_report_result_badge($run); ?></p>
        <p><?php echo portal_copy_local_link($canonicalUrl, __t('package_report.copy_run_link')); ?></p>
        <p><a href="<?php echo h(package_report_detail_url($missionId, $vmId, (string) $run['run_id'], $workContext, $dates, $listFilter)); ?>"><?php echo h(__t('package_report.refresh')); ?></a></p>
        <?php if (!$datesValid) { ?>
            <p class="alert alert-error" role="alert"><?php echo h(__t('package_report.invalid_filter')); ?></p>
        <?php } else { ?>
            <p><a href="<?php echo h($comparisonUrl); ?>"><?php echo h(__t($hasCategory
                ? 'package_report.compare_other_devices' : 'package_report.other_devices_general')); ?></a></p>
        <?php } ?>
        <dl class="diagnostics">
            <?php foreach ([
                'run_id' => 'run_id', 'run_context' => 'context', 'rollout_revision' => 'revision',
                'last_evidence_at' => 'received', 'client_started_at' => 'client_started',
                'client_completed_at' => 'client_completed', 'wrapper_exit_code' => 'wrapper_exit',
            ] as $column => $label) {
                $value = $run[$column];
                if ($value === null) { continue; }
                if (str_ends_with($column, '_at')) { $value = portal_format_timestamp((string) $value); }
                ?>
                <div class="diagnostics-item">
                    <dt><?php echo h(__t('package_report.' . $label)); ?></dt>
                    <dd><?php echo h((string) $value); ?></dd>
                </div>
            <?php } ?>
        </dl>
        <?php if ((int) $run['stored_starts'] === 0) { ?>
            <p class="muted"><?php echo h(__t('package_report.start_missing')); ?></p>
        <?php } ?>
        <?php if ($run['processed_count'] !== null) { ?>
            <p><?php echo h(__t('package_report.steps_known', [
                'stored' => (int) $run['stored_steps'], 'processed' => (int) $run['processed_count'],
            ])); ?></p>
        <?php } else { ?>
            <p class="muted"><?php echo h(__t('package_report.count_unknown')); ?></p>
        <?php } ?>
        <?php if ($firstFailure !== null) { ?>
            <h2><?php echo h(__t('package_report.first_failure')); ?></h2>
            <p><?php echo h(__t('package_report.failure_step', [
                'step' => (int) $firstFailure['step_index'], 'script' => (string) $firstFailure['script_name'],
            ])); ?></p>
            <?php if ($firstFailure['error_category'] !== null) { ?>
                <p><?php echo h(__t('package_report.error')); ?>: <?php echo h((string) $firstFailure['error_category']); ?></p>
            <?php } ?>
            <?php if ($firstFailure['child_exit_code'] !== null) { ?>
                <p><?php echo h(__t('package_report.child_exit')); ?>: <?php echo h((string) $firstFailure['child_exit_code']); ?></p>
            <?php } ?>
            <?php if (is_string($firstFailure['detail_path']) && $firstFailure['detail_path'] !== '') { ?>
                <p><?php echo h(__t('package_report.detail_log')); ?>: <?php echo portal_copy_value($firstFailure['detail_path'], __t('package_report.detail_log')); ?></p>
            <?php } ?>
            <p class="muted"><?php echo h(__t('package_report.check_step_log')); ?></p>
        <?php } elseif ($run['completed_received_at'] === null) { ?>
            <p class="muted"><?php echo h(__t('package_report.check_wrapper_reporting')); ?></p>
        <?php } ?>
        <?php foreach (['wrapper_log_path' => 'wrapper_log', 'reporting_log_path' => 'reporting_log'] as $column => $label) {
            if (!is_string($run[$column]) || $run[$column] === '') { continue; } ?>
            <p><?php echo h(__t('package_report.' . $label)); ?>: <?php echo portal_copy_value($run[$column], __t('package_report.' . $label)); ?></p>
        <?php } ?>
        <?php if ($steps !== []) { ?>
            <div class="table-wrap" tabindex="0"><table>
                <thead><tr><th><?php echo h(__t('package_report.step')); ?></th><th><?php echo h(__t('package_report.script')); ?></th><th><?php echo h(__t('package_report.result')); ?></th><th><?php echo h(__t('package_report.error')); ?></th></tr></thead>
                <tbody><?php foreach ($steps as $step) { ?>
                    <tr>
                        <td><?php echo h((string) $step['step_index']); ?></td>
                        <td><?php echo h((string) $step['script_name']); ?></td>
                        <td><?php echo h(__t('package_report.step_' . (string) $step['result'])); ?></td>
                        <td><?php echo h((string) ($step['error_category'] ?? '')); ?></td>
                    </tr>
                <?php } ?></tbody>
            </table></div>
        <?php } ?>
        <details>
            <summary><?php echo h(__t('package_report.support_copy')); ?></summary>
            <p class="muted"><?php echo h(__t('package_report.support_hint')); ?></p>
            <label for="package-report-support-text"><?php echo h(__t('package_report.support_as_of')); ?>: <?php echo h(portal_format_timestamp($asOf)); ?></label>
            <textarea id="package-report-support-text" readonly rows="10"><?php echo h($supportText); ?></textarea>
            <?php echo portal_copy_input_button('package-report-support-text', $supportText,
                __t('package_report.support_copy')); ?>
        </details>
    </section>
    <?php
}
