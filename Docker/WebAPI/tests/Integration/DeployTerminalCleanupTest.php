<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/repo/deploy_jobs.php';
require_once __DIR__ . '/../../lib/deploy_worker_cleanup.php';

/** Cleanup uses real terminal rows and the real SSH precondition failure (SC-012). */
final class DeployTerminalCleanupTest extends TestCase
{
    private mysqli $db;
    private int $missionId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $name = 'phpunit_terminal_cleanup_' . bin2hex(random_bytes(5));
        repo_execute($this->db, "INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, 'active')", 's', [$name]);
        $this->missionId = (int) $this->db->insert_id;
    }

    protected function tearDown(): void
    {
        if ($this->missionId > 0) {
            repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$this->missionId]);
        }
    }

    public function testRetainedUncertainDirectoryNeverAppendsToTerminalJobOrSkipsLocalCleanup(): void
    {
        [$jobId, $channel] = $this->job('create');
        $vmName = 'cleanup-' . $jobId;
        repo_execute($this->db, 'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)', 'iss', [$this->missionId, $vmName, $vmName]);
        repo_deploy_create_materialize($this->db, $jobId, [['id' => (int) $this->db->insert_id, 'vm_name' => $vmName]]);
        repo_execute($this->db, "UPDATE deploy_create_vm_results SET status = 'uncertain', async_jid = '123456789.1', error_code = ?, error_detail = 'fixture unresolved result', finished_at = NOW() WHERE job_id = ?", 'si', [VIRTUSPHERE_CREATE_ERROR_JOB_TIMEOUT, $jobId]);
        try {
            self::assertTrue(repo_finish_deploy_job($this->db, $jobId, 'phpunit-cleanup', 'failed', 'unresolved create'));
            $before = $this->snapshot($jobId);
        } finally {
            $diagnostic = $this->captureErrorLog(static function () use ($channel): void {
                deploy_worker_cleanup_remote_dir($channel, ['host' => 'never-connect.example.invalid'], 'unused-fixture-secret', '/tmp/virtusphere-cleanup-never-created', false);
            });
        }
        self::assertSame($before, $this->snapshot($jobId));
        self::assertSame('uncertain', repo_deploy_create_results($this->db, $jobId)[0]['status']);
        self::assertStringContainsString('Remote job directory left in place', $diagnostic);
        self::assertSame(0, $channel->spooledLineCount());
    }

    public function testFailedSshCleanupAfterNonCreateSuccessPreservesOutcomeAndOriginalException(): void
    {
        [$jobId, $channel] = $this->job('start');
        self::assertTrue(repo_finish_deploy_job($this->db, $jobId, 'phpunit-cleanup', 'succeeded'));
        $before = $this->snapshot($jobId);
        $original = new RuntimeException('original result consumer failure');
        $cleanup = (object) ['diagnostic' => ''];
        try {
            try {
                throw $original;
            } finally {
                // Missing username fails inside the real SSH helper before
                // creating any connection. No transport test double or host.
                $cleanup->diagnostic = $this->captureErrorLog(static function () use ($channel): void {
                    deploy_worker_cleanup_remote_dir($channel, ['host' => 'never-connect.example.invalid'], 'unused-fixture-secret', '/tmp/virtusphere-cleanup-never-created', false);
                });
            }
        } catch (Throwable $observed) {
            self::assertSame($original, $observed, 'Cleanup must never replace an exception already propagating through finally.');
        }
        self::assertSame($before, $this->snapshot($jobId));
        self::assertSame([], repo_deploy_create_results($this->db, $jobId));
        self::assertStringContainsString('Remote job directory could not be removed', $cleanup->diagnostic);
        self::assertSame(0, $channel->spooledLineCount());
    }

    public function testDiagnosticNormalizesAndRedactsBeforeWritingTheWorkerLog(): void
    {
        $secret = 'synthetic secret value';
        $diagnostic = $this->captureErrorLog(static function () use ($secret): void {
            deploy_worker_cleanup_diagnose(42, "remote failure: synthetic \e[31msecret\e[0m value / " . rawurlencode($secret) . ' / Authorization: Bearer fixture-token', $secret);
        });
        self::assertStringContainsString('[deploy-worker] job 42 cleanup:', $diagnostic);
        foreach ([$secret, rawurlencode($secret), 'fixture-token', "\e["] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $diagnostic);
        }
    }

    private function job(string $mode): array
    {
        $payload = json_encode(['mode' => $mode, 'vm_ids' => []], JSON_THROW_ON_ERROR);
        repo_execute($this->db, "INSERT INTO deploy_jobs (mission_id, status, locked_by, locked_at, heartbeat_at, payload_json) VALUES (?, 'running', 'phpunit-cleanup', NOW(), NOW(), ?)", 'is', [$this->missionId, $payload]);
        $jobId = (int) $this->db->insert_id;
        $channel = new DeployWorkerDbChannel($this->db, fn (): mysqli => $this->db, $jobId, 'phpunit-cleanup');

        return [$jobId, $channel];
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

    /** Redirect inside the test body: PHPUnit starts its own capture after setUp(). */
    private function captureErrorLog(callable $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vs-cleanup-diagnostic-');
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
