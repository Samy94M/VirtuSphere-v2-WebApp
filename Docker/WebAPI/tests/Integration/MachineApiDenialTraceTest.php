<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ClientIpAllowlist.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/integration_health.php';
require_once dirname(__DIR__, 2) . '/lib/repo/log.php';

/**
 * Every machine-API refusal leaves a trace, and the System status can tell
 * "rejected" apart from "never set up".
 *
 * machine_api_forbidden() was one line - machine_api_json(..., 403) - with no
 * audit row, no error_log, no counter, and it did not even take a database
 * connection, while its 401 sibling audited. Six endpoints hang off it.
 *
 * The consequence was the worst kind of silence: the single commonest setup
 * mistake in the product, a missing IP allowlist entry, looked in the portal
 * EXACTLY like a server on which MECM was never installed - a grey "no data yet"
 * row and nothing else. The error report could not report it either, because
 * reportRun sits behind the same gate. Three agents found this independently.
 */
final class MachineApiDenialTraceTest extends TestCase
{
    use ClientIpAllowlist;

    private mysqli $db;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        if (@file_get_contents(virtusphere_test_base_url() . '/portal/health.php') === false) {
            self::markTestSkipped('VirtuSphere test stack is not reachable.');
        }
        $this->clearTrace();
    }

    protected function tearDown(): void
    {
        $this->restoreClientIpAllowlistIfTouched();
        if (isset($this->db)) {
            $this->clearTrace();
        }
    }

    /** @return iterable<string, array{0:string}> */
    public static function gatedEndpoints(): iterable
    {
        // Every endpoint behind the IP gate. A new one that forgets to audit its
        // refusal fails here rather than going quiet in production.
        yield 'device list' => ['/mecm-api.php?action=getDeviceList'];
        yield 'resource id' => ['/mecm_updateid.php?action=updateDevice'];
        yield 'run report' => ['/mecm_report.php?action=reportRun'];
        yield 'heartbeat' => ['/mecm_report.php?action=heartbeat'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gatedEndpoints')]
    public function testARefusedRequestLeavesAnAuditRow(string $path): void
    {
        $this->ensureClientIpNotAllowlisted($this->db);

        [$status, $body] = $this->post($path);

        self::assertSame(403, $status, $body);
        // The wire response is unchanged, byte for byte: the German sentence and
        // the echoed IP are the frozen contract the Ansible preflight probe parses.
        self::assertStringStartsWith('Zugriff verweigert. Ihre IP: ', (string) json_decode($body, true, 512, JSON_THROW_ON_ERROR)['error']);

        $rows = repo_recent_machine_api_denials($this->db, 600);
        self::assertNotSame([], $rows, 'a refusal must leave a trace in the security log; ' . $path . ' left none');
    }

    /** The throttle keeps a polling task from flooding the log. */
    public function testARepeatedRefusalFromTheSameIpDoesNotFloodTheLog(): void
    {
        $this->ensureClientIpNotAllowlisted($this->db);

        for ($i = 0; $i < 4; $i++) {
            [$status] = $this->post('/mecm-api.php?action=getDeviceList');
            self::assertSame(403, $status);
        }

        $rows = repo_recent_machine_api_denials($this->db, 600);
        self::assertCount(1, $rows, 'one IP, one row');
        self::assertSame(1, $rows[0]['hits'], 'a task polling every ten seconds must not write one row per request');
    }

    /**
     * The point of the trace: the page stops claiming the integration is probably
     * not set up when it has positive evidence that it is being refused.
     */
    public function testTheSnapshotCarriesTheDenialSoTheStatusPageCanNameIt(): void
    {
        $this->ensureClientIpNotAllowlisted($this->db);
        [$status] = $this->post('/mecm-api.php?action=getDeviceList');
        self::assertSame(403, $status);

        $snapshot = integration_health_snapshot($this->db);

        self::assertArrayHasKey('machine_api_denials', $snapshot);
        self::assertNotSame([], $snapshot['machine_api_denials'], 'the status page needs the refusal to tell three grey states apart');
        $first = $snapshot['machine_api_denials'][0];
        self::assertNotSame('', (string) $first['ip'], 'the IP is the fix: it is what goes on the allowlist');
        self::assertNotSame('', (string) $first['last_at']);
    }

    /** The category decides the tab, and a refused access is a security question. */
    public function testTheTraceLandsInTheSecurityTabWithTheLongRetention(): void
    {
        self::assertContains(
            VIRTUSPHERE_LOG_CATEGORY_MACHINE_API,
            VIRTUSPHERE_LOG_TABS[VIRTUSPHERE_LOG_TAB_SECURITY],
            'a refused machine access belongs where somebody looks for foreign access'
        );
        self::assertSame(
            VIRTUSPHERE_LOG_RETENTION_SECURITY_DAYS,
            log_retention_days_for_tab(VIRTUSPHERE_LOG_TAB_SECURITY),
            'the misconfiguration behind the commonest case can sit unnoticed for months'
        );
    }

    /** @return array{0:int,1:string} */
    private function post(string $path): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => '{}',
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);
        $body = @file_get_contents(virtusphere_test_base_url() . $path, false, $context);
        if ($body === false) {
            self::markTestSkipped('VirtuSphere test endpoint is not reachable.');
        }

        $status = 0;
        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return [$status, (string) $body];
    }

    private function clearTrace(): void
    {
        $category = VIRTUSPHERE_LOG_CATEGORY_MACHINE_API;
        repo_execute($this->db, 'DELETE FROM deploy_logs WHERE category = ?', 's', [$category]);
        repo_execute($this->db, 'DELETE FROM deploy_audit_throttle WHERE category = ?', 's', [$category]);
    }
}
