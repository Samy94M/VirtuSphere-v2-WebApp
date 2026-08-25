<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_job_result.php';
require_once __DIR__ . '/mac_import.php';
require_once __DIR__ . '/portal_time.php';
require_once __DIR__ . '/layout_response.php';

/** @return array{result:?array,reason:?array,cancel:?array,last_error:?string} */
function deploy_terminal_presenter(array $job): array
{
    $status = (string) ($job['status'] ?? '');
    $result = null;
    if (in_array($status, VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true)) {
        $mac = mac_import_decode_result(isset($job['result_json']) ? (string) $job['result_json'] : null);
        $genericResult = deploy_job_decode_terminal_result(isset($job['result_json']) ? (string) $job['result_json'] : null);
        $resultKey = match ($status) {
            VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED => $mac === null ? 'deploy.result_succeeded' : 'deploy.result_succeeded_counts',
            VIRTUSPHERE_DEPLOY_STATUS_PARTIAL => $mac === null ? 'deploy.result_partial' : 'deploy.result_partial_counts',
            VIRTUSPHERE_DEPLOY_STATUS_FAILED => 'deploy.result_failed',
            VIRTUSPHERE_DEPLOY_STATUS_CANCELLED => 'deploy.result_cancelled',
        };
        $counts = $mac['counts'] ?? [];
        $result = [
            'title' => __t('deploy.result_heading'),
            'text' => __t($resultKey, [
                'successful' => (int) ($counts['successful_vms'] ?? count($mac['successful_vm_ids'] ?? [])),
                'failed' => (int) ($counts['failed_vms'] ?? count($mac['failed_vm_ids'] ?? [])),
            ]),
            'structured' => $mac !== null || ($genericResult !== null && $genericResult['outcome'] === $status),
        ];
    }

    $reason = null;
    $code = trim((string) ($job['terminal_reason_code'] ?? ''));
    if (in_array($status, VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true)) {
        $knownCode = isset(VIRTUSPHERE_DEPLOY_TERMINAL_REASON_STATUSES[$code])
            && in_array($status, VIRTUSPHERE_DEPLOY_TERMINAL_REASON_STATUSES[$code], true);
        $labelKey = $knownCode ? 'deploy.terminal_reason_' . $code : 'deploy.terminal_reason_legacy';
        $reason = [
            'title' => __t('deploy.terminal_reason_heading'),
            'text' => __t($labelKey),
            // Codes are durable technical values. They are displayed verbatim,
            // never localized; an invalid future value remains visible too.
            'code' => $code,
            'detail' => deploy_terminal_reason_detail(isset($job['terminal_reason_detail']) ? (string) $job['terminal_reason_detail'] : null),
        ];
    }

    $cancel = null;
    if ($status === VIRTUSPHERE_DEPLOY_STATUS_CANCELLING || $status === VIRTUSPHERE_DEPLOY_STATUS_CANCELLED) {
        $actorId = (int) ($job['cancel_requested_by'] ?? 0);
        $actorName = trim((string) ($job['cancel_requested_by_name'] ?? ''));
        $actor = $actorName !== ''
            ? $actorName
            : ($actorId > 0 ? __t('deploy.cancel_actor_deleted', ['id' => $actorId]) : __t('deploy.cancel_actor_unknown'));
        $requestedAt = portal_format_timestamp($job['cancel_requested_at'] ?? null);
        $cancelledAt = portal_format_timestamp($job['cancelled_at'] ?? null);
        $cancel = [
            'title' => __t('deploy.cancel_heading'),
            'text' => $status === VIRTUSPHERE_DEPLOY_STATUS_CANCELLING
                ? __t('deploy.cancel_pending')
                : __t('deploy.cancel_confirmed'),
            'actor' => $actor,
            'requested_at' => $requestedAt,
            'cancelled_at' => $cancelledAt,
        ];
    }

    return [
        'result' => $result,
        'reason' => $reason,
        'cancel' => $cancel,
        // Legacy cancelled rows may contain old cancellation prose here. The
        // status decides semantics; string comparisons never do.
        'last_error' => $status === VIRTUSPHERE_DEPLOY_STATUS_FAILED && !empty($job['last_error'])
            ? (string) $job['last_error']
            : null,
    ];
}

function deploy_terminal_blocks_html(array $job): string
{
    $view = deploy_terminal_presenter($job);
    $html = '';
    foreach (['result', 'reason', 'cancel'] as $name) {
        $block = $view[$name];
        if (!is_array($block)) {
            continue;
        }
        $html .= '<section class="panel"><h2>' . deploy_terminal_h((string) $block['title']) . '</h2>';
        $html .= '<p>' . deploy_terminal_h((string) $block['text']) . '</p>';
        if ($name === 'reason' && (string) ($block['code'] ?? '') !== '') {
            $html .= '<p><span class="muted">' . deploy_terminal_h(__t('deploy.terminal_reason_code')) . '</span> '
                . '<code>' . deploy_terminal_h((string) $block['code']) . '</code></p>';
        }
        if ($name === 'reason' && (string) ($block['detail'] ?? '') !== '') {
            $html .= '<p><span class="muted">' . deploy_terminal_h(__t('deploy.terminal_reason_detail')) . '</span> '
                . '<code class="log-line">' . deploy_terminal_h((string) $block['detail']) . '</code></p>';
        }
        if ($name === 'cancel') {
            $html .= '<p>' . deploy_terminal_h(__t('deploy.cancel_requested_by', ['actor' => (string) $block['actor']])) . '</p>';
            if ((string) $block['requested_at'] !== '') {
                $html .= '<p>' . deploy_terminal_h(__t('deploy.cancel_requested_at', ['time' => (string) $block['requested_at']])) . '</p>';
            }
            if ((string) $block['cancelled_at'] !== '') {
                $html .= '<p>' . deploy_terminal_h(__t('deploy.cancelled_at', ['time' => (string) $block['cancelled_at']])) . '</p>';
            }
        }
        $html .= '</section>';
    }
    if (is_string($view['last_error'])) {
        $html .= '<section class="panel"><h2>' . deploy_terminal_h(__t('deploy.last_error')) . '</h2>'
            . '<code class="log-line">' . deploy_terminal_h($view['last_error']) . '</code></section>';
    }

    return $html;
}

function deploy_terminal_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
