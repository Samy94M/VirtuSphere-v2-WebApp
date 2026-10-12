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
 * credential removes the file and writes one audit row.
 *
 * Runs against a real MySQL server. The SSH layer is replaced by recording fakes
 * through the $execute and $upload seams, so no host is ever contacted.
 *
 * Which methods fail today: the two command tests (uncertain unit, idle host)
 * load only the cleanup entry point and fail on the command they record. The
 * other methods need deploy_remote_secret_files and the K21 modules; a missing
 * module fails its test by name (requireK21Module) instead of aborting the run
 * with a fatal require.
 */
final class K21RemoteSecretCleanupTest extends TestCase
{
    private const WORKER = 'phpunit-k21';
    private const EVENT_REMOVED = 'deploy.remote_secret_removed';
    private const CONTACT_MESSAGE = 'will be removed at the next contact with this Ansible host';

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
            repo_execute($this->db, 'DELETE FROM deploy_logs WHERE event_code = ? AND object_id = ?', 'ss', [self::EVENT_REMOVED, (string) $jobId]);
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

        self::assertSame(["rm -f -- '" . $remoteDir . "/accounts.yml'"], $commands);
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

        self::assertSame(["rm -rf -- '" . $remoteDir . "'"], $commands);
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

        self::assertSame(["rm -rf -- '" . $remoteDir . "'"], $commands);
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
        self::assertSame(["rm -f -- '" . $remoteDir . "/accounts.yml'"], $commands);
        self::assertTrue($this->rowExists($jobId, $remoteDir), 'an unconfirmed removal keeps its row');
        self::assertStringContainsString(self::CONTACT_MESSAGE, $diagnostic);
        self::assertStringNotContainsString($secret, $diagnostic);
        self::assertSame([], $this->removalAuditRows($jobId));
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
        self::assertSame([], $this->removalAuditRows($jobId));
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

        $removed = deploy_remote_secret_sweep(
            $this->db,
            $this->ansibleCredential(),
            $this->ansibleSecret,
            $current,
            $this->recorder($commands),
            static function (string $line) use (&$messages): void {
                $messages[] = $line;
            }
        );

        self::assertSame(1, $removed);
        self::assertSame(["rm -f -- '" . $leftoverDir . "/accounts.yml'"], $commands);
        self::assertFalse($this->rowExists($leftover, $leftoverDir));
        self::assertTrue($this->rowExists($active, $activeDir), 'an active job keeps its row');
        self::assertTrue($this->rowExists($otherCredential, $otherDir), 'another credential keeps its row');
        self::assertTrue($this->rowExists($current, $currentDir), 'the current job is excluded');
        self::assertSame(['Removed the ESXi access file that job ' . $leftover . ' left on the Ansible host.'], $messages);

        $audit = $this->removalAuditRows($leftover);
        self::assertCount(1, $audit, 'one removal writes exactly one audit row');
        self::assertSame('deploy_job', $audit[0]['object_type']);
        self::assertSame('success', $audit[0]['result']);
        $context = json_decode((string) $audit[0]['context_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->ansibleId, (int) $context['credential_id']);
        self::assertSame($current, (int) $context['job_id']);
        foreach ([$active, $otherCredential, $current] as $untouched) {
            self::assertSame([], $this->removalAuditRows($untouched), 'no audit row for a job that was not swept');
        }
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

        $removed = deploy_remote_secret_sweep($this->db, $this->ansibleCredential(), $this->ansibleSecret, $current, $this->recorder($commands));

        self::assertSame(1, $removed);
        self::assertSame(["rm -f -- '" . $doomedDir . "/accounts.yml'"], $commands);
        self::assertFalse($this->rowExists($doomed, $doomedDir));
        self::assertCount(1, $this->removalAuditRows($doomed));
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
        $failingCommand = "rm -f -- '" . $failingDir . "/accounts.yml'";
        $succeedingCommand = "rm -f -- '" . $succeedingDir . "/accounts.yml'";
        $commands = [];
        $messages = [];
        $execute = $this->recorder($commands, static fn (string $command): array => $command === $failingCommand
            ? ['exit_code' => 1, 'output' => 'rm: permission denied']
            : ['exit_code' => 0, 'output' => '']);
        $removed = null;

        $diagnostic = $this->captureErrorLog(function () use (&$removed, $current, $execute, &$messages): void {
            $removed = deploy_remote_secret_sweep(
                $this->db,
                $this->ansibleCredential(),
                $this->ansibleSecret,
                $current,
                $execute,
                static function (string $line) use (&$messages): void {
                    $messages[] = $line;
                }
            );
        });

        self::assertSame(1, $removed);
        self::assertSame([$failingCommand, $succeedingCommand], $commands);
        self::assertTrue($this->rowExists($failing, $failingDir), 'the unconfirmed row stays');
        self::assertSame([], $this->removalAuditRows($failing));
        self::assertFalse($this->rowExists($succeeding, $succeedingDir));
        self::assertCount(1, $this->removalAuditRows($succeeding));
        self::assertSame(['Removed the ESXi access file that job ' . $succeeding . ' left on the Ansible host.'], $messages);
        self::assertNotSame('', $diagnostic, 'the failed removal is diagnosed in the error log');
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
                'execute' => $this->recorder($commands, static fn (): array => ['exit_code' => 0, 'output' => "PLAY RECAP\n" . $marker . "\n"]),
            ]
        );

        self::assertCount(1, $uploads, 'one upload per status query');
        self::assertSame($remoteDir, $uploads[0]['remoteDir']);
        self::assertSame(['accounts.yml'], $uploads[0]['files']);
        self::assertStringContainsString($this->esxiSecret, $uploads[0]['content']);
        self::assertTrue($uploads[0]['rowBeforeUpload'], 'the row must exist before the upload starts');
        self::assertFalse(is_dir($uploads[0]['localDir']), 'the local temporary directory is removed afterwards');
        self::assertCount(2, $commands);
        self::assertStringContainsString(VIRTUSPHERE_CREATE_PLAYBOOK_STATUS, $commands[0]);
        self::assertSame("rm -f -- '" . $remoteDir . "/accounts.yml'", $commands[1]);
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

            return ['exit_code' => 0, 'output' => ''];
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
        self::assertSame("rm -f -- '" . $remoteDir . "/accounts.yml'", $commands[1]);
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
        $removal = "rm -f -- '" . $remoteDir . "/accounts.yml'";
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
        self::assertStringContainsString(self::CONTACT_MESSAGE, $diagnostic);
    }

    /** Loads a K21 module by path. A missing module fails this test by name instead of aborting the run with a fatal require. */
    private function requireK21Module(string $relative): void
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path, 'K21 module is missing: ' . $relative);
        require_once $path;
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
        $stmt->bind_param('ssisss', $type, $name, $host, $port, $username, $ciphertext);
        $stmt->execute();
        $credentialId = (int) $this->db->insert_id;
        $this->credentialIds[] = $credentialId;

        return $credentialId;
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
     * The row the upload of a job writes. A direct insert keeps this fixture
     * independent of the writer under test.
     */
    private function seedRow(int $jobId, ?int $ansibleId, string $remoteDir): void
    {
        repo_execute($this->db, 'INSERT INTO deploy_remote_secret_files (job_id, credential_ansible_id, remote_dir) VALUES (?, ?, ?)', 'iis', [$jobId, $ansibleId, $remoteDir]);
    }

    private function rowExists(int $jobId, string $remoteDir): bool
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_remote_secret_files WHERE job_id = ? AND remote_dir = ?', 'is', [$jobId, $remoteDir]) > 0;
    }

    private function secretTableExists(): bool
    {
        $result = $this->db->query("SHOW TABLES LIKE 'deploy_remote_secret_files'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    /** @return list<array{object_type: string, object_id: string, result: string, context_json: string|null}> */
    private function removalAuditRows(int $jobId): array
    {
        $stmt = $this->db->prepare('SELECT object_type, object_id, result, context_json FROM deploy_logs WHERE event_code = ? AND object_id = ? ORDER BY id');
        $code = self::EVENT_REMOVED;
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
     * Records each command and answers it. Without $respond every command succeeds.
     *
     * @param list<string> $commands
     */
    private function recorder(array &$commands, ?callable $respond = null): callable
    {
        return function (array $credential, string $secret, string $command, int $timeout = 20) use (&$commands, $respond): array {
            $commands[] = $command;

            return $respond === null ? ['exit_code' => 0, 'output' => ''] : $respond($command);
        };
    }

    /**
     * Records each upload: the local directory's file names and content, the
     * remote directory, and whether the row existed when the upload started.
     *
     * @param list<array<string, mixed>> $uploads
     */
    private function recordingUpload(array &$uploads, int $jobId): callable
    {
        return function (array $credential, string $secret, string $localDir, string $remoteDir, ?callable $logger = null, ?callable $now = null) use (&$uploads, $jobId): void {
            $file = $localDir . '/accounts.yml';
            $uploads[] = [
                'localDir' => $localDir,
                'remoteDir' => $remoteDir,
                'files' => $this->listFiles($localDir),
                'content' => is_file($file) ? (string) file_get_contents($file) : '',
                'rowBeforeUpload' => $this->rowExists($jobId, $remoteDir),
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
