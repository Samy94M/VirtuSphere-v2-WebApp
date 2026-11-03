<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/integration_health.php';
require_once dirname(__DIR__, 2) . '/lib/system_status_mecm_panels.php';

final class SystemStatusSiteEvidenceTest extends TestCase
{
    private const NOW = 1791201600;

    protected function setUp(): void
    {
        Lang::load('de');
    }

    private function row(int $age, string $category = ''): array
    {
        return [
            'last_result_at' => gmdate('Y-m-d H:i:s', self::NOW - $age),
            'last_status' => $category === '' ? VIRTUSPHERE_RUN_OUTCOME_OK : VIRTUSPHERE_RUN_OUTCOME_FAIL,
            'last_error_category' => $category,
            'interval_seconds' => 300,
            'last_summary' => '{"site_code":"P01","provider":"provider.example.test"}',
        ];
    }

    public function testAnOldCriticalResultIsHistoricalWhileTheReporterIsOverdue(): void
    {
        $row = $this->row(3600, VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL);
        $entries = integration_health_site_evidence_rows([
            ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH, 'row' => $row, 'state' => 'stale'],
        ], self::NOW);
        self::assertSame('warning', $entries[0]['state']);
        self::assertSame('warning', $entries[0]['reporter_state']);
        self::assertSame('stale', $entries[0]['result_state']);
        self::assertSame($row, $entries[0]['row'], 'historical diagnostics are retained verbatim');
        ob_start();
        system_status_render_site($entries);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Überfällig', $html);
        self::assertStringContainsString('Veraltet', $html);
        self::assertStringContainsString(h(__t('system_status.site_historical_result')), $html);
        self::assertStringContainsString(h(__t('system_status.err_site_critical')), $html);
        self::assertStringNotContainsString('badge-danger', $html, 'old criticality is not a current critical verdict');
    }

    public function testAFreshCriticalResultDoesNotClaimADeadReporter(): void
    {
        $row = $this->row(30, VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL);
        $entries = integration_health_site_evidence_rows([
            ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH, 'row' => $row, 'state' => 'danger'],
        ], self::NOW);
        self::assertSame('danger', $entries[0]['state']);
        self::assertSame('ok', $entries[0]['reporter_state']);
        self::assertSame('danger', $entries[0]['result_state']);
    }

    public function testTheReporterDeadlineIsInclusiveAndRejectsInvalidEvidence(): void
    {
        $limit = max(300 * VIRTUSPHERE_HEARTBEAT_WARN_MULTIPLIER, VIRTUSPHERE_HEARTBEAT_WARN_FLOOR_SECONDS);
        self::assertSame('ok', virtusphere_site_reporter_state($this->row($limit), self::NOW));
        self::assertSame('warning', virtusphere_site_reporter_state($this->row($limit + 1), self::NOW));
        self::assertSame('unknown', virtusphere_site_reporter_state(['last_result_at' => 'unreadable'], self::NOW));
        self::assertSame('unknown', virtusphere_site_reporter_state($this->row(-VIRTUSPHERE_STATUS_EVIDENCE_FUTURE_SKEW_SECONDS - 1), self::NOW));
    }

    public function testStaleHasItsOwnBadgeAndLegendInBothLocales(): void
    {
        self::assertContains('stale', VIRTUSPHERE_HEARTBEAT_STATES);
        self::assertStringContainsString('Veraltet', heartbeat_badge('stale'));
        foreach (['de', 'en'] as $locale) {
            $catalog = require dirname(__DIR__, 2) . '/lang/' . $locale . '/system_status.php';
            self::assertArrayHasKey('legend_stale', $catalog);
            self::assertNotSame('', $catalog['legend_stale']);
        }
    }

    public function testTheExistingPresenterDoesNotHideAnOverdueReporterAsNoData(): void
    {
        ob_start();
        system_status_render_site([
            ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH, 'row' => $this->row(3600, VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL), 'state' => 'stale'],
        ]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Überfällig', $html);
        self::assertStringContainsString('Veraltet', $html);
        self::assertStringNotContainsString('Noch keine Daten', $html);
    }
}
