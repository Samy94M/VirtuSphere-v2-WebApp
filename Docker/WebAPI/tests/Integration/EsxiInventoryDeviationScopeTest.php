<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/esxi_inventory.php';
require_once dirname(__DIR__, 2) . '/lib/repo/esxi_inventory.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';

/**
 * Who sees a template's deviation (ADR-0023 amendment). The three readers of the
 * same scan answer different questions and must therefore scope it differently:
 *
 *  - the System status report includes templates and flags them, because a stale
 *    VLAN or datastore living only in a template is invisible everywhere else and
 *    propagates into every mission cloned from it,
 *  - the mission-list badge and the deploy hint exclude them, because a template
 *    cannot deploy and a warning about one would name a problem nobody can act on
 *    from that page.
 *
 * The report used to call the scan without the flag, so the whole template branch
 * (and the is_template badge that renders it) was dead code.
 */
final class EsxiInventoryDeviationScopeTest extends TestCase
{
    private const PREFIX = 'phpunit_devscope_';

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
        $host = 'esxi.example.com';
        $port = 443;
        $user = 'svc';
        $secret = 'x';
        $stmt->bind_param('ssisss', $type, $name, $host, $port, $user, $secret);
        $stmt->execute();
        $this->credentialId = (int) $this->db->insert_id;

        // A non-empty set per kind is the empty-guard's precondition: without it
        // the scan cannot prove absence and reports nothing at all.
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_KIND_NETWORK, repo_esxi_inventory_name_items([self::PREFIX . 'vlan_known']));
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_KIND_DATASTORE, repo_esxi_inventory_name_items([self::PREFIX . 'ds_known']));
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_KIND_DATACENTER, repo_esxi_inventory_name_items([self::PREFIX . 'dc_known']));
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testATemplateOnlyDeviationReachesTheReportButNoBadge(): void
    {
        $templateId = $this->makeMission(VIRTUSPHERE_TEMPLATE_PREFIX . self::PREFIX . 'tpl', self::PREFIX . 'vlan_stale');
        $missionId = $this->makeMission(self::PREFIX . 'clean', self::PREFIX . 'vlan_known');

        $withTemplates = $this->byMissionId(esxi_inventory_mission_deviations($this->db, true));
        $withoutTemplates = $this->byMissionId(esxi_inventory_mission_deviations($this->db, false));

        self::assertArrayHasKey($templateId, $withTemplates, 'the report must see a deviation that lives only in a template');
        self::assertTrue($withTemplates[$templateId]['is_template'], 'and must mark it as one, or it reads as a broken mission');
        self::assertSame(
            [['field' => 'vlan', 'value' => self::PREFIX . 'vlan_stale']],
            $withTemplates[$templateId]['issues']
        );

        self::assertArrayNotHasKey($templateId, $withoutTemplates, 'the mission list and the deploy hint must not warn about a template');
        self::assertArrayNotHasKey($missionId, $withTemplates, 'a mission whose values are all in the inventory has nothing to report');

        // The list badge is the without-templates reader; naming a template there
        // would send an operator to a page that cannot deploy it.
        self::assertArrayNotHasKey($templateId, esxi_inventory_deviating_mission_ids($this->db));
    }

    public function testAMissionDeviationIsSeenByBothReaders(): void
    {
        $missionId = $this->makeMission(self::PREFIX . 'stale', self::PREFIX . 'vlan_stale');

        self::assertArrayHasKey($missionId, $this->byMissionId(esxi_inventory_mission_deviations($this->db, true)));
        self::assertArrayHasKey($missionId, $this->byMissionId(esxi_inventory_mission_deviations($this->db, false)));
        self::assertArrayHasKey($missionId, esxi_inventory_deviating_mission_ids($this->db));
    }

    /**
     * @param array<int, array<string, mixed>> $deviations
     * @return array<int, array<string, mixed>> only this test's missions
     */
    private function byMissionId(array $deviations): array
    {
        $out = [];
        foreach ($deviations as $entry) {
            if (str_contains((string) $entry['mission_name'], self::PREFIX)) {
                $out[(int) $entry['mission_id']] = $entry;
            }
        }

        return $out;
    }

    private function makeMission(string $name, string $vlan): int
    {
        return repo_create_mission($this->db, [
            'mission_name' => $name,
            'mission_notes' => '',
            'wds_vlan' => $vlan,
            'hypervisor_datastorage' => self::PREFIX . 'ds_known',
            'hypervisor_datacenter' => self::PREFIX . 'dc_known',
            'domain' => 'dc.example.com',
        ]);
    }

    private function cleanup(): void
    {
        $like = '%' . self::PREFIX . '%';
        foreach ([
            'DELETE FROM deploy_interfaces WHERE vm_id IN (SELECT id FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?))',
            'DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
            'DELETE FROM deploy_missions WHERE mission_name LIKE ?',
            'DELETE FROM deploy_esxi_inventory WHERE credential_id IN (SELECT id FROM deploy_credentials WHERE name LIKE ?)',
            'DELETE FROM deploy_esxi_inventory_state WHERE credential_id IN (SELECT id FROM deploy_credentials WHERE name LIKE ?)',
            'DELETE FROM deploy_credentials WHERE name LIKE ?',
        ] as $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('s', $like);
            $stmt->execute();
        }
    }
}
