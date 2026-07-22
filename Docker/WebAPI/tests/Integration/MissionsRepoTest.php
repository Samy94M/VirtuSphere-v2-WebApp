<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';

/**
 * E2 mission-repo guards that were previously untested: the requireLocation
 * validation, the MECM rename lock (mission name doubles as the MECM collection
 * name) and the global VM-name conflict preflight for template clones. Runs
 * in-stack against db() and cleans up its own rows by name prefix.
 */
final class MissionsRepoTest extends TestCase
{
    private const PREFIX = 'phpunit_missionsrepo_';

    private ?mysqli $db = null;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanupTestRows();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanupTestRows();
        }
    }

    public function testRequireLocationRejectsEmptyDatastore(): void
    {
        $this->expectException(ValidationException::class);
        repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'loc_missing',
            'hypervisor_datastorage' => '',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
        ], true);
    }

    public function testRequireLocationAllowsAnEmptyDatacenter(): void
    {
        // ADR-0023: an empty datacenter is resolved at deploy time from the ESXi
        // credential chosen there, when that host reports exactly one. Requiring
        // it here would press a copy of a derivable value into every mission.
        // repo_deploy_assert_mission_ready() refuses the job if nothing resolves.
        $missionId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'loc_no_dc',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => '',
            'domain' => 'dc.example.com',
        ], true);

        self::assertGreaterThan(0, $missionId);
        self::assertSame('', (string) repo_scalar($this->db, 'SELECT hypervisor_datacenter FROM deploy_missions WHERE id = ?', 'i', [$missionId]));
    }

    public function testRequireLocationAcceptsCompleteLocation(): void
    {
        $missionId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'loc_ok',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
        ], true);

        self::assertGreaterThan(0, $missionId);
    }

    public function testRenameIsLockedWhenAVmIsRegisteredInMecm(): void
    {
        $missionId = repo_create_mission($this->db, ['mission_name' => self::PREFIX . 'locked']);
        $this->insertVm($missionId, 'PHPUNITLOCK1', VIRTUSPHERE_MECM_REGISTERED);

        $this->expectException(ValidationException::class);
        repo_update_mission_checked($this->db, $missionId, ['mission_name' => self::PREFIX . 'renamed'], '');
    }

    public function testRenameIsAllowedWithoutMecmRegistration(): void
    {
        $missionId = repo_create_mission($this->db, ['mission_name' => self::PREFIX . 'free']);
        $this->insertVm($missionId, 'PHPUNITFREE1', VIRTUSPHERE_MECM_NOT_READY);

        $result = repo_update_mission_checked($this->db, $missionId, ['mission_name' => self::PREFIX . 'free_renamed'], '');
        self::assertTrue($result);

        $mission = repo_get_mission($this->db, $missionId);
        self::assertSame(self::PREFIX . 'free_renamed', (string) $mission['mission_name']);
    }

    public function testCloneTemplateReportsGlobalNameConflicts(): void
    {
        $templateId = repo_create_mission($this->db, ['mission_name' => VIRTUSPHERE_TEMPLATE_PREFIX . self::PREFIX . 'tpl']);
        $this->insertVm($templateId, 'PHPUNITCLONE1', VIRTUSPHERE_MECM_NOT_READY);

        // Same VM name already lives in an unrelated mission, so the clone
        // preflight must refuse before creating anything.
        $otherId = repo_create_mission($this->db, ['mission_name' => self::PREFIX . 'other']);
        $this->insertVm($otherId, 'PHPUNITCLONE1', VIRTUSPHERE_MECM_NOT_READY);

        try {
            repo_clone_template_to_new_mission($this->db, $templateId, self::PREFIX . 'clone_target', 1);
            self::fail('Expected a ValidationException for the conflicting VM name.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('PHPUNITCLONE1', $exception->getMessage());
        }

        // Preflight refuses before the insert transaction, so no target mission
        // was created.
        self::assertFalse(repo_mission_name_exists($this->db, self::PREFIX . 'clone_target'));
    }

    private function insertVm(int $missionId, string $name, string $mecmState): void
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, mecm_sync_state) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('isss', $missionId, $name, $name, $mecmState);
        $stmt->execute();
    }

    private function cleanupTestRows(): void
    {
        // Remove child VMs first, then the missions, matching both the plain
        // and the template-prefixed names this test creates.
        foreach ([self::PREFIX . '%', VIRTUSPHERE_TEMPLATE_PREFIX . self::PREFIX . '%'] as $pattern) {
            $stmt = $this->db->prepare('DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)');
            $stmt->bind_param('s', $pattern);
            $stmt->execute();
        }
        foreach ([self::PREFIX . '%', VIRTUSPHERE_TEMPLATE_PREFIX . self::PREFIX . '%'] as $pattern) {
            $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
            $stmt->bind_param('s', $pattern);
            $stmt->execute();
        }
    }
}
