<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_create_result.php';

/**
 * The create unit state machine, driven without a database (Etappe 14B).
 *
 * These are the cases a real MySQL server cannot be asked for on demand: the
 * fifteenth VM going uncertain while fourteen are confirmed, a duplicate poll
 * answer arriving after a terminal state, an outcome that contradicts its own
 * evidence. They decide what the repository is allowed to write, so they are
 * proved here rather than inferred from a green integration run.
 */
final class DeployCreateResultStateTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function rows(array $statuses, array $overrides = []): array
    {
        $rows = [];
        $position = 0;
        foreach ($statuses as $status) {
            $position++;
            $rows[] = array_merge([
                'position' => $position,
                'vm_name' => sprintf('VM%03d', $position),
                'status' => $status,
                'outcome' => in_array($status, VIRTUSPHERE_CREATE_RESULT_SUCCESSFUL_STATUSES, true)
                    ? VIRTUSPHERE_CREATE_OUTCOME_CREATED
                    : null,
                'started_at' => '2026-09-01 10:00:00',
            ], $overrides[$position] ?? []);
        }

        return $rows;
    }

    public function testTheTransitionTableIsClosedAndTerminalStatesAreTerminal(): void
    {
        foreach ([VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED] as $terminal) {
            foreach (VIRTUSPHERE_CREATE_RESULT_STATUSES as $target) {
                self::assertFalse(
                    deploy_create_transition_allowed($terminal, $target),
                    $terminal . ' must not move to ' . $target . ' inside the same job'
                );
            }
        }

        // uncertain is deliberately NOT terminal: it says VirtuSphere does not
        // know, and it leaves through a resumed poll, a verified identity or an
        // operator confirming that nothing was created.
        self::assertTrue(deploy_create_transition_allowed(VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING));
        self::assertTrue(deploy_create_transition_allowed(VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN, VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED));
        self::assertTrue(deploy_create_transition_allowed(VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN, VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED));
        // The poll refresh of a running unit.
        self::assertTrue(deploy_create_transition_allowed(VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING));
        // A unit cannot skip its preparation and cannot be launched twice.
        self::assertFalse(deploy_create_transition_allowed(VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING));
        self::assertFalse(deploy_create_transition_allowed(VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED, VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED));

        // Every target that appears anywhere in the table is a known status; a
        // typo would otherwise create a transition to a state nothing reads.
        foreach (deploy_create_transitions() as $from => $targets) {
            self::assertContains($from, VIRTUSPHERE_CREATE_RESULT_STATUSES);
            foreach ($targets as $target) {
                self::assertContains($target, VIRTUSPHERE_CREATE_RESULT_STATUSES);
            }
        }
    }

    public function testTheFourthEvidenceCombinationIsNotAQuietSuccess(): void
    {
        self::assertSame(VIRTUSPHERE_CREATE_OUTCOME_CREATED, deploy_create_outcome_for(false, true));
        self::assertSame(VIRTUSPHERE_CREATE_OUTCOME_UPDATED, deploy_create_outcome_for(true, true));
        self::assertSame(VIRTUSPHERE_CREATE_OUTCOME_UNCHANGED, deploy_create_outcome_for(true, false));

        // Nothing was there and nothing changed. The module reported success for
        // a VM the live check had not seen and did not create, which is the one
        // combination that must never become a stored outcome.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID);
        deploy_create_outcome_for(false, false);
    }

    public function testASuccessMustCarryItsVerifiedIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('vm_instance_uuid');
        deploy_create_assert_transition_fields(VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, [
            'outcome' => VIRTUSPHERE_CREATE_OUTCOME_CREATED,
            'changed' => 1,
            'existed_before' => 0,
            'vm_moid' => 'vm-42',
        ]);
    }

    public function testAnOutcomeThatContradictsItsEvidenceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contradicts its evidence');
        deploy_create_assert_transition_fields(VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, [
            // The VM existed before and the module changed nothing, so this is
            // `unchanged`. Claiming `created` would report a VM as newly built
            // that was already there.
            'outcome' => VIRTUSPHERE_CREATE_OUTCOME_CREATED,
            'changed' => 0,
            'existed_before' => 1,
            'vm_moid' => 'vm-42',
            'vm_instance_uuid' => '5001-abcd',
        ]);
    }

    public function testARunningUnitNeedsJobIdHandleAndDeadline(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('remote_execution_id');
        deploy_create_assert_transition_fields(VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, [
            'async_jid' => 'j1234567890.42',
            'async_deadline_at' => '2026-09-01 12:00:00',
        ]);
    }

    public function testAFailureCannotInventItsOwnErrorCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown create error code');
        deploy_create_assert_transition_fields(VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, [
            // Free Ansible prose is exactly what must not become a code.
            'error_code' => 'the module said something red',
            'error_detail' => 'redacted detail',
        ]);
    }

    public function testAnInFlightUnitStopsTheJobFromStartingTheNextOne(): void
    {
        $rows = $this->rows([
            VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING,
            VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING,
        ]);
        self::assertTrue(deploy_create_has_inflight($rows));
        self::assertNull(deploy_create_next_position($rows), 'no second async job of the same deploy job');

        // The same holds for an uncertain unit, which is the case that matters:
        // continuing there would create VM three while VM two may still be
        // running on the host.
        $uncertain = $this->rows([
            VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
            VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING,
        ]);
        self::assertNull(deploy_create_next_position($uncertain));

        $free = $this->rows([
            VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING,
        ]);
        // A confirmed failure does not stop the job: the remaining VMs are
        // independent work (freed decision F5).
        self::assertSame(3, deploy_create_next_position($free));
    }

    public function testTheIncidentShapeIsCountedHonestly(): void
    {
        // Fourteen confirmed, the fifteenth unresolved: the exact state the
        // production job ended in, and the one the portal has to show without
        // calling anything failed.
        $statuses = array_fill(0, 14, VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED);
        $statuses[] = VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN;
        $summary = deploy_create_summary($this->rows($statuses));

        self::assertSame(15, $summary['total']);
        self::assertSame(15, $summary['processed']);
        self::assertSame(14, $summary['succeeded']);
        self::assertSame(14, $summary['created']);
        self::assertSame(1, $summary['uncertain']);
        self::assertSame(0, $summary['failed'], 'uncertain is never counted as failed');
        self::assertSame(0, $summary['not_started']);
        self::assertNotNull($summary['current']);
        self::assertSame(15, $summary['current']['position'], 'the unresolved unit is where the job stopped');
        self::assertFalse(deploy_create_all_successful($this->rows($statuses)));
    }

    public function testASkippedUnitCountsAsSuccessfulForTheFullPipeline(): void
    {
        $rows = $this->rows([
            VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED,
        ]);
        self::assertTrue(deploy_create_all_successful($rows));
        // An empty job is not a successful one; the full pipeline must not
        // continue on a job that materialized nothing.
        self::assertFalse(deploy_create_all_successful([]));
    }

    public function testARetryTakesOverProvenUnitsAndIsBlockedByUnresolvedOnes(): void
    {
        $rows = $this->rows([
            VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED,
        ]);
        $plan = deploy_create_retry_plan($rows);
        self::assertFalse($plan['blocked']);
        self::assertCount(2, $plan['verify'], 'a confirmed success and an earlier skip are both proven');
        self::assertCount(1, $plan['create']);

        $withUncertain = $this->rows([
            VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
            VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
        ]);
        $blocked = deploy_create_retry_plan($withUncertain);
        self::assertTrue($blocked['blocked'], 'a retry must not start a second create for an unresolved VM');
        self::assertSame([2], $blocked['blocking_positions']);
    }
}
