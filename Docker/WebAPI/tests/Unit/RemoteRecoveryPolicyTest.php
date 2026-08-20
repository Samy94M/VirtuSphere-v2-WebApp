<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/remote_recovery_policy.php';

final class RemoteRecoveryPolicyTest extends TestCase
{
    private string $generation;

    protected function setUp(): void
    {
        $this->generation = str_repeat('a', 32);
    }

    public function testLegacyAndForeignJobsNeverBecomeRetriesOrTerminal(): void
    {
        $legacy = remote_recovery_decision($this->job('legacy_v1'), null, $this->generation);
        self::assertSame('legacy_uncertain', $legacy['action']);
        self::assertFalse($legacy['may_terminalize']);
        self::assertFalse($legacy['may_cleanup']);

        $foreign = remote_recovery_decision($this->job('remote_v1', str_repeat('b', 32)), $this->execution(), $this->generation);
        self::assertSame('foreign_generation', $foreign['action']);
        self::assertFalse($foreign['may_terminalize']);
    }

    public function testOnlyProvenNeverStartMayTerminalize(): void
    {
        $missing = remote_recovery_decision($this->job(), null, $this->generation);
        self::assertSame('terminal_not_started', $missing['action']);
        self::assertTrue($missing['may_terminalize']);

        $neverStarted = remote_recovery_decision($this->job(), $this->execution('never_started'), $this->generation);
        self::assertSame('terminal_not_started', $neverStarted['action']);
        self::assertTrue($neverStarted['may_terminalize']);

        foreach (['prepared', 'active', 'lost_after_start', 'protocol_error'] as $state) {
            $decision = remote_recovery_decision($this->job(), $this->execution($state), $this->generation);
            self::assertFalse($decision['may_terminalize'], $state);
        }
    }

    public function testActiveUnknownAndProtocolCasesStayRecoverableOrManual(): void
    {
        self::assertSame('reattach', remote_recovery_decision($this->job(), $this->execution('active'), $this->generation)['action']);
        self::assertSame('reattach', remote_recovery_decision($this->job(), $this->execution('lost_after_start'), $this->generation)['action']);
        self::assertSame('manual_required', remote_recovery_decision($this->job(), $this->execution('protocol_error'), $this->generation)['action']);
        $manual = $this->execution('active');
        $manual['reconciliation_state'] = 'manual_required';
        self::assertSame('manual_required', remote_recovery_decision($this->job(), $manual, $this->generation)['action']);
    }

    public function testTerminalControllerStillNeedsImportOrReconciliation(): void
    {
        $exited = remote_recovery_decision($this->job(), $this->execution('exited_0'), $this->generation);
        self::assertSame('import_result', $exited['action']);
        self::assertFalse($exited['may_terminalize']);
        $pending = $this->execution('exited_nonzero');
        $pending['reconciliation_state'] = 'pending';
        $pending['cleanup_state'] = 'eligible';
        $decision = remote_recovery_decision($this->job(), $pending, $this->generation);
        self::assertSame('reconcile', $decision['action']);
        self::assertTrue($decision['may_cleanup']);
    }

    /** @return array<string, mixed> */
    private function job(string $contract = 'remote_v1', ?string $generation = null): array
    {
        return ['status' => 'running', 'execution_contract' => $contract, 'execution_generation_id' => $generation ?? $this->generation];
    }

    /** @return array<string, mixed> */
    private function execution(string $controller = 'active'): array
    {
        return ['generation_id' => $this->generation, 'controller_state' => $controller, 'reconciliation_state' => 'not_required', 'cleanup_state' => 'pending'];
    }
}
