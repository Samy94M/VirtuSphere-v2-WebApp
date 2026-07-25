<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_form_state.php';

/**
 * The deploy queue form re-renders from three sources and only one of them used
 * to be read, so changing the mission reset every other field to its default.
 *
 * Both the source choice and form_state() memoize, so each case runs in its own
 * process to start from a clean slate.
 */
final class DeployFormStateTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAMissionChangeRestoresTheQueryStringButNotTheVmSelection(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['mission_id' => '4', 'mode' => 'export', 'verbose' => '1', 'stagger_minutes' => '7'];

        self::assertSame('export', deploy_form_value('mode', 'full'));
        self::assertSame('1', deploy_form_value('verbose'));
        self::assertSame('7', deploy_form_value('stagger_minutes'));
        // A field the URL does not carry keeps its constant default.
        self::assertSame('5', deploy_form_value('powercycle_wait', '5'));
        // The checkboxes named the VMs of the mission being left, so the new
        // mission starts fully checked instead of empty.
        self::assertNull(deploy_form_vm_selection());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testThePreviewRenderReadsItsOwnPostIncludingTheVmSubset(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['mode' => 'powercycle', 'vm_ids' => ['3', 7, ['nested'], 'abc', '0']];

        self::assertSame('powercycle', deploy_form_value('mode', 'full'));
        // A non-scalar drops as form_old_array() drops it; a forged id drops
        // the way the repo drops it when the same payload is enqueued.
        self::assertSame([3 => true, 7 => true], deploy_form_vm_selection());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAPostWinsOverALeftoverStashSoAnUncheckedBoxStaysUnchecked(): void
    {
        // One source wins per render. Falling back per field would re-check
        // `verbose` from the older stash: an unchecked box posts no key at all.
        $_SESSION['_form_state'] = [
            'schedule' => ['old' => ['verbose' => '1', 'mode' => 'full'], 'errors' => []],
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['mode' => 'export'];

        self::assertSame('export', deploy_form_value('mode', 'full'));
        self::assertSame('', deploy_form_value('verbose'));
        self::assertSame([], deploy_form_vm_selection());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAFailedValidationBeatsTheQueryStringItRedirectedTo(): void
    {
        // The redirect target carries only mission_id; everything else lives in
        // the stash, which must therefore win on that render.
        $_SESSION['_form_state'] = [
            'schedule' => ['old' => ['mode' => 'start', 'vm_ids' => ['9']], 'errors' => []],
        ];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['mission_id' => '4', 'mode' => 'export'];

        self::assertSame('start', deploy_form_value('mode', 'full'));
        self::assertSame([9 => true], deploy_form_vm_selection());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testABracketedParameterFallsBackInsteadOfThrowing(): void
    {
        // `?mode[]=x` reaches the reader as an array; request_string() answers
        // with the default instead of raising "Array to string conversion".
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['mode' => ['export'], 'vm_ids' => 'not-a-list'];

        self::assertSame('full', deploy_form_value('mode', 'full'));
        self::assertNull(deploy_form_vm_selection());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAScalarVmIdsIsNotASelectionOfOne(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['vm_ids' => '3'];

        // Mirrors form_old_array(): a checkbox list is an array or nothing.
        self::assertSame([], deploy_form_vm_selection());
    }
}
