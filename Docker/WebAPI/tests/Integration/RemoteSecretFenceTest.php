<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/repo/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/repo/credential_host_identity.php';
require_once dirname(__DIR__, 2) . '/lib/network_mac_constants.php';

/**
 * The fence of the ESXi access file on its Ansible credential (K21-F).
 *
 * A row of deploy_remote_secret_files means accounts.yml may still be on the host
 * the credential points at. While a row exists, the credential cannot be deleted
 * and its target (type, host, effective SSH port, pinned host key) cannot change.
 * The credential's own access data (password, username, name) can still be
 * repaired on the same target. repo_remote_secret_acknowledge() is the one
 * logged way out: it closes the rows of a credential and target that an operator
 * has established to be gone, with one audit row.
 *
 * Runs against a real MySQL server, like K21RemoteSecretCleanupTest. No host is
 * ever contacted. The tests that call repo_remote_secret_acknowledge() first assert
 * that it exists, so a missing module fails those tests by name.
 */
final class RemoteSecretFenceTest extends TestCase
{
    private const ACK_EVENT = 'deploy.remote_secret_acknowledged';
    private const ACK_FUNCTION = 'repo_remote_secret_acknowledge';
    private const WORKER = 'phpunit-k21f';
    private const PORT = 22;

    private mysqli $db;
    private string $token = '';
    private string $ciphertext = '';
    private int $userId = 0;
    private int $missionId = 0;
    private int $esxiId = 0;
    private int $ansibleId = 0;
    private int $otherAnsibleId = 0;
    /** @var list<int> */
    private array $missionIds = [];
    /** @var list<int> */
    private array $credentialIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        // The module is loaded when it exists. Until then the tests that need it fail by name.
        $module = dirname(__DIR__, 2) . '/lib/repo/deploy_remote_secret_fence.php';
        if (is_file($module)) {
            require_once $module;
        }

        $this->token = bin2hex(random_bytes(5));
        $this->ciphertext = 'K21F-CIPHERTEXT-' . $this->token;
        $this->userId = (int) (($this->db->query('SELECT id FROM deploy_users ORDER BY id LIMIT 1')->fetch_assoc()['id'] ?? 0));
        self::assertGreaterThan(0, $this->userId, 'The synthetic QA user is required.');

        $this->missionId = $this->insertMission();
        $this->esxiId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443, 'esxi');
        $this->ansibleId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, self::PORT, 'ansible');
        $this->otherAnsibleId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, self::PORT, 'ansible-other');
        $this->pointCredentialAt($this->ansibleId, $this->hostFor('ansible'), self::fingerprintFor('ansible-a'));
        $this->pointCredentialAt($this->otherAnsibleId, $this->hostFor('ansible-other'), self::fingerprintFor('other-a'));
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        repo_execute($this->db, 'DELETE FROM deploy_remote_secret_files WHERE remote_dir LIKE ?', 's', [$this->remoteRoot() . '%']);
        repo_execute($this->db, 'DELETE FROM deploy_logs WHERE event_code = ? AND context_json LIKE ?', 'ss', [self::ACK_EVENT, '%' . $this->token . '%']);
        // Deleting a mission cascades its jobs.
        foreach ($this->missionIds as $missionId) {
            repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$missionId]);
        }
        foreach ($this->credentialIds as $credentialId) {
            // The host identity confirmation writes credential audit rows that carry no token.
            repo_execute($this->db, "DELETE FROM deploy_logs WHERE object_type = 'credential' AND object_id = ?", 's', [(string) $credentialId]);
            repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$credentialId]);
        }
    }

    /** A refused delete of an Ansible credential with an open row keeps both the credential and the row. */
    public function testDeletingAnAnsibleCredentialWithAnOpenRowIsRefusedAndKeepsCredentialAndRow(): void
    {
        $this->seedRow($this->finishedJob($this->ansibleId), $this->ansibleId);
        $rows = $this->remoteRows($this->ansibleId);
        self::assertCount(1, $rows, 'fixture: the open row');

        $this->expectRefusal(fn (): bool => repo_delete_credential($this->db, $this->ansibleId));

        self::assertNotNull(repo_credential($this->db, $this->ansibleId), 'a refused delete keeps the credential');
        self::assertSame($rows, $this->remoteRows($this->ansibleId), 'a refused delete keeps the row');
    }

    /** A refused host change writes nothing: the credential row and the open row stay as they were. */
    public function testChangingTheHostWithAnOpenRowIsRefusedAndWritesNothing(): void
    {
        $this->seedRow($this->finishedJob($this->ansibleId), $this->ansibleId);
        $credential = repo_credential($this->db, $this->ansibleId);
        $rows = $this->remoteRows($this->ansibleId);

        $this->expectRefusal(fn (): bool => repo_update_credential(
            $this->db,
            $this->ansibleId,
            $this->payloadFor($credential, ['host' => $this->hostFor('moved')]),
            null
        ));

        self::assertSame($credential, repo_credential($this->db, $this->ansibleId));
        self::assertSame($rows, $this->remoteRows($this->ansibleId));
    }

    /** A refused port change writes nothing: the effective SSH port is part of the target. */
    public function testChangingThePortWithAnOpenRowIsRefusedAndWritesNothing(): void
    {
        $this->seedRow($this->finishedJob($this->ansibleId), $this->ansibleId);
        $credential = repo_credential($this->db, $this->ansibleId);
        $rows = $this->remoteRows($this->ansibleId);

        $this->expectRefusal(fn (): bool => repo_update_credential(
            $this->db,
            $this->ansibleId,
            $this->payloadFor($credential, ['port' => 2222]),
            null
        ));

        self::assertSame($credential, repo_credential($this->db, $this->ansibleId));
        self::assertSame($rows, $this->remoteRows($this->ansibleId));
    }

    /** A refused type change writes nothing: an Ansible credential that becomes an ESXi one leaves its file behind. */
    public function testChangingTheTypeWithAnOpenRowIsRefusedAndWritesNothing(): void
    {
        $this->seedRow($this->finishedJob($this->ansibleId), $this->ansibleId);
        $credential = repo_credential($this->db, $this->ansibleId);
        $rows = $this->remoteRows($this->ansibleId);

        $this->expectRefusal(fn (): bool => repo_update_credential(
            $this->db,
            $this->ansibleId,
            $this->payloadFor($credential, ['type' => VIRTUSPHERE_CREDENTIAL_TYPE_ESXI]),
            null
        ));

        self::assertSame($credential, repo_credential($this->db, $this->ansibleId));
        self::assertSame($rows, $this->remoteRows($this->ansibleId));
    }

    /** Confirming a DIFFERENT host key is refused while a row exists; the pin and the row stay. */
    public function testConfirmingAnotherHostKeyWithAnOpenRowIsRefused(): void
    {
        $this->seedRow($this->finishedJob($this->ansibleId), $this->ansibleId);
        $credential = repo_credential($this->db, $this->ansibleId);
        $rows = $this->remoteRows($this->ansibleId);

        $this->expectRefusal(function () use ($credential): void {
            repo_confirm_ansible_host_identity(
                $this->db,
                $this->ansibleId,
                (int) $credential['config_revision'],
                (string) $credential['ansible_host_fingerprint'],
                self::fingerprintFor('ansible-b'),
                'ssh-ed25519',
                $this->userId
            );
        });

        self::assertSame(self::fingerprintFor('ansible-a'), repo_credential($this->db, $this->ansibleId)['ansible_host_fingerprint']);
        self::assertSame($rows, $this->remoteRows($this->ansibleId));
    }

    /** Repairing password, username and name on the same target stays allowed while a row exists. */
    public function testRepairingPasswordUsernameOrNameOnTheSameTargetIsAllowed(): void
    {
        $this->seedRow($this->finishedJob($this->ansibleId), $this->ansibleId);
        $credential = repo_credential($this->db, $this->ansibleId);
        $rows = $this->remoteRows($this->ansibleId);
        $newSecret = 'K21F-repaired-' . $this->token;

        $saved = repo_update_credential(
            $this->db,
            $this->ansibleId,
            $this->payloadFor($credential, ['name' => $credential['name'] . '_repaired', 'username' => 'svc-repaired']),
            $newSecret
        );

        self::assertTrue($saved);
        $after = repo_credential($this->db, $this->ansibleId);
        self::assertSame($credential['name'] . '_repaired', $after['name']);
        self::assertSame('svc-repaired', $after['username']);
        self::assertSame($credential['host'], $after['host']);
        self::assertSame($credential['ansible_host_fingerprint'], $after['ansible_host_fingerprint'], 'the pinned key is untouched');
        self::assertSame($newSecret, repo_credential_secret($this->db, $this->ansibleId));
        self::assertSame($rows, $this->remoteRows($this->ansibleId));
    }

    /** Control: without a row, the host change and the delete are allowed. */
    public function testWithoutAnOpenRowChangingTheHostAndDeletingAreAllowed(): void
    {
        $controlId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, self::PORT, 'control');
        $credential = repo_credential($this->db, $controlId);

        self::assertTrue(repo_update_credential($this->db, $controlId, $this->payloadFor($credential, ['host' => $this->hostFor('control-moved')]), null));
        self::assertTrue(repo_delete_credential($this->db, $controlId));
        self::assertNull(repo_credential($this->db, $controlId));
    }

    /** A row of another Ansible credential does not block this credential. */
    public function testARowOfAnotherCredentialDoesNotBlockThisCredential(): void
    {
        $this->seedRow($this->finishedJob($this->otherAnsibleId), $this->otherAnsibleId);
        $otherRows = $this->remoteRows($this->otherAnsibleId);

        self::assertTrue(repo_update_credential(
            $this->db,
            $this->ansibleId,
            $this->payloadFor(repo_credential($this->db, $this->ansibleId), ['host' => $this->hostFor('moved')]),
            null
        ));
        self::assertTrue(repo_delete_credential($this->db, $this->ansibleId));
        self::assertNull(repo_credential($this->db, $this->ansibleId));
        self::assertSame($otherRows, $this->remoteRows($this->otherAnsibleId), 'the other credential keeps its row');
    }

    /** The refusal names the number of open rows and the host and port, and no secret. */
    public function testTheRefusalNamesTheCountAndTheHostPortAndNoSecret(): void
    {
        $this->seedRow($this->finishedJob($this->ansibleId), $this->ansibleId);
        $this->seedRow($this->finishedJob($this->ansibleId), $this->ansibleId);

        $exception = $this->expectRefusal(fn (): bool => repo_delete_credential($this->db, $this->ansibleId));

        $message = $exception->getMessage();
        self::assertMatchesRegularExpression('/(?<![\w.:-])2(?![\w.:-])/', $message, 'the message names the count of open rows');
        self::assertStringContainsString($this->hostFor('ansible') . ':' . self::PORT, $message, 'the message names host and port');
        self::assertStringNotContainsString($this->ciphertext, $message, 'the message carries no secret ciphertext');
        foreach ($exception->errors() as $value) {
            self::assertStringNotContainsString($this->ciphertext, $value);
        }
    }

    /** The refusal offers an action with a permission, like the create fence does. */
    public function testTheRefusalOffersAnActionWithAPermission(): void
    {
        $this->seedRow($this->finishedJob($this->ansibleId), $this->ansibleId);

        $exception = $this->expectRefusal(fn (): bool => repo_delete_credential($this->db, $this->ansibleId));

        $action = $exception->portalAction();
        self::assertIsArray($action, 'the refusal carries an action');
        self::assertSame('credentials.manage', $action['permission'] ?? null);
        self::assertSame('credentials.php#remote-secret-' . $this->ansibleId, $action['url'] ?? null, 'the action opens the credential row');
        self::assertSame('credentials.remote_secret_open_action', $action['label_key'] ?? null);
    }

    /** Acknowledging closes the rows of this credential and target, writes one audit row, and then allows the delete. */
    public function testAcknowledgeClosesTheRowsOfTheCredentialAndTargetWritesOneAuditRowAndAllowsDeletion(): void
    {
        $this->seedRow($this->finishedJob($this->ansibleId), $this->ansibleId);
        $this->seedRow($this->finishedJob($this->ansibleId), $this->ansibleId);
        $reason = 'Host ausser Betrieb, Datei von Hand entfernt (' . $this->token . ')';

        $closed = $this->acknowledge($this->ansibleId, $this->hostFor('ansible'), $reason);

        self::assertSame(2, $closed);
        self::assertSame([], $this->remoteRows($this->ansibleId));
        $audit = $this->acknowledgedRows();
        self::assertCount(1, $audit, 'exactly one audit row for one acknowledgement');
        self::assertSame('credential', $audit[0]['object_type']);
        self::assertSame((string) $this->ansibleId, (string) $audit[0]['object_id']);
        self::assertSame('success', $audit[0]['result']);
        $context = json_decode((string) $audit[0]['context_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $context['count']);
        self::assertSame($this->hostFor('ansible'), $context['host']);
        self::assertSame(self::PORT, $context['port']);
        self::assertSame($reason, $context['reason']);
        self::assertSame($this->ansibleId, $context['credential_id']);
        self::assertIsString($context['oldest']);
        self::assertNotSame('', $context['oldest']);
        self::assertTrue(repo_delete_credential($this->db, $this->ansibleId), 'after the acknowledgement the delete is allowed');
    }

    /** Acknowledging closes only the rows that point at the given target; the old target is closed by its own acknowledgement. */
    public function testAcknowledgeClosesOnlyTheRowsOfTheGivenTarget(): void
    {
        $this->seedRow($this->finishedJob($this->ansibleId), $this->ansibleId);
        $previousHost = 'previous-' . $this->token . '.example.invalid';
        $oldJob = $this->finishedJob($this->ansibleId);
        $this->seedRowOnTarget($oldJob, $this->ansibleId, $previousHost, self::PORT, self::fingerprintFor('previous'));

        self::assertSame(1, $this->acknowledge($this->ansibleId, $this->hostFor('ansible'), 'Ziel geprüft (' . $this->token . ')'));
        self::assertSame([$oldJob], $this->remoteJobIds($this->ansibleId), 'the row of the old target stays');

        self::assertSame(1, $this->acknowledge($this->ansibleId, $previousHost, 'Altes Ziel geprüft (' . $this->token . ')'));
        self::assertSame([], $this->remoteRows($this->ansibleId));
    }

    /** An empty or blank reason is refused on the field reason, and nothing is closed or written. */
    public function testAnEmptyOrBlankReasonIsRefusedOnTheReasonFieldAndClosesNothing(): void
    {
        $this->seedRow($this->finishedJob($this->ansibleId), $this->ansibleId);
        $rows = $this->remoteRows($this->ansibleId);

        foreach (['', " \t  ", 'kurz'] as $reason) {
            $exception = $this->expectRefusal(fn (): int => $this->acknowledge($this->ansibleId, $this->hostFor('ansible'), $reason));
            self::assertArrayHasKey('reason', $exception->errors());
        }

        self::assertSame($rows, $this->remoteRows($this->ansibleId));
        self::assertCount(0, $this->acknowledgedRows());
    }

    /** A row under a live lease and a row of an active job stay open; only the finished row closes. */
    public function testRowsUnderALiveLeaseAndRowsOfActiveJobsStayOpen(): void
    {
        $finished = $this->finishedJob($this->ansibleId);
        $this->seedRow($finished, $this->ansibleId);
        $leased = $this->finishedJob($this->ansibleId);
        $this->seedRow($leased, $this->ansibleId);
        repo_execute($this->db, 'UPDATE deploy_remote_secret_files SET lease_until = DATE_ADD(NOW(6), INTERVAL 1 HOUR) WHERE job_id = ?', 'i', [$leased]);
        $running = $this->runningJob($this->ansibleId);
        $this->seedRow($running, $this->ansibleId);

        self::assertSame(1, $this->acknowledge($this->ansibleId, $this->hostFor('ansible'), 'Nur abgeschlossene Aufträge (' . $this->token . ')'));

        self::assertSame([$leased, $running], $this->remoteJobIds($this->ansibleId));
    }

    /** A row whose credential was deleted (credential NULL) is closed through credentialId null. */
    public function testAcknowledgeWithoutCredentialClosesTheOrphanedRows(): void
    {
        $orphanId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, self::PORT, 'orphan');
        $orphanHost = $this->hostFor('orphan');
        $this->pointCredentialAt($orphanId, $orphanHost, self::fingerprintFor('orphan'));
        $this->seedRow($this->finishedJob($orphanId), $orphanId);
        // A raw delete, because the fence refuses a delete of this credential. The
        // foreign key of the row sets its credential to NULL, as it does for a row
        // written before the fence existed.
        repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$orphanId]);
        self::assertCount(1, array_filter($this->remoteRows(null), static fn (array $row): bool => $row['target_host'] === $orphanHost));

        self::assertSame(1, $this->acknowledge(null, $orphanHost, 'Zugang geloescht, Host ausser Betrieb (' . $this->token . ')'));

        self::assertCount(0, array_filter($this->remoteRows(null), static fn (array $row): bool => $row['target_host'] === $orphanHost));
        $audit = $this->acknowledgedRows();
        self::assertCount(1, $audit);
        self::assertSame('0', (string) $audit[0]['object_id'], 'a deleted credential is addressed by 0');
        $context = json_decode((string) $audit[0]['context_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $context['credential_id']);
    }

    /**
     * An overlong reason is bounded, never refused. The audit field "reason" keeps 256 bytes, so the
     * stored copy is the first 256 bytes of the input, cut at a character boundary, as valid UTF-8.
     */
    public function testAnOverlongReasonIsStoredBoundedAndNotRefused(): void
    {
        $this->seedRow($this->finishedJob($this->ansibleId), $this->ansibleId);
        // The token leads, so the bounded copy still names this test's audit row.
        $reason = $this->token . ' ' . str_repeat('ä', 400);

        self::assertSame(1, $this->acknowledge($this->ansibleId, $this->hostFor('ansible'), $reason));

        $audit = $this->acknowledgedRows();
        self::assertCount(1, $audit);
        $context = json_decode((string) $audit[0]['context_json'], true, 512, JSON_THROW_ON_ERROR);
        $stored = (string) $context['reason'];
        self::assertTrue(mb_check_encoding($stored, 'UTF-8'), 'the stored reason is valid UTF-8');
        self::assertSame(virtusphere_bounded_utf8_bytes($reason, 256), $stored, 'the stored reason is the input bounded to 256 bytes');
        self::assertLessThanOrEqual(256, strlen($stored));
        self::assertStringStartsWith($stored, $reason);
    }

    /** Acknowledging with no open row closes nothing, returns 0 and writes no audit row. */
    public function testAcknowledgeWithoutOpenRowsClosesNothingAndWritesNoAudit(): void
    {
        self::assertSame(0, $this->acknowledge($this->ansibleId, $this->hostFor('ansible'), 'Nichts offen (' . $this->token . ')'));

        self::assertCount(0, $this->acknowledgedRows());
    }

    /**
     * Any change of the pinned key with a row open is refused, the first pin too: a NULL pin
     * with a row cannot be confirmed to a key while the row stands. The pin and the row stay.
     */
    public function testConfirmingTheFirstKeyWithAnOpenRowIsRefused(): void
    {
        repo_execute($this->db, 'UPDATE deploy_credentials SET ansible_host_fingerprint = NULL WHERE id = ?', 'i', [$this->ansibleId]);
        $this->seedRow($this->finishedJob($this->ansibleId), $this->ansibleId);
        $credential = repo_credential($this->db, $this->ansibleId);
        $rows = $this->remoteRows($this->ansibleId);
        self::assertNull($credential['ansible_host_fingerprint'], 'fixture: no pinned key');

        $this->expectRefusal(function () use ($credential): void {
            repo_confirm_ansible_host_identity(
                $this->db,
                $this->ansibleId,
                (int) $credential['config_revision'],
                '',
                self::fingerprintFor('ansible-a'),
                'ssh-ed25519',
                $this->userId
            );
        });

        self::assertNull(repo_credential($this->db, $this->ansibleId)['ansible_host_fingerprint']);
        self::assertSame($rows, $this->remoteRows($this->ansibleId));
    }

    /** Confirming the key that is already pinned changes no target and stays allowed while a row is open. */
    public function testConfirmingTheSamePinnedKeyWithAnOpenRowIsAllowed(): void
    {
        $this->seedRow($this->finishedJob($this->ansibleId), $this->ansibleId);
        $credential = repo_credential($this->db, $this->ansibleId);
        $rows = $this->remoteRows($this->ansibleId);

        repo_confirm_ansible_host_identity(
            $this->db,
            $this->ansibleId,
            (int) $credential['config_revision'],
            (string) $credential['ansible_host_fingerprint'],
            (string) $credential['ansible_host_fingerprint'],
            (string) $credential['ansible_host_key_type'],
            $this->userId
        );

        self::assertSame($credential['ansible_host_fingerprint'], repo_credential($this->db, $this->ansibleId)['ansible_host_fingerprint']);
        self::assertSame($rows, $this->remoteRows($this->ansibleId));
    }

    private function acknowledge(?int $credentialId, string $host, string $reason): int
    {
        self::assertTrue(function_exists(self::ACK_FUNCTION), 'Structural: ' . self::ACK_FUNCTION . '() does not exist yet; the F-Fence module lib/repo/deploy_remote_secret_fence.php is missing.');

        return repo_remote_secret_acknowledge($this->db, $credentialId, $host, self::PORT, $reason, $this->userId);
    }

    /** Runs the write that must be refused and returns its exception; a write that goes through fails the test. */
    private function expectRefusal(callable $write): ValidationException
    {
        try {
            $write();
        } catch (ValidationException $exception) {
            return $exception;
        }
        self::fail('The write was not refused with a ValidationException.');
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $changes @return array<string, mixed> */
    private function payloadFor(array $row, array $changes): array
    {
        return array_replace([
            'type' => (string) $row['type'],
            'name' => (string) $row['name'],
            'host' => (string) $row['host'],
            'port' => credential_ssh_port($row['port']),
            'username' => (string) $row['username'],
        ], $changes);
    }

    private static function fingerprintFor(string $seed): string
    {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $seed, true)), '=');
    }

    private function prefix(): string
    {
        return 'phpunit_k21f_' . $this->token;
    }

    private function remoteRoot(): string
    {
        return '/tmp/vs-k21f-' . $this->token . '/';
    }

    private function remoteDir(int $jobId): string
    {
        return rtrim($this->remoteRoot(), '/') . '/job-' . $jobId;
    }

    private function hostFor(string $label): string
    {
        return $label . '-' . $this->token . '.example.invalid';
    }

    private function insertMission(): int
    {
        repo_execute($this->db, "INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, 'active')", 's', [$this->prefix() . '_mission']);
        $missionId = (int) $this->db->insert_id;
        $this->missionIds[] = $missionId;

        return $missionId;
    }

    private function insertCredential(string $type, int $port, string $label): int
    {
        $name = $this->prefix() . '_' . $label;
        $host = $this->hostFor($label);
        $username = 'svc';
        $ciphertext = $this->ciphertext;
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssiss', $type, $name, $host, $port, $username, $ciphertext);
        $stmt->execute();
        $credentialId = (int) $this->db->insert_id;
        $this->credentialIds[] = $credentialId;

        return $credentialId;
    }

    /** Sets host and pinned host key of a credential, as a confirmed key leaves it. */
    private function pointCredentialAt(int $credentialId, string $host, string $fingerprint): void
    {
        repo_execute($this->db, 'UPDATE deploy_credentials SET host = ?, ansible_host_fingerprint = ?, ansible_host_key_type = ? WHERE id = ?', 'sssi', [$host, $fingerprint, 'ssh-ed25519', $credentialId]);
    }

    /** A job that ended: its open rows are the case the fence is about. */
    private function finishedJob(int $ansibleId): int
    {
        return $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $ansibleId, null);
    }

    private function runningJob(int $ansibleId): int
    {
        return $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $ansibleId, self::WORKER);
    }

    private function insertJob(string $status, int $ansibleId, ?string $lockedBy): int
    {
        $payload = json_encode(['mode' => 'start', 'vm_ids' => []], JSON_THROW_ON_ERROR);
        $esxiId = $this->esxiId;
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_jobs (mission_id, status, credential_esxi_id, credential_ansible_id, locked_by, locked_at, heartbeat_at, payload_json) '
            . 'VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)'
        );
        $missionId = $this->missionId;
        $stmt->bind_param('isiiss', $missionId, $status, $esxiId, $ansibleId, $lockedBy, $payload);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    /** The write-ahead row of a job, with the target its credential has now. Direct insert, independent of the writer. */
    private function seedRow(int $jobId, int $ansibleId): void
    {
        repo_execute(
            $this->db,
            'INSERT INTO deploy_remote_secret_files (job_id, credential_ansible_id, remote_dir, target_host, target_port, target_fingerprint)'
                . ' SELECT ?, c.id, ?, c.host, COALESCE(c.port, ?), c.ansible_host_fingerprint FROM deploy_credentials c WHERE c.id = ?',
            'isii',
            [$jobId, $this->remoteDir($jobId), self::PORT, $ansibleId]
        );
    }

    private function seedRowOnTarget(int $jobId, int $ansibleId, string $host, int $port, ?string $fingerprint): void
    {
        repo_execute(
            $this->db,
            'INSERT INTO deploy_remote_secret_files (job_id, credential_ansible_id, remote_dir, target_host, target_port, target_fingerprint) VALUES (?, ?, ?, ?, ?, ?)',
            'iissis',
            [$jobId, $ansibleId, $this->remoteDir($jobId), $host, $port, $fingerprint]
        );
    }

    /** @return list<array<string, mixed>> the rows of this test with the given credential (null: credential NULL) */
    private function remoteRows(?int $credentialId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, job_id, credential_ansible_id, remote_dir, target_host, target_port, target_fingerprint, lease_until'
            . ' FROM deploy_remote_secret_files WHERE credential_ansible_id <=> ? AND remote_dir LIKE ? ORDER BY id'
        );
        $pattern = $this->remoteRoot() . '%';
        $stmt->bind_param('is', $credentialId, $pattern);
        $stmt->execute();

        return repo_fetch_all($stmt->get_result());
    }

    /** @return list<int> */
    private function remoteJobIds(int $credentialId): array
    {
        return array_map('intval', array_column($this->remoteRows($credentialId), 'job_id'));
    }

    /** @return list<array<string, mixed>> the audit rows of this test */
    private function acknowledgedRows(): array
    {
        $stmt = $this->db->prepare('SELECT object_type, object_id, result, context_json FROM deploy_logs WHERE event_code = ? AND context_json LIKE ? ORDER BY id');
        $code = self::ACK_EVENT;
        $pattern = '%' . $this->token . '%';
        $stmt->bind_param('ss', $code, $pattern);
        $stmt->execute();

        return repo_fetch_all($stmt->get_result());
    }
}
