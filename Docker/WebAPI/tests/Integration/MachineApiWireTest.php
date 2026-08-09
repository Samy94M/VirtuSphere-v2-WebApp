<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ClientIpAllowlist.php';

final class MachineApiWireTest extends TestCase
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

    public function testInvalidMacKeepsMachineApiWireEnvelope(): void
    {
        [$status, $headers, $body] = $this->get('/mecm-api.php?action=getDeviceInfos&mac=not-a-mac');

        self::assertSame(400, $status);
        self::assertStringContainsString('application/json', strtolower($headers));
        self::assertSame(['error' => 'Invalid MAC address'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testForbiddenEnvelopeIncludesClientIp(): void
    {
        // Zustand herstellen statt skippen (ADR-0015-Ergaenzung): der Test
        // behauptet die 403-Envelope, also entfernt er die eigene IP aus der
        // Allowlist; tearDown stellt den Ausgangszustand wieder her.
        $this->ensureClientIpNotAllowlisted(db(true));

        [$status, $headers, $body] = $this->get('/mecm-api.php?action=getDeviceList');

        self::assertSame(403, $status);
        self::assertStringContainsString('application/json', strtolower($headers));
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $payload);
        self::assertStringStartsWith('Zugriff verweigert. Ihre IP: ', (string) $payload['error']);
    }

    public function testSeparatorAndCaseVariantsAreNeverRejectedAsInvalid(): void
    {
        // E2: any FILTER_VALIDATE_MAC-valid notation must reach the lookup
        // (403 or data), never the 400 invalid-MAC envelope.
        foreach (['00-50-56-aa-bb-cc', '00:50:56:aa:bb:cc', '0050.56aa.bbcc'] as $mac) {
            [$status] = $this->get('/mecm-api.php?action=getDeviceInfos&mac=' . urlencode($mac));
            self::assertNotSame(400, $status, $mac);
        }
    }

    /** ADR-0019/E3: the mission already rides on getDeviceList. */
    public function testRetiredMissionNameActionUsesTheUnknownActionEnvelope(): void
    {
        $this->ensureClientIpAllowlisted(db(true));

        [$status, $headers, $body] = $this->get('/mecm-api.php?action=getMissionName&mission_id=1');

        self::assertSame(400, $status);
        self::assertStringContainsString('application/json', strtolower($headers));
        self::assertSame(['message' => 'Invalid action specified'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * ADR-0019/E3: getDeviceInfos is a read-only bootstrap read with an exact,
     * deliberately small payload. Lifecycle progress belongs to the explicit
     * client-ready acknowledgement below, never to a GET.
     */
    public function testDeviceInfosIsMinimalAndDoesNotAdvanceLifecycle(): void
    {
        $db = db(true);
        $fixture = $this->createClientFixture($db, 'device-infos');

        try {
            [$status, $headers, $body] = $this->get('/mecm-api.php?action=getDeviceInfos&mac=' . urlencode($fixture['mac']));

            self::assertSame(200, $status, $body);
            self::assertStringContainsString('application/json', strtolower($headers));
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertSame(
                ['interfaces', 'mission_id', 'vm_domain', 'vm_hostname', 'vm_name', 'vm_os'],
                $this->sortedKeys($payload)
            );
            self::assertSame([
                'dns1', 'dns2', 'gateway', 'ip', 'mac', 'mode', 'subnet', 'type', 'vlan',
            ], $this->sortedKeys($payload['interfaces'][0]));
            self::assertSame($fixture['mac'], $payload['interfaces'][0]['mac']);

            $state = $this->vmState($db, $fixture['vm_id']);
            self::assertSame(VIRTUSPHERE_LIFECYCLE_DEPLOYED, $state['lifecycle_state']);
            self::assertSame(VIRTUSPHERE_MECM_SYNC_PENDING, $state['mecm_sync_state']);
            self::assertSame(0, $this->statusEventCount($db, $fixture['vm_id']));
        } finally {
            $this->deleteClientFixture($db, $fixture['mission_id']);
        }
    }

    /**
     * The new POST is the sole 5/5 writer. Retrying after an uncertain network
     * result is safe and must not produce another status-history event.
     */
    public function testClientReadyAcknowledgementIsPostOnlyAndIdempotent(): void
    {
        $db = db(true);
        $fixture = $this->createClientFixture($db, 'client-ready');

        try {
            [$status, , $body] = $this->get('/mecm_client_ack.php');
            self::assertSame(405, $status, $body);
            self::assertSame(['error' => 'Method not allowed'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));

            [$status, $headers, $body] = $this->post('/mecm_client_ack.php', ['mac' => $fixture['mac']]);
            self::assertSame(200, $status, $body);
            self::assertStringContainsString('application/json', strtolower($headers));
            self::assertSame(
                ['success' => true, 'vm_id' => $fixture['vm_id']],
                json_decode($body, true, 512, JSON_THROW_ON_ERROR)
            );

            $state = $this->vmState($db, $fixture['vm_id']);
            self::assertSame(VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, $state['lifecycle_state']);
            self::assertSame(VIRTUSPHERE_MECM_SYNC_REGISTERED, $state['mecm_sync_state']);
            self::assertSame(1, $this->statusEventCount($db, $fixture['vm_id']));

            [$status, , $body] = $this->post('/mecm_client_ack.php', ['mac' => $fixture['mac']]);
            self::assertSame(200, $status, $body);
            self::assertSame(
                ['success' => true, 'vm_id' => $fixture['vm_id'], 'deduplicated' => true],
                json_decode($body, true, 512, JSON_THROW_ON_ERROR)
            );
            self::assertSame(1, $this->statusEventCount($db, $fixture['vm_id']));
        } finally {
            $this->deleteClientFixture($db, $fixture['mission_id']);
        }
    }

    public function testWrongMethodIs405ButUnknownActionIs400(): void
    {
        // db_importMAC.php and mecm_updateid.php must keep the two cases
        // apart like mecm-api.php and mecm_report.php do: a wrong HTTP method
        // answers 405, an unknown action answers the 400 invalid-action
        // envelope. Both gates sit behind the IP allowlist, also traegt sich
        // der Test selbst ein statt zu skippen (ADR-0015-Ergaenzung).
        $this->ensureClientIpAllowlisted(db(true));

        foreach (['/db_importMAC.php?action=updateInterface', '/mecm_updateid.php?action=updateDevice'] as $path) {
            [$status, , $body] = $this->get($path);
            self::assertSame(405, $status, $path);
            self::assertSame(['error' => 'Method not allowed'], json_decode($body, true, 512, JSON_THROW_ON_ERROR), $path);
        }

        foreach (['/db_importMAC.php?action=nope', '/mecm_updateid.php?action=nope'] as $path) {
            [$status, , $body] = $this->post($path, ['probe' => true]);
            self::assertSame(400, $status, $path);
            self::assertSame(['message' => 'Invalid action specified'], json_decode($body, true, 512, JSON_THROW_ON_ERROR), $path);
        }
    }

    /**
     * Negative contract of the E3 retirement (ADR-0035): the legacy desktop
     * token API is gone as a path, not merely disabled. 404 is the claim; any
     * 2xx/4xx envelope here would mean a resurrected endpoint. The check runs
     * allowlisted on purpose, so a 403 cannot mask a still-deployed file.
     */
    public function testRetiredLegacyTokenApiPathsAnswer404(): void
    {
        $this->ensureClientIpAllowlisted(db(true));

        foreach (['/access.php?action=getMissions', '/api/login.php'] as $path) {
            [$status] = $this->get($path);
            self::assertSame(404, $status, $path . ' must not exist anymore (ADR-0035)');
        }
    }

    /**
     * A ResourceID reported for a VM id that does not exist is a failure, and the
     * wire now says so.
     *
     * It answered 200 "Data updated successfully" for it. The device-sync reads
     * that as done and never reports the device again, for a row somebody deleted
     * in the portal: the machine sat in MECM with nothing in VirtuSphere pointing
     * at it, and no log line anywhere named the mismatch. This is a deliberate
     * wire-behaviour change, and the PowerShell side already treats a non-2xx as
     * a failure it counts and reports (resource_update_failed).
     */
    public function testAResourceIdForAnUnknownVmIsRejectedInsteadOfConfirmed(): void
    {
        $this->ensureClientIpAllowlisted(db(true));

        [$status, $headers, $body] = $this->post('/mecm_updateid.php?action=updateDevice', [
            'deviceName' => 'phpunit-does-not-exist',
            'deviceResourceID' => '16777999',
            'deviceid' => 999000111,
        ]);

        self::assertSame(404, $status, $body);
        self::assertStringContainsString('application/json', strtolower($headers));
        self::assertSame(['error' => 'Unknown VM id'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    /** The two input rejections keep their frozen 400 envelope. */
    public function testMissingResourceIdOrVmIdKeepsTheInvalidDataEnvelope(): void
    {
        $this->ensureClientIpAllowlisted(db(true));

        foreach ([['deviceResourceID' => '', 'deviceid' => 1], ['deviceResourceID' => '4711', 'deviceid' => 0]] as $payload) {
            [$status, , $body] = $this->post('/mecm_updateid.php?action=updateDevice', $payload);
            self::assertSame(400, $status, $body);
            self::assertSame(['error' => 'Invalid data format'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
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

        return $this->request($path, $context);
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function get(string $path): array
    {
        $context = stream_context_create([
            'http' => [
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);

        return $this->request($path, $context);
    }

    /**
     * @param resource $context
     * @return array{0:int,1:string,2:string}
     */
    private function request(string $path, $context): array
    {
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

    /** @return array{mission_id:int,vm_id:int,mac:string} */
    private function createClientFixture(mysqli $db, string $label): array
    {
        $suffix = bin2hex(random_bytes(5));
        $missionName = 'phpunit-e3-' . $label . '-' . $suffix;
        $stmt = $db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $active = 'active';
        $stmt->bind_param('ss', $missionName, $active);
        $stmt->execute();
        $missionId = (int) $db->insert_id;

        $vmName = 'E3-' . strtoupper(substr($suffix, 0, 8));
        $hostname = strtolower($vmName);
        $domain = 'example.test';
        $os = 'Windows 11';
        $lifecycle = VIRTUSPHERE_LIFECYCLE_DEPLOYED;
        $mecm = VIRTUSPHERE_MECM_SYNC_PENDING;
        $status = VIRTUSPHERE_STATUS_DEPLOYED;
        $stmt = $db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_domain, vm_os, lifecycle_state, mecm_sync_state, vm_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssssss', $missionId, $vmName, $hostname, $domain, $os, $lifecycle, $mecm, $status);
        $stmt->execute();
        $vmId = (int) $db->insert_id;

        $mac = '02:' . strtoupper(implode(':', str_split(substr($suffix, 0, 10), 2)));
        $ip = '192.0.2.10';
        $subnet = '255.255.255.0';
        $gateway = '192.0.2.1';
        $dns1 = '192.0.2.53';
        $dns2 = '';
        $vlan = 'E3';
        $mode = 'static';
        $type = 'vmxnet3';
        $stmt = $db->prepare('INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, dns1, dns2, vlan, mac, mode, type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssssssss', $vmId, $ip, $subnet, $gateway, $dns1, $dns2, $vlan, $mac, $mode, $type);
        $stmt->execute();

        return ['mission_id' => $missionId, 'vm_id' => $vmId, 'mac' => $mac];
    }

    /** @return array{lifecycle_state:string,mecm_sync_state:string} */
    private function vmState(mysqli $db, int $vmId): array
    {
        $stmt = $db->prepare('SELECT lifecycle_state, mecm_sync_state FROM deploy_vms WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $vmId);
        $stmt->execute();
        $state = $stmt->get_result()->fetch_assoc();
        self::assertIsArray($state);

        return $state;
    }

    private function statusEventCount(mysqli $db, int $vmId): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) AS amount FROM deploy_vm_status_events WHERE vm_id = ?');
        $stmt->bind_param('i', $vmId);
        $stmt->execute();

        return (int) $stmt->get_result()->fetch_assoc()['amount'];
    }

    private function deleteClientFixture(mysqli $db, int $missionId): void
    {
        $stmt = $db->prepare('DELETE FROM deploy_missions WHERE id = ?');
        $stmt->bind_param('i', $missionId);
        $stmt->execute();
    }

    /** @return list<string> */
    private function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);

        return $keys;
    }
}
