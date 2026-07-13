<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The modal axis (ADR-0013) lives in CSS, which no linter in this repo reads.
 * php -l, node --check and the whole unit suite stay green while a dialog drifts
 * off its axis or clips the name it is asking about.
 *
 * Two failures this pins down:
 *  - A per-dialog alignment override. Before this test the rule sat on
 *    `.modal-confirm .modal-msg`, so the session modal never inherited it and a
 *    third dialog would have had to restate it. Only the shared `.modal-msg` may
 *    align a message; a `.modal-x .modal-msg { text-align: center }` added later
 *    would look right on the one message it was written for and wrong on the next.
 *  - A message that clips instead of wrapping. A confirm question names its
 *    target (`:name`, ADR-0013) and a target name is user input: an unbroken
 *    90-character service account ran past the box and the name was cut off,
 *    which is exactly the misread the naming rule exists to prevent. `.modal-msg`
 *    keeps `overflow-wrap` for that, and it has to be `anywhere` rather than
 *    `break-word`: `anywhere` also lowers the element's min-content width, and
 *    min-content is what `width: fit-content` resolves against.
 *
 * Alignment is not restated per dialog; it is derived from the text length.
 * `width: fit-content` plus auto inline margins let a one-line message shrink to
 * its own width and recentre, while a wrapping one hits `max-width` and keeps its
 * left edge. That is why no rule has to guess at the sentence count, and why the
 * same rule holds in German and English although the two wrap differently.
 */
final class ModalAxisContractTest extends TestCase
{
    private const CSS = 'portal/assets/css/components.css';

    /**
     * Properties that decide where modal content sits. A rule may only declare
     * one of these if it owns that decision below.
     */
    private const GUARDED = ['text-align', 'justify-content', 'align-items'];

    /**
     * The only selectors allowed to place modal content, each with the exact
     * decision it owns. Anything else mentioning a modal must inherit.
     *
     * @var array<string, array<string, string>> selector => property => value
     */
    private const AXIS_OWNERS = [
        // The dialog element is the dim layer; this centres the box in the viewport.
        '.modal[open]' => ['align-items' => 'center', 'justify-content' => 'center'],
        // Icon and title sit on the axis.
        '.modal-box' => ['text-align' => 'center'],
        // Wrapped prose reads from its left edge; a one-line message is recentred
        // by fit-content + auto margins, not by text-align.
        '.modal-msg' => ['text-align' => 'left'],
        '.modal-actions' => ['justify-content' => 'center'],
    ];

    private function css(): string
    {
        $path = str_replace('\\', '/', dirname(__DIR__, 2)) . '/' . self::CSS;
        self::assertFileExists($path, self::CSS . ' must exist');

        return (string) file_get_contents($path);
    }

    /**
     * Comments in components.css quote the declarations these tests reason about
     * ("fit-content", "text-align: left"), so scanning them raw would read the
     * prose instead of the rules.
     */
    private function stripComments(string $css): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
    }

    /**
     * Flatten the stylesheet to `selector => declarations`, descending into
     * at-rules. A regex over the whole file cannot do this: `@media (...) {`
     * would match as a selector and swallow the first rule nested inside it.
     *
     * @return array<int, array{selector: string, body: string}>
     */
    private function rules(string $css): array
    {
        $rules = [];
        $length = strlen($css);
        $prelude = '';

        for ($i = 0; $i < $length; $i++) {
            if ($css[$i] !== '{') {
                $prelude .= $css[$i];
                continue;
            }

            $depth = 1;
            $body = '';
            for ($i++; $i < $length && $depth > 0; $i++) {
                if ($css[$i] === '{') {
                    $depth++;
                } elseif ($css[$i] === '}') {
                    if (--$depth === 0) {
                        break;
                    }
                }
                $body .= $css[$i];
            }

            $selector = trim(preg_replace('/\s+/', ' ', $prelude) ?? $prelude);
            $prelude = '';

            if (str_starts_with($selector, '@')) {
                // @media/@supports wrap rules; @keyframes wrap `from`/`to` blocks,
                // which carry no selector we guard. Both are safe to descend into.
                foreach ($this->rules($body) as $nested) {
                    $rules[] = $nested;
                }
                continue;
            }

            $rules[] = ['selector' => $selector, 'body' => $body];
        }

        return $rules;
    }

    /** @return array<string, string> property => value */
    private function declarations(string $body): array
    {
        preg_match_all('/(-?[a-z][-a-z]*)\s*:\s*([^;]+)/i', $body, $matches, PREG_SET_ORDER);

        $declarations = [];
        foreach ($matches as $match) {
            $declarations[strtolower($match[1])] = trim($match[2]);
        }

        return $declarations;
    }

    /** @return array<int, array{selector: string, body: string}> */
    private function modalRules(): array
    {
        $rules = array_values(array_filter(
            $this->rules($this->stripComments($this->css())),
            static fn (array $rule): bool => str_contains($rule['selector'], 'modal'),
        ));

        self::assertNotSame([], $rules, 'the modal component disappeared from ' . self::CSS);

        return $rules;
    }

    /**
     * Declarations per selector, later blocks winning as the cascade would.
     * A selector may appear more than once: `.modal[open]` centres the box at
     * the top of the file and picks up its open animation again inside the
     * `prefers-reduced-motion` block.
     *
     * @return array<string, array<string, string>> selector => property => value
     */
    private function cascaded(): array
    {
        $cascaded = [];
        foreach ($this->modalRules() as $rule) {
            $cascaded[$rule['selector']] = array_merge(
                $cascaded[$rule['selector']] ?? [],
                $this->declarations($rule['body']),
            );
        }

        return $cascaded;
    }

    public function testOnlyTheSharedRulesPlaceModalContent(): void
    {
        $offenders = [];
        foreach ($this->modalRules() as $rule) {
            $guarded = array_intersect_key($this->declarations($rule['body']), array_flip(self::GUARDED));
            if ($guarded === []) {
                continue;
            }

            if (!isset(self::AXIS_OWNERS[$rule['selector']])) {
                $offenders[$rule['selector']] = implode(', ', array_keys($guarded));
            }
        }

        self::assertSame(
            [],
            $offenders,
            'a modal rule places its own content; alignment belongs to the shared .modal-box/.modal-msg/.modal-actions rules (ADR-0013)',
        );
    }

    public function testEachAxisOwnerStillMakesItsDecision(): void
    {
        $cascaded = $this->cascaded();

        foreach (self::AXIS_OWNERS as $selector => $expected) {
            self::assertArrayHasKey($selector, $cascaded, $selector . ' is an axis owner and must exist');

            foreach ($expected as $property => $value) {
                self::assertArrayHasKey($property, $cascaded[$selector], $selector . ' must declare ' . $property);
                self::assertSame($value, $cascaded[$selector][$property], $selector . ' { ' . $property . ' } decides where modal content sits');
            }
        }
    }

    public function testTheMessageDerivesItsAlignmentFromItsLength(): void
    {
        $cascaded = $this->cascaded();
        self::assertArrayHasKey('.modal-msg', $cascaded, '.modal-msg is the shared message rule');

        $declarations = $cascaded['.modal-msg'];

        // fit-content + max-width: a one-line message takes its own width, a
        // longer one fills the box. Without both, text-align: left applies to
        // every message and a short question hangs off the dialog axis.
        self::assertSame('fit-content', $declarations['width'] ?? null, '.modal-msg sizes to its text so a one-line message can recentre');
        self::assertSame('100%', $declarations['max-width'] ?? null, '.modal-msg must not outgrow the box');

        // The auto inline margins are what put the shrunk block back on the axis.
        $margin = $declarations['margin'] ?? $declarations['margin-inline'] ?? '';
        self::assertMatchesRegularExpression('/(^|\s)auto(\s|$)/', $margin, '.modal-msg needs auto inline margins to sit on the dialog axis');
    }

    public function testTheMessageWrapsALongTargetNameInsteadOfClippingIt(): void
    {
        $declarations = $this->cascaded()['.modal-msg'] ?? [];

        // `anywhere`, not `break-word`: it also lowers min-content, which is what
        // width: fit-content resolves against. With `break-word` the longest word
        // stays the min-content width, the block outgrows the box and only
        // max-width catches it, clipping the name mid-token.
        self::assertSame(
            'anywhere',
            $declarations['overflow-wrap'] ?? null,
            'a confirm question names its target and the name is user input; it must wrap, not clip (ADR-0013)',
        );
    }
}
