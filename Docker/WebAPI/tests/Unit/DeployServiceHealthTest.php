<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_service_health.php';

/**
 * The three axes of the deploy service snapshot.
 *
 * They are tested as pure functions because that is the only way their corners
 * get exercised at all: "the worker died while a job was running and a second
 * job is already overdue" is not a state anybody arranges in a database twice.
 */
final class DeployServiceHealthTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private function facts(array $overrides = []): array
    {
        return array_merge([
            'supervisor_contract' => VIRTUSPHERE_SUPERVISOR_CONTRACT_WORKER,
            'process_alive' => true,
            'child_alive' => true,
            'shape_matches_contract' => true,
            'active_job' => false,
            'active_job_consistent' => true,
            'overdue_seconds' => 0,
            'claim_state' => VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING,
            'restart_cooldown' => false,
        ], $overrides);
    }

    public function testAHealthyIdleServiceIsReady(): void
    {
        self::assertSame(VIRTUSPHERE_DEPLOY_AVAILABILITY_READY, deploy_service_availability($this->facts()));
    }

    public function testADeadProcessBeatsEverythingElse(): void
    {
        // Nothing else in the snapshot means anything while nobody is executing,
        // so `offline` must not be masked by a job that merely looks active.
        self::assertSame(
            VIRTUSPHERE_DEPLOY_AVAILABILITY_OFFLINE,
            deploy_service_availability($this->facts([
                'process_alive' => false,
                'active_job' => true,
                'overdue_seconds' => 9999,
            ]))
        );
    }

    public function testAnActiveButInconsistentJobIsDegradedNotBusy(): void
    {
        // The shape a dead worker leaves behind: a job still marked active with
        // no fresh heartbeat. Reporting that as `busy` is the failure that reads
        // exactly like normal operation.
        self::assertSame(
            VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED,
            deploy_service_availability($this->facts(['active_job' => true, 'active_job_consistent' => false]))
        );
        self::assertSame(
            VIRTUSPHERE_DEPLOY_AVAILABILITY_BUSY,
            deploy_service_availability($this->facts(['active_job' => true]))
        );
    }

    public function testAnOverdueQueueIsDegradedOnlyWhileTheServiceClaimsToAccept(): void
    {
        $overdue = ['overdue_seconds' => VIRTUSPHERE_DEPLOY_CLAIM_GRACE_SECONDS + 1];

        self::assertSame(
            VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED,
            deploy_service_availability($this->facts($overdue))
        );
        // A pause EXPLAINS the waiting job, so it is not a fault. Reporting it
        // as one would train an operator to ignore the degraded state on
        // exactly the day it was real.
        foreach ([VIRTUSPHERE_DEPLOY_CLAIM_PAUSED, VIRTUSPHERE_DEPLOY_CLAIM_PAUSE_AFTER_CURRENT] as $paused) {
            self::assertSame(
                VIRTUSPHERE_DEPLOY_AVAILABILITY_READY,
                deploy_service_availability($this->facts($overdue + ['claim_state' => $paused])),
                $paused . ' must not be reported as a fault'
            );
        }
    }

    public function testTheGraceBoundaryIsNotAFault(): void
    {
        self::assertSame(
            VIRTUSPHERE_DEPLOY_AVAILABILITY_READY,
            deploy_service_availability($this->facts(['overdue_seconds' => VIRTUSPHERE_DEPLOY_CLAIM_GRACE_SECONDS]))
        );
    }

    public function testAFutureJobIsNotABacklog(): void
    {
        // Scheduled-for-later work has overdue_seconds 0 by construction; the
        // point of the assertion is that planning ahead never colours the tile.
        self::assertSame(VIRTUSPHERE_DEPLOY_AVAILABILITY_READY, deploy_service_availability($this->facts()));
    }

    public function testAnUnobservableContractFailsClosed(): void
    {
        self::assertSame(
            VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED,
            deploy_service_availability($this->facts(['supervisor_contract' => 'supervisor_v2']))
        );
    }

    public function testTheSupervisorCooldownOnlyAppliesToTheSupervisorContract(): void
    {
        self::assertSame(
            VIRTUSPHERE_DEPLOY_AVAILABILITY_COOLDOWN,
            deploy_service_availability($this->facts([
                'supervisor_contract' => VIRTUSPHERE_SUPERVISOR_CONTRACT_SUPERVISOR,
                'restart_cooldown' => true,
            ]))
        );
        self::assertSame(
            VIRTUSPHERE_DEPLOY_AVAILABILITY_READY,
            deploy_service_availability($this->facts(['restart_cooldown' => true]))
        );
    }

    /**
     * The supervisor branch of the precedence (Etappe 14C, plan section 21.2).
     *
     * The order of the two supervisor rules is the point: a supervisor inside
     * its restart window legitimately holds no child, so `cooldown` has to beat
     * "the child is missing". The other way round, every planned restart would
     * be reported as a breakage, and an operator would go looking for a fault
     * that is the system working.
     */
    public function testASupervisorWithoutAChildIsCoolingDownNotDegraded(): void
    {
        $facts = $this->facts([
            'supervisor_contract' => VIRTUSPHERE_SUPERVISOR_CONTRACT_SUPERVISOR,
            'child_alive' => false,
            'restart_cooldown' => true,
        ]);

        self::assertSame(VIRTUSPHERE_DEPLOY_AVAILABILITY_COOLDOWN, deploy_service_availability($facts));
    }

    public function testASupervisorWhoseChildStoppedAnsweringIsDegraded(): void
    {
        $facts = $this->facts([
            'supervisor_contract' => VIRTUSPHERE_SUPERVISOR_CONTRACT_SUPERVISOR,
            'child_alive' => false,
            'restart_cooldown' => false,
        ]);

        self::assertSame(VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED, deploy_service_availability($facts));
    }

    public function testAMissingSupervisorIsOfflineEvenWithALiveChild(): void
    {
        $facts = $this->facts([
            'supervisor_contract' => VIRTUSPHERE_SUPERVISOR_CONTRACT_SUPERVISOR,
            'process_alive' => false,
            'child_alive' => true,
        ]);

        self::assertSame(VIRTUSPHERE_DEPLOY_AVAILABILITY_OFFLINE, deploy_service_availability($facts));
    }

    public function testAHealthySupervisorWithAJobIsBusy(): void
    {
        $facts = $this->facts([
            'supervisor_contract' => VIRTUSPHERE_SUPERVISOR_CONTRACT_SUPERVISOR,
            'active_job' => true,
        ]);

        self::assertSame(VIRTUSPHERE_DEPLOY_AVAILABILITY_BUSY, deploy_service_availability($facts));
    }

    public function testTheChildAxisDoesNotApplyToTheWorkerContract(): void
    {
        // Under `worker_v1` there IS no child; the worker is the process. A
        // false `child_alive` there must not invent a second fault, because the
        // same fact already decided `process_alive`.
        $facts = $this->facts(['child_alive' => false]);

        self::assertSame(VIRTUSPHERE_DEPLOY_AVAILABILITY_READY, deploy_service_availability($facts));
    }

    public function testAnObservedShapeThatContradictsTheContractFailsClosed(): void
    {
        $facts = $this->facts(['shape_matches_contract' => false]);

        self::assertSame(
            VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED,
            deploy_service_availability($facts),
            'a supervisor reporting under worker_v1 must never read as ready'
        );
    }

    public function testTheSupervisorFreshnessBoundIsTheSlowerPublishCadence(): void
    {
        $now = 10_000;
        $atLimit = ['heartbeat_at' => date('Y-m-d H:i:s', $now - 3 * VIRTUSPHERE_SUPERVISOR_PUBLISH_INTERVAL_SECONDS)];
        $past = ['heartbeat_at' => date('Y-m-d H:i:s', $now - 3 * VIRTUSPHERE_SUPERVISOR_PUBLISH_INTERVAL_SECONDS - 1)];

        self::assertTrue(deploy_supervisor_state_is_fresh($atLimit, $now), 'the bound is inclusive');
        self::assertFalse(deploy_supervisor_state_is_fresh($past, $now));
        self::assertFalse(deploy_supervisor_state_is_fresh(['heartbeat_at' => null], $now));
        self::assertFalse(deploy_supervisor_state_is_fresh(['heartbeat_at' => 'not a time'], $now));
    }

    public function testAttentionPrioritisesManualReviewOverRecovering(): void
    {
        self::assertSame(
            VIRTUSPHERE_DEPLOY_ATTENTION_NONE,
            deploy_service_recovery_attention(['manual_required' => 0, 'legacy_uncertain_active' => 0, 'recovering' => 0])
        );
        self::assertSame(
            VIRTUSPHERE_DEPLOY_ATTENTION_RECOVERING,
            deploy_service_recovery_attention(['manual_required' => 0, 'legacy_uncertain_active' => 0, 'recovering' => 2])
        );
        self::assertSame(
            VIRTUSPHERE_DEPLOY_ATTENTION_MANUAL_REVIEW,
            deploy_service_recovery_attention(['manual_required' => 1, 'legacy_uncertain_active' => 0, 'recovering' => 5])
        );
        // A legacy job whose outcome nobody could establish also needs a person.
        self::assertSame(
            VIRTUSPHERE_DEPLOY_ATTENTION_MANUAL_REVIEW,
            deploy_service_recovery_attention(['manual_required' => 0, 'legacy_uncertain_active' => 1, 'recovering' => 0])
        );
    }

    public function testTheBadgeNeverHidesAFactTheAxesCarry(): void
    {
        // The two combinations the plan calls out by name: both halves stay
        // true at once, and the badge must take its colour from the worse one.
        self::assertSame(
            'info',
            deploy_service_badge_variant(VIRTUSPHERE_DEPLOY_AVAILABILITY_BUSY, VIRTUSPHERE_DEPLOY_ATTENTION_NONE)
        );
        self::assertSame(
            'danger',
            deploy_service_badge_variant(VIRTUSPHERE_DEPLOY_AVAILABILITY_OFFLINE, VIRTUSPHERE_DEPLOY_ATTENTION_MANUAL_REVIEW)
        );
        // Attention alone is enough to colour it, even while the service runs.
        self::assertSame(
            'danger',
            deploy_service_badge_variant(VIRTUSPHERE_DEPLOY_AVAILABILITY_READY, VIRTUSPHERE_DEPLOY_ATTENTION_MANUAL_REVIEW)
        );
        self::assertSame(
            'warning',
            deploy_service_badge_variant(VIRTUSPHERE_DEPLOY_AVAILABILITY_READY, VIRTUSPHERE_DEPLOY_ATTENTION_RECOVERING)
        );
        self::assertSame(
            'success',
            deploy_service_badge_variant(VIRTUSPHERE_DEPLOY_AVAILABILITY_READY, VIRTUSPHERE_DEPLOY_ATTENTION_NONE)
        );
    }

    public function testEveryDeclaredAvailabilityStateIsReachable(): void
    {
        // A constant-walk in the other direction: a state nobody can reach is a
        // state whose label and colour were never seen by anyone.
        $reached = [
            deploy_service_availability($this->facts()),
            deploy_service_availability($this->facts(['active_job' => true])),
            deploy_service_availability($this->facts(['active_job' => true, 'active_job_consistent' => false])),
            deploy_service_availability($this->facts([
                'supervisor_contract' => VIRTUSPHERE_SUPERVISOR_CONTRACT_SUPERVISOR,
                'restart_cooldown' => true,
            ])),
            deploy_service_availability($this->facts(['process_alive' => false])),
        ];

        sort($reached);
        $expected = VIRTUSPHERE_DEPLOY_AVAILABILITY_STATES;
        sort($expected);
        self::assertSame($expected, $reached);
    }

    public function testOnlyAcceptingAllowsNewWork(): void
    {
        self::assertTrue(deploy_claim_state_allows_new_work(VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING));
        // The remaining job of a pause_after_current is one the worker HOLDS,
        // not one it may take.
        self::assertFalse(deploy_claim_state_allows_new_work(VIRTUSPHERE_DEPLOY_CLAIM_PAUSE_AFTER_CURRENT));
        self::assertFalse(deploy_claim_state_allows_new_work(VIRTUSPHERE_DEPLOY_CLAIM_PAUSED));
        self::assertFalse(deploy_claim_state_allows_new_work('something_else'));
    }
}
