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
    private const NEW_KEYS = [
        'vm_overview' => ['title_all', 'title_mecm_pending', 'intro_all', 'intro_mecm_pending', 'filter_label',
            'filter_all', 'filter_mecm_pending', 'template_badge', 'open_vm', 'hits', 'count_changed',
            'empty_all', 'empty_mecm_pending', 'back_dashboard', 'pagination_label', 'previous', 'next', 'page_info'],
        'help_overview' => ['dashboard_counts_heading', 'dashboard_counts_p1'],
    ];

    public function testBothDashboardTilesOpenTheListWithTheirFilterAndCaptureTime(): void
    {
        $dashboard = $this->source('portal/dashboard.php');

        self::assertStringContainsString('$kpiCountedAt = time();', $dashboard, 'one capture moment for both tiles');
        self::assertStringContainsString('vm_overview_render_tile(VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL, __t(\'dashboard.kpi_vms\'), $vmCount, $kpiCountedAt)', $dashboard);
        self::assertStringContainsString('vm_overview_render_tile(VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_PENDING, __t(\'dashboard.kpi_mecm_pending\'), $mecmPending, $kpiCountedAt, $mecmPending > 0)', $dashboard);
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
        self::assertStringContainsString('mission_details_url(', $page, 'mission links use the shared helper');
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

    public function testTheNewCatalogKeysExistInBothLocalesWithTheSamePlaceholders(): void
    {
        foreach (array_keys(self::NEW_KEYS) as $module) {
            $de = $this->catalog('de', $module);
            $en = $this->catalog('en', $module);
            foreach (self::NEW_KEYS[$module] as $key) {
                self::assertArrayHasKey($key, $de, "de/{$module}.php misses {$key}");
                self::assertArrayHasKey($key, $en, "en/{$module}.php misses {$key}");
                self::assertSame(
                    $this->placeholders($de[$key]),
                    $this->placeholders($en[$key]),
                    "{$module}.{$key} placeholders differ between DE and EN"
                );
            }
        }

        // The module catalogs stay in parity as a whole, not only for the new keys.
        self::assertSame(
            array_keys($this->catalog('de', 'vm_overview')),
            array_keys($this->catalog('en', 'vm_overview'))
        );
    }

    public function testTheNewPortalTextHasNoDashAsSentencePunctuation(): void
    {
        $dash = ["\u{2014}", "\u{2013}"];
        foreach (['de', 'en'] as $locale) {
            foreach (array_keys(self::NEW_KEYS) as $module) {
                foreach ($this->catalog($locale, $module) as $key => $value) {
                    if (!in_array($key, self::NEW_KEYS[$module], true)) {
                        continue;
                    }
                    foreach ($dash as $char) {
                        self::assertStringNotContainsString($char, $value, "{$locale}/{$module}.{$key} uses a dash as sentence punctuation");
                    }
                }
            }
        }
    }

    public function testTheGermanTextUsesRealUmlautsAndFormalAddress(): void
    {
        $de = $this->catalog('de', 'vm_overview');
        self::assertStringContainsString('ü', $de['intro_mecm_pending']);
        self::assertStringNotContainsString(' ue', $de['empty_mecm_pending']);
        self::assertStringContainsString('Zurzeit wartet keine VM', $de['empty_mecm_pending']);

        $help = $this->catalog('de', 'help_overview')['dashboard_counts_p1'];
        self::assertStringContainsString('öffnen Sie', $help, 'help addresses the operator formally');
        self::assertStringNotContainsString('öffnest', $help);
    }

    public function testTheMecmTileWarnsOnlyForAPositiveCount(): void
    {
        // Carried over from the old article: a zero stays neutral, a waiting VM is amber.
        $dashboard = $this->source('portal/dashboard.php');
        self::assertStringContainsString('$mecmPending > 0', $dashboard);
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

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/:[a-z_]+/', $text, $matches);
        $found = array_values(array_unique($matches[0]));
        sort($found);

        return $found;
    }
}
