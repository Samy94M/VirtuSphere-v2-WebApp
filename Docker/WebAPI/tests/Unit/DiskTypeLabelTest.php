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
        disk_type_label('thickprovisioned');
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
