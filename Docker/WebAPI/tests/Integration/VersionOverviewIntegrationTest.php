<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/version_overview.php';
require_once dirname(__DIR__, 2) . '/lib/repo/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/repo/ansible_preflight.php';

/**
 * The Version tab's read model against the live database. Each MECM row is judged
 * from the report its task stored, the Ansible row from the preflight the portal
 * recorded, and the database row from the migrations the code defines.
 *
 * The four report sources hold the dev stack's real heartbeats. They are read
 * first, replaced by the fixtures below and restored exactly afterwards, the same
 * rule MecmReportWireTest follows.
 */
final class VersionOverviewIntegrationTest extends TestCase
{
    private const CREDENTIAL_NAME = 'phpunit_version_overview';
    private const CREDENTIAL_HOST = 'ansible-version.example.test';

    private mysqli $db;
    private bool $snapshotTaken = false;
    /** @var list<array<string,mixed>> */
    private array $savedHeartbeats = [];
    private int $credentialId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }

        $this->savedHeartbeats = $this->readHeartbeats();
        $this->snapshotTaken = true;
        $this->deleteHeartbeats();
        $this->cleanupCredential();

        $actor = (int) ($this->db->query('SELECT id FROM deploy_users ORDER BY id LIMIT 1')->fetch_assoc()['id'] ?? 0);
        if ($actor <= 0) {
            self::markTestSkipped('No user exists for credential provenance.');
        }

        $release = portal_release();
        if ($release === null) {
            self::fail('lib/VERSION must hold the release this checkout is built as');
        }
        $this->seedHeartbeat(VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC, 'device-sync/2.1+' . $release);
        $this->seedHeartbeat(VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC, 'packages-sync/2.0+' . $release . '-abw');
        $this->seedHeartbeat(VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER, 'autoimporter/2.2+2000.01.1');
        // The site-health reporter gets no row at all: that is the "never reported" case.

        $this->credentialId = repo_create_credential($this->db, $this->credentialPayload(), 'secret-version-overview', $actor);
        $revision = (int) repo_credential($this->db, $this->credentialId)['config_revision'];
        $generation = repo_ansible_preflight_begin($this->db, $this->credentialId, $revision);
        self::assertTrue(repo_ansible_preflight_record(
            $this->db,
            $this->credentialId,
            VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK,
            null,
            $revision,
            $generation
        ));
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }

        $this->cleanupCredential();
        // Without a snapshot there is nothing to restore, and deleting the live
        // rows here would destroy the state this test was meant to protect.
        if (!$this->snapshotTaken) {
            return;
        }

        $this->deleteHeartbeats();
        foreach ($this->savedHeartbeats as $row) {
            $this->insertHeartbeat($row);
        }
    }

    public function testEachMecmRowIsJudgedFromItsOwnReport(): void
    {
        $mecm = version_overview($this->db)['mecm'];

        self::assertSame(VIRTUSPHERE_INTEGRATION_RUN_SOURCES, array_keys($mecm));

        $deviceSync = $mecm[VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC];
        self::assertSame(VIRTUSPHERE_VERSION_STATE_OK, $deviceSync['state']);
        self::assertNull($deviceSync['reason']);
        self::assertSame(portal_release(), $deviceSync['release']);
        self::assertNotNull($deviceSync['reported_at']);

        $packagesSync = $mecm[VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC];
        self::assertSame(VIRTUSPHERE_VERSION_STATE_DEVIATES, $packagesSync['state']);
        self::assertSame('files', $packagesSync['reason']);

        $autoimporter = $mecm[VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER];
        self::assertSame(VIRTUSPHERE_VERSION_STATE_DEVIATES, $autoimporter['state']);
        self::assertSame('release', $autoimporter['reason']);
        self::assertSame('2000.01.1', $autoimporter['release']);

        $siteHealth = $mecm[VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH];
        self::assertSame(VIRTUSPHERE_VERSION_STATE_UNKNOWN, $siteHealth['state']);
        self::assertSame('no_report', $siteHealth['reason']);
        self::assertNull($siteHealth['reported_at']);
    }

    public function testPortalAndDatabaseAreTheReference(): void
    {
        $overview = version_overview($this->db);
        $migrations = release_code_migrations();

        self::assertSame(portal_release(), $overview['portal']['release']);
        self::assertSame(PHP_VERSION, $overview['runtime']['php']);
        self::assertNotSame([], $migrations);
        self::assertSame(VIRTUSPHERE_VERSION_STATE_OK, $overview['database']['state']);
        self::assertSame(0, $overview['database']['pending']);
        self::assertSame(end($migrations), $overview['database']['last_applied']);
    }

    public function testAnsibleHostWithCurrentPreflightIsOk(): void
    {
        $hosts = array_values(array_filter(
            version_overview($this->db)['ansible_hosts'],
            fn (array $host): bool => $host['id'] === $this->credentialId
        ));

        self::assertCount(1, $hosts);
        self::assertSame(self::CREDENTIAL_NAME, $hosts[0]['name']);
        self::assertSame(VIRTUSPHERE_VERSION_STATE_OK, $hosts[0]['state']);
        self::assertNull($hosts[0]['reason']);
        self::assertNotNull($hosts[0]['checked_at']);
    }

    /** @return array<string,mixed> */
    private function credentialPayload(): array
    {
        return [
            'type' => VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE,
            'name' => self::CREDENTIAL_NAME,
            'host' => self::CREDENTIAL_HOST,
            'port' => 22,
            'username' => 'svc-ansible',
        ];
    }

    private function cleanupCredential(): void
    {
        $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE name = ?');
        $name = self::CREDENTIAL_NAME;
        $stmt->bind_param('s', $name);
        $stmt->execute();
    }

    private function seedHeartbeat(string $source, string $scriptVersion): void
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_integration_heartbeats (source, last_seen_at, last_script_version) VALUES (?, NOW(), ?)');
        $stmt->bind_param('ss', $source, $scriptVersion);
        $stmt->execute();
    }

    /** @return list<array<string,mixed>> */
    private function readHeartbeats(): array
    {
        $sources = VIRTUSPHERE_INTEGRATION_RUN_SOURCES;
        // SELECT *, never a column list: a restore must put back every column the
        // table holds, including ones added after this test was written.
        $stmt = $this->db->prepare('SELECT * FROM deploy_integration_heartbeats WHERE source IN (' . implode(',', array_fill(0, count($sources), '?')) . ')');
        $stmt->bind_param(str_repeat('s', count($sources)), ...$sources);
        $stmt->execute();

        $result = $stmt->get_result();
        $rows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function deleteHeartbeats(): void
    {
        $sources = VIRTUSPHERE_INTEGRATION_RUN_SOURCES;
        $stmt = $this->db->prepare('DELETE FROM deploy_integration_heartbeats WHERE source IN (' . implode(',', array_fill(0, count($sources), '?')) . ')');
        $stmt->bind_param(str_repeat('s', count($sources)), ...$sources);
        $stmt->execute();
    }

    /** @param array<string,mixed> $row a row as SELECT * returned it */
    private function insertHeartbeat(array $row): void
    {
        $columns = array_keys($row);
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_integration_heartbeats (' . implode(',', $columns) . ') '
            . 'VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')'
        );
        $stmt->bind_param(str_repeat('s', count($columns)), ...array_values($row));
        $stmt->execute();
    }
}
