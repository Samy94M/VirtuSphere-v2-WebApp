<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';

/**
 * health.php is an ADDRESS PROBE before it is a health report, and that decides
 * its HTTP status.
 *
 * It used to answer 503 for `degraded`. PowerShell 5.1's Invoke-RestMethod throws
 * a terminating error on any 5xx (and swallows the body), so `Resolve-VsApi`
 * discarded the address: one stale deploy job, or a worker restart, made the
 * portal look unreachable to every client script on every VM at once. The same
 * 503 also made this very test suite skip its integration tests, because they
 * use this endpoint as their reachability check - the defect hid its own class of
 * evidence.
 *
 * So: 200 for ok AND for degraded, and 503 reserved for the case where the
 * service genuinely cannot serve requests (the catch branch).
 */
final class HealthEndpointStatusTest extends TestCase
{
    private const PREFIX = 'phpunit_health_';

    private ?mysqli $db = null;
    private int $missionId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testAHealthyPortalAnswers200AndOk(): void
    {
        [$status, $payload] = $this->probe();

        self::assertSame(200, $status);
        // Not asserted as 'ok': the dev stack may legitimately be degraded (an
        // unwritable log dir). What this pins is the status CODE and that the
        // body still names the state.
        self::assertContains($payload['status'] ?? null, ['ok', 'degraded']);
    }

    public function testADegradedPortalStillAnswers200(): void
    {
        $this->insertStaleRunningJob();

        [$status, $payload] = $this->probe();

        self::assertSame(
            200,
            $status,
            'a degraded portal must stay reachable for the machine chain; 503 stops every client script'
        );
        self::assertSame('degraded', $payload['status'] ?? null, 'the nuance belongs in the body, and must be there');
    }

    public function testTheBodyExposesNothingAnUnauthenticatedCallerShouldNotSee(): void
    {
        $this->insertStaleRunningJob();

        [, $payload] = $this->probe();

        self::assertSame(['status', 'db', 'php'], array_keys($payload));
        foreach (['running_jobs', 'stale_running_jobs', 'latest_heartbeat_at', 'worker', 'logs', 'backup'] as $leak) {
            self::assertArrayNotHasKey($leak, $payload, $leak . ' is operational detail for any host in the deploy VLAN');
        }
    }

    /**
     * The 503 branch still exists and is the only one: proven at the source,
     * because forcing a real database outage would take the stack down for every
     * other test in this suite.
     */
    public function testOnlyTheCatchBranchSets503(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/health.php');
        $catchAt = strpos($source, 'catch (Throwable');
        self::assertIsInt($catchAt, 'health.php must keep its catch branch');
        $catchBranch = substr($source, $catchAt);

        self::assertSame(1, substr_count($source, 'http_response_code(503)'), 'exactly one 503 in the whole file');
        self::assertStringContainsString('http_response_code(503)', $catchBranch, 'and it belongs to the catch branch');
        self::assertStringNotContainsString("'degraded'", $catchBranch, 'the catch branch reports error, never degraded');
    }

    /** @return array{0:int, 1:array<string, mixed>} */
    private function probe(): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        $body = @file_get_contents(virtusphere_test_base_url() . '/portal/health.php', false, $context);
        if ($body === false) {
            self::markTestSkipped('VirtuSphere test stack is not reachable.');
        }

        // $http_response_header is populated by the wrapper on every answered
        // request; file_get_contents returning a body proves there was one.
        $status = 0;
        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        $payload = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return [$status, $payload];
    }

    /**
     * A running job whose heartbeat is older than the reaper's window: exactly
     * the state the endpoint calls `degraded`.
     */
    private function insertStaleRunningJob(): void
    {
        $name = self::PREFIX . 'm';
        $status = 'active';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();
        $this->missionId = (int) $this->db->insert_id;

        $running = VIRTUSPHERE_DEPLOY_STATUS_RUNNING;
        $payload = json_encode(['mode' => 'full', 'vm_ids' => []], JSON_THROW_ON_ERROR);
        $worker = self::PREFIX . 'worker';
        $stale = VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS * 2;
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json, locked_at, locked_by, heartbeat_at) VALUES (?, ?, ?, NOW(), ?, DATE_SUB(NOW(), INTERVAL ? SECOND))');
        $stmt->bind_param('isssi', $this->missionId, $running, $payload, $worker, $stale);
        $stmt->execute();
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        foreach ([
            'DELETE FROM deploy_jobs WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)',
            'DELETE FROM deploy_missions WHERE mission_name LIKE ?',
        ] as $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('s', $like);
            $stmt->execute();
        }
        $this->missionId = 0;
    }
}
