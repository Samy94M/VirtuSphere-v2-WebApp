<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * form_old_array() is the sticky-selection reader behind the deploy VM checkboxes:
 * after a validation failure the form must re-check exactly what was posted, so a
 * corrected resubmit cannot silently widen to the whole mission.
 *
 * form_state() memoizes the session read in a function static, so each case runs
 * in its own process to start from a clean slate.
 */
final class FormOldArrayTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReturnsThePostedListAsStringsAndDropsNonScalars(): void
    {
        $_SESSION['_form_state'] = [
            'schedule' => ['old' => ['vm_ids' => ['1', 3, ['nested'], '7']], 'errors' => []],
        ];

        self::assertSame(['1', '3', '7'], form_old_array('schedule', 'vm_ids'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReturnsEmptyWithoutRememberedState(): void
    {
        self::assertSame([], form_old_array('schedule', 'vm_ids'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReturnsEmptyWhenTheFieldWasScalarNotAList(): void
    {
        $_SESSION['_form_state'] = [
            'schedule' => ['old' => ['mode' => 'full'], 'errors' => []],
        ];

        // A scalar field is not a checkbox list; the reader must not turn it into
        // a one-element selection.
        self::assertSame([], form_old_array('schedule', 'mode'));
        // A field that was never posted, and a form with no state at all.
        self::assertSame([], form_old_array('schedule', 'vm_ids'));
        self::assertSame([], form_old_array('other', 'vm_ids'));
    }
}
