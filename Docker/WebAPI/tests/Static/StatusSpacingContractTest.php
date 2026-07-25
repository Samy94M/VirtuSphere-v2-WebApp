<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/CssRules.php';

/**
 * The spacing scale of the System status block (ADR-0013).
 *
 * No hook in this repo reads CSS: php -l, node --check, PHPStan and the whole
 * unit suite stay green while a stylesheet drifts back to per-rule px literals.
 * That is how the page got a `gap` in five different sizes, two `margin-top`
 * declarations the cascade had killed, and a card radius that disagreed with the
 * row inside it. So the guard is the file boundary: everything in status.css
 * takes its distances from the scale in base.css or it does not ship.
 *
 * Three things are pinned here, and each has been seen to fail on purpose:
 *  - A raw length in a guarded property inside status.css.
 *  - A token used here that base.css does not define, AND a token base.css
 *    defines that nothing uses. The second direction matters as much: a scale
 *    that grows past its callers is the same drift as a rule without markup,
 *    only in the direction no other guard looks.
 *  - The <link> order in lib/layout.php. Several status rules are
 *    specificity-equal with their counterparts in components.css (a panel's
 *    heading margin, a paragraph's, an alert's) and win only on position.
 *    Swapping the two <link> elements would put every one of those margins back
 *    without changing a single declaration.
 */
final class StatusSpacingContractTest extends TestCase
{
    private const SHEET = 'status.css';

    /**
     * The properties that decide distance. Deliberately spelled out rather than
     * prefix-matched: `scroll-margin-top` is an anchor offset against the sticky
     * topbar, not spacing, and has no business on a four-step scale.
     */
    private const GUARDED = '/^(?:(?:row|column)-gap|gap|(?:margin|padding)(?:-(?:top|right|bottom|left|block|inline)(?:-(?:start|end))?)?|border-radius|border-(?:start|end)-(?:start|end)-radius|border-(?:top|bottom)-(?:left|right)-radius)$/';

    private function webApiRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    private function sheet(): string
    {
        $path = $this->webApiRoot() . '/portal/assets/css/' . self::SHEET;
        self::assertFileExists($path, self::SHEET . ' is the System status stylesheet');

        return (string) file_get_contents($path);
    }

    /**
     * The custom properties base.css declares in :root, name => value. Only the
     * light block: spacing is not themed, and a step that appeared in the dark
     * block alone would move the layout with the theme toggle.
     *
     * @return array<string, string>
     */
    private function declaredTokens(): array
    {
        $base = CssRules::stripComments(
            (string) file_get_contents($this->webApiRoot() . '/portal/assets/css/base.css')
        );

        foreach (CssRules::rules($base) as $rule) {
            if ($rule['selector'] !== ':root') {
                continue;
            }

            $tokens = [];
            foreach (CssRules::declarations($rule['body']) as $property => $value) {
                if (str_starts_with($property, '--space-') || str_starts_with($property, '--radius-')) {
                    $tokens[$property] = $value;
                }
            }

            return $tokens;
        }

        self::fail('base.css has no :root block; the token scale moved');
    }

    public function testEveryDistanceInTheStatusSheetComesFromTheScale(): void
    {
        $offenders = [];
        foreach (CssRules::rules(CssRules::stripComments($this->sheet())) as $rule) {
            foreach (CssRules::declarations($rule['body']) as $property => $value) {
                if (preg_match(self::GUARDED, $property) !== 1) {
                    continue;
                }

                foreach (preg_split('#[\s/]+#', trim($value)) ?: [] as $part) {
                    if ($part === '' || $part === '0' || $part === 'auto') {
                        continue;
                    }
                    if (preg_match('/^var\(--(?:space|radius)-[a-z0-9]+\)$/', $part) === 1) {
                        continue;
                    }
                    $offenders[] = $rule['selector'] . ' { ' . $property . ': ' . $value . ' }';
                }
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($offenders)),
            self::SHEET . ' may only space with var(--space-*) / var(--radius-*), 0 or auto.'
            . " Add the step to the :root scale in base.css and use it, or round onto an existing one.\n"
            . 'A single px literal here is how the scale went away last time.'
        );
    }

    public function testTheSheetOnlyUsesTokensBaseCssDefines(): void
    {
        $declared = $this->declaredTokens();
        preg_match_all('/var\((--(?:space|radius)-[a-z0-9]+)\)/', $this->sheet(), $used);

        $missing = array_values(array_unique(array_diff($used[1], array_keys($declared))));
        sort($missing);

        self::assertSame([], $missing, self::SHEET . ' uses a token base.css :root does not define');
    }

    public function testEveryDefinedStepHasACaller(): void
    {
        $declared = $this->declaredTokens();
        self::assertNotSame([], $declared, 'base.css :root must carry the spacing scale');

        $allCss = implode("\n", CssRules::stylesheets($this->webApiRoot()));
        $unused = [];
        foreach (array_keys($declared) as $token) {
            if (substr_count($allCss, 'var(' . $token . ')') === 0) {
                $unused[] = $token;
            }
        }
        sort($unused);

        self::assertSame(
            [],
            $unused,
            'base.css defines a spacing step nothing uses. The scale is derived from the callers, '
            . 'not set in advance: drop it, or the next value that fits nothing will invent a fifth step.'
        );
    }

    public function testTheStatusSheetLoadsAfterComponents(): void
    {
        $order = CssRules::linkOrder($this->webApiRoot());

        $components = array_search('components.css', $order, true);
        $status = array_search(self::SHEET, $order, true);

        self::assertIsInt($components, 'lib/layout.php must link components.css');
        self::assertIsInt($status, 'lib/layout.php must link ' . self::SHEET);
        self::assertGreaterThan(
            $components,
            $status,
            self::SHEET . ' must be linked after components.css: its heading, paragraph and alert '
            . 'margin resets are specificity-equal with the panel defaults there and win only on position'
        );
    }
}
