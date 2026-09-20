<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PortalWorkContextContractTest extends TestCase
{
    public function testContextIsBoundedAndDoesNotBecomeSessionOrFreeReturnState(): void
    {
        $helper = file_get_contents(dirname(__DIR__, 2) . '/lib/portal_work_context.php');
        self::assertIsString($helper);
        self::assertStringContainsString('VIRTUSPHERE_WORK_CONTEXT_KEYS', $helper);
        self::assertStringNotContainsString('$_SESSION', $helper);
        self::assertStringNotContainsString('return_to', $helper);

        preg_match("/const VIRTUSPHERE_WORK_CONTEXT_KEYS = \[(.*?)\];/s", $helper, $match);
        self::assertArrayHasKey(1, $match);
        preg_match_all("/'work_[a-z_]+' /x", $match[1], $keys);
        self::assertCount(6, $keys[0]);
    }

    public function testConsumersUseCanonicalUrlsAndStableRowTargets(): void
    {
        $root = dirname(__DIR__, 2);
        $missions = file_get_contents($root . '/portal/missions.php');
        $details = file_get_contents($root . '/portal/mission_details.php');
        $vms = file_get_contents($root . '/portal/vms.php');
        $edit = file_get_contents($root . '/lib/vm_edit_page.php');

        foreach ([$missions, $details, $vms, $edit] as $source) {
            self::assertIsString($source);
            self::assertStringContainsString('portal_work_context', $source);
        }
        self::assertStringContainsString('id="mission-', $missions);
        self::assertStringContainsString('mission_details_url(', $missions);
        self::assertStringContainsString('portal_work_context_mission_list_url(', $details);
        self::assertStringContainsString('id="vm-', $vms);
        self::assertStringContainsString('vm_edit_url(', $vms);
        self::assertStringContainsString("\$returnTo === 'editor'", $vms);
        self::assertStringContainsString('portal_work_context_vm_list_url(', $edit);
    }
}
