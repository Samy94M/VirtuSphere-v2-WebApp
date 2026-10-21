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
                    if ($lifecycle === VIRTUSPHERE_LIFECYCLE_OS_INSTALLED) {
                        $expected = VIRTUSPHERE_VM_PHASE_WINDOWS_REPORTS;
                    } elseif ($lifecycle === VIRTUSPHERE_LIFECYCLE_OS_INSTALLING || $mecm === VIRTUSPHERE_MECM_SYNC_REGISTERED) {
                        $expected = VIRTUSPHERE_VM_PHASE_HANDED_TO_MECM;
                    } elseif ($lifecycle === VIRTUSPHERE_LIFECYCLE_DEPLOYED || $mecm === VIRTUSPHERE_MECM_SYNC_PENDING) {
                        $expected = VIRTUSPHERE_VM_PHASE_MAC_KNOWN;
                    } elseif ($bound) {
                        $expected = VIRTUSPHERE_VM_PHASE_ON_ESXI;
                    } elseif ($lifecycle === VIRTUSPHERE_LIFECYCLE_DEPLOYING) {
                        $expected = VIRTUSPHERE_VM_PHASE_IN_PROGRESS;
                    } else {
                        $expected = VIRTUSPHERE_VM_PHASE_CREATED;
                    }

                    yield $lifecycle . ' / ' . $mecm . ' / ' . ($bound ? 'bound' : 'unbound') => [$lifecycle, $mecm, $bound, $expected];
                }
            }
        }
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
            virtusphere_vm_phase(self::vm(VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_FAILED, true))
        );
        self::assertSame(
            VIRTUSPHERE_VM_PHASE_CREATED,
            virtusphere_vm_phase(self::vm(VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_FAILED, false))
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

    public function testAnAliasIsTakenOneToOneAndEveryLiteralIsAStateToken(): void
    {
        $sql = virtusphere_vm_phase_sql('x-1');

        self::assertStringContainsString("x-1.lifecycle_state = 'os_installed'", $sql);
        self::assertStringContainsString('x-1.id', $sql);
        preg_match_all("/'([^']*)'/", $sql, $literals);
        self::assertNotEmpty($literals[1]);
        foreach ($literals[1] as $literal) {
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
