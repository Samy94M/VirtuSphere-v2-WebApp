<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase_cells.php';

/**
 * SP4 R3 (F7): the tables show a finding in its short form, the tooltip and the
 * VM editor keep the full sentence. The finding kinds are derived from
 * VIRTUSPHERE_VM_FINDINGS, so a new kind without a short form fails here.
 */
final class VmFindingShortTextTest extends TestCase
{
    /** The job_failed variants the long sentence distinguishes, and their short keys. */
    private const VARIANT_KEYS = ['finding_short_create_failed', 'finding_short_job_outcome_unknown'];

    protected function tearDown(): void
    {
        Lang::load('de');
    }

    public function testEveryFindingKindHasAShortAndALongFormInBothLocales(): void
    {
        foreach (['de', 'en'] as $locale) {
            $catalog = $this->catalog($locale);
            foreach (array_keys(VIRTUSPHERE_VM_FINDINGS) as $kind) {
                $short = 'finding_short_' . $kind;
                self::assertArrayHasKey($short, $catalog, "{$locale}/status.php misses {$short}");
                self::assertNotSame('', trim($catalog[$short]), "{$locale}: {$short} is empty");
                self::assertStringNotContainsString(':', $catalog[$short], "{$locale}: {$short} carries a placeholder; the short form names no time or number");
                self::assertDoesNotMatchRegularExpression('/\d/', $catalog[$short], "{$locale}: {$short} writes a number; numbers stay in the full sentence");
            }
            foreach (self::VARIANT_KEYS as $key) {
                self::assertArrayHasKey($key, $catalog, "{$locale}/status.php misses {$key}");
            }

            Lang::load($locale);
            foreach (array_keys(VIRTUSPHERE_VM_FINDINGS) as $kind) {
                $finding = ['kind' => $kind, 'severity' => VIRTUSPHERE_VM_FINDINGS[$kind], 'params' => [], 'link' => []];
                self::assertNotSame(__t('status.finding_unknown'), portal_vm_finding_text($finding), "{$locale}: {$kind} has no long form");
                self::assertNotSame(__t('status.finding_unknown'), vm_finding_short_text($finding), "{$locale}: {$kind} has no short form");
            }
        }
    }

    public function testTheShortFormIsShorterThanTheFullSentenceAndTheUnknownKindIsNeutral(): void
    {
        Lang::load('de');
        $overdue = ['kind' => VIRTUSPHERE_VM_FINDING_MECM_PENDING_OVERDUE, 'params' => ['since' => '2026-10-31 08:00:00', 'hours' => 2], 'link' => []];
        self::assertSame(__t('status.finding_short_mecm_pending_overdue'), vm_finding_short_text($overdue));
        self::assertLessThan(mb_strlen(portal_vm_finding_text($overdue)), mb_strlen(vm_finding_short_text($overdue)));

        self::assertSame(__t('status.finding_unknown'), vm_finding_short_text(['kind' => 'no_such_kind']), 'an unknown kind never shows its token');
    }

    public function testAnUnknownJobOutcomeIsNotCalledFailedInTheShortFormEither(): void
    {
        Lang::load('de');
        $base = ['kind' => VIRTUSPHERE_VM_FINDING_JOB_FAILED, 'link' => []];
        $unknown = vm_finding_short_text($base + ['params' => ['job_id' => 7, 'mode' => 'full', 'time' => '2026-10-31 08:00:00', 'outcome_unknown' => true]]);
        $created = vm_finding_short_text($base + ['params' => ['job_id' => 7, 'mode' => 'create', 'time' => '2026-10-31 08:00:00', 'create_failed' => true]]);
        $failed = vm_finding_short_text($base + ['params' => ['job_id' => 7, 'mode' => 'full', 'time' => '2026-10-31 08:00:00']]);

        self::assertSame(__t('status.finding_short_job_outcome_unknown'), $unknown);
        self::assertSame(__t('status.finding_short_create_failed'), $created);
        self::assertSame(__t('status.finding_short_job_failed'), $failed);
        self::assertStringNotContainsString('fehlgeschlagen', $unknown);
        self::assertCount(3, array_unique([$unknown, $created, $failed]));
    }

    public function testTheTableCellShowsTheShortFormAndKeepsTheFullSentenceAsTooltip(): void
    {
        Lang::load('en');
        $finding = [
            'kind' => VIRTUSPHERE_VM_FINDING_MECM_PENDING_OVERDUE,
            'severity' => 'warning',
            'params' => ['since' => '2026-10-31 08:00:00', 'hours' => 2],
            'link' => ['target' => VIRTUSPHERE_VM_FINDING_LINK_SYSTEM_STATUS_MECM],
        ];
        $cell = vm_findings_cell([$finding], 3, 9, []);

        self::assertStringContainsString('title="' . h(portal_vm_finding_text($finding)) . '"', $cell, 'the full sentence is the tooltip');
        self::assertStringContainsString(portal_badge('warning', vm_finding_short_text($finding)), $cell, 'the badge shows the short form in the severity colour');
        self::assertStringNotContainsString('>' . h(portal_vm_finding_text($finding)) . '<', $cell, 'the full sentence is not the badge text');
    }

    public function testTheEditorKeepsTheFullSentence(): void
    {
        $editor = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/vm_edit_status.php');
        self::assertStringContainsString('vm_finding_badge($finding)', $editor, 'the editor lists every finding with its full sentence');
        self::assertStringNotContainsString('vm_finding_short_text', $editor);
    }

    /** @return array<string,string> */
    private function catalog(string $locale): array
    {
        $catalog = require dirname(__DIR__, 2) . '/lang/' . $locale . '/status.php';
        self::assertIsArray($catalog);

        return $catalog;
    }
}
