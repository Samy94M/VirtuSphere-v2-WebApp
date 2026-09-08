<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/correlation_display.php';
require_once __DIR__ . '/../lib/deploy_display.php';
require_once __DIR__ . '/../lib/log_filter.php';
require_once __DIR__ . '/../lib/deploy_urls.php';
require_once __DIR__ . '/../lib/deploy_log_panels.php';
require_once __DIR__ . '/../lib/deploy_log_recovery.php';
require_once __DIR__ . '/../lib/deploy_log_create_release.php';
require_once __DIR__ . '/../lib/deploy_create_progress.php';
require_once __DIR__ . '/../lib/deploy_log_view.php';
require_once __DIR__ . '/../lib/deploy_terminal_presenter.php';
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

// Everything that needs the session is decided: identity, permission and the
// locale the catalog was loaded with. From here the poll is a read, so the
// session file is closed before the first query.
//
// PHP holds an exclusive lock on the session for the whole request. A job log
// polls every two seconds and drains without pause while `has_more` is set, so
// keeping that lock means the rest of the portal queues behind this one tab:
// opening a second page in the same session stalls until a poll finishes. The
// close is deliberately NOT applied to the HTML and raw formats, which still
// write flashes and audit rows.
if ($format === 'json' && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
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
    $retryEvaluation = deploy_job_is_retryable((string) $job['status'], (int) ($job['mission_id'] ?? 0))
        ? deploy_retry_blockers($connection, (int) $job['id'])
        : null;
    $existingVmIds = deploy_log_existing_vm_ids($connection, $job);
    echo json_encode([
        'ok' => true,
        'job' => [
            'id' => (int) $job['id'],
            // `status` and `badge` are the existing wire fields and stay exactly
            // as they were, so an older client keeps working. `label` is
            // additive and is the ONLY field the browser is allowed to print:
            // the raw token stays available for machine-side decisions, and the
            // reader never sees it again.
            'status' => (string) $job['status'],
            'badge' => deploy_job_status_badge_class((string) $job['status']),
            'label' => deploy_job_status_label((string) $job['status']),
            'updated_at' => portal_format_timestamp((string) $job['updated_at']),
            'terminal' => in_array((string) $job['status'], VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true),
        ],
        'logs' => $page['logs'],
        'oldest_seq' => $page['oldest_seq'],
        'newest_seq' => $page['newest_seq'],
        'has_older' => $page['has_older'],
        'has_more' => $page['has_more'],
        'caught_up' => $page['caught_up'],
        // The create progress is counted from deploy_create_vm_results, never
        // re-derived from the log lines this response also carries. Null means
        // the job has no create section at all, which is not the same as a
        // section with nothing done yet, so the browser removes the card
        // rather than showing it at zero.
        'create_progress' => deploy_create_progress_payload($connection, $job),
        'empty_state' => $emptyState,
        'empty_message' => deploy_job_log_empty_message($emptyState),
        'terminal_html' => deploy_terminal_blocks_html($job, $retryEvaluation, $existingVmIds),
        'actions' => [
            'can_cancel' => in_array((string) $job['status'], VIRTUSPHERE_DEPLOY_JOB_CANCELLABLE_STATUSES, true),
        ],
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

$view = deploy_log_view_model($connection, $job, $_GET);
$page = $view['page'];
$logs = $view['logs'];
$timeline = $view['timeline'];
$logFilter = $view['filter'];
$oldestSeq = $page['oldest_seq'] ?? 0;
$lastSeq = $page['newest_seq'] ?? 0;
$isTerminal = in_array((string) $job['status'], VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true);
$retryEvaluation = deploy_job_is_retryable((string) $job['status'], (int) ($job['mission_id'] ?? 0))
    ? deploy_retry_blockers($connection, (int) $job['id'])
    : null;
$existingVmIds = deploy_log_existing_vm_ids($connection, $job);
$originUrl = deploy_job_origin_url($job);
// An empty log on an old finished job is almost certainly the retention prune,
// not a job that printed nothing. Saying so beats an unexplained empty table.
// The ' UTC' suffix is the house rule for DB timestamps: today PHP and MySQL
// both run on UTC, but a date.timezone in php.ini would silently shift this.
$emptyState = deploy_job_log_empty_state($job, $logs);
// A page reached through the no-JavaScript older link is HISTORY, not the live
// end of the run. Following it would append the newest incoming lines below a
// window of old ones, which is a log that lies about its own order. The client
// reads this the same way it reads a filtered view: no cursor, no polling.
$isHistoryPage = deploy_job_log_cursor('before_seq', true, $_GET) !== null;

layout_header(__t('deploy.log_title'), $user, 'deploy', 'deploy');
?>
<div class="stack" data-deploy-log data-job-id="<?php echo h((string) $job['id']); ?>" data-after-seq="<?php echo h((string) $lastSeq); ?>" data-before-seq="<?php echo h((string) $oldestSeq); ?>" data-terminal="<?php echo $isTerminal ? '1' : '0'; ?>" data-caught-up="<?php echo $page['caught_up'] ? '1' : '0'; ?>" data-dom-limit="<?php echo h((string) VIRTUSPHERE_DEPLOY_LOG_DOM_WINDOW); ?>" data-bottom-tolerance="<?php echo h((string) VIRTUSPHERE_DEPLOY_LOG_BOTTOM_TOLERANCE_PX); ?>" data-status-throttle="<?php echo h((string) VIRTUSPHERE_DEPLOY_LOG_STATUS_THROTTLE_MS); ?>" data-filtered="<?php echo $logFilter['active'] || $isHistoryPage ? '1' : '0'; ?>">
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
                <form class="inline-form" method="post" action="deploy.php<?php echo (int) $job['mission_id'] > 0 ? '?mission_id=' . h((string) $job['mission_id']) : ''; ?>" data-deploy-cancel-form>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="job_id" value="<?php echo h((string) $job['id']); ?>">
                    <input type="hidden" name="origin" value="<?php echo h(VIRTUSPHERE_DEPLOY_JOB_ORIGIN_LOG); ?>">
                    <?php // A system job (ESXi inventory) has no mission, so it names itself. ?>
                    <button class="button button-danger" type="submit" data-confirm="<?php echo h(__t('deploy.confirm_cancel', ['name' => (int) ($job['mission_id'] ?? 0) > 0 ? (string) ($job['mission_name'] ?? '') : __t('deploy.system_job')])); ?>" data-confirm-action="<?php echo h(__t('deploy.cancel_job')); ?>"><?php echo h(__t('common.cancel')); ?></button>
                </form>
            <?php } ?>
            <?php if (is_array($retryEvaluation) && !empty($retryEvaluation['allowed'])) { ?>
                <form class="inline-form" method="post" action="deploy.php?mission_id=<?php echo h((string) $job['mission_id']); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="retry">
                    <input type="hidden" name="job_id" value="<?php echo h((string) $job['id']); ?>">
                    <input type="hidden" name="origin" value="<?php echo h(VIRTUSPHERE_DEPLOY_JOB_ORIGIN_LOG); ?>">
                    <button class="button button-secondary" type="submit" data-confirm="<?php echo h(!empty($retryEvaluation['external_confirmation']) ? __t('deploy.confirm_retry_external', ['name' => (string) ($job['mission_name'] ?? '')]) : __t('deploy.confirm_retry', ['name' => (string) ($job['mission_name'] ?? '')])); ?>"><?php echo h(__t('deploy.retry')); ?></button>
                </form>
            <?php } elseif (is_array($retryEvaluation) && (int) ($retryEvaluation['repair_vm_id'] ?? 0) > 0 && can('vms.write', $user)) { ?>
                <a class="button button-secondary" href="<?php echo h(vm_edit_url((int) $job['mission_id'], (int) $retryEvaluation['repair_vm_id'], 'interfaces')); ?>"><?php echo h(__t('deploy.retry_fix_configuration')); ?></a>
            <?php } elseif (is_array($retryEvaluation)) { ?>
                <span class="muted" data-deploy-retry-blocked><?php echo h(__t('deploy.retry_blocked_short')); ?></span>
            <?php } ?>
        </div>
    </section>

    <section class="grid" aria-label="<?php echo h(__t('deploy.log_title')); ?>">
        <article class="card kpi"><span class="muted"><?php echo h(__t('deploy.kpi_job')); ?></span><span class="value"><?php echo h((string) $job['id']); ?></span></article>
        <article class="card kpi"><span class="muted"><?php echo h(__t('common.status')); ?></span><span class="value"><span data-deploy-status class="badge badge-<?php echo h(deploy_job_status_badge_class((string) $job['status'])); ?>"><?php echo h(deploy_job_status_label((string) ($job['status'] ?? ''))); ?></span></span></article>
        <article class="card kpi"><span class="muted"><?php echo h(__t('deploy.label_mode')); ?></span><span class="value value-small"><?php echo h(deploy_job_payload_display($job['payload_json'] ?? null)); ?></span></article>
        <article class="card kpi"><span class="muted"><?php echo h(__t('common.mission')); ?></span><span class="value value-small"><?php echo h((int) $job['mission_id'] > 0 ? (string) ($job['mission_name'] ?? '') : __t('deploy.system_job')); ?></span></article>
        <?php // The trace of the portal request that enqueued this job
              // (ADR-0032). Rendered exactly as the audit table renders it, so
              // the id can be carried from here into the log search and back;
              // the link goes to that search, which this page is not. It is
              // gated on users.manage because the audit view it opens is.
              // A job from before the id exists shows a dash, not a blank. ?>
        <article class="card kpi"><span class="muted"><?php echo h(__t('logs.th_correlation')); ?></span><span class="value value-small"><?php echo portal_correlation_id(
            (string) ($job['correlation_id'] ?? ''),
            can('users.manage', $user) ? log_filter_correlation_url(VIRTUSPHERE_LOG_TAB_DEPLOY, (string) ($job['correlation_id'] ?? '')) : ''
        ); ?></span></article>
    </section>

    <div class="stack" data-deploy-terminal-blocks><?php echo deploy_terminal_blocks_html($job, $retryEvaluation, $existingVmIds); ?></div>

    <?php // Above the recovery and phase blocks on purpose: the question this
          // card answers ("which of the fifteen VMs exist") is the one the
          // incident was about, and it must not sit below four other panels. ?>
    <?php deploy_log_render_create_progress($connection, $job, $user); ?>
    <?php deploy_log_render_recovery($job, deploy_log_remote_execution($connection, (int) $job['id']), $user); ?>
    <?php deploy_log_render_create_release($connection, $job, $user); ?>
    <?php deploy_log_render_phases($timeline); ?>
    <?php deploy_log_render_filter((int) $job['id'], $logFilter, $view['phase_names'], count($logs), $view['match_capped']); ?>

    <section class="panel">
        <h2><?php echo h(__t('deploy.output')); ?></h2>
        <p class="muted"><?php echo h(__t('deploy.retention_hint', ['days' => VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS])); ?></p>
        <p class="muted" data-deploy-log-window-note><?php echo h(__t('deploy.window_notice', ['limit' => VIRTUSPHERE_DEPLOY_LOG_DOM_WINDOW])); ?></p>
        <div class="actions">
            <?php // A LINK, not a button: this was a `type="button"` that did
                  // nothing without JavaScript, on the one page a person opens
                  // when something has already gone wrong. The endpoint has
                  // accepted `before_seq` as a GET cursor all along, so the
                  // capability existed and was simply not offered. The client
                  // upgrades it in place (preventDefault plus the same fetch);
                  // with scripting off the href does the paging. ?>
            <a class="button button-secondary"<?php echo $page['has_older'] && $oldestSeq > 0 ? ' href="' . h(deploy_log_older_url((int) $job['id'], $oldestSeq)) . '"' : ''; ?> data-deploy-log-older<?php echo $page['has_older'] ? '' : ' hidden'; ?>><?php echo h(__t('deploy.load_older')); ?></a>
            <?php // Both switches carry a real visible label, not a title or an
                  // icon: they change what the view does, and a control whose
                  // meaning is only in a tooltip has no meaning on a keyboard. ?>
            <?php // Follow, the jump target and the retry belong to the live
                  // reading. A filtered view has no cursor to follow, so the
                  // controls are absent rather than present and inert. ?>
            <?php if (!$logFilter['active']) { ?>
            <label class="inline-check"><input type="checkbox" data-deploy-log-follow checked> <?php echo h(__t('deploy.follow_label')); ?></label>
            <?php } ?>
            <label class="inline-check"><input type="checkbox" data-deploy-log-wrap checked> <?php echo h(__t('deploy.wrap_label')); ?></label>
            <?php if (!$logFilter['active']) { ?>
            <button class="button button-secondary" type="button" data-deploy-log-jump hidden></button>
            <button class="button button-secondary" type="button" data-deploy-log-retry hidden><?php echo h(__t('deploy.poll_retry')); ?></button>
            <?php } ?>
        </div>
        <?php if (!$logFilter['active']) { ?><p class="muted"><?php echo h(__t('deploy.follow_hint')); ?></p><?php } ?>
        <?php // The connection state and the feedback line are the only regions
              // that speak. They are role="status" (polite, atomic), so a state
              // CHANGE is announced once as a whole sentence. ?>
        <p class="muted" data-deploy-log-connection role="status" aria-atomic="true"></p>
        <p class="muted" data-deploy-log-feedback role="status" aria-atomic="true"></p>
        <?php // role="log" identifies the region for assistive technology, but
              // aria-live is explicitly off: an Ansible run emits thousands of
              // lines, and a polite live region would queue every one of them
              // and read log output for minutes without a way to stop. The
              // announcing is done by the throttled status line above, which
              // summarises a batch in one sentence. The batch itself is appended
              // in a single DOM mutation for the same reason. ?>
        <div class="table-wrap" tabindex="0" role="log" aria-live="off" aria-label="<?php echo h(__t('deploy.output')); ?>" data-deploy-log-scroller><table>
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

</div>
<script type="application/json" data-i18n-deploy-log nonce="<?php echo h(virtusphere_csp_nonce()); ?>"><?php echo json_encode([
    'session_expired' => __t('deploy.poll_session_expired'),
    'forbidden' => __t('deploy.poll_forbidden'),
    'failed' => __t('deploy.poll_failed'),
    'loading' => __t('deploy.loading_older'),
    'history_mode' => __t('deploy.history_mode'),
    'follow_paused' => __t('deploy.follow_paused'),
    'jump_to_end' => __t('deploy.jump_to_end'),
    'new_lines_one' => __t('deploy.new_lines_one'),
    'new_lines_many' => __t('deploy.new_lines_many'),
    'live' => __t('deploy.live_state_live'),
    'live_paused' => __t('deploy.live_state_paused'),
    'live_interrupted' => __t('deploy.live_state_interrupted'),
    'live_updated' => __t('deploy.live_state_updated'),
    'live_finished' => __t('deploy.live_state_finished'),
    'live_off' => __t('deploy.live_state_off'),
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); ?></script>
<?php layout_footer(); ?>
