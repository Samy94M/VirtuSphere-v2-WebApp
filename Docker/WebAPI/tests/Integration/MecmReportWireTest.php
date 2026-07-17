<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ClientIpAllowlist.php';

// Wire contract of the report channel (ADR-0018). Runs against the live
// Docker stack like MachineApiWireTest; the POST helper is the first POST
// usage in the wire suite.
final class MecmReportWireTest extends TestCase
{
    use ClientIpAllowlist;

    protected function setUp(): void
    {
        $health = @file_get_contents(virtusphere_test_base_url() . '/portal/health.php');
        if ($health === false) {
            self::markTestSkipped('VirtuSphere test stack is not reachable.');
        }
    }

    protected function tearDown(): void
    {
        $this->restoreClientIpAllowlistIfTouched();
    }

    public function testGetIsRejectedWithMethodNotAllowed(): void
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        $body = @file_get_contents(virtusphere_test_base_url() . '/mecm_report.php?action=heartbeat', false, $context);
        if ($body === false) {
            self::markTestSkipped('VirtuSphere test endpoint is not reachable.');
        }

        self::assertSame(['error' => 'Method not allowed'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testInvalidActionReturns400(): void
    {
        [$status, , $body] = $this->post('/mecm_report.php?action=nope', ['x' => 1]);
        $this->skipUnlessAuthorized($status);

        self::assertSame(400, $status);
        self::assertSame(['message' => 'Invalid action specified'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testReportPhaseValidatesMacPhaseAndEvent(): void
    {
        [$status, , $body] = $this->post('/mecm_report.php?action=reportPhase', ['mac' => 'not-a-mac', 'phase' => 'getinfo', 'event' => 'started']);
        $this->skipUnlessAuthorized($status);
        self::assertSame(400, $status);
        self::assertSame(['error' => 'Invalid MAC address'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));

        [$status, , $body] = $this->post('/mecm_report.php?action=reportPhase', ['mac' => '00:50:56:AA:BB:CC', 'phase' => 'reboot', 'event' => 'started']);
        self::assertSame(400, $status);
        self::assertSame(['error' => 'Invalid phase'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));

        [$status, , $body] = $this->post('/mecm_report.php?action=reportPhase', ['mac' => '00:50:56:AA:BB:CC', 'phase' => 'getinfo', 'event' => 'done']);
        self::assertSame(400, $status);
        self::assertSame(['error' => 'Invalid event'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testHeartbeatValidatesSourceAndInterval(): void
    {
        // Selbst allowlisten statt skippen (ADR-0015-Ergaenzung).
        $this->ensureClientIpAllowlisted(db(true));

        [$status, , $body] = $this->post('/mecm_report.php?action=heartbeat', ['source' => 'unknown-source', 'interval_seconds' => 10]);
        $this->skipUnlessAuthorized($status);
        self::assertSame(400, $status);
        self::assertSame(['error' => 'Invalid source'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));

        [$status, , $body] = $this->post('/mecm_report.php?action=heartbeat', ['source' => 'device-sync', 'interval_seconds' => 0]);
        self::assertSame(400, $status);
        self::assertSame(['error' => 'Invalid interval_seconds'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testHeartbeatRoundTripLandsInDatabase(): void
    {
        // Selbst allowlisten statt skippen (ADR-0015-Ergaenzung).
        $this->ensureClientIpAllowlisted(db(true));

        [$status, , $body] = $this->post('/mecm_report.php?action=heartbeat', ['source' => 'device-sync', 'interval_seconds' => 10, 'detail' => 'phpunit wire test']);
        $this->skipUnlessAuthorized($status);

        self::assertSame(200, $status);
        self::assertSame(['success' => true, 'source' => 'device-sync'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));

        require_once dirname(__DIR__, 2) . '/lib/db.php';
        $db = db(true);
        $row = $db->query("SELECT last_status, last_detail, interval_seconds FROM deploy_integration_heartbeats WHERE source = 'device-sync'")->fetch_assoc();
        self::assertIsArray($row);
        self::assertSame('ok', $row['last_status']);
        self::assertSame('phpunit wire test', $row['last_detail']);
        self::assertSame(10, (int) $row['interval_seconds']);
    }

    public function testOversizedBodyReturns413(): void
    {
        $payload = ['mac' => '00:50:56:AA:BB:CC', 'phase' => 'getinfo', 'event' => 'started', 'detail' => str_repeat('x', 9000)];
        [$status, , $body] = $this->post('/mecm_report.php?action=reportPhase', $payload);
        $this->skipUnlessAuthorized($status);

        self::assertSame(413, $status);
        self::assertSame(['error' => 'Payload too large'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testReportTokenGuardsHeartbeatOnly(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/db.php';
        require_once dirname(__DIR__, 2) . '/lib/repo/settings.php';
        try {
            $db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }

        // Configure a token, then prove the gate is scoped to heartbeats. Restore
        // the previous value in finally so a real token setup is never clobbered
        // (empty string = "no token", which is how the endpoint treats it).
        $key = VIRTUSPHERE_SETTING_MACHINE_REPORT_TOKEN_HASH;
        $previous = repo_setting_value($db, $key, '');
        repo_set_setting($db, $key, hash('sha256', 'phpunit-report-token'));
        try {
            // reportPhase authenticates by MAC, so a configured token must NOT turn
            // a token-less client report into a 401 (that was the provisioning trap).
            // A non-allowlisted IP with an unknown MAC lands on the MAC gate (403/404),
            // never on the token gate (401).
            [$status] = $this->post('/mecm_report.php?action=reportPhase', ['mac' => '02:00:00:00:00:01', 'phase' => 'getinfo', 'event' => 'started']);
            self::assertNotSame(401, $status, 'reportPhase must not require the report token');
            self::assertContains($status, [403, 404], 'reportPhase should reach the MAC gate, not the token gate');

            // heartbeat still requires the token when one is configured (checked
            // before the IP gate, so a wrong/missing token is a deterministic 401).
            [$status] = $this->post('/mecm_report.php?action=heartbeat', ['source' => 'device-sync', 'interval_seconds' => 10]);
            self::assertSame(401, $status, 'heartbeat must require the report token when configured');
        } finally {
            repo_set_setting($db, $key, $previous);
        }
    }

    public function testEveryWireSourceIsAcceptedAndReturnsItsShapeInternalSourcesRejected(): void
    {
        $this->ensureClientIpAllowlisted(db(true));

        // The wire contract: exactly the wire sources may report a heartbeat,
        // each returns {success:true, source:<echo>}; the internal-only sources
        // (written by the maintenance worker) are refused at the wire.
        foreach (VIRTUSPHERE_INTEGRATION_WIRE_SOURCES as $source) {
            [$status, , $body] = $this->post('/mecm_report.php?action=heartbeat', ['source' => $source, 'interval_seconds' => 30]);
            $this->skipUnlessAuthorized($status);
            self::assertSame(200, $status, $source . ' must be accepted');
            self::assertSame(['success' => true, 'source' => $source], json_decode($body, true, 512, JSON_THROW_ON_ERROR), $source . ' heartbeat wire shape');
        }

        $internalOnly = array_diff(VIRTUSPHERE_INTEGRATION_SOURCES, VIRTUSPHERE_INTEGRATION_WIRE_SOURCES);
        self::assertNotSame([], $internalOnly, 'there must be internal-only sources to guard');
        foreach ($internalOnly as $source) {
            [$status, , $body] = $this->post('/mecm_report.php?action=heartbeat', ['source' => $source, 'interval_seconds' => 30]);
            $this->skipUnlessAuthorized($status);
            self::assertSame(400, $status, $source . ' must not be reportable over the wire');
            self::assertSame(['error' => 'Invalid source'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testReportPhaseRoundTripEchoesVmIdAndDeduplicates(): void
    {
        $this->ensureClientIpAllowlisted(db(true));
        $db = db(true);
        $prefix = 'phpunit_wire_' . bin2hex(random_bytes(4));
        $mac = sprintf('02:00:%02x:%02x:%02x:%02x', random_int(0, 255), random_int(0, 255), random_int(0, 255), random_int(0, 255));

        require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
        $missionId = 0;
        try {
            $missionId = repo_create_mission($db, ['mission_name' => $prefix . '_m'], false, null);
            $vmName = strtoupper($prefix);
            $stmt = $db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $missionId, $vmName, $vmName);
            $stmt->execute();
            $vmId = (int) $db->insert_id;
            $stmt = $db->prepare("INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac, mode) VALUES (?, '', '', '', 'WDS', ?, 'dhcp')");
            $stmt->bind_param('is', $vmId, $mac);
            $stmt->execute();

            // First report: the success shape echoes the resolved vm_id.
            $detail = $prefix . '-detail';
            [$status, , $body] = $this->post('/mecm_report.php?action=reportPhase', ['mac' => $mac, 'phase' => 'getinfo', 'event' => 'started', 'detail' => $detail]);
            $this->skipUnlessAuthorized($status);
            self::assertSame(200, $status);
            self::assertSame(['success' => true, 'vm_id' => $vmId], json_decode($body, true, 512, JSON_THROW_ON_ERROR), 'reportPhase success wire shape');

            // It landed in the client-event log.
            $count = (int) ($db->query('SELECT COUNT(*) AS c FROM deploy_client_events WHERE vm_id = ' . $vmId)->fetch_assoc()['c'] ?? 0);
            self::assertSame(1, $count, 'the event was recorded once');

            // An identical repeat inside the dedup window is acknowledged as a
            // duplicate and writes no second row.
            [$status2, , $body2] = $this->post('/mecm_report.php?action=reportPhase', ['mac' => $mac, 'phase' => 'getinfo', 'event' => 'started', 'detail' => $detail]);
            self::assertSame(200, $status2);
            self::assertSame(['success' => true, 'vm_id' => $vmId, 'deduplicated' => true], json_decode($body2, true, 512, JSON_THROW_ON_ERROR), 'the duplicate wire shape carries deduplicated:true');
            $count2 = (int) ($db->query('SELECT COUNT(*) AS c FROM deploy_client_events WHERE vm_id = ' . $vmId)->fetch_assoc()['c'] ?? 0);
            self::assertSame(1, $count2, 'the duplicate wrote no second row');
        } finally {
            if ($missionId > 0) {
                $db->query('DELETE FROM deploy_client_events WHERE vm_id IN (SELECT id FROM deploy_vms WHERE mission_id = ' . $missionId . ')');
                $db->query('DELETE FROM deploy_missions WHERE id = ' . $missionId);
            }
        }
    }

    public function testInvalidJsonBodyReturns400(): void
    {
        $this->ensureClientIpAllowlisted(db(true));
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => '{not valid json',
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);
        $body = @file_get_contents(virtusphere_test_base_url() . '/mecm_report.php?action=heartbeat', false, $context);
        if ($body === false) {
            self::markTestSkipped('VirtuSphere test endpoint is not reachable.');
        }
        // $http_response_header is populated by the successful call above; no
        // `?? []` guard (the post() helper's guarded use is the baselined one).
        $status = 0;
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }
        self::assertContains($status, [400, 401], 'malformed JSON is a 400 (or the token gate if one is configured)');
    }

    // Only action=heartbeat can return 401 (report token). reportPhase is
    // MAC-authenticated and never token-gated, so its posts skip on 401 as a
    // no-op safety net only.
    private function skipUnlessAuthorized(int $status): void
    {
        if ($status === 401) {
            self::markTestSkipped('A machine report token is configured; wire test runs token-less.');
        }
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function post(string $path, array $payload): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);
        $body = @file_get_contents(virtusphere_test_base_url() . $path, false, $context);
        if ($body === false) {
            self::markTestSkipped('VirtuSphere test endpoint is not reachable.');
        }

        $status = 0;
        $headers = [];
        foreach (($http_response_header ?? []) as $header) {
            $headers[] = $header;
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return [$status, implode("\n", $headers), $body];
    }
}
