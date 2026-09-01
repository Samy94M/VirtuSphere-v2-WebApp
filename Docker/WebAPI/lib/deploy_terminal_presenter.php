<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_job_result.php';
require_once __DIR__ . '/mac_import.php';
require_once __DIR__ . '/mac_import_presenter.php';
require_once __DIR__ . '/vm_network_display.php';
require_once __DIR__ . '/vm_network_preflight_result.php';
require_once __DIR__ . '/vm_urls.php';
require_once __DIR__ . '/portal_time.php';
require_once __DIR__ . '/layout_response.php';

/** @return array{result:?array,reason:?array,cancel:?array,last_error:?string} */
function deploy_terminal_presenter(array $job, ?array $existingVmIds = null): array
{
    $status = (string) ($job['status'] ?? '');
    $result = null;
    if (in_array($status, VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true)) {
        $mac = mac_import_decode_result(isset($job['result_json']) ? (string) $job['result_json'] : null);
        $network = vm_network_preflight_decode_result(isset($job['result_json']) ? (string) $job['result_json'] : null);
        $genericResult = deploy_job_decode_terminal_result(isset($job['result_json']) ? (string) $job['result_json'] : null);
        $resultKey = $network !== null ? 'deploy.result_network_preflight' : match ($status) {
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
                'blocked' => (int) ($network['counts']['blocked_vms'] ?? 0),
                'expected' => (int) ($network['counts']['expected_vms'] ?? 0),
            ]),
            'structured' => $mac !== null || $network !== null || ($genericResult !== null && $genericResult['outcome'] === $status),
            'mac_version' => (int) ($mac['version'] ?? 0),
            'vm_rows' => $mac !== null ? mac_import_present_vm_rows($mac, $job, $existingVmIds) : [],
            'network_rows' => $network !== null ? deploy_terminal_network_rows($network, $job, $existingVmIds) : [],
            // A stored preflight result may list fewer VMs than it blocked
            // (correction plan 16.4). The counts above stay the complete
            // decision, so the table has to say that it is not all of it;
            // otherwise "3 of 40 blocked" reads as a table with 3 missing rows.
            'network_omitted' => (int) ($network['vm_results_omitted_count'] ?? 0),
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

function deploy_terminal_blocks_html(array $job, ?array $retryEvaluation = null, ?array $existingVmIds = null): string
{
    $view = deploy_terminal_presenter($job, $existingVmIds);
    $html = '';
    foreach (['result', 'reason', 'cancel'] as $name) {
        $block = $view[$name];
        if (!is_array($block)) {
            continue;
        }
        $html .= '<section class="panel"><h2>' . deploy_terminal_h((string) $block['title']) . '</h2>';
        $html .= '<p>' . deploy_terminal_h((string) $block['text']) . '</p>';
        if ($name === 'result' && (int) ($block['mac_version'] ?? 0) === VIRTUSPHERE_MAC_IMPORT_LEGACY_RESULT_VERSION) {
            $html .= '<p class="muted">' . deploy_terminal_h(__t('deploy.mac_result_legacy')) . '</p>';
        }
        if ($name === 'result' && (array) ($block['vm_rows'] ?? []) !== []) {
            $html .= deploy_terminal_vm_results_html((array) $block['vm_rows']);
        }
        if ($name === 'result' && (array) ($block['network_rows'] ?? []) !== []) {
            $html .= deploy_terminal_network_results_html((array) $block['network_rows']);
            if ((int) ($block['network_omitted'] ?? 0) > 0) {
                $html .= '<p class="muted">' . deploy_terminal_h(
                    __t('deploy.blocker_omitted', ['count' => (int) $block['network_omitted']])
                ) . '</p>';
            }
        }
        if ($name === 'result' && is_array($retryEvaluation) && !empty($retryEvaluation['network_ready'])) {
            $html .= '<p>' . deploy_terminal_h(__t('deploy.retry_network_ready_now')) . '</p>';
        } elseif ($name === 'result' && is_array($retryEvaluation) && deploy_terminal_retry_has_network_blocker((array) ($retryEvaluation['findings'] ?? []))) {
            $html .= '<p>' . deploy_terminal_h(__t('deploy.retry_network_still_blocked')) . '</p>';
        }
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

/** @param list<array<string,mixed>> $rows */
function deploy_terminal_vm_results_html(array $rows): string
{
    $html = '<div class="table-wrap" tabindex="0"><table><thead><tr>'
        . '<th>' . deploy_terminal_h(__t('common.vms')) . '</th>'
        . '<th>' . deploy_terminal_h(__t('common.status')) . '</th>'
        . '<th>' . deploy_terminal_h(__t('deploy.mac_wds_heading')) . '</th>'
        . '<th>' . deploy_terminal_h(__t('deploy.mac_reason_heading')) . '</th>'
        . '<th>' . deploy_terminal_h(__t('common.actions')) . '</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $success = (string) ($row['outcome'] ?? '') === 'success';
        $wds = is_array($row['wds'] ?? null) ? $row['wds'] : [];
        $html .= '<tr><td>' . deploy_terminal_h((string) ($row['vm_name'] ?? ''));
        if (empty($row['vm_exists'])) {
            $html .= '<br><span class="muted">' . deploy_terminal_h(__t('deploy.mac_vm_deleted')) . '</span>';
        }
        $html .= '</td>';
        $html .= '<td>' . deploy_terminal_h($success ? __t('deploy.mac_vm_success') : __t('deploy.mac_vm_failed')) . '</td>';
        $html .= '<td><code>' . deploy_terminal_h((string) ($wds['configured_portgroup'] ?? '')) . '</code><br>'
            . deploy_terminal_h(!empty($wds['verified']) ? __t('deploy.mac_wds_verified') : __t('deploy.mac_wds_not_verified')) . '</td>';
        $errors = (array) ($row['errors'] ?? []);
        if ($errors === []) {
            $html .= '<td>' . deploy_terminal_h(__t('deploy.mac_no_error')) . '</td><td></td>';
        } else {
            $html .= '<td><ul>';
            foreach ($errors as $error) {
                $html .= '<li>' . deploy_terminal_h((string) $error['message']);
                if ((string) $error['context'] !== '') {
                    $html .= ' ' . deploy_terminal_h((string) $error['context']);
                }
                $html .= ' <code>' . deploy_terminal_h((string) $error['code']) . '</code></li>';
            }
            $action = null;
            foreach ($errors as $error) {
                if ((string) ($error['action_url'] ?? '') !== '' && (string) ($error['action_label'] ?? '') !== '') {
                    $action = $error;
                    break;
                }
            }
            $html .= '</ul></td><td>';
            if (is_array($action)) {
                $html .= '<a href="' . deploy_terminal_h((string) $action['action_url']) . '">'
                    . deploy_terminal_h((string) $action['action_label']) . '</a>';
            } else {
                foreach ($errors as $error) {
                    if ((string) ($error['action_label'] ?? '') !== '' && (string) ($error['action_url'] ?? '') === '') {
                        $html .= deploy_terminal_h((string) $error['action_label']);
                        break;
                    }
                }
            }
            $html .= '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}

/** @return list<array<string,mixed>> */
function deploy_terminal_network_rows(array $result, array $job, ?array $existingVmIds): array
{
    $rows = [];
    foreach ((array) ($result['vm_results'] ?? []) as $row) {
        $vmId = (int) ($row['vm_id'] ?? 0);
        $vmExists = $existingVmIds === null || in_array($vmId, $existingVmIds, true);
        $messages = [];
        foreach ((array) ($row['issues'] ?? []) as $issue) {
            $finding = (array) $issue + [
                'mission_id' => (int) ($job['mission_id'] ?? 0),
                'vm_id' => $vmId,
                'vm_name' => (string) ($row['vm_name'] ?? ''),
                'occurrences' => count((array) ($issue['interface_ids'] ?? [])),
            ];
            $messages[] = [
                'code' => (string) ($issue['code'] ?? ''),
                'message' => vm_network_finding_message($finding),
            ];
        }
        $rows[] = [
            'vm_id' => $vmId,
            'vm_name' => (string) ($row['vm_name'] ?? ''),
            'vm_exists' => $vmExists,
            'issues' => $messages,
            'action_url' => $vmExists && function_exists('can') && can('vms.write')
                ? vm_edit_url((int) ($job['mission_id'] ?? 0), $vmId, 'interfaces')
                : '',
            'missing' => false,
        ];
    }
    foreach ((array) ($result['missing_vm_ids'] ?? []) as $vmId) {
        $rows[] = [
            'vm_id' => (int) $vmId,
            'vm_name' => 'VM #' . (int) $vmId,
            'vm_exists' => false,
            'issues' => [['code' => '', 'message' => __t('deploy.mac_vm_deleted')]],
            'action_url' => '',
            'missing' => true,
        ];
    }
    return $rows;
}

/** @param list<array<string,mixed>> $rows */
function deploy_terminal_network_results_html(array $rows): string
{
    $html = '<div class="table-wrap" tabindex="0"><table><thead><tr>'
        . '<th>' . deploy_terminal_h(__t('common.vms')) . '</th>'
        . '<th>' . deploy_terminal_h(__t('deploy.network_issue_heading')) . '</th>'
        . '<th>' . deploy_terminal_h(__t('common.actions')) . '</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr><td>' . deploy_terminal_h((string) ($row['vm_name'] ?? ''));
        if (!empty($row['missing'])) {
            $html .= '<br><span class="muted">' . deploy_terminal_h(__t('deploy.mac_vm_deleted')) . '</span>';
        }
        $html .= '</td><td><ul>';
        foreach ((array) ($row['issues'] ?? []) as $issue) {
            $html .= '<li>' . deploy_terminal_h((string) ($issue['message'] ?? ''));
            if ((string) ($issue['code'] ?? '') !== '') {
                $html .= ' <code>' . deploy_terminal_h((string) $issue['code']) . '</code>';
            }
            $html .= '</li>';
        }
        $html .= '</ul></td><td>';
        if ((string) ($row['action_url'] ?? '') !== '') {
            $html .= '<a href="' . deploy_terminal_h((string) $row['action_url']) . '">'
                . deploy_terminal_h(__t('deploy.network_open')) . '</a>';
        }
        $html .= '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}

/** @param list<array<string,mixed>> $findings */
function deploy_terminal_retry_has_network_blocker(array $findings): bool
{
    foreach ($findings as $finding) {
        if ((string) ($finding['kind'] ?? '') === 'network' && !empty($finding['blocking'])) {
            return true;
        }
    }
    return false;
}

function deploy_terminal_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
