<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_worker_create.php';

/**
 * The decisions of the per-VM create orchestration that need no database and no
 * SSH (Etappe 14B, Teiletappe E).
 *
 * Everything here is a property a green run cannot show. A control command that
 * carries a cleanup trap still works, right up to the moment a connection drops
 * and it deletes the async state of a VM that is still being created. A budget
 * that is off by one at its boundary still works, until the boundary is where
 * the fifteenth VM sits.
 */
final class CreateFlowWorkerContractTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function units(int $total, array $overrides = []): array
    {
        $rows = [];
        for ($position = 1; $position <= $total; $position++) {
            $rows[] = array_merge([
                'id' => 1000 + $position,
                'job_id' => 7,
                'vm_id' => 100 + $position,
                'vm_name' => 'ATeP04-' . str_pad((string) $position, 3, '0', STR_PAD_LEFT),
                'position' => $position,
                'total' => $total,
                'action' => VIRTUSPHERE_CREATE_ACTION_CREATE,
                'status' => VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING,
            ], $overrides[$position] ?? []);
        }

        return $rows;
    }

    public function testTheCreateModeTokenAndTheLaunchPlaybookAreTheSameFile(): void
    {
        // Two SSoTs name this file: the mode map, which decides which modes
        // create VMs, and the create contract, which decides which files are
        // uploaded. They are in different modules because they are loaded at
        // different levels, so nothing but this check keeps them equal.
        self::assertSame(VIRTUSPHERE_CREATE_PLAYBOOK_LAUNCH, VIRTUSPHERE_PLAYBOOKS['create']);
        self::assertContains(VIRTUSPHERE_PLAYBOOKS['create'], ansible_required_files());
    }

    public function testTheUnitDirectoryIsDerivedFromTheJobDirectoryAndThePosition(): void
    {
        $remoteDir = '/tmp/virtusphere-job-42-Mission';

        self::assertSame($remoteDir . '/create.vm.3', ansible_create_unit_dir($remoteDir, 3));
        self::assertSame($remoteDir . '/create.vm.3/async', ansible_create_async_dir($remoteDir, 3));
        self::assertSame($remoteDir . '/create.vm.3/status.json', ansible_create_result_file($remoteDir, 3, 'status'));
        // Derived rather than stored, which is what lets a restarted worker find
        // the async state of a unit it did not launch itself.
        self::assertSame(
            ansible_create_async_dir($remoteDir, 3),
            ansible_create_async_dir($remoteDir, 3)
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableRemoteDirectories')]
    public function testAnAsyncDirectoryIsNeverBuiltUnderAnUnusablePath(string $remoteDir): void
    {
        $this->expectException(InvalidArgumentException::class);
        ansible_create_async_dir($remoteDir, 1);
    }

    /** @return array<string, array{string}> */
    public static function unusableRemoteDirectories(): array
    {
        return [
            'empty' => [''],
            'relative' => ['tmp/job'],
            'traversal' => ['/tmp/../etc'],
        ];
    }

    public function testAControlCommandNeverCarriesACleanupTrap(): void
    {
        $command = ansible_create_control_command('/tmp/vs-job-1', VIRTUSPHERE_CREATE_PLAYBOOK_STATUS, 2, [
            'vs_portal_vm_id' => 12,
            'vs_result_file' => '/tmp/vs-job-1/create.vm.2/status.json',
            'vs_async_dir' => '/tmp/vs-job-1/create.vm.2/async',
            'vs_async_jid' => 'j123.45',
        ]);

        // THE difference to a sequence step. A trap here would remove the async
        // state of a VM that is still being created the moment the channel
        // drops, which is the one piece of evidence this whole stage exists to
        // keep.
        self::assertStringNotContainsString('trap ', $command);
        self::assertStringNotContainsString('rm -rf', $command);
        self::assertStringContainsString('export PYTHONUNBUFFERED=1', $command);
        self::assertStringContainsString("mkdir -p -m 0700 '/tmp/vs-job-1/create.vm.2/async'", $command);
        self::assertStringContainsString("test ! -L '/tmp/vs-job-1/create.vm.2/async'", $command);
        // A stale document from the previous call must never be read as this
        // call's answer.
        self::assertStringContainsString("rm -f -- '/tmp/vs-job-1/create.vm.2/status.json'", $command);
        self::assertStringContainsString("python3 'emit_create_result.py'", $command);
    }

    public function testExtraVarsTravelAsTypedJsonAndAreCheckedInBothDirections(): void
    {
        $command = ansible_create_control_command('/tmp/vs-job-1', VIRTUSPHERE_CREATE_PLAYBOOK_LAUNCH, 1, [
            'vs_portal_vm_id' => 12,
            'vs_result_file' => '/tmp/vs-job-1/create.vm.1/launch.json',
            'vs_async_dir' => '/tmp/vs-job-1/create.vm.1/async',
            'vs_async_timeout' => 3600,
            'vs_expected_existed_before' => false,
            'vs_expected_moid' => '',
            'vs_expected_instance_uuid' => '',
        ]);

        // An int stays an int and a boolean stays a boolean: a playbook that has
        // to guess the type of a value the worker knows exactly will guess wrong
        // once, and `vs_expected_existed_before` is the value that decides
        // whether the module selects by name or by UUID.
        self::assertStringContainsString('"vs_portal_vm_id":12', $command);
        self::assertStringContainsString('"vs_async_timeout":3600', $command);
        self::assertStringContainsString('"vs_expected_existed_before":false', $command);

        $this->expectException(InvalidArgumentException::class);
        ansible_create_control_command('/tmp/vs-job-1', VIRTUSPHERE_CREATE_PLAYBOOK_PREPARE, 1, [
            'vs_portal_vm_id' => 12,
            'vs_result_file' => '/tmp/x.json',
            'vs_async_dir' => '/tmp/vs-job-1/create.vm.1/async',
        ]);
    }

    public function testEveryDeclaredControlPlaybookCanBuildItsCommand(): void
    {
        $samples = [
            VIRTUSPHERE_CREATE_PLAYBOOK_PREPARE => ['vs_portal_vm_id' => 1, 'vs_result_file' => '/tmp/j/create.vm.1/prepare.json'],
            VIRTUSPHERE_CREATE_PLAYBOOK_CLEANUP => ['vs_async_jid' => 'j1.2', 'vs_async_dir' => '/tmp/j/create.vm.1/async'],
        ];
        foreach ($samples as $playbook => $vars) {
            self::assertStringContainsString(
                "ansible-playbook '" . $playbook . "'",
                ansible_create_control_command('/tmp/j', $playbook, 1, $vars)
            );
        }
        // The cleanup call writes no result document, so it must not run the
        // emitter over a file nobody created.
        self::assertStringNotContainsString(
            'emit_create_result.py',
            ansible_create_control_command('/tmp/j', VIRTUSPHERE_CREATE_PLAYBOOK_CLEANUP, 1, $samples[VIRTUSPHERE_CREATE_PLAYBOOK_CLEANUP])
        );
    }

    public function testTheBudgetIsExactAtItsBoundary(): void
    {
        $budget = deploy_create_total_budget_seconds();
        $startedAt = gmdate('Y-m-d H:i:s', 1_800_000_000);

        self::assertSame(1, deploy_worker_create_remaining_seconds($startedAt, 1_800_000_000 + $budget - 1));
        self::assertSame(0, deploy_worker_create_remaining_seconds($startedAt, 1_800_000_000 + $budget));
        self::assertSame(-1, deploy_worker_create_remaining_seconds($startedAt, 1_800_000_000 + $budget + 1));
        // A missing start time is not an expired budget. It is a job whose first
        // RUN has not happened yet, and refusing to start it would be the same
        // defect as the timeout that started this plan.
        self::assertSame($budget, deploy_worker_create_remaining_seconds(null, 1_800_000_000));
    }

    public function testAnInFlightUnitAlwaysWinsOverALowerPendingPosition(): void
    {
        $rows = $this->units(15, [
            1 => ['status' => VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED],
            8 => ['status' => VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING],
        ]);

        // Not "the lowest pending one": position 9 onwards are pending, and
        // starting one of them would be the second async job of this job.
        self::assertSame(8, (int) deploy_worker_create_next_unit($rows)['position']);

        $uncertain = $this->units(3, [2 => ['status' => VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN]]);
        self::assertSame(2, (int) deploy_worker_create_next_unit($uncertain)['position']);

        $done = $this->units(2, [
            1 => ['status' => VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED],
            2 => ['status' => VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED],
        ]);
        self::assertNull(deploy_worker_create_next_unit($done));
    }

    public function testAConfirmedFailureContinuesWhileAGlobalStopClassDoesNot(): void
    {
        // Decision F5: a VM that provably failed does not cost the operator the
        // other fourteen. The classes that DO stop are the ones where carrying
        // on would build on something unestablished.
        self::assertNotContains(VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED, VIRTUSPHERE_CREATE_GLOBAL_STOP_ERROR_CODES);
        self::assertNotContains(VIRTUSPHERE_CREATE_ERROR_IDENTITY_CONFLICT, VIRTUSPHERE_CREATE_GLOBAL_STOP_ERROR_CODES);
        foreach (VIRTUSPHERE_CREATE_GLOBAL_STOP_ERROR_CODES as $code) {
            self::assertContains($code, VIRTUSPHERE_CREATE_ERROR_CODES, 'a stop class must be a known error code');
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedMaterializations')]
    public function testAMalformedMaterializationIsRefusedBeforeAnythingStarts(array $rows, string $needle): void
    {
        $this->expectException(DeployWorkerCreateProtocolError::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($needle, '/') . '/');
        deploy_worker_create_assert_materialized($rows, ['id' => 7]);
    }

    /** @return array<string, array{list<array<string,mixed>>, string}> */
    public static function malformedMaterializations(): array
    {
        $base = static function (int $total, array $overrides = []): array {
            $test = new self('x');

            return $test->units($total, $overrides);
        };

        return [
            // A create-capable job queued before this contract. It is refused
            // rather than run through some other path: a fallback would be a
            // second create with different evidence.
            'no rows at all' => [[], 'queued before the per-VM create contract'],
            // Consistent totals, so only the gap is left to find: position 2 is
            // the VM nobody would ever work on again.
            'gap in the positions' => [
                array_values(array_filter(
                    $base(3, [1 => ['total' => 2], 2 => ['total' => 2], 3 => ['total' => 2]]),
                    static fn (array $row): bool => (int) $row['position'] !== 2
                )),
                'not contiguous',
            ],
            'two units for one VM' => [$base(2, [2 => ['vm_id' => 101]]), 'twice'],
            'unit without a VM' => [$base(2, [2 => ['vm_id' => null]]), 'no VM'],
        ];
    }

    public function testTheJobStatusMatrixKeepsPartialApart(): void
    {
        $status = static fn (int $succeeded, int $skipped, int $total): string => deploy_worker_create_job_status(
            ['succeeded' => $succeeded, 'skipped' => $skipped, 'total' => $total]
        );

        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, $status(15, 0, 15));
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, $status(3, 12, 15));
        // Fourteen of fifteen. Calling this `failed` is what sends an operator
        // to delete fourteen VMs that are fine.
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, $status(14, 0, 15));
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $status(0, 0, 15));
    }

    public function testTheProgressAndSummaryLinesKeepTheirTechnicalShape(): void
    {
        $unit = ['position' => 8, 'total' => 15, 'vm_name' => 'ATeP04-008'];

        self::assertSame('[8/15] RUN create ATeP04-008', deploy_worker_create_progress_line($unit, 'RUN'));
        self::assertSame('[8/15] DONE create ATeP04-008 created', deploy_worker_create_progress_line($unit, 'DONE', 'created'));

        $line = deploy_worker_create_summary_line([
            'total' => 15, 'created' => 14, 'updated' => 0, 'unchanged' => 0,
            'skipped' => 0, 'failed' => 0, 'uncertain' => 1, 'not_started' => 0,
        ]);
        // Stable technical keys, never localized: an operator greps these, and
        // the portal's visible counters come from the rows through the catalogs.
        self::assertSame(
            'Create summary: total=15 created=14 updated=0 unchanged=0 skipped=0 failed=0 uncertain=1 not_started=0',
            $line
        );
    }
}
