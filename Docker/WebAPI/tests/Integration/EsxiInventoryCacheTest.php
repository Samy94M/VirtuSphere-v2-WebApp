<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/credentials.php';
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
            'vms' => [
                ['name' => 'WS-001', 'meta_json' => ['moid' => 'vm-24', 'power_state' => 'poweredOff']],
            ],
        ]);

        self::assertSame(1, $summary['datacenter']['written']);
        self::assertSame(2, $summary['network']['written']);
        self::assertSame(1, $summary['vm']['written']);

        $grouped = repo_esxi_inventory_for_credential($this->db, $this->credentialId);
        self::assertCount(2, $grouped['network']);
        $datastore = $grouped['datastore'][0];
        self::assertSame('ds-fast', (string) $datastore['name']);
        self::assertSame(500_000_000_000, (int) $datastore['free_bytes']);
        self::assertStringContainsString('cpu_cores', (string) $grouped['host'][0]['meta_json']);
        self::assertStringContainsString('vm-24', (string) $grouped['vm'][0]['meta_json']);
    }

    public function testAnsweredEmptyVmQueryClearsNamesakesAndStampsFreshness(): void
    {
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_KIND_VM, [
            ['name' => 'WS-OLD', 'meta_json' => ['moid' => 'vm-1']],
        ]);

        $summary = repo_esxi_inventory_apply($this->db, $this->credentialId, [
            'datacenters' => [],
            'datastores' => [],
            'networks' => [],
            'hosts' => [],
            'vms' => [],
            'queries' => [
                'vms' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_ANSWERED, 'message' => ''],
            ],
        ]);

        self::assertTrue($summary['vm']['cleared']);
        self::assertSame(1, $summary['vm']['removed']);
        self::assertArrayNotHasKey('vm', repo_esxi_inventory_for_credential($this->db, $this->credentialId));

        $map = json_decode((string) repo_esxi_inventory_state($this->db, $this->credentialId)['kind_freshness_json'], true);
        self::assertArrayHasKey(VIRTUSPHERE_INVENTORY_KIND_VM, $map);
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

    /**
     * B15: an empty list used to be three different things at once, and the
     * cache treated all three as "keep the old rows". With the per-query report
     * the pull can now SAY which one happened, and the cache follows: a kind
     * whose every query answered decides authoritatively, so an answered-empty
     * kind is cleared (the host genuinely has none, and keeping the old rows
     * would show portgroups the host lost). failed/skipped still keep rows.
     */
    public function testAnsweredEmptyClearsTheKindWhileFailedEmptyKeepsIt(): void
    {
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, 'network', repo_esxi_inventory_name_items(['VLAN10', 'VLAN20']));
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, 'datastore', [['name' => 'ds-old']]);

        $summary = repo_esxi_inventory_apply($this->db, $this->credentialId, [
            'datacenters' => ['DC1'],
            'datastores' => [],
            'networks' => [],
            'hosts' => [],
            'queries' => [
                'datacenters' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_ANSWERED, 'message' => ''],
                'datastores' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_REJECTED, 'message' => 'not authorized'],
                'networks_standard' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_ANSWERED, 'message' => ''],
                'networks_dvs' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_ANSWERED, 'message' => ''],
                'hosts' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_SKIPPED, 'message' => ''],
            ],
        ]);

        // Both network queries answered and the union is empty: authoritative.
        self::assertFalse($summary['network']['kept_empty']);
        self::assertTrue($summary['network']['cleared']);
        self::assertSame(2, $summary['network']['removed']);
        // The datastore query was rejected: the empty list proves nothing and
        // the frozen rows stay, exactly as before the report existed.
        self::assertTrue($summary['datastore']['kept_empty']);
        self::assertFalse($summary['datastore']['cleared']);

        $grouped = repo_esxi_inventory_for_credential($this->db, $this->credentialId);
        self::assertArrayNotHasKey('network', $grouped);
        self::assertCount(1, $grouped['datastore']);
    }

    public function testKindFreshnessIsStampedOnlyForAnsweredKinds(): void
    {
        repo_esxi_inventory_apply($this->db, $this->credentialId, [
            'datacenters' => ['DC1'],
            'datastores' => [],
            'networks' => ['VLAN10'],
            'hosts' => [],
            'queries' => [
                'datacenters' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_ANSWERED, 'message' => ''],
                'datastores' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_REJECTED, 'message' => 'x'],
                'networks_standard' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_ANSWERED, 'message' => ''],
                'networks_dvs' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_SKIPPED, 'message' => ''],
                'hosts' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_ANSWERED, 'message' => ''],
            ],
        ]);

        $state = repo_esxi_inventory_state($this->db, $this->credentialId);
        self::assertNotNull($state, 'the freshness stamp must create the state row when none exists yet');
        $map = json_decode((string) ($state['kind_freshness_json'] ?? ''), true);
        self::assertIsArray($map);
        // datacenters and hosts answered: stamped, including the EMPTY host
        // kind (that is the whole point: "we know it is empty as of T" is not
        // representable by row timestamps). The rejected datastore query and
        // the half-skipped network union stamp nothing.
        self::assertArrayHasKey(VIRTUSPHERE_INVENTORY_KIND_DATACENTER, $map);
        self::assertArrayHasKey(VIRTUSPHERE_INVENTORY_KIND_HOST, $map);
        self::assertArrayNotHasKey(VIRTUSPHERE_INVENTORY_KIND_DATASTORE, $map);
        self::assertArrayNotHasKey(VIRTUSPHERE_INVENTORY_KIND_NETWORK, $map);

        // A later pull with the datastore query healthy fills the gap and
        // keeps the earlier stamps.
        repo_esxi_inventory_apply($this->db, $this->credentialId, [
            'datacenters' => [],
            'datastores' => [['name' => 'ds1']],
            'networks' => [],
            'hosts' => [],
            'queries' => [
                'datastores' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_ANSWERED, 'message' => ''],
                'datacenters' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_REJECTED, 'message' => 'x'],
                'networks_standard' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_REJECTED, 'message' => 'x'],
                'networks_dvs' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_REJECTED, 'message' => 'x'],
                'hosts' => ['state' => VIRTUSPHERE_INVENTORY_QUERY_REJECTED, 'message' => 'x'],
            ],
        ]);
        $map = json_decode((string) repo_esxi_inventory_state($this->db, $this->credentialId)['kind_freshness_json'], true);
        self::assertArrayHasKey(VIRTUSPHERE_INVENTORY_KIND_DATASTORE, $map);
        self::assertArrayHasKey(VIRTUSPHERE_INVENTORY_KIND_DATACENTER, $map, 'an earlier stamp survives a later rejected query');
    }

    public function testAPullWithoutTheQueryReportKeepsTheOldGuardSemantics(): void
    {
        // Output from a playbook that predates the per-query report: nothing is
        // authoritative, nothing is stamped, the empty-guard works as always.
        repo_esxi_inventory_replace_kind($this->db, $this->credentialId, 'network', repo_esxi_inventory_name_items(['VLAN10']));

        $summary = repo_esxi_inventory_apply($this->db, $this->credentialId, [
            'datacenters' => [],
            'datastores' => [],
            'networks' => [],
            'hosts' => [],
        ]);

        self::assertTrue($summary['network']['kept_empty']);
        self::assertFalse($summary['network']['cleared']);
        self::assertCount(1, repo_esxi_inventory_for_credential($this->db, $this->credentialId)['network']);
        $state = repo_esxi_inventory_state($this->db, $this->credentialId);
        self::assertTrue($state === null || $state['kind_freshness_json'] === null);
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

    /**
     * The pause exists to stop an ESXi ACCOUNT from locking itself out, so it
     * belongs to exactly one category: the ESXi endpoint rejected our login.
     * Every Ansible-host finding is about the machine in between, and pausing
     * on one would stop every future auto-pull of a credential whose password
     * was never wrong - repaired only by re-saving a credential that nobody has
     * a reason to touch. Proven against the real write path rather than the
     * predicate alone, because it is an UPDATE that decides this, not a `bool`.
     */
    public function testNoAnsibleHostFindingPausesTheEsxiCredential(): void
    {
        foreach ([
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_AUTH,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_AUTHZ,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TIMEOUT,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_UNREACHABLE,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_PREFLIGHT,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_CONFIG,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_SFTP,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_TRANSPORT,
            VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_DNS,
        ] as $category) {
            repo_esxi_inventory_record_failure($this->db, $this->credentialId, $category);
            $state = repo_esxi_inventory_state($this->db, $this->credentialId);
            self::assertSame(
                0,
                (int) $state['paused_until_credential_change'],
                $category . ' must not pause the ESXi credential: it names the Ansible host, not the ESXi login.'
            );
            self::assertSame($category, $state['last_error_category']);
        }

        // ...and the one category that does still pauses after all of them.
        repo_esxi_inventory_record_failure($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_ERROR_AUTH);
        $state = repo_esxi_inventory_state($this->db, $this->credentialId);
        self::assertSame(1, (int) $state['paused_until_credential_change']);
    }

    /**
     * The documented way out of a pause that a pre-origin-split pull wrote for
     * an Ansible-side failure: saving the ESXi credential resumes the cycle.
     * It has to keep working, or an operator reading the runbook is left with
     * a credential that never pulls again.
     */
    public function testSavingTheCredentialLiftsALegacyFalsePause(): void
    {
        repo_esxi_inventory_record_failure($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_ERROR_AUTH);
        repo_esxi_inventory_record_failure($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_ERROR_AUTH);
        $state = repo_esxi_inventory_state($this->db, $this->credentialId);
        self::assertSame(1, (int) $state['paused_until_credential_change']);
        self::assertSame(2, (int) $state['failure_streak']);

        repo_esxi_inventory_clear_pause($this->db, $this->credentialId);

        $state = repo_esxi_inventory_state($this->db, $this->credentialId);
        self::assertSame(0, (int) $state['paused_until_credential_change']);
        self::assertSame(0, (int) $state['failure_streak']);
        // The finding itself survives: the pause is lifted, not the evidence
        // that the last pull failed, which the card still has to show.
        self::assertSame(VIRTUSPHERE_INVENTORY_ERROR_AUTH, $state['last_error_category']);
    }

    public function testFetchStatePointsToItsExactRetainedJobAndClearsWithRetention(): void
    {
        $failedJob = $this->makeInventoryJob(VIRTUSPHERE_DEPLOY_STATUS_FAILED);
        repo_esxi_inventory_record_failure(
            $this->db,
            $this->credentialId,
            VIRTUSPHERE_INVENTORY_ERROR_PARSE,
            $failedJob
        );
        self::assertSame($failedJob, (int) repo_esxi_inventory_state($this->db, $this->credentialId)['last_job_id']);

        $successfulJob = $this->makeInventoryJob(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED);
        repo_esxi_inventory_record_success($this->db, $this->credentialId, null, $successfulJob);
        self::assertSame($successfulJob, (int) repo_esxi_inventory_state($this->db, $this->credentialId)['last_job_id']);

        // Finished system-job retention deletes the row and its log. ON DELETE
        // SET NULL must remove the route in the same operation, otherwise the
        // status card would render a link that can only 404.
        $this->db->query('DELETE FROM deploy_jobs WHERE id = ' . $successfulJob);
        self::assertNull(repo_esxi_inventory_state($this->db, $this->credentialId)['last_job_id']);
        $this->db->query('DELETE FROM deploy_jobs WHERE id = ' . $failedJob);
    }

    private function makeInventoryJob(string $status): int
    {
        $payload = json_encode(['mode' => VIRTUSPHERE_DEPLOY_MODE_INVENTORY], JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json, credential_esxi_id) VALUES (NULL, ?, ?, ?)');
        $stmt->bind_param('ssi', $status, $payload, $this->credentialId);
        $stmt->execute();

        return (int) $this->db->insert_id;
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
        $stmt = $this->db->prepare('DELETE FROM deploy_jobs WHERE credential_esxi_id IN (SELECT id FROM deploy_credentials WHERE name LIKE ?)');
        $stmt->bind_param('s', $like);
        $stmt->execute();
        // Inventory + state cascade from the credential; delete the credential.
        $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }
}
