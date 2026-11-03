<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * What the package catalog sync may touch, pinned as a scope rather than left as
 * an accident.
 *
 * The question this answers is "can a package re-import re-queue a VM for MECM,
 * or move it back in its lifecycle". The answer today is no, and the reason is
 * that mecm_packages.php changes only template VM package assignments (AV-R08)
 * and the owning VM's configuration version. It never changes rollout or lifecycle state. That is a
 * real guarantee an operator relies on: a catalog sync runs every minute, and a
 * VM that starts installing again because a package name changed would be a
 * production incident.
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

    public function testTheSyncNeverTouchesRuntimeVmState(): void
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

    public function testTheOnlyVmParentAccessIsOrderedLockAndConfigurationVersionTouch(): void
    {
        $source = $this->source();
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);
        $scopeHelper = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/repo/package_sync.php');

        self::assertSame(1, substr_count($scopeHelper, 'FOR UPDATE'));
        self::assertMatchesRegularExpression(
            "/FROM deploy_vms v INNER JOIN deploy_missions m ON m\\.id = v\\.mission_id\\s+WHERE v\\.id IN \\(' \\. \\\$placeholders \\. '\\) ORDER BY v\\.id FOR UPDATE/",
            $scopeHelper,
            'the exact affected parents must be locked deterministically before assignment writes'
        );
        self::assertStringNotContainsString('UPDATE deploy_vms', $scopeHelper);
        self::assertSame(1, substr_count($code, 'repo_advance_vm_edit_version('));
        self::assertStringNotContainsString('UPDATE deploy_vms', $code, 'the endpoint may not add another VM mutation');

        $versionHelper = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/repo/edit_version.php');
        self::assertSame(1, substr_count($versionHelper, 'UPDATE deploy_vms SET'));
        self::assertStringContainsString(
            "UPDATE deploy_vms SET ' . VIRTUSPHERE_EDIT_VERSION_INCREMENT_SQL . ', updated_at = NOW() WHERE id = ?",
            $versionHelper,
            'the allowed helper must change only the configuration version and its display timestamp for one VM id'
        );
        foreach (['mecm_sync_state', 'lifecycle_state', 'updated ='] as $forbiddenState) {
            self::assertStringNotContainsString($forbiddenState, $versionHelper);
        }
    }

    public function testVmParentsAreLockedBeforeAnyPackageCatalogWrite(): void
    {
        $source = $this->source();
        $scopeLock = strpos($source, '$templateFollowScope = packages_lock_template_follow_scope(');
        $catalogWrite = strpos($source, "catalog_retire_missing(\$connection, 'deploy_packages'");
        self::assertIsInt($scopeLock);
        self::assertIsInt($catalogWrite);
        self::assertLessThan($catalogWrite, $scopeLock, 'VM -> package order must match the editor and avoid a deadlock cycle');

        [$followStart, $followEnd] = $this->followFunctionBounds($source);
        self::assertStringNotContainsString('FOR UPDATE', substr($source, $followStart, $followEnd - $followStart), 'the template rule must use only the prelocked parent scope');
    }

    /** Zero-match guard: the file must still be the sync we think it is. */
    public function testTheScanLooksAtTheRealSync(): void
    {
        $source = $this->source();

        self::assertStringContainsString('deploy_packages', $source);
        self::assertStringContainsString('deploy_vm_packages', $source, 'the sync does own the assignment table');
        self::assertStringContainsString('function packages_follow_first_versions_in_templates', $source);
    }

    /**
     * AV-R08 (15.10.2026): the sync never moves a real VM. Every write into the
     * assignment table is derived from the source and must sit inside the
     * template rule, and the scope that feeds it filters through the one
     * template SSoT, before and after the lock.
     */
    public function testEveryAssignmentWriteIsTheTemplateRule(): void
    {
        $source = $this->source();
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);
        [$followStart, $followEnd] = $this->followFunctionBounds($code);

        preg_match_all('/(UPDATE(?:\s+IGNORE)?\s+deploy_vm_packages|DELETE\s+FROM\s+deploy_vm_packages|INSERT\s+INTO\s+deploy_vm_packages)/', $code, $matches, PREG_OFFSET_CAPTURE);
        self::assertNotSame([], $matches[0], 'zero match: the template rule writes assignments');
        foreach ($matches[0] as [$statement, $offset]) {
            self::assertTrue(
                $offset > $followStart && $offset < $followEnd,
                sprintf('"%s" outside packages_follow_first_versions_in_templates would move a real VM', $statement)
            );
        }
        self::assertStringNotContainsString('packages_relink_upgrades', $code, 'the real-VM relink is gone (AV-R08)');

        $scopeHelper = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/repo/package_sync.php');
        self::assertSame(2, substr_count($scopeHelper, 'mission_name_is_template('), 'template check before and after the lock');
    }

    /** @return array{0:int,1:int} */
    private function followFunctionBounds(string $source): array
    {
        $start = strpos($source, 'function packages_follow_first_versions_in_templates');
        $end = strpos($source, 'function packages_audit_retired_assignments', (int) $start);
        self::assertIsInt($start);
        self::assertIsInt($end);

        return [$start, $end];
    }

    /**
     * The template rule's conditions, pinned at the source. Each is load-bearing
     * and none is visible from the outside on a payload that happens not to hit
     * it.
     */
    public function testTheTemplateRuleKeepsItsConditions(): void
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
            'the template rule must be bounded to versions this payload created, or a transient gap rewrites assignments'
        );
        self::assertStringContainsString(
            'assignments_relinked_at',
            $source,
            'a template relink has to record that it removed the reference the purge protection reads'
        );
    }
}
