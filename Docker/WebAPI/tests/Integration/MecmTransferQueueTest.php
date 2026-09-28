<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ClientIpAllowlist.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms_operations.php';

/**
 * AV-P0 (autoimporter version plan, AV-F01/AV-F02): an operator transfer of a
 * registered VM must leave the device-sync queue once the sync reported back,
 * and a transfer queued while a sync run is under way must not be lost.
 *
 * The first half was broken: the sync re-reports the ResourceID the VM already
 * has, the revision fence answers `noop`, nothing is written, and `updated`
 * stayed 1, so the VM was delivered by getDeviceList every run forever. The
 * second half needs a counter: `updated` alone cannot tell "the transfer the
 * sync just applied" from "a newer one queued meanwhile".
 */
final class MecmTransferQueueTest extends TestCase
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

    public function testATransferredRegisteredVmLeavesTheQueueWhenTheSyncReportsItsResourceIdAgain(): void
    {
        $db = db(true);
        $this->ensureClientIpAllowlisted($db);
        $fixture = $this->createRegisteredVm($db);
        try {
            repo_mark_vm_for_mecm_resync($db, $fixture['mission_id'], $fixture['vm_id']);
            self::assertSame(1, $this->updatedFlag($db, $fixture['vm_id']));
            self::assertNotNull($this->deviceListRow($fixture['vm_id']), 'the transfer queues the VM');

            // Today's device-sync, which sends no transfer generation.
            [$status, , $body] = $this->post([
                'deviceName' => $fixture['vm_name'],
                'deviceResourceID' => $fixture['mecm_id'],
                'deviceid' => $fixture['vm_id'],
                'rollout_revision' => 1,
            ]);
            self::assertSame(200, $status, $body);
            self::assertSame(0, $this->updatedFlag($db, $fixture['vm_id']), 'the applied transfer must leave the queue');
            self::assertNull($this->deviceListRow($fixture['vm_id']));
        } finally {
            $this->deleteMission($db, $fixture['mission_id']);
        }
    }

    public function testATransferQueuedDuringASyncRunSurvivesTheOlderCallback(): void
    {
        $db = db(true);
        $this->ensureClientIpAllowlisted($db);
        $fixture = $this->createRegisteredVm($db);
        try {
            repo_mark_vm_for_mecm_resync($db, $fixture['mission_id'], $fixture['vm_id']);
            $row = $this->deviceListRow($fixture['vm_id']);
            self::assertIsArray($row);
            self::assertArrayHasKey('transfer_generation', $row, 'getDeviceList exports the transfer generation');
            $readBySync = (int) $row['transfer_generation'];

            // The operator saves and transfers again while that run is under way.
            repo_mark_vm_for_mecm_resync($db, $fixture['mission_id'], $fixture['vm_id']);

            [$status, , $body] = $this->post([
                'deviceName' => $fixture['vm_name'],
                'deviceResourceID' => $fixture['mecm_id'],
                'deviceid' => $fixture['vm_id'],
                'rollout_revision' => 1,
                'transfer_generation' => $readBySync,
            ]);
            self::assertSame(200, $status, $body);
            self::assertSame(1, $this->updatedFlag($db, $fixture['vm_id']), 'the newer transfer must stay queued');

            $current = (int) $this->deviceListRow($fixture['vm_id'])['transfer_generation'];
            self::assertSame($readBySync + 1, $current);
            [$status, , $body] = $this->post([
                'deviceName' => $fixture['vm_name'],
                'deviceResourceID' => $fixture['mecm_id'],
                'deviceid' => $fixture['vm_id'],
                'rollout_revision' => 1,
                'transfer_generation' => $current,
            ]);
            self::assertSame(200, $status, $body);
            self::assertSame(0, $this->updatedFlag($db, $fixture['vm_id']));
        } finally {
            $this->deleteMission($db, $fixture['mission_id']);
        }
    }

    public function testAMalformedTransferGenerationKeepsTheInvalidDataEnvelope(): void
    {
        $db = db(true);
        $this->ensureClientIpAllowlisted($db);
        foreach (['x', -1, 1.5, [1]] as $generation) {
            [$status, , $body] = $this->post(['deviceResourceID' => '4711', 'deviceid' => 1, 'transfer_generation' => $generation]);
            self::assertSame(400, $status, $body);
            self::assertSame(['error' => 'Invalid data format'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    /** @return array{mission_id:int,vm_id:int,vm_name:string,mecm_id:string} */
    private function createRegisteredVm(mysqli $db): array
    {
        $suffix = bin2hex(random_bytes(5));
        $missionName = 'phpunit-av-p0-' . $suffix;
        $active = 'active';
        $stmt = $db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $missionName, $active);
        $stmt->execute();
        $missionId = (int) $db->insert_id;

        $vmName = 'AVP0-' . strtoupper(substr($suffix, 0, 8));
        $rolloutHostname = 'R' . strtoupper(substr($suffix, 0, 8));
        $revision = VIRTUSPHERE_MECM_ROLLOUT_REVISION_INITIAL;
        $mecmId = (string) (16700000 + random_int(1, 99999));
        $lifecycle = VIRTUSPHERE_LIFECYCLE_OS_INSTALLED;
        $sync = VIRTUSPHERE_MECM_SYNC_REGISTERED;
        $status = VIRTUSPHERE_STATUS_OS_INSTALLED;
        $domain = 'example.test';
        $os = 'Windows 11';
        $stmt = $db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, mecm_rollout_hostname, mecm_rollout_revision, mecm_id, vm_domain, vm_os, lifecycle_state, mecm_sync_state, vm_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $hostname = strtolower($vmName);
        $stmt->bind_param('isssissssss', $missionId, $vmName, $hostname, $rolloutHostname, $revision, $mecmId, $domain, $os, $lifecycle, $sync, $status);
        $stmt->execute();
        $vmId = (int) $db->insert_id;

        $mac = '02:' . strtoupper(implode(':', str_split(substr($suffix, 0, 10), 2)));
        $ip = '192.0.2.20';
        $subnet = '255.255.255.0';
        $gateway = '192.0.2.1';
        $dns = '192.0.2.53';
        $empty = '';
        $vlan = 'AVP0';
        $mode = 'static';
        $type = 'vmxnet3';
        $stmt = $db->prepare('INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, dns1, dns2, vlan, mac, mode, type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssssssss', $vmId, $ip, $subnet, $gateway, $dns, $empty, $vlan, $mac, $mode, $type);
        $stmt->execute();

        return ['mission_id' => $missionId, 'vm_id' => $vmId, 'vm_name' => $vmName, 'mecm_id' => $mecmId];
    }

    private function updatedFlag(mysqli $db, int $vmId): int
    {
        $stmt = $db->prepare('SELECT updated FROM deploy_vms WHERE id = ?');
        $stmt->bind_param('i', $vmId);
        $stmt->execute();

        return (int) $stmt->get_result()->fetch_assoc()['updated'];
    }

    /** @return array<string,mixed>|null */
    private function deviceListRow(int $vmId): ?array
    {
        $body = @file_get_contents(virtusphere_test_base_url() . '/mecm-api.php?action=getDeviceList', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]));
        self::assertIsString($body);
        foreach (json_decode($body, true, 512, JSON_THROW_ON_ERROR) as $row) {
            if ((int) $row['id'] === $vmId) {
                return $row;
            }
        }

        return null;
    }

    /** @return array{0:int,1:string,2:string} */
    private function post(array $payload): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 5,
        ]]);
        $body = @file_get_contents(virtusphere_test_base_url() . '/mecm_updateid.php?action=updateDevice', false, $context);
        self::assertIsString($body);
        $status = 0;
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return [$status, '', $body];
    }

    private function deleteMission(mysqli $db, int $missionId): void
    {
        $stmt = $db->prepare('DELETE FROM deploy_missions WHERE id = ?');
        $stmt->bind_param('i', $missionId);
        $stmt->execute();
    }
}
