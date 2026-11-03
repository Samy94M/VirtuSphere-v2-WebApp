<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_terminal_presenter.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RegisteredVmExportPresenterTest extends TestCase
{
    public function testUnchangedV2SuccessIsReadableInBothLocalesAndRoundTrips(): void
    {
        $plan = mac_import_finalize_plan(
            [1 => ['id' => 1, 'vm_name' => 'BOUND', 'mecm_id' => '4711']],
            [1 => [
                'vm' => ['id' => 1, 'mecm_id' => '4711'],
                'updates' => [['id' => 11, 'mac' => '02:11:22:33:44:55']],
                'errors' => [],
                'wds' => ['configured_portgroup' => 'WDS', 'portal_interface_id' => 11, 'verified' => true],
            ]],
            [],
            []
        );
        $result = mac_import_result_contract($plan, str_repeat('a', 64));
        $decoded = mac_import_decode_result(json_encode($result, JSON_THROW_ON_ERROR));
        self::assertNotNull($decoded);
        self::assertSame(0, $decoded['counts']['updated_interfaces']);
        self::assertSame([1], $decoded['successful_vm_ids']);
        foreach (['de' => 'unverändert', 'en' => 'unchanged'] as $locale => $label) {
            Lang::load($locale);
            $rows = mac_import_present_vm_rows($decoded, ['id' => 1, 'mission_id' => 2]);
            self::assertStringContainsString($label, deploy_terminal_vm_results_html($rows));
        }
        $result['vm_results'][0]['wds']['verified'] = false;
        self::assertNull(mac_import_decode_result(json_encode($result, JSON_THROW_ON_ERROR)), 'zero writes do not excuse missing WDS proof');
    }

    public function testBoundFirstMacCountsOnlyWrittenCardsAndShowsTheMarkedNotice(): void
    {
        $plan = mac_import_finalize_plan(
            [1 => ['id' => 1, 'vm_name' => 'BOUND', 'mecm_id' => '4711']],
            [1 => [
                'vm' => ['id' => 1, 'mecm_id' => '4711'],
                'updates' => [
                    11 => ['id' => 11, 'mac' => '02:11:22:33:44:55', 'first_mac' => true],
                    12 => ['id' => 12, 'mac' => '02:11:22:33:44:66', 'first_mac' => false],
                ],
                'errors' => [],
                'wds' => ['configured_portgroup' => 'WDS', 'portal_interface_id' => 11, 'verified' => true],
            ]],
            [],
            []
        );
        self::assertSame([['id' => 11, 'mac' => '02:11:22:33:44:55', 'first_mac' => true]], mac_import_plan_writes($plan['vm_plans'][1]));
        $decoded = mac_import_decode_result(json_encode(mac_import_result_contract($plan, str_repeat('b', 64)), JSON_THROW_ON_ERROR));
        self::assertNotNull($decoded);
        self::assertSame(1, $decoded['vm_results'][0]['updated_interfaces']);
        self::assertSame(1, $decoded['counts']['updated_interfaces']);

        $line = mac_import_bound_first_mac_log_line(1);
        self::assertSame(1, mac_import_bound_first_mac_log_vm_id($line));
        self::assertNull(mac_import_bound_first_mac_log_vm_id('x ' . $line));
        self::assertNull(mac_import_bound_first_mac_log_vm_id(VIRTUSPHERE_MAC_IMPORT_BOUND_FIRST_MAC_LOG_PREFIX . '0; x'));

        foreach (['de' => ['MAC erstmals übernommen', 'erfolgreich'], 'en' => ['MAC imported for the first time', 'successful']] as $locale => [$notice, $label]) {
            Lang::load($locale);
            $marked = deploy_terminal_vm_results_html(mac_import_present_vm_rows($decoded, ['id' => 1, 'mission_id' => 2, 'mac_bound_first_vm_ids' => [1]]));
            self::assertStringContainsString($notice, $marked);
            self::assertStringContainsString($label, $marked);
            self::assertStringNotContainsString('MECM kennt noch', $marked);
            self::assertStringNotContainsString('still knows', $marked);
            $plain = deploy_terminal_vm_results_html(mac_import_present_vm_rows($decoded, ['id' => 1, 'mission_id' => 2]));
            self::assertStringNotContainsString($notice, $plain, 'without the job-log marker the row is an ordinary success');
        }
    }

    public function testMacChangeInstructionLinksOnlyToAnExistingWritableVm(): void
    {
        // Isolated RBAC boundary: prove the presenter requests the target's
        // permission, without a database/session in this unit test.
        require __DIR__ . '/../fixtures/registered-vm-export-rbac.php';
        $error = ['code' => 'bound_mac_changed', 'vm_id' => 3];
        $job = ['id' => 1, 'mission_id' => 2];
        foreach (['de' => 'MECM-ID zurücksetzen', 'en' => 'reset the MECM ID'] as $locale => $instruction) {
            Lang::load($locale);
            $GLOBALS['k3_can_write'] = true;
            $shown = mac_import_present_error($error, $job);
            self::assertStringContainsString($instruction, $shown['message']);
            self::assertSame(vm_edit_url(2, 3), $shown['action_url']);
            self::assertNotSame('', $shown['action_label']);
            self::assertSame('', mac_import_present_error($error, $job, false)['action_url']);
            $GLOBALS['k3_can_write'] = false;
            $hidden = mac_import_present_error($error, $job);
            self::assertSame('', $hidden['action_url']);
            self::assertStringContainsString($instruction, $hidden['message']);
        }
    }
}
