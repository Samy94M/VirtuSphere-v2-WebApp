<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * What the package catalog sync may touch, pinned as a scope rather than left as
 * an accident.
 *
 * The question this answers is "can a package re-import re-queue a VM for MECM,
 * or move it back in its lifecycle". The answer today is no, and the reason is
 * that mecm_packages.php references neither `deploy_vms`, nor `updated`, nor
 * `mecm_sync_state` anywhere. That is a real guarantee an operator relies on: a
 * catalog sync runs every minute, and a VM that starts installing again because a
 * package name changed would be a production incident.
 *
 * But nothing pinned it. The guarantee held by construction, and construction is
 * exactly what changes when somebody adds "and while we're here, mark affected
 * VMs for re-sync". So it is a contract now.
 *
 * The catalog tables and the assignment table are in scope on purpose: the sync
 * owns those. deploy_logs is in scope because it audits.
 */
final class PackageSyncScopeContractTest extends TestCase
{
    /**
     * Identifiers whose appearance in the sync would mean it has started to touch
     * VM state, with what each one would mean if it did.
     */
    private const FORBIDDEN = [
        'deploy_vms' => 'a catalog sync must not read or write VM rows; a package name is not a reason to touch a VM',
        'mecm_sync_state' => 'this would re-queue a VM for device-sync from a catalog event',
        'lifecycle_state' => 'this would move a VM backwards or forwards in its lifecycle from a catalog event',
        'updated' => 'the legacy re-queue flag; setting it here would push a VM back into the MECM queue',
    ];

    private function source(): string
    {
        $path = dirname(__DIR__, 2) . '/mecm_packages.php';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testTheSyncNeverTouchesVmState(): void
    {
        $source = $this->source();
        // Comments are stripped first: this file explains what it does NOT do,
        // and a prose mention must not read as a reference.
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

        foreach (self::FORBIDDEN as $needle => $why) {
            self::assertStringNotContainsString(
                $needle,
                $code,
                sprintf('mecm_packages.php references "%s": %s', $needle, $why)
            );
        }
    }

    /** Zero-match guard: the file must still be the sync we think it is. */
    public function testTheScanLooksAtTheRealSync(): void
    {
        $source = $this->source();

        self::assertStringContainsString('deploy_packages', $source);
        self::assertStringContainsString('deploy_vm_packages', $source, 'the sync does own the assignment table');
        self::assertStringContainsString('function packages_relink_upgrades', $source);
    }

    /**
     * The relink's two conditions, pinned at the source. Both are load-bearing
     * and neither is visible from the outside on a payload that happens not to
     * hit them.
     */
    public function testTheRelinkKeepsItsTwoConditions(): void
    {
        $source = $this->source();

        self::assertStringContainsString(
            'version_compare(',
            $source,
            'the successor must be chosen by version; ORDER BY id DESC picked the last row written, not the higher version'
        );
        self::assertStringNotContainsString(
            'ORDER BY id DESC LIMIT 1',
            $source,
            'the row-id successor choice must stay gone'
        );
        self::assertStringContainsString(
            '$newPackageIds',
            $source,
            'the relink must be bounded to successors this payload created, or a transient gap rewrites assignments'
        );
        self::assertStringContainsString(
            'assignments_relinked_at',
            $source,
            'a relink has to record that it removed the reference the purge protection reads'
        );
    }
}
