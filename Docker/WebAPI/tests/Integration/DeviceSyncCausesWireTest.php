<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ClientIpAllowlist.php';
require_once dirname(__DIR__, 2) . '/lib/device_sync_cause_constants.php';
require_once dirname(__DIR__, 2) . '/lib/repo/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vm_sync_causes.php';

/**
 * Wire contract of `mecm_report.php?action=reportDeviceCauses` (MX1, FM-05)
 * against the live stack: per-entry revision fence, a deleted VM, complete
 * versus partial reports, replay, retention, token, IP gate and byte bound.
 *
 * A complete report replaces the whole table by design, so this class owns the
 * table while it runs and leaves it empty.
 */
final class DeviceSyncCausesWireTest extends TestCase
{
    use ClientIpAllowlist;

    private const MISSION = 'PHPUNIT-DEVICE-CAUSE-WIRE-MISSION';

    private mysqli $db;
    /** @var array{pending:int,second:int,registered:int} */
    private array $vm;

    protected function setUp(): void
    {
        $health = @file_get_contents(virtusphere_test_base_url() . '/portal/health.php');
        if ($health === false) {
            self::markTestSkipped('VirtuSphere test stack is not reachable.');
        }
        $this->db = db(true);
        $this->cleanup();
        $this->ensureClientIpAllowlisted($this->db);
        repo_execute($this->db, 'INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)',
            'ss', [self::MISSION, 'active']);
        $missionId = (int) $this->db->insert_id;
        $this->vm = [
            'pending' => $this->insertVm($missionId, 'PHPUNIT-CAUSE-PENDING', 7, 'pending', 0),
            'second' => $this->insertVm($missionId, 'PHPUNIT-CAUSE-SECOND', 2, 'pending', 0),
            'registered' => $this->insertVm($missionId, 'PHPUNIT-CAUSE-REGISTERED', 3, 'registered', 0),
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
        $this->restoreClientIpAllowlistIfTouched();
    }

    public function testAcceptedCausesLandAndTheResponseCarriesTheReceipt(): void
    {
        $run = $this->runId();
        [$status, $body] = $this->post($this->report($run, true, [
            [$this->vm['pending'], 7, 'mac_missing'],
            [$this->vm['second'], 2, 'resource_id_pending'],
        ]));

        self::assertSame(200, $status, $body);
        self::assertSame([
            'success' => true,
            'schema_version' => 1,
            'run_id' => $run,
            'complete' => true,
            'accepted' => 2,
            'stale' => 0,
            'unknown_vm' => 0,
            'deduplicated' => false,
        ], $this->json($body));

        $current = repo_vm_sync_causes_current($this->db, [$this->vm['pending'], $this->vm['second']]);
        self::assertSame('mac_missing', $current[$this->vm['pending']]['code']);
        self::assertSame(7, $current[$this->vm['pending']]['rollout_revision']);
        self::assertSame($run, $current[$this->vm['pending']]['run_id']);
        self::assertSame('resource_id_pending', $current[$this->vm['second']]['code']);
    }

    /** ADR-0043 per entry: an older and a newer revision are both counted, never 409. */
    public function testTheRevisionFenceCountsOlderAndNewerAsStale(): void
    {
        [$status, $body] = $this->post($this->report($this->runId(), false, [
            [$this->vm['pending'], 6, 'mac_missing'],
            [$this->vm['second'], 3, 'mac_missing'],
            [$this->vm['registered'], 3, 'mac_missing'],
        ]));

        self::assertSame(200, $status, $body);
        $answer = $this->json($body);
        self::assertSame([0, 3, 0], [$answer['accepted'], $answer['stale'], $answer['unknown_vm']]);
        self::assertSame(0, $this->rowCount(), 'stale entries write nothing; a registered VM off the list is stale too');
    }

    public function testAResetHidesTheOldCauseAndAStaleReplayCannotRestoreIt(): void
    {
        $run = $this->runId();
        $this->post($this->report($run, false, [[$this->vm['pending'], 7, 'mac_missing']]));
        repo_execute($this->db, 'UPDATE deploy_vms SET mecm_rollout_revision = 8 WHERE id = ?', 'i', [$this->vm['pending']]);

        self::assertSame([], repo_vm_sync_causes_current($this->db, [$this->vm['pending']]), 'the row of revision 7 is not a cause of rollout 8');

        [, $body] = $this->post($this->report($run, false, [[$this->vm['pending'], 7, 'mac_missing']]));
        self::assertSame(1, $this->json($body)['stale']);
        self::assertSame([], repo_vm_sync_causes_current($this->db, [$this->vm['pending']]));
    }

    public function testADeletedVmIsCountedAndDoesNotFailTheReport(): void
    {
        $gone = $this->vm['second'];
        repo_execute($this->db, 'DELETE FROM deploy_vms WHERE id = ?', 'i', [$gone]);

        [$status, $body] = $this->post($this->report($this->runId(), true, [
            [$gone, 2, 'mac_missing'],
            [$this->vm['pending'], 7, 'mission_missing'],
        ]));

        self::assertSame(200, $status, $body);
        $answer = $this->json($body);
        self::assertSame([1, 0, 1], [$answer['accepted'], $answer['stale'], $answer['unknown_vm']]);
        self::assertSame(1, $this->rowCount());
    }

    public function testAPartialReportOnlyUpsertsAndACompleteReportReplacesTheRest(): void
    {
        $first = $this->runId();
        $this->post($this->report($first, true, [
            [$this->vm['pending'], 7, 'mac_missing'],
            [$this->vm['second'], 2, 'mac_missing'],
        ]));

        $partial = $this->runId();
        [, $body] = $this->post($this->report($partial, false, [[$this->vm['pending'], 7, 'mac_conflict']]));
        self::assertFalse($this->json($body)['deduplicated']);
        $rows = repo_vm_sync_causes_current($this->db, [$this->vm['pending'], $this->vm['second']]);
        self::assertSame('mac_conflict', $rows[$this->vm['pending']]['code']);
        self::assertSame($first, $rows[$this->vm['second']]['run_id'], 'complete=false never removes a row it did not name');

        $complete = $this->runId();
        [, $body] = $this->post($this->report($complete, true, [[$this->vm['second'], 2, 'resource_id_pending']]));
        self::assertFalse($this->json($body)['deduplicated']);
        $rows = repo_vm_sync_causes_current($this->db, [$this->vm['pending'], $this->vm['second']]);
        self::assertSame([$this->vm['second']], array_keys($rows), 'complete=true replaces every row of other runs');

        [, $body] = $this->post($this->report($this->runId(), true, []));
        self::assertSame(0, $this->rowCount(), 'an empty complete report clears the table');
    }

    public function testAnIdenticalReplayIsDeduplicatedAndKeepsTheObservationTime(): void
    {
        $run = $this->runId();
        $payload = $this->report($run, true, [[$this->vm['pending'], 7, 'mac_missing']]);
        $this->post($payload);
        repo_execute($this->db, 'UPDATE deploy_vm_sync_causes SET observed_at = ? WHERE vm_id = ?', 'si', ['2026-11-01 08:00:00', $this->vm['pending']]);

        [$status, $body] = $this->post($payload);

        self::assertSame(200, $status, $body);
        $answer = $this->json($body);
        self::assertTrue($answer['deduplicated']);
        self::assertSame(1, $answer['accepted']);
        self::assertSame('2026-11-01 08:00:00', (string) repo_scalar($this->db,
            'SELECT observed_at FROM deploy_vm_sync_causes WHERE vm_id = ?', 'i', [$this->vm['pending']]));

        // The next run reporting the same cause refreshes the observation.
        [, $body] = $this->post($this->report($this->runId(), true, [[$this->vm['pending'], 7, 'mac_missing']]));
        self::assertFalse($this->json($body)['deduplicated']);
        self::assertNotSame('2026-11-01 08:00:00', (string) repo_scalar($this->db,
            'SELECT observed_at FROM deploy_vm_sync_causes WHERE vm_id = ?', 'i', [$this->vm['pending']]));
    }

    public function testRetentionRemovesOnlyCausesOlderThanTheWindow(): void
    {
        $this->post($this->report($this->runId(), false, [
            [$this->vm['pending'], 7, 'mac_missing'],
            [$this->vm['second'], 2, 'mac_missing'],
        ]));
        repo_execute($this->db,
            'UPDATE deploy_vm_sync_causes SET observed_at = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE vm_id = ?',
            'ii', [VIRTUSPHERE_DEVICE_SYNC_CAUSE_RETENTION_DAYS + 1, $this->vm['pending']]);

        self::assertSame(1, repo_purge_device_sync_causes($this->db));
        self::assertSame([$this->vm['second']], array_keys(repo_vm_sync_causes_current($this->db, [$this->vm['pending'], $this->vm['second']])));
    }

    public function testDeletingTheVmCascadesItsCause(): void
    {
        $this->post($this->report($this->runId(), false, [[$this->vm['pending'], 7, 'mac_missing']]));
        repo_execute($this->db, 'DELETE FROM deploy_vms WHERE id = ?', 'i', [$this->vm['pending']]);

        self::assertSame(0, $this->rowCount());
    }

    public function testAValidationRefusalWritesNothing(): void
    {
        [$status, $body] = $this->post($this->report($this->runId(), true, [
            [$this->vm['pending'], 7, 'mac_missing'],
            [$this->vm['pending'], 7, 'mac_conflict'],
        ]));

        self::assertSame(400, $status);
        self::assertSame(['error' => 'duplicate_vm_id'], $this->json($body));
        self::assertSame(0, $this->rowCount());
    }

    public function testTheActionUsesItsOwnByteBound(): void
    {
        // Above the 8-KiB report bound but inside 64 KiB: the validator answers.
        $causes = [];
        for ($i = 1; $i <= 120; $i++) {
            $causes[] = [$i + 1000000000, 1, 'membership_provenance_invalid'];
        }
        $payload = $this->report($this->runId(), false, $causes);
        self::assertGreaterThan(VIRTUSPHERE_CLIENT_EVENT_MAX_BODY_BYTES, strlen(json_encode($payload, JSON_THROW_ON_ERROR)));
        [$status, $body] = $this->post($payload);
        self::assertSame(200, $status, $body);
        self::assertSame(120, $this->json($body)['unknown_vm']);

        [$status, $body] = $this->post($this->report($this->runId(), false, []) + ['padding' => str_repeat('x', VIRTUSPHERE_DEVICE_SYNC_CAUSE_MAX_BODY_BYTES)]);
        self::assertSame(413, $status);
        self::assertSame(['error' => 'Payload too large'], $this->json($body));
    }

    public function testTheActionRequiresTheIpAllowlist(): void
    {
        $this->ensureClientIpNotAllowlisted($this->db);

        [$status] = $this->post($this->report($this->runId(), false, [[$this->vm['pending'], 7, 'mac_missing']]));

        self::assertSame(403, $status);
        self::assertSame(0, $this->rowCount());
    }

    public function testTheActionRequiresTheReportTokenWhenOneIsConfigured(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/repo/settings.php';
        $key = VIRTUSPHERE_SETTING_MACHINE_REPORT_TOKEN_HASH;
        $previous = repo_setting_value($this->db, $key, '');
        repo_set_setting($this->db, $key, hash('sha256', 'phpunit-device-cause-token'));
        try {
            $payload = $this->report($this->runId(), false, [[$this->vm['pending'], 7, 'mac_missing']]);
            [$status] = $this->post($payload);
            self::assertSame(401, $status, 'reportDeviceCauses must require the report token when configured');
            self::assertSame(0, $this->rowCount());

            [$status, $body] = $this->post($payload, 'phpunit-device-cause-token');
            self::assertSame(200, $status, $body);
            self::assertSame(1, $this->json($body)['accepted']);
        } finally {
            repo_set_setting($this->db, $key, $previous);
        }
    }

    private function insertVm(int $missionId, string $name, int $revision, string $syncState, int $updated): int
    {
        repo_execute($this->db,
            'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, mecm_rollout_revision, mecm_sync_state, updated) VALUES (?, ?, ?, ?, ?, ?)',
            'issisi', [$missionId, $name, $name, $revision, $syncState, $updated]);

        return (int) $this->db->insert_id;
    }

    /**
     * @param list<array{0:int,1:int,2:string}> $causes
     * @return array<string,mixed>
     */
    private function report(string $runId, bool $complete, array $causes): array
    {
        return [
            'schema_version' => 1,
            'run_id' => $runId,
            'complete' => $complete,
            'causes' => array_map(static fn (array $c): array => ['vm_id' => $c[0], 'rollout_revision' => $c[1], 'code' => $c[2]], $causes),
        ];
    }

    private function runId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function rowCount(): int
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_vm_sync_causes');
    }

    /** @return array<string,mixed> */
    private function json(string $body): array
    {
        $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /** @return array{0:int,1:string} */
    private function post(array $payload, ?string $token = null): array
    {
        $headers = "Content-Type: application/json\r\n";
        if ($token !== null) {
            $headers .= 'X-VirtuSphere-Token: ' . $token . "\r\n";
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => $headers,
            'content' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        $body = file_get_contents(virtusphere_test_base_url() . '/mecm_report.php?action=reportDeviceCauses', false, $context);
        self::assertIsString($body);
        $status = 0;
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match) === 1) {
                $status = (int) $match[1];
                break;
            }
        }

        return [$status, $body];
    }

    private function cleanup(): void
    {
        repo_execute($this->db, 'DELETE FROM deploy_vm_sync_causes');
        repo_execute($this->db, 'DELETE FROM deploy_missions WHERE mission_name = ?', 's', [self::MISSION]);
    }
}
