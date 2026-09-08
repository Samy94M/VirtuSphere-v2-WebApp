<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_create_progress.php';

/**
 * The per-VM progress card (Etappe 14B, Teiletappe G).
 *
 * The incident this whole stage answers was "which of the fifteen VMs exist",
 * and the reason it had no answer was that the only record was prose. So the
 * cases proved here are the ones a green run never produces: fourteen done and
 * one unresolved, a job that creates nothing at all, and a unit that has no
 * start time. None of them needs a database, and all of them decide what a
 * person reads at the moment they are least able to check it themselves.
 */
final class DeployCreateProgressTest extends TestCase
{
    /**
     * @param list<array{0:string,1:string}> $units status/outcome pairs
     * @return list<array<string, mixed>>
     */
    private function rows(array $units, ?string $startedAt = '2026-09-02 10:00:00'): array
    {
        $rows = [];
        $position = 0;
        foreach ($units as [$status, $outcome]) {
            $position++;
            $rows[] = [
                'position' => $position,
                'vm_name' => sprintf('VM%03d', $position),
                'status' => $status,
                'outcome' => $outcome,
                'error_code' => '',
                'started_at' => $startedAt,
            ];
        }

        return $rows;
    }

    public function testAJobWithoutACreateSectionHasNoCard(): void
    {
        // Not "zero of zero": an export follow-up or an inventory pull creates
        // nothing, and a card at zero would teach readers that the card is
        // meaningless on every other job too.
        self::assertNull(deploy_create_progress_from_rows([]));
        self::assertNull(deploy_create_progress_payload_from_view(null));
    }

    public function testFourteenDoneAndOneUnresolvedIsCountedAsSuchNotAsFailed(): void
    {
        $units = [];
        for ($i = 0; $i < 14; $i++) {
            $units[] = [VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, 'created'];
        }
        $units[] = [VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN, ''];

        $view = deploy_create_progress_from_rows($this->rows($units));

        self::assertNotNull($view);
        self::assertSame(15, $view['total']);
        self::assertSame(14, $view['counters']['created']);
        self::assertSame(1, $view['counters']['uncertain']);
        // The one thing that must never happen: an unresolved unit counted as a
        // failure. A failure says the VM was not created; unresolved says
        // nobody established either way, and the two lead to opposite actions.
        self::assertSame(0, $view['counters']['failed']);
        self::assertSame(0, $view['counters']['not_started']);
        self::assertNotNull($view['unresolved']);
        self::assertSame(15, $view['unresolved']['position']);
        self::assertSame('VM015', $view['unresolved']['vm_name']);

        // The headline says 14 of 15, not 15 of 15. deploy_create_summary()
        // counts an uncertain unit as processed, which is correct for the
        // worker ("we got to it") and pinned there at 15, but reading "15 of 15
        // concluded" directly above "unresolved: 1" tells a person the job is
        // finished and that something is open in the same breath.
        self::assertSame(14, $view['concluded']);
        self::assertSame(15, $view['processed'], 'the worker-side meaning must not be redefined by the card');
    }

    public function testAStoppedJobStillReportsWhatWasNeverStarted(): void
    {
        $view = deploy_create_progress_from_rows($this->rows([
            [VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, 'created'],
            [VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN, ''],
            [VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, ''],
            [VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING, ''],
        ]));

        self::assertNotNull($view);
        self::assertSame(4, $view['total']);
        self::assertSame(2, $view['counters']['not_started']);
        // The unresolved unit is where the job stopped, so it is the current
        // one, not the first pending row behind it.
        self::assertSame(2, $view['current']['position']);
    }

    public function testTheUnresolvedUnitIsNotDescribedAsRunning(): void
    {
        $running = deploy_create_progress_current_label([
            'position' => 3,
            'vm_name' => 'VM003',
            'status' => VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING,
        ]);
        $uncertain = deploy_create_progress_current_label([
            'position' => 3,
            'vm_name' => 'VM003',
            'status' => VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
        ]);

        self::assertSame(__t('deploy.create_progress_current_running', ['name' => 'VM003', 'position' => '3']), $running);
        self::assertSame(__t('deploy.create_progress_current_uncertain', ['name' => 'VM003', 'position' => '3']), $uncertain);
        self::assertNotSame($running, $uncertain, 'a stopped unit must not read as one that is still working');
    }

    public function testThePayloadCarriesFinishedSentencesAndNeverARawToken(): void
    {
        $view = deploy_create_progress_from_rows($this->rows([
            [VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED, 'created'],
            [VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, ''],
        ]));
        $payload = deploy_create_progress_payload_from_view($view);

        self::assertNotNull($payload);
        self::assertSame(
            __t('deploy.create_progress_position', ['concluded' => 1, 'total' => 2]),
            $payload['position_label']
        );
        self::assertNotNull($payload['current']);
        self::assertIsString($payload['current']['label']);
        self::assertIsString($payload['current']['since_label']);
        // A translated string is never assembled in the browser, so the raw
        // status must not travel at all: if it were in the payload, the first
        // person to "just print what the server sent" would ship the token.
        self::assertArrayNotHasKey('status', $payload['current']);
        // Scoped to the current unit on purpose. The counter names legitimately
        // read like statuses ('failed', 'uncertain', 'skipped') because they
        // count exactly those outcomes; a whole-payload scan would fail on its
        // own keys and would have to be weakened until it proved nothing. What
        // must not appear is a status as a VALUE describing the current unit.
        $current = json_encode($payload['current'], JSON_THROW_ON_ERROR);
        foreach (VIRTUSPHERE_CREATE_RESULT_STATUSES as $status) {
            self::assertStringNotContainsString(
                '"' . $status . '"',
                $current,
                $status . ' reached the wire as a bare token'
            );
        }
    }

    public function testAUnitWithoutAStartTimeGetsNoSinceFragment(): void
    {
        // Null rather than an empty sentence: the card hides the fragment with
        // its separator, and a lone middle dot reads as a rendering fault.
        $view = deploy_create_progress_from_rows(
            $this->rows([[VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING, '']], null)
        );
        $payload = deploy_create_progress_payload_from_view($view);

        self::assertNotNull($payload);
        self::assertNull($payload['current']['since_label']);
    }

    public function testEveryRenderedCounterIsNamedInBothLocales(): void
    {
        // A counter added to the constant without a label would render its own
        // key at the reader. The card walks this list, so the build has to.
        foreach (VIRTUSPHERE_CREATE_PROGRESS_COUNTERS as $counter) {
            $key = 'deploy.create_progress_count_' . $counter;
            foreach (['de', 'en'] as $locale) {
                $catalog = require dirname(__DIR__, 2) . '/lang/' . $locale . '/deploy.php';
                self::assertArrayHasKey(
                    'create_progress_count_' . $counter,
                    $catalog,
                    $key . ' is missing in ' . $locale
                );
            }
        }
    }

    public function testStoredCreateFindingsDistinguishFailureClassesWithoutParsingProse(): void
    {
        $rows = $this->rows([
            [VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, ''],
            [VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN, ''],
            [VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED, ''],
            [VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN, ''],
        ]);
        $rows[0]['error_code'] = VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED;
        $rows[1]['error_code'] = VIRTUSPHERE_CREATE_ERROR_ASYNC_STATE_MISSING;
        $rows[2]['error_code'] = VIRTUSPHERE_CREATE_ERROR_IDENTITY_CONFLICT;
        $rows[3]['error_code'] = VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR;

        $view = deploy_create_progress_from_rows($rows);
        self::assertNotNull($view);
        self::assertSame([
            'deploy.create_progress_reason_module_failed',
            'deploy.create_progress_reason_unresolved_observation',
            'deploy.create_progress_reason_identity_conflict',
            'deploy.create_progress_reason_invalid_evidence',
        ], array_column($view['findings'], 'reason_key'));
        foreach ($view['findings'] as $finding) {
            self::assertNotSame($finding['error_code'], $finding['reason_label']);
        }
    }
}
