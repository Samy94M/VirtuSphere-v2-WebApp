<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';

/**
 * The guards the create SSoT cannot express as a type (Etappe 14B, plan 6.3).
 *
 * Every value in lib/deploy_create_constants.php is a plain integer or string,
 * so nothing stops a later edit from setting a poll interval above the stale
 * limit. That would not fail anywhere: the job would simply look abandoned
 * between two healthy polls and be reaped mid-run, which is the class of defect
 * this whole stage exists to remove. The relations therefore live here, walked
 * rather than listed, so a new value cannot join the module unnoticed.
 */
final class DeployCreateConstantsContractTest extends TestCase
{
    public function testEveryTimingValueIsPositive(): void
    {
        $values = [
            'poll interval' => VIRTUSPHERE_CREATE_POLL_INTERVAL_SECONDS,
            'control idle timeout' => VIRTUSPHERE_CREATE_CONTROL_IDLE_TIMEOUT_SECONDS,
            'control total timeout' => VIRTUSPHERE_CREATE_CONTROL_TOTAL_TIMEOUT_SECONDS,
            'jid discovery interval' => VIRTUSPHERE_CREATE_JID_DISCOVERY_INTERVAL_SECONDS,
            'jid discovery timeout' => VIRTUSPHERE_CREATE_JID_DISCOVERY_TIMEOUT_SECONDS,
            'transport backoff min' => VIRTUSPHERE_CREATE_TRANSPORT_BACKOFF_MIN_SECONDS,
            'transport backoff max' => VIRTUSPHERE_CREATE_TRANSPORT_BACKOFF_MAX_SECONDS,
            'remote cleanup batch size' => VIRTUSPHERE_REMOTE_CLEANUP_BATCH_SIZE,
            'remote cleanup max auto attempts' => VIRTUSPHERE_REMOTE_CLEANUP_MAX_AUTO_ATTEMPTS,
            'remote cleanup backoff min' => VIRTUSPHERE_REMOTE_CLEANUP_BACKOFF_MIN_SECONDS,
            'remote cleanup backoff max' => VIRTUSPHERE_REMOTE_CLEANUP_BACKOFF_MAX_SECONDS,
        ];
        foreach ($values as $label => $value) {
            self::assertGreaterThan(0, $value, $label . ' must be positive');
        }
    }

    public function testTheTimingRelationsHold(): void
    {
        // A discovery interval at or above its own timeout would poll once, or
        // never, and then declare the launch unconfirmed.
        self::assertLessThan(
            VIRTUSPHERE_CREATE_JID_DISCOVERY_TIMEOUT_SECONDS,
            VIRTUSPHERE_CREATE_JID_DISCOVERY_INTERVAL_SECONDS
        );
        // A control call must finish well inside the window after which a job
        // counts as stale, or the worker would be reaped while it is waiting for
        // an answer it is going to get.
        self::assertLessThan(
            VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS,
            VIRTUSPHERE_CREATE_CONTROL_TOTAL_TIMEOUT_SECONDS
        );
        self::assertLessThan(
            VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS,
            VIRTUSPHERE_CREATE_POLL_INTERVAL_SECONDS
        );
        self::assertLessThanOrEqual(
            VIRTUSPHERE_CREATE_CONTROL_TOTAL_TIMEOUT_SECONDS,
            VIRTUSPHERE_CREATE_CONTROL_IDLE_TIMEOUT_SECONDS
        );
        self::assertLessThanOrEqual(
            VIRTUSPHERE_CREATE_TRANSPORT_BACKOFF_MAX_SECONDS,
            VIRTUSPHERE_CREATE_TRANSPORT_BACKOFF_MIN_SECONDS
        );
        self::assertLessThanOrEqual(
            VIRTUSPHERE_REMOTE_CLEANUP_BACKOFF_MAX_SECONDS,
            VIRTUSPHERE_REMOTE_CLEANUP_BACKOFF_MIN_SECONDS
        );
    }

    public function testTheCreateBudgetIsDerivedAndNotRepeated(): void
    {
        self::assertSame(VIRTUSPHERE_SSH_TOTAL_TIMEOUT_SECONDS, deploy_create_total_budget_seconds());

        // Freed decision F2 keeps the create budget at the SSH total budget. The
        // point of the assertion is the DERIVATION: a second literal would keep
        // working while it quietly stops meaning the same thing.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_create_constants.php');
        self::assertStringNotContainsString(
            (string) VIRTUSPHERE_SSH_TOTAL_TIMEOUT_SECONDS,
            $source,
            'the create module must derive the budget, not repeat its number'
        );
    }

    public function testTheClosedSetsAreCompleteAndDisjointWhereTheyHaveToBe(): void
    {
        foreach ([VIRTUSPHERE_CREATE_RESULT_PROCESSED_STATUSES, VIRTUSPHERE_CREATE_RESULT_INFLIGHT_STATUSES, VIRTUSPHERE_CREATE_RESULT_SUCCESSFUL_STATUSES] as $subset) {
            foreach ($subset as $status) {
                self::assertContains($status, VIRTUSPHERE_CREATE_RESULT_STATUSES);
            }
        }
        // Every status is either in flight, processed, or the untouched start.
        // A status in neither set would be invisible to both the progress count
        // and the "may the next VM start" question.
        $covered = array_merge(
            VIRTUSPHERE_CREATE_RESULT_PROCESSED_STATUSES,
            VIRTUSPHERE_CREATE_RESULT_INFLIGHT_STATUSES,
            [VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING]
        );
        foreach (VIRTUSPHERE_CREATE_RESULT_STATUSES as $status) {
            self::assertContains($status, $covered, $status . ' belongs to no progress class');
        }
        // uncertain is in both the in-flight and the processed set on purpose:
        // it stops the job AND it is a unit nobody will work on again without a
        // decision. That overlap is the only one allowed.
        $overlap = array_values(array_intersect(
            VIRTUSPHERE_CREATE_RESULT_PROCESSED_STATUSES,
            VIRTUSPHERE_CREATE_RESULT_INFLIGHT_STATUSES
        ));
        self::assertSame([VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN], $overlap);

        foreach (VIRTUSPHERE_CREATE_AUTO_RETRYABLE_ERROR_CODES as $code) {
            self::assertContains($code, VIRTUSPHERE_CREATE_ERROR_CODES);
        }
        // Everything whose outcome is not established must NOT be automatically
        // retryable: re-running it could create a second VM.
        foreach ([VIRTUSPHERE_CREATE_ERROR_LAUNCH_UNCONFIRMED, VIRTUSPHERE_CREATE_ERROR_ASYNC_STATE_MISSING, VIRTUSPHERE_CREATE_ERROR_TRANSPORT_LOST, VIRTUSPHERE_CREATE_ERROR_JOB_TIMEOUT, VIRTUSPHERE_CREATE_ERROR_IDENTITY_CONFLICT, VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID, VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR, VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST] as $code) {
            self::assertNotContains($code, VIRTUSPHERE_CREATE_AUTO_RETRYABLE_ERROR_CODES, $code . ' must not auto-retry');
        }
    }

    public function testTheJidClassRefusesWhatWouldReachAShell(): void
    {
        self::assertTrue(deploy_create_jid_is_valid('j123456789012.3456'));
        self::assertTrue(deploy_create_jid_is_valid('747689456876.6394'));
        self::assertFalse(deploy_create_jid_is_valid(''));
        self::assertFalse(deploy_create_jid_is_valid('123; rm -rf /'));
        self::assertFalse(deploy_create_jid_is_valid('12 34'));
        self::assertFalse(deploy_create_jid_is_valid("123\n456"));
        self::assertFalse(deploy_create_jid_is_valid('../../etc/passwd'));
        self::assertFalse(deploy_create_jid_is_valid(str_repeat('9', VIRTUSPHERE_CREATE_JID_MAX_LENGTH + 1)));
    }

    public function testTheStepKeyIsBoundToThePosition(): void
    {
        self::assertSame('create.vm.1', deploy_create_step_key(1));
        self::assertSame('create.vm.15', deploy_create_step_key(15));

        $this->expectException(InvalidArgumentException::class);
        deploy_create_step_key(0);
    }

    public function testTheSchemaMirrorsTheConstOrderInBothSources(): void
    {
        $sources = [
            dirname(__DIR__, 4) . '/Docker/mysql/mysql-init/struktur.sql',
            dirname(__DIR__, 4) . '/Docker/WebAPI/lib/migrations/0047_deploy_create_results.php',
        ];
        $expected = [
            'action' => "'" . implode("','", VIRTUSPHERE_CREATE_ACTIONS) . "'",
            'status' => "'" . implode("','", VIRTUSPHERE_CREATE_RESULT_STATUSES) . "'",
            'outcome' => "'" . implode("','", VIRTUSPHERE_CREATE_OUTCOMES) . "'",
        ];
        foreach ($sources as $path) {
            self::assertFileExists($path);
            $source = (string) file_get_contents($path);
            foreach ($expected as $column => $enum) {
                self::assertStringContainsString(
                    $column . ' ENUM(' . $enum . ')',
                    $source,
                    $column . ' mirror drifted in ' . basename($path)
                );
            }
        }
    }
}
