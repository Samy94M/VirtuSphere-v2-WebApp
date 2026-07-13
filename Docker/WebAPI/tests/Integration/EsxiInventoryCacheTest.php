<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/esxi_inventory.php';

/**
 * Paket E chunk 1: the ESXi inventory cache engine (ADR-0023). Verifies the
 * per-(credential,kind) replace, the empty-result guard, case-insensitive
 * de-duplication and the fetch-state tracking (auth failures pause). No ESXi
 * needed; the parsed inventory is passed in directly.
 */
final class EsxiInventoryCacheTest extends TestCase
{
    private const PREFIX = 'phpunit_inv_';

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
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testApplyWritesAllKinds(): void
    {
        $summary = repo_esxi_inventory_apply($this->db, $this->credentialId, [
            'datacenters' => ['DC1'],
            'datastores' => [
                ['name' => 'ds-fast', 'capacity_bytes' => 2_000_000_000_000, 'free_bytes' => 500_000_000_000],
            ],
            'networks' => ['VLAN10', 'VLAN20'],
            'hosts' => [
                ['name' => 'esxi-01', 'meta_json' => ['ram_mb' => 262144, 'cpu_cores' => 40]],
            ],
        ]);

        self::assertSame(1, $summary['datacenter']['written']);
        self::assertSame(2, $summary['network']['written']);

        $grouped = repo_esxi_inventory_for_credential($this->db, $this->credentialId);
        self::assertCount(2, $grouped['network']);
        $datastore = $grouped['datastore'][0];
        self::assertSame('ds-fast', (string) $datastore['name']);
        self::assertSame(500_000_000_000, (int) $datastore['free_bytes']);
        self::assertStringContainsString('cpu_cores', (string) $grouped['host'][0]['meta_json']);
    }

    public function testEmptyResultKeepsExistingRows(): void
    {
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, 'network', repo_esxi_inventory_name_items(['VLAN10', 'VLAN20']));
        // A transient blank fetch must not wipe the cache.
        $result = repo_esxi_inventory_replace_kind($this->db, $this->credentialId, 'network', []);
        self::assertTrue($result['kept_empty']);
        self::assertSame(0, $result['written']);
        $grouped = repo_esxi_inventory_for_credential($this->db, $this->credentialId);
        self::assertCount(2, $grouped['network']);
    }

    public function testCaseInsensitiveDedupe(): void
    {
        $result = repo_esxi_inventory_replace_kind($this->db, $this->credentialId, 'network', repo_esxi_inventory_name_items(['Prod', 'prod', 'PROD', 'Test']));
        // "Prod"/"prod"/"PROD" collapse to one; "Test" is separate.
        self::assertSame(2, $result['written']);
    }

    public function testVlanPresenceReportCountsOnlySuccessfulCredentials(): void
    {
        $secondId = $this->makeCredential('esxi2');
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, 'network', repo_esxi_inventory_name_items([self::PREFIX . 'vlanA', self::PREFIX . 'vlanB']));
        repo_esxi_inventory_replace_kind($this->db, $secondId, 'network', repo_esxi_inventory_name_items([self::PREFIX . 'vlanA']));
        // Only the first credential ever pulled successfully; the second has
        // cache rows but no success state and must not enter the denominator.
        repo_esxi_inventory_record_success($this->db, $this->credentialId);

        // vlanA carries a vlan_id meta on the second credential only; the
        // report's ID aggregation must surface it (F-slice).
        repo_esxi_inventory_replace_kind($this->db, $secondId, 'network', [
            ['name' => self::PREFIX . 'vlanA', 'meta_json' => ['vlan_id' => 903, 'trunk' => false]],
        ]);

        $report = repo_esxi_vlan_presence_report($this->db);
        self::assertContains(self::PREFIX . 'esxi', $report['eligible']);
        self::assertNotContains(self::PREFIX . 'esxi2', $report['eligible']);
        self::assertSame([self::PREFIX . 'esxi2'], $report['ids'][esxi_inventory_name_key(self::PREFIX . 'vlanA')][903]);

        $presenceA = $report['by_name'][esxi_inventory_name_key(self::PREFIX . 'vlanA')] ?? [];
        $presenceB = $report['by_name'][esxi_inventory_name_key(self::PREFIX . 'vlanB')] ?? [];
        self::assertContains(self::PREFIX . 'esxi', $presenceA);
        self::assertContains(self::PREFIX . 'esxi2', $presenceA);
        self::assertContains(self::PREFIX . 'esxi', $presenceB);
        self::assertNotContains(self::PREFIX . 'esxi2', $presenceB);
    }

    public function testAuthFailurePausesButUnreachableDoesNot(): void
    {
        repo_esxi_inventory_record_failure($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE);
        $state = repo_esxi_inventory_state($this->db, $this->credentialId);
        self::assertSame(0, (int) $state['paused_until_credential_change']);
        self::assertSame(1, (int) $state['failure_streak']);

        repo_esxi_inventory_record_failure($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_ERROR_AUTH);
        $state = repo_esxi_inventory_state($this->db, $this->credentialId);
        self::assertSame(1, (int) $state['paused_until_credential_change']);
        self::assertSame(2, (int) $state['failure_streak']);

        // A success clears streak + pause.
        repo_esxi_inventory_record_success($this->db, $this->credentialId);
        $state = repo_esxi_inventory_state($this->db, $this->credentialId);
        self::assertSame(0, (int) $state['paused_until_credential_change']);
        self::assertSame(0, (int) $state['failure_streak']);
        self::assertNotNull($state['last_success_at']);
    }

    private function makeCredential(string $suffix): int
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $type = VIRTUSPHERE_CREDENTIAL_TYPE_ESXI;
        $name = self::PREFIX . $suffix;
        $host = 'esxi.example.com';
        $port = 443;
        $user = 'svc';
        $secret = 'x';
        $stmt->bind_param('ssisss', $type, $name, $host, $port, $user, $secret);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        // Inventory + state cascade from the credential; delete the credential.
        $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }
}
