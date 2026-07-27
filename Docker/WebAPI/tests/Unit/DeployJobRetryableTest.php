<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/**
 * deploy_job_is_retryable() gates both the retry button on deploy.php and the
 * repo-side check in repo_retry_deploy_job(), so button visibility and the
 * POST handler cannot drift apart. Only failed/cancelled/partial mission jobs
 * qualify: succeeded re-runs go through the start form (readiness preview),
 * active jobs are cancelled instead, and mission-less system jobs (ESXi
 * inventory pulls) are scheduled by the worker, never retried by hand.
 *
 * deploy_job_retry_plan() decides WHAT the retry re-queues (AP1.8/L7): a
 * partial job becomes an export-only job for exactly its failed VMs, and when
 * the failed set is not trustworthy (divergence, missing/malformed result,
 * empty failed set) the export repeats the original selection. It must never
 * widen to the full deploy once an import has committed anything.
 */
final class DeployJobRetryableTest extends TestCase
{
    public function testOnlyFailedCancelledAndPartialMissionJobsAreRetryable(): void
    {
        $expected = [
            VIRTUSPHERE_DEPLOY_STATUS_QUEUED => false,
            VIRTUSPHERE_DEPLOY_STATUS_RUNNING => false,
            // Active, not terminal (ADR-0033): the wish is recorded but the
            // sequence may still run; a retry of it would be a second job.
            VIRTUSPHERE_DEPLOY_STATUS_CANCELLING => false,
            VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED => false,
            VIRTUSPHERE_DEPLOY_STATUS_FAILED => true,
            VIRTUSPHERE_DEPLOY_STATUS_CANCELLED => true,
            VIRTUSPHERE_DEPLOY_STATUS_PARTIAL => true,
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

    /** Matrix 7: a partial job retries only export, only the failed VMs. */
    public function testPartialJobWithTrustedResultRetriesExportForFailedVms(): void
    {
        $plan = deploy_job_retry_plan(
            VIRTUSPHERE_DEPLOY_STATUS_PARTIAL,
            $this->decoded('partial', successful: [1, 2], failed: [3]),
            []
        );

        self::assertSame(['mode' => 'export', 'vm_ids' => [3], 'scope' => 'failed_vms'], $plan);
    }

    /**
     * Divergence rule (L7) inside a partial job: without a trustworthy failed
     * set the export repeats the ORIGINAL selection, never the full deploy.
     */
    public function testPartialJobWithoutTrustedResultRepeatsOriginalSelectionAsExport(): void
    {
        $original = [1, 2, 3];
        foreach ([
            'missing result' => null,
            'diverging outcome' => $this->decoded('success', successful: [1, 2, 3], failed: []),
            'empty failed set' => $this->decoded('partial', successful: [1, 2, 3], failed: []),
        ] as $case => $result) {
            $plan = deploy_job_retry_plan(VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, $result, $original);
            self::assertSame(['mode' => 'export', 'vm_ids' => [1, 2, 3], 'scope' => 'original_selection'], $plan, $case);
        }
    }

    /** Matrix 16: job failed, stored outcome success (lost HTTP response). */
    public function testFailedJobWithCommittedImportRetriesExportForOriginalSelection(): void
    {
        foreach (['success', 'partial'] as $outcome) {
            $plan = deploy_job_retry_plan(
                VIRTUSPHERE_DEPLOY_STATUS_FAILED,
                $this->decoded($outcome, successful: [7], failed: $outcome === 'partial' ? [8] : []),
                [7, 8]
            );
            self::assertSame(['mode' => 'export', 'vm_ids' => [7, 8], 'scope' => 'original_selection'], $plan, $outcome);
        }
    }

    /** The pre-existing branch: plain failed/cancelled retries re-queue the old payload. */
    public function testPlainFailedAndCancelledJobsKeepTheOldPayload(): void
    {
        self::assertNull(deploy_job_retry_plan(VIRTUSPHERE_DEPLOY_STATUS_FAILED, null, [1]), 'failed without result');
        self::assertNull(
            deploy_job_retry_plan(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $this->decoded('failed', successful: [], failed: [1]), [1]),
            'failed with a wholly failed import diverges nowhere'
        );
        self::assertNull(
            deploy_job_retry_plan(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, $this->decoded('success', successful: [1], failed: []), [1]),
            'a cancellation makes no outcome claim, so there is nothing to diverge from'
        );
    }

    /** An empty original selection stays empty: export for the whole mission. */
    public function testWholeMissionSelectionStaysWholeMission(): void
    {
        $plan = deploy_job_retry_plan(VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, null, []);

        self::assertNotNull($plan);
        self::assertSame([], $plan['vm_ids']);
    }

    /** @return array{outcome:string, successful_vm_ids:list<int>, failed_vm_ids:list<int>, counts:array<string,int>} */
    private function decoded(string $outcome, array $successful, array $failed): array
    {
        return [
            'outcome' => $outcome,
            'successful_vm_ids' => $successful,
            'failed_vm_ids' => $failed,
            'counts' => [
                'expected_vms' => count($successful) + count($failed),
                'successful_vms' => count($successful),
                'failed_vms' => count($failed),
                'updated_interfaces' => count($successful),
            ],
        ];
    }
}
