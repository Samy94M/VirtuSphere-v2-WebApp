<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/portal_status_display.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase.php';

/**
 * The VM path figure of the help page (lib/help_vm_path.php), rendered in
 * both locales with no database: one numbered step per derived phase in code
 * order, each with its portal label and its caption from the help catalog,
 * joined by connectors. The template is no step.
 *
 * The checks read the markup: the structure a screen reader and the layout rely
 * on, the phase order, the localised captions, and the absence of scripts, inline
 * styles, event handlers, links, dashes and raw tokens.
 *
 * The partial is required inside the helper, after its existence is asserted:
 * a missing file must fail these tests by name, not abort the whole unit run at
 * collection time.
 */
final class VmPathSvgRenderTest extends TestCase
{
    private const PARTIAL = 'lib/help_vm_path.php';
    private const STEP_MARKER = 'class="vm-path-step vm-path-step-';

    protected function tearDown(): void
    {
        Lang::load(Lang::DEFAULT_LOCALE);
    }

    /** @return list<array{0:string}> */
    public static function locales(): array
    {
        return [['de'], ['en']];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testFigureAndSvgCarryTheAccessibleStructure(string $locale): void
    {
        $html = self::figure($locale);

        self::assertStringContainsString('<figure class="vm-path">', $html);
        self::assertStringContainsString('<svg class="vm-path-svg"', $html);
        self::assertStringContainsString('viewBox="', $html);
        self::assertStringContainsString('role="img"', $html);
        self::assertStringContainsString('aria-labelledby="vm-path-title vm-path-desc"', $html);
        self::assertStringContainsString('focusable="false"', $html);
        self::assertSame(1, preg_match('/<title id="vm-path-title">[^<]+<\/title>/', $html), 'the svg needs a non-empty title');
        self::assertSame(1, preg_match('/<desc id="vm-path-desc">[^<]+<\/desc>/', $html), 'the svg needs a non-empty desc');
        self::assertStringContainsString('<figcaption', $html);
    }

    #[PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testEveryPhaseIsOneNumberedStepInCodeOrder(string $locale): void
    {
        $html = self::figure($locale);

        self::assertSame(count(VIRTUSPHERE_VM_PHASES), substr_count($html, self::STEP_MARKER), 'one step per derived phase');
        $first = strpos($html, self::STEP_MARKER);
        self::assertIsInt($first, 'the figure has no step');
        $position = $first;
        foreach (VIRTUSPHERE_VM_PHASES as $index => $phase) {
            $badge = virtusphere_vm_phase_meta($phase)['badge'];
            self::assertStringContainsString('vm-path-step-' . $badge, $html, $phase . ' has no step of badge ' . $badge);
            self::assertStringContainsString('>' . ($index + 1) . '<', $html, $phase . ' has no step number');

            $label = h(portal_vm_phase_label($phase));
            $found = strpos($html, $label, $position);
            self::assertIsInt($found, $phase . ' label "' . $label . '" is missing or out of phase order');
            $position = $found + strlen($label);

            $key = 'help_vm_path.caption_' . $phase;
            $caption = __t($key);
            self::assertNotSame($key, $caption, $key . ' is missing from the help catalog');
            self::assertStringContainsString(h($caption), $html, $phase . ' caption is not shown');
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testConnectorsJoinNeighbouringStepsOnly(string $locale): void
    {
        self::assertSame(
            count(VIRTUSPHERE_VM_PHASES) - 1,
            substr_count(self::figure($locale), 'class="vm-path-connector"')
        );
    }

    #[PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testDescriptionNamesEveryPhaseLabel(string $locale): void
    {
        $html = self::figure($locale);

        self::assertSame(1, preg_match('/<desc id="vm-path-desc">(.*?)<\/desc>/s', $html, $match), 'the svg has no desc');
        foreach (VIRTUSPHERE_VM_PHASES as $phase) {
            self::assertStringContainsString(h(portal_vm_phase_label($phase)), $match[1], $phase . ' is missing from the desc');
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testFigureHasNoScriptStyleHandlerLinkOrDashAndNoRawToken(string $locale): void
    {
        $html = self::figure($locale);

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString(' style=', $html);
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', $html);
        self::assertStringNotContainsString('href=', $html);
        self::assertStringNotContainsString("\u{2014}", $html);
        foreach (VIRTUSPHERE_VM_PHASES as $phase) {
            self::assertStringNotContainsString('>' . $phase . '<', $html, 'the raw token ' . $phase . ' is shown');
        }
    }

    public function testGermanAndEnglishFiguresDiffer(): void
    {
        $key = 'help_vm_path.caption_' . VIRTUSPHERE_VM_PHASE_CREATED;

        $de = self::figure('de');
        $deCaption = __t($key);
        $en = self::figure('en');
        $enCaption = __t($key);

        self::assertNotSame($deCaption, $enCaption, $key . ' is not localised');
        self::assertNotSame($de, $en, 'the German and English figures render the same');
    }

    #[PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testTemplateHasNoStep(string $locale): void
    {
        $html = self::figure($locale);

        self::assertSame(1, preg_match('/<svg\b.*<\/svg>/s', $html, $svg), 'the figure has no svg');
        $template = h(portal_vm_phase_label(VIRTUSPHERE_VM_PHASE_TEMPLATE));
        self::assertStringNotContainsString($template, $svg[0], 'the template is a mission property and has no step');
    }

    private static function figure(string $locale): string
    {
        $partial = dirname(__DIR__, 2) . '/' . self::PARTIAL;
        self::assertFileExists($partial, self::PARTIAL . ' (the VM path figure) does not exist yet.');
        require_once $partial;
        Lang::load($locale);

        return help_vm_path_figure();
    }
}
