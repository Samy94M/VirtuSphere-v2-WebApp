<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/**
 * What a reap is allowed to say.
 *
 * The message the reaper writes is the operator's entire account of why a job
 * ended, so it must contain observations and nothing else. Two versions of it
 * failed that test: "no heartbeat for 600 seconds" named the mechanism that
 * noticed rather than the event, and the sentence added later went further and
 * asserted a cause - "the deploy service is reporting, so it did not die" or
 * "it stopped reporting as well, so it is down" - from a singleton status row
 * that establishes neither. A restart writes a fresh row, so a reporting
 * service does not show that the process holding THIS job survived, and a
 * silent one does not show that it died. Each version implied an instruction
 * ("restart the service" / "do not") that was as likely wrong as right.
 *
 * What the transaction can actually see is four things, and this pins that they
 * are all present and that no causal claim joins them.
 */
final class DeployJobReapObservationTest extends TestCase
{
    public function testItNamesJobLockHeartbeatAgeLimitAndTransition(): void
    {
        $observation = deploy_job_reap_observation(77, 'deploy-host:412', 743, 600, 'running', 'failed');

        self::assertStringContainsString('Job 77:', $observation);
        self::assertStringContainsString('last heartbeat 743 s ago', $observation);
        self::assertStringContainsString('limit 600 s', $observation);
        self::assertStringContainsString('lock held by deploy-host:412', $observation);
        self::assertStringContainsString('running -> failed', $observation);
    }

    /**
     * A cancelling job converges to what the operator asked for, never to
     * failed: the wish was recorded and only the confirmation is missing
     * (ADR-0033). The sentence has to show that, or a converged cancellation
     * reads as a failure.
     */
    public function testACancellationConvergesToTheOperatorsWish(): void
    {
        $observation = deploy_job_reap_observation(12, 'deploy-host:1', 900, 600, 'cancelling', 'cancelled');

        self::assertStringContainsString('cancelling -> cancelled', $observation);
        self::assertStringNotContainsString('failed', $observation);
    }

    /**
     * A job that never beat at all is a different observation from one whose
     * beat is old, and "last heartbeat 0 s ago" would be a lie about a NULL.
     */
    public function testAJobThatNeverBeatSaysSoInsteadOfReportingZero(): void
    {
        $observation = deploy_job_reap_observation(5, 'deploy-host:1', null, 600, 'running', 'failed');

        self::assertStringContainsString('no heartbeat was ever written', $observation);
        self::assertStringNotContainsString('0 s ago', $observation);
    }

    /** A lock nobody holds is named, not rendered as an empty gap. */
    public function testAnEmptyLockIsNamed(): void
    {
        self::assertStringContainsString('lock held by nobody', deploy_job_reap_observation(5, '   ', 700, 600, 'running', 'failed'));
    }

    /**
     * The negative half, and the reason this function exists as its own unit:
     * every phrase below was in a shipped version of this message and each one
     * claimed something the code had not checked.
     */
    public function testItAssertsNoCause(): void
    {
        $observation = deploy_job_reap_observation(77, 'deploy-host:412', 743, 600, 'running', 'failed');

        foreach ([
            'did not die',
            'stopped reporting as well',
            'database outage',
            'the worker died',
            'restart',
            'may still be running',
        ] as $claim) {
            self::assertStringNotContainsString($claim, $observation, 'the reap message must not claim: ' . $claim);
        }
    }
}
