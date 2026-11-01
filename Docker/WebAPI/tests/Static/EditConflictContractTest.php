<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/request.php';
require_once dirname(__DIR__, 2) . '/lib/vm_edit_form.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/mission_details_page.php';

/**
 * QL-01/QL-03 B. The comparison derives its fields from the save path, so a
 * column added to an editor appears in the comparison or fails here; and the
 * continue step of both editors is wired so that it cannot reach a writer.
 */
final class EditConflictContractTest extends TestCase
{
    public function testVmComparisonCoversEverySavedValueExceptTheHiddenDiskMirror(): void
    {
        $saved = array_keys(vm_edit_submitted_values([], false));
        $compared = array_keys(vm_edit_conflict_fields([]));

        self::assertSame(array_values(array_diff($saved, ['vm_disk'])), $compared);
    }

    public function testMissionComparisonCoversEverySavedColumnAndOnlyEditableOnes(): void
    {
        $saved = array_keys(mission_details_submitted_changes([]));

        self::assertSame($saved, array_keys(mission_details_conflict_fields()));
        self::assertSame([], array_values(array_diff($saved, REPO_MISSION_EDITABLE_COLUMNS)));
    }

    public function testBothEditorsUseTheSavePathFieldListAndTheSharedRenderer(): void
    {
        $page = self::source('lib/vm_edit_page.php');
        $mission = self::source('portal/mission_details.php');

        self::assertStringContainsString('$vmData = vm_edit_submitted_values($_POST);', $page);
        self::assertStringContainsString('$missionChanges = mission_details_submitted_changes($_POST);', $mission);
        self::assertStringContainsString("edit_conflict_render(\$editConflict, 'vm-editor-form')", self::source('lib/vm_edit_panels.php'));
        self::assertStringContainsString("edit_conflict_render(\$editConflict, 'mission-settings-form')", $mission);
        self::assertStringContainsString('id="vm-editor-form"', self::source('lib/vm_edit_panels.php'));
        self::assertStringContainsString('id="mission-settings-form"', $mission);
    }

    public function testTheContinueStepNeverReachesAWriter(): void
    {
        // VM: the continue branch only re-renders; the save lives in the else branch.
        $page = self::source('lib/vm_edit_page.php');
        $continue = strpos($page, 'if ($continueVersion !== null) {');
        $else = strpos($page, '} else {', (int) $continue);
        $save = strpos($page, 'vm_save_with_audit(');
        self::assertNotFalse($continue);
        self::assertNotFalse($else);
        self::assertGreaterThan($else, $save, 'the VM save must sit in the branch the continue step does not take');
        self::assertDoesNotMatchRegularExpression('/repo_|vm_save_with_audit|audit_event/', substr($page, (int) $continue, (int) $else - (int) $continue));

        // Mission: the continue step remembers and redirects before the update action.
        $mission = self::source('portal/mission_details.php');
        $start = strpos($mission, 'edit_conflict_continue_version($_POST)');
        $redirect = strpos($mission, 'redirect_to($detailsUrl);', (int) $start);
        self::assertNotFalse($start);
        self::assertNotFalse($redirect);
        self::assertLessThan(strpos($mission, 'repo_update_mission_checked('), $redirect);
        self::assertDoesNotMatchRegularExpression('/repo_|audit_event/', substr($mission, (int) $start, (int) $redirect - (int) $start));
    }

    public function testTheContinueButtonPostsTheEditorFormWithoutValidationOrConfirm(): void
    {
        $view = self::source('lib/edit_conflict_view.php');

        self::assertStringContainsString('type="submit" form="<?php echo h($formId); ?>" name="edit_conflict" value="<?php echo h(VIRTUSPHERE_EDIT_CONFLICT_CONTINUE); ?>" formnovalidate>', $view);
        self::assertStringContainsString('name="edit_conflict_version"', $view);
        // It writes nothing, so it carries no confirm and no busy label of a save.
        self::assertStringNotContainsString('data-confirm', $view);
        self::assertStringNotContainsString('data-busy-label', $view);
    }

    public function testTheTextsExistInBothLocalesWithoutDashes(): void
    {
        $de = require dirname(__DIR__, 2) . '/lang/de/edit_conflict.php';
        $en = require dirname(__DIR__, 2) . '/lang/en/edit_conflict.php';

        self::assertSame(array_keys($de), array_keys($en));
        foreach (array_merge(array_values($de), array_values($en)) as $text) {
            self::assertStringNotContainsString('—', $text);
        }
        self::assertSame('Mit meinen Eingaben weiterarbeiten', $de['continue']);
        self::assertSame('Ihre Eingabe', $de['th_mine']);
        self::assertSame('Jetzt gespeichert', $de['th_current']);
    }

    private static function source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    }
}
