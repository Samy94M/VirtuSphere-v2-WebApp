<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/deploy_urls.php';
require_once __DIR__ . '/../lib/deploy_log_view.php';
require_once __DIR__ . '/../lib/repo/deploy_jobs.php';

/** @var mysqli $connection Provided by bootstrap.php. */
$format = request_string($_GET, 'format', 'html');
if (!in_array($format, ['html', 'json', 'raw'], true)) {
    http_response_code(400);
    exit(__t('portal.invalid_request'));
}

// A fetch whose session expired must receive a real 401 JSON response, not a
// 200 login page that merely fails JSON.parse() and retries forever.
if ($format === 'json') {
    $user = current_user($connection);
    if ($user === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => __t('login.session_expired')], JSON_THROW_ON_ERROR);
        exit;
    }
    if ((int) ($user['must_change_password'] ?? 0) === 1) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => __t('portal.forbidden')], JSON_THROW_ON_ERROR);
        exit;
    }
} else {
    $user = portal_require_user($connection);
}
if (!can('deploy.run', $user)) {
    portal_forbid($connection, $user, 'deploy.run', $format === 'json');
}

$jobId = request_int($_GET, 'id');
$job = $jobId > 0 ? repo_deploy_job($connection, $jobId) : null;

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    if ($job === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => __t('portal.deploy_not_found')], JSON_THROW_ON_ERROR);
        exit;
    }

    try {
        $afterSeq = deploy_job_log_cursor('after_seq', false);
        $beforeSeq = deploy_job_log_cursor('before_seq', true);
        if ($afterSeq !== null && $beforeSeq !== null) {
            throw new InvalidArgumentException('Only one deploy-log cursor is allowed.');
        }
        if ($beforeSeq !== null) {
            $page = repo_deploy_job_log_older($connection, (int) $job['id'], $beforeSeq);
        } elseif ($afterSeq !== null) {
            $page = repo_deploy_job_log_forward($connection, (int) $job['id'], $afterSeq);
        } else {
            $page = repo_deploy_job_log_initial_tail($connection, (int) $job['id']);
        }
    } catch (InvalidArgumentException $exception) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => __t('portal.invalid_request')], JSON_THROW_ON_ERROR);
        exit;
    }
    $page = deploy_job_log_format_page($page);
    $emptyState = deploy_job_log_empty_state($job, $page['logs']);
    echo json_encode([
        'ok' => true,
        'job' => [
            'id' => (int) $job['id'],
            'status' => (string) $job['status'],
            'badge' => deploy_job_status_badge_class((string) $job['status']),
            'updated_at' => portal_format_timestamp((string) $job['updated_at']),
            'terminal' => in_array((string) $job['status'], VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true),
        ],
        'logs' => $page['logs'],
        'oldest_seq' => $page['oldest_seq'],
        'newest_seq' => $page['newest_seq'],
        'has_older' => $page['has_older'],
        'has_more' => $page['has_more'],
        'caught_up' => $page['caught_up'],
        'empty_state' => $emptyState,
        'empty_message' => deploy_job_log_empty_message($emptyState),
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($format === 'raw') {
    if ($job === null) {
        http_response_code(404);
        exit(__t('portal.deploy_not_found'));
    }
    $filename = 'virtusphere-deploy-job-' . (int) $job['id'] . '.ndjson';
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Accel-Buffering: no');
    header('X-VirtuSphere-Log-Retention-Days: ' . VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS);
    $rawBatches = repo_deploy_job_log_raw_batches($connection, (int) $job['id']);
    $rawBatches->rewind();
    $rawHasLogs = $rawBatches->valid() && $rawBatches->current() !== [];
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    echo json_encode([
        'type' => 'meta',
        'job_id' => (int) $job['id'],
        'retention_days' => VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS,
        'portal_timezone' => portal_timezone(),
        'empty_state' => deploy_job_log_empty_state($job, $rawHasLogs ? [['seq' => 1]] : []),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    while ($rawBatches->valid()) {
        $batch = $rawBatches->current();
        foreach ($batch as $entry) {
            echo json_encode([
                'type' => 'log',
                'seq' => (int) $entry['seq'],
                'created_at_utc' => deploy_job_log_raw_utc($entry['created_at'] ?? null),
                'created_at_portal' => portal_format_timestamp($entry['created_at'] ?? null),
                'portal_timezone' => portal_timezone(),
                'source' => (string) ($entry['stream'] ?? ''),
                'source_label' => deploy_job_log_source_label((string) ($entry['stream'] ?? '')),
                'line' => (string) ($entry['line'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        flush();
        $rawBatches->next();
    }
    exit;
}

if ($job === null) {
    flash_set('error', __t('portal.deploy_not_found'));
    redirect_to('deploy.php');
}

$page = repo_deploy_job_log_initial_tail($connection, (int) $job['id']);
$logs = $page['logs'];
$oldestSeq = $page['oldest_seq'] ?? 0;
$lastSeq = $page['newest_seq'] ?? 0;
$isTerminal = in_array((string) $job['status'], VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true);
$originUrl = deploy_job_origin_url($job);
// An empty log on an old finished job is almost certainly the retention prune,
// not a job that printed nothing. Saying so beats an unexplained empty table.
// The ' UTC' suffix is the house rule for DB timestamps: today PHP and MySQL
// both run on UTC, but a date.timezone in php.ini would silently shift this.
$emptyState = deploy_job_log_empty_state($job, $logs);

layout_header(__t('deploy.log_title'), $user, 'deploy');
?>
<div class="stack" data-deploy-log data-job-id="<?php echo h((string) $job['id']); ?>" data-after-seq="<?php echo h((string) $lastSeq); ?>" data-before-seq="<?php echo h((string) $oldestSeq); ?>" data-terminal="<?php echo $isTerminal ? '1' : '0'; ?>" data-caught-up="<?php echo $page['caught_up'] ? '1' : '0'; ?>" data-dom-limit="<?php echo h((string) VIRTUSPHERE_DEPLOY_LOG_DOM_WINDOW); ?>">
    <section class="panel">
        <div class="actions">
            <a class="button button-secondary" href="<?php echo h($originUrl); ?>"><?php echo h(__t('common.back')); ?></a>
            <?php // A system job (ESXi inventory) has no mission; the link would have gone to id=0. ?>
            <?php if ((int) $job['mission_id'] > 0) { ?>
                <a class="button button-secondary" href="mission_details.php?id=<?php echo h((string) $job['mission_id']); ?>"><?php echo h(__t('common.mission')); ?></a>
            <?php } ?>
            <a class="button button-secondary" href="<?php echo h(deploy_job_log_url((int) $job['id'])); ?>"><?php echo h(__t('common.refresh')); ?></a>
            <a class="button button-secondary" href="<?php echo h(deploy_job_raw_log_url((int) $job['id'])); ?>"><?php echo h(__t('deploy.raw_download')); ?></a>
            <?php // Cancellable, not active: a cancelling job's wish is recorded
                  // and the button would promise a no-op (ADR-0033). ?>
            <?php if (in_array((string) $job['status'], VIRTUSPHERE_DEPLOY_JOB_CANCELLABLE_STATUSES, true)) { ?>
                <form class="inline-form" method="post" action="deploy.php<?php echo (int) $job['mission_id'] > 0 ? '?mission_id=' . h((string) $job['mission_id']) : ''; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="job_id" value="<?php echo h((string) $job['id']); ?>">
                    <?php // A system job (ESXi inventory) has no mission, so it names itself. ?>
                    <button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('deploy.confirm_cancel', ['name' => (int) ($job['mission_id'] ?? 0) > 0 ? (string) ($job['mission_name'] ?? '') : __t('deploy.system_job')])); ?>" data-confirm-action="<?php echo h(__t('deploy.cancel_job')); ?>"><?php echo h(__t('common.cancel')); ?></button>
                </form>
            <?php } ?>
        </div>
    </section>

    <section class="grid" aria-label="<?php echo h(__t('deploy.log_title')); ?>">
        <article class="card kpi"><span class="muted"><?php echo h(__t('deploy.kpi_job')); ?></span><span class="value"><?php echo h((string) $job['id']); ?></span></article>
        <article class="card kpi"><span class="muted"><?php echo h(__t('common.status')); ?></span><span class="value"><span data-deploy-status class="badge badge-<?php echo h(deploy_job_status_badge_class((string) $job['status'])); ?>"><?php echo h($job['status'] ?? ''); ?></span></span></article>
        <article class="card kpi"><span class="muted"><?php echo h(__t('deploy.label_mode')); ?></span><span class="value value-small"><?php echo h(deploy_job_payload_summary($job['payload_json'] ?? null)); ?></span></article>
        <article class="card kpi"><span class="muted"><?php echo h(__t('common.mission')); ?></span><span class="value value-small"><?php echo h((int) $job['mission_id'] > 0 ? (string) ($job['mission_name'] ?? '') : __t('deploy.system_job')); ?></span></article>
    </section>

    <section class="panel">
        <h2><?php echo h(__t('deploy.output')); ?></h2>
        <p class="muted"><?php echo h(__t('deploy.retention_hint', ['days' => VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS])); ?></p>
        <p class="muted" data-deploy-log-window-note><?php echo h(__t('deploy.window_notice', ['limit' => VIRTUSPHERE_DEPLOY_LOG_DOM_WINDOW])); ?></p>
        <div class="actions">
            <button class="button button-secondary" type="button" data-deploy-log-older<?php echo $page['has_older'] ? '' : ' hidden'; ?>><?php echo h(__t('deploy.load_older')); ?></button>
            <span class="muted" data-deploy-log-feedback aria-live="polite"></span>
        </div>
        <div class="table-wrap" tabindex="0"><table>
            <thead><tr><th><?php echo h(__t('deploy.th_seq')); ?></th><th><?php echo h(__t('deploy.th_time')); ?></th><th><?php echo h(__t('deploy.th_stream')); ?></th><th><?php echo h(__t('deploy.th_line')); ?></th></tr></thead>
            <tbody data-deploy-log-body>
            <?php foreach ($logs as $log) { ?>
                <tr data-log-seq="<?php echo h((string) $log['seq']); ?>">
                    <td><?php echo h((string) $log['seq']); ?></td>
                    <td><?php echo h(portal_format_timestamp($log['created_at'] ?? '')); ?></td>
                    <td><?php echo h(deploy_job_log_source_label((string) ($log['stream'] ?? ''))); ?></td>
                    <td><code class="log-line"><?php echo h($log['line'] ?? ''); ?></code></td>
                </tr>
            <?php } ?>
            <?php if ($logs === []) { ?><tr data-empty-log><td colspan="4"><?php echo h(deploy_job_log_empty_message($emptyState)); ?></td></tr><?php } ?>
            </tbody>
        </table></div>
    </section>

    <?php if (!empty($job['last_error'])) { ?>
        <section class="panel">
            <h2><?php echo h(__t('deploy.last_error')); ?></h2>
            <code class="log-line"><?php echo h($job['last_error']); ?></code>
        </section>
    <?php } ?>
</div>
<script type="application/json" data-i18n-deploy-log nonce="<?php echo h(virtusphere_csp_nonce()); ?>"><?php echo json_encode([
    'session_expired' => __t('deploy.poll_session_expired'),
    'forbidden' => __t('deploy.poll_forbidden'),
    'failed' => __t('deploy.poll_failed'),
    'loading' => __t('deploy.loading_older'),
    'history_mode' => __t('deploy.history_mode'),
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); ?></script>
<?php layout_footer(); ?>
