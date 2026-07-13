<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';

/**
 * Provenance is server-owned (GROK.md forbidden pattern): deploy_vms.vm_creator
 * and deploy_missions.mission_creator are stamped from the acting user on create
 * and preserved on update, never read from the caller's payload. These guards pin
 * the four authorship rules that are easy to regress: create stamps, update
 * preserves, capture keeps the VM authors, and a template clone re-stamps the
 * operator. Runs in-stack against db() and cleans up its own rows by name prefix.
 */
final class CreatorProvenanceTest extends TestCase
{
    private const PREFIX = 'phpunit_creator_';

    private ?mysqli $db = null;
    private int $userId = 0;
    private string $userName = '';
    private int $otherUserId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }

        $user = repo_fetch_one($this->db, 'SELECT id, name FROM deploy_users ORDER BY id LIMIT 1');
        if ($user === null) {
            self::markTestSkipped('No user to act as.');
        }
        $this->userId = (int) $user['id'];
        $this->userName = (string) $user['name'];

        $other = repo_fetch_one($this->db, 'SELECT id FROM deploy_users WHERE id <> ? ORDER BY id LIMIT 1', 'i', [$this->userId]);
        $this->otherUserId = $other !== null ? (int) $other['id'] : $this->userId;

        $this->cleanupTestRows();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanupTestRows();
        }
    }

    public function testMissionCreateStampsActingUserAndIgnoresPayload(): void
    {
        $missionId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'stamp',
            'mission_creator' => 'FORGED',
        ], false, $this->userId);

        self::assertSame($this->userName, $this->missionCreator($missionId));
    }

    public function testMissionCreateWithoutUserLeavesCreatorEmpty(): void
    {
        // The legacy token API (access.php -> createMission()) resolves only the
        // token's role, so there is no author to record. Empty beats a guess.
        self::assertTrue(createMission(self::PREFIX . 'legacy', $this->db));

        $missionId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_missions WHERE mission_name = ?', 's', [self::PREFIX . 'legacy']);
        self::assertSame('', $this->missionCreator($missionId));
    }

    public function testVmCreateStampsActingUserAndIgnoresPayload(): void
    {
        $missionId = repo_create_mission($this->db, ['mission_name' => self::PREFIX . 'vmcreate'], false, $this->userId);
        $vmId = $this->saveVm($missionId, null, 'phpunitprov1', ['vm_creator' => 'FORGED'], $this->userId);

        self::assertSame($this->userName, $this->vmCreator($vmId));
    }

    public function testVmUpdateByAnotherUserPreservesTheOriginalCreator(): void
    {
        $missionId = repo_create_mission($this->db, ['mission_name' => self::PREFIX . 'vmupdate'], false, $this->userId);
        $vmId = $this->saveVm($missionId, null, 'phpunitprov2', [], $this->userId);
        $original = $this->vmCreator($vmId);

        $bundle = repo_get_vm_bundle($this->db, $vmId);
        $this->saveVm($missionId, $vmId, 'phpunitprov2', [
            'vm_creator' => 'FORGED',
            'vm_ram' => '4096',
        ], $this->otherUserId, (string) ($bundle['updated_at'] ?? ''));

        self::assertSame($original, $this->vmCreator($vmId));
        self::assertNotSame('FORGED', $this->vmCreator($vmId));
    }

    public function testCaptureStampsTemplateRowButKeepsVmAuthors(): void
    {
        $missionId = repo_create_mission($this->db, ['mission_name' => self::PREFIX . 'capsrc'], false, $this->userId);
        $this->saveVm($missionId, null, 'phpunitprov3', [], $this->userId);

        $result = repo_save_mission_as_template($this->db, $missionId, self::PREFIX . 'captpl', $this->otherUserId);
        $templateId = (int) $result['target_mission_id'];

        self::assertSame($this->nameOf($this->otherUserId), $this->missionCreator($templateId), 'template row belongs to whoever captured it');
        self::assertSame([$this->userName], $this->vmCreators($templateId), 'captured VMs keep their original author');
    }

    public function testTemplateCloneStampsTheOperatorOnMissionAndVms(): void
    {
        // A template authored by userId, instantiated by otherUserId: the new
        // mission and its VMs are the operator's work, not the template author's.
        $templateId = repo_create_mission($this->db, ['mission_name' => VIRTUSPHERE_TEMPLATE_PREFIX . self::PREFIX . 'clonetpl'], false, $this->userId);
        $this->saveVm($templateId, null, 'phpunitprov4', [], $this->userId);

        $result = repo_clone_template_to_new_mission($this->db, $templateId, self::PREFIX . 'clonedst', $this->otherUserId);
        $cloneId = (int) $result['target_mission_id'];

        $operator = $this->nameOf($this->otherUserId);
        self::assertSame($operator, $this->missionCreator($cloneId));
        self::assertSame([$operator], $this->vmCreators($cloneId));
    }

    /**
     * mecm-api.php embeds the mission row with `SELECT *`, so this column is an
     * additive `mission.mission_creator` key on getDeviceInfos/getDeviceList.
     */
    public function testMissionCreatorColumnExistsForTheMachineApiPayload(): void
    {
        $column = repo_fetch_one($this->db, "SHOW COLUMNS FROM deploy_missions LIKE 'mission_creator'");

        self::assertNotNull($column);
        self::assertSame('YES', (string) $column['Null'], 'pre-migration rows stay NULL, never backfilled');
    }

    private function saveVm(int $missionId, ?int $vmId, string $name, array $overrides, int $userId, string $expectedUpdatedAt = ''): int
    {
        $payload = $overrides + [
            'vm_name' => $name,
            'vm_hostname' => $name,
            'vm_domain' => 'phpunit.example.local',
            'vm_os' => 'phpunit-os',
            'vm_ram' => '2048',
            'vm_cpu' => '2',
            'vm_disk' => '',
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
            $expectedUpdatedAt,
            $userId
        );
    }

    private function nameOf(int $userId): string
    {
        return (string) (repo_scalar($this->db, 'SELECT name FROM deploy_users WHERE id = ?', 'i', [$userId]) ?? '');
    }

    private function missionCreator(int $missionId): string
    {
        return (string) (repo_scalar($this->db, 'SELECT mission_creator FROM deploy_missions WHERE id = ?', 'i', [$missionId]) ?? '');
    }

    private function vmCreator(int $vmId): string
    {
        return (string) (repo_scalar($this->db, 'SELECT vm_creator FROM deploy_vms WHERE id = ?', 'i', [$vmId]) ?? '');
    }

    /** @return list<string> */
    private function vmCreators(int $missionId): array
    {
        $stmt = $this->db->prepare('SELECT DISTINCT vm_creator FROM deploy_vms WHERE mission_id = ? ORDER BY vm_creator');
        $stmt->bind_param('i', $missionId);
        $stmt->execute();

        return array_column(repo_fetch_all($stmt->get_result()), 'vm_creator');
    }

    private function cleanupTestRows(): void
    {
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
