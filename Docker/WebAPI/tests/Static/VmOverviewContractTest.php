<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/help_page.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms_modules.php';

/**
 * Markup and ownership contract of the dashboard VM overview (F05): both dashboard
 * tiles open the list with their filter, the capture time travels with them, the
 * tile count and the list read one predicate, the page checks the signed-in user
 * before its first query, and it stays read-only with escaped output.
 */
final class VmOverviewContractTest extends TestCase
{
    /**
     * The help renderer (lib/help/overview.php) is outside the literal scan of
     * LangCatalogTest, so its keys are pinned here. DE/EN parity, placeholders,
     * sentence dashes and umlaut spellings of every catalog value are owned by
     * scripts/lang-audit.php (gate lang-parity, QL4); the vm_overview keys are
     * static __t() literals that LangCatalogTest already resolves.
     */
    private const HELP_KEYS = ['dashboard_counts_heading', 'dashboard_counts_p1'];

    public function testBothDashboardTilesOpenTheListWithTheirFilterAndCaptureTime(): void
    {
        $dashboard = $this->source('portal/dashboard.php');

        self::assertStringContainsString('$kpiCountedAt = time();', $dashboard, 'one capture moment for both tiles');
        // Since VB2 the dashboard and the live poll render the VM tiles through
        // one function; the two tile calls sit there, with the same arguments.
        self::assertStringContainsString('vm_overview_live_tiles($vmCount, $mecmPending, $vmAttentionGroups, $kpiCountedAt)', $dashboard);
        $tiles = $this->source('lib/vm_overview_page.php');
        self::assertStringContainsString('vm_overview_render_tile(VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL, __t(\'dashboard.kpi_vms\'), $vmCount, $countedAt)', $tiles);
        self::assertStringContainsString('vm_overview_render_tile(VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_PENDING, __t(\'dashboard.kpi_mecm_pending\'), $mecmPending, $countedAt, $mecmPending > 0)', $tiles);
        self::assertStringNotContainsString('vm_overview.php?', $dashboard, 'a hand-written tile link would skip the snapshot the list explains itself with');
        self::assertStringNotContainsString('<article class="card kpi"><span class="muted"><?php echo h(__t(\'dashboard.kpi_vms\'))', $dashboard, 'the VM tile is a link now');
    }

    public function testTheTileLinkCarriesItsCaptureTime(): void
    {
        $tile = $this->source('lib/vm_overview_page.php');

        self::assertStringContainsString("vm_overview_url(\$filter, ['snapshot' => ['counted' => \$countedAt, 'expected' => \$count]])", $tile);
    }

    public function testTheCountAndTheListShareOnePredicatePerFilter(): void
    {
        $reader = $this->source('lib/repo/vms_overview.php');

        self::assertSame(1, substr_count($reader, '(v.updated = 1 OR v.mecm_sync_state = ?)'), 'the MECM takeover meaning is written once');
        self::assertSame(1, substr_count($reader, "LEFT(m.mission_name, 1) <> ?"), 'the real-mission predicate is written once');

        $operations = $this->source('lib/repo/vms_operations.php');
        self::assertStringContainsString('return repo_vm_overview_count($db, VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL);', $operations);
        self::assertStringNotContainsString('LEFT(m.mission_name', $operations, 'the legacy count delegates instead of repeating the predicate');

        $dashboard = $this->source('portal/dashboard.php');
        self::assertStringNotContainsString('updated = 1 OR mecm_sync_state', $dashboard, 'the dashboard no longer derives the MECM count itself');
        self::assertStringNotContainsString('repo_vm_count_in_real_missions', $dashboard);

        $page = $this->source('portal/vm_overview.php');
        self::assertStringContainsString('repo_vm_overview_page(', $page);
        self::assertStringNotContainsString('deploy_vms', $page, 'the page queries only through the reader');
    }

    public function testTheOverviewPageChecksTheUserBeforeItsFirstQuery(): void
    {
        $page = $this->source('portal/vm_overview.php');

        $guard = strpos($page, 'portal_require_user($connection)');
        self::assertNotFalse($guard);
        $firstQuery = strpos($page, 'repo_vm_overview_page(');
        self::assertNotFalse($firstQuery);
        self::assertLessThan($firstQuery, $guard, 'the signed-in check runs before the reader is called');
        self::assertStringNotContainsString('$_GET[', $page, 'every parameter goes through request_string() or request_int()');
    }

    public function testTheOverviewIsReadOnlyAndNeitherPostsNorScriptsItself(): void
    {
        foreach (['portal/vm_overview.php', 'lib/vm_overview_page.php', 'lib/vm_overview.php'] as $file) {
            $source = $this->source($file);
            self::assertStringNotContainsString('method="post"', $source, $file);
            self::assertStringNotContainsString('<form', $source, $file);
            self::assertStringNotContainsString('<script', $source, $file);
            self::assertStringNotContainsString('window.confirm', $source, $file);
            self::assertDoesNotMatchRegularExpression('/\s(style|on[a-z]+)=/', $source, $file . ' uses an inline style or handler');
            self::assertStringNotContainsString('portal_copy_', $source, $file . ' offers no copy action (A44)');
        }
    }

    public function testTheOverviewBuildsItsAddressesOnlyThroughItsHelpers(): void
    {
        self::assertStringContainsString("'vm_overview.php?'", $this->source('lib/vm_overview.php'));
        $page = $this->source('lib/vm_overview_page.php');
        self::assertStringContainsString('mission_details_url_from_overview(', $page, 'mission links use the shared helper with the row view (UX03-b)');
        self::assertStringContainsString('vm_edit_url(', $page, 'VM links use the shared helper');
        self::assertStringNotContainsString('mission_details.php?id=', $page);
        self::assertStringNotContainsString('vm_edit.php?', $page);
    }

    public function testTheOverviewIsARegisteredPortalPageWithItsHelpSection(): void
    {
        self::assertContains('vm_overview.php', VIRTUSPHERE_LAYOUT_PAGES);

        $tables = $this->source('lib/layout.php');
        self::assertStringContainsString("'vm_edit.php', 'vm_overview.php', 'vms.php',", $tables, 'the table stylesheet covers the list');

        self::assertSame('overview', VIRTUSPHERE_HELP_SECTIONS['help-dashboard-counts']);
        self::assertStringContainsString('id="help-dashboard-counts"', $this->source('lib/help/overview.php'));

        $page = $this->source('portal/vm_overview.php');
        self::assertMatchesRegularExpression("/layout_header\\(.+'overview',\\s*'help-dashboard-counts'\\);/", $page);
    }

    public function testTheReaderIsRegisteredInTheVmRepositoryOwnerList(): void
    {
        self::assertContains('lib/repo/vms_overview.php', VIRTUSPHERE_VM_REPO_MODULES);
        self::assertStringContainsString("require_once __DIR__ . '/vms_overview.php';", $this->source('lib/repo/vms.php'));
    }

    public function testTheHelpKeysExist(): void
    {
        $help = $this->catalog('de', 'help_overview');
        foreach (self::HELP_KEYS as $key) {
            self::assertArrayHasKey($key, $help, "de/help_overview.php misses {$key}");
        }
    }

    public function testTheGermanTextKeepsItsWordingAndFormalAddress(): void
    {
        $de = $this->catalog('de', 'vm_overview');
        self::assertStringContainsString('Zurzeit wartet keine VM', $de['empty_mecm_pending']);

        $help = $this->catalog('de', 'help_overview')['dashboard_counts_p1'];
        self::assertStringContainsString('öffnen Sie', $help, 'help addresses the operator formally');
        self::assertStringNotContainsString('öffnest', $help);
    }

    public function testTheMecmTileWarnsOnlyForAPositiveCount(): void
    {
        // Carried over from the old article: a zero stays neutral, a waiting VM is amber.
        // The tile call moved into vm_overview_live_tiles() with VB2.
        $tiles = $this->source('lib/vm_overview_page.php');
        self::assertStringContainsString('$mecmPending, $countedAt, $mecmPending > 0)', $tiles);
    }

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return array<string,string> */
    private function catalog(string $locale, string $module): array
    {
        $catalog = require dirname(__DIR__, 2) . '/lang/' . $locale . '/' . $module . '.php';
        self::assertIsArray($catalog);

        return $catalog;
    }
}
