<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/audit_events.php';

/**
 * The pure helpers behind the field-level audit diff. They decide what a log line
 * reveals, so their edges matter: an empty value must be legible, a secret must
 * never leak, and a runaway diff must not blow the line up or hide its own
 * truncation.
 */
final class AuditEventsTest extends TestCase
{
    public function testOnlyChangedFieldsAppearInTheSummary(): void
    {
        $before = ['name' => 'M1', 'datastore' => 'ds1', 'datacenter' => 'DC1'];
        $after = ['name' => 'M1', 'datastore' => 'ds2', 'datacenter' => 'DC1'];

        self::assertSame('datastore: "ds1" -> "ds2"', audit_change_summary($before, $after));
    }

    public function testOnlyKeysPresentInAfterAreCompared(): void
    {
        // A caller lists exactly the columns it wrote; unrelated stored columns
        // must not surface as spurious changes.
        $before = ['name' => 'M1', 'secret_hash' => 'abc', 'datastore' => 'ds1'];
        $after = ['datastore' => 'ds2'];

        self::assertSame('datastore: "ds1" -> "ds2"', audit_change_summary($before, $after));
    }

    public function testNullAndEmptyAreTheSameValue(): void
    {
        // An empty datacenter and a NULL one read identically to an operator, so
        // clearing an already-empty field is not a change.
        self::assertSame('', audit_change_summary(['dc' => null], ['dc' => '']));
        self::assertSame('', audit_change_summary(['dc' => '  '], ['dc' => '']));
    }

    public function testEmptyValuesRenderAsAMarkerNotBlank(): void
    {
        self::assertSame('dc: (empty) -> "DC1"', audit_change_summary(['dc' => ''], ['dc' => 'DC1']));
        self::assertSame('dc: "DC1" -> (empty)', audit_change_summary(['dc' => 'DC1'], ['dc' => '']));
    }

    public function testOpaqueFieldsReportChangedWithoutTheirValue(): void
    {
        // Secrets and free prose must never reach the log as their value.
        $summary = audit_change_summary(['secret' => 'old-pw', 'notes' => 'a'], ['secret' => 'new-pw', 'notes' => 'b'], ['secret', 'notes']);

        self::assertStringContainsString('secret: changed', $summary);
        self::assertStringContainsString('notes: changed', $summary);
        self::assertStringNotContainsString('old-pw', $summary);
        self::assertStringNotContainsString('new-pw', $summary);
    }

    public function testLongValuesAreBounded(): void
    {
        $long = str_repeat('x', 200);
        $summary = audit_change_summary(['name' => ''], ['name' => $long]);

        self::assertLessThanOrEqual(VIRTUSPHERE_AUDIT_VALUE_MAX + 20, mb_strlen($summary));
        self::assertStringContainsString('…', $summary);
    }

    public function testNewlinesAreCollapsedSoOneEventStaysOneLine(): void
    {
        $summary = audit_change_summary(['notes' => ''], ['notes' => "line1\nline2\r\nline3"]);

        self::assertStringNotContainsString("\n", $summary);
        self::assertStringContainsString('line1 line2 line3', $summary);
    }

    public function testAManyFieldDiffTruncatesAndCountsTheRemainder(): void
    {
        $before = [];
        $after = [];
        for ($i = 0; $i < 40; $i++) {
            $before['field' . $i] = 'old' . str_repeat('y', 20);
            $after['field' . $i] = 'new' . str_repeat('z', 20);
        }
        $summary = audit_change_summary($before, $after);

        self::assertLessThanOrEqual(VIRTUSPHERE_AUDIT_SUMMARY_MAX + 30, mb_strlen($summary));
        self::assertMatchesRegularExpression('/\+\d+ more field\(s\)$/', $summary, 'a truncated summary must admit it is incomplete');
    }

    /**
     * The no-op note moved into the presenter with Etappe 10C, because it is a
     * statement about the rendered description and not about the diff: the
     * structured row says `action=updated` with no `changes` key, which is the
     * same fact without a sentence. An update that changed nothing must still
     * say so, since under optimistic locking a no-op save is a real event.
     */
    public function testDescriptionOfAnUpdateThatChangedNothingSaysSo(): void
    {
        $unchanged = audit_event_description(
            VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED,
            'vm',
            '7',
            VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
            ['action' => 'updated', 'mission_id' => 3]
        );
        self::assertStringEndsWith('(no field changes)', $unchanged);

        $changed = audit_event_description(
            VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED,
            'vm',
            '7',
            VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
            ['action' => 'updated', 'mission_id' => 3, 'changes' => 'datastore: "a" -> "b"']
        );
        self::assertStringEndsWith('(datastore: "a" -> "b")', $changed);

        // A create has no previous value to diff against, so it must not claim
        // that nothing changed.
        $created = audit_event_description(
            VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED,
            'vm',
            '7',
            VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
            ['action' => 'created', 'mission_id' => 3]
        );
        self::assertStringNotContainsString('no field changes', $created);
    }

    public function testSnippetLeavesShortValuesIntact(): void
    {
        self::assertSame('ha-datacenter', audit_snippet('  ha-datacenter  '));
        self::assertSame('', audit_snippet(null));
    }
}
