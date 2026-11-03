<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/deploy_storage.php';

/**
 * The decisions behind the deploy storage table: which VMs a job would touch, and
 * how a requirement compares against the free space of the chosen ESXi credential.
 * The schedule preview renders these server-side, so they hold without JavaScript.
 *
 * ADR-0023: this display is warn-only. Every unusable number must degrade to
 * `unknown`, never to a refusal, because the cache may be stale, partial or absent.
 */
final class DeployStorageVerdictTest extends TestCase
{
    private const GB = 1024 * 1024 * 1024;

    // --- deploy_selected_vms: mirrors repo_deploy_group_vm_list ---

    public function testNoSelectionMeansTheWholeMission(): void
    {
        $vms = [['id' => 1], ['id' => 2], ['id' => 3]];

        self::assertSame($vms, deploy_selected_vms($vms, []));
    }

    public function testOnlyTheCheckedVmsAreCounted(): void
    {
        $vms = [['id' => 1], ['id' => 2], ['id' => 3]];

        self::assertSame([['id' => 1], ['id' => 3]], deploy_selected_vms($vms, ['1', '3']));
    }

    public function testAnIdOutsideTheMissionSelectsNothingFromIt(): void
    {
        // The enqueue path filters foreign ids too; the estimate must not invent a
        // VM that the job would never touch.
        self::assertSame([], deploy_selected_vms([['id' => 1]], [99]));
    }

    // --- deploy_storage_state ---

    public function testFreeSpaceAtLeastTheRequirementIsSufficient(): void
    {
        self::assertSame('ok', deploy_storage_state(100 * self::GB, 200 * self::GB));
        self::assertSame('ok', deploy_storage_state(100 * self::GB, 100 * self::GB), 'exactly enough still fits');
    }

    public function testLessFreeSpaceThanRequiredIsFlagged(): void
    {
        self::assertSame('insufficient', deploy_storage_state(680 * self::GB, 300 * self::GB));
    }

    public function testAnAbsentFreeValueNeverBecomesARefusal(): void
    {
        // No credential chosen, datastore absent from that host, or a NULL column:
        // all prove nothing, so the verdict stays open instead of blocking.
        self::assertSame('unknown', deploy_storage_state(680 * self::GB, null));
        self::assertSame('unknown', deploy_storage_state(0, null));
    }

    // --- deploy_storage_projected_pct: usage after this deploy ---

    public function testProjectedUsageCountsTheRequirementOnTopOfWhatIsUsed(): void
    {
        // 1024 GB datastore, 400 GB free (624 in use), 200 GB requested: the bar
        // has to show 824/1024, not the 200/1024 the deploy adds.
        $pct = deploy_storage_projected_pct(200 * self::GB, 400 * self::GB, 1024 * self::GB);

        self::assertSame(80, $pct);
    }

    public function testProjectedUsageIsClampedAtAHundredPercent(): void
    {
        // An over-commit must not paint a bar wider than its track.
        self::assertSame(100, deploy_storage_projected_pct(5000 * self::GB, 100 * self::GB, 1024 * self::GB));
    }

    public function testProjectedUsageIsNullWhenTheSizeIsUnknown(): void
    {
        self::assertNull(deploy_storage_projected_pct(10, null, 100));
        self::assertNull(deploy_storage_projected_pct(10, 50, null));
        self::assertNull(deploy_storage_projected_pct(10, 50, 0));
    }
}
