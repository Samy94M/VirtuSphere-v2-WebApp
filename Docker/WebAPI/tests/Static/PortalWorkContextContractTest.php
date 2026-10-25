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
        self::assertCount(11, $keys[0]);
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

    public function testTheVmOverviewHandsItsViewToTheEditorAndTheEditorReturnsThere(): void
    {
        $root = dirname(__DIR__, 2);
        $overviewPage = file_get_contents($root . '/lib/vm_overview_page.php');
        $edit = file_get_contents($root . '/lib/vm_edit_page.php');
        $panels = file_get_contents($root . '/lib/vm_edit_panels.php');
        $urls = file_get_contents($root . '/lib/deploy_urls.php');
        foreach ([$overviewPage, $edit, $panels, $urls] as $source) {
            self::assertIsString($source);
        }

        self::assertStringContainsString('id="vm-', $overviewPage, 'overview rows own a stable fragment');
        self::assertStringContainsString('vm_overview_editor_context(', $overviewPage);
        self::assertStringContainsString('mission_details_url_from_overview(', $overviewPage, 'the mission link of a row hands its view to the details back button');
        self::assertStringContainsString('vm_overview_mission_context(', $overviewPage);
        self::assertStringContainsString('vm_overview_return_url($workContext, $savedVmId)', $edit, 'a save returns to the overview');
        self::assertStringContainsString('portal_work_context_vm_editor_action_url(', $panels, 'round trips through vms.php keep the origin');
        self::assertStringNotContainsString('portal_work_context_vm_list_url($missionId, $workContext)); ?>">', $panels);
        self::assertStringContainsString('portal_work_context_mission_scope($workContext)', $urls, 'mission details drop the overview origin');
    }

    public function testTheMissionDetailsKeepTheOverviewOriginOnlyForTheBackButton(): void
    {
        $root = dirname(__DIR__, 2);
        $details = file_get_contents($root . '/portal/mission_details.php');
        $helper = file_get_contents($root . '/lib/portal_work_context.php');
        self::assertIsString($details);
        self::assertIsString($helper);

        $readOrigin = strpos($details, 'vm_overview_details_return_url($workContext)');
        $scopeOrigin = strpos($details, '$workContext = portal_work_context_mission_scope($workContext);');
        $firstNav = strpos($details, 'mission_detail_nav(');
        self::assertIsInt($readOrigin, 'the details read the overview origin for their back button');
        self::assertIsInt($scopeOrigin, 'the details then drop the origin for every other destination');
        self::assertIsInt($firstNav, 'the details render their own navigation');
        self::assertLessThan($scopeOrigin, $readOrigin, 'the origin is read before it is dropped');
        self::assertLessThan($firstNav, $scopeOrigin, 'every mission-scoped link is built after the origin is dropped');

        preg_match("/const VIRTUSPHERE_WORK_CONTEXT_OVERVIEW_KEYS = \[(.*?)\];/s", $helper, $match);
        self::assertArrayHasKey(1, $match);
        self::assertStringContainsString("'work_ov_vm'", $match[1], 'the row VM travels with the overview origin');
    }
}
