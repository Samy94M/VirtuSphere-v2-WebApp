<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/credentials_status.php';

/**
 * The credentials page for the trust mode: the open inventory pull of a row
 * (its disabled button and its status line) and the downgrade question of a
 * strict row's editor. The page itself needs a session and a layout, so the
 * panel is checked in its source the way RemoteSecretNoticeRenderTest checks
 * the remote secret line, and the pure helper is run directly.
 */
final class CredentialsTrustPanelTest extends TestCase
{
    private function panelSource(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/lib/credentials_panels.php');
        self::assertIsString($source);

        return $source;
    }

    public function testNoOpenJobMeansNoNoticeAndTheButtonStaysEnabled(): void
    {
        self::assertNull(credential_inventory_pending_notice(null));
    }

    public function testQueuedAndRunningJobsGetTheirOwnSentenceAndTheLogLink(): void
    {
        $queued = credential_inventory_pending_notice(['id' => 4321, 'status' => VIRTUSPHERE_DEPLOY_STATUS_QUEUED]);
        self::assertNotNull($queued);
        self::assertFalse($queued['running']);
        self::assertSame(4321, $queued['job_id']);
        self::assertSame('deploy_log.php?id=4321', $queued['url']);
        self::assertSame(__t('credentials.inventory_queued_line'), $queued['line']);
        self::assertStringContainsString('4321', $queued['title']);

        $running = credential_inventory_pending_notice(['id' => 4322, 'status' => VIRTUSPHERE_DEPLOY_STATUS_RUNNING]);
        self::assertNotNull($running);
        self::assertTrue($running['running']);
        self::assertSame(__t('credentials.inventory_running_line'), $running['line']);
        self::assertStringContainsString('4322', $running['title']);

        // A cancelling pull still occupies the credential, so it reads as running.
        $cancelling = credential_inventory_pending_notice(['id' => 4323, 'status' => VIRTUSPHERE_DEPLOY_STATUS_CANCELLING]);
        self::assertNotNull($cancelling);
        self::assertTrue($cancelling['running']);
    }

    public function testTheRowsReadTheOpenJobsOnceForTheWholeTable(): void
    {
        $source = $this->panelSource();

        self::assertSame(1, substr_count($source, 'repo_esxi_inventory_pending_jobs($connection)'), 'one table-wide read, not one per row');
        self::assertStringContainsString('credential_inventory_pending_notice($pendingInventoryJobs[$rowId] ?? null)', $source);
    }

    public function testAnOpenPullDisablesTheButtonWithItsReasonAndLinksTheLineBelow(): void
    {
        $source = $this->panelSource();

        self::assertStringContainsString("' disabled title=\"' . h(\$pendingNotice['title']) . '\"' . form_described_by_attr('credential-pending-' . \$rowId)", $source);
        self::assertStringNotContainsString('aria-describedby', $source, 'the form contract owns the attribute; the panel asks lib/forms.php for it');
        self::assertStringContainsString('id="credential-pending-<?php echo h((string) $rowId); ?>"', $source, 'the line the button points to');
        self::assertStringContainsString('<a href="<?php echo h($pendingNotice[\'url\']); ?>">', $source, 'the line links the job log');
    }

    public function testTheDowngradeQuestionIsRenderedOnlyForStrictRowsAndNeverAsAnEmptyConfirm(): void
    {
        $source = $this->panelSource();

        self::assertStringContainsString('$strictRow = $isEsxi && credential_esxi_trust_mode($row) === VIRTUSPHERE_ESXI_TRUST_STRICT;', $source);
        self::assertStringContainsString("if (\$strictRow) { ?> data-downgrade-confirm=\"<?php echo h(__t('credentials.confirm_disable_verification', ['name' => (string) (\$row['name'] ?? '')])); ?>\"", $source);
        // The question lives on the form, not on the button: the button only gets data-confirm from forms.js.
        self::assertStringNotContainsString('data-confirm="<?php echo h(__t(\'credentials.confirm_disable_verification\'', $source);
    }

    public function testTheUseLegacyButtonIsGoneAndTheCertificateTypeHasThreeChoices(): void
    {
        $source = $this->panelSource();

        self::assertStringNotContainsString('use_legacy', $source);
        self::assertStringNotContainsString('new_strict_hint', $source);
        self::assertStringContainsString('VIRTUSPHERE_ESXI_CERT_DEFAULT_NEW', $source, 'the create form preselects the named default');
        self::assertSame(2, substr_count($source, 'value="<?php echo h(VIRTUSPHERE_ESXI_CERT_NONE); ?>"'), 'one "none" option in the create form and one in the editor');
    }

    public function testTheCertificateTextIsDisabledWhileNoneIsChosenOnEveryForm(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/portal/assets/forms.js');
        self::assertIsString($source);

        self::assertStringContainsString("pemField.disabled = certField.value === 'none';", $source);
        self::assertStringContainsString("if (event.target.name === 'esxi_cert_kind' && downgradeForm) {", $source, 're-evaluated on change');
        self::assertStringContainsString("document.querySelectorAll('select[name=\"esxi_cert_kind\"]').forEach(", $source, 'evaluated on load for create and every editor');
        // The create form and each editor carry the same select name, so the handler needs no per-form list.
        $panel = file_get_contents(dirname(__DIR__, 2) . '/lib/credentials_panels.php');
        self::assertIsString($panel);
        self::assertSame(2, substr_count($panel, 'name="esxi_cert_kind"'), 'the create form and the editor share one control name');
    }

    public function testTheHandlerSetsOrRemovesTheConfirmOnTheSaveButton(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/portal/assets/forms.js');
        self::assertIsString($source);

        self::assertStringContainsString("submit.setAttribute('data-confirm', question);", $source);
        self::assertStringContainsString("submit.removeAttribute('data-confirm');", $source, 'a blank data-confirm would still be matched by core.js');
        self::assertStringContainsString("form.querySelector('button[type=\"submit\"]')", $source);
        self::assertStringContainsString("document.querySelectorAll('form[data-downgrade-confirm]').forEach(syncVerificationDowngrade);", $source, 'applied on load');
    }
}
