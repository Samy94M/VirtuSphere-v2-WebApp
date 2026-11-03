<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/repo/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
// The notice module itself (VIRTUSPHERE_REMOTE_SECRET_NOTICE_AFTER_SECONDS, repo_remote_secret_notice_groups,
// repo_remote_secret_open_by_credential) is K21-F and is required in setUp, where a missing file fails each case.

/**
 * F-UI (K21-F): the System status notice lists the ESXi access files that are
 * still open on an Ansible host and that no job will remove on its own. The
 * notice is a read model over deploy_remote_secret_files; it writes nothing.
 *
 * A row is due for the notice when it is one of these, and only then:
 *  - orphaned: its Ansible credential is gone (credential_ansible_id NULL), at once;
 *  - moved: its target (host, effective SSH port, pinned host key) no longer
 *    matches the credential, at once;
 *  - overdue: uploaded more than VIRTUSPHERE_REMOTE_SECRET_NOTICE_AFTER_SECONDS ago.
 * A row under a running lease, or of a job that is still active, never counts.
 *
 * Runs against the QA database. Fixtures carry a per-test token in their host
 * names, and every assertion reads only the groups of that token, so rows of
 * other tests or of a real installation cannot disturb the result.
 */
final class RemoteSecretNoticeTest extends TestCase
{
    private const WORKER = 'phpunit-k21f';
    private const REMOTE_ROOT = '/tmp/vs-k21f-';
    private const ALLOWED_GROUP_KEYS = ['credential_id', 'credential_name', 'target_host', 'target_port', 'count', 'oldest', 'reason'];

    private mysqli $db;
    private string $token = '';
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
        $this->requireModule('lib/repo/deploy_remote_secret_notice.php');
        $this->token = bin2hex(random_bytes(5));
        $this->missionId = $this->insertMission();
        $this->esxiId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443, 'esxi');
        $this->ansibleId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22, 'ansible');
        $this->otherAnsibleId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22, 'ansible-other');
        $this->pinTarget($this->ansibleId, $this->hostFor('ansible'), 'SHA256:' . $this->token . 'a');
        $this->pinTarget($this->otherAnsibleId, $this->hostFor('ansible-other'), 'SHA256:' . $this->token . 'b');
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        // The rows of this test are found by their host, which carries the token.
        repo_execute($this->db, 'DELETE FROM deploy_remote_secret_files WHERE target_host LIKE ?', 's', ['%' . $this->token . '%']);
        // Deleting a mission cascades its jobs.
        foreach ($this->missionIds as $missionId) {
            repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$missionId]);
        }
        foreach ($this->credentialIds as $credentialId) {
            repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$credentialId]);
        }
    }

    /** The module is the interface under test; a missing file fails each test instead of aborting the run. */
    private function requireModule(string $relative): void
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        if (!is_file($path)) {
            self::fail('Interface missing: ' . $relative . ' does not exist yet.');
        }
        require_once $path;
    }

    /**
     * The groups of this test's own targets, in the order the notice lists them.
     *
     * @return list<array<string, mixed>>
     */
    private function noticeGroups(): array
    {
        return array_values(array_filter(
            repo_remote_secret_notice_groups($this->db),
            fn (array $group): bool => str_contains((string) $group['target_host'], $this->token)
        ));
    }

    /** The single group of this test's own targets; a case that expects one row fails on any other shape. */
    private function onlyGroup(): array
    {
        $groups = $this->noticeGroups();
        self::assertCount(1, $groups, 'exactly one group of this test is expected');

        return $groups[0];
    }

    private function oldAge(): int
    {
        return VIRTUSPHERE_REMOTE_SECRET_NOTICE_AFTER_SECONDS + 3600;
    }

    private function youngAge(): int
    {
        return 3600;
    }

    private function hostFor(string $label): string
    {
        return $label . '-' . $this->token . '.example.invalid';
    }

    private function remoteDir(int $jobId): string
    {
        return self::REMOTE_ROOT . $this->token . '/job-' . $jobId;
    }

    private function insertMission(): int
    {
        repo_execute($this->db, "INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, 'active')", 's', ['phpunit_k21f_' . $this->token]);
        $missionId = (int) $this->db->insert_id;
        $this->missionIds[] = $missionId;

        return $missionId;
    }

    private function insertCredential(string $type, int $port, string $label): int
    {
        $name = 'phpunit_k21f_' . $this->token . '_' . $label;
        $host = $this->hostFor($label);
        $username = 'svc';
        // A recognisable sealed value: the notice must never print it.
        $ciphertext = 'sealed-' . $this->token . '-' . $label;
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssiss', $type, $name, $host, $port, $username, $ciphertext);
        $stmt->execute();
        $credentialId = (int) $this->db->insert_id;
        $this->credentialIds[] = $credentialId;

        return $credentialId;
    }

    /** Points an Ansible credential at a target, as an edit plus a confirmed host key leave it. */
    private function pinTarget(int $credentialId, string $host, ?string $fingerprint): void
    {
        repo_execute($this->db, 'UPDATE deploy_credentials SET host = ?, ansible_host_fingerprint = ? WHERE id = ?', 'ssi', [$host, $fingerprint, $credentialId]);
    }

    /** @return array{host: string, port: int, fingerprint: ?string} the target the credential has now */
    private function targetOf(int $credentialId): array
    {
        $row = repo_fetch_one($this->db, 'SELECT host, port, ansible_host_fingerprint FROM deploy_credentials WHERE id = ?', 'i', [$credentialId]);
        self::assertNotNull($row);

        return [
            'host' => (string) $row['host'],
            'port' => credential_ssh_port($row['port']),
            'fingerprint' => $row['ansible_host_fingerprint'] === null ? null : (string) $row['ansible_host_fingerprint'],
        ];
    }

    /** A job that already ended, the state every job has when its ESXi file is due. */
    private function finishedJob(): int
    {
        $jobId = $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $this->ansibleId);
        self::assertTrue(repo_finish_deploy_job($this->db, $jobId, self::WORKER, VIRTUSPHERE_DEPLOY_STATUS_FAILED, 'fixture job end'));

        return $jobId;
    }

    private function insertJob(string $status, int $ansibleId): int
    {
        $payload = json_encode(['mode' => 'start', 'vm_ids' => []], JSON_THROW_ON_ERROR);
        $lockedBy = $status === VIRTUSPHERE_DEPLOY_STATUS_RUNNING ? self::WORKER : null;
        $esxiId = $this->esxiId;
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_jobs (mission_id, status, credential_esxi_id, credential_ansible_id, locked_by, locked_at, heartbeat_at, payload_json) '
            . 'VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)'
        );
        $stmt->bind_param('isiiss', $this->missionId, $status, $esxiId, $ansibleId, $lockedBy, $payload);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    /**
     * The row the upload of a job writes: the target the credential has at this
     * moment, uploaded $ageSeconds ago. A direct insert keeps the fixture
     * independent of the writer under test.
     */
    private function rowFor(int $credentialId, int $ageSeconds, ?int $jobId = null): int
    {
        $jobId ??= $this->finishedJob();
        $target = $this->targetOf($credentialId);
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_remote_secret_files (job_id, credential_ansible_id, remote_dir, target_host, target_port, target_fingerprint, uploaded_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, NOW(6) - INTERVAL ? SECOND)'
        );
        $remoteDir = $this->remoteDir($jobId);
        $stmt->bind_param('iissisi', $jobId, $credentialId, $remoteDir, $target['host'], $target['port'], $target['fingerprint'], $ageSeconds);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    /** A running lease of $seconds from now (negative: a lease that already ended). */
    private function leaseRow(int $rowId, int $seconds): void
    {
        repo_execute($this->db, 'UPDATE deploy_remote_secret_files SET lease_until = NOW(6) + INTERVAL ? SECOND WHERE id = ?', 'ii', [$seconds, $rowId]);
    }

    public function testFreshRowOfAMatchingTargetIsNoGroup(): void
    {
        $this->rowFor($this->ansibleId, $this->youngAge());

        self::assertSame([], $this->noticeGroups());
    }

    public function testTheNoticeAgeIsTwentyFourHours(): void
    {
        self::assertSame(86400, VIRTUSPHERE_REMOTE_SECRET_NOTICE_AFTER_SECONDS);
    }

    public function testRowOlderThanTheNoticeAgeIsOverdueWithTheFieldsTheNoticeNeeds(): void
    {
        $rowId = $this->rowFor($this->ansibleId, $this->oldAge());
        $uploaded = (string) repo_scalar($this->db, 'SELECT uploaded_at FROM deploy_remote_secret_files WHERE id = ?', 'i', [$rowId]);

        $group = $this->onlyGroup();

        self::assertSame(self::ALLOWED_GROUP_KEYS, array_keys($group));
        self::assertSame($this->ansibleId, $group['credential_id']);
        self::assertSame('phpunit_k21f_' . $this->token . '_ansible', $group['credential_name']);
        self::assertSame($this->hostFor('ansible'), $group['target_host']);
        self::assertSame(22, $group['target_port']);
        self::assertSame(1, $group['count']);
        self::assertSame(substr($uploaded, 0, 19), substr((string) $group['oldest'], 0, 19));
        self::assertSame('overdue', $group['reason']);
    }

    public function testOrphanedRowIsOrphanedAtOnceEvenWhenYoung(): void
    {
        $this->rowFor($this->otherAnsibleId, $this->youngAge());
        // The foreign key sets credential_ansible_id to NULL when the credential goes.
        repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$this->otherAnsibleId]);

        $group = $this->onlyGroup();

        self::assertNull($group['credential_id']);
        self::assertNull($group['credential_name']);
        self::assertSame($this->hostFor('ansible-other'), $group['target_host']);
        self::assertSame(22, $group['target_port']);
        self::assertSame(1, $group['count']);
        self::assertSame('orphaned', $group['reason']);
    }

    /** @return iterable<string, array{string}> */
    public static function targetChanges(): iterable
    {
        yield 'host' => ['host'];
        yield 'port' => ['port'];
        yield 'host key' => ['fingerprint'];
    }

    #[DataProvider('targetChanges')]
    public function testYoungRowWhoseTargetNoLongerMatchesTheCredentialIsMovedAtOnce(string $change): void
    {
        $this->rowFor($this->ansibleId, $this->youngAge());
        $before = $this->targetOf($this->ansibleId);

        match ($change) {
            'host' => $this->pinTarget($this->ansibleId, $this->hostFor('moved'), $before['fingerprint']),
            'port' => repo_execute($this->db, 'UPDATE deploy_credentials SET port = ? WHERE id = ?', 'ii', [2222, $this->ansibleId]),
            'fingerprint' => $this->pinTarget($this->ansibleId, $before['host'], 'SHA256:' . $this->token . 'changed'),
            default => self::fail('unknown target change ' . $change),
        };

        $group = $this->onlyGroup();

        self::assertSame('moved', $group['reason']);
        // The group names the target the file is on, which is the row's old target.
        self::assertSame($before['host'], $group['target_host']);
        self::assertSame(22, $group['target_port']);
        self::assertSame($this->ansibleId, $group['credential_id']);
        self::assertSame(1, $group['count']);
    }

    public function testRunningLeaseExcludesAnOldRow(): void
    {
        $rowId = $this->rowFor($this->ansibleId, $this->oldAge());
        $this->leaseRow($rowId, 600);

        self::assertSame([], $this->noticeGroups());
    }

    public function testLeaseThatEndedNoLongerExcludesAnOldRow(): void
    {
        $rowId = $this->rowFor($this->ansibleId, $this->oldAge());
        $this->leaseRow($rowId, -600);

        self::assertSame('overdue', $this->onlyGroup()['reason']);
    }

    public function testRowOfAnActiveJobIsExcludedEvenWhenOld(): void
    {
        foreach (VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES as $status) {
            $this->rowFor($this->ansibleId, $this->oldAge(), $this->insertJob($status, $this->ansibleId));
        }

        self::assertSame([], $this->noticeGroups(), 'queued, running and cancelling jobs keep their rows out of the notice');
    }

    public function testRowOfAnEndedJobCountsOnceOld(): void
    {
        $this->rowFor($this->ansibleId, $this->oldAge());

        self::assertSame('overdue', $this->onlyGroup()['reason']);
    }

    public function testRowWhoseJobRowIsGoneCountsAsInactive(): void
    {
        // deploy_remote_secret_files has no foreign key to the job: a deleted mission leaves its row behind.
        $this->rowFor($this->ansibleId, $this->oldAge(), 2000000000 + random_int(0, 1000000));

        self::assertSame('overdue', $this->onlyGroup()['reason']);
    }

    public function testSeveralRowsOfOneCredentialAndTargetFormOneGroup(): void
    {
        $this->rowFor($this->ansibleId, $this->oldAge());
        $this->rowFor($this->ansibleId, $this->oldAge() + 7200);
        $this->rowFor($this->ansibleId, $this->oldAge() + 86400);
        $oldest = (string) repo_scalar(
            $this->db,
            'SELECT MIN(uploaded_at) FROM deploy_remote_secret_files WHERE target_host = ?',
            's',
            [$this->hostFor('ansible')]
        );

        $groups = $this->noticeGroups();

        self::assertCount(1, $groups);
        self::assertSame(3, $groups[0]['count']);
        self::assertSame(substr($oldest, 0, 19), substr((string) $groups[0]['oldest'], 0, 19));
        self::assertSame('overdue', $groups[0]['reason']);
    }

    public function testGroupsCarryNoSecretAndNoRemoteDirectory(): void
    {
        $this->rowFor($this->ansibleId, $this->oldAge());
        $this->rowFor($this->otherAnsibleId, $this->youngAge());
        repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$this->otherAnsibleId]);

        $groups = $this->noticeGroups();
        $json = json_encode($groups, JSON_THROW_ON_ERROR);

        self::assertCount(2, $groups);
        foreach ($groups as $group) {
            self::assertSame(self::ALLOWED_GROUP_KEYS, array_keys($group), 'the notice exposes exactly its own fields');
        }
        self::assertStringNotContainsString('sealed-' . $this->token, $json, 'secret_ciphertext never leaves the database');
        self::assertStringNotContainsString(self::REMOTE_ROOT . $this->token, $json, 'the remote directory is not part of the notice');
    }

    public function testYoungOpenRowOfADueTargetIsCountedWithIt(): void
    {
        $this->rowFor($this->ansibleId, $this->oldAge());
        $this->rowFor($this->ansibleId, $this->youngAge());

        $group = $this->onlyGroup();

        self::assertSame('overdue', $group['reason']);
        self::assertSame(2, $group['count'], 'the acknowledgement closes the young row too, so the notice counts it');
    }

    public function testLeasedRowOfADueTargetIsNotCounted(): void
    {
        $this->rowFor($this->ansibleId, $this->oldAge());
        $leased = $this->rowFor($this->ansibleId, $this->oldAge());
        $this->leaseRow($leased, 600);

        self::assertSame(1, $this->onlyGroup()['count']);
    }

    public function testRowOfAnActiveJobIsNotCountedInADueTarget(): void
    {
        $this->rowFor($this->ansibleId, $this->oldAge());
        $this->rowFor($this->ansibleId, $this->youngAge(), $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $this->ansibleId));

        self::assertSame(1, $this->onlyGroup()['count']);
    }

    public function testGroupsOrderOrphanedThenMovedThenOverdue(): void
    {
        $thirdId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22, 'ansible-third');
        $this->pinTarget($thirdId, $this->hostFor('ansible-third'), 'SHA256:' . $this->token . 'c');
        $this->rowFor($thirdId, $this->youngAge());
        $this->pinTarget($thirdId, $this->hostFor('ansible-third-moved'), 'SHA256:' . $this->token . 'c');
        $this->rowFor($this->ansibleId, $this->oldAge());
        $this->rowFor($this->otherAnsibleId, $this->youngAge());
        repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$this->otherAnsibleId]);

        self::assertSame(['orphaned', 'moved', 'overdue'], array_column($this->noticeGroups(), 'reason'));
    }

    public function testOpenByCredentialListsEveryOpenRowOfAnAnsibleCredential(): void
    {
        $this->rowFor($this->ansibleId, $this->youngAge());
        $leased = $this->rowFor($this->ansibleId, $this->oldAge());
        $this->leaseRow($leased, 600);
        $this->rowFor($this->ansibleId, $this->oldAge(), $this->insertJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $this->ansibleId));

        $targets = repo_remote_secret_open_by_credential($this->db)[$this->ansibleId] ?? [];

        self::assertCount(1, $targets, 'the leased row and the active job stay out of the credential line');
        self::assertSame($this->hostFor('ansible'), $targets[0]['target_host']);
        self::assertSame(22, $targets[0]['target_port']);
        self::assertSame(1, $targets[0]['count']);
    }

    public function testOpenByCredentialListsTheOldTargetOfAMovedCredential(): void
    {
        $this->rowFor($this->ansibleId, $this->youngAge());
        $this->pinTarget($this->ansibleId, $this->hostFor('moved'), 'SHA256:' . $this->token . 'd');

        $targets = repo_remote_secret_open_by_credential($this->db)[$this->ansibleId] ?? [];

        self::assertCount(1, $targets);
        self::assertSame($this->hostFor('ansible'), $targets[0]['target_host'], 'the old target stays listed: a confirmation there closes its rows');
    }

    public function testTheGroupListIsTheSameOnEveryCall(): void
    {
        $this->rowFor($this->ansibleId, $this->oldAge());
        $this->rowFor($this->otherAnsibleId, $this->youngAge());
        repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$this->otherAnsibleId]);

        self::assertSame($this->noticeGroups(), $this->noticeGroups());
        self::assertCount(2, $this->noticeGroups());
    }
}
