<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/crypto.php';
require_once dirname(__DIR__, 2) . '/lib/lang.php';
require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_command.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_full_test.php';
require_once dirname(__DIR__, 2) . '/lib/ansible_test_scheduler.php';
require_once dirname(__DIR__, 2) . '/lib/credentials_actions.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_mission.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';

/**
 * K21-F (F-Sweep): a passed full test of an Ansible credential is the second
 * "contact" that sweeps the ESXi access files which other jobs left on the same
 * target, and the test result says so in one line of its own.
 *
 * The manual path (credentials_actions.php) and the scheduled path
 * (ansible_test_run_due) must reach the same owner, ansible_full_test_execute(),
 * so both are exercised here. The external work is replaced through the seams:
 *
 *   ansible_full_test_execute($db, $credential, ['test' => fn, 'execute' => fn])
 *     test:    replaces credential_test_connection(); answers the Volltest result
 *              (its context may carry 'secret_cleanup' => enabled|missing|unknown,
 *              the timer state read from the preflight output).
 *     execute: the ssh_execute_capture() signature for the sweep's removal.
 *     clock:   callable(): float, the time source of the sweep budget (fake in the budget case).
 *   ansible_test_run_due($db, ['claim' => fn, 'test' => fn, 'execute' => fn])
 *     claim:   replaces repo_ansible_test_claim_due(), so the due credential of
 *              this fixture is the one that runs (other due credentials in the
 *              shared QA database cannot take its place).
 *
 * The result shape the sweep adds: 'secret_sweep' => ['closed' => int, 'aborted' => bool],
 * present only after a passed test. The sentences after the verdict come from
 * credentials_complete_ansible_test() (F-UI), so the flash assertions need that change.
 *
 * Runs against the QA MySQL server only; it never reaches an Ansible host.
 */
final class AnsibleFullTestSweepTest extends TestCase
{
    private const WORKER = 'phpunit-ansible-sweep';
    private const REMOVED = "VIRTUSPHERE_REMOTE_SECRET removed\n";
    private const CLEARED = 'deploy.remote_secret_cleared';
    private const TESTED = VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_TESTED;

    private mysqli $db;
    private string $token = '';
    private int $userId = 0;
    private int $missionId = 0;
    private int $esxiId = 0;
    private int $ansibleId = 0;
    private string $ansibleHost = '';
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
        Lang::load(Lang::DEFAULT_LOCALE);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_start();
        }
        unset($_SESSION['_flash']);
        $this->token = bin2hex(random_bytes(5));
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        if ($this->userId <= 0) {
            self::markTestSkipped('No user exists for credential provenance.');
        }
        $this->missionId = $this->insertMission('mission');
        $this->esxiId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443, 'esxi');
        $this->ansibleId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22, 'ansible');
        $this->ansibleHost = $this->hostFor('ansible');
    }

    protected function tearDown(): void
    {
        unset($_SESSION['_flash']);
        if (!isset($this->db)) {
            return;
        }
        repo_execute($this->db, 'DELETE FROM deploy_remote_secret_files WHERE remote_dir LIKE ?', 's', [$this->remoteRoot() . '%']);
        foreach ($this->jobIds as $jobId) {
            repo_execute($this->db, 'DELETE FROM deploy_logs WHERE event_code = ? AND object_type = ? AND object_id = ?', 'sss', [self::CLEARED, 'deploy_job', (string) $jobId]);
        }
        foreach ($this->credentialIds as $credentialId) {
            repo_execute($this->db, 'DELETE FROM deploy_logs WHERE event_code = ? AND object_type = ? AND object_id = ?', 'sss', [self::TESTED, 'credential', (string) $credentialId]);
        }
        // Deleting a mission cascades its jobs, VMs and create units.
        foreach ($this->missionIds as $missionId) {
            repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$missionId]);
        }
        foreach ($this->credentialIds as $credentialId) {
            repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$credentialId]);
        }
    }

    /* ---------- manual path: ansible_full_test_execute() + the manual finaliser ---------- */

    public function testPassingManualTestClosesTheOpenRowOfItsTargetAndAuditsIt(): void
    {
        $leftover = $this->finishedJob();
        $remoteDir = $this->remoteDir($leftover);
        $this->seedRowOnCurrentTarget($leftover, $remoteDir);
        $tests = [];
        $commands = [];

        $result = $this->manualTest([
            'test' => $this->testSeam($tests, credential_test_result(true, VIRTUSPHERE_CREDENTIAL_TEST_OK)),
            'execute' => $this->removalSeam($commands),
        ]);

        self::assertSame(1, count($tests), 'the fixture credential must run through the passed test');
        self::assertTrue($result['ok']);
        self::assertSame(['closed' => 1, 'aborted' => false], $result['secret_sweep'] ?? null, 'the result names one removed file');
        self::assertSame([ansible_remote_secret_remove_command($remoteDir)], $commands);
        self::assertFalse($this->rowExists($leftover, $remoteDir), 'the swept row is closed');

        $audit = $this->clearedAuditRows($leftover);
        self::assertCount(1, $audit, 'one removal writes exactly one deploy.remote_secret_cleared row');
        self::assertSame('success', $audit[0]['result']);
        $context = json_decode((string) $audit[0]['context_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->ansibleId, (int) $context['credential_id']);
        self::assertSame(0, (int) $context['job_id'], 'a manual test has no job of its own');
        self::assertSame('removed', $context['outcome']);
        self::assertSame($this->ansibleHost, $context['host']);
        self::assertSame(22, (int) $context['port']);

        $lines = $this->flashTexts();
        self::assertContains(
            __t('ansible_test.sweep_closed', ['count' => 1]),
            $lines,
            'the operator sees the removal as its own line of the test result'
        );
        self::assertContains(__t('credentials.test_ok_ansible'), $lines, 'the traffic light message is still the test verdict');
    }

    public function testFailedManualTestLeavesTheRowAndRunsNoRemoval(): void
    {
        $leftover = $this->finishedJob();
        $remoteDir = $this->remoteDir($leftover);
        $this->seedRowOnCurrentTarget($leftover, $remoteDir);
        $tests = [];
        $commands = [];

        $result = $this->manualTest([
            'test' => $this->testSeam($tests, credential_test_result(false, VIRTUSPHERE_CREDENTIAL_TEST_SFTP, 'fixture failure')),
            'execute' => $this->removalSeam($commands),
        ]);

        self::assertSame(1, count($tests));
        self::assertFalse($result['ok']);
        self::assertSame([], $commands, 'a failed test never sweeps');
        self::assertTrue($this->rowExists($leftover, $remoteDir));
        self::assertSame([], $this->clearedAuditRows($leftover));
        self::assertNotContains(__t('ansible_test.sweep_closed', ['count' => 1]), $this->flashTexts());
    }

    public function testRowOfAnotherTargetStaysAndTheResultClaimsNoRemoval(): void
    {
        $leftover = $this->finishedJob();
        $remoteDir = $this->remoteDir($leftover);
        $this->seedRowOnTarget($leftover, $remoteDir, 'moved-' . $this->token . '.example.invalid', 22, null);
        $tests = [];
        $commands = [];

        $result = $this->manualTest([
            'test' => $this->testSeam($tests, credential_test_result(true, VIRTUSPHERE_CREDENTIAL_TEST_OK)),
            'execute' => $this->removalSeam($commands),
        ]);

        self::assertSame(1, count($tests));
        self::assertTrue($result['ok']);
        self::assertSame(0, (int) ($result['secret_sweep']['closed'] ?? 0), 'nothing on the current target was closed');
        self::assertSame([], $commands, 'the sweep never contacts the host of another target');
        self::assertTrue($this->rowExists($leftover, $remoteDir), 'the row of the other target waits for its own contact');
        self::assertSame([], $this->clearedAuditRows($leftover));
        self::assertNotContains(__t('ansible_test.sweep_closed', ['count' => 1]), $this->flashTexts(), 'no false removal line');
    }

    public function testTransportFailureDuringTheSweepEndsAsAbortedAndKeepsEveryRow(): void
    {
        $first = $this->finishedJob();
        $second = $this->finishedJob();
        $firstDir = $this->remoteDir($first);
        $secondDir = $this->remoteDir($second);
        $this->seedRowOnCurrentTarget($first, $firstDir);
        $this->seedRowOnCurrentTarget($second, $secondDir);
        $tests = [];
        $commands = [];

        $result = $this->manualTest([
            'test' => $this->testSeam($tests, credential_test_result(true, VIRTUSPHERE_CREDENTIAL_TEST_OK)),
            'execute' => $this->removalSeam($commands, self::REMOVED, new RuntimeException('fixture transport down')),
        ]);

        self::assertTrue($result['ok'], 'a failing sweep never changes the verdict of the test');
        self::assertSame(['closed' => 0, 'aborted' => true], $result['secret_sweep'] ?? null);
        self::assertCount(1, $commands, 'the first transport failure ends the sweep');
        self::assertTrue($this->rowExists($first, $firstDir));
        self::assertTrue($this->rowExists($second, $secondDir));
        self::assertContains(__t('ansible_test.sweep_aborted', ['count' => 0]), $this->flashTexts());
    }

    /**
     * K21-F budget: each removal takes 61 s on the fake clock, so the second
     * candidate starts after the 60 s budget and waits for the next contact.
     */
    public function testTheBudgetEndsTheSweepAsAbortedAndLeavesTheRestForTheNextContact(): void
    {
        $first = $this->finishedJob();
        $second = $this->finishedJob();
        $this->seedRowOnCurrentTarget($first, $this->remoteDir($first));
        $this->seedRowOnCurrentTarget($second, $this->remoteDir($second));
        $tests = [];
        $commands = [];
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $removal = static function (array $credential, string $secret, string $command, int $timeout) use (&$commands, &$now): array {
            $commands[] = $command;
            $now += 61.0;

            return ['exit_code' => 0, 'output' => self::REMOVED];
        };

        $result = $this->manualTest([
            'test' => $this->testSeam($tests, credential_test_result(true, VIRTUSPHERE_CREDENTIAL_TEST_OK)),
            'execute' => $removal,
            'clock' => $clock,
        ]);

        self::assertSame(['closed' => 1, 'aborted' => true], $result['secret_sweep'] ?? null);
        self::assertCount(1, $commands, 'the second candidate starts after the budget and waits');
        $open = (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_remote_secret_files WHERE job_id IN (?, ?)', 'ii', [$first, $second]);
        self::assertSame(1, $open, 'one row stays open for the next contact');
        self::assertContains(__t('ansible_test.sweep_aborted', ['count' => 1]), $this->flashTexts());
    }

    /**
     * K21-F, verworfenes Ergebnis: the removal does not depend on whether the verdict
     * is still current, so the sweep runs. The stale result shows only its discarded
     * note, never a sentence about a removal of a result nobody stored.
     */
    public function testADiscardedPassedTestStillSweepsButShowsNoSweepSentence(): void
    {
        $leftover = $this->finishedJob();
        $remoteDir = $this->remoteDir($leftover);
        $this->seedRowOnCurrentTarget($leftover, $remoteDir);
        $tests = [];
        $commands = [];
        $credential = repo_credential($this->db, $this->ansibleId, true);
        self::assertNotNull($credential);
        $revision = (int) $credential['config_revision'];
        $generation = repo_ansible_preflight_begin($this->db, $this->ansibleId, $revision);
        $result = ansible_full_test_execute($this->db, $credential, [
            'test' => $this->testSeam($tests, credential_test_result(true, VIRTUSPHERE_CREDENTIAL_TEST_OK)),
            'execute' => $this->removalSeam($commands),
        ]);
        // A newer generation arrives while the test ran: the result is stale.
        repo_ansible_preflight_begin($this->db, $this->ansibleId, $revision);

        credentials_complete_ansible_test($this->db, $this->ansibleId, $this->userId, $result, $revision, $generation);

        self::assertSame(['closed' => 1, 'aborted' => false], $result['secret_sweep'] ?? null);
        self::assertFalse($this->rowExists($leftover, $remoteDir), 'the sweep ran although the verdict is discarded');
        self::assertCount(1, $this->clearedAuditRows($leftover));
        self::assertSame([__t('credentials.test_result_discarded')], $this->flashTexts());
    }

    /* ---------- scheduled path: ansible_test_run_due() reaches the same owner ---------- */

    public function testPassingScheduledTestRunsTheSameSweepAndAuditsIt(): void
    {
        $leftover = $this->finishedJob();
        $remoteDir = $this->remoteDir($leftover);
        $this->seedRowOnCurrentTarget($leftover, $remoteDir);
        $tests = [];
        $commands = [];

        $this->scheduledTest([
            'test' => $this->testSeam($tests, credential_test_result(true, VIRTUSPHERE_CREDENTIAL_TEST_OK)),
            'execute' => $this->removalSeam($commands),
        ]);

        self::assertSame(1, count($tests), 'the claimed credential of this fixture ran the test');
        self::assertSame([ansible_remote_secret_remove_command($remoteDir)], $commands);
        self::assertFalse($this->rowExists($leftover, $remoteDir));
        $audit = $this->clearedAuditRows($leftover);
        self::assertCount(1, $audit);
        $context = json_decode((string) $audit[0]['context_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, (int) $context['job_id']);
        self::assertSame('removed', $context['outcome']);
        $tested = $this->latestTestedAudit();
        self::assertSame(VIRTUSPHERE_AUDIT_RESULT_SUCCESS, $tested['result']);
        self::assertTrue((bool) (json_decode((string) $tested['context_json'], true, 8, JSON_THROW_ON_ERROR)['scheduled'] ?? false));
    }

    public function testFailedScheduledTestLeavesTheRowAndRunsNoRemoval(): void
    {
        $leftover = $this->finishedJob();
        $remoteDir = $this->remoteDir($leftover);
        $this->seedRowOnCurrentTarget($leftover, $remoteDir);
        $tests = [];
        $commands = [];

        $this->scheduledTest([
            'test' => $this->testSeam($tests, credential_test_result(false, VIRTUSPHERE_CREDENTIAL_TEST_SFTP, 'fixture failure')),
            'execute' => $this->removalSeam($commands),
        ]);

        self::assertSame(1, count($tests), 'the claimed credential of this fixture ran the test');
        self::assertSame([], $commands, 'a failed scheduled test never sweeps');
        self::assertTrue($this->rowExists($leftover, $remoteDir));
        self::assertSame([], $this->clearedAuditRows($leftover));
    }

    /* ---------- timer state: one hint line, the traffic light unchanged ---------- */

    /**
     * K21-F, Portal-Zurückhaltung: only a MISSING timer adds a sentence. An installed
     * timer and an unreadable state add none. The traffic light is the test's alone.
     */
    #[DataProvider('timerStates')]
    public function testOnlyAMissingTimerAddsAHintLineAndNeverChangesTheTrafficLight(string $state, bool $named): void
    {
        $tests = [];
        $commands = [];

        $result = $this->manualTest([
            'test' => $this->testSeam($tests, credential_test_result(true, VIRTUSPHERE_CREDENTIAL_TEST_OK, '', ['secret_cleanup' => $state])),
            'execute' => $this->removalSeam($commands),
        ]);

        self::assertTrue($result['ok'], 'the timer state does not change the verdict');
        $preflight = $this->preflightState();
        self::assertSame(VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK, $preflight['last_status'], 'the stored traffic light stays green');
        $flashes = $this->flashes();
        $verdict = array_values(array_filter($flashes, static fn (array $flash): bool => $flash['message'] === __t('credentials.test_ok_ansible')));
        self::assertCount(1, $verdict);
        self::assertSame('success', $verdict[0]['type'], 'the verdict flash keeps its type');
        $hints = array_values(array_filter($flashes, static fn (array $flash): bool => $flash['message'] === __t('ansible_test.secret_cleanup_missing')));
        if (!$named) {
            self::assertSame([], $hints, 'an installed or unreadable timer adds no sentence');
            self::assertCount(1, $flashes, 'the verdict is the only line');

            return;
        }
        self::assertCount(1, $hints, 'a missing timer is named by exactly one line');
        self::assertSame('info', $hints[0]['type'], 'a timer hint is never an alert of the traffic light');
        self::assertCount(2, $flashes);
    }

    /** @return iterable<string, array{string, bool}> state, whether the state is named */
    public static function timerStates(): iterable
    {
        yield 'enabled' => ['enabled', false];
        yield 'missing' => ['missing', true];
        yield 'unknown' => ['unknown', false];
    }

    /* ---------- worker: one timer line in the job's preflight log ---------- */

    /**
     * K21-F: the job log of the preflight names a MISSING timer in one line, and
     * says nothing about an installed or unreadable one. The preflight fails after
     * its marker lines, so the job ends right there.
     */
    #[DataProvider('workerLines')]
    public function testWorkerWritesOneLogLineOnlyForAMissingTimer(?string $marker, int $expected): void
    {
        $vmId = $this->insertVm();
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, [
            'mode' => VIRTUSPHERE_DEPLOY_MODE_FULL,
            'vm_ids' => [$vmId],
        ]);
        $this->jobIds[] = $jobId;

        $this->processClaimed($jobId, static function (string $command, callable $onChunk) use ($marker): int {
            $onChunk(($marker === null ? '' : "::virtusphere-secret-cleanup:: " . $marker . "\n") . "ansible-playbook: command not found\n");

            return 1;
        });

        self::assertSame($expected, $this->logCount($jobId, '%cleanup timer%'), 'the timer line of the preflight log');
    }

    /** @return iterable<string, array{?string, int}> marker value, expected timer lines */
    public static function workerLines(): iterable
    {
        yield 'missing names the timer' => ['missing', 1];
        yield 'enabled stays silent' => ['enabled', 0];
        yield 'unknown stays silent' => ['unknown', 0];
        yield 'no marker stays silent' => [null, 0];
    }

    /* ---------- helpers ---------- */

    /** @param array{test?: callable, execute?: callable, clock?: callable(): float, time_limit?: int} $seams */
    private function manualTest(array $seams): array
    {
        $credential = repo_credential($this->db, $this->ansibleId, true);
        self::assertNotNull($credential);
        $revision = (int) $credential['config_revision'];
        $generation = repo_ansible_preflight_begin($this->db, $this->ansibleId, $revision);
        $result = ansible_full_test_execute($this->db, $credential, $seams);
        credentials_complete_ansible_test($this->db, $this->ansibleId, $this->userId, $result, $revision, $generation);

        return $result;
    }

    /** @param array<string, callable> $seams */
    private function scheduledTest(array $seams): void
    {
        $credential = repo_credential($this->db, $this->ansibleId, true);
        self::assertNotNull($credential);
        $revision = (int) $credential['config_revision'];
        $generation = repo_ansible_preflight_begin($this->db, $this->ansibleId, $revision);
        $seams['claim'] = static fn (): array => ['credential' => $credential, 'generation' => $generation];
        ansible_test_run_due($this->db, $seams);
    }

    /**
     * @param list<string> $calls
     */
    private function testSeam(array &$calls, array $result): callable
    {
        return static function (array $credential, string $secret, string $apiBaseUrl) use (&$calls, $result): array {
            $calls[] = 'test';

            return $result;
        };
    }

    /**
     * The ssh_execute_capture() signature. The removal answers with the state
     * line of the real command, or throws as a transport failure would.
     *
     * @param list<string> $commands
     */
    private function removalSeam(array &$commands, string $output = self::REMOVED, ?Throwable $failure = null): callable
    {
        return static function (array $credential, string $secret, string $command, int $timeout) use (&$commands, $output, $failure): array {
            $commands[] = $command;
            if ($failure !== null) {
                throw $failure;
            }

            return ['exit_code' => 0, 'output' => $output];
        };
    }

    private function processClaimed(int $jobId, callable $hostPreflightRunner): void
    {
        $workerId = 'phpunit:ansible-sweep-' . $jobId;
        $lockToken = bin2hex(random_bytes(16));
        repo_execute(
            $this->db,
            'UPDATE deploy_jobs SET status = ?, locked_by = ?, lock_token = ?, worker_epoch = 0, attempts = 1,'
            . ' execution_generation_id = (SELECT current_generation_id FROM deploy_runtime_identity WHERE id = 1),'
            . ' locked_at = NOW(), heartbeat_at = NOW() WHERE id = ?',
            'sssi',
            [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $workerId, $lockToken, $jobId]
        );
        $job = repo_deploy_job($this->db, $jobId);
        self::assertNotNull($job);
        deploy_worker_process_job($this->db, $job, $workerId, [
            'once' => true,
            'cleanup' => true,
            'sleep' => 1,
            'host_preflight_runner' => $hostPreflightRunner,
        ]);
    }

    private function logCount(int $jobId, string $pattern): int
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_job_logs WHERE job_id = ? AND line LIKE ?', 'is', [$jobId, $pattern]);
    }

    private function prefix(): string
    {
        return 'phpunit_ansible_sweep_' . $this->token;
    }

    private function remoteRoot(): string
    {
        return '/tmp/vs-ansible-sweep-' . $this->token . '/';
    }

    private function remoteDir(int $jobId): string
    {
        return rtrim($this->remoteRoot(), '/') . '/job-' . $jobId;
    }

    private function hostFor(string $label): string
    {
        return $label . '-' . $this->token . '.example.invalid';
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
        $secret = crypto_encrypt_secret('fixture-secret-' . $this->token);
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssiss', $type, $name, $host, $port, $username, $secret);
        $stmt->execute();
        $credentialId = (int) $this->db->insert_id;
        $this->credentialIds[] = $credentialId;

        return $credentialId;
    }

    private function insertVm(): int
    {
        $vmName = strtoupper($this->prefix() . '_vm');
        $lifecycle = VIRTUSPHERE_LIFECYCLE_OS_INSTALLED;
        $mecm = VIRTUSPHERE_MECM_SYNC_REGISTERED;
        $instanceUuid = 'ansible-sweep-' . bin2hex(random_bytes(8));
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state, vm_instance_uuid) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssss', $this->missionId, $vmName, $vmName, $lifecycle, $mecm, $instanceUuid);
        $stmt->execute();
        $vmId = (int) $this->db->insert_id;
        repo_execute(
            $this->db,
            "INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac) VALUES (?, '', '', '', 'WDS', '')",
            'i',
            [$vmId]
        );

        return $vmId;
    }

    /** A job whose worker wrote its terminal status: the state every job has when its ESXi file is due for removal. */
    private function finishedJob(): int
    {
        $payload = json_encode(['mode' => 'start', 'vm_ids' => []], JSON_THROW_ON_ERROR);
        $esxiId = $this->esxiId;
        $ansibleId = $this->ansibleId;
        $status = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $lockedBy = self::WORKER;
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_jobs (mission_id, status, credential_esxi_id, credential_ansible_id, locked_by, locked_at, heartbeat_at, payload_json) '
            . 'VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)'
        );
        $missionId = $this->missionId;
        $stmt->bind_param('isiiss', $missionId, $status, $esxiId, $ansibleId, $lockedBy, $payload);
        $stmt->execute();
        $jobId = (int) $this->db->insert_id;
        $this->jobIds[] = $jobId;
        self::assertTrue(repo_finish_deploy_job($this->db, $jobId, self::WORKER, 'failed', 'fixture job end'));

        return $jobId;
    }

    /** The row an upload of a job writes, with the target the credential has now (host, port, pinned key). */
    private function seedRowOnCurrentTarget(int $jobId, string $remoteDir): void
    {
        repo_execute(
            $this->db,
            'INSERT INTO deploy_remote_secret_files (job_id, credential_ansible_id, remote_dir, target_host, target_port, target_fingerprint)'
                . ' SELECT ?, c.id, ?, c.host, COALESCE(c.port, 22), c.ansible_host_fingerprint FROM deploy_credentials c WHERE c.id = ?',
            'isi',
            [$jobId, $remoteDir, $this->ansibleId]
        );
    }

    private function seedRowOnTarget(int $jobId, string $remoteDir, string $host, int $port, ?string $fingerprint): void
    {
        repo_execute(
            $this->db,
            'INSERT INTO deploy_remote_secret_files (job_id, credential_ansible_id, remote_dir, target_host, target_port, target_fingerprint) VALUES (?, ?, ?, ?, ?, ?)',
            'iissis',
            [$jobId, $this->ansibleId, $remoteDir, $host, $port, $fingerprint]
        );
    }

    private function rowExists(int $jobId, string $remoteDir): bool
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_remote_secret_files WHERE job_id = ? AND remote_dir = ?', 'is', [$jobId, $remoteDir]) > 0;
    }

    /** @return list<array{object_type: string, object_id: string, result: string, context_json: string|null}> */
    private function clearedAuditRows(int $jobId): array
    {
        $stmt = $this->db->prepare('SELECT object_type, object_id, result, context_json FROM deploy_logs WHERE event_code = ? AND object_type = ? AND object_id = ? ORDER BY id');
        $event = self::CLEARED;
        $objectType = 'deploy_job';
        $objectId = (string) $jobId;
        $stmt->bind_param('sss', $event, $objectType, $objectId);
        $stmt->execute();

        return repo_fetch_all($stmt->get_result());
    }

    /** @return array<string, mixed> */
    private function latestTestedAudit(): array
    {
        $stmt = $this->db->prepare('SELECT result, context_json FROM deploy_logs WHERE event_code = ? AND object_type = ? AND object_id = ? ORDER BY id DESC LIMIT 1');
        $event = self::TESTED;
        $objectType = 'credential';
        $objectId = (string) $this->ansibleId;
        $stmt->bind_param('sss', $event, $objectType, $objectId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        self::assertIsArray($row, 'the credential test audit is missing');

        return $row;
    }

    /** @return array<string, mixed> */
    private function preflightState(): array
    {
        $state = repo_ansible_preflight_state($this->db, $this->ansibleId);
        self::assertNotNull($state);

        return $state;
    }

    /**
     * The queued flashes without consuming them: flash_messages() empties the
     * queue, so a second read in the same test would see nothing.
     *
     * @return list<array{type: string, message: string, detail: string, action: mixed}>
     */
    private function flashes(): array
    {
        $messages = $_SESSION['_flash'] ?? [];

        return is_array($messages) ? array_values($messages) : [];
    }

    /** @return list<string> */
    private function flashTexts(): array
    {
        return array_map(static fn (array $flash): string => (string) $flash['message'], $this->flashes());
    }
}
