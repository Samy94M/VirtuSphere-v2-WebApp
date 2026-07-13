<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/esxi_inventory.php';

/**
 * Paket E4 refinement (ADR-0023): the guided VLAN mass reassignment and the
 * inventory-deviation detector. Reassignment rewrites mission WDS-VLAN and
 * interface VLAN name-strings in one transaction and reports counts; deviations
 * flag missions and VMs whose values are absent from a non-empty inventory kind,
 * while templates and matching values stay clear. Prefix-scoped cleanup.
 */
final class EsxiVlanReassignTest extends TestCase
{
    private const PREFIX = 'phpunit_reassign_';

    private ?mysqli $db = null;
    private int $credentialId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $type = VIRTUSPHERE_CREDENTIAL_TYPE_ESXI;
        $name = self::PREFIX . 'esxi';
        $host = 'h';
        $port = 443;
        $user = 'u';
        $secret = 'x';
        $stmt->bind_param('ssisss', $type, $name, $host, $port, $user, $secret);
        $stmt->execute();
        $this->credentialId = (int) $this->db->insert_id;
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testReassignRewritesMissionsAndInterfacesAndLeavesOthers(): void
    {
        $from = self::PREFIX . 'a';
        $to = self::PREFIX . 'b';
        $other = self::PREFIX . 'c';

        $missionA = $this->makeMission('m_a', $from);
        $this->makeVm($missionA, 'PHPUNITRA1', $from);
        $missionB = $this->makeMission('m_b', $other); // must stay untouched
        $this->makeVm($missionB, 'PHPUNITRA2', $other);

        $result = repo_reassign_vlan($this->db, $from, $to);
        self::assertSame(1, $result['missions']);
        self::assertSame(1, $result['interfaces']);

        self::assertSame($to, $this->missionVlan($missionA));
        self::assertSame($other, $this->missionVlan($missionB));
        self::assertSame(1, (int) repo_scalar($this->db, "SELECT COUNT(*) FROM deploy_interfaces WHERE vlan = ?", 's', [$to]));
        self::assertSame(0, (int) repo_scalar($this->db, "SELECT COUNT(*) FROM deploy_interfaces WHERE vlan = ?", 's', [$from]));
    }

    public function testReassignRejectsEmptyOrIdenticalArguments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        repo_reassign_vlan($this->db, self::PREFIX . 'x', self::PREFIX . 'x');
    }

    public function testDeviationsFlagUnknownVlanButNotMatchingOrTemplate(): void
    {
        $known = self::PREFIX . 'known';
        $unknown = self::PREFIX . 'unknown';
        // Populate all three kinds so the detector evaluates them; the missions'
        // DC/datastore match the inventory, isolating VLAN as the only deviation
        // source regardless of any other credentials' inventory rows.
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_KIND_NETWORK, [['name' => $known]]);
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_KIND_DATACENTER, [['name' => 'DC1']]);
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_KIND_DATASTORE, [['name' => 'ds1']]);

        $good = $this->makeMission('m_good', $known);
        $bad = $this->makeMission('m_bad', $unknown);
        // Template names literally start with '_'; the detector excludes them.
        $template = $this->makeMissionNamed('_' . self::PREFIX . 'tmpl', $unknown);

        $ids = esxi_inventory_deviating_mission_ids($this->db);
        self::assertArrayHasKey($bad, $ids);
        self::assertArrayNotHasKey($good, $ids);
        self::assertArrayNotHasKey($template, $ids);

        // Mission and VM entries share the mission_id, so accumulate instead of
        // overwriting.
        self::assertSame([$unknown], $this->issueValues($bad, 'vlan'));
    }

    public function testTemplateDeviationsAreFlaggedWhenIncluded(): void
    {
        $known = self::PREFIX . 'known';
        $stale = self::PREFIX . 'ghost';
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_KIND_NETWORK, [['name' => $known]]);
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_KIND_DATACENTER, [['name' => 'DC1']]);
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_KIND_DATASTORE, [['name' => 'ds1']]);

        $template = $this->makeMissionNamed('_' . self::PREFIX . 'tmpl2', $stale);
        $mission = $this->makeMission('m_plain', $stale);

        // Default stays template-free (mission badge, deploy hint).
        $defaultIds = [];
        foreach (esxi_inventory_mission_deviations($this->db) as $entry) {
            $defaultIds[$entry['mission_id']] = true;
        }
        self::assertArrayNotHasKey($template, $defaultIds);
        self::assertArrayNotHasKey($template, esxi_inventory_deviating_mission_ids($this->db));

        // Included: the template entry appears and is flagged as such.
        $included = [];
        foreach (esxi_inventory_mission_deviations($this->db, true) as $entry) {
            if (!isset($entry['vm_id'])) {
                $included[$entry['mission_id']] = $entry;
            }
        }
        self::assertArrayHasKey($template, $included);
        self::assertTrue($included[$template]['is_template']);
        self::assertArrayHasKey($mission, $included);
        self::assertFalse($included[$mission]['is_template']);
    }

    public function testReassignReportsZeroCountsForAnUnusedSourceName(): void
    {
        // "From" is free text on the integrations page. A typo must not look like
        // a successful rename; the caller turns zero counts into a warning.
        $this->makeMission('m_keep', self::PREFIX . 'live');

        $result = repo_reassign_vlan($this->db, self::PREFIX . 'typo', self::PREFIX . 'live');
        self::assertSame(['missions' => 0, 'interfaces' => 0], $result);
    }

    public function testVmLocationOverrideDeviatesButAnEmptyOverrideDoesNot(): void
    {
        $known = self::PREFIX . 'known';
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_KIND_NETWORK, [['name' => $known]]);
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_KIND_DATACENTER, [['name' => 'DC1']]);
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_KIND_DATASTORE, [['name' => 'ds1']]);

        $mission = $this->makeMission('m_vm', $known);
        $inheriting = $this->makeVm($mission, 'PHPUNITVM1', $known);
        $overriding = $this->makeVm($mission, 'PHPUNITVM2', $known, ['vm_datastore' => 'ghost-ds']);

        // The mission itself is clean, so it must not appear on its own.
        self::assertSame([], $this->issueValues($mission, 'datastore'));

        $entries = $this->vmEntries();
        self::assertArrayNotHasKey($inheriting, $entries, 'an empty override is not a deviation');
        self::assertArrayHasKey($overriding, $entries);
        self::assertSame('PHPUNITVM2', $entries[$overriding]['vm_name']);
        self::assertSame($mission, $entries[$overriding]['mission_id']);
        self::assertSame([['field' => 'vm_datastore', 'value' => 'ghost-ds']], $entries[$overriding]['issues']);

        // The badge on the mission list keys off mission_id, so a VM-only
        // deviation has to light it up too.
        self::assertArrayHasKey($mission, esxi_inventory_deviating_mission_ids($this->db));
    }

    public function testStaleInterfaceVlanIsReportedOnTheVm(): void
    {
        $known = self::PREFIX . 'known';
        $stale = self::PREFIX . 'stale';
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_KIND_NETWORK, [['name' => $known]]);

        // Mission VLAN is fine; only the VM's interface points at a gone portgroup.
        $mission = $this->makeMission('m_nic', $known);
        $vmId = $this->makeVm($mission, 'PHPUNITNIC', $stale);

        $entries = $this->vmEntries();
        self::assertArrayHasKey($vmId, $entries);
        self::assertSame([['field' => 'vlan', 'value' => $stale]], $entries[$vmId]['issues']);
    }

    /** All issue values of one field, across the mission entry and its VM entries. */
    private function issueValues(int $missionId, string $field): array
    {
        $values = [];
        foreach (esxi_inventory_mission_deviations($this->db) as $entry) {
            if ($entry['mission_id'] !== $missionId || isset($entry['vm_id'])) {
                continue;
            }
            foreach ($entry['issues'] as $issue) {
                if ($issue['field'] === $field) {
                    $values[] = $issue['value'];
                }
            }
        }

        return $values;
    }

    /** VM-level deviation entries keyed by vm_id. */
    private function vmEntries(): array
    {
        $entries = [];
        foreach (esxi_inventory_mission_deviations($this->db) as $entry) {
            if (isset($entry['vm_id'])) {
                $entries[$entry['vm_id']] = $entry;
            }
        }

        return $entries;
    }

    private function makeMission(string $suffix, string $vlan): int
    {
        return $this->makeMissionNamed(self::PREFIX . $suffix, $vlan);
    }

    private function makeMissionNamed(string $name, string $vlan): int
    {
        return repo_create_mission($this->db, [
            'mission_name' => $name,
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
            'wds_vlan' => $vlan,
        ], true);
    }

    private function makeVm(int $missionId, string $name, string $vlan, array $extra = []): int
    {
        return repo_save_vm(
            $this->db,
            $missionId,
            null,
            $extra + ['vm_name' => $name, 'vm_hostname' => $name, 'vm_os' => 'Windows Server 2019', 'vm_domain' => 'dc.example.com', 'vm_guest_id' => 'windows2019srv_64Guest'],
            [['ip' => '10.0.0.5', 'subnet' => '255.255.255.0', 'gateway' => '10.0.0.1', 'mode' => 'static', 'type' => 'vmxnet3', 'vlan' => $vlan, 'mac' => '']],
            [['disk_name' => 'System', 'disk_size' => 40, 'disk_type' => 'thick']],
            [],
            '',
            1
        );
    }

    private function missionVlan(int $missionId): string
    {
        return (string) repo_scalar($this->db, 'SELECT wds_vlan FROM deploy_missions WHERE id = ?', 'i', [$missionId]);
    }

    private function cleanup(): void
    {
        // Contains-match so the leading-underscore template name is cleaned too.
        $like = '%' . self::PREFIX . '%';
        foreach (['DELETE FROM deploy_interfaces WHERE vm_id IN (SELECT id FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?))',
                  'DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
                  'DELETE FROM deploy_esxi_inventory WHERE credential_id IN (SELECT id FROM deploy_credentials WHERE name LIKE ?)',
                  'DELETE FROM deploy_missions WHERE mission_name LIKE ?',
                  'DELETE FROM deploy_credentials WHERE name LIKE ?'] as $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('s', $like);
            $stmt->execute();
        }
    }
}
