<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The overview strip and the deviation count it now carries.
 *
 * Two things drift silently here and neither shows up as an error. The first is
 * the column count: the strip is a fixed grid, so a card added to the PHP alone
 * starts a second row holding one card, which reads as a rendering fault rather
 * than as a design. The second is the count itself. It used to be summed inside
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
        $start = strpos($source, '$cards = [');
        self::assertNotFalse($start, 'system_status_render_overview() must build its cards in one list');
        $end = strpos($source, '];', $start);
        self::assertNotFalse($end);
        $block = substr($source, $start, $end - $start);

        preg_match_all('/VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_[A-Z_]+/', $block, $m);
        self::assertNotSame([], $m[0], 'the card list must name its anchors through the constants');

        return $m[0];
    }

    public function testTheGridHasOneColumnPerCard(): void
    {
        $cards = $this->overviewCards();
        $css = $this->source('portal/assets/css/status.css');

        self::assertSame(
            1,
            preg_match('/^\.status-overview \{(.*?)\}/ms', $css, $block),
            '.status-overview must exist in status.css'
        );
        self::assertSame(
            1,
            preg_match('/grid-template-columns:\s*repeat\((\d+),/', $block[1], $columns),
            'the strip must declare an explicit column count'
        );
        self::assertSame(
            count($cards),
            (int) $columns[1],
            'the strip renders ' . count($cards) . ' cards; the grid must give each one a column'
        );
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
