<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ClientIpAllowlist.php';
require_once dirname(__DIR__, 2) . '/lib/package_run_report_constants.php';
require_once dirname(__DIR__, 2) . '/lib/repo/helpers.php';

final class PackageRunReportWireTest extends TestCase
{
    use ClientIpAllowlist;

    private const MISSION = 'PHPUNIT-PACKAGE-WIRE-MISSION';
    private const MAC = '02:00:00:00:57:01';
    private const RUNS = [
        '018f2f49-5e41-4d55-8f05-8f55a5335701',
        '018f2f49-5e41-4d55-8f05-8f55a5335702',
    ];

    private mysqli $db;
    private int $vmId;
    private string $deviceGeneration;
    private string $acceptanceGeneration;

    protected function setUp(): void
    {
        $this->db = db(true);
        $this->cleanup();
        $this->ensureClientIpAllowlisted($this->db);
        $this->acceptanceGeneration = (string) repo_scalar($this->db,
            'SELECT LOWER(BIN_TO_UUID(acceptance_generation)) FROM deploy_package_report_state WHERE id = 1');
        repo_execute($this->db, 'INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)',
            'ss', [self::MISSION, 'active']);
        $missionId = (int) $this->db->insert_id;
        repo_execute($this->db,
            'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, mecm_rollout_revision) VALUES (?, ?, ?, 7)',
            'iss', [$missionId, 'PHPUNIT-PACKAGE-WIRE-VM', 'PHPUNIT-PACKAGE-WIRE-VM']);
        $this->vmId = (int) $this->db->insert_id;
        $this->deviceGeneration = (string) repo_scalar($this->db,
            'SELECT LOWER(BIN_TO_UUID(package_report_generation)) FROM deploy_vms WHERE id = ?', 'i', [$this->vmId]);
        repo_execute($this->db,
            'INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, mac, mode, type) VALUES (?, ?, ?, ?, ?, ?, ?)',
            'issssss', [$this->vmId, '10.0.0.20', '255.255.255.0', '10.0.0.1', self::MAC, 'dhcp', 'vmxnet3']);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->restoreClientIpAllowlistIfTouched();
    }

    public function testRoundTripEchoesCorrelationAndIdenticalReplayIsDeduplicated(): void
    {
        $payload = $this->base(self::RUNS[0]);

        [$status, $body] = $this->post($payload);
        self::assertSame(200, $status);
        self::assertSame([
            'schema_version' => 1,
            'run_id' => self::RUNS[0],
            'event' => 'started',
            'event_seq' => 1,
            'accepted' => true,
            'deduplicated' => false,
        ], json_decode($body, true, 512, JSON_THROW_ON_ERROR));

        [$status, $body] = $this->post($payload);
        self::assertSame(200, $status);
        self::assertSame(false, json_decode($body, true, 512, JSON_THROW_ON_ERROR)['accepted']);
        self::assertSame(true, json_decode($body, true, 512, JSON_THROW_ON_ERROR)['deduplicated']);
    }

    public function testPackageActionUsesItsOwnBoundAndNotTheServerReportToken(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/repo/settings.php';
        $key = VIRTUSPHERE_SETTING_MACHINE_REPORT_TOKEN_HASH;
        $previous = repo_setting_value($this->db, $key, '');
        repo_set_setting($this->db, $key, hash('sha256', 'must-not-be-required'));
        try {
            $withinPackageBound = $this->base(self::RUNS[1]) + ['future_padding' => str_repeat('x', 9000)];
            [$status, $body] = $this->post($withinPackageBound);
            self::assertSame(400, $status, 'package reports must not inherit the legacy 8-KiB body cap');
            self::assertSame(['error' => 'unknown_field'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));

            $oversized = $this->base(self::RUNS[1]) + ['future_padding' => str_repeat('x', 66000)];
            [$status, $body] = $this->post($oversized);
            self::assertSame(413, $status);
            self::assertSame(['error' => 'Payload too large'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        } finally {
            repo_set_setting($this->db, $key, $previous);
        }
    }

    public function testPackageActionRequiresTheIpAllowlist(): void
    {
        $this->ensureClientIpNotAllowlisted($this->db);

        [$status] = $this->post($this->base(self::RUNS[0]));

        self::assertSame(403, $status);
        self::assertSame(0, (int) repo_scalar($this->db,
            'SELECT COUNT(*) FROM deploy_package_run_markers WHERE run_id = UUID_TO_BIN(?)', 's', [self::RUNS[0]]));
    }

    private function base(string $runId): array
    {
        return [
            'schema_version' => 1,
            'run_id' => $runId,
            'event' => 'started',
            'event_seq' => 1,
            'mac_candidates' => [self::MAC],
            'rollout_revision' => 7,
            'device_generation' => $this->deviceGeneration,
            'acceptance_generation' => $this->acceptanceGeneration,
            'project_name' => 'Wire-App',
            'package_version' => '1.0',
            'client_started_at' => '2026-09-22T08:00:00Z',
            'event_at' => '2026-09-22T08:00:00Z',
            'context' => 'system',
            'total' => 1,
        ];
    }

    /** @return array{0:int,1:string} */
    private function post(array $payload): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        $body = file_get_contents(
            virtusphere_test_base_url() . '/mecm_report.php?action=reportPackageRun', false, $context
        );
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
        repo_execute($this->db, 'DELETE FROM deploy_missions WHERE mission_name = ?', 's', [self::MISSION]);
        foreach (self::RUNS as $runId) {
            repo_execute($this->db, 'DELETE FROM deploy_package_run_markers WHERE run_id = UUID_TO_BIN(?)', 's', [$runId]);
        }
    }
}
