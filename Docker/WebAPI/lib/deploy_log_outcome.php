<?php

declare(strict_types=1);

// The outcome blocks above a job log (E1/E2, 29.10.2026).
//
// Prod job 1527 said the same thing four times at the top ("partial result",
// "regular end, see the result", a technical code and an English detail) and
// named the one VM that was missing only further down, under "stored causes".
// The page now answers in this order: what happened to which VM and what to
// do (the result block), then everything technical in one collapsed place
// that every reader can open. The initial render and the live payload both go
// through deploy_log_outcome_html(), so they cannot drift apart.
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_create_progress.php';
require_once __DIR__ . '/deploy_create_progress_findings.php';
require_once __DIR__ . '/deploy_log_filter.php';
require_once __DIR__ . '/deploy_terminal_presenter.php';
require_once __DIR__ . '/correlation_display.php';
require_once __DIR__ . '/log_filter.php';
require_once __DIR__ . '/layout_response.php';

/**
 * Result, reason, cancel and technical details of one job as HTML.
 *
 * @param array<string,mixed> $job
 * @param array<string,mixed>|null $retryEvaluation
 * @param list<int>|null $existingVmIds
 * @param array<string,mixed> $user
 */
function deploy_log_outcome_html(mysqli $db, array $job, ?array $retryEvaluation, ?array $existingVmIds, array $user): string
{
    $view = deploy_create_progress_view($db, $job);
    $findings = $view === null ? [] : (array) $view['findings'];
    $terminal = deploy_create_progress_job_is_terminal($job);
    $retryAllowed = is_array($retryEvaluation) && !empty($retryEvaluation['allowed']);

    return deploy_terminal_blocks_html(
        $job,
        $retryEvaluation,
        $existingVmIds,
        can('system.config', $user),
        can('deploy.run', $user),
        $terminal ? deploy_log_create_outcome_html((int) $job['id'], $findings, $retryAllowed) : ''
    ) . deploy_log_technical_details_html($job, $findings, can('users.manage', $user));
}

/**
 * One line per affected VM directly under "Result": the VM, the closed cause,
 * the host's message where there is one, and the place in the log. Below the
 * list one sentence says what "Run again" does, and only while it is offered.
 *
 * @param list<array<string,mixed>> $findings
 */
function deploy_log_create_outcome_html(int $jobId, array $findings, bool $retryAllowed): string
{
    if ($findings === []) {
        return '';
    }
    $html = '<ul data-deploy-create-outcome>';
    foreach ($findings as $finding) {
        $html .= '<li data-create-outcome-position="' . h((string) (int) $finding['position']) . '">'
            . h(deploy_create_progress_finding_text($finding))
            . deploy_create_progress_host_html($finding)
            . '<br><a href="' . h(deploy_log_create_unit_url($jobId, $finding)) . '">' . h(__t('deploy_log.show_in_log')) . '</a>'
            . '</li>';
    }
    $html .= '</ul>';
    if ($retryAllowed) {
        $html .= '<p>' . h(__t('deploy_log.retry_creates_missing', ['action' => __t('deploy.retry')])) . '</p>';
    }

    return $html;
}

/**
 * Everything technical in one collapsed block, open to every reader (E2): the
 * stored code and English detail of the job's end, the stored code and text of
 * each affected VM with its first attempt, and the correlation id. These texts
 * stay English as stored (docs/ai/reference/i18n.md R13); the visible meaning
 * is in the blocks above. Its own panel, so it exists for a running job too.
 *
 * @param array<string,mixed> $job
 * @param list<array<string,mixed>> $findings
 */
function deploy_log_technical_details_html(array $job, array $findings, bool $canSeeTrace): string
{
    $row = static fn (string $label, string $value): string => '<p><span class="muted">' . h($label) . '</span> ' . $value . '</p>';
    $code = static fn (string $value): string => '<code>' . h($value) . '</code>';
    $text = static fn (string $value): string => '<code class="log-line">' . h($value) . '</code>';

    $correlation = (string) ($job['correlation_id'] ?? '');
    $html = $row(__t('logs.th_correlation'), portal_correlation_id(
        $correlation,
        $canSeeTrace ? log_filter_correlation_url(VIRTUSPHERE_LOG_TAB_DEPLOY, $correlation) : ''
    ));
    if (in_array((string) ($job['status'] ?? ''), VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true)) {
        $reasonCode = trim((string) ($job['terminal_reason_code'] ?? ''));
        $reasonDetail = deploy_terminal_reason_detail(isset($job['terminal_reason_detail']) ? (string) $job['terminal_reason_detail'] : null);
        if ($reasonCode !== '') {
            $html .= $row(__t('deploy.terminal_reason_code'), $code($reasonCode));
        }
        if ($reasonDetail !== null && $reasonDetail !== '') {
            $html .= $row(__t('deploy.terminal_reason_detail'), $text($reasonDetail));
        }
    }
    foreach ($findings as $finding) {
        $label = __t('deploy_log.technical_vm', ['name' => (string) $finding['vm_name'], 'position' => (int) $finding['position']]);
        $html .= $row($label, $code((string) $finding['error_code'])
            . ((string) $finding['detail'] !== '' ? ' ' . $text((string) $finding['detail']) : ''));
        if ((string) $finding['first_error_code'] !== '') {
            $html .= $row(__t('deploy_log.technical_first_attempt'), $code((string) $finding['first_error_code'])
                . ((string) $finding['first_error_detail'] !== '' ? ' ' . $text((string) $finding['first_error_detail']) : ''));
        }
    }

    return '<section class="panel"><details class="technical-details" data-deploy-technical><summary>'
        . h(__t('common.technical_details')) . '</summary>' . $html . '</details></section>';
}
