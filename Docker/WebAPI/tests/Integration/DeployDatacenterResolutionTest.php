<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_page.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/repo/esxi_inventory.php';

/**
 * ADR-0023: a mission may leave its datacenter empty. The deploy then resolves it
 * from the ESXi credential chosen at queue time, but only when that credential
 * reports exactly one datacenter (a standalone host's implicit ha-datacenter).
 * Zero (never pulled) or several (vCenter) is not a guess the portal may make.
 * The datastore stays mandatory throughout. Prefix-scoped cleanup.
 *
 * The portal gate (lib/deploy_page.php) is a twin of the repository gate and is
 * exercised here against the same fixtures, because the two used to disagree
 * about `autostart`: the repo skipped the location requirement for it, the
 * portal did not, and the portal answers first.
 */
final class DeployDatacenterResolutionTest extends TestCase
{
    private const PREFIX = 'phpunit_dcres_';

    private ?mysqli $db = null;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testSoleDatacenterOnlyAnswersWhenItIsUnambiguous(): void
    {
        $none = $this->makeCredential('none');
        $one = $this->makeCredential('one');
        $two = $this->makeCredential('two');
        $this->setDatacenters($one, ['ha-datacenter']);
        $this->setDatacenters($two, ['DC-Nord', 'DC-Sued']);

        self::assertNull(repo_esxi_sole_datacenter($this->db, $none), 'never pulled proves nothing');
        self::assertSame('ha-datacenter', repo_esxi_sole_datacenter($this->db, $one));
        self::assertNull(repo_esxi_sole_datacenter($this->db, $two), 'vCenter must be decided by a human');
        self::assertNull(repo_esxi_sole_datacenter($this->db, 0), 'no credential chosen');

        self::assertSame(['DC-Nord', 'DC-Sued'], repo_esxi_datacenters_for_credential($this->db, $two));
    }

    public function testAnEmptyMissionDatacenterPassesTheGateForASingleDatacenterHost(): void
    {
        $credentialId = $this->makeCredential('standalone');
        $this->setDatacenters($credentialId, ['ha-datacenter']);
        $mission = $this->makeMission('');

        repo_deploy_assert_mission_ready($this->db, $mission, $credentialId);
        self::assertTrue(true, 'gate accepted the host fallback');
    }

    public function testAnEmptyMissionDatacenterIsRejectedWithoutInventory(): void
    {
        $credentialId = $this->makeCredential('unpulled');
        $mission = $this->makeMission('');

        $this->expectException(RuntimeException::class);
        repo_deploy_assert_mission_ready($this->db, $mission, $credentialId);
    }

    public function testAnEmptyMissionDatacenterIsRejectedForAVcenter(): void
    {
        $credentialId = $this->makeCredential('vcenter');
        $this->setDatacenters($credentialId, ['DC-Nord', 'DC-Sued']);
        $mission = $this->makeMission('');

        $this->expectException(RuntimeException::class);
        repo_deploy_assert_mission_ready($this->db, $mission, $credentialId);
    }

    public function testAStoredMissionDatacenterNeedsNoInventoryAtAll(): void
    {
        $credentialId = $this->makeCredential('unpulled2');
        $mission = $this->makeMission('DC-Nord');

        repo_deploy_assert_mission_ready($this->db, $mission, $credentialId);
        self::assertTrue(true);
    }

    public function testOmittingTheCredentialKeepsTheStrictOldBehaviour(): void
    {
        $mission = $this->makeMission('');

        $this->expectException(RuntimeException::class);
        repo_deploy_assert_mission_ready($this->db, $mission);
    }

    public function testTheDatastoreStaysMandatoryEvenWhenTheHostResolvesTheDatacenter(): void
    {
        $credentialId = $this->makeCredential('standalone2');
        $this->setDatacenters($credentialId, ['ha-datacenter']);
        $mission = $this->makeMission('');
        $mission['hypervisor_datastorage'] = '';

        $this->expectException(RuntimeException::class);
        repo_deploy_assert_mission_ready($this->db, $mission, $credentialId);
    }

    public function testTheAutostartModePassesThePortalGateWithoutAnyDatacenter(): void
    {
        // The failing case in a mixed fleet: a mission with no datacenter, mode
        // autostart, against a credential that was never pulled. The autostart
        // playbook reads no location at all, so refusing here would answer a
        // question nobody asked, and the backend would have queued the job.
        $neverPulled = $this->makeCredential('auto_none');
        $vcenter = $this->makeCredential('auto_many');
        $this->setDatacenters($vcenter, ['DC-Nord', 'DC-Sued']);
        $mission = $this->makeMission('');

        foreach ([$neverPulled, $vcenter, 0] as $credentialId) {
            deploy_assert_datacenter_resolvable($this->db, (int) $mission['id'], $credentialId, VIRTUSPHERE_DEPLOY_MODE_AUTOSTART);
        }
        self::assertTrue(true, 'the portal gate let every autostart case through');
    }

    public function testTheRepositoryGateAgreesWithThePortalOnAutostart(): void
    {
        // Both halves of the twin, same fixture: whatever one accepts the other
        // must accept, or the operator meets a refusal that has no backend.
        $credentialId = $this->makeCredential('auto_twin');
        $mission = $this->makeMission('');

        repo_deploy_assert_mission_ready($this->db, $mission, $credentialId, VIRTUSPHERE_DEPLOY_MODE_AUTOSTART);
        deploy_assert_datacenter_resolvable($this->db, (int) $mission['id'], $credentialId, VIRTUSPHERE_DEPLOY_MODE_AUTOSTART);
        self::assertTrue(true);
    }

    public function testALocationReadingModeStillFailsThePortalGate(): void
    {
        // The regression guard for the fix above: skipping the gate for
        // autostart must not skip it for everything else.
        $credentialId = $this->makeCredential('auto_full');
        $mission = $this->makeMission('');

        $this->expectException(ValidationException::class);
        deploy_assert_datacenter_resolvable($this->db, (int) $mission['id'], $credentialId, VIRTUSPHERE_DEPLOY_MODE_FULL);
    }

    private function makeCredential(string $suffix): int
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $type = VIRTUSPHERE_CREDENTIAL_TYPE_ESXI;
        $name = self::PREFIX . $suffix;
        $host = 'h';
        $port = 443;
        $user = 'u';
        $secret = 'x';
        $stmt->bind_param('ssisss', $type, $name, $host, $port, $user, $secret);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    /** @param array<int,string> $names */
    private function setDatacenters(int $credentialId, array $names): void
    {
        $items = array_map(static fn (string $name): array => ['name' => $name], $names);
        repo_esxi_inventory_replace_kind($this->db, $credentialId, VIRTUSPHERE_INVENTORY_KIND_DATACENTER, $items);
    }

    /** @return array<string,mixed> the mission row shape the gate reads */
    private function makeMission(string $datacenter): array
    {
        $missionId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'm',
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => $datacenter,
            'domain' => 'dc.example.com',
            'wds_vlan' => 'VLAN10',
        ]);
        // The gate also requires at least one VM.
        repo_save_vm(
            $this->db,
            $missionId,
            null,
            ['vm_name' => 'PHPUNITDCR1', 'vm_hostname' => 'PHPUNITDCR1', 'vm_os' => 'Windows Server 2019', 'vm_domain' => 'dc.example.com', 'vm_guest_id' => 'windows2019srv_64Guest'],
            [['ip' => '10.0.0.5', 'subnet' => '255.255.255.0', 'gateway' => '10.0.0.1', 'mode' => 'static', 'type' => 'vmxnet3', 'vlan' => 'VLAN10', 'mac' => '']],
            [['disk_name' => 'System', 'disk_size' => 40, 'disk_type' => 'thick']],
            [],
            '',
            1
        );

        return [
            'id' => $missionId,
            'mission_name' => self::PREFIX . 'm',
            'hypervisor_datacenter' => $datacenter,
            'hypervisor_datastorage' => 'ds1',
        ];
    }

    private function cleanup(): void
    {
        $like = '%' . self::PREFIX . '%';
        foreach (['DELETE FROM deploy_interfaces WHERE vm_id IN (SELECT id FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?))',
                  'DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
                  'DELETE FROM deploy_missions WHERE mission_name LIKE ?',
                  'DELETE FROM deploy_esxi_inventory WHERE credential_id IN (SELECT id FROM deploy_credentials WHERE name LIKE ?)',
                  'DELETE FROM deploy_credentials WHERE name LIKE ?'] as $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('s', $like);
            $stmt->execute();
        }
    }
}
