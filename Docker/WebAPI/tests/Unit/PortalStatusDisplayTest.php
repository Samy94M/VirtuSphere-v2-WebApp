<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout_response.php';
require_once dirname(__DIR__, 2) . '/lib/status.php';
require_once dirname(__DIR__, 2) . '/lib/layout_presenters.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_display.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase.php';

/**
 * The portal-only status vocabulary.
 *
 * These are constant walks, not example tests: every value the SSoT declares
 * must be named in BOTH locales, or a state added to lib/constants.php ships
 * with its raw token as its label and nothing notices. That is exactly how
 * `os_installing` reached an operator.
 */
final class PortalStatusDisplayTest extends TestCase
{
    /**
     * Every deploy-job status the portal can be asked to render, derived from
     * the active and terminal sets: a hand list of seven values silently missed
     * outcome_unknown until it was rewritten (WK-04/WK-07 A).
     *
     * @return list<string>
     */
    private static function jobStatuses(): array
    {
        return array_values(array_unique(array_merge(VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES, VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES)));
    }

    /** The derived set is exactly what the constants file declares, so no status hides outside both sets. */
    public function testTheJobStatusWalkCoversEveryDeclaredStatus(): void
    {
        $declared = array_values(array_filter(
            get_defined_constants(),
            static fn (mixed $value, string $name): bool => str_starts_with($name, 'VIRTUSPHERE_DEPLOY_STATUS_'),
            ARRAY_FILTER_USE_BOTH
        ));
        self::assertNotSame([], $declared);
        $walked = self::jobStatuses();
        sort($declared);
        sort($walked);
        self::assertSame($declared, $walked);
    }

    public function testAnUnknownOutcomeIsAWarningAndNamedInBothLocales(): void
    {
        self::assertSame('warning', deploy_job_status_badge_class(VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN));
        Lang::load('de');
        self::assertSame('Ausgang unbekannt', deploy_job_status_label(VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN));
        Lang::load('en');
        self::assertSame('Outcome unknown', deploy_job_status_label(VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN));
    }

    protected function tearDown(): void
    {
        Lang::load(Lang::DEFAULT_LOCALE);
    }

    /**
     * @return array<string, array{0: list<string>, 1: callable(string): string, 2: string}>
     */
    public static function vocabularies(): array
    {
        return [
            'lifecycle' => [VIRTUSPHERE_LIFECYCLE_STATES, portal_lifecycle_label(...), 'status.lifecycle_unknown'],
            'mecm sync' => [VIRTUSPHERE_MECM_SYNC_STATES, portal_mecm_sync_label(...), 'status.mecm_unknown'],
            'job status' => [self::jobStatuses(), deploy_job_status_label(...), 'status.job_unknown'],
            // The six postable modes come from the technical SSoT itself, so a
            // mode added there without a label fails here instead of shipping
            // its payload token as a name. `inventory` is appended because the
            // portal shows it although nobody can post it.
            'deploy mode' => [
                array_merge(array_keys(virtusphere_deploy_mode_labels()), [VIRTUSPHERE_DEPLOY_MODE_INVENTORY]),
                deploy_mode_label(...),
                'status.mode_unknown',
            ],
            // `template` is no stored state, but the list shows it in the same column.
            'vm phase' => [
                array_merge(VIRTUSPHERE_VM_PHASES, [VIRTUSPHERE_VM_PHASE_TEMPLATE]),
                portal_vm_phase_label(...),
                'status.phase_unknown',
            ],
        ];
    }

    /**
     * The visible payload summary and the persisted one are different functions
     * on purpose: the retained job log is evidence and must not move with a
     * display language.
     */
    public function testThePersistedPayloadSummaryStaysTechnical(): void
    {
        Lang::load('de');
        $payload = '{"mode":"start","verbose":true,"vm_ids":[1,2,3]}';

        self::assertSame('start -vvv (3 VMs)', deploy_job_payload_summary($payload));
        $display = deploy_job_payload_display($payload);
        self::assertStringContainsString(__t('status.mode_start'), $display);
        self::assertStringNotContainsString('start -vvv', $display);
        // -vvv survives: it is the Ansible flag itself, not a word this portal
        // invented, and an operator pasting it into a runbook needs it verbatim.
        self::assertStringContainsString('-vvv', $display);
        self::assertStringContainsString(__t('status.mode_scope_many', ['count' => '3']), $display);
    }

    public function testTheVisiblePayloadSummaryCountsAndFailsSafely(): void
    {
        Lang::load('de');
        self::assertSame(__t('status.mode_full'), deploy_job_payload_display(null));
        self::assertSame(__t('status.mode_full'), deploy_job_payload_display('   '));
        self::assertSame(__t('status.mode_invalid_payload'), deploy_job_payload_display('not json'));
        // No "VM(s)": the sentence is chosen by count.
        self::assertStringContainsString(
            __t('status.mode_scope_one', ['count' => '1']),
            deploy_job_payload_display('{"mode":"export","vm_ids":[7]}')
        );
    }

    /**
     * @param list<string> $values
     * @param callable(string): string $label
     */
    #[PHPUnit\Framework\Attributes\DataProvider('vocabularies')]
    public function testEveryDeclaredValueIsNamedInBothLocales(array $values, callable $label, string $unknownKey): void
    {
        self::assertNotSame([], $values, 'the vocabulary is empty, so this walk would assert nothing');

        foreach (Lang::LOCALES as $locale) {
            Lang::load($locale);
            $unknown = __t($unknownKey);
            $seen = [];
            foreach ($values as $value) {
                $text = $label($value);
                self::assertNotSame('', trim($text), $locale . ': ' . $value . ' has no label');
                // A missing key returns the key itself (lib/lang.php), so this
                // catches a value that was added to the constant but not to the
                // catalog.
                self::assertStringNotContainsString('status.', $text, $locale . ': ' . $value . ' renders an untranslated key');
                self::assertNotSame($value, $text, $locale . ': ' . $value . ' still renders its raw token');
                self::assertNotSame($unknown, $text, $locale . ': ' . $value . ' falls through to the unknown label');
                self::assertArrayNotHasKey($text, $seen, $locale . ': ' . $value . ' shares a label with ' . ($seen[$text] ?? ''));
                $seen[$text] = $value;
            }
        }
    }

    /**
     * @param list<string> $values
     * @param callable(string): string $label
     */
    #[PHPUnit\Framework\Attributes\DataProvider('vocabularies')]
    public function testAnUnknownValueIsNeutralAndNeverEchoedBack(array $values, callable $label, string $unknownKey): void
    {
        foreach (Lang::LOCALES as $locale) {
            Lang::load($locale);
            foreach (['', 'os_installing_v2', '<script>alert(1)</script>', 'ABGEBROCHEN'] as $foreign) {
                $text = $label($foreign);
                self::assertSame(__t($unknownKey), $text, $locale . ': "' . $foreign . '" must land on the neutral label');
                if ($foreign !== '') {
                    self::assertStringNotContainsString($foreign, $text, $locale . ': the raw value leaked into the label');
                }
            }
        }
    }

    /**
     * A page renders many rows, so one legacy value must not cost one log line
     * per row. The drift is caught by the walk above at build time, not by a
     * production log nobody reads.
     */
    public function testRenderingAnUnknownValueWritesNoLogLine(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'vsdrift');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);

        try {
            Lang::load('de');
            lifecycle_badge('not_a_state');
            mecm_sync_badge('not_a_state');
            deploy_job_status_badge('not_a_state');
            portal_mission_status_label('not_a_status');
            vm_phase_badge('not_a_phase');

            self::assertSame('', (string) file_get_contents($log), 'rendering an unknown state wrote to the error log');
        } finally {
            if (is_string($previous)) {
                ini_set('error_log', $previous);
            }
            @unlink($log);
        }
    }

    public function testBadgeVariantStillComesFromTheMetaSsoT(): void
    {
        Lang::load('de');
        // Colour and wording have separate owners; the badge must carry the
        // variant of the state machine and the text of the catalog.
        self::assertStringContainsString('badge-danger', lifecycle_badge(VIRTUSPHERE_LIFECYCLE_FAILED));
        self::assertStringContainsString(__t('status.lifecycle_failed'), lifecycle_badge(VIRTUSPHERE_LIFECYCLE_FAILED));
        self::assertStringContainsString('badge-success', mecm_sync_badge(VIRTUSPHERE_MECM_SYNC_REGISTERED));
        self::assertStringContainsString(__t('status.mecm_registered'), mecm_sync_badge(VIRTUSPHERE_MECM_SYNC_REGISTERED));
        self::assertStringContainsString(
            deploy_job_status_badge_class(VIRTUSPHERE_DEPLOY_STATUS_PARTIAL),
            deploy_job_status_badge(VIRTUSPHERE_DEPLOY_STATUS_PARTIAL)
        );
        // An unknown value keeps the neutral variant it already had.
        self::assertStringContainsString('badge-neutral', lifecycle_badge('not_a_state'));
    }

    /**
     * Every finding kind is named in both locales, its text never leaks a
     * catalog key or the raw kind, and its badge variant comes from the meta.
     * `mecm_sync_failed` deliberately reuses `status.mecm_failed`, so it stays
     * out of the uniqueness walk.
     */
    public function testEveryFindingKindIsNamedInBothLocalesAndHasAColour(): void
    {
        $params = [
            'job_id' => 7,
            'position' => 2,
            'mode' => 'create',
            'time' => '2026-10-21 10:00:00',
            'since' => '2026-10-21 10:00:00',
            'host' => 'esxi01',
            'id' => 'R123',
            'hours' => 2,
            'minutes' => 15,
            'phase' => 'hostname',
            'package' => 'Paket 1.0',
        ];

        foreach (Lang::LOCALES as $locale) {
            Lang::load($locale);
            $seen = [];
            foreach (array_keys(VIRTUSPHERE_VM_FINDINGS) as $kind) {
                $finding = ['kind' => $kind, 'severity' => VIRTUSPHERE_VM_FINDINGS[$kind], 'params' => $params, 'link' => []];
                $text = portal_vm_finding_text($finding);

                self::assertNotSame('', trim($text), $locale . ': ' . $kind . ' has no text');
                self::assertStringNotContainsString('status.', $text, $locale . ': ' . $kind . ' renders an untranslated key');
                self::assertStringNotContainsString($kind, $text, $locale . ': ' . $kind . ' renders its raw kind');
                if ($kind !== VIRTUSPHERE_VM_FINDING_MECM_SYNC_FAILED) {
                    self::assertArrayNotHasKey($text, $seen, $locale . ': ' . $kind . ' shares a text with ' . ($seen[$text] ?? ''));
                    $seen[$text] = $kind;
                }
                self::assertContains(
                    virtusphere_vm_finding_meta($finding)['badge'],
                    ['success', 'warning', 'danger', 'info', 'neutral'],
                    $locale . ': ' . $kind . ' has no palette variant'
                );
            }
        }
    }

    /**
     * A finding whose facts are missing says so in its neutral sentence, never
     * with an empty clause and never with the raw token.
     */
    public function testAFindingWithoutItsFactsFallsBackToItsNeutralSentence(): void
    {
        Lang::load('de');

        $this->assertNamedText('status.finding_unknown', portal_vm_finding_text(
            ['kind' => 'not_a_kind', 'severity' => 'neutral', 'params' => [], 'link' => []]
        ));
        $this->assertNamedText('status.finding_job_failed_unknown', portal_vm_finding_text(
            ['kind' => VIRTUSPHERE_VM_FINDING_JOB_FAILED, 'severity' => 'danger', 'params' => [], 'link' => []]
        ));
        $this->assertNamedText('status.finding_job_running_unknown', portal_vm_finding_text(
            ['kind' => VIRTUSPHERE_VM_FINDING_JOB_RUNNING, 'severity' => 'info', 'params' => [], 'link' => []]
        ));
        $this->assertNamedText('status.finding_mecm_pending', portal_vm_finding_text(
            ['kind' => VIRTUSPHERE_VM_FINDING_MECM_PENDING, 'severity' => 'info', 'params' => ['since' => ''], 'link' => []]
        ));
        $this->assertNamedText('status.finding_missing_from_inventory_no_host', portal_vm_finding_text(
            ['kind' => VIRTUSPHERE_VM_FINDING_MISSING_FROM_INVENTORY, 'severity' => 'danger', 'params' => ['host' => '', 'time' => '2026-10-21 10:00:00'], 'link' => []]
        ), ['time' => portal_format_datetime('2026-10-21 10:00:00')]);
    }

    public function testPhaseAndFindingBadgesTakeTheirVariantFromTheMeta(): void
    {
        Lang::load('de');

        self::assertStringContainsString('badge-success', vm_phase_badge(VIRTUSPHERE_VM_PHASE_CLIENT_DONE));
        self::assertStringContainsString(__t('status.phase_client_done'), vm_phase_badge(VIRTUSPHERE_VM_PHASE_CLIENT_DONE));
        self::assertStringContainsString('badge-neutral', vm_phase_badge('not_a_phase'));
        self::assertStringContainsString(__t('status.phase_unknown'), vm_phase_badge('not_a_phase'));
        self::assertStringContainsString('badge-danger', vm_finding_badge([
            'kind' => VIRTUSPHERE_VM_FINDING_CREATE_UNCERTAIN,
            'severity' => 'danger',
            'params' => ['job_id' => 1, 'position' => 1],
            'link' => [],
        ]));
    }

    /** The text is the catalog sentence of that key, and no key or raw token leaks through. */
    /** @param array<string,string> $replace */
    private function assertNamedText(string $key, string $text, array $replace = []): void
    {
        self::assertStringNotContainsString('status.', $text, $key . ' renders an untranslated key');
        self::assertSame(__t($key, $replace), $text);
    }

    public function testMissionStatusIsNotTreatedAsAnEnum(): void
    {
        Lang::load('de');
        self::assertSame(__t('status.mission_active'), portal_mission_status_label('active'));
        self::assertSame(__t('status.mission_active'), portal_mission_status_label('  active  '));
        // A free legacy value keeps its own text: the column is a VARCHAR that
        // this application only ever wrote `active` into, so anything else is
        // somebody's data, not a state this portal may reinterpret.
        self::assertSame('archiviert 2019', portal_mission_status_label('archiviert 2019'));
        self::assertSame('', portal_mission_status_label('   '));
    }
}
