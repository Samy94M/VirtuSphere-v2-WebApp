<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/esxi_inventory.php';

/**
 * Paket E chunk 2: mission-less system-job enqueue (ADR-0023). Verifies the
 * race guard (no duplicate pending inventory job per ESXi credential) and the
 * Ansible-credential resolution used by the scheduler.
 */
final class EsxiInventoryEnqueueTest extends TestCase
{
    private const PREFIX = 'phpunit_invq_';

    private ?mysqli $db = null;
    private int $esxiId = 0;
    private int $ansibleId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
        $this->esxiId = $this->makeCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443);
        $this->ansibleId = $this->makeCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testSystemJobRaceGuardPreventsDuplicates(): void
    {
        $first = repo_create_system_job($this->db, VIRTUSPHERE_DEPLOY_MODE_INVENTORY, $this->esxiId, $this->ansibleId, null);
        self::assertNotNull($first);
        // A second enqueue while one is still queued returns null (deduped).
        $second = repo_create_system_job($this->db, VIRTUSPHERE_DEPLOY_MODE_INVENTORY, $this->esxiId, $this->ansibleId, null);
        self::assertNull($second);

        $count = (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_jobs WHERE credential_esxi_id = ? AND mission_id IS NULL', 'i', [$this->esxiId]);
        self::assertSame(1, $count);

        // The job is resolvable despite having no mission (LEFT JOIN).
        $job = repo_deploy_job($this->db, (int) $first);
        self::assertNotNull($job);
        self::assertNull($job['mission_id']);
    }

    public function testEnqueueDueSkipsRecentlyAttemptedNeverSucceededCredential(): void
    {
        // Only meaningful with a single ESXi credential, so enqueue_due does not
        // enqueue real due credentials as a side effect.
        $esxiCount = (int) repo_scalar($this->db, "SELECT COUNT(*) FROM deploy_credentials WHERE type = 'esxi'", '', []);
        if ($esxiCount !== 1) {
            self::markTestSkipped('Expected exactly one ESXi credential in the test DB.');
        }
        // A failure just now (never succeeded, not auth-paused): the interval gate
        // must skip it until the interval elapses, instead of re-enqueuing every
        // check cycle (E-1 regression). last_attempt_at is fresh, last_success NULL.
        repo_esxi_inventory_record_failure($this->db, $this->esxiId, VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE);

        $enqueued = esxi_inventory_enqueue_due($this->db);
        self::assertSame(0, $enqueued);
        $pending = (int) repo_scalar(
            $this->db,
            'SELECT COUNT(*) FROM deploy_jobs WHERE credential_esxi_id = ? AND mission_id IS NULL AND status = ?',
            'is',
            [$this->esxiId, VIRTUSPHERE_DEPLOY_STATUS_QUEUED]
        );
        self::assertSame(0, $pending);
    }

    public function testRefreshAllTargetsSkipPausedCredential(): void
    {
        $pausedId = $this->makeCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443, 'paused');
        // An auth failure pauses the credential until it is changed (ADR-0023).
        repo_esxi_inventory_record_failure($this->db, $pausedId, VIRTUSPHERE_INVENTORY_ERROR_AUTH);

        $targets = esxi_inventory_refresh_all_targets($this->db);
        self::assertContains($this->esxiId, $targets['ids']);
        self::assertNotContains($pausedId, $targets['ids']);
        // >= because a shared test DB may hold other paused credentials.
        self::assertGreaterThanOrEqual(1, $targets['skipped_paused']);

        // A non-auth failure must not exclude a credential from the bulk path.
        repo_esxi_inventory_record_failure($this->db, $this->esxiId, VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE);
        $targets = esxi_inventory_refresh_all_targets($this->db);
        self::assertContains($this->esxiId, $targets['ids']);
    }

    public function testEnqueueForCredentialUsesTheSingleAnsibleCredential(): void
    {
        // Only phpunit credentials of each type may exist for this assertion.
        $ansibleCount = (int) repo_scalar($this->db, "SELECT COUNT(*) FROM deploy_credentials WHERE type = 'ansible'", '', []);
        if ($ansibleCount !== 1) {
            self::markTestSkipped('Expected exactly one ansible credential in the test DB.');
        }
        self::assertSame($this->ansibleId, esxi_inventory_ansible_credential_id($this->db));

        $result = esxi_inventory_enqueue_for_credential($this->db, $this->esxiId, null);
        self::assertTrue($result['enqueued']);
    }

    private function makeCredential(string $type, int $port, string $suffix = ''): int
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $name = self::PREFIX . $type . ($suffix !== '' ? '_' . $suffix : '');
        $host = 'host.example.com';
        $user = 'svc';
        $secret = 'x';
        $stmt->bind_param('ssisss', $type, $name, $host, $port, $user, $secret);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        // Jobs referencing the credential first (FK SET NULL would keep orphans).
        $this->db->query("DELETE FROM deploy_jobs WHERE mission_id IS NULL AND credential_esxi_id IN (SELECT id FROM deploy_credentials WHERE name LIKE '" . $this->db->real_escape_string($like) . "')");
        $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }
}
