<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_command.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_create_protocol.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_create_results.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_cleanup.php';

/**
 * The ESXi access file accounts.yml is removed at the end of every job that
 * uploaded it (K21, VT-04). A removal that cannot be confirmed leaves a row in
 * deploy_remote_secret_files, and the next contact with the same Ansible
 * credential AND the same target (host, port, pinned host key) closes it with
 * one audit row that says whether the file was removed or already gone.
 *
 * Runs against a real MySQL server. The SSH layer is replaced by recording fakes
 * through the execute and upload seams, so no host is ever contacted. A fake
 * removal answers with the state line of the real command (see
 * AnsibleRemoteSecretCommandTest, which runs that command through /bin/sh).
 *
 * The Astra conditions K21-A2 (target), K21-A3 (sweep heartbeat, abort and
 * order), K21-A4 (lease of a status query) and K21-A6 (directory diagnostic)
 * each have their own cases below.
 */
final class K21RemoteSecretCleanupTest extends TestCase
{
    private const WORKER = 'phpunit-k21';
    private const EVENT_CLEARED = 'deploy.remote_secret_cleared';
    private const CONTACT_MESSAGE = 'will be removed at the next contact with this Ansible host';
    private const DIRECTORY_MESSAGE = 'Its ESXi access file will be removed at the next contact; the rest of the directory stays.';
    private const REMOVED = "VIRTUSPHERE_REMOTE_SECRET removed\n";
    private const ABSENT = "VIRTUSPHERE_REMOTE_SECRET absent\n";

    private mysqli $db;
    private string $token = '';
    private string $esxiSecret = '';
    private string $ansibleSecret = '';
    private int $missionId = 0;
    private int $esxiId = 0;
    private int $ansibleId = 0;
    private int $otherAnsibleId = 0;
    /** @var list<int> */
    private array $missionIds = [];
    /** @var list<int> */
    private array $credentialIds = [];
    /** @var list<int> */
    private array $jobIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->token = bin2hex(random_bytes(5));
        $this->esxiSecret = 'K21Esxi' . bin2hex(random_bytes(6));
        $this->ansibleSecret = 'K21Ansible' . bin2hex(random_bytes(6));
        $this->missionId = $this->insertMission('mission');
        $this->esxiId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443, 'esxi');
        $this->ansibleId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22, 'ansible');
        $this->otherAnsibleId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22, 'ansible-other');
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        // Without the table there are no rows of this test to remove.
        if ($this->secretTableExists()) {
            repo_execute($this->db, 'DELETE FROM deploy_remote_secret_files WHERE remote_dir LIKE ?', 's', [$this->remoteRoot() . '%']);
        }
        foreach ($this->jobIds as $jobId) {
            repo_execute($this->db, 'DELETE FROM deploy_logs WHERE event_code = ? AND object_id = ?', 'ss', [self::EVENT_CLEARED, (string) $jobId]);
        }
        // Deleting a mission cascades its jobs, VMs and create units.
        foreach ($this->missionIds as $missionId) {
            repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$missionId]);
        }
        foreach ($this->credentialIds as $credentialId) {
            repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$credentialId]);
        }
    }

    /**
     * T1 (command): the uncertain unit at job end gets exactly one file removal.
     * The directory is left alone because its async state is still evidence.
     */
    public function testUncertainUnitAtJobEndRunsExactlyOneFileRemovalAndLeavesTheUnitUncertain(): void
    {
        $jobId = $this->uncertainCreateJob();
        self::assertTrue(repo_finish_deploy_job($this->db, $jobId, self::WORKER, 'failed', 'unresolved create'));
        $remoteDir = $this->remoteDir($jobId);
        $before = $this->snapshot($jobId);
        $commands = [];

        $diagnostic = $this->captureErrorLog(function () use ($jobId, $remoteDir, &$commands): void {
            deploy_worker_cleanup_remote_dir($this->channel($jobId), $this->unreachableCredential(), $this->ansibleSecret, $remoteDir, false, $this->recorder($commands));
        });

        self::assertSame([ansible_remote_secret_remove_command($remoteDir)], $commands);
        self::assertStringContainsString('Remote job directory left in place', $diagnostic);
        self::assertSame('uncertain', repo_deploy_create_results($this->db, $jobId)[0]['status']);
        self::assertSame($before, $this->snapshot($jobId));
    }

    /** T1 (row): the confirmed removal forgets the row that the upload wrote. Needs the table. */
    public function testUncertainUnitAtJobEndForgetsTheRememberedRowOnConfirmedRemoval(): void
    {
        $jobId = $this->uncertainCreateJob();
        self::assertTrue(repo_finish_deploy_job($this->db, $jobId, self::WORKER, 'failed', 'unresolved create'));
        $remoteDir = $this->remoteDir($jobId);
        $this->seedRow($jobId, $this->ansibleId, $remoteDir);
        self::assertTrue($this->rowExists($jobId, $remoteDir));
        $commands = [];

        $this->captureErrorLog(function () use ($jobId, $remoteDir, &$commands): void {
            deploy_worker_cleanup_remote_dir($this->channel($jobId), $this->unreachableCredential(), $this->ansibleSecret, $remoteDir, false, $this->recorder($commands));
        });

        self::assertCount(1, $commands);
        self::assertFalse($this->rowExists($jobId, $remoteDir), 'a confirmed removal forgets its row');
        self::assertSame('uncertain', repo_deploy_create_results($this->db, $jobId)[0]['status']);
    }

    /** T2 (command): an idle host gets one recursive removal of the whole job directory. */
    public function testIdleHostRunsExactlyOneRecursiveRemovalOfTheJobDirectory(): void
    {
        $jobId = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $remoteDir = $this->remoteDir($jobId);
        $commands = [];

        $this->captureErrorLog(function () use ($jobId, $remoteDir, &$commands): void {
            deploy_worker_cleanup_remote_dir($this->channel($jobId), $this->unreachableCredential(), $this->ansibleSecret, $remoteDir, false, $this->recorder($commands));
        });

        self::assertSame([ansible_remote_secret_remove_command($remoteDir, true)], $commands);
    }

    /** T2 (row): the recursive removal of an idle host forgets the row too. Needs the table. */
    public function testIdleHostRemovalForgetsTheRememberedRow(): void
    {
        $jobId = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $remoteDir = $this->remoteDir($jobId);
        $this->seedRow($jobId, $this->ansibleId, $remoteDir);
        $commands = [];

        $this->captureErrorLog(function () use ($jobId, $remoteDir, &$commands): void {
            deploy_worker_cleanup_remote_dir($this->channel($jobId), $this->unreachableCredential(), $this->ansibleSecret, $remoteDir, false, $this->recorder($commands));
        });

        self::assertSame([ansible_remote_secret_remove_command($remoteDir, true)], $commands);
        self::assertFalse($this->rowExists($jobId, $remoteDir));
    }

    /** T3: a thrown removal keeps the row and tells the operator about the next contact. */
    public function testThrowingRemovalKeepsTheRowAndNamesTheNextContact(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $jobId = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $remoteDir = $this->remoteDir($jobId);
        $this->seedRow($jobId, $this->ansibleId, $remoteDir);
        $secret = $this->ansibleSecret;
        $commands = [];
        $execute = $this->recorder($commands, static function () use ($secret): array {
            throw new RuntimeException('ssh connection lost while removing with ' . $secret);
        });
        $removed = null;

        $diagnostic = $this->captureErrorLog(function () use (&$removed, $jobId, $remoteDir, $execute): void {
            $removed = deploy_remote_secret_release($this->connection(), $this->ansibleCredential(), $this->ansibleSecret, $jobId, $remoteDir, false, $execute);
        });

        self::assertFalse($removed);
        self::assertSame([ansible_remote_secret_remove_command($remoteDir)], $commands);
        self::assertTrue($this->rowExists($jobId, $remoteDir), 'an unconfirmed removal keeps its row');
        self::assertStringContainsString(self::CONTACT_MESSAGE, $diagnostic);
        self::assertStringNotContainsString($secret, $diagnostic);
        self::assertSame([], $this->clearedAuditRows($jobId));
    }

    /** T3: a host that answers with a non-zero exit code is not a confirmed removal either. */
    public function testNonZeroExitRemovalKeepsTheRowAndNamesTheNextContact(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $jobId = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $remoteDir = $this->remoteDir($jobId);
        $this->seedRow($jobId, $this->ansibleId, $remoteDir);
        $commands = [];
        $execute = $this->recorder($commands, static fn (): array => ['exit_code' => 1, 'output' => 'rm: cannot remove: permission denied']);
        $removed = null;

        $diagnostic = $this->captureErrorLog(function () use (&$removed, $jobId, $remoteDir, $execute): void {
            $removed = deploy_remote_secret_release($this->connection(), $this->ansibleCredential(), $this->ansibleSecret, $jobId, $remoteDir, false, $execute);
        });

        self::assertFalse($removed);
        self::assertCount(1, $commands);
        self::assertTrue($this->rowExists($jobId, $remoteDir));
        self::assertStringContainsString(self::CONTACT_MESSAGE, $diagnostic);
        self::assertSame([], $this->clearedAuditRows($jobId));
    }

    /** New: exit 0 without the state line is no confirmation; `rm -f` answers 0 for a path that never existed. */
    public function testReleaseWithoutAStateLineKeepsTheRow(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $jobId = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $remoteDir = $this->remoteDir($jobId);
        $this->seedRow($jobId, $this->ansibleId, $remoteDir);
        $commands = [];
        $removed = null;

        $diagnostic = $this->captureErrorLog(function () use (&$removed, $jobId, $remoteDir, &$commands): void {
            $removed = deploy_remote_secret_release($this->connection(), $this->ansibleCredential(), $this->ansibleSecret, $jobId, $remoteDir, false, $this->recorder($commands, static fn (): array => ['exit_code' => 0, 'output' => '']));
        });

        self::assertFalse($removed);
        self::assertTrue($this->rowExists($jobId, $remoteDir));
        self::assertStringContainsString('without confirming the removal', $diagnostic);
        self::assertStringContainsString(self::CONTACT_MESSAGE, $diagnostic);
    }

    /** New: a file that was already gone closes the row too, and the diagnostic says it was not there. */
    public function testReleaseOfAFileThatWasNoLongerThereForgetsTheRowAndSaysSo(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $jobId = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $remoteDir = $this->remoteDir($jobId);
        $this->seedRow($jobId, $this->ansibleId, $remoteDir);
        $commands = [];
        $removed = null;

        $diagnostic = $this->captureErrorLog(function () use (&$removed, $jobId, $remoteDir, &$commands): void {
            $removed = deploy_remote_secret_release($this->connection(), $this->ansibleCredential(), $this->ansibleSecret, $jobId, $remoteDir, false, $this->recorder($commands, static fn (): array => ['exit_code' => 0, 'output' => self::ABSENT]));
        });

        self::assertTrue($removed);
        self::assertFalse($this->rowExists($jobId, $remoteDir));
        self::assertStringContainsString('was not on the Ansible host any more', $diagnostic);
    }

    /** K21-A3: a failed release records its attempt and ends a running lease, so the sweep can take the row next. */
    public function testAFailedReleaseRecordsTheAttemptAndEndsTheLease(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $jobId = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $remoteDir = $this->remoteDir($jobId);
        $this->seedRow($jobId, $this->ansibleId, $remoteDir);
        repo_execute($this->db, 'UPDATE deploy_remote_secret_files SET lease_until = NOW(6) + INTERVAL 1 HOUR WHERE job_id = ?', 'i', [$jobId]);
        $commands = [];

        $this->captureErrorLog(function () use ($jobId, $remoteDir, &$commands): void {
            deploy_remote_secret_release($this->connection(), $this->ansibleCredential(), $this->ansibleSecret, $jobId, $remoteDir, false, $this->recorder($commands, static fn (): array => ['exit_code' => 1, 'output' => '']));
        });

        self::assertTrue($this->rowExists($jobId, $remoteDir));
        self::assertNotNull($this->rowValue($jobId, $remoteDir, 'last_attempt_at'));
        self::assertNull($this->rowValue($jobId, $remoteDir, 'lease_until'));
    }

    /** K21-A6: a failed directory removal promises only the access file, because the next contact removes nothing else. */
    public function testAFailedDirectoryRemovalPromisesOnlyTheAccessFile(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $jobId = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $remoteDir = $this->remoteDir($jobId);
        $this->seedRow($jobId, $this->ansibleId, $remoteDir);
        $commands = [];

        $diagnostic = $this->captureErrorLog(function () use ($jobId, $remoteDir, &$commands): void {
            deploy_remote_secret_release($this->connection(), $this->ansibleCredential(), $this->ansibleSecret, $jobId, $remoteDir, true, $this->recorder($commands, static function (): array {
                throw new RuntimeException('host went away');
            }));
        });

        self::assertSame([ansible_remote_secret_remove_command($remoteDir, true)], $commands);
        self::assertStringContainsString('Remote job directory ' . $remoteDir . ' could not be removed', $diagnostic);
        self::assertStringContainsString(self::DIRECTORY_MESSAGE, $diagnostic);
        self::assertStringNotContainsString('It will be removed at the next contact', $diagnostic);
        self::assertTrue($this->rowExists($jobId, $remoteDir));
    }

    /** K21-A2: the row records where the file went, read from the credential at upload time. */
    public function testRememberStoresTheTargetHostPortAndPinnedHostKey(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $this->pointCredentialAt($this->ansibleId, $this->hostFor('ansible'), 'SHA256:k21-pinned-old');
        $jobId = $this->runningJob($this->ansibleId, $this->missionId, 'start');
        $remoteDir = $this->remoteDir($jobId);

        deploy_remote_secret_remember($this->db, $jobId, $this->ansibleId, $remoteDir);

        self::assertSame($this->hostFor('ansible'), $this->rowValue($jobId, $remoteDir, 'target_host'));
        self::assertSame('22', $this->rowValue($jobId, $remoteDir, 'target_port'));
        self::assertSame('SHA256:k21-pinned-old', $this->rowValue($jobId, $remoteDir, 'target_fingerprint'));
        self::assertNull($this->rowValue($jobId, $remoteDir, 'lease_until'), 'a worker row has no lease; its active job protects it');
        self::assertNull($this->rowValue($jobId, $remoteDir, 'last_attempt_at'));
    }

    /** K21-A2: a second upload for the same job directory to another target is refused instead of moving the row. */
    public function testRememberRefusesTheSameJobDirectoryOnAnotherTarget(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $jobId = $this->finishedJob($this->ansibleId, $this->missionId, 'create');
        $remoteDir = $this->remoteDir($jobId);
        deploy_remote_secret_remember($this->db, $jobId, $this->ansibleId, $remoteDir);
        $this->pointCredentialAt($this->ansibleId, $this->hostFor('replacement'), 'SHA256:k21-pinned-new');

        try {
            deploy_remote_secret_remember($this->db, $jobId, $this->ansibleId, $remoteDir, VIRTUSPHERE_REMOTE_SECRET_STATUS_LEASE_SECONDS);
            self::fail('an upload to another host must not move the row of the first one');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($this->hostFor('ansible'), $exception->getMessage());
        }
        self::assertSame($this->hostFor('ansible'), $this->rowValue($jobId, $remoteDir, 'target_host'));
        self::assertNull($this->rowValue($jobId, $remoteDir, 'lease_until'));
    }

    /**
     * T3: the next job of the same Ansible credential removes what is left, and
     * only that: active jobs, other credentials and the current job keep theirs.
     */
    public function testNextContactSweepRemovesOnlyFinishedJobsOfTheSameCredentialAndAuditsEachOnce(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $leftover = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $leftoverDir = $this->remoteDir($leftover);
        $this->seedRow($leftover, $this->ansibleId, $leftoverDir);
        $active = $this->queuedJob($this->ansibleId, $this->missionId);
        $activeDir = $this->remoteDir($active);
        $this->seedRow($active, $this->ansibleId, $activeDir);
        $otherCredential = $this->finishedJob($this->otherAnsibleId, $this->missionId, 'start');
        $otherDir = $this->remoteDir($otherCredential);
        $this->seedRow($otherCredential, $this->otherAnsibleId, $otherDir);
        $current = $this->runningJob($this->ansibleId, $this->missionId, 'start');
        $currentDir = $this->remoteDir($current);
        $this->seedRow($current, $this->ansibleId, $currentDir);
        $commands = [];
        $messages = [];

        $removed = $this->sweep($current, ['execute' => $this->recorder($commands), 'log' => static function (string $line) use (&$messages): void {
            $messages[] = $line;
        }]);

        self::assertSame(1, $removed);
        self::assertSame([ansible_remote_secret_remove_command($leftoverDir)], $commands);
        self::assertFalse($this->rowExists($leftover, $leftoverDir));
        self::assertTrue($this->rowExists($active, $activeDir), 'an active job keeps its row');
        self::assertTrue($this->rowExists($otherCredential, $otherDir), 'another credential keeps its row');
        self::assertTrue($this->rowExists($current, $currentDir), 'the current job is excluded');
        self::assertSame(['Removed the ESXi access file that job ' . $leftover . ' left on the Ansible host.'], $messages);

        $audit = $this->clearedAuditRows($leftover);
        self::assertCount(1, $audit, 'one removal writes exactly one audit row');
        self::assertSame('deploy_job', $audit[0]['object_type']);
        self::assertSame('success', $audit[0]['result']);
        $context = json_decode((string) $audit[0]['context_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->ansibleId, (int) $context['credential_id']);
        self::assertSame($current, (int) $context['job_id']);
        self::assertSame('removed', $context['outcome']);
        self::assertSame($this->hostFor('ansible'), $context['host']);
        self::assertSame(22, (int) $context['port']);
        foreach ([$active, $otherCredential, $current] as $untouched) {
            self::assertSame([], $this->clearedAuditRows($untouched), 'no audit row for a job that was not swept');
        }
    }

    /** New: a file that was already gone closes its row with its own audit outcome and log line. */
    public function testNextContactSweepTellsARemovedFileFromOneThatWasAlreadyGone(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $present = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $presentDir = $this->remoteDir($present);
        $this->seedRow($present, $this->ansibleId, $presentDir);
        $gone = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $goneDir = $this->remoteDir($gone);
        $this->seedRow($gone, $this->ansibleId, $goneDir);
        $current = $this->runningJob($this->ansibleId, $this->missionId, 'start');
        $goneCommand = ansible_remote_secret_remove_command($goneDir);
        $commands = [];
        $messages = [];

        $removed = $this->sweep($current, [
            'execute' => $this->recorder($commands, static fn (string $command): array => ['exit_code' => 0, 'output' => $command === $goneCommand ? self::ABSENT : self::REMOVED]),
            'log' => static function (string $line) use (&$messages): void {
                $messages[] = $line;
            },
        ]);

        self::assertSame(2, $removed);
        self::assertFalse($this->rowExists($present, $presentDir));
        self::assertFalse($this->rowExists($gone, $goneDir));
        self::assertSame([
            'Removed the ESXi access file that job ' . $present . ' left on the Ansible host.',
            'The ESXi access file that job ' . $gone . ' left was no longer on the Ansible host; its record is closed.',
        ], $messages);
        $presentContext = json_decode((string) $this->clearedAuditRows($present)[0]['context_json'], true, 512, JSON_THROW_ON_ERROR);
        $goneContext = json_decode((string) $this->clearedAuditRows($gone)[0]['context_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('removed', $presentContext['outcome']);
        self::assertSame('absent', $goneContext['outcome']);
        self::assertSame($this->hostFor('ansible'), $goneContext['host']);
    }

    /** New: a sweep removal answered without the state line keeps its row and writes no audit. */
    public function testNextContactSweepWithoutAStateLineKeepsTheRowWithoutAudit(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $leftover = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $leftoverDir = $this->remoteDir($leftover);
        $this->seedRow($leftover, $this->ansibleId, $leftoverDir);
        $current = $this->runningJob($this->ansibleId, $this->missionId, 'start');
        $commands = [];

        $diagnostic = $this->captureErrorLog(function () use ($current, &$commands): void {
            $this->sweep($current, ['execute' => $this->recorder($commands, static fn (): array => ['exit_code' => 0, 'output' => ''])]);
        });

        self::assertCount(1, $commands);
        self::assertTrue($this->rowExists($leftover, $leftoverDir));
        self::assertSame([], $this->clearedAuditRows($leftover));
        self::assertNotNull($this->rowValue($leftover, $leftoverDir, 'last_attempt_at'));
        self::assertStringContainsString('without confirming the removal', $diagnostic);
    }

    /** T3: a row whose job was deleted with its mission has no job to protect it and is swept. */
    public function testNextContactSweepAlsoRemovesTheRowOfAJobWhoseMissionWasDeleted(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $doomedMission = $this->insertMission('doomed');
        $doomed = $this->finishedJob($this->ansibleId, $doomedMission, 'start');
        $doomedDir = $this->remoteDir($doomed);
        $this->seedRow($doomed, $this->ansibleId, $doomedDir);
        repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$doomedMission]);
        self::assertNull(repo_deploy_job($this->db, $doomed), 'deleting the mission cascades its job away');
        self::assertTrue($this->rowExists($doomed, $doomedDir), 'the row has no foreign key to its job and survives the cascade');
        $current = $this->runningJob($this->ansibleId, $this->missionId, 'start');
        $commands = [];

        $removed = $this->sweep($current, ['execute' => $this->recorder($commands)]);

        self::assertSame(1, $removed);
        self::assertSame([ansible_remote_secret_remove_command($doomedDir)], $commands);
        self::assertFalse($this->rowExists($doomed, $doomedDir));
        self::assertCount(1, $this->clearedAuditRows($doomed));
    }

    /** T3: a failing sweep removal keeps its row without an audit row and does not stop the sweep. */
    public function testFailedSweepRemovalKeepsItsRowWithoutAuditAndContinuesWithTheNextRow(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $failing = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $failingDir = $this->remoteDir($failing);
        $this->seedRow($failing, $this->ansibleId, $failingDir);
        $succeeding = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $succeedingDir = $this->remoteDir($succeeding);
        $this->seedRow($succeeding, $this->ansibleId, $succeedingDir);
        $current = $this->runningJob($this->ansibleId, $this->missionId, 'start');
        $failingCommand = ansible_remote_secret_remove_command($failingDir);
        $succeedingCommand = ansible_remote_secret_remove_command($succeedingDir);
        $commands = [];
        $messages = [];
        $execute = $this->recorder($commands, static fn (string $command): array => $command === $failingCommand
            ? ['exit_code' => 1, 'output' => 'rm: permission denied']
            : ['exit_code' => 0, 'output' => self::REMOVED]);
        $removed = null;

        $diagnostic = $this->captureErrorLog(function () use (&$removed, $current, $execute, &$messages): void {
            $removed = $this->sweep($current, ['execute' => $execute, 'log' => static function (string $line) use (&$messages): void {
                $messages[] = $line;
            }]);
        });

        self::assertSame(1, $removed);
        self::assertSame([$failingCommand, $succeedingCommand], $commands);
        self::assertTrue($this->rowExists($failing, $failingDir), 'the unconfirmed row stays');
        self::assertSame([], $this->clearedAuditRows($failing));
        self::assertFalse($this->rowExists($succeeding, $succeedingDir));
        self::assertCount(1, $this->clearedAuditRows($succeeding));
        self::assertSame(['Removed the ESXi access file that job ' . $succeeding . ' left on the Ansible host.'], $messages);
        self::assertStringContainsString('exit code 1', $diagnostic, 'the failed removal is diagnosed in the error log');
    }

    /**
     * K21-A2: after the credential was pointed at another host, the file of the
     * old host is out of reach. The sweep sends no command, keeps the row, writes
     * no audit and says where the file is.
     */
    public function testNextContactSweepKeepsTheRowOfAFileLeftOnAnotherHost(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $this->pointCredentialAt($this->ansibleId, $this->hostFor('ansible'), 'SHA256:k21-pinned-old');
        $leftover = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $leftoverDir = $this->remoteDir($leftover);
        deploy_remote_secret_remember($this->db, $leftover, $this->ansibleId, $leftoverDir);
        // What repo_update_credential() and the confirmation of the new host key leave behind.
        $this->pointCredentialAt($this->ansibleId, $this->hostFor('replacement'), 'SHA256:k21-pinned-new');
        $current = $this->runningJob($this->ansibleId, $this->missionId, 'start');
        $commands = [];
        $removed = null;

        $diagnostic = $this->captureErrorLog(function () use (&$removed, $current, &$commands): void {
            $removed = $this->sweep($current, ['execute' => $this->recorder($commands)]);
        });

        self::assertSame(0, $removed);
        self::assertSame([], $commands, 'no removal may run on a host that never had the file');
        self::assertTrue($this->rowExists($leftover, $leftoverDir));
        self::assertSame([], $this->clearedAuditRows($leftover));
        self::assertStringContainsString($this->hostFor('ansible'), $diagnostic);
        self::assertStringContainsString('kept', $diagnostic);
    }

    /** K21-A2: pointed back at the old host, the credential reaches the file again and the sweep closes it. */
    public function testNextContactSweepClosesTheRowOnceTheCredentialPointsBackToItsHost(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $this->pointCredentialAt($this->ansibleId, $this->hostFor('ansible'), 'SHA256:k21-pinned-old');
        $leftover = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $leftoverDir = $this->remoteDir($leftover);
        deploy_remote_secret_remember($this->db, $leftover, $this->ansibleId, $leftoverDir);
        $this->pointCredentialAt($this->ansibleId, $this->hostFor('replacement'), 'SHA256:k21-pinned-new');
        $current = $this->runningJob($this->ansibleId, $this->missionId, 'start');
        $commands = [];
        $this->captureErrorLog(function () use ($current, &$commands): void {
            $this->sweep($current, ['execute' => $this->recorder($commands)]);
        });
        self::assertSame([], $commands);
        $this->pointCredentialAt($this->ansibleId, $this->hostFor('ansible'), 'SHA256:k21-pinned-old');

        $removed = $this->sweep($current, ['execute' => $this->recorder($commands)]);

        self::assertSame(1, $removed);
        self::assertSame([ansible_remote_secret_remove_command($leftoverDir)], $commands);
        self::assertFalse($this->rowExists($leftover, $leftoverDir));
        $context = json_decode((string) $this->clearedAuditRows($leftover)[0]['context_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->hostFor('ansible'), $context['host']);
    }

    /** K21-A3: the first transport failure ends the sweep; the other rows wait for the next contact. */
    public function testNextContactSweepStopsAtTheFirstTransportFailure(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $dirs = [];
        foreach (range(1, 3) as $ignored) {
            $jobId = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
            $dirs[$jobId] = $this->remoteDir($jobId);
            $this->seedRow($jobId, $this->ansibleId, $dirs[$jobId]);
        }
        $current = $this->runningJob($this->ansibleId, $this->missionId, 'start');
        $commands = [];
        $removed = null;

        $diagnostic = $this->captureErrorLog(function () use (&$removed, $current, &$commands): void {
            $removed = $this->sweep($current, ['execute' => $this->recorder($commands, static function (): array {
                throw new RuntimeException('ssh connection could not be established');
            })]);
        });

        self::assertSame(0, $removed);
        self::assertCount(1, $commands, 'a host that cannot be reached is not asked again for every row');
        $first = array_key_first($dirs);
        self::assertNotNull($this->rowValue($first, $dirs[$first], 'last_attempt_at'));
        foreach ($dirs as $jobId => $dir) {
            self::assertTrue($this->rowExists($jobId, $dir));
            if ($jobId !== $first) {
                self::assertNull($this->rowValue($jobId, $dir, 'last_attempt_at'), 'a row that was not tried keeps its place');
            }
        }
        self::assertStringContainsString('next contact', $diagnostic);
    }

    /**
     * K21-A3: rows whose removal keeps failing must not block a newer one for
     * ever. A failed attempt moves its row behind the rows never tried.
     */
    public function testRowsThatKeepFailingDoNotBlockANewerRowAtTheNextSweep(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $stuck = [];
        foreach (range(1, VIRTUSPHERE_REMOTE_SECRET_SWEEP_LIMIT) as $ignored) {
            $jobId = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
            $stuck[$jobId] = $this->remoteDir($jobId);
            $this->seedRow($jobId, $this->ansibleId, $stuck[$jobId]);
        }
        $current = $this->runningJob($this->ansibleId, $this->missionId, 'start');
        $this->captureErrorLog(function () use ($current): void {
            $ignoredCommands = [];
            $this->sweep($current, ['execute' => $this->recorder($ignoredCommands, static fn (): array => ['exit_code' => 1, 'output' => 'rm: permission denied'])]);
        });
        $newer = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $newerDir = $this->remoteDir($newer);
        $this->seedRow($newer, $this->ansibleId, $newerDir);
        $newerCommand = ansible_remote_secret_remove_command($newerDir);
        $commands = [];

        $this->captureErrorLog(function () use ($current, $newerCommand, &$commands): void {
            $this->sweep($current, ['execute' => $this->recorder($commands, static fn (string $command): array => $command === $newerCommand
                ? ['exit_code' => 0, 'output' => self::REMOVED]
                : ['exit_code' => 1, 'output' => 'rm: permission denied'])]);
        });

        self::assertSame($newerCommand, $commands[0] ?? null, 'the row never tried comes first');
        self::assertFalse($this->rowExists($newer, $newerDir));
        foreach ($stuck as $jobId => $dir) {
            self::assertTrue($this->rowExists($jobId, $dir));
        }
    }

    /** K21-A3: the sweep keeps the job alive: one heartbeat per candidate. */
    public function testNextContactSweepTicksOncePerCandidate(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        foreach (range(1, 3) as $ignored) {
            $jobId = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
            $this->seedRow($jobId, $this->ansibleId, $this->remoteDir($jobId));
        }
        $current = $this->runningJob($this->ansibleId, $this->missionId, 'start');
        $commands = [];
        $ticks = 0;

        $this->sweep($current, ['execute' => $this->recorder($commands), 'tick' => static function () use (&$ticks): void {
            $ticks++;
        }]);

        self::assertCount(3, $commands);
        self::assertSame(3, $ticks);
    }

    /**
     * K21-A3: the database handle is taken per use from the callable. When the
     * database goes away after the host removed the file, the diagnostic says
     * the file is gone and only its record is open, not that the removal failed.
     */
    public function testARemovedFileWhoseRecordCannotBeClosedIsNotReportedAsAFailedRemoval(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $leftover = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $leftoverDir = $this->remoteDir($leftover);
        $this->seedRow($leftover, $this->ansibleId, $leftoverDir);
        $current = $this->runningJob($this->ansibleId, $this->missionId, 'start');
        // An object, not a by-reference bool: the flag flips inside the execute seam.
        $database = new ArrayObject(['gone' => false]);
        $connection = function () use ($database): mysqli {
            if ($database['gone']) {
                throw new RuntimeException('database connection lost');
            }

            return $this->db;
        };
        $commands = [];
        $removed = null;

        $diagnostic = $this->captureErrorLog(function () use (&$removed, $current, $connection, $database, &$commands): void {
            $removed = deploy_remote_secret_sweep($connection, $this->ansibleCredential(), $this->ansibleSecret, $current, [
                'execute' => $this->recorder($commands, static function () use ($database): array {
                    $database['gone'] = true;

                    return ['exit_code' => 0, 'output' => self::REMOVED];
                }),
            ]);
        });

        self::assertSame(0, $removed);
        self::assertCount(1, $commands);
        self::assertTrue($this->rowExists($leftover, $leftoverDir), 'the record could not be closed');
        self::assertStringContainsString('its record could not be closed', $diagnostic);
        self::assertStringNotContainsString('could not be removed', $diagnostic);
    }

    /**
     * K21-A4: a status query outside the job holds a lease on its row. A sweep of
     * another job of the same credential that runs while the query uploads leaves
     * that row alone and writes no audit row for a leftover that is not one.
     */
    public function testASweepDuringAStatusQueryLeavesTheLeasedRowAlone(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $this->requireK21Module('lib/deploy_create_status_outside_job.php');
        $jobId = $this->uncertainCreateJob();
        self::assertTrue(repo_finish_deploy_job($this->db, $jobId, self::WORKER, 'failed', 'unresolved create'));
        $unit = repo_deploy_create_results($this->db, $jobId)[0];
        $remoteDir = $this->remoteDir($jobId);
        $other = $this->runningJob($this->ansibleId, $this->missionId, 'start');
        $marker = $this->succeededMarker($unit);
        $sweepCommands = [];
        $commands = [];
        $upload = function () use ($other, &$sweepCommands): void {
            $this->sweep($other, ['execute' => $this->recorder($sweepCommands)]);
        };

        $result = deploy_create_status_query_outside_job(
            $this->db,
            $unit,
            $remoteDir,
            $this->esxiCredential(),
            $this->esxiSecret,
            $this->ansibleCredential(),
            $this->ansibleSecret,
            'https://portal.example.invalid',
            ['upload' => $upload, 'execute' => $this->recorder($commands, $this->statusAnswer("PLAY RECAP\n" . $marker . "\n"))]
        );

        self::assertSame([], $sweepCommands, 'the sweep must not remove the file of a running status query');
        self::assertSame([], $this->clearedAuditRows($jobId));
        self::assertTrue($result['secret_removed']);
        self::assertFalse($this->rowExists($jobId, $remoteDir), 'the query itself removes its file afterwards');
    }

    /** K21-A4: a lease that ran out is not protection any more; the row is swept like any other. */
    public function testAnExpiredLeaseIsSweptAgain(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $leftover = $this->finishedJob($this->ansibleId, $this->missionId, 'start');
        $leftoverDir = $this->remoteDir($leftover);
        $this->seedRow($leftover, $this->ansibleId, $leftoverDir);
        repo_execute($this->db, 'UPDATE deploy_remote_secret_files SET lease_until = NOW(6) - INTERVAL 1 SECOND WHERE job_id = ?', 'i', [$leftover]);
        $current = $this->runningJob($this->ansibleId, $this->missionId, 'start');
        $commands = [];

        $removed = $this->sweep($current, ['execute' => $this->recorder($commands)]);

        self::assertSame(1, $removed);
        self::assertFalse($this->rowExists($leftover, $leftoverDir));
    }

    /**
     * T4: after the job ended, a status query uploads only accounts.yml in a
     * private local directory, with its row written before the first byte goes
     * out, and removes the file again afterwards.
     */
    public function testStatusQueryUploadsOnlyAccountsYmlWithItsRowRegisteredFirstAndRemovesItAfterwards(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $this->requireK21Module('lib/deploy_create_status_outside_job.php');
        $jobId = $this->uncertainCreateJob();
        self::assertTrue(repo_finish_deploy_job($this->db, $jobId, self::WORKER, 'failed', 'unresolved create'));
        $unit = repo_deploy_create_results($this->db, $jobId)[0];
        $remoteDir = $this->remoteDir($jobId);
        $rowsBefore = repo_deploy_create_results($this->db, $jobId);
        $logsBefore = $this->jobLogCount($jobId);
        $marker = $this->succeededMarker($unit);
        $uploads = [];
        $commands = [];

        $result = deploy_create_status_query_outside_job(
            $this->db,
            $unit,
            $remoteDir,
            $this->esxiCredential(),
            $this->esxiSecret,
            $this->ansibleCredential(),
            $this->ansibleSecret,
            'https://portal.example.invalid',
            [
                'upload' => $this->recordingUpload($uploads, $jobId),
                'execute' => $this->recorder($commands, $this->statusAnswer("PLAY RECAP\n" . $marker . "\n")),
            ]
        );

        self::assertCount(1, $uploads, 'one upload per status query');
        self::assertSame($remoteDir, $uploads[0]['remoteDir']);
        self::assertSame(['accounts.yml'], $uploads[0]['files']);
        self::assertStringContainsString($this->esxiSecret, $uploads[0]['content']);
        self::assertTrue($uploads[0]['rowBeforeUpload'], 'the row must exist before the upload starts');
        self::assertTrue($uploads[0]['leasedDuringUpload'], 'the row carries a running lease while the query runs');
        self::assertFalse(is_dir($uploads[0]['localDir']), 'the local temporary directory is removed afterwards');
        self::assertCount(2, $commands);
        self::assertStringContainsString(VIRTUSPHERE_CREATE_PLAYBOOK_STATUS, $commands[0]);
        self::assertSame(ansible_remote_secret_remove_command($remoteDir), $commands[1]);
        self::assertNull($result['error']);
        self::assertTrue($result['secret_removed']);
        self::assertIsArray($result['marker']);
        self::assertSame(VIRTUSPHERE_CREATE_EVENT_SUCCEEDED, $result['marker']['event']);
        self::assertTrue(ansible_create_marker_matches($result['marker'], (int) $unit['vm_id'], (string) $unit['vm_name'], '123456789.1'));
        self::assertFalse($this->rowExists($jobId, $remoteDir));
        self::assertSame($rowsBefore, repo_deploy_create_results($this->db, $jobId), 'the status query writes nothing to the unit');
        self::assertSame($logsBefore, $this->jobLogCount($jobId), 'the status query writes nothing to the job log');
    }

    /** T5: a failing status command still removes accounts.yml, and its error is redacted. */
    public function testFailedStatusCommandStillRemovesAccountsYmlAndKeepsTheRedactedError(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $this->requireK21Module('lib/deploy_create_status_outside_job.php');
        $jobId = $this->uncertainCreateJob();
        self::assertTrue(repo_finish_deploy_job($this->db, $jobId, self::WORKER, 'failed', 'unresolved create'));
        $unit = repo_deploy_create_results($this->db, $jobId)[0];
        $remoteDir = $this->remoteDir($jobId);
        $secret = $this->ansibleSecret;
        $uploads = [];
        $commands = [];
        $execute = $this->recorder($commands, static function (string $command) use ($secret): array {
            if (str_contains($command, VIRTUSPHERE_CREATE_PLAYBOOK_STATUS)) {
                throw new RuntimeException('ssh session lost during the status check with ' . $secret);
            }

            return ['exit_code' => 0, 'output' => self::REMOVED];
        });

        $result = deploy_create_status_query_outside_job(
            $this->db,
            $unit,
            $remoteDir,
            $this->esxiCredential(),
            $this->esxiSecret,
            $this->ansibleCredential(),
            $this->ansibleSecret,
            'https://portal.example.invalid',
            ['upload' => $this->recordingUpload($uploads, $jobId), 'execute' => $execute]
        );

        self::assertCount(1, $uploads);
        self::assertCount(2, $commands);
        self::assertStringContainsString(VIRTUSPHERE_CREATE_PLAYBOOK_STATUS, $commands[0]);
        self::assertSame(ansible_remote_secret_remove_command($remoteDir), $commands[1]);
        self::assertNull($result['marker']);
        self::assertIsString($result['error']);
        self::assertStringNotContainsString($secret, $result['error']);
        self::assertTrue($result['secret_removed']);
        self::assertFalse($this->rowExists($jobId, $remoteDir));
    }

    /** T5: when the removal after a status query fails, the row stays for the next contact. */
    public function testFailedRemovalAfterStatusQueryKeepsTheRowForTheNextContact(): void
    {
        $this->requireK21Module('lib/deploy_remote_secret.php');
        $this->requireK21Module('lib/deploy_create_status_outside_job.php');
        $jobId = $this->uncertainCreateJob();
        self::assertTrue(repo_finish_deploy_job($this->db, $jobId, self::WORKER, 'failed', 'unresolved create'));
        $unit = repo_deploy_create_results($this->db, $jobId)[0];
        $remoteDir = $this->remoteDir($jobId);
        $removal = ansible_remote_secret_remove_command($remoteDir);
        $marker = $this->succeededMarker($unit);
        $uploads = [];
        $commands = [];
        $execute = $this->recorder($commands, static function (string $command) use ($removal, $marker): array {
            if ($command === $removal) {
                return ['exit_code' => 1, 'output' => 'rm: cannot remove: permission denied'];
            }

            return ['exit_code' => 0, 'output' => "PLAY RECAP\n" . $marker . "\n"];
        });
        $result = [];

        $diagnostic = $this->captureErrorLog(function () use (&$result, $unit, $remoteDir, $execute, &$uploads, $jobId): void {
            $result = deploy_create_status_query_outside_job(
                $this->db,
                $unit,
                $remoteDir,
                $this->esxiCredential(),
                $this->esxiSecret,
                $this->ansibleCredential(),
                $this->ansibleSecret,
                'https://portal.example.invalid',
                ['upload' => $this->recordingUpload($uploads, $jobId), 'execute' => $execute]
            );
        });

        self::assertFalse($result['secret_removed']);
        self::assertSame(VIRTUSPHERE_CREATE_EVENT_SUCCEEDED, $result['marker']['event'] ?? null, 'a failed removal does not invalidate the status answer');
        self::assertTrue($this->rowExists($jobId, $remoteDir), 'an unconfirmed removal keeps its row');
        self::assertNull($this->rowValue($jobId, $remoteDir, 'lease_until'), 'the lease ends with the query, so the next contact can take the row');
        self::assertStringContainsString(self::CONTACT_MESSAGE, $diagnostic);
    }

    /** Loads a K21 module by path. A missing module fails this test by name instead of aborting the run with a fatal require. */
    private function requireK21Module(string $relative): void
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path, 'K21 module is missing: ' . $relative);
        require_once $path;
    }

    /** @param array<string, callable> $seams */
    private function sweep(int $currentJobId, array $seams): int
    {
        return deploy_remote_secret_sweep($this->connection(), $this->ansibleCredential(), $this->ansibleSecret, $currentJobId, $seams);
    }

    private function prefix(): string
    {
        return 'phpunit_k21_' . $this->token;
    }

    private function remoteRoot(): string
    {
        return '/tmp/vs-k21-' . $this->token . '/';
    }

    private function remoteDir(int $jobId): string
    {
        return rtrim($this->remoteRoot(), '/') . '/job-' . $jobId;
    }

    private function hostFor(string $label): string
    {
        return $label . '-' . $this->token . '.example.invalid';
    }

    /** @return array<string, mixed> */
    private function ansibleCredential(): array
    {
        return ['id' => $this->ansibleId, 'type' => VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 'host' => $this->hostFor('ansible'), 'port' => 22, 'username' => 'svc'];
    }

    /**
     * Without a username the real SSH helper fails before any connection, so a
     * cleanup test that ignored its $execute seam could not reach a host either.
     *
     * @return array<string, mixed>
     */
    private function unreachableCredential(): array
    {
        return ['id' => $this->ansibleId, 'host' => 'never-connect.example.invalid'];
    }

    /** @return array<string, mixed> */
    private function esxiCredential(): array
    {
        return ['id' => $this->esxiId, 'type' => VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 'host' => $this->hostFor('esxi'), 'port' => 443, 'username' => 'svc'];
    }

    private function connection(): callable
    {
        return fn (): mysqli => $this->db;
    }

    private function channel(int $jobId): DeployWorkerDbChannel
    {
        return new DeployWorkerDbChannel($this->db, fn (): mysqli => $this->db, $jobId, self::WORKER);
    }

    private function insertMission(string $suffix): int
    {
        repo_execute($this->db, "INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, 'active')", 's', [$this->prefix() . '_' . $suffix]);
        $missionId = (int) $this->db->insert_id;
        $this->missionIds[] = $missionId;

        return $missionId;
    }

    private function insertCredential(string $type, int $port, string $label): int
    {
        $name = $this->prefix() . '_' . $label;
        $host = $this->hostFor($label);
        $username = 'svc';
        $ciphertext = 'ciphertext';
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssiss', $type, $name, $host, $port, $username, $ciphertext);
        $stmt->execute();
        $credentialId = (int) $this->db->insert_id;
        $this->credentialIds[] = $credentialId;

        return $credentialId;
    }

    /** Sets the target of an Ansible credential: host and pinned host key, as an edit plus a confirmed key leave it. */
    private function pointCredentialAt(int $credentialId, string $host, ?string $fingerprint): void
    {
        repo_execute($this->db, 'UPDATE deploy_credentials SET host = ?, ansible_host_fingerprint = ? WHERE id = ?', 'ssi', [$host, $fingerprint, $credentialId]);
    }

    private function insertJob(int $missionId, string $mode, string $status, int $ansibleId): int
    {
        $payload = json_encode(['mode' => $mode, 'vm_ids' => []], JSON_THROW_ON_ERROR);
        $lockedBy = $status === VIRTUSPHERE_DEPLOY_STATUS_RUNNING ? self::WORKER : null;
        $esxiId = $this->esxiId;
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_jobs (mission_id, status, credential_esxi_id, credential_ansible_id, locked_by, locked_at, heartbeat_at, payload_json) '
            . 'VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)'
        );
        $stmt->bind_param('isiiss', $missionId, $status, $esxiId, $ansibleId, $lockedBy, $payload);
        $stmt->execute();
        $jobId = (int) $this->db->insert_id;
        $this->jobIds[] = $jobId;

        return $jobId;
    }

    private function runningJob(int $ansibleId, int $missionId, string $mode): int
    {
        return $this->insertJob($missionId, $mode, VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $ansibleId);
    }

    private function queuedJob(int $ansibleId, int $missionId): int
    {
        return $this->insertJob($missionId, 'start', VIRTUSPHERE_DEPLOY_STATUS_QUEUED, $ansibleId);
    }

    /** A job whose worker wrote its terminal status: the state every job has when its ESXi file is due for removal. */
    private function finishedJob(int $ansibleId, int $missionId, string $mode): int
    {
        $jobId = $this->runningJob($ansibleId, $missionId, $mode);
        self::assertTrue(repo_finish_deploy_job($this->db, $jobId, self::WORKER, 'failed', 'fixture job end'));

        return $jobId;
    }

    /** A create job whose one unit ended uncertain with a known async job. */
    private function uncertainCreateJob(): int
    {
        $vmName = strtoupper($this->prefix() . '_vm_' . bin2hex(random_bytes(3)));
        repo_execute($this->db, 'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)', 'iss', [$this->missionId, $vmName, $vmName]);
        $vmId = (int) $this->db->insert_id;
        $jobId = $this->runningJob($this->ansibleId, $this->missionId, 'create');
        repo_deploy_create_materialize($this->db, $jobId, [['id' => $vmId, 'vm_name' => $vmName]]);
        repo_execute(
            $this->db,
            "UPDATE deploy_create_vm_results SET status = 'uncertain', async_jid = '123456789.1', error_code = ?, error_detail = 'fixture unresolved result', finished_at = NOW() WHERE job_id = ?",
            'si',
            [VIRTUSPHERE_CREATE_ERROR_JOB_TIMEOUT, $jobId]
        );

        return $jobId;
    }

    /**
     * The row the upload of a job writes, with the target its credential has now.
     * A direct insert keeps this fixture independent of the writer under test.
     */
    private function seedRow(int $jobId, int $ansibleId, string $remoteDir): void
    {
        repo_execute(
            $this->db,
            'INSERT INTO deploy_remote_secret_files (job_id, credential_ansible_id, remote_dir, target_host, target_port, target_fingerprint)'
                . ' SELECT ?, c.id, ?, c.host, COALESCE(c.port, 22), c.ansible_host_fingerprint FROM deploy_credentials c WHERE c.id = ?',
            'isi',
            [$jobId, $remoteDir, $ansibleId]
        );
    }

    private function rowExists(int $jobId, string $remoteDir): bool
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_remote_secret_files WHERE job_id = ? AND remote_dir = ?', 'is', [$jobId, $remoteDir]) > 0;
    }

    private function rowValue(int $jobId, string $remoteDir, string $column): ?string
    {
        $row = repo_fetch_one($this->db, 'SELECT ' . $column . ' AS value FROM deploy_remote_secret_files WHERE job_id = ? AND remote_dir = ?', 'is', [$jobId, $remoteDir]);
        self::assertNotNull($row, 'no remembered row for job ' . $jobId);

        return $row['value'] === null ? null : (string) $row['value'];
    }

    private function secretTableExists(): bool
    {
        $result = $this->db->query("SHOW TABLES LIKE 'deploy_remote_secret_files'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    /** @return list<array{object_type: string, object_id: string, result: string, context_json: string|null}> */
    private function clearedAuditRows(int $jobId): array
    {
        $stmt = $this->db->prepare('SELECT object_type, object_id, result, context_json FROM deploy_logs WHERE event_code = ? AND object_id = ? ORDER BY id');
        $code = self::EVENT_CLEARED;
        $objectId = (string) $jobId;
        $stmt->bind_param('ss', $code, $objectId);
        $stmt->execute();

        return repo_fetch_all($stmt->get_result());
    }

    private function jobLogCount(int $jobId): int
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_job_logs WHERE job_id = ?', 'i', [$jobId]);
    }

    private function snapshot(int $jobId): array
    {
        $stmt = $this->db->prepare('SELECT seq, stream, line FROM deploy_job_logs WHERE job_id = ? ORDER BY seq');
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $logs = repo_fetch_all($stmt->get_result());
        $stmt->close();

        return ['job' => repo_deploy_job($this->db, $jobId), 'logs' => $logs];
    }

    /**
     * Records each command and answers it. Without $respond every command is a
     * confirmed removal of a file that was there.
     *
     * @param list<string> $commands
     */
    private function recorder(array &$commands, ?callable $respond = null): callable
    {
        return function (array $credential, string $secret, string $command, int $timeout = 20) use (&$commands, $respond): array {
            $commands[] = $command;

            return $respond === null ? ['exit_code' => 0, 'output' => self::REMOVED] : $respond($command);
        };
    }

    /** Answers the status command with $statusOutput and the removal afterwards with its state line. */
    private function statusAnswer(string $statusOutput): callable
    {
        return static fn (string $command): array => str_contains($command, VIRTUSPHERE_CREATE_PLAYBOOK_STATUS)
            ? ['exit_code' => 0, 'output' => $statusOutput]
            : ['exit_code' => 0, 'output' => self::REMOVED];
    }

    /**
     * Records each upload: the local directory's file names and content, the
     * remote directory, and whether the row existed (and was leased) when the
     * upload started.
     *
     * @param list<array<string, mixed>> $uploads
     */
    private function recordingUpload(array &$uploads, int $jobId): callable
    {
        return function (array $credential, string $secret, string $localDir, string $remoteDir, ?callable $logger = null, ?callable $now = null) use (&$uploads, $jobId): void {
            $file = $localDir . '/accounts.yml';
            $exists = $this->rowExists($jobId, $remoteDir);
            $uploads[] = [
                'localDir' => $localDir,
                'remoteDir' => $remoteDir,
                'files' => $this->listFiles($localDir),
                'content' => is_file($file) ? (string) file_get_contents($file) : '',
                'rowBeforeUpload' => $exists,
                'leasedDuringUpload' => $exists && (int) repo_scalar(
                    $this->db,
                    'SELECT COUNT(*) FROM deploy_remote_secret_files WHERE job_id = ? AND remote_dir = ? AND lease_until > NOW(6)',
                    'is',
                    [$jobId, $remoteDir]
                ) > 0,
            ];
        };
    }

    /** @return list<string> */
    private function listFiles(string $dir): array
    {
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    /** @param array<string, mixed> $unit */
    private function succeededMarker(array $unit): string
    {
        return $this->marker([
            'event' => VIRTUSPHERE_CREATE_EVENT_SUCCEEDED,
            'portal_vm_id' => (int) $unit['vm_id'],
            'vm_name' => (string) $unit['vm_name'],
            'async_jid' => '123456789.1',
            'changed' => false,
            'moid' => 'vm-' . $unit['vm_id'],
            'instance_uuid' => '5001-' . $unit['vm_id'],
            'power_state' => 'poweredOff',
        ]);
    }

    /** One create marker line in the wire format of emit_create_result.py (see AnsibleCreateProtocolTest). */
    private function marker(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return VIRTUSPHERE_CREATE_MARKER_PREFIX . ' v' . VIRTUSPHERE_CREATE_PROTOCOL_VERSION . ' '
            . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** Redirect inside the test body: PHPUnit starts its own capture after setUp(). */
    private function captureErrorLog(callable $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vs-k21-diagnostic-');
        self::assertNotFalse($path);
        $previous = ini_get('error_log');
        self::assertNotFalse(ini_set('error_log', $path));
        try {
            $body();

            return (string) file_get_contents($path);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            unlink($path);
        }
    }
}
