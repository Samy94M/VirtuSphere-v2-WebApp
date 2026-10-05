<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_blockers.php';

final class DeployQueueBlockersTest extends TestCase
{
    public function testPrerequisitesAreDiscriminatedAndComplete(): void
    {
        $blockers = deploy_queue_base_blockers(false, false, false, false, 'API missing', false, null, [], []);
        self::assertCount(4, $blockers);
        self::assertSame(['missions', 'esxi_credential', 'ansible_credential', 'api_base_url'], array_column($blockers, 'code'));
        self::assertSame([VIRTUSPHERE_DEPLOY_BLOCKER_PREREQUISITE], array_values(array_unique(array_column($blockers, 'kind'))));
    }

    public function testMissionSelectionAndEmptyMissionAreVisibleBlockers(): void
    {
        $selection = deploy_queue_base_blockers(true, true, true, true, '', false, null, [], []);
        self::assertSame('mission_selection', $selection[0]['code']);

        $empty = deploy_queue_base_blockers(true, true, true, true, '', true, ['id' => 7], [], []);
        self::assertSame(VIRTUSPHERE_DEPLOY_BLOCKER_EMPTY_MISSION, $empty[0]['kind']);
        self::assertSame('vms.php?mission_id=7', $empty[0]['action']['url']);
    }

    /**
     * WM-E2a: "adopt identity" is gone. Whether or not the inventory knows
     * MOID and UUID, the block names the three ways out and links the portal
     * VM plus an inventory refresh (after a rename on ESXi the cache has to
     * see it). No variant posts anything.
     */
    public function testIdentityConflictOffersLinksAndNoAdoption(): void
    {
        foreach ([['vm-9', 'uuid-9'], ['', '']] as [$moid, $uuid]) {
            $conflict = [
                'vm_id' => 9,
                'vm_name' => 'VM09',
                'inventory_moid' => $moid,
                'inventory_instance_uuid' => $uuid,
            ];
            $blockers = deploy_queue_base_blockers(true, true, true, true, '', true, ['id' => 7], [['id' => 9]], [$conflict]);
            self::assertCount(1, $blockers);
            self::assertSame(VIRTUSPHERE_DEPLOY_BLOCKER_IDENTITY_CONFLICT, $blockers[0]['kind']);
            self::assertSame($conflict, $blockers[0]['conflict']);
            // open_remedy finds the action again by code; a shared code would
            // send every conflict's link to the first VM.
            self::assertSame('identity_conflict_9', $blockers[0]['code']);
            self::assertSame(__t('deploy.identity_conflict', ['name' => 'VM09']), $blockers[0]['message']);
            self::assertSame([
                'type' => 'link',
                'url' => vm_edit_url(7, 9),
                'label' => __t('deploy.identity_vm_link'),
                'permission' => 'vms.write',
            ], $blockers[0]['action']);
            self::assertSame([
                'url' => system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI),
                'label' => __t('deploy.identity_refresh_link'),
            ], $blockers[0]['help']);
        }
    }

    public function testIdentityConflictRendersBothLinksAndNoAdoptionForm(): void
    {
        $conflict = ['vm_id' => 9, 'vm_name' => 'VM09', 'inventory_moid' => 'vm-9', 'inventory_instance_uuid' => 'uuid-9'];
        $blockers = deploy_queue_base_blockers(true, true, true, true, '', true, ['id' => 7], [['id' => 9]], [$conflict]);
        // The remedy form carries a CSRF field, which needs a session.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_start();
        }
        ob_start();
        try {
            deploy_render_blockers($blockers, ['role' => VIRTUSPHERE_ROLE_USER]);
        } finally {
            $html = (string) ob_get_clean();
        }

        self::assertStringNotContainsString('adopt_vm', $html);
        self::assertStringNotContainsString('data-confirm', $html);
        // The VM link is a draft-keeping remedy form keyed by its own code; the
        // refresh link is a plain follow-up in the same actions row.
        self::assertStringContainsString('name="remedy_code" value="identity_conflict_9"', $html);
        self::assertStringContainsString(h(__t('deploy.identity_vm_link')), $html);
        self::assertStringContainsString('href="' . h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI)) . '"', $html);
    }

    public function testRendererRejectsAnUnknownVariant(): void
    {
        $this->expectException(LogicException::class);
        ob_start();
        try {
            deploy_render_blockers([[
                'kind' => 'future_kind', 'code' => 'future', 'message' => 'x',
            ]], ['role' => VIRTUSPHERE_ROLE_ADMIN]);
        } finally {
            ob_end_clean();
        }
    }

    public function testPresentationUsesOnlyTheCompleteDecisionAndMaterializedScope(): void
    {
        $state = deploy_queue_normalize_input(['mode' => 'create']);
        $ready = deploy_blocker_presentation($state, [], 4);
        self::assertSame('ready', $ready['state']);
        self::assertSame(__t('deploy.preparation_ready'), $ready['status']);
        self::assertStringContainsString('4', $ready['context']);

        $blocked = deploy_blocker_presentation($state, [
            ['kind' => VIRTUSPHERE_DEPLOY_BLOCKER_PREREQUISITE, 'code' => 'a', 'message' => 'a'],
            ['kind' => VIRTUSPHERE_DEPLOY_BLOCKER_PREREQUISITE, 'code' => 'b', 'message' => 'b'],
        ], null);
        self::assertSame('blocked', $blocked['state']);
        self::assertSame(__t('deploy.preparation_blocked_many', ['count' => 2]), $blocked['status']);
        self::assertSame(__t('deploy.preparation_context_unknown', ['mode' => deploy_mode_label('create')]), $blocked['context']);
    }
}
