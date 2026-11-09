<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/vm_edit_form.php';

/**
 * NZ1: the disk name field shows the Windows label where it differs from the
 * name, linked by aria-describedby exactly while visible, and hands the mirror
 * its limit and sentences in a JSON island instead of letting the script carry
 * copies.
 */
final class VmDiskLabelHintRenderTest extends TestCase
{
    private function row(string $name, int|string $index, bool $template = false): string
    {
        ob_start();
        try {
            render_disk_row(['disk_name' => $name, 'disk_size' => 40, 'disk_type' => 'thin'], $index, true, $template);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public function testLongNameShowsTheCutLabelAndDescribesTheField(): void
    {
        $name = str_repeat('Datenablage', 4);
        $label = (string) vm_disk_volume_label($name);
        $html = $this->row($name, 1);
        $hintId = form_hint_id('vm_edit', 'disk_label', 1);

        self::assertStringContainsString('id="' . $hintId . '"', $html);
        self::assertStringContainsString('aria-describedby="' . $hintId . '"', $html);
        self::assertStringContainsString(htmlspecialchars(__t('vm_edit.disk_label_windows', ['label' => $label]), ENT_QUOTES, 'UTF-8'), $html);
        self::assertSame(VIRTUSPHERE_DISK_VOLUME_LABEL_MAX, mb_strlen($label));
    }

    public function testFittingNameAndEmptySystemDiskStayQuiet(): void
    {
        foreach ([['Daten', 1], ['', 0], ['System', 0], ['Disk 2', 0], ['Systemplatte', 0]] as [$name, $index]) {
            $html = $this->row($name, $index);
            self::assertStringNotContainsString('aria-describedby', $html, $name);
            self::assertMatchesRegularExpression('/<small class="hint" id="[^"]+" data-disk-label-hint[^>]* hidden><\/small>/', $html, $name);
        }
    }

    public function testSystemDiskShowsThePlaceholderAndNeverSavesIt(): void
    {
        // DN2: a stored legacy default renders as the empty field it is saved
        // as; the task sequence label is only the placeholder.
        foreach (['', 'System', 'Disk 2'] as $name) {
            $html = $this->row($name, 0);
            self::assertStringContainsString('value="" placeholder="' . VIRTUSPHERE_SYSTEM_DISK_LABEL_PLACEHOLDER . '"', $html, $name);
        }
        self::assertStringContainsString('value="Systemplatte" placeholder=', $this->row('Systemplatte', 0));
        self::assertStringContainsString('data-autoname-legacy="' . VIRTUSPHERE_VM_DEFAULTS['disk_name_legacy_default'] . '"', $this->row('', 0));
        // Data disks and the template have no placeholder; the mirror moves it.
        self::assertStringNotContainsString('placeholder=', $this->row('', 1));
        self::assertStringContainsString('value="System"', $this->row('System', 1));
        self::assertStringNotContainsString('placeholder=', $this->row('', '__INDEX__', true));
    }

    public function testSystemDiskNoticeOnlyWhenCutReplacedOrNothingUsable(): void
    {
        // F7: the system disk speaks only for cut, replaced or nothing usable.
        $long = str_repeat('x', 40);
        self::assertStringContainsString(
            htmlspecialchars(__t('vm_edit.disk_label_windows', ['label' => (string) vm_disk_volume_label($long)]), ENT_QUOTES, 'UTF-8'),
            $this->row($long, 0)
        );
        $html = $this->row('???', 0);
        self::assertStringContainsString(htmlspecialchars(__t('vm_edit.disk_label_system_fallback'), ENT_QUOTES, 'UTF-8'), $html);
        self::assertStringNotContainsString(htmlspecialchars(__t('vm_edit.disk_label_fallback'), ENT_QUOTES, 'UTF-8'), $html);
        self::assertStringContainsString('aria-describedby="' . form_hint_id('vm_edit', 'disk_label', 0) . '"', $html);
    }

    public function testTemplateRowLeavesThePositionToTheMirror(): void
    {
        $html = $this->row('', '__INDEX__', true);
        self::assertStringContainsString('id="' . form_hint_id('vm_edit', 'disk_label', '__INDEX__') . '"', $html);
        self::assertStringNotContainsString('aria-describedby', $html);
        self::assertMatchesRegularExpression('/<small class="hint" id="[^"]+" data-disk-label-hint hidden><\/small>/', $html);
    }

    public function testIslandCarriesTheLimitAndTheThreeSentences(): void
    {
        ob_start();
        try {
            render_disk_type_hint();
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame(1, preg_match('/<script type="application\/json" data-disk-label-texts nonce="[^"]+">(.*?)<\/script>/s', $html, $match));
        $island = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(VIRTUSPHERE_DISK_VOLUME_LABEL_MAX, $island['max']);
        // The keys are the notice kinds the mirror looks up.
        foreach (['label', 'default'] as $kind) {
            self::assertStringContainsString(':label', (string) $island[$kind], $kind);
        }
        self::assertSame(__t('vm_edit.disk_label_fallback'), $island['fallback']);
        self::assertSame(__t('vm_edit.disk_label_system_fallback'), $island['system_fallback']);
        self::assertSame(VIRTUSPHERE_SYSTEM_DISK_LABEL_PLACEHOLDER, $island['placeholder']);
    }

    public function testEveryNoticeKindHasASentenceInBothLocales(): void
    {
        foreach (['de', 'en'] as $locale) {
            $catalog = require dirname(__DIR__, 2) . '/lang/' . $locale . '/vm_edit.php';
            foreach (['disk_label_windows', 'disk_label_default'] as $key) {
                self::assertStringContainsString(':label', (string) ($catalog[$key] ?? ''), $locale . ' ' . $key);
            }
            self::assertNotSame('', trim((string) ($catalog['disk_label_fallback'] ?? '')), $locale);
            self::assertNotSame('', trim((string) ($catalog['disk_label_system_fallback'] ?? '')), $locale);
        }
    }
}
