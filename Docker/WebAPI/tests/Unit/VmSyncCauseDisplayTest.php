<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/portal_status_display.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase_cells.php';
require_once dirname(__DIR__, 2) . '/lib/vm_edit_status.php';

/**
 * MX1 (FM-05) display of the device sync's cause, user answers Q1 and Q2:
 * the full sentence of the MECM wait (VM editor, table cell title) names the
 * cause in words, the short form of the cell stays as it is (R56, F7); an old
 * report names its observation time; the editor's technical block shows code,
 * revision and observation time. Words come from the catalog, never the token.
 */
final class VmSyncCauseDisplayTest extends TestCase
{
    private const NOW = 1_800_000_000;

    protected function tearDown(): void
    {
        Lang::load('de');
    }

    public function testTheFullSentenceNamesTheCauseInWordsAndTheShortFormDoesNot(): void
    {
        foreach (['de', 'en'] as $locale) {
            Lang::load($locale);
            foreach ([-600, -VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS - 1] as $sinceOffset) {
                $finding = $this->waitFinding(['code' => 'resource_mac_conflict'], $sinceOffset);
                $full = portal_vm_finding_text($finding);
                $label = __t('status.sync_cause_resource_mac_conflict');

                self::assertStringContainsString($label, $full, "{$locale}: {$finding['kind']}");
                self::assertStringStartsWith(portal_vm_finding_text(['kind' => $finding['kind'], 'params' => $this->withoutCause($finding['params'])]), $full, 'the wait sentence stays in front');
                self::assertStringNotContainsString('resource_mac_conflict', $full, 'no raw token');
                self::assertSame(__t('status.finding_short_' . $finding['kind']), vm_finding_short_text($finding), 'the short form stays (F7)');

                $cell = vm_findings_cell([$finding], 1, 2, []);
                self::assertStringContainsString('title="' . h($full) . '"', $cell, 'the cell title is the full sentence');
                self::assertStringNotContainsString('>' . h($label), $cell, 'the badge does not name the cause');
            }
        }
    }

    public function testAnOldReportNamesItsObservationTimeAndAYoungOneDoesNot(): void
    {
        foreach (['de', 'en'] as $locale) {
            Lang::load($locale);
            $observed = gmdate('Y-m-d H:i:s', self::NOW - VIRTUSPHERE_VM_SYNC_CAUSE_AS_OF_AFTER_SECONDS);
            $old = portal_vm_finding_text($this->waitFinding(['observed_at' => $observed]));
            $young = portal_vm_finding_text($this->waitFinding(['observed_at' => gmdate('Y-m-d H:i:s', self::NOW - 60)]));

            self::assertStringContainsString(portal_format_datetime($observed), $old, $locale);
            self::assertSame(__t('status.finding_mecm_cause_as_of', [
                'finding' => portal_vm_finding_text(['kind' => VIRTUSPHERE_VM_FINDING_MECM_PENDING, 'params' => ['since' => gmdate('Y-m-d H:i:s', self::NOW - 600)]]),
                'cause' => __t('status.sync_cause_mac_missing'),
                'time' => portal_format_datetime($observed),
            ]), $old);
            self::assertStringNotContainsString(portal_format_datetime(gmdate('Y-m-d H:i:s', self::NOW - 60)), $young, $locale);
        }
    }

    public function testWithoutACauseTheWaitSentenceIsUnchanged(): void
    {
        $finding = ['kind' => VIRTUSPHERE_VM_FINDING_MECM_PENDING, 'params' => ['since' => gmdate('Y-m-d H:i:s', self::NOW - 600)]];

        self::assertSame(__t('status.finding_mecm_pending_since', ['since' => portal_format_datetime($finding['params']['since'])]), portal_vm_finding_text($finding));
        self::assertSame(__t('status.finding_mecm_pending'), portal_vm_finding_text(['kind' => VIRTUSPHERE_VM_FINDING_MECM_PENDING, 'params' => ['since' => '', 'cause' => 'no_such_code']]));
    }

    public function testTheTechnicalLineNamesCodeRevisionAndObservationTime(): void
    {
        Lang::load('de');
        $observed = gmdate('Y-m-d H:i:s', self::NOW - 120);
        $html = (string) vm_edit_sync_cause_html([$this->waitFinding(['observed_at' => $observed])]);

        self::assertStringContainsString('<code>mac_missing</code>', $html);
        self::assertStringContainsString(h(__t('vm_edit.technical_sync_cause_detail', ['revision' => 3, 'time' => portal_format_timestamp($observed)])), $html);
        self::assertStringContainsString(
            h(__t('vm_edit.technical_sync_cause_detail_no_time', ['revision' => 3])),
            (string) vm_edit_sync_cause_html([$this->waitFinding(['observed_at' => 'not-a-timestamp'])])
        );
    }

    /** Q7: no wait finding with a cause, no technical cause (the block then says "not set"). */
    public function testTheTechnicalLineIsEmptyWithoutACauseAtTheWait(): void
    {
        self::assertNull(vm_edit_sync_cause_html([]));
        self::assertNull(vm_edit_sync_cause_html([['kind' => VIRTUSPHERE_VM_FINDING_MECM_PENDING, 'params' => ['since' => '']]]));
        self::assertNull(vm_edit_sync_cause_html([['kind' => VIRTUSPHERE_VM_FINDING_MECM_SYNC_DEPLOY_FAILED, 'params' => ['cause' => 'mac_missing']]]));
    }

    /** @return array<string,mixed> the MECM-wait finding of a pending VM with a reported cause */
    private function waitFinding(array $cause = [], int $sinceOffset = -600): array
    {
        $vm = [
            'id' => 2,
            'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_DEPLOYED,
            'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_PENDING,
            'mecm_pending_since' => gmdate('Y-m-d H:i:s', self::NOW + $sinceOffset),
        ];
        $findings = virtusphere_vm_findings($vm, ['sync_cause' => $cause + [
            'code' => 'mac_missing',
            'rollout_revision' => 3,
            'run_id' => str_repeat('b', 32),
            'observed_at' => gmdate('Y-m-d H:i:s', self::NOW - 60),
        ]], self::NOW);
        self::assertCount(1, $findings);

        return $findings[0];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function withoutCause(array $params): array
    {
        return array_diff_key($params, array_flip(['cause', 'cause_revision', 'cause_observed_at', 'cause_as_of']));
    }
}
