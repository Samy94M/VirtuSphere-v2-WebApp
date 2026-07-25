<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/esxi_inventory.php';
require_once dirname(__DIR__, 2) . '/lib/repo/esxi_inventory.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';

/**
 * The database half of the picker's option source, and the disjointness of the
 * two deploy warning boxes.
 *
 * Both were untested. The pure helpers underneath had cover, but nothing proved
 * that what the repository reads actually reaches them: whether the credential
 * host arrives for the bucket label, whether a maintenance meta survives the
 * round trip into the "no usable free space" flag, whether the proof denominator
 * is the credentials that pulled rather than the ones that hold rows. And the
 * disjointness of the two warning boxes was asserted "by construction" in a
 * comment and nowhere else, while its whole point is that an operator never
 * reads one value in two boxes with two different explanations.
 *
 * Scoped to this test's own credentials throughout: the option source spans the
 * whole table by design, so a shared development database contributes rows this
 * test neither owns nor may assert about. The global flags (`exact`, the
 * credential count) are therefore pinned in EsxiInventoryOptionFlagsTest, where
 * the inputs are constructed rather than found.
 */
final class EsxiInventoryOptionsTest extends TestCase
{
    private const PREFIX = 'phpunit_invopt_';

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

    public function testTheGroupsCarryTheCredentialHostAndTheFreeSpaceOfEachRow(): void
    {
        $id = $this->credential('solo', '10.0.5.11');
        $this->datastores($id, ['ds-a' => 400, 'ds-b' => null]);

        $group = $this->ownGroups()[$id];

        self::assertSame(self::PREFIX . 'solo', $group['credential_name']);
        self::assertSame('10.0.5.11', $group['credential_host'], 'the address is what makes a fleet of "esxi1".."esxi6" usable');
        self::assertSame(['ds-a', 'ds-b'], $group['names']);
        self::assertSame(400, $group['free_by_key']['ds-a']);
        self::assertNull($group['free_by_key']['ds-b'], 'a row without a number stays without one');
        self::assertSame([], $group['unusable_keys']);
    }

    public function testOnlyACompletedPullCountsAsProof(): void
    {
        // The proof denominator is "has ever pulled", not "holds rows of this
        // kind": a host that pulled and genuinely has no datastore still belongs
        // in the count, or every datastore of the others is promoted to "present
        // everywhere" while a whole host is missing from the denominator.
        $proven = $this->credential('proven', '10.0.5.11');
        $silent = $this->credential('silent', '10.0.5.12');
        $emptyButPulled = $this->credential('pulled-empty', '10.0.5.13');
        $this->datastores($proven, ['ds-a' => 100]);
        $this->recordSuccess($proven);
        $this->recordSuccess($emptyButPulled);

        $eligible = repo_esxi_inventory_pulled_credential_ids($this->db);

        self::assertContains($proven, $eligible);
        self::assertContains($emptyButPulled, $eligible, 'a successful pull with no rows of this kind still counts');
        self::assertNotContains($silent, $eligible, 'a credential that was never pulled cannot prove what it has');

        // Two proven credentials, one of which reports no datastore: ds-a exists
        // on exactly one of them, so it is an "only on ..." entry and choosing
        // it commits the host choice. Counting groups instead of proven pulls
        // would have made it "present everywhere" with one host unaccounted for.
        $buckets = esxi_inventory_presence_buckets(array_values($this->ownGroups()), 2);
        self::assertSame([['only', ['ds-a']]], $this->scopes($buckets));
        self::assertSame([self::PREFIX . 'proven'], array_column($buckets[0]['credentials'], 'name'));
    }

    public function testAMixedFleetBucketsBySharedAndLocalAndNamesTheHost(): void
    {
        $a = $this->credential('esxi-a', '10.0.5.11');
        $b = $this->credential('esxi-b', '10.0.5.12');
        $this->datastores($a, ['ds-shared' => 900, 'ds-local-a' => 300]);
        $this->datastores($b, ['ds-shared' => 400]);
        $this->recordSuccess($a);
        $this->recordSuccess($b);

        $buckets = esxi_inventory_presence_buckets(array_values($this->ownGroups()), 2);

        self::assertSame([['all', ['ds-shared']], ['only', ['ds-local-a']]], $this->scopes($buckets));
        self::assertSame(400, $buckets[0]['free_by_key']['ds-shared'], 'the tightest host wins while the target is open');
        self::assertSame([self::PREFIX . 'esxi-a'], array_column($buckets[1]['credentials'], 'name'));
        self::assertSame('10.0.5.11', $buckets[1]['credentials'][0]['host']);
    }

    public function testAMaintenanceMetaSurvivesTheRoundTripAndWithdrawsTheNumber(): void
    {
        $id = $this->credential('maint', '10.0.5.11');
        $this->datastores($id, ['ds-work' => 500, 'ds-fine' => 700], ['ds-work' => ['maintenance' => 'inMaintenance']]);
        $this->recordSuccess($id);

        $group = $this->ownGroups()[$id];

        self::assertSame(['ds-work' => true], $group['unusable_keys']);
        self::assertNull($group['free_by_key']['ds-work'], 'a size in maintenance is not space anybody can deploy onto');
        self::assertSame(700, $group['free_by_key']['ds-fine'], 'and the sibling keeps its number');

        // The same fact through the deploy table's reader.
        $capacity = deploy_datastore_capacity_map($this->db, [$id])[$id];
        self::assertNull($capacity['ds-work']['free']);
        self::assertTrue($capacity['ds-work']['unusable']);
        self::assertSame('unknown', deploy_storage_state(1, $capacity['ds-work']['free']));
    }

    public function testTheBulkNameSetsReplaceThePerCredentialReads(): void
    {
        // The B6 shape: one query for every credential and every kind the deploy
        // page compares, instead of a full inventory read inside a loop.
        $a = $this->credential('bulk-a', '10.0.5.11');
        $b = $this->credential('bulk-b', '10.0.5.12');
        $this->datastores($a, ['DataStore-A' => 1]);
        $this->networks($a, [self::PREFIX . 'vlan-a']);
        $this->networks($b, [self::PREFIX . 'vlan-b']);

        $sets = repo_esxi_inventory_name_sets_by_credential($this->db, [
            VIRTUSPHERE_INVENTORY_KIND_DATASTORE,
            VIRTUSPHERE_INVENTORY_KIND_NETWORK,
        ]);

        self::assertSame(['datastore-a' => true], $sets[$a][VIRTUSPHERE_INVENTORY_KIND_DATASTORE], 'keys go through esxi_inventory_name_key()');
        self::assertArrayHasKey(esxi_inventory_name_key(self::PREFIX . 'vlan-a'), $sets[$a][VIRTUSPHERE_INVENTORY_KIND_NETWORK]);
        self::assertArrayNotHasKey(VIRTUSPHERE_INVENTORY_KIND_DATASTORE, $sets[$b], 'a credential without rows of a kind carries no set for it');
        self::assertArrayNotHasKey(VIRTUSPHERE_INVENTORY_KIND_HOST, $sets[$a], 'only the requested kinds are read');
    }

    public function testTheTwoDeployWarningsNeverNameTheSameValue(): void
    {
        // The disjointness rule: a value missing from EVERY host belongs to the
        // mission-level deviation warning, a value missing from ONE host to the
        // per-host box. Naming it in both would make the operator read two
        // different explanations of one problem and act on the wrong one.
        $a = $this->credential('esxi-a', '10.0.5.11');
        $b = $this->credential('esxi-b', '10.0.5.12');
        $this->datastores($a, [self::PREFIX . 'ds-only-a' => 100]);
        $this->datastores($b, [self::PREFIX . 'ds-shared' => 100]);
        $this->networks($a, [self::PREFIX . 'vlan-known']);
        $this->networks($b, [self::PREFIX . 'vlan-known']);
        $this->recordSuccess($a);
        $this->recordSuccess($b);

        $missionId = repo_create_mission($this->db, [
            'mission_name' => self::PREFIX . 'm',
            'wds_vlan' => self::PREFIX . 'vlan-nowhere',
            'hypervisor_datastorage' => self::PREFIX . 'ds-only-a',
            'hypervisor_datacenter' => '',
            'domain' => 'dc.example.com',
        ]);

        $perHost = esxi_inventory_mission_missing_by_credential($this->db, $missionId);
        $union = [];
        foreach (esxi_inventory_mission_deviations($this->db, true) as $entry) {
            if ((int) $entry['mission_id'] === $missionId) {
                foreach ($entry['issues'] as $issue) {
                    $union[] = (string) $issue['value'];
                }
            }
        }

        self::assertSame([self::PREFIX . 'ds-only-a'], $perHost[$b] ?? [], 'host B is one that cannot serve this datastore');
        self::assertArrayNotHasKey($a, $perHost, 'host A has it, so it has nothing to warn about');
        self::assertSame([self::PREFIX . 'vlan-nowhere'], $union, 'the VLAN exists nowhere, so only the union warning names it');
        self::assertSame([], array_intersect($union, $perHost[$b] ?? []), 'the two boxes must never name the same value');
    }

    /** @param array<int, array<string, mixed>> $buckets */
    private function scopes(array $buckets): array
    {
        return array_map(static fn (array $bucket): array => [$bucket['scope'], $bucket['names']], $buckets);
    }

    /**
     * Datastore groups of this test's credentials only. The real option source
     * spans the whole table, which is exactly why a shared database cannot be
     * asserted against wholesale.
     *
     * @return array<int, array<string, mixed>> credential id => group
     */
    private function ownGroups(): array
    {
        $groups = [];
        foreach (repo_esxi_inventory_names_by_credential($this->db, VIRTUSPHERE_INVENTORY_KIND_DATASTORE) as $group) {
            if (str_starts_with((string) $group['credential_name'], self::PREFIX)) {
                $groups[(int) $group['credential_id']] = $group;
            }
        }

        return $groups;
    }

    private function credential(string $suffix, string $host): int
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $type = VIRTUSPHERE_CREDENTIAL_TYPE_ESXI;
        $name = self::PREFIX . $suffix;
        $port = 443;
        $user = 'svc';
        $secret = 'x';
        // (type, name, host, port, username, secret): the host is a string and
        // this test asserts on it, so the types have to line up with the columns.
        $stmt->bind_param('sssiss', $type, $name, $host, $port, $user, $secret);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    /**
     * @param array<string, ?int> $freeByName
     * @param array<string, array<string, mixed>> $metaByName
     */
    private function datastores(int $credentialId, array $freeByName, array $metaByName = []): void
    {
        $items = [];
        foreach ($freeByName as $name => $free) {
            $items[] = [
                'name' => (string) $name,
                'capacity_bytes' => 1000,
                'free_bytes' => $free,
                'meta_json' => $metaByName[$name] ?? null,
            ];
        }
        repo_esxi_inventory_replace_kind($this->db, $credentialId, VIRTUSPHERE_INVENTORY_KIND_DATASTORE, $items);
    }

    /** @param array<int, string> $names */
    private function networks(int $credentialId, array $names): void
    {
        repo_esxi_inventory_replace_kind($this->db, $credentialId, VIRTUSPHERE_INVENTORY_KIND_NETWORK, repo_esxi_inventory_name_items($names));
    }

    private function recordSuccess(int $credentialId): void
    {
        repo_esxi_inventory_record_success($this->db, $credentialId);
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
