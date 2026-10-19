<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AV-R12 and HA-06 as source contracts: the editor offers the two MECM save buttons only for a
 * registered VM and only while its OS deviates (the stored state renders them, forms.js follows
 * the OS field), the transfer button sends only through its own POST value, the save queues
 * inside the save transaction, and the retired transfer button and its keys are gone.
 */
final class VmMecmSaveContractTest extends TestCase
{
    private static function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testTheRetiredTransferButtonAndItsKeysAreGone(): void
    {
        $root = dirname(__DIR__, 2);
        $retired = ['transfer_mecm', 'vm_queue_mecm_transfer_with_audit', 'vm_mecm_transfer_', 'vm_mecm_preview_', 'flash_mecm_transfer_pending'];
        $files = array_merge(
            glob($root . '/lib/*.php') ?: [],
            glob($root . '/lib/*/*.php') ?: [],
            glob($root . '/portal/*.php') ?: [],
            glob($root . '/lang/*/*.php') ?: [],
        );
        self::assertNotSame([], $files, 'the scan matched nothing');
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach ($retired as $token) {
                self::assertStringNotContainsString($token, $source, basename($file) . ' still names the retired transfer button');
            }
        }
    }

    public function testTheTwoButtonsCarryTheirOwnPostValueAndTheTransferAsksFirst(): void
    {
        $panels = self::source('lib/vm_edit_panels.php');

        self::assertStringContainsString('name="mecm_save" value="<?php echo h(VIRTUSPHERE_VM_MECM_SAVE_PORTAL_ONLY); ?>"', $panels);
        self::assertStringContainsString('name="mecm_save" value="<?php echo h(VIRTUSPHERE_VM_MECM_SAVE_TRANSFER); ?>"', $panels);
        self::assertStringContainsString('data-confirm="<?php echo h(__t(\'vm_edit.mecm_transfer_confirm\')); ?>"', $panels);
        self::assertStringContainsString('data-mecm-save-single', $panels, 'the single save button stays for every other VM');
    }

    public function testTheTwoButtonsExistOnlyForARegisteredVmAndAreHiddenWithoutADeviation(): void
    {
        $panels = self::source('lib/vm_edit_panels.php');

        // The two buttons open their own split guard, so neither renders for a VM without MECM state.
        self::assertSame(1, preg_match('/<\?php if \(\$mecmView\[\'split\'\]\) \{ \?>\s*<button class="button button-secondary"/', $panels, $match, PREG_OFFSET_CAPTURE), 'the two buttons sit inside the split guard');
        $split = (int) $match[0][1];
        self::assertGreaterThan($split, strpos($panels, 'data-mecm-save-portal'));
        self::assertGreaterThan($split, strpos($panels, 'data-mecm-save-transfer'));
        // Shown on a deviation: the hint and the two buttons. Hidden on a deviation: the single button.
        self::assertSame(3, substr_count($panels, "\$mecmView['deviates'] ? '' : ' hidden'"), 'hint, portal and transfer button');
        self::assertSame(1, substr_count($panels, "\$mecmView['deviates'] ? ' hidden' : ''"), 'the single button');
    }

    public function testTheOsFieldLinksItsHintOnlyWhileTheOsDeviates(): void
    {
        $panels = self::source('lib/vm_edit_panels.php');

        self::assertStringContainsString("\$mecmView['deviates'] ? [form_hint_id('vm_edit', 'mecm_os')] : false", $panels);
        self::assertStringContainsString('data-mecm-os-hint', $panels);
        self::assertStringNotContainsString('aria-describedby="', $panels, 'the attribute comes from form_control_attrs()');
    }

    public function testTheEditorFormCarriesTheMecmOsAndFormsJsFollowsIt(): void
    {
        $page = self::source('lib/vm_edit_panels.php');
        $forms = self::source('portal/assets/forms.js');

        self::assertStringContainsString('vm_mecm_save_form_attrs($mecmView)', $page);
        self::assertStringContainsString("form[data-mecm-os]", $forms);
        self::assertStringContainsString('function syncMecmSave(form)', $forms);
        self::assertStringContainsString("event.target.name === 'vm_os'", $forms);
        self::assertStringContainsString('[data-mecm-save-transfer]', $forms);
    }

    public function testTheSaveQueuesInsideTheSaveTransaction(): void
    {
        $service = self::source('lib/vm_save_service.php');

        $transaction = strpos($service, 'repo_transaction($db, static function');
        $queue = strpos($service, 'vm_mecm_save_transfer($db, $missionId, $savedVmId');
        self::assertNotFalse($transaction);
        self::assertNotFalse($queue);
        self::assertGreaterThan($transaction, $queue, 'the queue runs inside the save transaction');
        self::assertStringNotContainsString('repo_mark_vm_for_mecm_resync', $service, 'only the MECM module queues a VM');
    }

    public function testTheQueueNamesItsActionsAndTheConstantsStayOutOfTheSharedCatalog(): void
    {
        $module = self::source('lib/vm_mecm_save.php');
        $constants = self::source('lib/constants.php');

        self::assertStringContainsString("'queued_mecm_transfer_on_save'", $module);
        self::assertStringContainsString("'queued_mecm_transfer_os_confirmed'", $module);
        self::assertStringNotContainsString('VIRTUSPHERE_VM_MECM_SAVE', $constants, 'constants.php must not grow for this feature');
    }

    public function testTheNewKeysExistInBothLocales(): void
    {
        $keys = ['flash_mecm_queued', 'flash_mecm_added', 'flash_mecm_removed', 'flash_mecm_portal_only', 'mecm_os_hint', 'mecm_os_unknown', 'mecm_transfer_confirm', 'save_portal_only', 'save_and_transfer'];
        foreach (['de', 'en'] as $locale) {
            $catalog = self::source('lang/' . $locale . '/vm_edit.php');
            foreach ($keys as $key) {
                self::assertMatchesRegularExpression("/'" . $key . "' => '/", $catalog, $locale . ' vm_edit.' . $key);
            }
        }
    }
}
