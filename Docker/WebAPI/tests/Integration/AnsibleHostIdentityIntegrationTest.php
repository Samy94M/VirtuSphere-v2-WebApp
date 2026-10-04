<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/credential_host_identity.php';
require_once dirname(__DIR__, 2) . '/lib/credentials_host_identity.php';
require_once dirname(__DIR__, 2) . '/lib/ssh.php';

final class AnsibleHostIdentityIntegrationTest extends TestCase
{
    private mysqli $db;
    private int $id;
    private int $userId;
    private ?int $activeJobId = null;

    protected function setUp(): void
    {
        $this->db = db(true);
        $this->userId = (int) ($this->db->query('SELECT id FROM deploy_users ORDER BY id LIMIT 1')->fetch_assoc()['id'] ?? 0);
        self::assertGreaterThan(0, $this->userId, 'The synthetic QA user is required.');
        $this->id = repo_create_credential($this->db, ['type' => 'ansible', 'name' => 'phpunit_host_identity_' . bin2hex(random_bytes(6)),
            'host' => 'fixture.invalid', 'port' => 22, 'username' => 'worker'], 'fixture-password', $this->userId);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db, $this->id)) { return; }
        if ($this->activeJobId !== null) {
            $stmt = $this->db->prepare('DELETE FROM deploy_jobs WHERE id = ?');
            $stmt->bind_param('i', $this->activeJobId);
            $stmt->execute();
        }
        $stmt = $this->db->prepare("DELETE FROM deploy_logs WHERE object_type = 'credential' AND object_id = ?");
        $id = (string) $this->id;
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE id = ?');
        $stmt->bind_param('i', $this->id);
        $stmt->execute();
    }

    public function testNewCredentialDoesNotInheritTheUpgradeException(): void
    {
        self::assertSame(0, (int) $this->row()['ansible_host_accept_new']);
        $this->reject($this->row(), $this->identity('a'), 'unconfirmed');
        self::assertNull($this->row()['ansible_host_fingerprint']);
        self::assertSame($this->identity('a')['fingerprint'], $this->row()['ansible_host_observed_fingerprint']);
    }

    public function testProvisionalPinBlocksEveryLaterDifferentKey(): void
    {
        $this->allowUpgrade();
        $snapshot = $this->row();
        repo_verify_ansible_host_identity($this->db, $snapshot, $this->identity('a'));
        self::assertSame('provisional', credential_host_identity_state($this->row()));
        self::assertNotNull($this->row()['ansible_host_first_seen_at']);
        self::assertSame(0, (int) $this->row()['ansible_host_accept_new']);
        $this->reject($snapshot, $this->identity('b'), 'mismatch');
        self::assertSame($this->identity('a')['fingerprint'], $this->row()['ansible_host_fingerprint']);
        self::assertSame('mismatch', credential_host_identity_state($this->row()));
        repo_verify_ansible_host_identity($this->db, $snapshot, $this->identity('a'));
        self::assertSame('provisional', credential_host_identity_state($this->row()));
    }

    public function testConfirmationRemovesPleaseConfirmWithoutInvalidatingTheSameIdentity(): void
    {
        $this->allowUpgrade();
        repo_verify_ansible_host_identity($this->db, $this->row(), $this->identity('a'));
        $before = $this->row();
        $identity = $this->identity('a');
        repo_confirm_ansible_host_identity($this->db, $this->id, (int) $before['config_revision'], $identity['fingerprint'], $identity['fingerprint'], $identity['type'], $this->userId);
        $after = $this->row();
        self::assertSame('confirmed', credential_host_identity_state($after));
        self::assertSame((int) $before['config_revision'], (int) $after['config_revision']);
        self::assertSame($before['ansible_host_first_seen_at'], $after['ansible_host_first_seen_at']);
        self::assertSame($this->userId, (int) $after['ansible_host_confirmed_by']);
        self::assertNotNull($after['ansible_host_confirmed_at']);
        $logs = repo_fetch_one($this->db, "SELECT COUNT(*) AS c FROM deploy_logs WHERE object_type = 'credential' AND object_id = ? AND event_code = 'credential.changed'", 's', [(string) $this->id]);
        self::assertSame(2, (int) $logs['c'], 'Initial pin and explicit confirmation must each be audited once.');
    }

    public function testRotationReadsTheCurrentPinForAnOldWorkerAndFencesAnOldForm(): void
    {
        $old = $this->identity('a');
        repo_confirm_ansible_host_identity($this->db, $this->id, 1, '', $old['fingerprint'], $old['type'], $this->userId);
        $snapshot = $this->row();
        $new = $this->identity('b');
        repo_confirm_ansible_host_identity($this->db, $this->id, (int) $snapshot['config_revision'], $old['fingerprint'], $new['fingerprint'], $new['type'], $this->userId);
        $this->reject($snapshot, $old, 'mismatch');
        repo_verify_ansible_host_identity($this->db, $snapshot, $new);
        try {
            repo_confirm_ansible_host_identity($this->db, $this->id, (int) $snapshot['config_revision'], $old['fingerprint'], $old['fingerprint'], $old['type'], $this->userId);
            self::fail('A stale form replaced a newer identity.');
        } catch (ValidationException) {
            self::assertSame($new['fingerprint'], $this->row()['ansible_host_fingerprint']);
        }
    }

    public function testChangedCredentialSnapshotCannotPinAnOldHost(): void
    {
        $this->allowUpgrade();
        $snapshot = $this->row();
        $stmt = $this->db->prepare('UPDATE deploy_credentials SET config_revision = config_revision + 1, host = ? WHERE id = ?');
        $host = 'changed.invalid';
        $stmt->bind_param('si', $host, $this->id);
        $stmt->execute();
        $this->reject($snapshot, $this->identity('a'), 'credential_changed');
        self::assertNull($this->row()['ansible_host_fingerprint']);
        self::assertNull($this->row()['ansible_host_observed_fingerprint']);
    }

    public function testRenamingACredentialDuringARunningJobKeepsTheNextConnectionAllowed(): void
    {
        $this->allowUpgrade();
        repo_verify_ansible_host_identity($this->db, $this->row(), $this->identity('a'));
        $snapshot = $this->row();
        $stmt = $this->db->prepare("INSERT INTO deploy_jobs (status, credential_ansible_id, user_id) VALUES ('running', ?, ?)");
        $stmt->bind_param('ii', $this->id, $this->userId);
        $stmt->execute();
        $this->activeJobId = (int) $this->db->insert_id;
        repo_update_credential($this->db, $this->id, array_replace($snapshot, ['name' => $snapshot['name'] . '_renamed']), null);
        self::assertGreaterThan((int) $snapshot['config_revision'], (int) $this->row()['config_revision']);
        try {
            repo_verify_ansible_host_identity($this->db, $snapshot, $this->identity('a'));
        } catch (SshHostIdentityRejected $exception) {
            self::fail('Renaming an unchanged endpoint rejected the next connection: ' . $exception->reason);
        }
        self::assertSame($snapshot['ansible_host_fingerprint'], $this->row()['ansible_host_fingerprint']);
    }

    public function testAnUpgradeCredentialMovedBeforeItsFirstConnectionRequiresConfirmation(): void
    {
        $this->allowUpgrade();
        $snapshot = $this->row();
        repo_update_credential($this->db, $this->id, array_replace($snapshot, ['host' => 'new-target.invalid']), null);
        $this->reject($this->row(), $this->identity('b'), 'unconfirmed');
        self::assertNull($this->row()['ansible_host_fingerprint']);
        self::assertSame(0, (int) $this->row()['ansible_host_accept_new']);
    }

    public function testChangingThePortOfAPinnedCredentialClearsItsIdentity(): void
    {
        $this->allowUpgrade();
        repo_verify_ansible_host_identity($this->db, $this->row(), $this->identity('a'));
        $snapshot = $this->row();
        repo_update_credential($this->db, $this->id, array_replace($snapshot, ['port' => 2222]), null);
        $this->reject($this->row(), $this->identity('a'), 'unconfirmed');
        self::assertNull($this->row()['ansible_host_fingerprint']);
        self::assertNull($this->row()['ansible_host_confirmed_at']);
        self::assertSame(0, (int) $this->row()['ansible_host_accept_new']);
    }

    public function testDifferentConcurrentFirstConnectionsCannotBothPin(): void
    {
        $this->allowUpgrade();
        $snapshot = $this->row();
        $first = $this->identity('a');
        $second = $this->identity('b');
        $process = null;
        $pipes = [];
        repo_transaction($this->db, function () use ($snapshot, $first, $second, &$process, &$pipes): void {
            repo_verify_ansible_host_identity($this->db, $snapshot, $first);
            $code = 'require ' . var_export(dirname(__DIR__, 2) . '/lib/db.php', true) . ';'
                . 'require ' . var_export(dirname(__DIR__, 2) . '/lib/repo/credential_host_identity.php', true) . ';'
                . '$db = db(); $db->query("SET SESSION innodb_lock_wait_timeout = 10"); echo "READY\n"; flush();'
                . 'try { repo_verify_ansible_host_identity($db, json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR), json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR)); echo "accepted"; }'
                . 'catch (SshHostIdentityRejected $e) { echo $e->reason; }';
            $process = proc_open([PHP_BINARY, '-r', $code, json_encode($snapshot, JSON_THROW_ON_ERROR), json_encode($second, JSON_THROW_ON_ERROR)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            self::assertSame("READY\n", fgets($pipes[1]));
            // The first transaction still owns the credential lock here. The
            // second connection must wait and then read the committed pin.
        });
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        self::assertSame('mismatch', $stdout, $stderr);
        self::assertSame($first['fingerprint'], $this->row()['ansible_host_fingerprint']);
    }

    public function testAnOpenTransactionCannotReleaseAPasswordAgainstAnUncommittedPin(): void
    {
        $this->allowUpgrade();
        $ssh = $this->createMock(phpseclib3\Net\SSH2::class);
        $ssh->method('getServerPublicHostKey')->willReturn('ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . str_repeat('a', 32)));
        $ssh->expects(self::never())->method('login');
        repo_transaction($this->db, function () use ($ssh): void {
            try {
                ssh_verified_login($ssh, 'worker', 'fixture-password', $this->row());
                self::fail('An uncommitted pin released authentication.');
            } catch (SshHostIdentityRejected $exception) {
                self::assertSame('storage_failed', $exception->reason);
            }
        });
        self::assertNull($this->row()['ansible_host_fingerprint']);
    }

    public function testPinObservationAndAuditRollBackTogether(): void
    {
        $this->allowUpgrade();
        $failure = null;
        try {
            repo_transaction($this->db, function (): void {
                repo_verify_ansible_host_identity($this->db, $this->row(), $this->identity('a'));
                throw new RuntimeException('Simulated commit-side storage failure');
            });
        } catch (RuntimeException $exception) {
            $failure = $exception->getMessage();
        }
        self::assertSame('Simulated commit-side storage failure', $failure);
        self::assertNull($this->row()['ansible_host_fingerprint']);
        self::assertNull($this->row()['ansible_host_observed_fingerprint']);
        self::assertSame(1, (int) $this->row()['ansible_host_accept_new']);
        $logs = repo_fetch_one($this->db, "SELECT COUNT(*) AS c FROM deploy_logs WHERE object_type = 'credential' AND object_id = ?", 's', [(string) $this->id]);
        self::assertSame(0, (int) $logs['c']);
    }

    public function testMigrationReplayDoesNotRegrantConsumedUpgradeEligibility(): void
    {
        $this->allowUpgrade();
        repo_verify_ansible_host_identity($this->db, $this->row(), $this->identity('a'));
        $before = $this->row();
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/lib/migrate.php', true)
            . '; migrate_0059_ansible_host_identity(db());';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr . $stdout);
        self::assertSame($before['ansible_host_fingerprint'], $this->row()['ansible_host_fingerprint']);
        self::assertSame(0, (int) $this->row()['ansible_host_accept_new']);
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        return repo_credential($this->db, $this->id) ?? throw new RuntimeException('Fixture vanished.');
    }

    private function allowUpgrade(): void
    {
        $stmt = $this->db->prepare('UPDATE deploy_credentials SET ansible_host_accept_new = 1 WHERE id = ?');
        $stmt->bind_param('i', $this->id);
        $stmt->execute();
    }

    /** @return array{type:string,fingerprint:string} */
    private function identity(string $value): array
    {
        return ['type' => 'ssh-ed25519', 'fingerprint' => 'SHA256:' . rtrim(base64_encode(hash('sha256', $value, true)), '=')];
    }

    private function reject(array $snapshot, array $identity, string $reason): void
    {
        try {
            repo_verify_ansible_host_identity($this->db, $snapshot, $identity);
            self::fail('A blocked identity was admitted.');
        } catch (SshHostIdentityRejected $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }
}
