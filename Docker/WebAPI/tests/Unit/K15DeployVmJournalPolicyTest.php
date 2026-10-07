<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_worker_vm_journal.php';

/** The journal never upgrades uncertain create evidence into either outcome. */
final class K15DeployVmJournalPolicyTest extends TestCase
{
    public function testCreateEvidenceHasAnExplicitKnowledgeBoundary(): void
    {
        $state = ['esxi_touched' => 0, 'create_launch_intent' => 0];
        $cases = [
            'no create unit has begun' => [null, 'restore'],
            'pending means not begun' => [['status' => 'pending', 'async_jid' => null, 'outcome' => null], 'restore'],
            'prepared without durable launch intent means not begun' => [['status' => 'prepared', 'async_jid' => null, 'outcome' => null], 'restore'],
            'prepared with launch intent has a crash gap' => [['status' => 'prepared', 'async_jid' => null, 'outcome' => null], 'unknown', 1],
            'running is not a terminal result' => [['status' => 'running', 'async_jid' => '123456789.1', 'outcome' => null], 'unknown'],
            'uncertain stays unknown' => [['status' => 'uncertain', 'async_jid' => null, 'outcome' => null], 'unknown'],
            'confirmed failed async unit is touched' => [['status' => 'failed', 'async_jid' => '123456789.1', 'outcome' => null], 'fail'],
            'successful unchanged unit is not a mutation' => [['status' => 'succeeded', 'async_jid' => null, 'outcome' => 'unchanged'], 'restore'],
            'verified skip is not a mutation' => [['status' => 'skipped', 'async_jid' => null, 'outcome' => 'unchanged'], 'restore'],
        ];
        foreach ($cases as $label => $case) {
            [$unit, $expected] = $case;
            $intent = $case[2] ?? 0;
            $fixture = $state;
            $fixture['create_launch_intent'] = (int) ($intent ?? 0);
            self::assertSame($expected, deploy_worker_vm_journal_disposition($fixture, $unit), $label);
        }
    }

    public function testDurableEsxiTouchFailsOnlyItsVmAndNoTouchRestores(): void
    {
        $untouched = ['esxi_touched' => 0, 'create_launch_intent' => 0];
        $touched = ['esxi_touched' => 1, 'create_launch_intent' => 0];

        self::assertSame('restore', deploy_worker_vm_journal_disposition($untouched, null));
        self::assertSame('fail', deploy_worker_vm_journal_disposition($touched, null));
    }
}
