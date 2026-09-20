<?php

declare(strict_types=1);

require_once __DIR__ . '/repo/deploy_create_results.php';
require_once __DIR__ . '/repo/deploy_job_log_search.php';
require_once __DIR__ . '/mac_import.php';

/** Historical names belong to this job, not today's editable VM configuration. */
function deploy_log_history_rows(array $createRows, ?array $macResult): array
{
    $rows = [];
    foreach ($createRows as $row) {
        $status = (string) ($row['status'] ?? '');
        $label = match ($status) {
            VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING => 'pending',
            VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING => 'running',
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED => 'failed',
            VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED => 'verified',
            VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED => match ($row['outcome'] ?? '') {
                VIRTUSPHERE_CREATE_OUTCOME_CREATED => 'created',
                VIRTUSPHERE_CREATE_OUTCOME_UPDATED => 'updated',
                VIRTUSPHERE_CREATE_OUTCOME_UNCHANGED => 'unchanged',
                default => 'verified',
            },
            default => 'unknown',
        };
        $rows[] = [
            'vm_name' => (string) ($row['vm_name'] ?? ''),
            'step' => 'create', 'result' => $label,
            'started_at' => $row['started_at'] ?? null,
            // An unresolved result must never look like a completed VM.
            'finished_at' => in_array($status, [VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
                VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED], true)
                ? ($row['finished_at'] ?? null) : null,
        ];
    }
    foreach (($macResult['vm_results'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rows[] = [
            'vm_name' => (string) ($row['vm_name'] ?? ''),
            'step' => 'mac',
            'result' => match ($row['outcome'] ?? '') {
                'success' => 'imported', 'failed' => 'failed', default => 'unknown',
            },
            // The callback stores results, not per-VM observation timestamps.
            'started_at' => null, 'finished_at' => null,
        ];
    }
    return $rows;
}

function deploy_log_history_html(mysqli $db, array $job): string
{
    $startedAt = repo_deploy_job_started_at($db, (int) $job['id']);
    $rows = deploy_log_history_rows(
        repo_deploy_create_results($db, (int) $job['id']),
        mac_import_decode_result($job['result_json'] ?? null)
    );
    ob_start();
    ?>
    <section class="grid" aria-label="<?php echo h(__t('deploy_history.times')); ?>">
        <article class="card kpi"><span class="muted"><?php echo h(__t('deploy_history.queued')); ?></span><span class="value value-small"><?php echo h(portal_format_timestamp($job['created_at'] ?? null)); ?></span></article>
        <article class="card kpi"><span class="muted"><?php echo h(__t('deploy_history.started')); ?></span><span class="value value-small"><?php echo h($startedAt === null ? __t('deploy_history.not_recorded') : portal_format_timestamp($startedAt)); ?></span><span class="muted"><?php echo h(__t('deploy_history.started_hint')); ?></span></article>
    </section>
    <section class="panel">
        <h2><?php echo h(__t('deploy_history.heading')); ?></h2>
        <p class="muted"><?php echo h(__t('deploy_history.hint')); ?></p>
        <div class="table-wrap"><table>
            <thead><tr>
                <?php foreach (['vm', 'step', 'begin', 'end', 'result'] as $column) { ?>
                    <th scope="col"><?php echo h(__t('deploy_history.' . $column)); ?></th>
                <?php } ?>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $row) { ?>
                    <tr><td><?php echo h($row['vm_name']); ?></td><td><?php echo h(__t('deploy_history.' . $row['step'])); ?></td>
                        <td><?php echo h($row['started_at'] === null ? __t('deploy_history.not_recorded') : portal_format_timestamp($row['started_at'])); ?></td>
                        <td><?php echo h($row['finished_at'] === null ? __t('deploy_history.not_recorded') : portal_format_timestamp($row['finished_at'])); ?></td>
                        <td><?php echo h(__t('deploy_history.' . $row['result'])); ?></td></tr>
                <?php } ?>
                <?php if ($rows === []) { ?><tr><td colspan="5"><?php echo h(__t('deploy_history.empty')); ?></td></tr><?php } ?>
            </tbody>
        </table></div>
    </section>
    <?php
    return (string) ob_get_clean();
}
