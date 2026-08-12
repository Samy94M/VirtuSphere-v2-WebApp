<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/repo/ansible_activity.php';
require_once dirname(__DIR__, 2) . '/lib/integration_health.php';
// The card is the end of this chain, and the browser layer cannot reach it
// while the portal's own login is down, so the render is proven here.
require_once dirname(__DIR__, 2) . '/lib/portal_time.php';
require_once dirname(__DIR__, 2) . '/lib/csrf.php';
require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/system_status_panels.php';

/**
 * Operational Ansible evidence comes from deploy_jobs without mutating or
 * masquerading as the manual preflight state, and it names only jobs a worker
 * actually processed. Every fixture therefore sets attempts explicitly: the
 * schema default 0 means "never claimed", so a row carrying it would prove the
 * opposite of what the display claims.
 */
final class AnsibleActivityTest extends TestCase
{
    private mysqli $db;
    private string $prefix;
    /** @var list<int> */
    private array $credentialIds = [];
    /** @var list<int> */
    private array $missionIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }

        $this->prefix = 'phpunit_ansible_activity_' . bin2hex(random_bytes(4));
        // The panel renders a CSRF-protected repair form; the portal bootstrap
        // normally supplies the session it needs.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_start();
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }

        foreach ($this->credentialIds as $credentialId) {
            $stmt = $this->db->prepare('DELETE FROM deploy_jobs WHERE credential_ansible_id = ?');
            $stmt->bind_param('i', $credentialId);
            $stmt->execute();
        }
        foreach (array_reverse($this->missionIds) as $missionId) {
            $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE id = ?');
            $stmt->bind_param('i', $missionId);
            $stmt->execute();
        }
        foreach ($this->credentialIds as $credentialId) {
            $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE id = ?');
            $stmt->bind_param('i', $credentialId);
            $stmt->execute();
        }
    }

    public function testLatestProcessedMissionJobIsSelectedPerCredential(): void
    {
        $credentialA = $this->insertCredential('a');
        $credentialB = $this->insertCredential('b');
        $credentialWithoutJobs = $this->insertCredential('none');
        $missionA = $this->insertMission('ALPHA');
        $missionB = $this->insertMission('BETA');

        $this->insertJob($credentialA, $missionA, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, '2026-08-11 09:00:00', 1);
        $sameSecondOlderId = $this->insertJob($credentialA, $missionA, VIRTUSPHERE_DEPLOY_STATUS_FAILED, '2026-08-11 10:00:00', 1);
        $sameSecondNewerId = $this->insertJob($credentialA, $missionB, VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, '2026-08-11 10:00:00', 2);

        // Neither an active mission job nor a newer mission-less system job may
        // hide the last outcome an operator can actually inspect as completed.
        $this->insertJob($credentialA, $missionA, VIRTUSPHERE_DEPLOY_STATUS_RUNNING, '2026-08-11 11:00:00', 1);
        $this->insertJob($credentialA, null, VIRTUSPHERE_DEPLOY_STATUS_FAILED, '2026-08-11 12:00:00', 1);

        $credentialBJob = $this->insertJob($credentialB, $missionA, VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, '2026-08-11 08:00:00', 1);

        $rows = repo_latest_completed_ansible_mission_jobs(
            $this->db,
            [$credentialA, $credentialB, $credentialWithoutJobs]
        );

        self::assertArrayHasKey($credentialA, $rows);
        self::assertSame($sameSecondNewerId, (int) $rows[$credentialA]['id'], 'the higher id must break a one-second timestamp tie');
        self::assertNotSame($sameSecondOlderId, (int) $rows[$credentialA]['id']);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_PARTIAL, $rows[$credentialA]['status']);
        self::assertSame($missionB, (int) $rows[$credentialA]['mission_id']);
        self::assertSame($this->prefix . '_BETA', $rows[$credentialA]['mission_name']);

        self::assertSame($credentialBJob, (int) $rows[$credentialB]['id']);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, $rows[$credentialB]['status']);
        self::assertArrayNotHasKey($credentialWithoutJobs, $rows);
    }

    public function testAJobCancelledOutOfTheQueueCannotDisplaceAProcessedOne(): void
    {
        $credential = $this->insertCredential('queued_cancel');
        $mission = $this->insertMission('GAMMA');

        $processed = $this->insertJob($credential, $mission, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, '2026-08-11 09:00:00', 1);
        // queued -> cancelled: terminal and newer, but no worker ever claimed it,
        // so it says nothing about whether this credential can reach anything.
        $neverRan = $this->insertJob($credential, $mission, VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, '2026-08-11 18:00:00', 0);

        $rows = repo_latest_completed_ansible_mission_jobs($this->db, [$credential]);

        self::assertSame($processed, (int) $rows[$credential]['id']);
        self::assertNotSame($neverRan, (int) $rows[$credential]['id']);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, $rows[$credential]['status']);
    }

    public function testACredentialWhoseOnlyJobsNeverRanReportsNothing(): void
    {
        $credential = $this->insertCredential('only_unclaimed');
        $mission = $this->insertMission('DELTA');

        $this->insertJob($credential, $mission, VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, '2026-08-11 07:00:00', 0);
        $this->insertJob($credential, $mission, VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, '2026-08-11 08:00:00', 0);

        $rows = repo_latest_completed_ansible_mission_jobs($this->db, [$credential]);

        self::assertArrayNotHasKey($credential, $rows, 'nothing was processed, so the card must say "none yet" rather than show a wish');
    }

    public function testAClaimedJobCountsWhateverEndedIt(): void
    {
        $preflight = $this->insertCredential('preflight');
        $reaped = $this->insertCredential('reaped');
        $mission = $this->insertMission('EPSILON');

        // Claimed, then failed in its own preflight: the worker did reach the
        // host, and how far it got is exactly what the job log answers.
        $preflightJob = $this->insertJob($preflight, $mission, VIRTUSPHERE_DEPLOY_STATUS_FAILED, '2026-08-11 09:30:00', 1);
        // Cancel confirmed (or reaped) after the claim: it ran, and the display
        // must not hide it behind an older success.
        $this->insertJob($reaped, $mission, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, '2026-08-11 09:00:00', 1);
        $reapedJob = $this->insertJob($reaped, $mission, VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, '2026-08-11 10:00:00', 2);

        $rows = repo_latest_completed_ansible_mission_jobs($this->db, [$preflight, $reaped]);

        self::assertSame($preflightJob, (int) $rows[$preflight]['id']);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_FAILED, $rows[$preflight]['status']);
        self::assertSame($reapedJob, (int) $rows[$reaped]['id']);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, $rows[$reaped]['status']);
    }

    /**
     * The card renders what the snapshot carries, so the snapshot is where the
     * two halves meet: the reader now needs the credential ids its caller is
     * about to loop over, and a caller that passed the wrong set (or none)
     * would empty the fact without any query failing.
     */
    public function testTheStatusSnapshotCarriesTheProcessedJobForItsCredential(): void
    {
        $credential = $this->insertCredential('snapshot');
        $mission = $this->insertMission('ETA');

        $processed = $this->insertJob($credential, $mission, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, '2026-08-11 09:00:00', 1, '{"mode":"start"}');
        $neverRan = $this->insertJob($credential, $mission, VIRTUSPHERE_DEPLOY_STATUS_CANCELLED, '2026-08-11 18:00:00', 0);

        $snapshot = integration_health_snapshot($this->db);
        $row = null;
        foreach ($snapshot['ansible']['rows'] as $entry) {
            if ((int) $entry['credential']['id'] === $credential) {
                $row = $entry;
            }
        }

        self::assertNotNull($row, 'the seeded Ansible credential must appear on the card');
        self::assertIsArray($row['last_mission_job']);
        self::assertSame($processed, (int) $row['last_mission_job']['id']);
        self::assertSame(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, $row['last_mission_job']['status']);

        ob_start();
        system_status_render_ansible(
            ['ansible' => ['rows' => [$row]]],
            ['id' => 1, 'role' => 'admin']
        );
        $html = (string) ob_get_clean();

        self::assertStringContainsString(__t('system_status.ansible_th_last_mission_job'), $html);
        self::assertStringContainsString(__t('system_status.ansible_job_succeeded'), $html);
        self::assertStringContainsString(__t('system_status.ansible_job_mode', ['mode' => 'start']), $html);
        self::assertStringContainsString('deploy_log.php?id=' . $processed, $html);
        self::assertStringNotContainsString(__t('system_status.ansible_job_cancelled'), $html);
        self::assertStringNotContainsString('deploy_log.php?id=' . $neverRan, $html);
    }

    public function testWithoutCredentialsNothingIsRead(): void
    {
        $credential = $this->insertCredential('unasked');
        $mission = $this->insertMission('ZETA');
        $this->insertJob($credential, $mission, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, '2026-08-11 09:00:00', 1);

        self::assertSame([], repo_latest_completed_ansible_mission_jobs($this->db, []));
        self::assertSame([], repo_latest_completed_ansible_mission_jobs($this->db, [0, -1]));
    }

    private function insertCredential(string $suffix): int
    {
        $type = VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE;
        $name = $this->prefix . '_' . $suffix;
        $host = $suffix . '.phpunit.invalid';
        $username = 'svc';
        $secret = 'phpunit-ciphertext';
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssss', $type, $name, $host, $username, $secret);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $this->credentialIds[] = $id;

        return $id;
    }

    private function insertMission(string $suffix): int
    {
        $name = $this->prefix . '_' . $suffix;
        $status = 'active';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $this->missionIds[] = $id;

        return $id;
    }

    /** @param int $attempts how often a worker claimed the job; 0 means it never ran */
    private function insertJob(
        int $credentialId,
        ?int $missionId,
        string $status,
        string $updatedAt,
        int $attempts,
        ?string $payloadJson = null
    ): int {
        $stmt = $this->db->prepare('INSERT INTO deploy_jobs (mission_id, status, attempts, payload_json, credential_ansible_id, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isisis', $missionId, $status, $attempts, $payloadJson, $credentialId, $updatedAt);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }
}
