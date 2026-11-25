<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase.php';

/**
 * The phase of a VM is a pure function of its stored state (plan "Phase und
 * Befund", section 3). The expected phase is written out here as the rule table
 * itself and is deliberately NOT obtained by calling virtusphere_vm_phase(): a
 * test that re-runs the function under test cannot fail on a wrong rule. The
 * data provider walks every lifecycle x MECM x binding combination, so a state
 * added to a constant without a rule changes the walk instead of falling into a
 * silent default.
 *
 * VB4 adds the stored x/5 value to the walk: behind one of the worker's two
 * marks (`deploying`, `failed`) it names the last evidence that arrived, and
 * anywhere else it decides nothing.
 */
final class VmPhaseTest extends TestCase
{
    private const PALETTE = ['success', 'warning', 'danger', 'info', 'neutral'];

    /**
     * Every stored combination, with the phase the rule table gives it. The
     * first matching rule wins, exactly as plan section 3 orders them.
     *
     * @return iterable<string, array{0:string,1:string,2:bool,3:string}>
     */
    public static function stateCombinations(): iterable
    {
        foreach (VIRTUSPHERE_LIFECYCLE_STATES as $lifecycle) {
            foreach (VIRTUSPHERE_MECM_SYNC_STATES as $mecm) {
                foreach ([false, true] as $bound) {
                    yield $lifecycle . ' / ' . $mecm . ' / ' . ($bound ? 'bound' : 'unbound')
                        => [$lifecycle, $mecm, $bound, self::ruleTable($lifecycle, $mecm, $bound, '')];
                }
            }
        }
    }

    /**
     * The same walk with every stored x/5 value, an unknown one and an empty one.
     *
     * @return iterable<string, array{0:string,1:string,2:bool,3:string,4:string}>
     */
    public static function stateCombinationsWithStoredStage(): iterable
    {
        foreach (VIRTUSPHERE_LIFECYCLE_STATES as $lifecycle) {
            foreach (VIRTUSPHERE_MECM_SYNC_STATES as $mecm) {
                foreach ([false, true] as $bound) {
                    foreach ([...VIRTUSPHERE_VM_STATUS_COMPAT, '9/5 Unknown', ''] as $stage) {
                        yield $lifecycle . ' / ' . $mecm . ' / ' . ($bound ? 'bound' : 'unbound') . ' / ' . ($stage === '' ? 'no stage' : $stage)
                            => [$lifecycle, $mecm, $bound, $stage, self::ruleTable($lifecycle, $mecm, $bound, $stage)];
                    }
                }
            }
        }
    }

    /**
     * The rule table, written out. $stage is the stored x/5 value; it is read
     * only behind a worker mark, where the lifecycle says nothing about evidence.
     */
    private static function ruleTable(string $lifecycle, string $mecm, bool $bound, string $stage): string
    {
        $behindAMark = $lifecycle === VIRTUSPHERE_LIFECYCLE_FAILED || $lifecycle === VIRTUSPHERE_LIFECYCLE_DEPLOYING;
        $last = $behindAMark ? $stage : '';
        if ($lifecycle === VIRTUSPHERE_LIFECYCLE_OS_INSTALLED || $last === '5/5 OS Installed') {
            return VIRTUSPHERE_VM_PHASE_WINDOWS_REPORTS;
        }
        if ($lifecycle === VIRTUSPHERE_LIFECYCLE_OS_INSTALLING || $mecm === VIRTUSPHERE_MECM_SYNC_REGISTERED || $last === '4/5 OS Installing') {
            return VIRTUSPHERE_VM_PHASE_HANDED_TO_MECM;
        }
        if ($lifecycle === VIRTUSPHERE_LIFECYCLE_DEPLOYED || $mecm === VIRTUSPHERE_MECM_SYNC_PENDING || $last === '3/5 Deployed') {
            return VIRTUSPHERE_VM_PHASE_MAC_KNOWN;
        }
        if ($bound) {
            return VIRTUSPHERE_VM_PHASE_ON_ESXI;
        }

        return $lifecycle === VIRTUSPHERE_LIFECYCLE_DEPLOYING ? VIRTUSPHERE_VM_PHASE_IN_PROGRESS : VIRTUSPHERE_VM_PHASE_CREATED;
    }

    /** @return iterable<string, array{0:string,1:?string}> */
    public static function oneUnfinishedClientPhase(): iterable
    {
        foreach (VIRTUSPHERE_CLIENT_PHASES as $odd) {
            foreach (['failed', 'started', null] as $state) {
                yield $odd . ' ' . ($state ?? 'missing') => [$odd, $state];
            }
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('stateCombinations')]
    public function testThePhaseFollowsThePlanRuleTable(string $lifecycle, string $mecm, bool $bound, string $expected): void
    {
        self::assertSame($expected, virtusphere_vm_phase(self::vm($lifecycle, $mecm, $bound)));
    }

    #[PHPUnit\Framework\Attributes\DataProvider('stateCombinations')]
    public function testATemplateIsATemplateWhateverItsStoredState(string $lifecycle, string $mecm, bool $bound, string $expected): void
    {
        self::assertSame(
            VIRTUSPHERE_VM_PHASE_TEMPLATE,
            virtusphere_vm_phase(self::vm($lifecycle, $mecm, $bound), ['is_template' => true])
        );
    }

    public function testAllFourFinishedClientPhasesCompleteAnInstalledVm(): void
    {
        $evidence = ['client_phases' => self::clientPhases(self::allFinished())];

        self::assertSame(
            VIRTUSPHERE_VM_PHASE_CLIENT_DONE,
            virtusphere_vm_phase(self::vm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_REGISTERED, true), $evidence)
        );
    }

    public function testFourFinishedClientPhasesDoNotLiftAnInstallingVm(): void
    {
        $evidence = ['client_phases' => self::clientPhases(self::allFinished())];

        self::assertSame(
            VIRTUSPHERE_VM_PHASE_HANDED_TO_MECM,
            virtusphere_vm_phase(self::vm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, true), $evidence),
            'client evidence alone does not lift the phase'
        );
    }

    /** @param ?string $state the odd phase's latest event; null is a silent phase */
    #[PHPUnit\Framework\Attributes\DataProvider('oneUnfinishedClientPhase')]
    public function testThreeFinishedPhasesAndOneOpenPhaseStayWindowsReports(string $odd, ?string $state): void
    {
        $events = array_fill_keys(VIRTUSPHERE_CLIENT_PHASES, VIRTUSPHERE_CLIENT_EVENT_FINISHED);
        $events[$odd] = $state;
        $evidence = ['client_phases' => self::clientPhases($events)];

        self::assertSame(
            VIRTUSPHERE_VM_PHASE_WINDOWS_REPORTS,
            virtusphere_vm_phase(self::vm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_REGISTERED, true), $evidence)
        );
    }

    public function testAnAckBeforeTheBindingIsStillWindowsReports(): void
    {
        self::assertSame(
            VIRTUSPHERE_VM_PHASE_WINDOWS_REPORTS,
            virtusphere_vm_phase(self::vm(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_PENDING, false))
        );
    }

    public function testAResetKeepsTheVmAtMacKnown(): void
    {
        $vm = self::vm(VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING, false)
            + ['mecm_previous_id' => 'E2E-OLD-DEVICE'];

        self::assertSame(VIRTUSPHERE_VM_PHASE_MAC_KNOWN, virtusphere_vm_phase($vm));
    }

    public function testACreateWithoutAnExportIsOnEsxi(): void
    {
        self::assertSame(
            VIRTUSPHERE_VM_PHASE_ON_ESXI,
            virtusphere_vm_phase(self::vm(VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, true))
        );
    }

    public function testAFailedVmWithAFailedMecmSyncIsOnEsxiWhenBoundAndCreatedWhenNot(): void
    {
        self::assertSame(
            VIRTUSPHERE_VM_PHASE_ON_ESXI,
            virtusphere_vm_phase(self::vm(VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED, true))
        );
        self::assertSame(
            VIRTUSPHERE_VM_PHASE_CREATED,
            virtusphere_vm_phase(self::vm(VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED, false))
        );
    }

    #[PHPUnit\Framework\Attributes\DataProvider('stateCombinationsWithStoredStage')]
    public function testThePhaseFollowsTheRuleTableWithTheStoredStage(string $lifecycle, string $mecm, bool $bound, string $stage, string $expected): void
    {
        self::assertSame($expected, virtusphere_vm_phase(self::vm($lifecycle, $mecm, $bound) + ['vm_status' => $stage]));
    }

    /** @return iterable<string, array{0:string}> */
    public static function workerMarks(): iterable
    {
        yield 'failed' => [VIRTUSPHERE_LIFECYCLE_FAILED];
        yield 'deploying' => [VIRTUSPHERE_LIFECYCLE_DEPLOYING];
    }

    /**
     * VB4: the worker writes `deploying` and `failed` without touching the
     * stored x/5 value, so behind such a mark that value is the last evidence.
     */
    #[PHPUnit\Framework\Attributes\DataProvider('workerMarks')]
    public function testAWorkerMarkKeepsThePhaseOfTheLastEvidence(string $mark): void
    {
        $mecm = $mark === VIRTUSPHERE_LIFECYCLE_FAILED ? VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED : VIRTUSPHERE_MECM_SYNC_NOT_READY;
        $vm = static fn (string $stage, bool $bound = true): array => self::vm($mark, $mecm, $bound) + ['vm_status' => $stage];
        $done = ['client_phases' => self::clientPhases(self::allFinished())];

        self::assertSame(VIRTUSPHERE_VM_PHASE_CLIENT_DONE, virtusphere_vm_phase($vm(VIRTUSPHERE_STATUS_OS_INSTALLED), $done));
        self::assertSame(VIRTUSPHERE_VM_PHASE_WINDOWS_REPORTS, virtusphere_vm_phase($vm(VIRTUSPHERE_STATUS_OS_INSTALLED)));
        self::assertSame(VIRTUSPHERE_VM_PHASE_HANDED_TO_MECM, virtusphere_vm_phase($vm(VIRTUSPHERE_STATUS_OS_INSTALLING), $done), 'client evidence alone does not lift the phase');
        self::assertSame(VIRTUSPHERE_VM_PHASE_MAC_KNOWN, virtusphere_vm_phase($vm(VIRTUSPHERE_STATUS_DEPLOYED)));
        self::assertSame(VIRTUSPHERE_VM_PHASE_MAC_KNOWN, virtusphere_vm_phase($vm(VIRTUSPHERE_STATUS_DEPLOYED, false)), 'the MAC import is evidence without a binding too');
    }

    #[PHPUnit\Framework\Attributes\DataProvider('workerMarks')]
    public function testAWorkerMarkInventsNoEvidence(string $mark): void
    {
        $mecm = $mark === VIRTUSPHERE_LIFECYCLE_FAILED ? VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED : VIRTUSPHERE_MECM_SYNC_NOT_READY;
        $unbound = $mark === VIRTUSPHERE_LIFECYCLE_DEPLOYING ? VIRTUSPHERE_VM_PHASE_IN_PROGRESS : VIRTUSPHERE_VM_PHASE_CREATED;

        foreach ([VIRTUSPHERE_STATUS_REGISTERED, VIRTUSPHERE_STATUS_INITIALIZING, '9/5 Unknown', ''] as $stage) {
            self::assertSame(VIRTUSPHERE_VM_PHASE_ON_ESXI, virtusphere_vm_phase(self::vm($mark, $mecm, true) + ['vm_status' => $stage]), $stage);
            self::assertSame($unbound, virtusphere_vm_phase(self::vm($mark, $mecm, false) + ['vm_status' => $stage]), $stage);
        }
        // A row that was read without the column says nothing about evidence.
        self::assertSame(VIRTUSPHERE_VM_PHASE_ON_ESXI, virtusphere_vm_phase(self::vm($mark, $mecm, true)));
        self::assertSame($unbound, virtusphere_vm_phase(self::vm($mark, $mecm, false)));
    }

    public function testTheStoredStageDecidesNothingWithoutAWorkerMark(): void
    {
        // No writer stores this pair; the rule must still not read the x/5 value as an override.
        $vm = self::vm(VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, false) + ['vm_status' => VIRTUSPHERE_STATUS_OS_INSTALLED];
        self::assertSame(VIRTUSPHERE_VM_PHASE_CREATED, virtusphere_vm_phase($vm, ['client_phases' => self::clientPhases(self::allFinished())]));

        // A reset writes deployed, pending and 3/5 together: the new rollout shows itself.
        $reset = self::vm(VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING, true) + ['vm_status' => VIRTUSPHERE_STATUS_DEPLOYED];
        self::assertSame(VIRTUSPHERE_VM_PHASE_MAC_KNOWN, virtusphere_vm_phase($reset, ['client_phases' => self::clientPhases(self::allFinished())]));
    }

    public function testAFailedJobAfterAResetShowsTheNewRolloutDespiteOldClientEvents(): void
    {
        $vm = self::vm(VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED, true) + ['vm_status' => VIRTUSPHERE_STATUS_DEPLOYED];

        self::assertSame(
            VIRTUSPHERE_VM_PHASE_MAC_KNOWN,
            virtusphere_vm_phase($vm, ['client_phases' => self::clientPhases(self::allFinished())])
        );
    }

    public function testAnInitializingVmIsCreated(): void
    {
        self::assertSame(
            VIRTUSPHERE_VM_PHASE_CREATED,
            virtusphere_vm_phase(self::vm(VIRTUSPHERE_LIFECYCLE_INITIALIZING, VIRTUSPHERE_MECM_SYNC_NOT_READY, false))
        );
    }

    public function testTheRankIsStrictlyMonotoneAlongTheChainAndATemplateRanksZero(): void
    {
        $ranks = array_map(virtusphere_vm_phase_rank(...), VIRTUSPHERE_VM_PHASES);

        self::assertSame(range(1, count(VIRTUSPHERE_VM_PHASES)), $ranks);
        self::assertSame(7, count(VIRTUSPHERE_VM_PHASES));
        self::assertSame(0, virtusphere_vm_phase_rank(VIRTUSPHERE_VM_PHASE_TEMPLATE));
    }

    public function testAnUnknownPhaseIsNotRanked(): void
    {
        $this->expectException(InvalidArgumentException::class);

        virtusphere_vm_phase_rank('not_a_phase');
    }

    public function testEveryPhaseAndTheTemplateHaveAPaletteVariant(): void
    {
        foreach ([...VIRTUSPHERE_VM_PHASES, VIRTUSPHERE_VM_PHASE_TEMPLATE] as $phase) {
            self::assertContains(virtusphere_vm_phase_meta($phase)['badge'], self::PALETTE, $phase);
        }
    }

    public function testAnUnknownPhaseIsNeutral(): void
    {
        self::assertSame(['badge' => 'neutral'], virtusphere_vm_phase_meta('not_a_phase'));
    }

    public function testEveryFindingKindHasAPaletteVariant(): void
    {
        foreach (VIRTUSPHERE_VM_FINDINGS as $kind => $variant) {
            self::assertContains($variant, self::PALETTE, $kind);
            self::assertSame(
                ['badge' => $variant],
                virtusphere_vm_finding_meta(['kind' => $kind, 'severity' => $variant, 'params' => [], 'link' => []]),
                $kind
            );
        }
    }

    public function testTheSqlTwinIsOneCaseWithAThenForEveryRankedPhase(): void
    {
        $sql = virtusphere_vm_phase_sql('v');

        self::assertStringStartsWith('CASE', $sql);
        self::assertStringEndsWith('END', $sql);
        foreach (VIRTUSPHERE_VM_PHASES as $phase) {
            $rank = virtusphere_vm_phase_rank($phase);
            if ($phase === VIRTUSPHERE_VM_PHASE_CREATED) {
                self::assertStringContainsString('ELSE ' . $rank . ' END', $sql, $phase);
            } else {
                self::assertStringContainsString('THEN ' . $rank . ' ', $sql, $phase);
            }
        }
    }

    public function testTheSqlTwinNamesEveryRuleTokenAndEveryClientPhase(): void
    {
        $sql = virtusphere_vm_phase_sql('v');
        $tokens = [
            VIRTUSPHERE_LIFECYCLE_OS_INSTALLED,
            VIRTUSPHERE_LIFECYCLE_OS_INSTALLING,
            VIRTUSPHERE_LIFECYCLE_DEPLOYED,
            VIRTUSPHERE_LIFECYCLE_DEPLOYING,
            VIRTUSPHERE_MECM_SYNC_REGISTERED,
            VIRTUSPHERE_MECM_SYNC_PENDING,
            VIRTUSPHERE_CLIENT_EVENT_FINISHED,
            ...VIRTUSPHERE_CLIENT_PHASES,
        ];

        foreach ($tokens as $token) {
            self::assertStringContainsString("'" . $token . "'", $sql, $token);
        }
        self::assertStringContainsString('v.vm_instance_uuid', $sql);
    }

    public function testTheSqlTwinReadsTheStoredStageOnlyBehindTheTwoWorkerMarks(): void
    {
        $sql = virtusphere_vm_phase_sql('v');
        // Compared as bytes: the database collation must not match what the PHP rule does not.
        $behind = "v.lifecycle_state IN ('deploying', 'failed') AND CAST(v.vm_status AS BINARY) = ";

        foreach ([VIRTUSPHERE_STATUS_OS_INSTALLED, VIRTUSPHERE_STATUS_OS_INSTALLING, VIRTUSPHERE_STATUS_DEPLOYED] as $stage) {
            self::assertStringContainsString($behind . "'" . $stage . "'", $sql, $stage);
        }
        self::assertSame(
            substr_count($sql, 'v.vm_status'),
            substr_count($sql, $behind),
            'the stored stage is never read without the mark in front of it'
        );
        foreach ([VIRTUSPHERE_STATUS_REGISTERED, VIRTUSPHERE_STATUS_INITIALIZING] as $stage) {
            self::assertStringNotContainsString($stage, $sql, 'a stage below the MAC import is no evidence');
        }
    }

    /**
     * VB4 widened this guard on purpose: the twin now also names stored x/5
     * values. A literal is a state token or one of those closed constants, so
     * still nothing but a constant of the state machine enters the string.
     */
    public function testAnAliasIsTakenOneToOneAndEveryLiteralIsAStateTokenOrAStoredStage(): void
    {
        $sql = virtusphere_vm_phase_sql('x-1');

        self::assertStringContainsString("x-1.lifecycle_state = 'os_installed'", $sql);
        self::assertStringContainsString('x-1.id', $sql);
        preg_match_all("/'([^']*)'/", $sql, $literals);
        self::assertNotEmpty($literals[1]);
        foreach ($literals[1] as $literal) {
            if (in_array($literal, VIRTUSPHERE_VM_STATUS_COMPAT, true)) {
                continue;
            }
            // The empty literal of the binding predicate is allowed; anything else must be a token.
            self::assertMatchesRegularExpression('/\A[a-z_]*\z/', $literal);
        }
    }

    /** @return array<string,mixed> */
    private static function vm(string $lifecycle, string $mecm, bool $bound): array
    {
        return [
            'lifecycle_state' => $lifecycle,
            'mecm_sync_state' => $mecm,
            'vm_instance_uuid' => $bound ? 'uuid-1' : '',
        ];
    }

    /** @return array<string,?string> */
    private static function allFinished(): array
    {
        return array_fill_keys(VIRTUSPHERE_CLIENT_PHASES, VIRTUSPHERE_CLIENT_EVENT_FINISHED);
    }

    /**
     * @param array<string,?string> $events phase => latest event; a null or absent phase is silent
     * @return array<string,array<string,string>|null>
     */
    private static function clientPhases(array $events): array
    {
        $phases = [];
        foreach (VIRTUSPHERE_CLIENT_PHASES as $phase) {
            $event = $events[$phase] ?? null;
            $phases[$phase] = $event === null ? null : ['event' => $event, 'created_at' => '2026-10-21 10:00:00'];
        }

        return $phases;
    }
}
