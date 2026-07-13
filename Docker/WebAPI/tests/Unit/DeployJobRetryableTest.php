<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/**
 * deploy_job_is_retryable() gates both the retry button on deploy.php and the
 * repo-side check in repo_retry_deploy_job(), so button visibility and the
 * POST handler cannot drift apart. Only failed/cancelled mission jobs qualify:
 * succeeded re-runs go through the start form (readiness preview), active jobs
 * are cancelled instead, and mission-less system jobs (ESXi inventory pulls)
 * are scheduled by the worker, never retried by hand.
 */
final class DeployJobRetryableTest extends TestCase
{
    public function testOnlyFailedAndCancelledMissionJobsAreRetryable(): void
    {
        $expected = [
            VIRTUSPHERE_DEPLOY_STATUS_QUEUED => false,
            VIRTUSPHERE_DEPLOY_STATUS_RUNNING => false,
            VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED => false,
            VIRTUSPHERE_DEPLOY_STATUS_FAILED => true,
            VIRTUSPHERE_DEPLOY_STATUS_CANCELLED => true,
        ];
        foreach ($expected as $status => $retryable) {
            self::assertSame(
                $retryable,
                deploy_job_is_retryable((string) $status, 42),
                $status . ' with a mission must ' . ($retryable ? '' : 'not ') . 'be retryable'
            );
        }
    }

    public function testSystemJobsAreNeverRetryable(): void
    {
        foreach ([null, 0, -1] as $missionId) {
            self::assertFalse(
                deploy_job_is_retryable(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $missionId),
                'mission_id ' . var_export($missionId, true) . ' must never be retryable'
            );
        }
    }

    public function testUnknownStatusIsNotRetryable(): void
    {
        self::assertFalse(deploy_job_is_retryable('done', 42));
        self::assertFalse(deploy_job_is_retryable('', 42));
    }
}
