<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Markup and wording pins for the portal correctness package Q (audit 16.10.2026,
 * QL-01 to QL-16 and SD-03b). Each assertion names the finding it guards.
 *
 * The behaviour itself is covered where it lives (DeleteWhileDeployingTest,
 * VmEditCpuFieldTest, MissionsEmptyListTest, VmRamTest); this file keeps the
 * render sites and both catalogs from drifting back to the state the audit found.
 */
final class PortalQolMarkupContractTest extends TestCase
{
    private static function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    private static function source(string $relative): string
    {
        $path = self::root() . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return array<string, mixed> */
    private static function catalog(string $locale, string $module): array
    {
        $values = require self::root() . '/lang/' . $locale . '/' . $module . '.php';
        self::assertIsArray($values);

        return $values;
    }

    /** The same key in both locales, so a key that exists in one catalog only fails here. */
    private static function texts(string $module, string $key): array
    {
        $de = (string) (self::catalog('de', $module)[$key] ?? '');
        $en = (string) (self::catalog('en', $module)[$key] ?? '');
        self::assertNotSame('', $de, 'de ' . $module . '.' . $key . ' is missing');
        self::assertNotSame('', $en, 'en ' . $module . '.' . $key . ' is missing');

        return [$de, $en];
    }

    public function testQl02BothEditorSaveButtonsCarryTheBusyLabel(): void
    {
        self::assertStringContainsString(
            'data-busy-label="<?php echo h(__t(\'vm_edit.save_vm_busy\')); ?>"',
            self::source('lib/vm_edit_panels.php'),
            'the VM editor save button needs data-busy-label, or the double-click guard in core.js does not see it'
        );
        self::assertStringContainsString(
            'data-busy-label="<?php echo h(__t(\'mission_details.save_busy\')); ?>"',
            self::source('portal/mission_details.php')
        );
        self::texts('vm_edit', 'save_vm_busy');
        self::texts('mission_details', 'save_busy');
    }

    public function testQl01Ql03ConflictMessagesSayThatReloadingDiscardsTheInput(): void
    {
        [$deStale, $enStale] = self::texts('validate', 'mission_stale');
        [$deConflict, $enConflict] = self::texts('vm_edit', 'err_conflict');

        foreach ([$deStale, $deConflict] as $text) {
            self::assertStringContainsString('neu laden', $text);
            self::assertStringContainsString('verwirft', $text, 'the message must name the loss a reload causes');
        }
        foreach ([$enStale, $enConflict] as $text) {
            self::assertStringContainsString('reload', $text);
            self::assertStringContainsString('discards', $text, 'the message must name the loss a reload causes');
        }
    }

    public function testQl12Ql13VmEditorFieldsShowTheirRangeFromTheConstants(): void
    {
        $panels = self::source('lib/vm_edit_panels.php');
        self::assertStringContainsString(
            'max="<?php echo h((string) VIRTUSPHERE_VM_LIMITS[\'cpu_count_max\']); ?>"',
            $panels
        );
        self::assertStringContainsString(
            'min="<?php echo h((string) VIRTUSPHERE_VM_LIMITS[\'cpu_count_min\']); ?>"',
            $panels
        );

        $ram = self::source('lib/vm_edit_ram.php');
        self::assertStringContainsString("__t('vm_edit.ram_range', ['min' => vm_ram_format(VIRTUSPHERE_VM_LIMITS['ram_mb_min'])", $ram);
        self::assertStringContainsString("'max' => vm_ram_format(VIRTUSPHERE_VM_LIMITS['ram_mb_max'])]", $ram);

        foreach (['de', 'en'] as $locale) {
            $range = (string) self::catalog($locale, 'vm_edit')['ram_range'];
            self::assertStringContainsString(':min', $range, $locale . ' ram_range takes the minimum as a placeholder');
            self::assertStringContainsString(':max', $range, $locale . ' ram_range takes the maximum as a placeholder');
        }
    }

    public function testQl06TheEditorRefusesAnEmptiedCpuFieldInsteadOfDefaulting(): void
    {
        $page = self::source('lib/vm_edit_page.php');
        self::assertStringContainsString("'vm_cpu' => vm_edit_cpu_from_post(\$_POST),", $page);
        self::assertStringNotContainsString("'vm_cpu' => request_string(\$_POST, 'vm_cpu'),", $page);
    }

    public function testQl09TheBulkButtonsAreEnabledInTheMarkupAndTheSelectionIsNamed(): void
    {
        $vms = self::source('portal/vms.php');
        $buttons = array_values(array_filter(
            preg_split('/\R/', $vms) ?: [],
            static fn (string $line): bool => str_contains($line, 'data-bulk-submit')
        ));
        self::assertCount(2, $buttons, 'delete and MECM reset are the two bulk submit buttons');
        foreach ($buttons as $line) {
            self::assertStringNotContainsString(' disabled', $line, 'a statically disabled bulk button cannot be used without JavaScript');
        }

        self::assertStringContainsString("__t('vms.bulk_select_hint')", $vms);
        self::assertStringContainsString("__t('vms.bulk_hint', ['cap' => VIRTUSPHERE_VM_BULK_CAP])", $vms);
        self::texts('vms', 'bulk_select_hint');
        foreach (['de', 'en'] as $locale) {
            self::assertStringContainsString(':cap', (string) self::catalog($locale, 'vms')['bulk_hint'], $locale . ' bulk_hint names the cap as a placeholder');
        }
    }

    /**
     * QL-10 named the column after the MECM interface value it showed. Since
     * the phase/finding rework (ST-1) the column shows the derived phase, the
     * raw x/5 value is its tooltip, and the help's technical paragraph says
     * where that value still lives and who reads it.
     */
    public function testQl10TheStatusColumnIsThePhaseAndTheHelpExplainsTheTechnicalValue(): void
    {
        self::assertStringContainsString(
            "portal_sort_header('vms.php', 'status', __t('vms.th_status')",
            self::source('portal/vms.php')
        );
        [$deHeader, $enHeader] = self::texts('vms', 'th_status');
        self::assertSame('Phase', $deHeader);
        self::assertSame('Phase', $enHeader);
        self::assertStringContainsString("__t('status.phase_technical'", self::source('lib/vm_phase_cells.php'));

        self::assertStringContainsString("__t('help_missions.status_technical_p1')", self::source('lib/help/missions.php'));
        [$deHelp, $enHelp] = self::texts('help_missions', 'status_technical_p1');
        foreach ([$deHelp, $enHelp] as $help) {
            self::assertStringContainsString('MECM', $help);
            self::assertStringContainsString('x/5', $help);
        }
    }

    public function testQl11TheDeleteWordingSaysOnlyThePortalRecordGoes(): void
    {
        [$deConfirm, $enConfirm] = self::texts('vms', 'confirm_delete');
        [$deFlash, $enFlash] = self::texts('vms', 'flash_deleted');
        foreach ([$deConfirm, $enConfirm, $deFlash, $enFlash] as $text) {
            self::assertTrue(
                stripos($text, 'Portal-Datensatz') !== false || stripos($text, 'portal record') !== false,
                'a single-VM delete must say that only the portal record goes: ' . $text
            );
        }
        self::assertStringContainsString(':name', $deConfirm);
        self::assertStringContainsString(':name', $enConfirm);
        self::assertStringContainsString('Hypervisor', $deFlash);
        self::assertStringContainsString('hypervisor', $enFlash);

        [$deVlan] = self::texts('vlans', 'confirm_delete');
        self::assertStringContainsString('löschen?', $deVlan, 'the VLAN question uses the verb of its button (common.delete)');
        self::assertStringNotContainsString('entfernen', $deVlan);
    }

    public function testQl14TheVmNameMessageDescribesTheAsciiRule(): void
    {
        [$de, $en] = self::texts('validate', 'vm_name_charset');
        self::assertStringContainsString('ASCII', $de);
        self::assertStringContainsString('A bis Z', $de);
        self::assertStringContainsString('ASCII', $en);
        self::assertStringContainsString('A to Z', $en);
        self::assertStringContainsString('ASCII letters (A to Z)', self::source('lib/repo/vms_validation.php'));
    }

    public function testQl16TheEmptyListLinksToTheCreateFormForAWriter(): void
    {
        $missions = self::source('portal/missions.php');
        self::assertStringContainsString('missions_empty_list_html($attentionOnly, $isTemplateView, can(\'missions.write\', $user))', $missions);
        self::assertStringContainsString('id="mission-create"', $missions);
        self::assertStringContainsString('#mission-create', self::source('lib/missions_page.php'));
        self::texts('missions', 'empty_mission');
        self::texts('missions', 'empty_template');
        self::texts('missions', 'empty_create_link');
    }

    public function testQl07TheDirectoryFormRestoresCaAndControllerButNeverAPassword(): void
    {
        $panels = self::source('lib/users_directory_panels.php');
        $caLine = '';
        foreach (preg_split('/\R/', $panels) ?: [] as $line) {
            if (str_contains($line, 'name="ca_certificate_pem"')) {
                $caLine = $line;
            }
        }
        self::assertStringContainsString("form_old('directory', 'ca_certificate_pem')", $caLine, 'the pasted CA certificate must survive a validation error');
        self::assertStringContainsString("form_old('directory', 'controller_id')", $panels);
        self::assertStringContainsString("? ' selected' : ''", $panels);
        self::assertStringNotContainsString("form_old('directory', 'bind_password'", $panels, 'a password field is never re-rendered');
    }

    public function testQl04AnEndedSessionIsSaidOnTheSignInPage(): void
    {
        $auth = self::source('lib/auth.php');
        $start = strpos($auth, 'function require_login(');
        self::assertIsInt($start);
        $end = strpos($auth, "\n}\n", $start);
        self::assertIsInt($end);
        $body = substr($auth, $start, $end - $start);

        $read = strpos($body, "\$wasSignedIn = (int) (\$_SESSION['user_id'] ?? 0) > 0");
        $check = strpos($body, 'current_user($db)');
        self::assertIsInt($read);
        self::assertIsInt($check);
        self::assertLessThan($check, $read, 'the session must be read before current_user() can log it out');
        self::assertStringContainsString("'?reason=session_expired'", $body);

        $login = self::source('portal/login.php');
        self::assertStringContainsString("request_string(\$_GET, 'reason') === 'session_expired'", $login);
        self::assertStringContainsString("alert alert-warning\"><?php echo h(__t('login.session_expired'))", $login);
        self::texts('login', 'session_expired');
    }

    public function testSd03bTheBadgeHelpersReturnTheBareVariant(): void
    {
        foreach (['lib/backup_status.php', 'lib/repo/log.php', 'lib/settings_view_model.php'] as $helper) {
            self::assertStringNotContainsString("'badge-", self::source($helper), $helper . ' returns a class; portal_badge() takes the bare variant');
        }
        foreach (['lib/backup_status.php', 'lib/settings/system_panel.php', 'lib/settings/deploy_panel.php', 'lib/repo/log.php', 'portal/logs.php'] as $caller) {
            self::assertStringNotContainsString("preg_replace('/^badge-/'", self::source($caller), $caller . ' strips a prefix the helper no longer adds');
        }
    }
}
