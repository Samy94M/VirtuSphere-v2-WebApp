<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_create_identity.php';
require_once dirname(__DIR__, 2) . '/lib/repo/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_network_preflight.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_blockers.php';

/** SC-007: persisted remote uncertainty fences every fresh admission path. */
final class DeployCreateHistoricalFenceTest extends TestCase
{
    private mysqli $db;
    private ?mysqli $second = null;
    private string $prefix;
    private int $missionId;
    private int $userId;
    private int $esxiId;
    private int $ansibleId;
    private array $missions = [];
    private array $credentials = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->prefix = 'fence-' . bin2hex(random_bytes(4));
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        $this->esxiId = $this->credential('esxi', $this->prefix . '.invalid');
        $this->ansibleId = $this->credential('ansible', 'ansible.invalid');
        $this->missionId = $this->mission();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->missions as $id) {
            repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$id]);
        }
        foreach ($this->credentials as $id) {
            repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$id]);
        }
        $this->second?->close();
    }

    public static function inflightStates(): array
    {
        return array_map(static fn (string $state): array => [$state], VIRTUSPHERE_CREATE_RESULT_INFLIGHT_STATUSES);
    }

    #[DataProvider('inflightStates')]
    public function testFreshQueueAndStaggerRefuseEveryUnresolvedState(string $state): void
    {
        [$jobId, $vmId] = $this->history($state);
        foreach (['queue', 'stagger'] as $path) {
            try {
                $path === 'queue'
                    ? $this->queue([$vmId])
                    : repo_enqueue_deploy_group($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'full', 'vm_ids' => [$vmId]], null, 1);
                self::fail($path . ' admitted an unresolved historical Create');
            } catch (DeployCreateHistoryBlocked $exception) {
                self::assertSame($jobId, $exception->findings[0]['job_id']);
            }
        }
        self::assertSame(1, (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_jobs WHERE mission_id = ?', 'i', [$this->missionId]));
        self::assertSame($state, repo_deploy_create_results($this->db, $jobId)[0]['status']);
        $items = deploy_queue_blockers($this->db, ['mission_id' => $this->missionId, 'credential_esxi_id' => $this->esxiId, 'credential_ansible_id' => $this->ansibleId, 'mode' => 'create', 'vm_ids' => [$vmId]]);
        $history = array_values(array_filter($items, static fn (array $item): bool => $item['code'] === 'create_history_unresolved'));
        self::assertCount(1, $history);
        self::assertSame(deploy_job_log_url($jobId), $history[0]['action']['url']);
    }

    public function testExactScopeHostAliasesAndRenamedPortalIdentity(): void
    {
        [$jobId, $vmId] = $this->history('uncertain');
        $name = (string) repo_scalar($this->db, 'SELECT vm_name FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
        $otherMission = $this->mission();
        $namesake = $this->vm($otherMission, $name);
        // The schema's mission/name uniqueness remains case-insensitive. A
        // third mission makes this exact-name negative control legal data.
        $caseMission = $this->mission();
        $caseOnly = $this->vm($caseMission, strtolower($name));
        $alias = $this->credential('esxi', 'http://' . strtoupper($this->prefix) . '.INVALID:8443');
        self::assertCount(1, repo_deploy_create_fence_conflicts($this->db, $otherMission, $alias, [$namesake]));
        self::assertSame([], repo_deploy_create_fence_conflicts($this->db, $caseMission, $alias, [$caseOnly]));
        $otherHost = $this->credential('esxi', 'other-' . $this->prefix . '.invalid');
        self::assertSame([], repo_deploy_create_fence_conflicts($this->db, $otherMission, $otherHost, [$namesake]));
        repo_execute($this->db, 'UPDATE deploy_vms SET vm_name = ? WHERE id = ?', 'si', ['RENAMED_' . $this->prefix, $vmId]);
        self::assertCount(1, repo_deploy_create_fence_conflicts($this->db, $this->missionId, $otherHost, [$vmId]));
        $independent = $this->vm($this->missionId, 'INDEPENDENT_' . $this->prefix);
        self::assertGreaterThan($jobId, $this->queue([$independent]));
    }

    public function testKnownUuidAndMissingHostEvidenceRemainBlocked(): void
    {
        [$jobId, $vmId] = $this->history('uncertain');
        $otherMission = $this->mission();
        $other = $this->vm($otherMission, 'OTHER_' . $this->prefix);
        repo_execute($this->db, 'UPDATE deploy_create_vm_results SET precheck_instance_uuid = ? WHERE job_id = ?', 'si', ['uuid-exact', $jobId]);
        repo_execute($this->db, 'UPDATE deploy_vms SET vm_instance_uuid = ? WHERE id = ?', 'si', ['uuid-exact', $other]);
        self::assertCount(1, repo_deploy_create_fence_conflicts($this->db, $otherMission, $this->esxiId, [$other]));
        // Simulates a historical deleted credential; the product delete path
        // now refuses this while uncertainty exists.
        repo_execute($this->db, 'UPDATE deploy_jobs SET credential_esxi_id = NULL WHERE id = ?', 'i', [$jobId]);
        $otherHost = $this->credential('esxi', 'other-' . $this->prefix . '.invalid');
        self::assertCount(1, repo_deploy_create_fence_conflicts($this->db, $otherMission, $otherHost, [$other]));
    }

    public function testClaimSkipsBlockedCandidateAndKeepsItsAttemptUntouched(): void
    {
        [, $vmId] = $this->history('uncertain');
        $blocked = $this->rawJob($vmId);
        $independent = $this->vm($this->missionId, 'INDEPENDENT_' . $this->prefix);
        $allowed = $this->rawJob($independent);
        $claimed = repo_claim_next_deploy_job($this->db, $this->prefix);
        self::assertNotNull($claimed);
        self::assertSame($allowed, (int) $claimed['id']);
        $waiting = repo_deploy_job($this->db, $blocked);
        self::assertSame('queued', $waiting['status']);
        self::assertSame(0, (int) $waiting['attempts']);
        self::assertNull($waiting['locked_by']);
    }

    public function testWorkerRecheckStopsBeforeAnyVmMutation(): void
    {
        [, $vmId] = $this->history('uncertain');
        $jobId = $this->rawJob($vmId);
        repo_execute($this->db, 'UPDATE deploy_jobs SET status = ?, locked_by = ?, lock_token = ?, worker_epoch = 1 WHERE id = ?', 'sssi', ['running', $this->prefix, bin2hex(random_bytes(16)), $jobId]);
        $job = repo_deploy_job($this->db, $jobId);
        $before = repo_fetch_one($this->db, 'SELECT lifecycle_state, mecm_sync_state FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
        try {
            deploy_worker_network_preflight(deploy_worker_open_db_channel($this->db, $jobId, $this->prefix), $job, $this->prefix);
            self::fail('Worker accepted unresolved historical Create');
        } catch (DeployWorkerConfigurationBlocked $exception) {
            self::assertInstanceOf(DeployCreateHistoryBlocked::class, $exception->getPrevious());
        }
        self::assertSame($before, repo_fetch_one($this->db, 'SELECT lifecycle_state, mecm_sync_state FROM deploy_vms WHERE id = ?', 'i', [$vmId]));
    }

    public function testClaimDoesNotAcquireHistoricalParentBehindCurrentJob(): void
    {
        [$oldJob] = $this->history('uncertain');
        $vmId = $this->vm($this->missionId, 'INDEPENDENT_' . $this->prefix);
        $newJob = $this->rawJob($vmId);
        $second = $this->secondConnection();
        // A regression must fail in one second, never hang the QA runner.
        repo_execute($second, 'SET SESSION innodb_lock_wait_timeout = 1');
        repo_transaction($this->db, function () use ($second, $oldJob, $newJob): void {
            // Credential edits and service actions start at the earlier job.
            repo_fetch_one($this->db, 'SELECT id FROM deploy_jobs WHERE id = ? FOR UPDATE', 'i', [$oldJob]);
            repo_transaction($second, function () use ($second, $newJob): void {
                $claimed = repo_claim_next_deploy_job($second, $this->prefix);
                self::assertSame($newJob, (int) ($claimed['id'] ?? 0), 'Claim must complete while the historical parent is locked elsewhere');
                // The second transaction really holds J2. A's forward edge to
                // it would wait; there is no reverse edge from B to J1.
                try {
                    repo_fetch_one($this->db, 'SELECT id FROM deploy_jobs WHERE id = ? FOR UPDATE NOWAIT', 'i', [$newJob]);
                    self::fail('The second transaction did not retain its claimed job lock');
                } catch (mysqli_sql_exception $exception) {
                    self::assertSame(3572, $exception->getCode());
                }
            });
            self::assertSame($newJob, (int) repo_fetch_one($this->db, 'SELECT id FROM deploy_jobs WHERE id = ? FOR UPDATE NOWAIT', 'i', [$newJob])['id']);
        });
    }

    public function testPinnedSnapshotCannotHideNewHistoricalEffectOrItsCredential(): void
    {
        $vmId = $this->vm($this->missionId, 'LATE_' . $this->prefix);
        $second = $this->secondConnection();
        repo_transaction($this->db, function () use ($second, $vmId): void {
            repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_jobs');
            $source = repo_create_deploy_job($second, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'create', 'vm_ids' => [$vmId]]);
            $this->markHistorical($second, $source, 'uncertain');
            try {
                $this->queue([$vmId]);
                self::fail('A pinned snapshot hid a committed historical Create');
            } catch (DeployCreateHistoryBlocked $exception) {
                self::assertSame($source, $exception->findings[0]['job_id']);
            }
            try {
                repo_update_credential($this->db, $this->esxiId, $this->credentialPayload('changed.invalid'));
                self::fail('A pinned joined-job snapshot hid the historical credential association');
            } catch (ValidationException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        });
        self::assertSame($this->prefix . '.invalid', repo_scalar($this->db, 'SELECT host FROM deploy_credentials WHERE id = ?', 'i', [$this->esxiId]));
    }

    public function testReviewedReleaseRestoresAdmissionAndCredentialRetarget(): void
    {
        [$jobId, $vmId] = $this->history('uncertain');
        foreach ([false, true] as $delete) {
            try {
                $delete ? repo_delete_credential($this->db, $this->esxiId)
                    : repo_update_credential($this->db, $this->esxiId, $this->credentialPayload('changed.invalid'));
                self::fail('Credential change erased historical endpoint evidence');
            } catch (ValidationException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        self::assertTrue(repo_update_credential($this->db, $this->esxiId, $this->credentialPayload($this->prefix . '.invalid')));
        $at = gmdate('Y-m-d H:i:s', time() + 120);
        repo_execute($this->db, 'INSERT INTO deploy_esxi_inventory_state (credential_id, last_success_at, kind_freshness_json) VALUES (?, ?, ?)', 'iss', [$this->esxiId, $at, json_encode(['vm' => $at], JSON_THROW_ON_ERROR)]);
        self::assertTrue(repo_deploy_create_release_unit($this->db, $jobId, 1, $this->userId, 'Synthetic host checked; no VM and no active task.', 'SC-007')['released']);
        self::assertTrue(repo_update_credential($this->db, $this->esxiId, $this->credentialPayload('changed.invalid')));
        self::assertGreaterThan($jobId, $this->queue([$vmId]));
    }

    private function credentialPayload(string $host): array
    {
        return ['type' => 'esxi', 'name' => $this->prefix . '_esxi', 'host' => $host, 'port' => 443, 'username' => 'repair-user'];
    }

    private function history(string $status): array
    {
        $vmId = $this->vm($this->missionId, 'UPPER_' . strtoupper($this->prefix));
        $jobId = $this->queue([$vmId]);
        $this->markHistorical($this->db, $jobId, $status);
        return [$jobId, $vmId];
    }

    private function markHistorical(mysqli $db, int $jobId, string $status): void
    {
        $finished = $status === 'uncertain' ? gmdate('Y-m-d H:i:s') : null;
        repo_execute($db, 'UPDATE deploy_create_vm_results SET status = ?, async_jid = ?, async_deadline_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR), error_code = ?, error_detail = ?, finished_at = ? WHERE job_id = ?', 'sssssi', [$status, '123456789.1', 'job_timeout', 'Synthetic unresolved remote effect.', $finished, $jobId]);
        repo_execute($db, 'UPDATE deploy_jobs SET status = ? WHERE id = ?', 'si', ['failed', $jobId]);
    }

    private function secondConnection(): mysqli
    {
        $this->second = new mysqli(envboot_required('DB_HOST'), envboot_required('DB_USER'), envboot_required('DB_PASS'), envboot_required('DB_NAME'), (int) envboot_optional('DB_PORT', '3306'));
        $this->second->set_charset('utf8mb4');
        repo_execute($this->second, "SET time_zone = '+00:00'");
        return $this->second;
    }

    private function queue(array $ids): int
    {
        return repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => 'create', 'vm_ids' => $ids]);
    }

    private function rawJob(int $vmId): int
    {
        $payload = json_encode(['mode' => 'create', 'vm_ids' => [$vmId]], JSON_THROW_ON_ERROR);
        repo_execute($this->db, 'INSERT INTO deploy_jobs (mission_id,user_id,payload_json,credential_esxi_id,credential_ansible_id) VALUES (?,?,?,?,?)', 'iisii', [$this->missionId, $this->userId, $payload, $this->esxiId, $this->ansibleId]);
        return (int) $this->db->insert_id;
    }

    private function vm(int $missionId, string $name): int
    {
        repo_execute($this->db, 'INSERT INTO deploy_vms (mission_id,vm_name,vm_hostname) VALUES (?,?,?)', 'iss', [$missionId, $name, $name]);
        $id = (int) $this->db->insert_id;
        repo_execute($this->db, "INSERT INTO deploy_interfaces (vm_id,ip,subnet,gateway,vlan,mac) VALUES (?,'','','','WDS','')", 'i', [$id]);
        return $id;
    }

    private function mission(): int
    {
        $name = $this->prefix . '_mission_' . count($this->missions);
        repo_execute($this->db, "INSERT INTO deploy_missions (mission_name,mission_status,hypervisor_datacenter,hypervisor_datastorage,wds_vlan) VALUES (?,'active','DC1','DS1','WDS')", 's', [$name]);
        $id = (int) $this->db->insert_id;
        $this->missions[] = $id;
        return $id;
    }

    private function credential(string $type, string $host): int
    {
        repo_execute($this->db, 'INSERT INTO deploy_credentials (type,name,host,username,secret_ciphertext) VALUES (?,?,?,?,?)', 'sssss', [$type, $this->prefix . '_' . count($this->credentials), $host, 'fixture', 'unused-ciphertext']);
        $id = (int) $this->db->insert_id;
        $this->credentials[] = $id;
        return $id;
    }
}
