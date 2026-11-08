<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/edit_conflict.php';

/**
 * QL-01/QL-03 B: after a refused editor save the page compares the operator's
 * input with the stored row. These pin the pure owner: only fields that differ
 * after the save path's normalization appear, child tables are summarized as
 * counts, and the "continue with my input" step adopts the shown version
 * without ever being mistaken for a save.
 */
final class EditConflictDiffTest extends TestCase
{
    public function testEqualValuesAfterNormalizationProduceNoRow(): void
    {
        $diff = edit_conflict_field_diff(
            ['name' => '  web01 ', 'cpu' => '04', 'hotplug' => 'on', 'notes' => "a\r\nb"],
            ['name' => 'web01', 'cpu' => 4, 'hotplug' => 1, 'notes' => "a\nb"],
            ['name' => 'text', 'cpu' => 'int', 'hotplug' => 'bool', 'notes' => 'text']
        );

        self::assertSame([], $diff);
    }

    public function testOnlyDifferingFieldsAreReturnedInSpecOrder(): void
    {
        $diff = edit_conflict_field_diff(
            ['cpu' => '2', 'name' => 'web01', 'hotplug' => '0', 'delay' => ''],
            ['name' => 'web02', 'cpu' => '2', 'hotplug' => '1', 'delay' => null],
            ['name' => 'text', 'cpu' => 'int', 'hotplug' => 'bool', 'delay' => 'int']
        );

        self::assertSame([
            ['field' => 'name', 'submitted' => 'web01', 'current' => 'web02'],
            ['field' => 'hotplug', 'submitted' => '0', 'current' => '1'],
        ], $diff);
    }

    public function testAFieldTheFormDidNotPostIsNotCompared(): void
    {
        self::assertSame([], edit_conflict_field_diff([], ['name' => 'web02'], ['name' => 'text']));
    }

    public function testAnEmptyIntegerStaysEmptyAndANonNumberStaysText(): void
    {
        $diff = edit_conflict_field_diff(
            ['a' => '', 'b' => 'x12'],
            ['a' => '0', 'b' => '12'],
            ['a' => 'int', 'b' => 'int']
        );

        self::assertSame([
            ['field' => 'a', 'submitted' => '', 'current' => '0'],
            ['field' => 'b', 'submitted' => 'x12', 'current' => '12'],
        ], $diff);
    }

    public function testChildRowsAreSummarizedAsCountsOnlyWhenTheyDiffer(): void
    {
        $rows = [['ip' => '192.0.2.1', 'vlan' => 'a'], ['ip' => '', 'vlan' => 'b']];

        self::assertNull(edit_conflict_rows_summary($rows, $rows));
        self::assertSame(['submitted' => 2, 'current' => 3], edit_conflict_rows_summary($rows, [...$rows, ['ip' => '', 'vlan' => 'c']]));
        // Same count, different content: still a difference, still counts only.
        self::assertSame(['submitted' => 2, 'current' => 2], edit_conflict_rows_summary($rows, [$rows[0], ['ip' => '', 'vlan' => 'z']]));
    }

    public function testModeNamesConflictContinuedOrNothing(): void
    {
        self::assertSame(VIRTUSPHERE_EDIT_CONFLICT_MODE_CONFLICT, edit_conflict_mode('3', '4', false));
        self::assertSame(VIRTUSPHERE_EDIT_CONFLICT_MODE_CONFLICT, edit_conflict_mode('3', '4', true));
        self::assertSame(VIRTUSPHERE_EDIT_CONFLICT_MODE_CONFLICT, edit_conflict_mode('', '4', false));
        self::assertSame(VIRTUSPHERE_EDIT_CONFLICT_MODE_CONTINUED, edit_conflict_mode('4', '4', true));
        self::assertSame('', edit_conflict_mode('4', '4', false));
    }

    public function testContinueIsRecognizedAndNeverFallsBackToASave(): void
    {
        self::assertNull(edit_conflict_continue_version(['edit_version' => '3']));
        self::assertNull(edit_conflict_continue_version(['edit_conflict' => 'save', 'edit_conflict_version' => '4']));
        self::assertSame('4', edit_conflict_continue_version(['edit_conflict' => VIRTUSPHERE_EDIT_CONFLICT_CONTINUE, 'edit_conflict_version' => '4']));
        // A malformed version is still a continue: the caller keeps the submitted one and writes nothing.
        self::assertSame('', edit_conflict_continue_version(['edit_conflict' => VIRTUSPHERE_EDIT_CONFLICT_CONTINUE, 'edit_conflict_version' => '04x']));
        self::assertSame('', edit_conflict_continue_version(['edit_conflict' => VIRTUSPHERE_EDIT_CONFLICT_CONTINUE, 'edit_conflict_version' => ['4']]));
    }

    public function testContinueStateAdoptsTheShownVersionAndMarksTheStep(): void
    {
        $state = edit_conflict_continue_state(['mission_name' => 'm1', 'edit_version' => '3', 'edit_conflict' => 'continue', 'edit_conflict_version' => '5'], '5');
        self::assertSame(['mission_name' => 'm1', 'edit_version' => '5', 'edit_conflict' => VIRTUSPHERE_EDIT_CONFLICT_CONTINUED], $state);

        $kept = edit_conflict_continue_state(['edit_version' => '3', 'edit_conflict' => 'continue'], '');
        self::assertSame('3', $kept['edit_version']);
    }

    public function testDisplayRowsUseLabelsOptionsAndEmptyText(): void
    {
        $rows = edit_conflict_rows(
            ['name' => 'web01', 'action' => 'shutdown', 'on' => '1', 'ds' => ''],
            ['name' => 'web02', 'action' => 'powerOff', 'on' => '0', 'ds' => 'ds1'],
            [
                'name' => ['label' => 'Name', 'kind' => 'text'],
                'action' => ['label' => 'Stop', 'kind' => 'text', 'options' => ['shutdown' => 'Herunterfahren', 'powerOff' => 'Ausschalten']],
                'on' => ['label' => 'Autostart', 'kind' => 'bool'],
                'ds' => ['label' => 'Datastore', 'kind' => 'text', 'empty' => 'Von der Mission'],
            ]
        );

        self::assertSame(['Name', 'web01', 'web02'], [$rows[0]['label'], $rows[0]['submitted'], $rows[0]['current']]);
        self::assertSame(['Herunterfahren', 'Ausschalten'], [$rows[1]['submitted'], $rows[1]['current']]);
        self::assertSame([__t('common.yes'), __t('common.no')], [$rows[2]['submitted'], $rows[2]['current']]);
        self::assertSame(['Von der Mission', 'ds1'], [$rows[3]['submitted'], $rows[3]['current']]);
        self::assertFalse($rows[0]['rows']);
    }

    public function testTheOwnerReadsAndWritesNothing(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/edit_conflict.php');
        // The comparison is a view of two arrays. A repository call or a query
        // here would turn "show me the difference" into a second save path.
        self::assertDoesNotMatchRegularExpression('/\brepo_[a-z_]+\s*\(|mysqli|->query\(|->prepare\(|form_remember\s*\(|\$_(POST|GET|SESSION)\b/', $source);
    }
}
