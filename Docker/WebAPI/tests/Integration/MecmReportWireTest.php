<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Wire contract of the report channel (ADR-0018). Runs against the live
// Docker stack like MachineApiWireTest; the POST helper is the first POST
// usage in the wire suite.
final class MecmReportWireTest extends TestCase
{
    protected function setUp(): void
    {
        $health = @file_get_contents(virtusphere_test_base_url() . '/portal/health.php');
        if ($health === false) {
            self::markTestSkipped('VirtuSphere test stack is not reachable.');
        }
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
        [$status, , $body] = $this->post('/mecm_report.php?action=heartbeat', ['source' => 'unknown-source', 'interval_seconds' => 10]);
        $this->skipUnlessAuthorized($status);
        if ($status === 403) {
            self::markTestSkipped('Current test client IP is not allowlisted.');
        }
        self::assertSame(400, $status);
        self::assertSame(['error' => 'Invalid source'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));

        [$status, , $body] = $this->post('/mecm_report.php?action=heartbeat', ['source' => 'device-sync', 'interval_seconds' => 0]);
        self::assertSame(400, $status);
        self::assertSame(['error' => 'Invalid interval_seconds'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testHeartbeatRoundTripLandsInDatabase(): void
    {
        [$status, , $body] = $this->post('/mecm_report.php?action=heartbeat', ['source' => 'device-sync', 'interval_seconds' => 10, 'detail' => 'phpunit wire test']);
        $this->skipUnlessAuthorized($status);
        if ($status === 403) {
            self::markTestSkipped('Current test client IP is not allowlisted.');
        }

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
