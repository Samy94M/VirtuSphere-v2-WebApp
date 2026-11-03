<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Every file below exists at HEAD; the K21-F symbols are used inside test bodies only,
// never in class constants or data providers, so one red test cannot abort the suite.
require_once dirname(__DIR__, 2) . '/lib/deploy_remote_secret.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_test_config.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_full_test.php';

/**
 * K21-F: the sweep of a passed Volltest must end before the time limit of the
 * path that runs it. Two limits exist:
 *   scheduled  600 s  the alarm of the scheduled worker (VIRTUSPHERE_ANSIBLE_TEST_TOTAL_TIMEOUT_SECONDS)
 *   manual     300 s  the read timeout of every PHP request (Docker/nginx/default.conf)
 * An alarm or a read timeout during the sweep would end the test before its
 * evidence is stored.
 *
 * The bound is derived from four numbers, all in the code:
 *   LIMIT     600 or 300 s  see above
 *   OVERRUN    45 s         one removal past its deadline: connect 15 s + command timeout 30 s
 *   MARGIN     30 s         store and flash after the sweep
 *   BUDGET     60 s         the most the sweep starts candidates in
 *
 * The deadline is elapsed-aware: budget = max(0, min(BUDGET, LIMIT - elapsed - OVERRUN - MARGIN)).
 * The data providers carry literal numbers on purpose (see the header).
 */
final class AnsibleFullTestSweepBudgetTest extends TestCase
{
    /** One removal past its deadline: connect plus the removal's command timeout (K21 lease worst case). */
    private static function overrun(): int
    {
        return VIRTUSPHERE_REMOTE_SECRET_CONNECT_SECONDS + VIRTUSPHERE_DEPLOY_REMOTE_CLEANUP_TIMEOUT_SECONDS;
    }

    public function testTheNumbersLeaveRoomForTheStoreInsideBothLimits(): void
    {
        self::assertSame(45, self::overrun(), 'connect 15 s plus the removal timeout 30 s');
        self::assertSame(600, VIRTUSPHERE_ANSIBLE_TEST_TOTAL_TIMEOUT_SECONDS, 'the scheduled alarm');
        self::assertSame(300, VIRTUSPHERE_ANSIBLE_TEST_WEB_LIMIT_SECONDS, 'the portal read timeout');
        $oneSweep = VIRTUSPHERE_REMOTE_SECRET_TEST_SWEEP_BUDGET_SECONDS + self::overrun() + VIRTUSPHERE_REMOTE_SECRET_TEST_SWEEP_MARGIN_SECONDS;
        self::assertLessThan(300, $oneSweep, 'a full budget, one overrun and the margin must fit even the portal limit');
    }

    /** @return iterable<string, array{int, float, float}> limit, elapsed seconds, budget seconds */
    public static function budgets(): iterable
    {
        yield 'scheduled: a short test gets the constant' => [600, 0.0, 60.0];
        yield 'scheduled: the last elapsed time with the full budget' => [600, 465.0, 60.0];
        yield 'scheduled: a test that used most of the alarm gets the rest' => [600, 500.0, 25.0];
        yield 'scheduled: the margin is reached, no candidate starts' => [600, 525.0, 0.0];
        yield 'scheduled: beyond the budget gets nothing, never a negative one' => [600, 600.0, 0.0];
        yield 'manual: a short test gets the constant' => [300, 0.0, 60.0];
        yield 'manual: the last elapsed time with the full budget' => [300, 165.0, 60.0];
        yield 'manual: a slow test gets the rest before the read timeout' => [300, 200.0, 25.0];
        yield 'manual: the margin is reached, no candidate starts' => [300, 225.0, 0.0];
        yield 'manual: beyond the budget gets nothing' => [300, 300.0, 0.0];
    }

    #[DataProvider('budgets')]
    public function testTheBudgetIsTheConstantOrWhatRemainsBeforeTheLimit(int $limit, float $elapsed, float $budget): void
    {
        $startedAt = 1000.0;
        $deadline = ansible_full_test_sweep_deadline($startedAt, $startedAt + $elapsed, $limit);

        self::assertEqualsWithDelta($budget, $deadline - ($startedAt + $elapsed), 0.0001);
    }

    public function testTheManualDefaultIsTheWebLimit(): void
    {
        $startedAt = 1000.0;

        self::assertEqualsWithDelta(
            ansible_full_test_sweep_deadline($startedAt, $startedAt + 200.0, VIRTUSPHERE_ANSIBLE_TEST_WEB_LIMIT_SECONDS),
            ansible_full_test_sweep_deadline($startedAt, $startedAt + 200.0),
            0.0001,
            'without a limit the manual path is bounded by the portal request, not by the scheduled alarm'
        );
    }

    /** @return iterable<string, array{int}> the two limits, written out (the constants of the owner are K21-F) */
    public static function limits(): iterable
    {
        yield 'manual portal request' => [300];
        yield 'scheduled worker' => [600];
    }

    /**
     * The property that matters: for any elapsed time up to the limit minus the
     * margin, the sweep is over by LIMIT - MARGIN. A candidate started before the
     * deadline ends at most OVERRUN later; without a budget nothing starts and the
     * test ends at once.
     */
    #[DataProvider('limits')]
    public function testTheSweepIsOverBeforeTheMarginForEveryElapsedTime(int $limit): void
    {
        $bound = $limit - VIRTUSPHERE_REMOTE_SECRET_TEST_SWEEP_MARGIN_SECONDS;
        $startedAt = 1000.0;
        for ($elapsed = 0.0; $elapsed <= $bound; $elapsed += 0.5) {
            $deadline = ansible_full_test_sweep_deadline($startedAt, $startedAt + $elapsed, $limit);
            $budget = $deadline - ($startedAt + $elapsed);
            $end = $budget > 0.0 ? $deadline + self::overrun() : $startedAt + $elapsed;

            self::assertLessThanOrEqual(
                $startedAt + $bound + 1e-6,
                $end,
                'a sweep started at elapsed ' . $elapsed . ' s with limit ' . $limit . ' would end after the margin'
            );
        }
    }

    public function testNoBudgetMeansTheDeadlineIsNotAfterNow(): void
    {
        $startedAt = 1000.0;
        $now = $startedAt + 600.0;

        self::assertLessThanOrEqual($now, ansible_full_test_sweep_deadline($startedAt, $now, VIRTUSPHERE_ANSIBLE_TEST_TOTAL_TIMEOUT_SECONDS));
    }
}
