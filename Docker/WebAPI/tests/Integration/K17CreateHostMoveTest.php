<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_create.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_network_blockers.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_retry_confirmation.php';
require_once dirname(__DIR__, 2) . '/tests/Support/NetworkMacFixtures.php';

/**
 * K17 (FM-01, FM-E1): a binding remembers the access and the access target it
 * was proven through. Create through an access with another host warns before
 * anything is queued; Create through the same access does not. Nothing blocks.
 */
final class K17CreateHostMoveTest extends TestCase
{
    private mysqli $db;
    private string $prefix;
    private int $missionId;
    private int $userId;
    private int $esxiA;
    private int $esxiB;
    private int $ansibleId;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->prefix = 'k17-host-' . bin2hex(random_bytes(4));
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $this->userId);
        $this->esxiA = $this->credential('esxi', 'a');
        $this->esxiB = $this->credential('esxi', 'b');
        $this->ansibleId = $this->credential('ansible', 'x');
        repo_execute($this->db, "INSERT INTO deploy_missions (mission_name,mission_status,hypervisor_datacenter,hypervisor_datastorage,wds_vlan) VALUES (?,'active','DC1','DS1','WDS')", 's', [$this->prefix]);
        $this->missionId = (int) $this->db->insert_id;
    }

    protected function tearDown(): void
    {
        if (!isset($this->missionId)) {
            return;
        }
        repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$this->missionId]);
        foreach ([$this->esxiA, $this->esxiB, $this->ansibleId] as $id) {
            repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$id]);
        }
    }

    public function testTheCreateCommitRecordsAccessAndHostOfTheBinding(): void
    {
        $vmId = $this->vm();
        $this->createThrough($this->esxiA, $vmId);

        $row = $this->binding($vmId);
        self::assertSame($this->uuid($vmId), $row['vm_instance_uuid']);
        self::assertSame($this->esxiA, (int) $row['vm_bound_credential_id']);
        self::assertSame($this->host('a'), $row['vm_bound_esxi_host']);
    }

    public function testCreateThroughAnotherHostWarnsAndTheBindingAccessDoesNot(): void
    {
        $vmId = $this->vm();
        $this->createThrough($this->esxiA, $vmId);
        test_prepare_network_mac_fixture($this->db, $this->missionId, $this->esxiB);

        $other = $this->hostMoveWarnings($this->esxiB, 'create', $vmId);
        self::assertCount(1, $other, 'a second access with another host warns before queueing');
        self::assertStringContainsString($this->host('a'), (string) $other[0]['message'], 'the warning names the previous host');
        self::assertStringContainsString($this->prefix . '_vm', (string) $other[0]['message']);
        self::assertCount(1, $this->hostMoveWarnings($this->esxiB, 'full', $vmId), 'full creates too');

        self::assertSame([], $this->hostMoveWarnings($this->esxiA, 'create', $vmId), 'the binding access warns nothing');
        self::assertSame([], $this->hostMoveWarnings($this->esxiB, 'export', $vmId), 'a mode that creates nothing warns nothing');
    }

    public function testARetargetedAccessWarnsAndACaseVariantOfTheSameHostDoesNot(): void
    {
        $vmId = $this->vm();
        $this->createThrough($this->esxiA, $vmId);

        repo_execute($this->db, 'UPDATE deploy_credentials SET host = ? WHERE id = ?', 'si', [strtoupper($this->host('a')) . '.', $this->esxiA]);
        // Held in a variable: asserting on the call itself would let static
        // analysis carry "always empty" over to the identical call below.
        $sameHost = $this->hostMoveWarnings($this->esxiA, 'create', $vmId);
        self::assertSame([], $sameHost, 'case and a trailing dot name the same host');

        repo_execute($this->db, 'UPDATE deploy_credentials SET host = ? WHERE id = ?', 'si', ['moved.' . $this->host('a'), $this->esxiA]);
        $warnings = $this->hostMoveWarnings($this->esxiA, 'create', $vmId);
        self::assertCount(1, $warnings, 'the same access pointed at another host warns (FM-01)');
        self::assertStringContainsString($this->host('a'), (string) $warnings[0]['message']);
    }

    public function testARetryOfACreateOnAnotherHostCarriesTheWarningIntoItsConfirmation(): void
    {
        $vmId = $this->vm();
        $this->createThrough($this->esxiA, $vmId);
        test_prepare_network_mac_fixture($this->db, $this->missionId, $this->esxiB);
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiB, $this->ansibleId, $this->payload('create', $vmId));
        repo_execute($this->db, "UPDATE deploy_jobs SET status = 'failed' WHERE id = ?", 'i', [$jobId]);

        $evaluation = deploy_retry_blockers($this->db, $jobId);
        self::assertTrue($evaluation['allowed'], json_encode($evaluation['blocking_findings']) ?: '');
        $confirm = deploy_retry_confirmation($evaluation, $this->prefix);
        self::assertStringContainsString($this->host('a'), $confirm, 'the retry dialog names the previous host');
    }

    public function testTheMigrationBackfillsOnlyFromUnchangedCreateEvidence(): void
    {
        $kept = $this->vm('_kept');
        $this->createThrough($this->esxiA, $kept);
        $changed = $this->vm('_changed');
        $this->createThrough($this->esxiB, $changed);
        repo_execute($this->db, 'UPDATE deploy_vms SET vm_bound_credential_id = NULL, vm_bound_esxi_host = NULL WHERE mission_id = ?', 'i', [$this->missionId]);
        // Access B was edited after its create unit finished: its host at that
        // time is unknown, so the backfill must not guess it.
        repo_execute($this->db, 'UPDATE deploy_credentials SET updated_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id = ?', 'i', [$this->esxiB]);

        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/lib/migrate.php', true) . '; migrate_0061_vm_host_binding(db());';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr . $stdout);

        self::assertSame($this->esxiA, (int) $this->binding($kept)['vm_bound_credential_id']);
        self::assertSame($this->host('a'), $this->binding($kept)['vm_bound_esxi_host']);
        self::assertNull($this->binding($changed)['vm_bound_credential_id']);
        self::assertNull($this->binding($changed)['vm_bound_esxi_host']);
    }

    /** @return list<array<string,mixed>> */
    private function hostMoveWarnings(int $esxiId, string $mode, int $vmId): array
    {
        $warnings = deploy_queue_warnings($this->db, [
            'mission_id' => $this->missionId,
            'credential_esxi_id' => $esxiId,
            'credential_ansible_id' => $this->ansibleId,
            'mode' => $mode,
            'vm_ids' => [$vmId],
        ]);

        return array_values(array_filter($warnings, static fn (array $warning): bool => ($warning['code'] ?? '') === 'vm_host_move'));
    }

    /** One succeeded create unit through the given access, then a terminal job. */
    private function createThrough(int $esxiId, int $vmId): void
    {
        test_prepare_network_mac_fixture($this->db, $this->missionId, $esxiId);
        $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $esxiId, $this->ansibleId, $this->payload('create', $vmId));
        $worker = $this->prefix . '-worker-' . $jobId;
        $job = repo_claim_next_deploy_job($this->db, $worker);
        self::assertNotNull($job);
        self::assertSame($jobId, (int) $job['id']);
        $fence = deploy_worker_job_fence($this->db, $jobId, $worker, $job);
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'pending', 'prepared', ['existed_before' => 0], $fence));
        self::assertTrue(repo_deploy_create_transition($this->db, $jobId, 1, 'prepared', 'running', ['async_jid' => '417.' . $jobId, 'async_deadline_at' => gmdate('Y-m-d H:i:s', time() + 3600)], $fence));
        $commit = repo_deploy_create_commit_success($this->db, $jobId, 1, false, true, 'vm-' . $vmId, $this->uuid($vmId), $fence);
        self::assertTrue($commit['committed']);
        repo_execute($this->db, "UPDATE deploy_jobs SET status = 'succeeded', terminal_reason_code = 'completed', terminal_reason_detail = 'fixture', locked_by = NULL, lock_token = NULL, worker_epoch = NULL WHERE id = ?", 'i', [$jobId]);
    }

    private function vm(string $suffix = ''): int
    {
        $name = $this->prefix . '_vm' . $suffix;
        repo_execute($this->db, 'INSERT INTO deploy_vms (mission_id,vm_name,vm_hostname) VALUES (?,?,?)', 'iss', [$this->missionId, $name, substr(str_replace('-', '', $name), -15)]);
        $id = (int) $this->db->insert_id;
        repo_execute($this->db, "INSERT INTO deploy_interfaces (vm_id,ip,subnet,gateway,vlan,mac) VALUES (?,'','','','WDS','')", 'i', [$id]);

        return $id;
    }

    /** @return array<string,mixed> */
    private function binding(int $vmId): array
    {
        $row = repo_fetch_one($this->db, 'SELECT vm_instance_uuid, vm_bound_credential_id, vm_bound_esxi_host FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
        self::assertNotNull($row);

        return $row;
    }

    /** @return array<string,mixed> */
    private function payload(string $mode, int $vmId): array
    {
        return ['mode' => $mode, 'vm_ids' => [$vmId], 'verbose' => false, 'powercycle_wait' => 5, 'start_wait' => 30];
    }

    private function uuid(int $vmId): string
    {
        return sprintf('52677625-4986-ed5b-89b5-%012d', $vmId);
    }

    private function host(string $tag): string
    {
        return $this->prefix . '.' . $tag . '.invalid';
    }

    private function credential(string $type, string $tag): int
    {
        repo_execute($this->db, 'INSERT INTO deploy_credentials (type,name,host,username,secret_ciphertext) VALUES (?,?,?,?,?)', 'sssss', [$type, $this->prefix . '_' . $type . '_' . $tag, $this->host($tag), 'fixture', 'unused-ciphertext']);

        return (int) $this->db->insert_id;
    }
}
