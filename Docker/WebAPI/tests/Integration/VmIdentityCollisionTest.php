<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/repo/esxi_inventory.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vm_identity.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/**
 * Decision 6: an occupied name is not proof that the host VM is ours. The
 * durable instance UUID decides ownership; adoption is the only operation
 * allowed to replace that identity and it never changes ESXi hardware.
 */
final class VmIdentityCollisionTest extends TestCase
{
    private const PREFIX = 'phpunit_identity_';
    private const VM_NAME = 'VS-ID-01';

    private ?mysqli $db = null;
    private int $missionId = 0;
    private int $vmId = 0;
    private int $esxiId = 0;
    private int $ansibleId = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();

        $this->missionId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'mission',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'example.test',
        ], true);
        $this->vmId = repo_save_vm(
            $this->db,
            $this->missionId,
            null,
            [
                'vm_name' => self::VM_NAME,
                'vm_hostname' => self::VM_NAME,
                'vm_os' => 'Windows Server 2022',
                'vm_domain' => 'example.test',
                'vm_guest_id' => 'windows2022srvNext_64Guest',
                'vm_cpu' => 4,
                'vm_ram' => 8192,
            ],
            [['ip' => '10.90.0.10', 'subnet' => '255.255.255.0', 'gateway' => '10.90.0.1', 'mode' => 'static', 'type' => 'vmxnet3', 'vlan' => 'VLAN90', 'mac' => '']],
            [['disk_name' => 'System', 'disk_size' => 80, 'disk_type' => 'thin']],
            [],
            '',
            1
        );
        $this->esxiId = $this->makeCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443);
        $this->ansibleId = $this->makeCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22);
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $this->userId, 'integration fixture requires one portal user');
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testForeignNamesakeBlocksSingleAndStaggeredEnqueue(): void
    {
        $this->replaceInventoryVm('vm-24', 'uuid-foreign');

        foreach (['single', 'group'] as $path) {
            try {
                if ($path === 'single') {
                    repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'full']);
                } else {
                    repo_enqueue_deploy_group($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'start'], null, 10);
                }
                self::fail($path . ' enqueue accepted a foreign namesake');
            } catch (VmIdentityConflictException $exception) {
                self::assertSame([self::VM_NAME], array_column($exception->conflicts(), 'vm_name'));
            }
        }

        self::assertSame(0, (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_jobs WHERE mission_id = ?', 'i', [$this->missionId]));
    }

    public function testMatchingInstanceUuidAllowsAChangedMoid(): void
    {
        $this->setStoredIdentity('vm-old', '503c89f1-5734-4d4d-a930-4d92b97a7289');
        $this->replaceInventoryVm('vm-new', '503C89F1-5734-4D4D-A930-4D92B97A7289');

        self::assertSame([], repo_vm_identity_conflicts($this->db, $this->missionId, $this->esxiId));
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'full']);
        self::assertGreaterThan(0, $jobId);
    }

    public function testAdoptionCannotReplaceIdentityDuringAnActiveJob(): void
    {
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'full']);
        self::assertGreaterThan(0, $jobId);
        $this->replaceInventoryVm('vm-66', 'uuid-new');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('active deploy job');
        repo_adopt_vm_identity($this->db, $this->missionId, $this->vmId, $this->esxiId);
    }

    public function testExplicitAdoptionStoresBothHandlesAndChangesNoHardware(): void
    {
        $this->setStoredIdentity('vm-old', 'uuid-old');
        $this->replaceInventoryVm('vm-44', 'uuid-adopted');
        $before = repo_fetch_one($this->db, 'SELECT vm_cpu, vm_ram, vm_guest_id, vm_datastore, vm_datacenter FROM deploy_vms WHERE id = ?', 'i', [$this->vmId]);

        $adopted = repo_adopt_vm_identity($this->db, $this->missionId, $this->vmId, $this->esxiId);

        self::assertSame(self::VM_NAME, $adopted['vm_name']);
        self::assertSame('vm-44', $adopted['vm_moid']);
        self::assertSame('uuid-adopted', $adopted['vm_instance_uuid']);
        $after = repo_fetch_one($this->db, 'SELECT vm_cpu, vm_ram, vm_guest_id, vm_datastore, vm_datacenter, vm_moid, vm_instance_uuid FROM deploy_vms WHERE id = ?', 'i', [$this->vmId]);
        self::assertSame($before, array_intersect_key($after, $before));
        self::assertSame('vm-44', $after['vm_moid']);
        self::assertSame('uuid-adopted', $after['vm_instance_uuid']);
        self::assertSame([], repo_vm_identity_conflicts($this->db, $this->missionId, $this->esxiId));
    }

    public function testAdoptionRejectsAnIncompleteInventoryIdentity(): void
    {
        $this->replaceInventoryVm('vm-55', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('complete MOID and instance UUID');
        repo_adopt_vm_identity($this->db, $this->missionId, $this->vmId, $this->esxiId);
    }

    private function replaceInventoryVm(string $moid, string $instanceUuid): void
    {
        repo_esxi_inventory_replace_kind($this->db, $this->esxiId, VIRTUSPHERE_INVENTORY_KIND_VM, [[
            'name' => self::VM_NAME,
            'meta_json' => ['moid' => $moid, 'instance_uuid' => $instanceUuid, 'power_state' => 'poweredOff'],
        ]], true);
    }

    private function setStoredIdentity(string $moid, string $instanceUuid): void
    {
        $stmt = $this->db->prepare('UPDATE deploy_vms SET vm_moid = ?, vm_instance_uuid = ? WHERE id = ?');
        $stmt->bind_param('ssi', $moid, $instanceUuid, $this->vmId);
        $stmt->execute();
    }

    private function makeCredential(string $type, int $port): int
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $name = self::PREFIX . $type;
        $host = $type === VIRTUSPHERE_CREDENTIAL_TYPE_ESXI ? 'esxi.example.test' : 'ansible.example.test';
        $username = 'svc';
        $secret = 'x';
        $stmt->bind_param('ssisss', $type, $name, $host, $port, $username, $secret);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        foreach ([
            'DELETE FROM deploy_jobs WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
            'DELETE FROM deploy_missions WHERE mission_name LIKE ?',
            'DELETE FROM deploy_credentials WHERE name LIKE ?',
        ] as $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('s', $like);
            $stmt->execute();
        }
    }
}
