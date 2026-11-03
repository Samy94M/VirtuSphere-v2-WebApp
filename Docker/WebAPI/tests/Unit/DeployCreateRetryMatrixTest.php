<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_create_result.php';

/**
 * The retry matrix of a create-tracked job, without a database (Etappe 14B,
 * Teiletappe F; plan section 10.2).
 *
 * The property that matters is not that a retry runs. It is that a retry never
 * creates a second VM for one that is already there, and never starts while the
 * first attempt may still be running on the host. Both are decisions about
 * rows, so both are provable here.
 */
final class DeployCreateRetryMatrixTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function rows(array $statuses): array
    {
        $rows = [];
        $position = 0;
        foreach ($statuses as $status) {
            $position++;
            $rows[] = [
                'id' => 500 + $position,
                'job_id' => 9,
                'vm_id' => 100 + $position,
                'vm_name' => 'VM' . $position,
                'position' => $position,
                'total' => count($statuses),
                'action' => VIRTUSPHERE_CREATE_ACTION_CREATE,
                'status' => $status,
            ];
        }

        return $rows;
    }

    public function testAConfirmedSuccessIsVerifiedAndEverythingElseIsCreatedAgain(): void
    {
        $plan = deploy_create_retry_plan($this->rows([
            VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING,
        ]));

        self::assertFalse($plan['blocked']);
        // A skip counts as proven too: the unit before it verified the same VM
        // live, so re-creating it would be the second VM this exists to prevent.
        self::assertSame([1, 2], array_column($plan['verify'], 'position'));
        self::assertSame([3, 4], array_column($plan['create'], 'position'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockingStatuses')]
    public function testAnUnresolvedOrRunningUnitBlocksTheWholeRetry(string $status): void
    {
        $plan = deploy_create_retry_plan($this->rows([
            VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
            $status,
            VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING,
        ]));

        // Not "retry the rest": the async job of position 2 may be alive on the
        // Ansible host, and a retry that runs beside it is how one VM becomes
        // two. The whole retry waits for that outcome to be established.
        self::assertTrue($plan['blocked']);
        self::assertSame([2], $plan['blocking_positions']);
    }

    /** @return array<string, array{string}> */
    public static function blockingStatuses(): array
    {
        return [
            'prepared' => [VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED],
            'running' => [VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING],
            'uncertain' => [VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN],
        ];
    }

    public function testTheRetryScopeIsTheWholeOriginalSelection(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/repo/deploy_job_retry.php';
        $rows = $this->rows([
            VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
        ]);

        // Plan 10.3: not the failed one. A full pipeline has to power-cycle,
        // export and start every VM it was asked for, including the two the
        // first job already created.
        self::assertSame([101, 102, 103], deploy_create_retry_vm_ids($rows));
    }

    public function testEveryReleaseBlockerHasATextInBothCatalogs(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/deploy_create_release.php';
        foreach (['de', 'en'] as $locale) {
            $catalog = require dirname(__DIR__, 2) . '/lang/' . $locale . '/deploy.php';
            foreach (VIRTUSPHERE_CREATE_RELEASE_BLOCKERS as $blocker) {
                // A blocker the page cannot name is a dead end: the operator is
                // told the release is impossible and not what would change that.
                self::assertArrayHasKey(
                    'create_release_blocker_' . $blocker,
                    $catalog,
                    $locale . ' has no text for the release blocker ' . $blocker
                );
            }
        }
    }
}
