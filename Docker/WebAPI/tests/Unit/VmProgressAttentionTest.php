<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/vm_progress.php';

/**
 * A long-running state is only an operator warning. It never mutates the VM,
 * and os_installing has no clock at all until the operator explicitly starts
 * observing the PXE run: registration is not proof that PXE was triggered.
 */
final class VmProgressAttentionTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testPendingWarnsOnlyAfterItsDedicatedClockCrossesTheBoundary(): void
    {
        $atBoundary = gmdate('Y-m-d H:i:s', self::NOW - VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS);
        $overBoundary = gmdate('Y-m-d H:i:s', self::NOW - VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS - 1);

        self::assertNull(virtusphere_vm_progress_attention($this->vm([
            'mecm_pending_since' => $atBoundary,
        ]), self::NOW));

        $attention = virtusphere_vm_progress_attention($this->vm([
            'mecm_pending_since' => $overBoundary,
        ]), self::NOW);
        self::assertSame(VIRTUSPHERE_VM_PROGRESS_MECM_PENDING, $attention['kind']);
        self::assertSame(VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS + 1, $attention['age_seconds']);
        self::assertSame(VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS, $attention['threshold_seconds']);
    }

    public function testPendingNeverFallsBackToGenericUpdatedAt(): void
    {
        self::assertNull(virtusphere_vm_progress_attention($this->vm([
            'mecm_pending_since' => null,
            'updated_at' => '2000-01-01 00:00:00',
        ]), self::NOW));
    }

    public function testInstallingNeedsAnExplicitObservationClock(): void
    {
        $installing = $this->vm([
            'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLING,
            'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_REGISTERED,
            'mecm_pending_since' => null,
            'os_install_watch_started_at' => null,
        ]);

        self::assertSame(VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING, virtusphere_vm_progress_watch_kind($installing));
        self::assertNull(virtusphere_vm_progress_attention($installing, self::NOW));

        $installing['os_install_watch_started_at'] = gmdate(
            'Y-m-d H:i:s',
            self::NOW - VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS - 1
        );
        $attention = virtusphere_vm_progress_attention($installing, self::NOW);
        self::assertSame(VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING, $attention['kind']);
        self::assertSame(VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS + 1, $attention['age_seconds']);
    }

    public function testOnlyTheTwoExpectedStateCombinationsCanRestartAWatch(): void
    {
        self::assertSame(VIRTUSPHERE_VM_PROGRESS_MECM_PENDING, virtusphere_vm_progress_watch_kind($this->vm()));
        self::assertNull(virtusphere_vm_progress_watch_kind($this->vm([
            'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_DEPLOYING,
        ])));
        self::assertNull(virtusphere_vm_progress_watch_kind($this->vm([
            'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLED,
            'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_REGISTERED,
        ])));
    }

    public function testMalformedOrFutureServerTimestampsNeverCreateAFalseWarning(): void
    {
        self::assertNull(virtusphere_vm_progress_attention($this->vm([
            'mecm_pending_since' => 'not-a-timestamp',
        ]), self::NOW));
        self::assertNull(virtusphere_vm_progress_attention($this->vm([
            'mecm_pending_since' => gmdate('Y-m-d H:i:s', self::NOW + 60),
        ]), self::NOW));
    }

    /** @return array<string,mixed> */
    private function vm(array $overrides = []): array
    {
        return $overrides + [
            'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_DEPLOYED,
            'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_PENDING,
            'mecm_pending_since' => gmdate('Y-m-d H:i:s', self::NOW),
            'os_install_watch_started_at' => null,
            'updated_at' => gmdate('Y-m-d H:i:s', self::NOW),
        ];
    }
}
