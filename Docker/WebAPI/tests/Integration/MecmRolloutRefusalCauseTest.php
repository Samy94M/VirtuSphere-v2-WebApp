<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ClientIpAllowlist.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/mecm_rollout_fence.php';

/**
 * MA-07 on the wire: a refused callback answers the unchanged 409 whether the
 * revision or the binding refused it, and the audit row says which one it was.
 * The pure table is pinned in MecmRolloutContractTest; this test proves the row
 * reaches the audit log through the endpoint.
 */
final class MecmRolloutRefusalCauseTest extends TestCase
{
    use ClientIpAllowlist;

    private const PREFIX = 'phpunit_cause_';
    private const EVENT = 'mecm.rollout_revision_refused';

    private mysqli $db;
    private int $missionId = 0;
    private int $vmId = 0;

    protected function setUp(): void
    {
        if (@file_get_contents(virtusphere_test_base_url() . '/portal/health.php') === false) {
            self::markTestSkipped('VirtuSphere test stack is not reachable.');
        }
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
        $this->ensureClientIpAllowlisted($this->db);

        // The throttle is per (category, event, scope); an earlier refusal of this
        // test client would silence the row this test is looking for.
        $tag = self::EVENT;
        $stmt = $this->db->prepare('DELETE FROM deploy_audit_throttle WHERE tag = ?');
        $stmt->bind_param('s', $tag);
        $stmt->execute();

        $name = self::PREFIX . 'mission';
        $status = 'active';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();
        $this->missionId = (int) $this->db->insert_id;

        $vmName = strtoupper(self::PREFIX . 'VM');
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $this->missionId, $vmName, $vmName);
        $stmt->execute();
        $this->vmId = (int) $this->db->insert_id;
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
            $this->restoreClientIpAllowlistIfTouched();
        }
    }

    public function testABindingConflictNamesItsCauseAndKeepsTheSameWireAnswer(): void
    {
        $this->setVm(2, 'RID-EXISTING');

        [$status, , $body] = $this->post('/mecm_updateid.php?action=updateDevice', [
            'deviceid' => $this->vmId,
            'deviceResourceID' => 'RID-OTHER',
            'rollout_revision' => 2,
        ]);

        self::assertSame(409, $status, $body);
        self::assertSame(['error' => 'Rollout revision is stale', 'rollout_revision' => 2], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('binding_conflict', $this->auditReason(), 'the revision is current, so the row must not blame the revision');
    }

    public function testARevisionMismatchNamesItsCauseAndNotTheBindingOne(): void
    {
        $this->setVm(3, null);

        [$status, , $body] = $this->post('/mecm_updateid.php?action=updateDevice', [
            'deviceid' => $this->vmId,
            'deviceResourceID' => 'RID-NEW',
            'rollout_revision' => 2,
        ]);

        self::assertSame(409, $status, $body);
        self::assertSame(['error' => 'Rollout revision is stale', 'rollout_revision' => 3], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('revision_mismatch', $this->auditReason());
    }

    private function setVm(int $revision, ?string $mecmId): void
    {
        $stmt = $this->db->prepare('UPDATE deploy_vms SET mecm_rollout_revision = ?, mecm_id = ? WHERE id = ?');
        $stmt->bind_param('isi', $revision, $mecmId, $this->vmId);
        $stmt->execute();
    }

    /** The reason of the newest refusal row for this VM, or null when none was written. */
    private function auditReason(): ?string
    {
        $event = self::EVENT;
        $type = 'vm';
        $object = (string) $this->vmId;
        $stmt = $this->db->prepare('SELECT context_json FROM deploy_logs WHERE event_code = ? AND object_type = ? AND object_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('sss', $event, $type, $object);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        self::assertIsArray($row, 'the refusal must leave an audit row for this VM');
        $context = json_decode((string) $row['context_json'], true, 512, JSON_THROW_ON_ERROR);

        return isset($context['reason']) ? (string) $context['reason'] : null;
    }

    /** @return array{0:int,1:string,2:string} */
    private function post(string $path, array $payload): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
        ]]);
        $body = (string) file_get_contents(virtusphere_test_base_url() . $path, false, $context);
        $status = 0;
        $headers = '';
        foreach (http_get_last_response_headers() ?? [] as $line) {
            $headers .= $line . "\n";
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return [$status, $headers, $body];
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }
}
