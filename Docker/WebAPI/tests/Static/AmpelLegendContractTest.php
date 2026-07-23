<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The System status page and its help panel each used to hand-list the states
 * of the heartbeat Ampel, and had already drifted: help explained five states,
 * the page's own legend four. The one it dropped was `missing`, which the page
 * renders as a badge, so the badge an operator can actually see there had no
 * entry to look up on the page they were looking at.
 *
 * The state sets now live in lib/constants.php and both legends render through
 * system_status_legend_items(). This pins that: neither side may re-inline a
 * state list, both must call the renderer, every state must have a translated
 * explanation in both locales, and a new state cannot be added to a constant
 * without its texts.
 */
final class AmpelLegendContractTest extends TestCase
{
    /** kind => [constant, lang key prefix] */
    private const AMPELN = [
        'heartbeat' => [VIRTUSPHERE_HEARTBEAT_STATES, 'legend_'],
        'esxi' => [VIRTUSPHERE_ESXI_AMPEL_STATES, 'esxi_legend_'],
        'ansible' => [VIRTUSPHERE_ANSIBLE_AMPEL_STATES, 'ansible_legend_'],
    ];

    private const RENDERERS = [
        'lib/system_status_panels.php',
        'lib/help/system_status.php',
    ];

    private function source(string $path): string
    {
        $full = str_replace('\\', '/', dirname(__DIR__, 2)) . '/' . $path;
        self::assertFileExists($full, $path . ' must exist');

        return (string) file_get_contents($full);
    }

    /** @return array<string,string> */
    private function catalog(string $locale): array
    {
        return require str_replace('\\', '/', dirname(__DIR__, 2)) . '/lang/' . $locale . '/system_status.php';
    }

    public function testEveryStateHasAnExplanationInBothLocales(): void
    {
        foreach (self::AMPELN as $kind => [$states, $prefix]) {
            foreach (['de', 'en'] as $locale) {
                $catalog = $this->catalog($locale);
                foreach ($states as $state) {
                    self::assertArrayHasKey(
                        $prefix . $state,
                        $catalog,
                        $locale . ' has no legend text for the ' . $kind . ' state "' . $state . '"'
                    );
                }
            }
        }
    }

    public function testEveryBadgeLabelExistsForEveryState(): void
    {
        // A state whose badge falls through to the "unknown" label would render
        // two different states with the same word, which is the failure the
        // separate label sets exist to prevent.
        foreach (self::AMPELN as $kind => [$states, $unusedPrefix]) {
            $labelPrefix = match ($kind) {
                'esxi' => 'esxi_state_',
                'ansible' => 'ansible_state_',
                default => 'status_',
            };
            foreach (['de', 'en'] as $locale) {
                $catalog = $this->catalog($locale);
                foreach ($states as $state) {
                    self::assertArrayHasKey(
                        $labelPrefix . $state,
                        $catalog,
                        $locale . ' has no badge label for the ' . $kind . ' state "' . $state . '"'
                    );
                }
            }
        }
    }

    public function testNeitherLegendReInlinesAStateList(): void
    {
        // Every panel module, not just the two that host a legend today: a state
        // list inlined into the module that owns the ESXi cards would drift just
        // as quietly as the one that started this.
        $paths = array_map(
            static fn (string $p): string => 'lib/' . basename($p),
            glob(str_replace('\\', '/', dirname(__DIR__, 2)) . '/lib/system_status_*panels.php') ?: []
        );
        $paths[] = 'lib/help/system_status.php';
        self::assertGreaterThan(1, count($paths), 'no panel modules found');

        foreach ($paths as $path) {
            $source = $this->source($path);
            self::assertSame(
                0,
                preg_match("/\\[\\s*'ok'\\s*,\\s*'warning'\\s*,\\s*'danger'/", $source),
                $path . ' re-inlines an Ampel state list; iterate the constant through system_status_legend_items() instead'
            );
        }
    }

    public function testBothLegendsUseTheSharedRenderer(): void
    {
        foreach (self::RENDERERS as $path) {
            self::assertStringContainsString(
                'system_status_legend_items(',
                $this->source($path),
                $path . ' must render its legend through the shared renderer'
            );
        }
    }

    public function testPageLegendCoversEveryAmpelItRenders(): void
    {
        // The page shows three vocabularies; explaining one of them and calling
        // it "the legend" is how the heartbeat/ESXi wording confusion started.
        $source = $this->source('lib/system_status_panels.php');
        foreach (array_keys(self::AMPELN) as $kind) {
            self::assertStringContainsString(
                "system_status_legend_items('" . $kind . "')",
                $source,
                'the System status legend does not explain the ' . $kind . ' Ampel it renders'
            );
        }
    }

    public function testHelpExplainsTheSameHeartbeatStatesAsThePage(): void
    {
        // Belt and braces for the original defect: both files must reach the
        // heartbeat legend, and neither may pass a narrowed state set.
        foreach (self::RENDERERS as $path) {
            self::assertStringContainsString(
                "system_status_legend_items('heartbeat')",
                $this->source($path),
                $path . ' must explain the heartbeat Ampel'
            );
        }
        self::assertContains('missing', VIRTUSPHERE_HEARTBEAT_STATES);
    }
}
