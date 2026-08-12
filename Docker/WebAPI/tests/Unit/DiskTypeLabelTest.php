<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/defaults.php';

/**
 * The disk provisioning types are stored as the tokens vmware_guest expects, and
 * the VM editor used to render exactly those tokens in its select. `thick` and
 * `eagerzeroedthick` are the same word to a reader who does not already know
 * VMware's provisioning model, so the field asked for a decision it never
 * explained. disk_type_label() is the translation from token to sentence.
 *
 * This walks VIRTUSPHERE_DISK_TYPES rather than listing the three names: a type
 * added to the constant without a label would otherwise reach the form as a bare
 * token again, which is the defect the labels removed.
 */
final class DiskTypeLabelTest extends TestCase
{
    public function testEveryKnownTypeHasALabelInEveryLocale(): void
    {
        foreach (Lang::LOCALES as $locale) {
            Lang::load($locale);
            foreach (VIRTUSPHERE_DISK_TYPES as $type) {
                $label = disk_type_label($type);
                self::assertNotSame(
                    $type,
                    $label,
                    sprintf('%s: %s falls back to its own token, so lang/%s/vm_edit.php has no disk_type_%s key.', $locale, $type, $locale, $type)
                );
                self::assertStringNotContainsString(
                    'vm_edit.',
                    $label,
                    sprintf('%s: %s renders a catalog key instead of a label.', $locale, $type)
                );
            }
        }
    }

    /** Two types reading the same makes the select a guess again. */
    public function testLabelsAreDistinctPerLocale(): void
    {
        foreach (Lang::LOCALES as $locale) {
            Lang::load($locale);
            $labels = array_map('disk_type_label', VIRTUSPHERE_DISK_TYPES);
            self::assertSame(
                count($labels),
                count(array_unique($labels)),
                $locale . ': two provisioning types share one label: ' . implode(' | ', $labels)
            );
        }
    }

    /**
     * The missing arm is the point of the exhaustive match: a new type fails
     * here instead of silently rendering as a token nobody can read.
     */
    public function testAnUnknownTypeIsNotSilentlyLabelled(): void
    {
        Lang::load('de');
        $this->expectException(\UnhandledMatchError::class);
        // The narrowed @param makes this a static error on purpose: the call is
        // the deliberate violation that proves the runtime arm still exists.
        // @phpstan-ignore argument.type
        disk_type_label('thickprovisioned');
    }

    /**
     * The narrowed @param on disk_type_label() is what lets the match stay
     * exhaustive without a default, and it is a hand-written copy of
     * VIRTUSPHERE_DISK_TYPES. A copy nothing compares is a copy that drifts: a
     * fourth token added to the constant plus the docblock would keep PHPStan
     * quiet while the match arm is missing, which is the failure the exhaustive
     * match was built to catch.
     */
    public function testTheNarrowedParameterUnionMatchesTheConstant(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/defaults.php');
        $found = preg_match('/@param\s+([^\s]+)\s+\$type\s/', $source, $matches);
        self::assertSame(1, $found, 'disk_type_label() lost its narrowed @param; the match can no longer be proven exhaustive.');

        $union = array_map(
            static fn (string $literal): string => trim($literal, "'"),
            explode('|', $matches[1])
        );
        self::assertNotSame([], $union, 'the parsed @param union came back empty; this test would pass on anything.');
        self::assertSame(
            VIRTUSPHERE_DISK_TYPES,
            $union,
            'The @param union in lib/defaults.php and VIRTUSPHERE_DISK_TYPES disagree, so a type can exist without a label arm.'
        );
    }

    /**
     * A label helper only helps where it is called. The storage-demand
     * paragraph of the deploy help interpolated VIRTUSPHERE_VM_DEFAULTS
     * ['disk_type'] directly, so one sentence still said "eagerzeroedthick"
     * while every other visible place said "Eager Zeroed Thick". Renderers are
     * derived from their directories rather than listed, so a new help page or
     * form module has to make the same decision.
     */
    public function testNoRendererInterpolatesTheRawDefaultToken(): void
    {
        $renderers = array_merge(
            glob(dirname(__DIR__, 2) . '/lib/help/*.php') ?: [],
            glob(dirname(__DIR__, 2) . '/lib/*_form.php') ?: [],
            glob(dirname(__DIR__, 2) . '/portal/*.php') ?: []
        );
        self::assertNotSame([], $renderers, 'no renderer sources found, so this contract proved nothing');

        $seen = 0;
        foreach ($renderers as $path) {
            foreach ($this->translationCalls((string) file_get_contents($path)) as $call) {
                if (!str_contains($call, "VIRTUSPHERE_VM_DEFAULTS['disk_type']")) {
                    continue;
                }
                $seen++;
                self::assertStringContainsString(
                    'disk_type_label(',
                    $call,
                    basename($path) . ' interpolates the stored provisioning token into visible text instead of its label'
                );
            }
        }

        self::assertGreaterThan(0, $seen, 'no visible text names the default provisioning type at all; the scan matched nothing');
    }

    /**
     * The text of every __t() call in a source, so the check can ask what
     * reaches a sentence rather than what the file merely mentions: the same
     * constant is a legitimate *value* in the form state a few lines above.
     *
     * @return list<string>
     */
    private function translationCalls(string $source): array
    {
        $tokens = token_get_all($source);
        $calls = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== '__t') {
                continue;
            }
            $depth = 0;
            $text = '';
            for ($cursor = $index + 1, $total = count($tokens); $cursor < $total; $cursor++) {
                $piece = is_array($tokens[$cursor]) ? $tokens[$cursor][1] : $tokens[$cursor];
                if ($piece === '(') {
                    $depth++;
                } elseif ($piece === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                $text .= $piece;
            }
            $calls[] = $text;
        }

        return $calls;
    }

    /** The preselected type is one of the labelled ones, in both directions. */
    public function testTheDefaultTypeIsALabelledKnownType(): void
    {
        Lang::load('de');
        self::assertContains(
            VIRTUSPHERE_VM_DEFAULTS['disk_type'],
            VIRTUSPHERE_DISK_TYPES,
            'The VM default names a provisioning type the validators reject.'
        );
        self::assertNotSame(VIRTUSPHERE_VM_DEFAULTS['disk_type'], disk_type_label(VIRTUSPHERE_VM_DEFAULTS['disk_type']));
    }
}
