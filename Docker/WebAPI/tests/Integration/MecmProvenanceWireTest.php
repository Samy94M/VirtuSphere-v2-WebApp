<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ClientIpAllowlist.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/mecm_provenance.php';

/**
 * MECM membership provenance on the wire (Etappe 8, ADR-0034, decisions 1-3).
 *
 * The device-sync creates direct membership rules and until now NOTHING
 * recorded which of them are VirtuSphere's own. Reconciliation (removing an
 * obsolete own rule on an OS switch) is only safe with that record: a remove
 * without provenance proof could take out a rule an administrator created by
 * hand in MECM. Two additive wire pieces carry it:
 *
 *  - getDeviceList: every device additionally lists `owned_collections`.
 *  - mecm_updateid.php?action=reportMembership: the script reports the rules
 *    it added or removed, and the portal keeps the provenance rows.
 */
final class MecmProvenanceWireTest extends TestCase
{
    use ClientIpAllowlist;

    private const PREFIX = 'phpunit_prov_';

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

        $name = self::PREFIX . 'mission';
        $status = 'active';
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();
        $this->missionId = (int) $this->db->insert_id;

        $vmName = strtoupper(self::PREFIX . 'VM');
        $updated = 1;
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, updated) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('issi', $this->missionId, $vmName, $vmName, $updated);
        $stmt->execute();
        $this->vmId = (int) $this->db->insert_id;

        $mac = '02:50:56:aa:bb:0c';
        $empty = '';
        $vlan = 'WDS';
        $stmt = $this->db->prepare('INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssss', $this->vmId, $empty, $empty, $empty, $vlan, $mac);
        $stmt->execute();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
            $this->restoreClientIpAllowlistIfTouched();
        }
    }

    public function testAMembershipReportForAnUnknownVmIs404(): void
    {
        [$status, , $body] = $this->post('/mecm_updateid.php?action=reportMembership', [
            'deviceid' => 999000222,
            'memberships' => [['collection_id' => 'VS100001', 'collection_name' => 'X', 'type' => 'package', 'change' => 'added']],
        ]);

        self::assertSame(404, $status, $body);
        self::assertSame(['error' => 'Unknown VM id'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testMalformedMembershipReportsKeepTheInvalidDataEnvelope(): void
    {
        $payloads = [
            ['deviceid' => $this->vmId],
            ['deviceid' => $this->vmId, 'memberships' => 'not-a-list'],
            ['deviceid' => $this->vmId, 'memberships' => [['collection_id' => '', 'collection_name' => 'X', 'type' => 'package', 'change' => 'added']]],
            ['deviceid' => $this->vmId, 'memberships' => [['collection_id' => 'VS1', 'collection_name' => 'X', 'type' => 'surprise', 'change' => 'added']]],
            ['deviceid' => $this->vmId, 'memberships' => [['collection_id' => 'VS1', 'collection_name' => 'X', 'type' => 'package', 'change' => 'adopted']]],
        ];
        foreach ($payloads as $payload) {
            [$status, , $body] = $this->post('/mecm_updateid.php?action=reportMembership', $payload);
            self::assertSame(400, $status, $body);
            self::assertSame(['error' => 'Invalid data format'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        }
        self::assertSame([], repo_mecm_rules_for_vm($this->db, $this->vmId), 'a rejected report writes nothing');
    }

    public function testAddedAndRemovedRoundTripThroughProvenanceAndDeviceList(): void
    {
        [$status, , $body] = $this->post('/mecm_updateid.php?action=reportMembership', [
            'deviceid' => $this->vmId,
            'memberships' => [
                ['collection_id' => 'VS100001', 'collection_name' => self::PREFIX . 'os', 'type' => 'os', 'change' => 'added'],
                ['collection_id' => 'VS100002', 'collection_name' => self::PREFIX . 'pkg-1.0', 'type' => 'package', 'change' => 'added'],
            ],
        ]);
        self::assertSame(200, $status, $body);

        $rules = repo_mecm_rules_for_vm($this->db, $this->vmId);
        self::assertCount(2, $rules);
        self::assertSame('created', (string) $rules[0]['origin'], 'a script-reported rule is owned as created, never adopted');

        // The device list carries the owned rules, so the script can hand them
        // to the plan without a second endpoint.
        $device = $this->deviceFromList();
        self::assertNotNull($device, 'the queued VM must be on the device list');
        self::assertArrayHasKey('owned_collections', $device);
        self::assertSame(
            ['VS100001', 'VS100002'],
            array_column($device['owned_collections'], 'collection_id')
        );

        // A removal withdraws the provenance; re-reporting the same removal is
        // idempotent (the second run after a half-failed apply must converge).
        foreach ([0, 1] as $round) {
            [$status] = $this->post('/mecm_updateid.php?action=reportMembership', [
                'deviceid' => $this->vmId,
                'memberships' => [['collection_id' => 'VS100002', 'collection_name' => self::PREFIX . 'pkg-1.0', 'type' => 'package', 'change' => 'removed']],
            ]);
            self::assertSame(200, $status, 'round ' . $round);
        }
        self::assertSame(
            ['VS100001'],
            array_column(repo_mecm_rules_for_vm($this->db, $this->vmId), 'collection_id')
        );
    }

    /** @return array<string, mixed>|null */
    private function deviceFromList(): ?array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true]]);
        $raw = (string) file_get_contents(virtusphere_test_base_url() . '/mecm-api.php?action=getDeviceList', false, $context);
        foreach (json_decode($raw, true, 512, JSON_THROW_ON_ERROR) as $device) {
            if ((int) ($device['id'] ?? 0) === $this->vmId) {
                return $device;
            }
        }

        return null;
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
        foreach ($http_response_header ?? [] as $line) {
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
