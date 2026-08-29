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

    public function testIdentityConflictIsItsOwnExhaustiveVariant(): void
    {
        $conflict = [
            'vm_id' => 9,
            'vm_name' => 'VM09',
            'inventory_moid' => 'vm-9',
            'inventory_instance_uuid' => 'uuid-9',
        ];
        $blockers = deploy_queue_base_blockers(true, true, true, true, '', true, ['id' => 7], [['id' => 9]], [$conflict], 12);
        self::assertCount(1, $blockers);
        self::assertSame(VIRTUSPHERE_DEPLOY_BLOCKER_IDENTITY_CONFLICT, $blockers[0]['kind']);
        self::assertSame($conflict, $blockers[0]['conflict']);
        self::assertSame('adopt', $blockers[0]['action']['type']);
        self::assertSame('vms.write', $blockers[0]['action']['permission']);
        self::assertSame(12, $blockers[0]['action']['fields']['credential_esxi_id']);
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
}
