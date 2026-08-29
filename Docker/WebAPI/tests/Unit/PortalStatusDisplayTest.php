<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout_response.php';
require_once dirname(__DIR__, 2) . '/lib/status.php';
require_once dirname(__DIR__, 2) . '/lib/layout_presenters.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_display.php';

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
    /** Every deploy-job status the portal can be asked to render. */
    private const JOB_STATUSES = [
        VIRTUSPHERE_DEPLOY_STATUS_QUEUED,
        VIRTUSPHERE_DEPLOY_STATUS_RUNNING,
        VIRTUSPHERE_DEPLOY_STATUS_CANCELLING,
        VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED,
        VIRTUSPHERE_DEPLOY_STATUS_FAILED,
        VIRTUSPHERE_DEPLOY_STATUS_CANCELLED,
        VIRTUSPHERE_DEPLOY_STATUS_PARTIAL,
    ];

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
            'job status' => [self::JOB_STATUSES, deploy_job_status_label(...), 'status.job_unknown'],
            // The six postable modes come from the technical SSoT itself, so a
            // mode added there without a label fails here instead of shipping
            // its payload token as a name. `inventory` is appended because the
            // portal shows it although nobody can post it.
            'deploy mode' => [
                array_merge(array_keys(virtusphere_deploy_mode_labels()), [VIRTUSPHERE_DEPLOY_MODE_INVENTORY]),
                deploy_mode_label(...),
                'status.mode_unknown',
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
