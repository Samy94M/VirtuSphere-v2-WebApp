<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/lang.php';
require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/vm_timeline.php';

/**
 * The VM timeline (plan ST-2, section 2) is one pure mixer over four evidence
 * sources, plus a note catalog that turns the writers' technical notes into
 * words. Each test names one rule: the merge order, the kind and row of every
 * entry, the tail for missing times, the tie order, the two limits, and the
 * wording of every note in both locales.
 *
 * Nothing here reads a database or a clock: the times are the rows' own values.
 */
final class VmTimelineTest extends TestCase
{
    /** @return iterable<string, array{0:string}> */
    public static function statusNotes(): iterable
    {
        foreach (array_keys(VIRTUSPHERE_VM_STATUS_NOTES) as $note) {
            yield $note => [$note];
        }
    }

    /** @return iterable<string, array{0:string}> */
    public static function jobSuffixes(): iterable
    {
        foreach (array_keys(VIRTUSPHERE_VM_STATUS_JOB_NOTES) as $suffix) {
            yield $suffix => [$suffix];
        }
    }

    /** @return iterable<string, array{0:string}> */
    public static function terminalJobStatuses(): iterable
    {
        yield VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED => [VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED];
        yield VIRTUSPHERE_DEPLOY_STATUS_FAILED => [VIRTUSPHERE_DEPLOY_STATUS_FAILED];
        yield VIRTUSPHERE_DEPLOY_STATUS_CANCELLED => [VIRTUSPHERE_DEPLOY_STATUS_CANCELLED];
        yield VIRTUSPHERE_DEPLOY_STATUS_PARTIAL => [VIRTUSPHERE_DEPLOY_STATUS_PARTIAL];
    }

    /** @return iterable<string, array{0:string}> */
    public static function activeJobStatuses(): iterable
    {
        yield VIRTUSPHERE_DEPLOY_STATUS_QUEUED => [VIRTUSPHERE_DEPLOY_STATUS_QUEUED];
        yield VIRTUSPHERE_DEPLOY_STATUS_RUNNING => [VIRTUSPHERE_DEPLOY_STATUS_RUNNING];
        yield VIRTUSPHERE_DEPLOY_STATUS_CANCELLING => [VIRTUSPHERE_DEPLOY_STATUS_CANCELLING];
    }

    /** @return iterable<string, array{0:?string}> */
    public static function emptyNotes(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }

    public function testTheFourSourcesAreMergedNewestFirstWithTheirOwnKindAndRow(): void
    {
        $status = self::statusRow('2026-10-20 10:00:00');
        $job = self::jobRow(7, VIRTUSPHERE_DEPLOY_STATUS_RUNNING, '2026-10-20 10:50:00', '2026-10-20 11:00:00', null);
        $package = self::packageRow('run-1', '2026-10-20 09:00:00', '2026-10-20 12:00:00');
        $client = self::clientRow('2026-10-20 10:30:00');

        $timeline = virtusphere_vm_timeline([$status], [$job], [$package], [$client]);

        self::assertFalse($timeline['truncated']);
        self::assertSame([
            [VIRTUSPHERE_VM_TIMELINE_PACKAGE_COMPLETED, VIRTUSPHERE_VM_TIMELINE_SOURCE_PACKAGE, '2026-10-20 12:00:00'],
            [VIRTUSPHERE_VM_TIMELINE_JOB_STARTED, VIRTUSPHERE_VM_TIMELINE_SOURCE_JOB, '2026-10-20 11:00:00'],
            [VIRTUSPHERE_VM_TIMELINE_CLIENT, VIRTUSPHERE_VM_TIMELINE_SOURCE_CLIENT, '2026-10-20 10:30:00'],
            [VIRTUSPHERE_VM_TIMELINE_STATUS, VIRTUSPHERE_VM_TIMELINE_SOURCE_STATUS, '2026-10-20 10:00:00'],
            [VIRTUSPHERE_VM_TIMELINE_PACKAGE_RECEIVED, VIRTUSPHERE_VM_TIMELINE_SOURCE_PACKAGE, '2026-10-20 09:00:00'],
        ], self::digest($timeline['entries']));
        self::assertSame([$package, $job, $client, $status, $package], array_column($timeline['entries'], 'row'), 'each entry carries its original row');
    }

    public function testAPackageRunWithoutACompletionTimeIsOnlyReceived(): void
    {
        $timeline = virtusphere_vm_timeline([], [], [self::packageRow('run-1', '2026-10-20 09:00:00')], []);

        self::assertSame([
            [VIRTUSPHERE_VM_TIMELINE_PACKAGE_RECEIVED, VIRTUSPHERE_VM_TIMELINE_SOURCE_PACKAGE, '2026-10-20 09:00:00'],
        ], self::digest($timeline['entries']));
    }

    public function testACompletedPackageRunAlsoShowsItsCompletion(): void
    {
        $package = self::packageRow('run-1', '2026-10-20 09:00:00', '2026-10-20 09:40:00', 1);

        $timeline = virtusphere_vm_timeline([], [], [$package], []);

        self::assertSame([
            [VIRTUSPHERE_VM_TIMELINE_PACKAGE_COMPLETED, VIRTUSPHERE_VM_TIMELINE_SOURCE_PACKAGE, '2026-10-20 09:40:00'],
            [VIRTUSPHERE_VM_TIMELINE_PACKAGE_RECEIVED, VIRTUSPHERE_VM_TIMELINE_SOURCE_PACKAGE, '2026-10-20 09:00:00'],
        ], self::digest($timeline['entries']));
        self::assertSame([$package, $package], array_column($timeline['entries'], 'row'));
    }

    /** @param string $status an active job has started, and nothing else is known about its end */
    #[PHPUnit\Framework\Attributes\DataProvider('activeJobStatuses')]
    public function testAnActiveJobIsOnlyStartedAtItsStartTime(string $status): void
    {
        $job = self::jobRow(7, $status, '2026-10-20 08:00:00', '2026-10-20 08:05:00', '2026-10-20 08:30:00');

        $timeline = virtusphere_vm_timeline([], [$job], [], []);

        self::assertSame([
            [VIRTUSPHERE_VM_TIMELINE_JOB_STARTED, VIRTUSPHERE_VM_TIMELINE_SOURCE_JOB, '2026-10-20 08:05:00'],
        ], self::digest($timeline['entries']));
        self::assertSame([$job], array_column($timeline['entries'], 'row'));
    }

    /** @param string $status a terminal job also shows its finish, at its last update */
    #[PHPUnit\Framework\Attributes\DataProvider('terminalJobStatuses')]
    public function testATerminalJobAlsoShowsItsFinishAtItsLastUpdate(string $status): void
    {
        $job = self::jobRow(7, $status, '2026-10-20 08:00:00', '2026-10-20 08:05:00', '2026-10-20 08:30:00');

        $timeline = virtusphere_vm_timeline([], [$job], [], []);

        self::assertSame([
            [VIRTUSPHERE_VM_TIMELINE_JOB_FINISHED, VIRTUSPHERE_VM_TIMELINE_SOURCE_JOB, '2026-10-20 08:30:00'],
            [VIRTUSPHERE_VM_TIMELINE_JOB_STARTED, VIRTUSPHERE_VM_TIMELINE_SOURCE_JOB, '2026-10-20 08:05:00'],
        ], self::digest($timeline['entries']));
        self::assertSame([$job, $job], array_column($timeline['entries'], 'row'));
    }

    public function testAJobWithoutAStartTimeIsDatedByItsCreation(): void
    {
        $job = self::jobRow(7, VIRTUSPHERE_DEPLOY_STATUS_RUNNING, '2026-10-20 08:00:00', null, null);

        $timeline = virtusphere_vm_timeline([], [$job], [], []);

        self::assertSame([
            [VIRTUSPHERE_VM_TIMELINE_JOB_STARTED, VIRTUSPHERE_VM_TIMELINE_SOURCE_JOB, '2026-10-20 08:00:00'],
        ], self::digest($timeline['entries']));
    }

    public function testEntriesWithoutAUsableTimeStandAtTheEndInInputOrder(): void
    {
        $timeline = virtusphere_vm_timeline([
            self::statusRow(null, 'cloned from template'),
            self::statusRow('2026-10-20 12:00:00', 'created from portal'),
            self::statusRow('not-a-date', 'captured from mission'),
            self::statusRow('2026-10-20 08:00:00', 'imported from file'),
            self::statusRow('', 'ansible mac import'),
        ], [], [], []);

        self::assertSame(
            ['created from portal', 'imported from file', 'cloned from template', 'captured from mission', 'ansible mac import'],
            array_column(array_column($timeline['entries'], 'row'), 'note'),
            'the dated entries come first, newest first; the undated ones keep their input order'
        );
        self::assertSame(
            ['2026-10-20 12:00:00', '2026-10-20 08:00:00', '', 'not-a-date', ''],
            array_column($timeline['entries'], 'at'),
            'a missing or unparsable time is never replaced by an invented one'
        );
    }

    public function testAtTheSameTimeTheSourceOrderIsStatusJobPackageClient(): void
    {
        $at = '2026-10-20 10:00:00';

        $timeline = virtusphere_vm_timeline(
            [self::statusRow($at)],
            [self::jobRow(7, VIRTUSPHERE_DEPLOY_STATUS_RUNNING, '2026-10-20 09:00:00', $at, null)],
            [self::packageRow('run-1', $at)],
            [self::clientRow($at)]
        );

        self::assertSame(
            [VIRTUSPHERE_VM_TIMELINE_STATUS, VIRTUSPHERE_VM_TIMELINE_JOB_STARTED, VIRTUSPHERE_VM_TIMELINE_PACKAGE_RECEIVED, VIRTUSPHERE_VM_TIMELINE_CLIENT],
            array_column($timeline['entries'], 'kind')
        );
    }

    public function testTheMergedListIsCutAtTheDefaultLimitAndSaysSo(): void
    {
        $statusEvents = [];
        for ($minute = 0; $minute < 25; $minute++) {
            $statusEvents[] = self::statusRow(sprintf('2026-10-20 10:%02d:00', $minute));
        }

        $timeline = virtusphere_vm_timeline($statusEvents, [], [], []);

        self::assertSame(20, VIRTUSPHERE_VM_TIMELINE_LIMIT);
        self::assertCount(20, $timeline['entries']);
        self::assertTrue($timeline['truncated']);
        self::assertSame('2026-10-20 10:24:00', $timeline['entries'][0]['at'], 'the newest entries are the ones kept');
        self::assertSame('2026-10-20 10:05:00', $timeline['entries'][19]['at']);
    }

    public function testThreeEntriesAreNotTruncated(): void
    {
        $timeline = virtusphere_vm_timeline($this->statusRows(3), [], [], []);

        self::assertCount(3, $timeline['entries']);
        self::assertFalse($timeline['truncated']);
    }

    public function testASourceAtItsOwnLimitIsTruncatedEvenWhenTheListFits(): void
    {
        $three = $this->statusRows(3);

        self::assertTrue(virtusphere_vm_timeline($three, [], [], [], VIRTUSPHERE_VM_TIMELINE_LIMIT, 3)['truncated'], 'a source at exactly its limit may have been cut');
        self::assertFalse(virtusphere_vm_timeline($three, [], [], [], VIRTUSPHERE_VM_TIMELINE_LIMIT, 4)['truncated'], 'a source below its limit was read whole');
    }

    public function testTheLimitsCanBeSetPerCall(): void
    {
        $two = virtusphere_vm_timeline($this->statusRows(2), [], [], [], 2);
        self::assertCount(2, $two['entries']);
        self::assertFalse($two['truncated'], 'two entries fit a limit of two');

        $three = virtusphere_vm_timeline($this->statusRows(3), [], [], [], 2);
        self::assertCount(2, $three['entries']);
        self::assertTrue($three['truncated']);
        self::assertSame(['2026-10-20 10:03:00', '2026-10-20 10:02:00'], array_column($three['entries'], 'at'));
    }

    public function testNoEvidenceGivesAnEmptyTimelineThatIsNotTruncated(): void
    {
        self::assertSame(['entries' => [], 'truncated' => false], virtusphere_vm_timeline([], [], [], []));
    }

    /** @param string $note every status note the writers store, shown in both locales */
    #[PHPUnit\Framework\Attributes\DataProvider('statusNotes')]
    public function testEveryStatusNoteHasAWordingInBothLocales(string $note): void
    {
        foreach (['de', 'en'] as $locale) {
            $text = virtusphere_vm_status_note_text($note, $locale);

            self::assertNotSame('', trim($text), $locale . ': ' . $note . ' has no wording');
            self::assertNotSame($note, $text, $locale . ': ' . $note . ' still shows its raw note');
            self::assertStringStartsNotWith('vm_timeline.', $text, $locale . ': ' . $note . ' shows an untranslated key');
            self::assertDoesNotMatchRegularExpression('/:[a-z]/', $text, $locale . ': ' . $note . ' keeps a placeholder');
            self::assertStringNotContainsString('—', $text, $locale . ': ' . $note . ' uses an em dash');
        }
    }

    /** @param string $suffix the worker's note after 'deploy job <id> ' */
    #[PHPUnit\Framework\Attributes\DataProvider('jobSuffixes')]
    public function testEveryWorkerNoteHasAWordingThatNamesTheJob(string $suffix): void
    {
        $note = 'deploy job 42 ' . $suffix;

        foreach (['de', 'en'] as $locale) {
            $text = virtusphere_vm_status_note_text($note, $locale);

            self::assertNotSame('', trim($text), $locale . ': ' . $note . ' has no wording');
            self::assertNotSame($note, $text, $locale . ': ' . $note . ' still shows its raw note');
            self::assertStringStartsNotWith('vm_timeline.', $text, $locale . ': ' . $note . ' shows an untranslated key');
            self::assertDoesNotMatchRegularExpression('/:[a-z]/', $text, $locale . ': ' . $note . ' keeps a placeholder');
            self::assertStringContainsString('42', $text, $locale . ': ' . $note . ' does not name the job');
            self::assertStringNotContainsString('—', $text, $locale . ': ' . $note . ' uses an em dash');
        }
    }

    /** @param string $note */
    #[PHPUnit\Framework\Attributes\DataProvider('statusNotes')]
    public function testAStatusNoteNamesItsCatalogKeyAndHasNoJobOrKeptState(string $note): void
    {
        $parsed = virtusphere_vm_status_note($note);

        self::assertIsArray($parsed);
        self::assertSame('vm_timeline.note_' . VIRTUSPHERE_VM_STATUS_NOTES[$note], $parsed['key']);
        self::assertNull($parsed['kept']);
        self::assertSame($note, $parsed['raw']);
    }

    /** @param string $suffix */
    #[PHPUnit\Framework\Attributes\DataProvider('jobSuffixes')]
    public function testAWorkerNoteNamesItsCatalogKeyAndTheJobNumber(string $suffix): void
    {
        $parsed = virtusphere_vm_status_note('deploy job 42 ' . $suffix);

        self::assertIsArray($parsed);
        self::assertSame('vm_timeline.note_' . VIRTUSPHERE_VM_STATUS_JOB_NOTES[$suffix], $parsed['key']);
        self::assertSame(42, $parsed['params']['job']);
    }

    public function testTheLifecycleSuffixNamesTheKeptStateInBothLocales(): void
    {
        $note = 'mecm update id (lifecycle kept at os_installed; a later state is not overwritten)';

        $parsed = virtusphere_vm_status_note($note);

        self::assertIsArray($parsed);
        self::assertSame('os_installed', $parsed['kept']);
        foreach (['de', 'en'] as $locale) {
            $text = virtusphere_vm_status_note_text($note, $locale);

            self::assertStringContainsString(Lang::getIn($locale, 'status.lifecycle_os_installed'), $text, $locale . ': the kept state is not named');
            self::assertStringStartsWith(virtusphere_vm_status_note_text('mecm update id', $locale) . ' ', $text, $locale . ': the sentence does not follow the base text');
            self::assertStringNotContainsString('os_installed', $text, $locale . ': the raw state leaked');
            self::assertStringNotContainsString('lifecycle kept at', $text, $locale . ': the raw suffix leaked');
        }
    }

    /**
     * The writer's suffix is appended to an empty note too, which leaves a
     * leading space. That space must not turn the suffix into an unknown note.
     */
    public function testALifecycleSuffixAfterAnEmptyBaseIsOnlyTheSentence(): void
    {
        $note = ' (lifecycle kept at os_installed; a later state is not overwritten)';

        $parsed = virtusphere_vm_status_note($note);

        self::assertIsArray($parsed);
        self::assertSame('os_installed', $parsed['kept']);
        foreach (['de', 'en'] as $locale) {
            $text = virtusphere_vm_status_note_text($note, $locale);

            self::assertStringContainsString(Lang::getIn($locale, 'status.lifecycle_os_installed'), $text, $locale . ': the kept state is not named');
            self::assertStringStartsNotWith(' ', $text, $locale . ': the empty base leaves a space in front');
            self::assertStringNotContainsString('lifecycle kept at', $text, $locale . ': the raw suffix leaked');
        }
    }

    public function testAnUnknownKeptStateIsNeutralAndNeverEchoed(): void
    {
        $note = 'mecm update id (lifecycle kept at mystery_state; a later state is not overwritten)';

        foreach (['de', 'en'] as $locale) {
            $text = virtusphere_vm_status_note_text($note, $locale);

            self::assertStringContainsString(Lang::getIn($locale, 'status.lifecycle_unknown'), $text, $locale . ': the neutral label is missing');
            self::assertStringNotContainsString('mystery_state', $text, $locale . ': the unknown token was echoed');
        }
    }

    public function testAnUnknownNoteIsShownAsItIsWithoutAKey(): void
    {
        foreach (['something odd happened', 'deploy job 7 exploded'] as $note) {
            $parsed = virtusphere_vm_status_note($note);

            self::assertIsArray($parsed, $note);
            self::assertNull($parsed['key'], $note);
            foreach (['de', 'en'] as $locale) {
                self::assertSame($note, virtusphere_vm_status_note_text($note, $locale), $locale . ': ' . $note);
            }
        }
    }

    /** @param ?string $note a note without any text has no entry and no wording */
    #[PHPUnit\Framework\Attributes\DataProvider('emptyNotes')]
    public function testAnEmptyNoteHasNoEntryAndNoWording(?string $note): void
    {
        self::assertNull(virtusphere_vm_status_note($note));
        foreach (['de', 'en'] as $locale) {
            self::assertSame('', virtusphere_vm_status_note_text($note, $locale), $locale);
        }
    }

    public function testTheLocalesDifferForAtLeastOneNote(): void
    {
        $differing = 0;
        foreach (array_keys(VIRTUSPHERE_VM_STATUS_NOTES) as $note) {
            if (virtusphere_vm_status_note_text($note, 'de') !== virtusphere_vm_status_note_text($note, 'en')) {
                $differing++;
            }
        }

        self::assertGreaterThan(0, $differing, 'the English catalog must not be a copy of the German one');
    }

    protected function tearDown(): void
    {
        Lang::load(Lang::DEFAULT_LOCALE);
    }

    /** @return array<string,mixed> */
    private static function statusRow(?string $createdAt, string $note = 'created from portal'): array
    {
        return [
            'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_READY,
            'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_NOT_READY,
            'legacy_status' => VIRTUSPHERE_STATUS_REGISTERED,
            'note' => $note,
            'created_at' => $createdAt,
            'actor_name' => 'admin',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function statusRows(int $count): array
    {
        $rows = [];
        for ($minute = 1; $minute <= $count; $minute++) {
            $rows[] = self::statusRow(sprintf('2026-10-20 10:%02d:00', $minute));
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private static function jobRow(int $id, string $status, ?string $createdAt, ?string $startedAt, ?string $updatedAt): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'mode' => 'create',
            'created_at' => $createdAt,
            'started_at' => $startedAt,
            'updated_at' => $updatedAt,
        ];
    }

    /** @return array<string,mixed> */
    private static function packageRow(string $runId, ?string $firstReceivedAt, ?string $completedAt = null, int $reportedFailures = 0): array
    {
        return [
            'run_id' => $runId,
            'project_name' => 'Paket',
            'package_version' => '1.0',
            'first_received_at' => $firstReceivedAt,
            'completed_received_at' => $completedAt,
            'wrapper_result' => $completedAt === null ? null : 'ok',
            'reported_failures' => $reportedFailures,
        ];
    }

    /** @return array<string,mixed> */
    private static function clientRow(string $createdAt, string $event = VIRTUSPHERE_CLIENT_EVENT_FINISHED): array
    {
        return [
            'phase' => VIRTUSPHERE_CLIENT_PHASE_HOSTNAME,
            'event' => $event,
            'detail' => null,
            'created_at' => $createdAt,
        ];
    }

    /**
     * @param list<array{at:string,source:string,kind:string,row:array<string,mixed>}> $entries
     * @return list<array{0:string,1:string,2:string}> kind, source and time of each entry, in order
     */
    private static function digest(array $entries): array
    {
        return array_map(
            static fn (array $entry): array => [$entry['kind'], $entry['source'], $entry['at']],
            $entries
        );
    }
}
