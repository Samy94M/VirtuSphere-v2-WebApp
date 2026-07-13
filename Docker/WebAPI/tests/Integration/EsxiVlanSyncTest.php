<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/esxi_inventory.php';

/**
 * Paket E chunk 4: the ESXi-owned VLAN catalog sync (ADR-0023). Verifies
 * upsert, un-retire, retire-not-delete, the "no retire without a fresh
 * successful fetch" guarantee and the empty-result guard. Uses a name prefix so
 * only its own catalog rows and credentials are touched, and parks the portgroup
 * cache plus fetch state of foreign credentials for the run: the sync under test
 * is global, so a dev database holding a real ESXi credential would otherwise
 * decide these cases instead of the fixture.
 */
final class EsxiVlanSyncTest extends TestCase
{
    private const PREFIX = 'phpunit_vsync_';

    private ?mysqli $db = null;
    private int $credentialId = 0;
    /** @var array<string, ?string> retired_at of pre-existing (non-prefixed) VLANs */
    private array $catalogSnapshot = [];
    /** @var array<int, array<string, mixed>> portgroup cache rows of foreign credentials */
    private array $networkCacheSnapshot = [];
    /** @var array<int, array<string, mixed>> inventory-state rows of foreign credentials */
    private array $inventoryStateSnapshot = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
        // The global sync under test re-evaluates every catalog row, so it can
        // retire pre-existing dev VLANs as a side effect (this test is the only
        // credential with a fresh fetch). Snapshot their state and restore it in
        // tearDown so a full suite run never corrupts real catalog data.
        $rows = $this->db->query('SELECT vlan_name, retired_at FROM deploy_vlan WHERE vlan_name NOT LIKE "' . self::PREFIX . '%"');
        foreach (repo_fetch_all($rows) as $row) {
            $this->catalogSnapshot[(string) $row['vlan_name']] = $row['retired_at'];
        }
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
        // repo_esxi_vlan_sync() reads the ENTIRE portgroup cache and the ENTIRE
        // state table, never a single credential. A dev database that already
        // holds a real ESXi credential therefore decides the cases below instead
        // of the fixture: its cached portgroup makes the "empty result" set
        // non-empty, and a successful pull of its own satisfies the fresh-fetch
        // check. Park the foreign rows for the run; tearDown puts them back.
        // MUST STAY LAST: this is the only destructive step in setUp, and PHPUnit
        // skips tearDown when setUp throws, so anything fallible has to run first
        // or a crash here would strand the parked rows.
        $this->parkForeignInventory();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
            // Restore pre-existing catalog rows the global sync may have retired.
            $stmt = $this->db->prepare('UPDATE deploy_vlan SET retired_at = ? WHERE vlan_name = ?');
            foreach ($this->catalogSnapshot as $name => $retiredAt) {
                $stmt->bind_param('ss', $retiredAt, $name);
                $stmt->execute();
            }
            // cleanup() already dropped the test credential's own cache and state
            // rows through the ON DELETE CASCADE, so this only puts back what
            // setUp parked.
            $this->restoreForeignInventory();
        }
    }

    /**
     * Moves the portgroup cache and the fetch state of every other credential out
     * of the way, so the global sync sees only what a test set up. Both reads run
     * before either delete, so a failure in between cannot lose a table.
     *
     * The portgroup rows are a cache the portal can re-pull. The state rows are
     * NOT: `failure_streak` and `paused_until_credential_change` are the auth-pause
     * bookkeeping of ADR-0023, and losing them silently un-pauses a credential
     * that was parked after repeated auth failures. They only live in PHP memory
     * between the delete here and the restore in tearDown, so a fatal error or a
     * killed process in that window drops them. deploy_vlan, the durable catalog,
     * is only ever restored, never removed.
     */
    private function parkForeignInventory(): void
    {
        $network = VIRTUSPHERE_INVENTORY_KIND_NETWORK;
        $stmt = $this->db->prepare('SELECT credential_id, name, capacity_bytes, free_bytes, meta_json, fetched_at FROM deploy_esxi_inventory WHERE kind = ?');
        $stmt->bind_param('s', $network);
        $stmt->execute();
        $this->networkCacheSnapshot = repo_fetch_all($stmt->get_result());
        $this->inventoryStateSnapshot = repo_fetch_all($this->db->query(
            'SELECT credential_id, last_success_at, last_attempt_at, last_status, last_error_category, failure_streak, paused_until_credential_change FROM deploy_esxi_inventory_state'
        ));

        $stmt = $this->db->prepare('DELETE FROM deploy_esxi_inventory WHERE kind = ?');
        $stmt->bind_param('s', $network);
        $stmt->execute();
        $this->db->query('DELETE FROM deploy_esxi_inventory_state');
    }

    /**
     * Puts the parked rows back. Both writes are upserts (MySQL 8.4 row alias),
     * not plain inserts, because the parked window is not exclusive: the
     * deploy-worker records inventory success/failure into exactly these two
     * tables, and `deploy_esxi_inventory_state.credential_id` is a primary key
     * while `deploy_esxi_inventory` has a unique key on (credential_id, kind,
     * name). A worker write during the window would make a plain INSERT throw
     * inside tearDown, and the snapshot only lives in PHP memory, so the foreign
     * rows would be gone for good. The parked values win the conflict on purpose:
     * whatever a worker computed while this test had the table emptied was
     * derived from that empty table (a failure_streak restarting at 1, a pause
     * flag reset) and is not a state anyone wants to keep.
     */
    private function restoreForeignInventory(): void
    {
        $network = VIRTUSPHERE_INVENTORY_KIND_NETWORK;
        // A credential deleted during the run took its cache and state with it
        // through ON DELETE CASCADE; re-inserting would only hit the foreign key
        // and abort the loop before the remaining rows are restored.
        $credentials = $this->existingCredentialIds();

        $stmt = $this->db->prepare(
            'INSERT INTO deploy_esxi_inventory (credential_id, kind, name, capacity_bytes, free_bytes, meta_json, fetched_at)
             VALUES (?, ?, ?, ?, ?, ?, ?) AS new
             ON DUPLICATE KEY UPDATE capacity_bytes = new.capacity_bytes, free_bytes = new.free_bytes, meta_json = new.meta_json, fetched_at = new.fetched_at'
        );
        foreach ($this->networkCacheSnapshot as $row) {
            $credentialId = (int) $row['credential_id'];
            if (!isset($credentials[$credentialId])) {
                continue;
            }
            $name = (string) $row['name'];
            $capacity = $row['capacity_bytes'];
            $free = $row['free_bytes'];
            $meta = $row['meta_json'];
            $fetchedAt = $row['fetched_at'];
            $stmt->bind_param('issiiss', $credentialId, $network, $name, $capacity, $free, $meta, $fetchedAt);
            $stmt->execute();
        }
        $this->networkCacheSnapshot = [];

        $stmt = $this->db->prepare(
            'INSERT INTO deploy_esxi_inventory_state (credential_id, last_success_at, last_attempt_at, last_status, last_error_category, failure_streak, paused_until_credential_change)
             VALUES (?, ?, ?, ?, ?, ?, ?) AS new
             ON DUPLICATE KEY UPDATE last_success_at = new.last_success_at, last_attempt_at = new.last_attempt_at, last_status = new.last_status, last_error_category = new.last_error_category, failure_streak = new.failure_streak, paused_until_credential_change = new.paused_until_credential_change'
        );
        foreach ($this->inventoryStateSnapshot as $row) {
            $credentialId = (int) $row['credential_id'];
            if (!isset($credentials[$credentialId])) {
                continue;
            }
            $successAt = $row['last_success_at'];
            $attemptAt = $row['last_attempt_at'];
            $status = $row['last_status'];
            $category = $row['last_error_category'];
            $streak = (int) $row['failure_streak'];
            $paused = (int) $row['paused_until_credential_change'];
            $stmt->bind_param('issssii', $credentialId, $successAt, $attemptAt, $status, $category, $streak, $paused);
            $stmt->execute();
        }
        $this->inventoryStateSnapshot = [];
    }

    /** @return array<int, true> */
    private function existingCredentialIds(): array
    {
        $ids = [];
        foreach (repo_fetch_all($this->db->query('SELECT id FROM deploy_credentials')) as $row) {
            $ids[(int) $row['id']] = true;
        }

        return $ids;
    }

    public function testUpsertUnretireAndRetire(): void
    {
        // Manual catalog: one that will stay (in cache) and one that will retire.
        $this->makeVlan(self::PREFIX . 'keep', null);
        $this->makeVlan(self::PREFIX . 'gone', null);
        $this->makeVlan(self::PREFIX . 'back', '2026-01-01 00:00:00'); // retired, will reappear

        $this->setNetworks([self::PREFIX . 'keep', self::PREFIX . 'back', self::PREFIX . 'new']);
        repo_esxi_inventory_record_success($this->db, $this->credentialId);

        $result = repo_esxi_vlan_sync($this->db);
        self::assertSame('active', $this->vlanStatus(self::PREFIX . 'keep'));
        self::assertSame('active', $this->vlanStatus(self::PREFIX . 'back'));  // un-retired
        self::assertSame('active', $this->vlanStatus(self::PREFIX . 'new'));   // upserted
        self::assertSame('retired', $this->vlanStatus(self::PREFIX . 'gone')); // retired, not deleted
        self::assertGreaterThanOrEqual(1, $result['retired']);
    }

    public function testNoRetireWithoutFreshFetch(): void
    {
        $this->makeVlan(self::PREFIX . 'frozen', null);
        // Cache has a network (frozen), but the last fetch FAILED -> no evidence.
        $this->setNetworks([self::PREFIX . 'other']);
        repo_esxi_inventory_record_failure($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE);

        repo_esxi_vlan_sync($this->db);
        // 'frozen' is absent from the cache but must NOT be retired (no fresh fetch).
        self::assertSame('active', $this->vlanStatus(self::PREFIX . 'frozen'));
    }

    public function testSyncRefreshesReportedCase(): void
    {
        // The catalog carried 'lab', ESXi now reports 'Lab': the upsert must
        // follow the reported spelling (retire/un-retire logic stays ci).
        $this->makeVlan(self::PREFIX . 'lab', null);
        $this->setNetworks([self::PREFIX . 'Lab']);
        repo_esxi_inventory_record_success($this->db, $this->credentialId);

        repo_esxi_vlan_sync($this->db);
        $row = repo_fetch_one($this->db, 'SELECT vlan_name, retired_at FROM deploy_vlan WHERE vlan_name = ? LIMIT 1', 's', [self::PREFIX . 'Lab']);
        self::assertNotNull($row);
        self::assertSame(self::PREFIX . 'Lab', (string) $row['vlan_name']);
        self::assertNull($row['retired_at']);
    }

    public function testCaseChoiceIsDeterministicAcrossSyncs(): void
    {
        // Two credentials report the same name in different cases; the catalog
        // pins one spelling (lowest credential id wins) stable across syncs.
        $secondId = $this->makeCredential('esxi2');
        $this->setNetworks([self::PREFIX . 'Case'], $this->credentialId);
        $this->setNetworks([self::PREFIX . 'case'], $secondId);
        repo_esxi_inventory_record_success($this->db, $this->credentialId);

        repo_esxi_vlan_sync($this->db);
        $first = (string) repo_scalar($this->db, 'SELECT vlan_name FROM deploy_vlan WHERE vlan_name = ? LIMIT 1', 's', [self::PREFIX . 'case']);
        repo_esxi_vlan_sync($this->db);
        $second = (string) repo_scalar($this->db, 'SELECT vlan_name FROM deploy_vlan WHERE vlan_name = ? LIMIT 1', 's', [self::PREFIX . 'case']);

        self::assertSame(self::PREFIX . 'Case', $first);
        self::assertSame($first, $second);
    }

    public function testEmptyResultKeepsCatalog(): void
    {
        $this->makeVlan(self::PREFIX . 'stay', null);
        // Fresh success but zero networks in the cache -> empty-guard, no retire.
        repo_esxi_inventory_record_success($this->db, $this->credentialId);

        repo_esxi_vlan_sync($this->db);
        self::assertSame('active', $this->vlanStatus(self::PREFIX . 'stay'));
    }

    public function testStatusOkWithoutSuccessTimestampIsNoEvidence(): void
    {
        // A state row can carry last_status='ok' without ever having completed a
        // fetch (backfill, hand-edited row). The cache is non-empty here, so only
        // the missing success timestamp may stop the retire branch.
        $this->makeVlan(self::PREFIX . 'stay', null);
        $this->setNetworks([self::PREFIX . 'known']);
        $this->writeStateRow($this->credentialId, null, 'ok');

        $result = repo_esxi_vlan_sync($this->db);

        self::assertSame('active', $this->vlanStatus(self::PREFIX . 'stay'));
        self::assertSame(0, $result['retired']);
    }

    public function testInterruptedRewriteKeepsThePreviousPortgroups(): void
    {
        // replace_kind() is DELETE + INSERT. Without its own transaction it
        // commits the empty middle, and a concurrent repo_esxi_vlan_sync() reads
        // "no portgroups" as positive evidence and retires them. Here the second
        // INSERT dies on the column limit, with no caller transaction to save us.
        $this->setNetworks([self::PREFIX . 'old1', self::PREFIX . 'old2']);
        $poisoned = [
            ['name' => self::PREFIX . 'new'],
            ['name' => str_repeat('x', 300)], // deploy_esxi_inventory.name is varchar(191)
        ];

        try {
            repo_esxi_inventory_replace_kind($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_KIND_NETWORK, $poisoned);
            self::fail('The oversized name must abort the rewrite.');
        } catch (mysqli_sql_exception $exception) {
            self::assertStringContainsString('Data too long', $exception->getMessage());
        }

        self::assertSame([self::PREFIX . 'old1', self::PREFIX . 'old2'], $this->networkNames());
    }

    public function testNestedRewriteRollsBackWithTheOuterTransaction(): void
    {
        // A nested repo_transaction() must join the caller's, not open a second
        // one: MySQL would answer a nested BEGIN by committing the outer.
        $this->setNetworks([self::PREFIX . 'old1']);

        try {
            repo_transaction($this->db, function (): void {
                repo_esxi_inventory_replace_kind($this->db, $this->credentialId, VIRTUSPHERE_INVENTORY_KIND_NETWORK, [['name' => self::PREFIX . 'new']]);
                throw new RuntimeException('caller failed after the rewrite');
            });
            self::fail('The failure must propagate to the caller.');
        } catch (RuntimeException $exception) {
            self::assertSame('caller failed after the rewrite', $exception->getMessage());
        }

        self::assertSame([self::PREFIX . 'old1'], $this->networkNames());
    }

    /** @return array<int, string> cached portgroup names of this test's credential */
    private function networkNames(): array
    {
        $network = VIRTUSPHERE_INVENTORY_KIND_NETWORK;
        $stmt = $this->db->prepare('SELECT name FROM deploy_esxi_inventory WHERE credential_id = ? AND kind = ? ORDER BY name');
        $stmt->bind_param('is', $this->credentialId, $network);
        $stmt->execute();

        return array_map(static fn (array $row): string => (string) $row['name'], repo_fetch_all($stmt->get_result()));
    }

    private function writeStateRow(int $credentialId, ?string $lastSuccessAt, string $status): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_esxi_inventory_state (credential_id, last_success_at, last_attempt_at, last_status, failure_streak, paused_until_credential_change)
             VALUES (?, ?, NOW(), ?, 0, 0) AS new
             ON DUPLICATE KEY UPDATE last_success_at = new.last_success_at, last_status = new.last_status'
        );
        $stmt->bind_param('iss', $credentialId, $lastSuccessAt, $status);
        $stmt->execute();
    }

    private function setNetworks(array $names, ?int $credentialId = null): void
    {
        $credentialId ??= $this->credentialId;
        $this->db->query('DELETE FROM deploy_esxi_inventory WHERE credential_id = ' . $credentialId);
        $items = array_map(static fn (string $n): array => ['name' => $n], $names);
        if ($items !== []) {
            repo_esxi_inventory_replace_kind($this->db, $credentialId, VIRTUSPHERE_INVENTORY_KIND_NETWORK, $items);
        }
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

    private function makeVlan(string $name, ?string $retiredAt): void
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_vlan (vlan_name, retired_at) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $retiredAt);
        $stmt->execute();
    }

    private function vlanStatus(string $name): string
    {
        $row = repo_fetch_one($this->db, 'SELECT retired_at FROM deploy_vlan WHERE vlan_name = ? LIMIT 1', 's', [$name]);
        if ($row === null) {
            return 'missing';
        }

        return $row['retired_at'] === null ? 'active' : 'retired';
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_vlan WHERE vlan_name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }
}
