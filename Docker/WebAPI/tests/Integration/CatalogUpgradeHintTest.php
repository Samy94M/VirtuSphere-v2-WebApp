<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/catalog.php';

/**
 * B12: the update-available hint and the real version relink used to answer
 * "which successor" with two different rules. mecm_packages.php picks by
 * version_compare(); the hint picked by `ORDER BY new.id DESC` and first-wins,
 * i.e. by INSERT ORDER. A re-imported older build (higher row id, lower
 * version) therefore became the recommended upgrade, and the operator was told
 * to "update" onto a downgrade. Both sides now share one pure pick.
 */
final class CatalogUpgradeHintTest extends TestCase
{
    private const PREFIX = 'phpunit_hint_';

    private mysqli $db;
    private int $missionId = 0;
    private int $vmId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();

        $name = self::PREFIX . 'mission';
        $status = 'active';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();
        $this->missionId = (int) $this->db->insert_id;

        $vmName = strtoupper(self::PREFIX . 'VM');
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $this->missionId, $vmName, $vmName);
        $stmt->execute();
        $this->vmId = (int) $this->db->insert_id;
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    public function testTheHintRecommendsTheHighestVersionNotTheNewestRow(): void
    {
        $retiredId = $this->insertPackage('1.2.0', VIRTUSPHERE_CATALOG_STATUS_RETIRED);
        $this->linkPackage($retiredId);
        // The HIGHER version gets the LOWER row id on purpose: a re-import of
        // an older build afterwards gave it the newest id, and the old
        // `ORDER BY new.id DESC` rule then recommended the downgrade.
        $this->insertPackage('1.10.0', VIRTUSPHERE_CATALOG_STATUS_DEFAULT);
        $this->insertPackage('1.9.0', VIRTUSPHERE_CATALOG_STATUS_DEFAULT);

        $hints = repo_vm_package_upgrade_hints($this->db, $this->vmId);

        self::assertSame(
            self::PREFIX . 'pkg-1.10.0',
            $hints[self::PREFIX . 'pkg-1.2.0'] ?? null,
            'the hint must follow version_compare, exactly like the relink in mecm_packages.php'
        );
    }

    public function testThePurePickPrefersVersionOverInsertOrder(): void
    {
        $best = catalog_pick_highest_version([
            ['package_name' => 'a-1.9.0', 'package_version' => '1.9.0'],
            ['package_name' => 'a-1.10.0', 'package_version' => '1.10.0'],
            ['package_name' => 'a-1.2.3', 'package_version' => '1.2.3'],
        ]);

        self::assertNotNull($best);
        self::assertSame('1.10.0', (string) $best['package_version']);
        self::assertNull(catalog_pick_highest_version([]));
    }

    private function insertPackage(string $version, string $status): int
    {
        $name = self::PREFIX . 'pkg-' . $version;
        $basename = self::PREFIX . 'pkg';
        $stmt = $this->db->prepare('INSERT INTO deploy_packages (package_name, package_basename, package_version, package_status, retired_at) VALUES (?, ?, ?, ?, ' . ($status === VIRTUSPHERE_CATALOG_STATUS_RETIRED ? 'NOW()' : 'NULL') . ')');
        $stmt->bind_param('ssss', $name, $basename, $version, $status);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function linkPackage(int $packageId): void
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_vm_packages (vm_id, package_id) VALUES (?, ?)');
        $stmt->bind_param('ii', $this->vmId, $packageId);
        $stmt->execute();
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM deploy_packages WHERE package_name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }
}
