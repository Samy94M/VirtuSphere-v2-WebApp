<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';

/**
 * Two operators editing the same VM. repo_save_vm() takes an expected version
 * and, inside a FOR UPDATE transaction, rejects a save whose expectation no longer
 * matches the stored row: the second writer is told to reload rather than silently
 * clobbering the first writer's disks, interfaces or overrides. The portal renders
 * this hidden `edit_version` field and passes it back, so the guard is live, not
 * theoretical.
 *
 * These pin that behaviour against the real database: a stale expectation is
 * refused, a current one goes through, and passing no expectation is the explicit
 * opt-out (the import and legacy-API paths that have no form to carry a timestamp).
 * Runs in-stack against db() and cleans up its own rows by name prefix.
 */
final class VmEditConflictTest extends TestCase
{
    private const PREFIX = 'phpunit_vmconf_';

    private ?mysqli $db = null;
    private int $userId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }

        $user = repo_fetch_one($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        if ($user === null) {
            self::markTestSkipped('No user to act as.');
        }
        $this->userId = (int) $user['id'];

        $this->cleanupTestRows();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('SET timestamp = 0');
            $this->cleanupTestRows();
        }
    }

    public function testSameSecondStaleVmSavePreservesFirstWriterAndChildren(): void
    {
        $this->db->query('SET timestamp = 1788900000');
        [$missionId, $vmId] = $this->seedVm('cfl-seconds');
        $version = $this->editVersion($vmId);
        $stamp = repo_scalar($this->db, 'SELECT updated_at FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
        $this->saveVm($missionId, $vmId, 'cfl-seconds', ['vm_cpu' => '4'], $version);
        $before = repo_get_vm_bundle($this->db, $vmId);
        self::assertSame($stamp, $before['updated_at']);
        self::assertNotSame($version, $this->editVersion($vmId));
        try {
            $this->saveVm($missionId, $vmId, 'cfl-seconds', ['vm_notes' => 'stale writer'], $version);
            self::fail('Same-second stale VM writer must be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('changed by another user', $error->getMessage());
        }
        self::assertSame($before, repo_get_vm_bundle($this->db, $vmId));
        $this->saveVm($missionId, $vmId, 'cfl-seconds', ['vm_cpu' => '6'], $this->editVersion($vmId));
        self::assertSame('6', repo_get_vm_bundle($this->db, $vmId)['vm_cpu']);
    }

    public function testSameSecondMissionConflictAndExplicitPortalRequirement(): void
    {
        $this->db->query('SET timestamp = 1788900000');
        [$missionId] = $this->seedVm('cfl-mission');
        $mission = repo_get_mission($this->db, $missionId);
        repo_update_mission_checked($this->db, $missionId, ['mission_notes' => 'first'], (string) $mission['edit_version'], requireVersion: true);
        $before = repo_get_mission($this->db, $missionId);
        self::assertSame($mission['updated_at'], $before['updated_at']);
        foreach ([(string) $mission['edit_version'], '', '01', 'broken', (string) $mission['updated_at']] as $stale) {
            try {
                repo_update_mission_checked($this->db, $missionId, ['mission_notes' => 'lost'], $stale, requireVersion: true);
                self::fail('Stale, missing and malformed portal versions must fail closed.');
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
            self::assertSame($before, repo_get_mission($this->db, $missionId));
        }
        repo_update_mission_checked($this->db, $missionId, ['mission_notes' => 'legacy'], '');
        self::assertNotSame($before['edit_version'], repo_get_mission($this->db, $missionId)['edit_version']);
    }

    public function testVmPortalRequirementRejectsMissingAndMalformedVersions(): void
    {
        [$missionId, $vmId] = $this->seedVm('cfl-vm-required');
        $before = repo_get_vm_bundle($this->db, $vmId);
        self::assertIsArray($before);

        foreach (['', '01', 'broken', (string) $before['updated_at']] as $invalid) {
            try {
                $this->saveVm($missionId, $vmId, 'cfl-vm-required', ['vm_notes' => 'lost'], $invalid, true);
                self::fail('A missing or malformed portal VM version must fail closed.');
            } catch (RuntimeException $error) {
                self::assertSame('VM was changed by another user. Reload before saving.', $error->getMessage());
            }
            self::assertSame($before, repo_get_vm_bundle($this->db, $vmId));
        }
    }

    public function testLegacyChildOnlyWriteInvalidatesOpenVmEditor(): void
    {
        $this->db->query('SET timestamp = 1788900000');
        [$missionId, $vmId] = $this->seedVm('cfl-child');
        $version = $this->editVersion($vmId);
        self::assertSame(1, vmListToUpdate([['id' => $vmId, 'disks' => [['disk_name' => 'Legacy', 'disk_size' => 80, 'disk_type' => 'thin']]]], $this->db));
        $before = repo_get_vm_bundle($this->db, $vmId);
        self::assertNotSame($version, $this->editVersion($vmId));
        try {
            $this->saveVm($missionId, $vmId, 'cfl-child', [], $version);
            self::fail('A child-only legacy edit must invalidate the portal snapshot.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('changed by another user', $error->getMessage());
        }
        self::assertSame($before, repo_get_vm_bundle($this->db, $vmId));
    }

    public function testVersionRollsBackWithFailedBundle(): void
    {
        [$missionId, $vmId] = $this->seedVm('cfl-rollback');
        $before = repo_get_vm_bundle($this->db, $vmId);
        try {
            repo_transaction($this->db, function () use ($missionId, $vmId): void {
                $this->saveVm($missionId, $vmId, 'cfl-rollback', ['vm_cpu' => '4'], $this->editVersion($vmId));
                throw new RuntimeException('rollback fixture');
            });
        } catch (RuntimeException $error) {
            self::assertSame('rollback fixture', $error->getMessage());
        }
        self::assertSame($before, repo_get_vm_bundle($this->db, $vmId));
    }

    public function testAStaleExpectationIsRejected(): void
    {
        [$missionId, $vmId] = $this->seedVm('cfl-a');
        // Operator two holds a timestamp from before operator one's save. A
        // literal that predates the row is deterministically staler than any real
        // prior value, so the test does not depend on the 1-second resolution of
        // the TIMESTAMP column (a same-second save would otherwise not move it).
        $stale = '2000-01-01 00:00:00';

        try {
            $this->saveVm($missionId, $vmId, 'cfl-a', ['vm_cpu' => '8'], $stale);
            self::fail('the stale save should have been rejected');
        } catch (RuntimeException $exception) {
            self::assertSame('VM was changed by another user. Reload before saving.', $exception->getMessage());
        }

        // The rejection did not clobber the row: the seeded value survives.
        self::assertSame('2', (string) repo_scalar($this->db, 'SELECT vm_cpu FROM deploy_vms WHERE id = ?', 'i', [$vmId]));
    }

    public function testACurrentExpectationGoesThrough(): void
    {
        [$missionId, $vmId] = $this->seedVm('cfl-b');

        $this->saveVm($missionId, $vmId, 'cfl-b', ['vm_cpu' => '4'], $this->editVersion($vmId));
        // Reading the row back and saving again with the new stamp is what a
        // reload-then-edit does, and it must be accepted.
        $this->saveVm($missionId, $vmId, 'cfl-b', ['vm_cpu' => '6'], $this->editVersion($vmId));

        self::assertSame('6', (string) repo_scalar($this->db, 'SELECT vm_cpu FROM deploy_vms WHERE id = ?', 'i', [$vmId]));
    }

    public function testAnEmptyExpectationOptsOutOfTheGuard(): void
    {
        [$missionId, $vmId] = $this->seedVm('cfl-c');
        // No version at all is the escape hatch for callers with no form to
        // carry one (import, legacy API): last-write, on purpose, and pinned so a
        // future refactor does not turn the opt-out into an accidental block.
        $this->saveVm($missionId, $vmId, 'cfl-c', ['vm_cpu' => '4'], '');

        self::assertSame('4', (string) repo_scalar($this->db, 'SELECT vm_cpu FROM deploy_vms WHERE id = ?', 'i', [$vmId]));
    }

    /** @return array{0:int, 1:int} mission id, vm id */
    private function seedVm(string $vmName): array
    {
        $missionId = repo_create_mission($this->db, ['mission_name' => self::PREFIX . $vmName], false, $this->userId);
        $vmId = $this->saveVm($missionId, null, $vmName, [], '');

        return [$missionId, $vmId];
    }

    private function editVersion(int $vmId): string
    {
        return (string) (repo_scalar($this->db, 'SELECT edit_version FROM deploy_vms WHERE id = ?', 'i', [$vmId]) ?? '');
    }

    private function saveVm(int $missionId, ?int $vmId, string $name, array $overrides, string $expectedVersion, bool $requireVersion = false): int
    {
        $payload = $overrides + [
            'vm_name' => $name,
            'vm_hostname' => 'cflhost',
            'vm_domain' => 'phpunit.example.local',
            'vm_os' => 'phpunit-os',
            'vm_ram' => '2048',
            'vm_cpu' => '2',
            'vm_datastore' => '',
            'vm_datacenter' => '',
            'vm_guest_id' => VIRTUSPHERE_VM_DEFAULTS['guest_id'],
            'vm_notes' => '',
            'cpu_hotplug' => '1',
            'ram_hotplug' => '1',
        ];

        return repo_save_vm(
            $this->db,
            $missionId,
            $vmId,
            $payload,
            [['id' => 0, 'ip' => '', 'subnet' => '', 'gateway' => '', 'dns1' => '', 'dns2' => '', 'vlan' => 'phpunit-vlan', 'mode' => 'dhcp', 'type' => 'vmxnet3']],
            [['disk_name' => 'System', 'disk_size' => 40, 'disk_type' => 'thin']],
            [],
            $expectedVersion,
            $this->userId,
            $requireVersion
        );
    }

    private function cleanupTestRows(): void
    {
        $pattern = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)');
        $stmt->bind_param('s', $pattern);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
        $stmt->bind_param('s', $pattern);
        $stmt->execute();
    }
}
