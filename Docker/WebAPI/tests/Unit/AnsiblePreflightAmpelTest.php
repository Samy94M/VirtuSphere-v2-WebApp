<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/status.php';

/**
 * Traffic-light matrix for ansible_preflight_ampel. The preflight has no
 * scheduler, so a passing result ages out into 'stale' instead of staying green
 * forever next to a months-old timestamp. $now is injected so the age branch is
 * deterministic.
 */
final class AnsiblePreflightAmpelTest extends TestCase
{
    private const NOW = '2026-07-24 12:00:00';

    private function now(): int
    {
        return (int) strtotime(self::NOW . ' UTC');
    }

    /** Timestamp $days days before NOW, in the stored (UTC, no zone) format. */
    private function daysAgo(float $days): string
    {
        return gmdate('Y-m-d H:i:s', $this->now() - (int) round($days * 86400));
    }

    /** @param array<string, mixed> $overrides */
    private function state(array $overrides = []): array
    {
        return $overrides + [
            'last_status' => 'ok',
            'last_checked_at' => self::NOW,
            'last_component' => null,
        ];
    }

    public function testNoRowOrEmptyStatusIsUnknown(): void
    {
        self::assertSame('unknown', ansible_preflight_ampel(null, $this->now()));
        self::assertSame('unknown', ansible_preflight_ampel($this->state(['last_status' => '']), $this->now()));
    }

    public function testFreshResultsKeepTheirOwnState(): void
    {
        self::assertSame('ok', ansible_preflight_ampel($this->state(), $this->now()));
        self::assertSame('warning', ansible_preflight_ampel($this->state(['last_status' => 'warning']), $this->now()));
        self::assertSame('danger', ansible_preflight_ampel($this->state(['last_status' => 'failed']), $this->now()));
    }

    public function testPassingResultAgesOutIntoStale(): void
    {
        $old = $this->daysAgo(VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS + 1);
        self::assertSame('stale', ansible_preflight_ampel($this->state(['last_checked_at' => $old]), $this->now()));
        self::assertSame(
            'stale',
            ansible_preflight_ampel($this->state(['last_status' => 'warning', 'last_checked_at' => $old]), $this->now()),
            'a restricted result is evidence too, and expires like any other'
        );
    }

    public function testAFailureNeverAgesIntoGrey(): void
    {
        // Greying out a known break would hide it. Red stays red until someone
        // re-tests and proves otherwise.
        self::assertSame(
            'danger',
            ansible_preflight_ampel(
                $this->state(['last_status' => 'failed', 'last_checked_at' => $this->daysAgo(400)]),
                $this->now()
            )
        );
    }

    public function testTheWindowIsInclusiveOfItsLastSecond(): void
    {
        // Pinned on the exact boundary, not near it: an off-by-one here flips a
        // green badge to grey a whole day early, and "roughly a week" is not a
        // claim the help text is allowed to make loosely.
        $window = VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS * 86400;
        $onTheEdge = gmdate('Y-m-d H:i:s', $this->now() - $window);
        $oneSecondPast = gmdate('Y-m-d H:i:s', $this->now() - $window - 1);

        self::assertSame(
            'ok',
            ansible_preflight_ampel($this->state(['last_checked_at' => $onTheEdge]), $this->now()),
            'exactly the window old still counts as evidence'
        );
        self::assertSame(
            'stale',
            ansible_preflight_ampel($this->state(['last_checked_at' => $oneSecondPast]), $this->now()),
            'one second past the window is stale'
        );
    }

    public function testAnUnreadableTimestampKeepsTheRecordedState(): void
    {
        // Never invent an age: a row whose timestamp cannot be parsed still has
        // a status the operator recorded, and guessing 'stale' would drop it.
        self::assertSame('ok', ansible_preflight_ampel($this->state(['last_checked_at' => '']), $this->now()));
        self::assertSame('ok', ansible_preflight_ampel($this->state(['last_checked_at' => 'not a date']), $this->now()));
    }

    public function testStaleRanksWithUnknownRatherThanWithOk(): void
    {
        // A roll-up must not report an expired green as healthy.
        self::assertGreaterThan(
            virtusphere_heartbeat_state_rank('ok'),
            virtusphere_heartbeat_state_rank('stale')
        );
        self::assertSame(
            virtusphere_heartbeat_state_rank('unknown'),
            virtusphere_heartbeat_state_rank('stale')
        );
        self::assertLessThan(
            virtusphere_heartbeat_state_rank('warning'),
            virtusphere_heartbeat_state_rank('stale')
        );
    }

    public function testStaleIsGreyNotYellow(): void
    {
        self::assertSame('neutral', virtusphere_heartbeat_meta('stale')['badge']);
    }

    public function testEveryStateTheFunctionCanReturnIsInTheAmpelConstant(): void
    {
        // VIRTUSPHERE_ANSIBLE_AMPEL_STATES drives the legend and the badge
        // labels. A state this function returns but the constant does not list
        // would render through the fallback label of a *different* state and
        // have no legend entry, which is silent rather than wrong-looking.
        $returned = [
            ansible_preflight_ampel(null, $this->now()),
            ansible_preflight_ampel($this->state(), $this->now()),
            ansible_preflight_ampel($this->state(['last_status' => 'warning']), $this->now()),
            ansible_preflight_ampel($this->state(['last_status' => 'failed']), $this->now()),
            ansible_preflight_ampel($this->state(['last_checked_at' => $this->daysAgo(400)]), $this->now()),
        ];
        foreach ($returned as $state) {
            self::assertContains($state, VIRTUSPHERE_ANSIBLE_AMPEL_STATES, 'unlisted Ampel state: ' . $state);
        }
        // Sets, not sequences: the constant's order is display order (healthy
        // to broken) while these are reached in call order. Compared both ways,
        // so a legend entry for a state that can never appear fails too.
        $reachable = array_unique($returned);
        $listed = VIRTUSPHERE_ANSIBLE_AMPEL_STATES;
        sort($reachable);
        sort($listed);
        self::assertSame($listed, $reachable, 'the constant lists a state the function never returns, or misses one it does');
    }
}
