<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The overview strip and the deviation count it now carries.
 *
 * Two things drift silently here and neither shows up as an error. The first is
 * the responsive grid: the optional directory card means the count is not fixed,
 * and seven cards would leave one alone on the last row of a three-column grid.
 * The second is the deviation count itself. It used to be summed inside
 * the deviation renderer; with a second reader in the strip above, two sums
 * would be two numbers the moment one of them is edited, and a strip saying
 * "3 deviations" over a section listing four is worse than no strip at all. So
 * exactly one function produces it and both readers take it as an argument.
 */
final class SystemStatusOverviewContractTest extends TestCase
{
    private function repoRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    private function source(string $relative): string
    {
        $path = $this->repoRoot() . '/' . $relative;
        self::assertFileExists($path, $relative . ' must exist');

        return (string) file_get_contents($path);
    }

    /** The card list of system_status_render_overview(), as anchor constants. */
    private function overviewCards(): array
    {
        $source = $this->source('lib/system_status_panels.php');
        $start = strpos($source, 'function system_status_render_overview(');
        self::assertNotFalse($start, 'system_status_render_overview() must build its cards in one list');
        $end = strpos($source, '?>', $start);
        self::assertNotFalse($end);
        $block = substr($source, $start, $end - $start);

        preg_match_all('/VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_[A-Z_]+/', $block, $m);
        self::assertNotSame([], $m[0], 'the card list must name its anchors through the constants');

        return $m[0];
    }

    /** The whole renderer function, up to its closing brace, so the nav markup is in scope too. */
    private function overviewFunction(): string
    {
        $source = $this->source('lib/system_status_panels.php');
        $start = strpos($source, 'function system_status_render_overview(');
        self::assertNotFalse($start);
        $end = strpos($source, "\n}", $start);
        self::assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }

    /** The one CSS block of a selector, with the leading block name anchored. */
    private function cssBlock(string $css, string $selector): string
    {
        self::assertSame(
            1,
            preg_match('/^' . preg_quote($selector, '/') . ' \{(.*?)\}/ms', $css, $block),
            $selector . ' must exist in status.css'
        );

        return $block[1];
    }

    public function testTheGridIsThreeColumnsWithAFourColumnModifierForSevenCards(): void
    {
        $cards = $this->overviewCards();
        $function = $this->overviewFunction();
        $css = $this->source('portal/assets/css/status.css');

        // The modifier comes from the same list the nav iterates, so the count
        // the layout uses is the count the strip shows.
        self::assertSame(
            1,
            preg_match('/\$overviewModifier = count\(\$cards\) % 3 === 1 \? \' status-overview-four\' : \'\';/', $function),
            'the modifier must be derived from the card list: one card alone in the last row of a 3-column grid'
        );
        self::assertSame(
            1,
            preg_match('/<nav class="status-overview<\?php echo \$overviewModifier; \?>"/', $function),
            'the nav must carry the derived modifier'
        );
        self::assertSame(
            1,
            preg_match('/foreach \(\$cards as/', $function),
            'the nav must render the same card list the count is taken from'
        );

        // Desktop: three per row by default, four only with the modifier. No
        // auto-fit: the count is not fixed, but the column count is decided here.
        $base = $this->cssBlock($css, '.status-overview');
        self::assertMatchesRegularExpression('/grid-template-columns:\s*repeat\(3,\s*minmax\(0,\s*1fr\)\);/', $base);
        self::assertDoesNotMatchRegularExpression('/auto-fit/', $base, 'auto-fit put all six cards on one row');
        self::assertMatchesRegularExpression(
            '/grid-template-columns:\s*repeat\(4,\s*minmax\(0,\s*1fr\)\);/',
            $this->cssBlock($css, '.status-overview-four'),
            'the modifier must give the 4 + 3 layout for seven cards'
        );

        // Small screens: two columns at 850px, one at 560px, for both counts.
        self::assertSame(
            1,
            preg_match('/@media \(max-width: 850px\) \{\s*\.status-overview,\s*\.status-overview-four \{\s*grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\);/', $css),
            'the 850px step must hold both counts at two columns'
        );
        self::assertSame(
            1,
            preg_match('/@media \(max-width: 560px\) \{\s*\.status-overview,\s*\.status-overview-four \{\s*grid-template-columns:\s*minmax\(0,\s*1fr\);/', $css),
            'the 560px step must hold both counts at one column'
        );
        self::assertDoesNotMatchRegularExpression(
            '/@media \(max-width: 1180px\)\s*\{\s*\.status-overview/',
            $css,
            'the 1180px step would override the four-column modifier back to three'
        );

        self::assertSame($cards, array_values(array_unique($cards)), 'an overview target may appear only once');
        self::assertContains('VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEPLOY_SERVICE', $cards);
        self::assertContains('VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DIRECTORY', $cards);
    }

    public function testTheDeviationScanHasItsOwnCard(): void
    {
        self::assertContains(
            'VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEVIATIONS',
            $this->overviewCards(),
            'the deviation scan is a health signal like the other four and belongs in the strip'
        );
    }

    public function testEveryCardLinksThroughTheUrlBuilder(): void
    {
        $source = $this->source('lib/system_status_panels.php');
        self::assertStringContainsString(
            'href="<?php echo h(system_status_url(',
            $source,
            'the overview cards must build their targets with system_status_url()'
        );
        self::assertStringNotContainsString(
            'href="#<?php',
            $source,
            'a hand-written fragment bypasses the anchor validation the builder performs'
        );
    }

    public function testCardContentStacksAndOnlyItsBadgeMayWrap(): void
    {
        $renderer = $this->source('lib/system_status_panels.php');
        $css = $this->source('portal/assets/css/status.css');

        self::assertStringContainsString('class="status-overview-label"', $renderer);
        self::assertSame(1, preg_match('/^\.status-overview-card \{(.*?)\}/ms', $css, $card));
        self::assertMatchesRegularExpression('/flex-direction:\s*column;/', $card[1]);
        self::assertMatchesRegularExpression('/align-items:\s*flex-start;/', $card[1]);

        self::assertSame(1, preg_match('/^\.status-overview-card \.badge \{(.*?)\}/ms', $css, $badge));
        self::assertMatchesRegularExpression('/max-width:\s*100%;/', $badge[1]);
        self::assertMatchesRegularExpression('/white-space:\s*normal;/', $badge[1]);
        self::assertMatchesRegularExpression('/overflow-wrap:\s*anywhere;/', $badge[1]);
        self::assertDoesNotMatchRegularExpression(
            '/^\.badge\s*\{[^}]*white-space:\s*normal;/ms',
            $css,
            'wrapping is a local overview-card exception, not a global badge contract change'
        );
    }

    public function testVisualFixtureUsesTheRealLongestDeployStatus(): void
    {
        $translations = require $this->repoRoot() . '/lang/de/system_status.php';
        $contractPath = dirname($this->repoRoot(), 2) . '/tests/e2e/visual/runner-contract.json';
        $contract = json_decode((string) file_get_contents($contractPath), true, 32, JSON_THROW_ON_ERROR);
        $pages = array_column($contract['pages'], null, 'name');

        self::assertArrayHasKey('system-status', $pages);
        self::assertSame('.status-overview', $pages['system-status']['captureSelector']);
        $badges = array_column($pages['system-status']['fixture']['overviewBadges'], null, 'href');
        self::assertSame(
            $translations['service_attention_manual'],
            $badges['system_status.php#deploy-service']['text'],
            'the reviewed geometry must render the actual localized long deploy status'
        );
    }

    public function testTheCountIsProducedInExactlyOnePlace(): void
    {
        $producer = $this->source('lib/system_status.php');
        self::assertStringContainsString(
            'function system_status_deviation_count(',
            $producer,
            'the deviation count must have one owner'
        );

        // No reader may re-sum the issue lists. The renderer did exactly this
        // before the strip existed, which is why the rule is worth a guard.
        foreach (['lib/system_status_esxi_panels.php', 'lib/system_status_panels.php', 'portal/system_status.php'] as $reader) {
            self::assertDoesNotMatchRegularExpression(
                "/array_sum\(array_map\([^;]*'issues'/s",
                $this->source($reader),
                $reader . ' re-derives the deviation count; take it from system_status_deviation_count()'
            );
        }
    }

    public function testBothReadersRenderTheCountThroughOneBadge(): void
    {
        foreach (['lib/system_status_esxi_panels.php', 'lib/system_status_panels.php'] as $reader) {
            self::assertStringContainsString(
                'deviation_count_badge(',
                $this->source($reader),
                $reader . ' must render the count through the shared badge, so colour and wording cannot drift'
            );
        }
    }
}
