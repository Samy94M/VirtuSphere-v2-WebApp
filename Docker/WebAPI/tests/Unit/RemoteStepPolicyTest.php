<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/remote_step_policy.php';

final class RemoteStepPolicyTest extends TestCase
{
    public function testRegistryIsClosedAndFollowsAnsiblePlaybookOrder(): void
    {
        $registry = remote_step_policy_registry();
        self::assertSame(VIRTUSPHERE_REMOTE_POLICY_MODES, array_keys($registry));

        foreach ($registry as $mode => $policy) {
            $expected = $mode === VIRTUSPHERE_DEPLOY_MODE_INVENTORY
                ? [VIRTUSPHERE_SYSTEM_PLAYBOOKS[VIRTUSPHERE_DEPLOY_MODE_INVENTORY]]
                : ansible_playbooks_for_mode($mode);
            self::assertSame($expected, $policy['playbooks'], $mode);
            self::assertSame($expected, array_column(array_values($policy['steps']), 'playbook'), $mode);
        }
    }

    public function testEveryStepNamesSiteBudgetOwnerCallbackAndRecoveryEvidence(): void
    {
        foreach (remote_step_policy_registry() as $mode => $policy) {
            foreach ($policy['steps'] as $step => $definition) {
                self::assertNotSame('', $definition['mutation'], $mode . ':' . $step);
                self::assertNotSame('', $definition['reconciliation_owner'], $mode . ':' . $step);
                self::assertNotSame('', $definition['callback_expectation'], $mode . ':' . $step);
                self::assertSame('site_acceptance_required', $definition['runtime_budget_source'], $mode . ':' . $step);
                self::assertNotSame([], $definition['recovery_evidence'], $mode . ':' . $step);
            }
        }
    }

    public function testModeSpecificRecoveryContractsAreExplicit(): void
    {
        $registry = remote_step_policy_registry();
        self::assertSame('read_only', $registry['inventory']['steps']['inventory']['mutation']);
        self::assertContains('same_job_callback', $registry['export']['steps']['export']['recovery_evidence']);
        self::assertContains('active_task_check', $registry['start']['steps']['start']['recovery_evidence']);
        self::assertContains('ha_and_license_gate', $registry['autostart']['steps']['autostart']['recovery_evidence']);
        self::assertSame(VIRTUSPHERE_REMOTE_POWERCYCLE_PHASES, $registry['powercycle']['steps']['powercycle']['phases']);
        self::assertSame(['powercycle', 'export'], array_keys($registry['powercycle']['steps']));
    }

    public function testDisabledLegacyAndRollbackNeverSelectRemoteOrFallback(): void
    {
        foreach (VIRTUSPHERE_REMOTE_POLICY_MODES as $mode) {
            $disabled = remote_mode_activation_verdict($this->activation($mode, 'disabled', null), $mode);
            self::assertFalse($disabled['allowed'], $mode);
            self::assertNull($disabled['contract'], $mode);

            $legacy = remote_mode_activation_verdict($this->activation($mode, 'legacy_explicit', 'legacy_v1'), $mode);
            self::assertFalse($legacy['allowed'], $mode);
            self::assertNull($legacy['contract'], $mode);

            $rollback = remote_mode_activation_verdict($this->activation($mode, 'rollback_pending', 'remote_v1'), $mode);
            self::assertFalse($rollback['allowed'], $mode);
            self::assertNull($rollback['contract'], $mode);
        }
    }

    public function testOnlyPilotAndEnabledRowsSelectRemoteV1(): void
    {
        foreach (VIRTUSPHERE_REMOTE_POLICY_MODES as $mode) {
            foreach (['pilot_remote', 'remote_enabled'] as $state) {
                $verdict = remote_mode_activation_verdict($this->activation($mode, $state, 'remote_v1'), $mode);
                self::assertTrue($verdict['allowed'], $mode . ':' . $state);
                self::assertSame('remote_v1', $verdict['contract']);
                self::assertSame('remote_only', $verdict['reason']);
            }
        }
    }

    public function testCreateAndFullRemainBlockedAndUnknownModesFailClosed(): void
    {
        foreach (['create', VIRTUSPHERE_DEPLOY_MODE_FULL] as $mode) {
            $verdict = remote_mode_activation_verdict($this->activation($mode, 'pilot_remote', 'remote_v1'), $mode);
            self::assertFalse($verdict['allowed']);
            self::assertSame('mode_not_offline_prepared', $verdict['reason']);
        }

        $this->expectException(InvalidArgumentException::class);
        remote_mode_activation_verdict($this->activation('unknown', 'disabled', null), 'unknown');
    }

    public function testMalformedOrMismatchedActivationFailsClosed(): void
    {
        foreach ([
            $this->activation('export', 'disabled', 'remote_v1'),
            $this->activation('export', 'pilot_remote', null),
            $this->activation('export', 'legacy_explicit', 'remote_v1'),
        ] as $activation) {
            try {
                remote_mode_activation_verdict($activation, 'export');
                self::fail('Malformed activation was accepted.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        remote_mode_activation_verdict($this->activation('start', 'disabled', null), 'export');
    }

    /** @return array{mode:string,state:string,contract_version:?string} */
    private function activation(string $mode, string $state, ?string $contract): array
    {
        return ['mode' => $mode, 'state' => $state, 'contract_version' => $contract];
    }
}
