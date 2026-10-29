<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_log_outcome.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_log_panels.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_log_view.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase_cells.php';

/**
 * The readable job log (E1/E2/B1/B8, 29.10.2026), proved on the shape of prod
 * job 1527: fifteen VMs, fourteen created, VM 13 refused by ESXi with
 * "503 Service Unavailable" after four attempts.
 */
final class DeployLogDisplayTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function job1527Rows(?int $fromHost = 1): array
    {
        $rows = [];
        for ($position = 1; $position <= 15; $position++) {
            $failed = $position === 13;
            $rows[] = [
                'position' => $position,
                'total' => 15,
                'vm_id' => 600 + $position,
                'vm_name' => 'VM-0' . (6000 + $position),
                'attempt' => $failed ? 4 : 1,
                'status' => $failed ? VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED : VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
                'outcome' => $failed ? null : 'created',
                'error_code' => $failed ? VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED : null,
                'error_detail' => $failed ? '503 Service Unavailable' : null,
                'error_detail_from_host' => $failed ? $fromHost : null,
                'first_error_code' => $failed ? VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED : null,
                'first_error_detail' => $failed ? '503 Service Unavailable: [Errno 104] Connection reset by peer' : null,
                'started_at' => '2026-10-29 10:36:25',
            ];
        }

        return $rows;
    }

    public function testTheCounterSaysWhatExistsAfterTheRunAndWhatWasProcessedDuringIt(): void
    {
        $view = deploy_create_progress_from_rows($this->job1527Rows());
        self::assertNotNull($view);

        self::assertSame(
            __t('deploy_log.position_created', ['created' => 14, 'total' => 15]) . ', ' . __t('deploy_log.position_part_failed', ['count' => 1]),
            deploy_create_progress_position_label($view, true)
        );
        self::assertSame(
            __t('deploy_log.position_running', ['processed' => 15, 'total' => 15]),
            deploy_create_progress_position_label($view, false)
        );
        // After the end the result block names the VM; the card keeps its numbers.
        self::assertSame([], deploy_create_progress_payload_from_view($view, 0, null, true)['findings']);
        self::assertCount(1, deploy_create_progress_payload_from_view($view, 0, null, false)['findings']);
    }

    public function testTheHostLabelFollowsTheRecordedOriginNeverTheText(): void
    {
        $byOrigin = function (?int $fromHost): array {
            $rows = $this->job1527Rows($fromHost);

            return deploy_create_progress_finding($rows[12]);
        };

        $host = $byOrigin(1);
        self::assertSame(__t('deploy.create_progress_host_detail'), deploy_create_progress_host_label($host));
        self::assertSame('503 Service Unavailable', $host['host_detail']);

        $fallback = $byOrigin(0);
        self::assertSame(__t('deploy_log.host_no_message'), deploy_create_progress_host_label($fallback));
        self::assertNull($fallback['host_detail'], 'a fallback is never shown as the host message');
        $technical = deploy_log_technical_details_html([], [$fallback], false);
        self::assertStringContainsString(h(__t('deploy_log.host_no_message')) . '</span> </p>', $technical, 'the fallback label stands alone');
        self::assertStringContainsString(h(__t('deploy_log.stored_detail')), $technical, 'the stored fallback text stays readable as stored text');

        $legacy = $byOrigin(null);
        self::assertSame(__t('deploy_log.stored_detail'), deploy_create_progress_host_label($legacy));
    }

    public function testAProtocolErrorShowsTheAnsibleFailureLineItsControlCallPrinted(): void
    {
        $row = [
            'error_code' => VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR,
            'error_detail' => 'The control call ended without a create marker. (control call exit code 2)'
                . VIRTUSPHERE_CREATE_CONTROL_CAUSE_PREFIX . 'TASK [Read the VM] fatal: [localhost]: 503 Service Unavailable',
            'error_detail_from_host' => null,
        ] + $this->job1527Rows()[12];
        $finding = deploy_create_progress_finding($row);

        self::assertSame(__t('deploy_log.ansible_cause'), deploy_create_progress_host_label($finding));
        self::assertSame('TASK [Read the VM] fatal: [localhost]: 503 Service Unavailable', $finding['host_detail']);

        $bare = deploy_create_progress_finding(['error_detail' => 'The marker describes another VM or another async job. (control call exit code 0)'] + $row);
        self::assertNull(deploy_create_progress_host_label($bare), 'without a printed cause there is no line to quote');
        self::assertStringNotContainsString(h(__t('deploy_log.ansible_cause')), deploy_log_technical_details_html([], [$bare], false));
        $technical = deploy_log_technical_details_html([], [$finding], false);
        self::assertStringContainsString(h(__t('deploy_log.ansible_cause')) . '</span> <code class="log-line">TASK [Read the VM]', $technical);
        self::assertStringContainsString(h(__t('deploy_log.stored_detail')) . '</span> <code class="log-line">The control call ended', $technical);
    }

    public function testTheResultBlockNamesTheVmItsCauseAndItsPlaceInTheLog(): void
    {
        $view = deploy_create_progress_from_rows($this->job1527Rows());
        self::assertNotNull($view);
        $html = deploy_log_create_outcome_html(1527, $view['findings'], true);

        self::assertStringContainsString(h(deploy_create_progress_finding_text($view['findings'][0])), $html);
        // The raw words of the host sit only in the technical details (user
        // decision 29.10.2026): on the first look they read as another problem.
        self::assertStringNotContainsString('503 Service Unavailable', $html);
        self::assertStringNotContainsString(h(__t('deploy.create_progress_host_detail')), $html);
        self::assertStringContainsString(h(deploy_log_filter_url(1527, [
            'q' => '[13/15] FAIL create', 'source' => VIRTUSPHERE_DEPLOY_LOG_SYSTEM, 'phase' => '', 'errors' => false, 'active' => true,
        ])), $html);
        self::assertStringContainsString('#' . VIRTUSPHERE_DEPLOY_LOG_FILTER_ANCHOR . '"', $html, 'the jump lands on the hit count, not on top of the page');
        ob_start();
        try {
            deploy_log_render_filter(1527, deploy_log_filter_from_query([], []), [], 0, false);
        } finally {
            $panel = (string) ob_get_clean();
        }
        self::assertStringContainsString('id="' . VIRTUSPHERE_DEPLOY_LOG_FILTER_ANCHOR . '"', $panel, 'the jump target exists');
        self::assertStringContainsString('action="deploy_log.php#' . VIRTUSPHERE_DEPLOY_LOG_FILTER_ANCHOR . '"', $panel, 'filtering lands there too');
        self::assertStringContainsString(h(__t('deploy_log.retry_creates_missing', ['action' => __t('deploy.retry')])), $html);
        self::assertStringNotContainsString(
            h(__t('deploy_log.retry_creates_missing', ['action' => __t('deploy.retry')])),
            deploy_log_create_outcome_html(1527, $view['findings'], false),
            'the action is named only while it is offered'
        );
        self::assertStringContainsString(
            (string) VIRTUSPHERE_CREATE_MAX_ATTEMPTS,
            deploy_create_progress_finding_text($view['findings'][0])
        );
    }

    public function testTechnicalDetailsHoldCodesStoredTextsTheFirstAttemptAndTheTrace(): void
    {
        $view = deploy_create_progress_from_rows($this->job1527Rows());
        self::assertNotNull($view);
        $job = [
            'status' => VIRTUSPHERE_DEPLOY_STATUS_PARTIAL,
            'correlation_id' => '1d9bb5277aee27aa',
            'terminal_reason_code' => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_PARTIAL_RESULT,
            'terminal_reason_detail' => 'The per-VM create section ended without creating every VM.',
        ];
        $html = deploy_log_technical_details_html($job, $view['findings'], false);

        self::assertStringStartsWith('<section class="panel"><details class="technical-details" data-deploy-technical>', $html);
        self::assertStringNotContainsString(' open', $html, 'collapsed for every reader');
        self::assertStringContainsString(h(__t('deploy.create_progress_host_detail')) . '</span> <code class="log-line">503 Service Unavailable</code>', $html);
        self::assertSame(1, substr_count($html, '>503 Service Unavailable<'), 'the host text once, not again as stored text');
        foreach (['1d9bb5277aee27aa', 'partial_result', 'The per-VM create section', 'module_failed', 'Connection reset by peer'] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        self::assertStringNotContainsString('logs.php', $html, 'the trace link needs users.manage');
        self::assertStringContainsString('logs.php', deploy_log_technical_details_html($job, [], true));
    }

    public function testARegularEndAddsNoReasonPanelButAFailureKeepsIt(): void
    {
        $job = [
            'id' => 1527,
            'mission_id' => 19,
            'status' => VIRTUSPHERE_DEPLOY_STATUS_PARTIAL,
            'terminal_reason_code' => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_PARTIAL_RESULT,
            'terminal_reason_detail' => 'The per-VM create section ended without creating every VM.',
            'payload_json' => '{}',
        ];
        $partial = deploy_terminal_blocks_html($job, null, null, false, true, '<ul data-deploy-create-outcome></ul>');
        self::assertStringNotContainsString(h(__t('deploy.terminal_reason_heading')), $partial);
        self::assertStringContainsString('<ul data-deploy-create-outcome></ul>', $partial);
        self::assertStringNotContainsString('The per-VM create section', $partial, 'the English detail lives in the technical details');

        $failed = deploy_terminal_blocks_html([
            'status' => VIRTUSPHERE_DEPLOY_STATUS_FAILED,
            'terminal_reason_code' => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_EXECUTION_FAILED,
        ] + $job);
        self::assertStringContainsString(h(__t('deploy.terminal_reason_heading')), $failed);
    }

    public function testAFinishedJobNeverShowsARunningStepAndNamesTheStep(): void
    {
        $timeline = deploy_log_phase_timeline([
            ['seq' => 79, 'line' => ansible_step_marker_line(VIRTUSPHERE_ANSIBLE_STEP_BEGIN, VIRTUSPHERE_PLAYBOOKS['create']), 'created_at' => '2026-10-29 10:36:17'],
        ]);

        $running = deploy_log_phases_html($timeline, false);
        $finished = deploy_log_phases_html($timeline, true);
        self::assertStringContainsString(h(__t('deploy.phase_current')), $running);
        self::assertStringNotContainsString(h(__t('deploy.phase_current')), $finished);
        self::assertStringContainsString(h(__t('deploy.phase_incomplete')), $finished);
        self::assertStringContainsString(h(__t('help_deploy_figures.step_create')), $finished);
        self::assertStringNotContainsString(VIRTUSPHERE_PLAYBOOKS['create'], $finished, 'a person reads the step, not the file');
        self::assertSame('inventoryESXi_playbook.yml', deploy_log_phase_label('inventoryESXi_playbook.yml'));
    }

    public function testErrorLinesAreClassifiedOnceAndCaseSensitively(): void
    {
        self::assertSame(VIRTUSPHERE_DEPLOY_LOG_SEVERITY_FAIL, deploy_log_line_severity('[13/15] FAIL create VM-06572 module_failed: 503 Service Unavailable'));
        self::assertSame(VIRTUSPHERE_DEPLOY_LOG_SEVERITY_HOLD, deploy_log_line_severity('[2/4] HOLD create VM-2 uncertain transport_lost'));
        self::assertSame(VIRTUSPHERE_DEPLOY_LOG_SEVERITY_FATAL, deploy_log_line_severity('fatal: [localhost]: FAILED! => {"msg": "x"}'));
        foreach (['[13/15] RETRY create VM-06572 module_failed; attempt 2 of 4 in 5 s', '[1/2] fail create VM-1 x', 'Create summary: failed=1'] as $ordinary) {
            self::assertNull(deploy_log_line_severity($ordinary), $ordinary);
        }
        self::assertSame('[13/15] FAIL create', deploy_log_create_unit_needle(13, 15, VIRTUSPHERE_DEPLOY_LOG_SEVERITY_FAIL));

        $filter = deploy_log_filter_from_query(['errors' => '1'], []);
        self::assertTrue($filter['active']);
        self::assertSame(array_values(VIRTUSPHERE_DEPLOY_LOG_SEVERITY_MARKERS), deploy_log_filter_repo_args($filter, ['phases' => [], 'current' => null])['any_of']);
        self::assertStringContainsString('errors=1', deploy_log_filter_url(1527, $filter));
        self::assertFalse(deploy_log_filter_from_query(['errors' => ['1']], [])['errors']);
    }

    public function testAVmWhoseCreateFailedLinksToItsFailLines(): void
    {
        $vm = ['id' => 613, 'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_FAILED, 'mecm_sync_state' => 'failed', 'vm_status' => '1'];
        $evidence = [
            'last_job' => ['id' => 1527, 'status' => VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, 'mode' => 'full', 'updated_at' => '2026-10-29 11:52:04'],
            'create_failed' => ['job_id' => 1527, 'position' => 13, 'total' => 15],
        ];
        $findings = virtusphere_vm_findings($vm, $evidence, strtotime('2026-10-29 12:00:00 UTC'));
        $jobFailed = array_values(array_filter($findings, static fn (array $f): bool => $f['kind'] === VIRTUSPHERE_VM_FINDING_JOB_FAILED))[0];

        self::assertTrue($jobFailed['params']['create_failed']);
        self::assertStringStartsWith(explode(':', __t('status.finding_create_failed'))[0], portal_vm_finding_text($jobFailed));
        self::assertStringContainsString(urlencode('[13/15] FAIL create'), vm_finding_href($jobFailed, 19, 613, []));

        $other = virtusphere_vm_findings($vm, ['create_failed' => null] + $evidence, strtotime('2026-10-29 12:00:00 UTC'));
        $plain = array_values(array_filter($other, static fn (array $f): bool => $f['kind'] === VIRTUSPHERE_VM_FINDING_JOB_FAILED))[0];
        self::assertSame(deploy_job_log_url(1527), vm_finding_href($plain, 19, 613, []));
    }
}
