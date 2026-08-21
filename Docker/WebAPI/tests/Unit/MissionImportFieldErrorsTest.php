<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/mission_transfer.php';

/**
 * Collecting every field error out of a repo list validator, without copying a
 * single one of its rules.
 *
 * Two properties are load-bearing and neither is visible in the output alone.
 * The whole list runs FIRST, so a rule that only holds across entries (the
 * network/VLAN contract planned as Etappe 14A) still reaches its one owner; and
 * only when that run fails does the helper re-run per entry, because the repo
 * validators throw at the first broken row and a file with three bad interfaces
 * would otherwise report one, get fixed, and meet the next on the second upload.
 * A fake validator is used deliberately: the point is the collecting mechanism,
 * not any particular repo rule.
 */
final class MissionImportFieldErrorsTest extends TestCase
{
    /** Fails on any row carrying 'bad', like the repo validators: first one wins. */
    private function firstBadEntryValidator(): callable
    {
        return static function (array $rows): array {
            foreach ($rows as $index => $row) {
                if (($row['value'] ?? '') === 'bad') {
                    throw new ValidationException(['interfaces.' . $index . '.value' => 'value is invalid']);
                }
            }

            return $rows;
        };
    }

    public function testACleanListProducesNoMessages(): void
    {
        $rows = [['value' => 'ok'], ['value' => 'ok']];

        self::assertSame([], mission_import_list_field_errors($this->firstBadEntryValidator(), 'interfaces', $rows));
    }

    /**
     * The regression this exists for: three broken entries have to produce three
     * messages, each naming its position in the FILE, not one message naming
     * index 0 three times.
     */
    public function testEveryBrokenEntryIsReportedWithItsFilePosition(): void
    {
        $rows = [['value' => 'ok'], ['value' => 'bad'], ['value' => 'bad']];

        $errors = mission_import_list_field_errors($this->firstBadEntryValidator(), 'interfaces', $rows);

        self::assertSame([
            'interfaces[2].value: value is invalid',
            'interfaces[3].value: value is invalid',
        ], $errors);
    }

    /**
     * A rule that only fails across the whole list keeps its own messages: per
     * entry nothing is wrong, and answering with an empty list would drop the
     * finding entirely. This is what keeps a future list-wide network contract
     * working through this helper.
     */
    public function testAListWideRuleKeepsItsOwnMessages(): void
    {
        $listOnly = static function (array $rows): array {
            if (count($rows) > 1) {
                throw new ValidationException(['interfaces' => 'two interfaces share one VLAN']);
            }

            return $rows;
        };

        $errors = mission_import_list_field_errors($listOnly, 'interfaces', [['value' => 'ok'], ['value' => 'ok']]);

        self::assertSame(['two interfaces share one VLAN'], $errors);
    }

    /** The whole list is validated first, and exactly once when it passes. */
    public function testTheWholeListIsValidatedBeforeAnyEntry(): void
    {
        $calls = [];
        $recording = static function (array $rows) use (&$calls): array {
            $calls[] = count($rows);

            return $rows;
        };

        mission_import_list_field_errors($recording, 'disks', [['value' => 'ok'], ['value' => 'ok']]);

        self::assertSame([2], $calls, 'a passing list must not be re-validated entry by entry');
    }

    /**
     * A field key the validator did not path-qualify still gets a position, so a
     * message can never be about "some entry".
     */
    public function testAnUnqualifiedFieldKeyStillCarriesAPosition(): void
    {
        $entryOnly = static function (array $rows): array {
            if ($rows !== [] && ($rows[0]['value'] ?? '') === 'bad') {
                throw new ValidationException(['disks.0' => 'entry is invalid']);
            }

            return $rows;
        };

        $errors = mission_import_list_field_errors($entryOnly, 'disks', [['value' => 'bad']]);

        self::assertSame(['disks[1]: entry is invalid'], $errors);
    }
}
