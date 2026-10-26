<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout_response.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_terminal_presenter.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_retry_confirmation.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_outcome_audit.php';
require_once dirname(__DIR__, 2) . '/lib/portal_status_display.php';

/**
 * WK-04/WK-07 A: the terminal status `outcome_unknown` that the stale-job reaper
 * writes for a silent running mission job. Each surface that reads the status
 * says "unknown", never "failed" and never "success".
 */
final class DeployOutcomeUnknownTest extends TestCase
{
    protected function tearDown(): void
    {
        Lang::load(Lang::DEFAULT_LOCALE);
    }

    public function testItIsTerminalNotActiveAndOnlyStaleHeartbeatExplainsIt(): void
    {
        self::assertContains(VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN, VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES);
        self::assertNotContains(VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN, VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES);
        $reasons = [];
        foreach (VIRTUSPHERE_DEPLOY_TERMINAL_REASON_STATUSES as $code => $statuses) {
            if (in_array(VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN, $statuses, true)) {
                $reasons[] = $code;
            }
        }
        self::assertSame([VIRTUSPHERE_DEPLOY_TERMINAL_REASON_STALE_HEARTBEAT], $reasons);
        // Rows reaped before 0065 keep failed/stale_heartbeat (no backfill).
        deploy_terminal_reason_assert(VIRTUSPHERE_DEPLOY_STATUS_FAILED, VIRTUSPHERE_DEPLOY_TERMINAL_REASON_STALE_HEARTBEAT);
    }

    /** Derived over every terminal status: only succeeded is a success, unknown is a warning. */
    public function testTheAuditResultIsAWarningAndNoOtherTerminalStatusBecomesSuccess(): void
    {
        foreach (VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES as $status) {
            $result = deploy_worker_outcome_audit_result($status);
            if ($status === VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED) {
                self::assertSame(VIRTUSPHERE_AUDIT_RESULT_SUCCESS, $result);
            } else {
                self::assertNotSame(VIRTUSPHERE_AUDIT_RESULT_SUCCESS, $result, $status);
            }
        }
        self::assertSame(VIRTUSPHERE_AUDIT_RESULT_WARNING, deploy_worker_outcome_audit_result(VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN));
    }

    public function testThePresenterSaysUnknownAndKeepsTheDiagnosisInTheReason(): void
    {
        $detail = 'Reaped stale deploy job. Job 9: last heartbeat 700 s ago, limit 600 s; lock held by w; running -> outcome_unknown. Remote outcome unknown: the reaper cannot see whether the remote run finished, so its exit code is not known.';
        foreach (['de', 'en'] as $locale) {
            Lang::load($locale);
            $view = deploy_terminal_presenter([
                'id' => 9,
                'status' => VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN,
                'last_error' => $detail,
                'terminal_reason_code' => VIRTUSPHERE_DEPLOY_TERMINAL_REASON_STALE_HEARTBEAT,
                'terminal_reason_detail' => $detail,
                'payload_json' => '{"mode":"full","vm_ids":[]}',
            ]);

            self::assertIsArray($view['result']);
            self::assertSame(__t('deploy.result_outcome_unknown'), $view['result']['text']);
            self::assertNotSame(__t('deploy.result_failed'), $view['result']['text']);
            self::assertIsArray($view['reason']);
            self::assertSame(__t('deploy.terminal_reason_stale_heartbeat'), $view['reason']['text']);
            self::assertSame($detail, $view['reason']['detail']);
            // "Last error" belongs to a job that failed; the diagnosis stays in the reason.
            self::assertNull($view['last_error']);
        }
    }

    public function testTheRetryConfirmationWarnsFirstAndKeepsThePlanQuestion(): void
    {
        $finding = ['kind' => 'external', 'code' => 'retry_remote_outcome_unknown', 'blocking' => false];
        foreach (['de', 'en'] as $locale) {
            Lang::load($locale);
            $plain = ['plan' => null, 'effective_mode' => 'full', 'scope_vm_ids' => [], 'findings' => [$finding]];
            self::assertSame(
                __t('deploy.confirm_retry_outcome_unknown') . ' ' . __t('deploy.confirm_retry', ['name' => 'M']),
                deploy_retry_confirmation($plain, 'M')
            );
            $create = ['plan' => ['scope' => 'create_units'], 'effective_mode' => 'full', 'scope_vm_ids' => [1], 'findings' => [$finding]];
            self::assertSame(
                __t('deploy.confirm_retry_outcome_unknown') . ' ' . __t('deploy.confirm_retry_create', ['name' => 'M']),
                deploy_retry_confirmation($create, 'M')
            );
            // Without the finding nothing changes.
            $plain['findings'] = [];
            self::assertSame(__t('deploy.confirm_retry', ['name' => 'M']), deploy_retry_confirmation($plain, 'M'));
        }
    }

    public function testTheVmFindingDoesNotCallAnUnknownOutcomeAFailedJob(): void
    {
        foreach (['de', 'en'] as $locale) {
            Lang::load($locale);
            $params = ['job_id' => 9, 'mode' => 'full', 'time' => '2026-10-26 10:00:00'];
            $failed = portal_vm_finding_text(['kind' => VIRTUSPHERE_VM_FINDING_JOB_FAILED, 'params' => $params]);
            $unknown = portal_vm_finding_text(['kind' => VIRTUSPHERE_VM_FINDING_JOB_FAILED, 'params' => $params + ['outcome_unknown' => true]]);
            self::assertNotSame($failed, $unknown);
            self::assertStringStartsWith(explode(':', __t('status.finding_job_outcome_unknown'))[0], $unknown);
        }
    }
}
