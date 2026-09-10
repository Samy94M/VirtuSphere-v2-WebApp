<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SC-023 presentation matrix. These cases deliberately render synthetic report
 * projections: the product evidence/union query remains covered by its existing
 * integration path and must not be weakened to make a message appear.
 */
final class SystemStatusDeviationEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/portal_time.php';
        require_once dirname(__DIR__, 2) . '/lib/csrf.php';
        require_once dirname(__DIR__, 2) . '/lib/layout.php';
        require_once dirname(__DIR__, 2) . '/lib/system_status_esxi_panels.php';
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_start();
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function kinds(string $datacenter, string $datastore, string $network): array
    {
        $facts = [];
        foreach ([
            VIRTUSPHERE_INVENTORY_KIND_DATACENTER => $datacenter,
            VIRTUSPHERE_INVENTORY_KIND_DATASTORE => $datastore,
            VIRTUSPHERE_INVENTORY_KIND_NETWORK => $network,
        ] as $kind => $state) {
            $facts[$kind] = [
                'qualified' => $state !== 'unavailable',
                'current' => $state === 'current',
                'historical' => $state === 'historical',
                'last_observed_at' => '2026-09-10 08:00:00',
                'name_count' => 1,
            ];
        }
        return $facts;
    }

    /**
     * @param array<string,mixed> $report
     * @param array<int,array<string,mixed>> $deviations
     */
    private function render(array $report, array $deviations = []): string
    {
        $view = system_status_deviation_view($deviations, 'all', '', 1);
        $count = system_status_deviation_count($deviations, (bool) ($report['has_evidence'] ?? false));
        ob_start();
        system_status_render_deviations(
            $view,
            ['VLAN_TARGET'],
            ['id' => 1, 'role' => 'admin'],
            '',
            $count,
            $report
        );
        return (string) ob_get_clean();
    }

    /** @return array<int,array<string,mixed>> */
    private function vlanDeviation(): array
    {
        return [[
            'mission_id' => 7,
            'mission_name' => 'SC023',
            'is_template' => false,
            'vm_id' => null,
            'vm_name' => '',
            'issues' => [['field' => 'vlan', 'value' => 'VLAN_OLD']],
        ]];
    }

    public function testNoConfiguredSourceIsNotDescribedAsACompletedPull(): void
    {
        $html = $this->render([
            'source_count' => 0,
            'has_evidence' => false,
            'fully_evaluable' => false,
            'fully_current' => false,
            'kinds' => $this->kinds('unavailable', 'unavailable', 'unavailable'),
        ]);

        self::assertStringContainsString(h(__t('system_status.dev_no_sources')), $html);
        self::assertStringContainsString('href="credentials.php"', $html);
        self::assertStringNotContainsString(h(__t('system_status.dev_no_qualified_evidence')), $html);
    }

    public function testStoredInventoryWithoutQualifiedAllSourceEvidenceNamesThatGap(): void
    {
        $html = $this->render([
            'source_count' => 2,
            'has_evidence' => false,
            'fully_evaluable' => false,
            'fully_current' => false,
            'kinds' => $this->kinds('unavailable', 'unavailable', 'unavailable'),
        ]);

        self::assertStringContainsString(h(__t('system_status.dev_no_qualified_evidence')), $html);
        self::assertStringContainsString('href="system_status.php#esxi"', $html);
        self::assertStringNotContainsString(h(__t('system_status.dev_no_sources')), $html);
    }

    public function testPartialEvidenceDoesNotTurnAnEmptySubsetIntoAllClear(): void
    {
        $html = $this->render([
            'source_count' => 2,
            'has_evidence' => true,
            'fully_evaluable' => false,
            'fully_current' => false,
            'kinds' => $this->kinds('current', 'unavailable', 'unavailable'),
        ]);

        self::assertStringContainsString(h(__t('system_status.dev_evidence_partial')), $html);
        self::assertStringContainsString(h(__t('system_status.dev_none_partial')), $html);
        self::assertStringNotContainsString(h(__t('system_status.dev_none')), $html);
    }

    public function testCompleteHistoricalEvidenceRemainsDiagnostic(): void
    {
        $report = [
            'source_count' => 2,
            'has_evidence' => true,
            'fully_evaluable' => true,
            'fully_current' => false,
            'kinds' => $this->kinds('historical', 'historical', 'historical'),
        ];
        $empty = $this->render($report);

        self::assertStringContainsString(h(__t('system_status.dev_evidence_historical')), $empty);
        self::assertStringContainsString(h(__t('system_status.dev_none_historical')), $empty);
    }

    public function testCompleteCurrentEvidenceCanShowAllClearAndOfferCurrentRepair(): void
    {
        $report = [
            'source_count' => 2,
            'has_evidence' => true,
            'fully_evaluable' => true,
            'fully_current' => true,
            'kinds' => $this->kinds('current', 'current', 'current'),
        ];
        $empty = $this->render($report);
        $withVlan = $this->render($report, $this->vlanDeviation());

        self::assertStringContainsString(h(__t('system_status.dev_evidence_current')), $empty);
        self::assertStringContainsString(h(__t('system_status.dev_none')), $empty);
        self::assertStringContainsString('reassign_from=VLAN_OLD', $withVlan);
        self::assertStringContainsString('<details class="repair-actions"', $withVlan);
    }
}
