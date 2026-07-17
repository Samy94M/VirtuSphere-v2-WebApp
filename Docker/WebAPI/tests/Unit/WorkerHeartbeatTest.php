<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/worker_heartbeat.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';

/**
 * Container liveness heartbeat (AP8): the workers touch a file, the compose
 * healthcheck judges its age through lib/worker_healthcheck.php. These tests
 * pin the freshness semantics and the consistency of the window against the
 * cadences that must fit inside it.
 */
final class WorkerHeartbeatTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'vs-hb-');
        self::assertIsString($file);
        $this->file = $file;
        putenv('VIRTUSPHERE_WORKER_HEARTBEAT_FILE=' . $this->file);
    }

    protected function tearDown(): void
    {
        putenv('VIRTUSPHERE_WORKER_HEARTBEAT_FILE');
        @unlink($this->file);
    }

    public function testAMissingFileIsNeverFresh(): void
    {
        unlink($this->file);
        self::assertFalse(worker_heartbeat_is_fresh());
    }

    public function testATouchIsFreshUntilTheWindowEndsAndStaleAfterIt(): void
    {
        worker_heartbeat_touch();
        self::assertTrue(worker_heartbeat_is_fresh());

        $touchedAt = filemtime($this->file);
        self::assertIsInt($touchedAt);
        self::assertTrue(worker_heartbeat_is_fresh($touchedAt + VIRTUSPHERE_WORKER_HEARTBEAT_MAX_AGE_SECONDS));
        self::assertFalse(worker_heartbeat_is_fresh($touchedAt + VIRTUSPHERE_WORKER_HEARTBEAT_MAX_AGE_SECONDS + 1));
    }

    public function testTouchCreatesTheFileWhenItIsGone(): void
    {
        unlink($this->file);
        worker_heartbeat_touch();
        self::assertFileExists($this->file);
        self::assertTrue(worker_heartbeat_is_fresh());
    }

    public function testTheWindowCoversEveryLegitimateTouchGap(): void
    {
        // Largest legitimate gap between touches: the 30s DB-reconnect backoff
        // (deploy_worker_connect_db / maintenance_worker_connect_db). The
        // window needs real margin above it, and must sit above the loop
        // cadences and the transport silence tick, which are the other paths
        // that keep the file fresh.
        self::assertGreaterThanOrEqual(2 * 30, VIRTUSPHERE_WORKER_HEARTBEAT_MAX_AGE_SECONDS);
        self::assertGreaterThan(VIRTUSPHERE_DEPLOY_WORKER_SLEEP_SECONDS, VIRTUSPHERE_WORKER_HEARTBEAT_MAX_AGE_SECONDS);
        self::assertGreaterThan(VIRTUSPHERE_MAINTENANCE_WORKER_SLEEP_SECONDS, VIRTUSPHERE_WORKER_HEARTBEAT_MAX_AGE_SECONDS);
        self::assertGreaterThan(VIRTUSPHERE_SSH_SILENCE_TICK_SECONDS, VIRTUSPHERE_WORKER_HEARTBEAT_MAX_AGE_SECONDS);
    }
}
