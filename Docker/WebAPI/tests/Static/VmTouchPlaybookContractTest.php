<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';

/** The first ESXi read is durably attributed to exactly one portal VM. */
final class VmTouchPlaybookContractTest extends TestCase
{
    public function testExportTouchPrecedesEachVmInfoAndPreservesCallbackRows(): void
    {
        $playbook = (string) file_get_contents(ansible_source_dir() . '/exportVMs-Informations-ESXi_playbook.yml');
        $include = (string) file_get_contents(ansible_source_dir() . '/export_vm_info.yml');

        self::assertStringContainsString('include_tasks: ./export_vm_info.yml', $playbook);
        self::assertStringContainsString('loop: "{{ vm_configurations }}"', $playbook);
        self::assertStringContainsString('loop_var: export_vm_config', $playbook);
        self::assertStringContainsString('vm_info_identity_results: []', $playbook);
        self::assertStringContainsString('vm_info_identity_results | to_nice_json', $playbook);

        $touchPos = strpos($include, './report_vm_touch.py');
        $queryPos = strpos($include, 'community.vmware.vmware_guest_info:');
        self::assertIsInt($touchPos);
        self::assertIsInt($queryPos);
        self::assertLessThan($queryPos, $touchPos, 'the touch for one VM must finish before its first ESXi read');
        self::assertStringContainsString("export_vm_config['portal_vm_id']", $include);
        self::assertStringContainsString("job_execution['attempt']", $include);
        self::assertStringContainsString("job_execution['generation']", $include);
        self::assertStringContainsString('- export', $include);
        self::assertStringContainsString('register: vm_info_current', $include);
        self::assertStringContainsString('ignore_errors: true', $include);
        self::assertStringContainsString("combine({'item': export_vm_config})", $include);
        self::assertStringContainsString("'failed': true", $include);
        self::assertStringContainsString("'msg': 'VirtuSphere VM identity mismatch'", $include);
        self::assertStringContainsString("['failed']", $include);
    }

    public function testBothNewIncludesAndTheTouchClientAreShipped(): void
    {
        $uploaded = ansible_required_files();
        foreach ([
            VIRTUSPHERE_REPORT_VM_TOUCH_SCRIPT,
            VIRTUSPHERE_POWERCYCLE_VM_INFO_TASKS,
            VIRTUSPHERE_EXPORT_VM_INFO_TASKS,
        ] as $required) {
            self::assertContains($required, $uploaded);
            self::assertFileExists(ansible_source_dir() . DIRECTORY_SEPARATOR . $required);
        }
    }
}
