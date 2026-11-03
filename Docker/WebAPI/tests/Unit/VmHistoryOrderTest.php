<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/lang.php';
require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/vm_history.php';

/**
 * The VM chronicle (SP3, F03) pages one global order over seven streams. These
 * tests drive the pure owner with an in-memory reader that applies exactly the
 * keyset and the high-water mark the SQL readers apply, so loss, duplication and
 * late arrivals show up here without a database.
 */
final class VmHistoryOrderTest extends TestCase
{
    private const T = '2026-10-31 12:00:00.000000';

    public function testEqualTimesOrderByStreamRankThenNewestId(): void
    {
        $rows = [];
        foreach (array_reverse(array_keys(VIRTUSPHERE_VM_HISTORY_STREAMS)) as $kind) {
            $rows[$kind] = [$this->row(self::T, 3), $this->row(self::T, 7)];
        }

        $page = virtusphere_vm_history_merge($rows, 100);

        $expected = [];
        foreach (array_keys(VIRTUSPHERE_VM_HISTORY_STREAMS) as $kind) {
            $expected[] = $kind . '#7';
            $expected[] = $kind . '#3';
        }
        self::assertSame($expected, $this->keys($page['entries']));
        self::assertFalse($page['more']);
    }

    public function testMicrosecondsOrderAgainstSecondPrecisionSources(): void
    {
        $page = virtusphere_vm_history_merge([
            VIRTUSPHERE_VM_TIMELINE_STATUS => [$this->row('2026-10-31 12:00:00', 1)],
            VIRTUSPHERE_VM_TIMELINE_PACKAGE_RECEIVED => [$this->row('2026-10-31 12:00:00.500000', 2)],
            VIRTUSPHERE_VM_TIMELINE_CLIENT => [$this->row('2026-10-31 12:00:01', 3)],
        ], 10);

        self::assertSame(
            [VIRTUSPHERE_VM_TIMELINE_CLIENT . '#3', VIRTUSPHERE_VM_TIMELINE_PACKAGE_RECEIVED . '#2', VIRTUSPHERE_VM_TIMELINE_STATUS . '#1'],
            $this->keys($page['entries'])
        );
        self::assertSame('2026-10-31 12:00:00.000000', $page['entries'][2]['at'], 'a second-precision time is normalised, not left short');
    }

    public function testPagesCoverEveryEntryOnceAcrossIdenticalTimestamps(): void
    {
        $store = $this->store(45);
        $seen = [];
        $cursor = null;
        $marks = $this->marks($store);
        $pages = 0;
        do {
            $page = virtusphere_vm_history_merge($this->read($store, $cursor, $marks, 10), 10);
            foreach ($page['entries'] as $entry) {
                $seen[] = $entry['kind'] . '#' . $entry['id'];
            }
            $cursor = $page['more'] ? virtusphere_vm_history_position($page['entries'][count($page['entries']) - 1]) : null;
            $pages++;
        } while ($cursor !== null && $pages < 20);

        self::assertSame(5, $pages, '45 entries at ten per page are five pages');
        self::assertCount(45, $seen);
        self::assertSame($seen, array_values(array_unique($seen)), 'no entry appears twice');
        self::assertSame($this->keys(virtusphere_vm_history_merge($this->read($store, null, $marks, 100), 100)['entries']), $seen, 'the pages concatenate to the one global order');
    }

    public function testAnEntryBehindTheHighWaterMarkNeverAppears(): void
    {
        $store = $this->store(12);
        $marks = $this->marks($store);
        $first = virtusphere_vm_history_merge($this->read($store, null, $marks, 5), 5);
        // A newer status row arrives after the page was opened, at the same second.
        $store[VIRTUSPHERE_VM_TIMELINE_STATUS][] = $this->row(self::T, 999);
        $cursor = virtusphere_vm_history_position($first['entries'][4]);
        $seen = [];
        while ($cursor !== null) {
            $page = virtusphere_vm_history_merge($this->read($store, $cursor, $marks, 5), 5);
            foreach ($page['entries'] as $entry) {
                $seen[] = $entry['kind'] . '#' . $entry['id'];
            }
            $cursor = $page['more'] ? virtusphere_vm_history_position($page['entries'][count($page['entries']) - 1]) : null;
        }

        self::assertNotContains(VIRTUSPHERE_VM_TIMELINE_STATUS . '#999', $seen);
        self::assertCount(7, $seen, 'the twelve entries of the snapshot, minus the five already shown');
    }

    public function testTheCursorRoundTripsAndCarriesTheMarks(): void
    {
        $marks = array_fill_keys(VIRTUSPHERE_VM_HISTORY_SOURCES, 0);
        $marks[VIRTUSPHERE_VM_TIMELINE_SOURCE_STATUS] = 41;
        $marks[VIRTUSPHERE_VM_HISTORY_SOURCE_AUDIT] = null;
        $position = ['at' => '2026-10-31 12:00:00.250000', 'rank' => 3, 'id' => 17];

        $encoded = virtusphere_vm_history_cursor_encode($position, $marks);

        self::assertMatchesRegularExpression('/\A[0-9n.]+\z/', $encoded, 'the cursor is numbers and separators only');
        self::assertSame(['position' => $position, 'marks' => $marks], virtusphere_vm_history_cursor_decode($encoded));
    }

    /** @return iterable<string, array{0:string}> */
    public static function manipulatedCursors(): iterable
    {
        $marks = '.1.2.3.4.5';
        yield 'empty' => [''];
        yield 'letters' => ['abc'];
        yield 'short time' => ['2026103112000000000.0.1' . $marks];
        yield 'impossible date' => ['20261332120000000000.0.1' . $marks];
        yield 'rank out of range' => ['20261031120000000000.' . count(VIRTUSPHERE_VM_HISTORY_STREAMS) . '.1' . $marks];
        yield 'zero id' => ['20261031120000000000.0.0' . $marks];
        yield 'negative id' => ['20261031120000000000.0.-1' . $marks];
        yield 'missing mark' => ['20261031120000000000.0.1.1.2.3.4'];
        yield 'extra mark' => ['20261031120000000000.0.1' . $marks . '.6'];
        yield 'oversized id' => ['20261031120000000000.0.9999999999999999999' . $marks];
        yield 'array' => ['20261031120000000000.0.1' . $marks . "\n"];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('manipulatedCursors')]
    public function testManipulatedCursorsAreRejected(string $cursor): void
    {
        self::assertNull(virtusphere_vm_history_cursor_decode($cursor));
    }

    public function testTheFilterKeepsOnlyPermittedSourcesAndConvertsTheRangeToUtc(): void
    {
        $filter = virtusphere_vm_history_filter([
            'sources' => [VIRTUSPHERE_VM_TIMELINE_SOURCE_STATUS, VIRTUSPHERE_VM_HISTORY_SOURCE_AUDIT, 'login', ['x']],
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ], false, 'Europe/Berlin');

        self::assertSame([VIRTUSPHERE_VM_TIMELINE_SOURCE_STATUS], $filter['sources'], 'audit without users.manage and unknown words are dropped');
        self::assertSame('2026-09-30 22:00:00.000000', $filter['from_utc'], 'midnight in the portal timezone, as UTC');
        self::assertSame('2026-10-31 23:00:00.000000', $filter['to_utc'], 'the whole last day is included (CET after the switch)');
        self::assertSame([], $filter['errors']);
    }

    public function testNoSelectionMeansEveryPermittedSource(): void
    {
        self::assertSame(VIRTUSPHERE_VM_HISTORY_SOURCES, virtusphere_vm_history_filter([], true, 'UTC')['sources']);
        self::assertNotContains(VIRTUSPHERE_VM_HISTORY_SOURCE_AUDIT, virtusphere_vm_history_filter([], false, 'UTC')['sources']);
    }

    public function testInvalidOrReversedDatesAreReportedAndIgnored(): void
    {
        $invalid = virtusphere_vm_history_filter(['from' => '31.10.2026', 'to' => '2026-02-30'], true, 'UTC');
        self::assertNull($invalid['from_utc']);
        self::assertNull($invalid['to_utc']);
        self::assertSame(['from', 'to'], $invalid['errors']);

        $reversed = virtusphere_vm_history_filter(['from' => '2026-10-31', 'to' => '2026-10-01'], true, 'UTC');
        self::assertNull($reversed['from_utc']);
        self::assertNull($reversed['to_utc']);
        self::assertSame(['range'], $reversed['errors']);
        self::assertSame('2026-10-31', $reversed['from'], 'the typed value stays in the field');
    }

    public function testEveryStreamBelongsToAKnownSourceAndEverySourceHasARetentionAnswer(): void
    {
        self::assertSame(VIRTUSPHERE_VM_HISTORY_SOURCES, array_values(array_unique(array_values(VIRTUSPHERE_VM_HISTORY_STREAMS))));
        foreach (VIRTUSPHERE_VM_HISTORY_SOURCES as $source) {
            $days = virtusphere_vm_history_retention_days($source);
            self::assertTrue($days === null || $days > 0, $source);
        }
        self::assertNull(virtusphere_vm_history_retention_days(VIRTUSPHERE_VM_TIMELINE_SOURCE_JOB), 'jobs live with their mission');
    }

    /** @return array{sort_at:string, sort_id:int, id:int} */
    private function row(string $at, int $id): array
    {
        return ['sort_at' => $at, 'sort_id' => $id, 'id' => $id];
    }

    /**
     * $count entries spread over all seven streams; most share one timestamp.
     *
     * @return array<string, list<array<string,mixed>>>
     */
    private function store(int $count): array
    {
        $kinds = array_keys(VIRTUSPHERE_VM_HISTORY_STREAMS);
        $store = array_fill_keys($kinds, []);
        for ($i = 1; $i <= $count; $i++) {
            $kind = $kinds[$i % count($kinds)];
            $at = $i % 5 === 0 ? '2026-10-31 11:59:59.000000' : self::T;
            $store[$kind][] = $this->row($at, $i);
        }

        return $store;
    }

    /** @return array<string, ?int> */
    private function marks(array $store): array
    {
        $marks = array_fill_keys(VIRTUSPHERE_VM_HISTORY_SOURCES, 0);
        foreach ($store as $kind => $rows) {
            $source = VIRTUSPHERE_VM_HISTORY_STREAMS[$kind];
            foreach ($rows as $row) {
                $marks[$source] = max((int) $marks[$source], (int) $row['sort_id']);
            }
        }

        return $marks;
    }

    /**
     * The in-memory twin of the SQL readers: mark, keyset, newest first, $limit + 1.
     *
     * @param array{at:string,rank:int,id:int}|null $cursor
     * @return array<string, list<array<string,mixed>>>
     */
    private function read(array $store, ?array $cursor, array $marks, int $limit): array
    {
        $out = [];
        foreach ($store as $kind => $rows) {
            $mark = $marks[VIRTUSPHERE_VM_HISTORY_STREAMS[$kind]];
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $mark !== null && $row['sort_id'] <= $mark
                && ($cursor === null || virtusphere_vm_history_is_after($kind, $row['sort_at'], $row['sort_id'], $cursor))));
            usort($rows, static fn (array $a, array $b): int => [$b['sort_at'], $b['sort_id']] <=> [$a['sort_at'], $a['sort_id']]);
            $out[$kind] = array_slice($rows, 0, $limit + 1);
        }

        return $out;
    }

    /** @return list<string> */
    private function keys(array $entries): array
    {
        return array_map(static fn (array $entry): string => $entry['kind'] . '#' . $entry['id'], $entries);
    }
}
