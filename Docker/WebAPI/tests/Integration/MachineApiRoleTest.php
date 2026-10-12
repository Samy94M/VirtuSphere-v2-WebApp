<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ClientIpAllowlist.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/api_access.php';

/**
 * K20 (VT-01): an allowlist entry admits only the endpoints of its role. The
 * Ansible host's IP may call the MAC callback but not updateDevice; the refusal
 * keeps the frozen 403 body and records a closed cause. An entry without a role
 * keeps opening every endpoint and is counted for System status.
 */
final class MachineApiRoleTest extends TestCase
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
        $this->ensureClientIpNotAllowlisted($this->db);
        $this->clearTrace();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            repo_execute($this->db, "DELETE FROM deploy_accessToWebAPI WHERE description = 'phpunit k20 role'");
            $this->clearTrace();
        }
        $this->restoreClientIpAllowlistIfTouched();
    }

    public function testAnAnsibleHostIpIsRefusedAtUpdateDeviceWithAClosedCause(): void
    {
        $this->allowlistOwnIp('ansible_host');

        [$status, $body] = $this->request('POST', '/mecm_updateid.php?action=updateDevice');

        self::assertSame(403, $status, $body);
        self::assertStringStartsWith('Zugriff verweigert. Ihre IP: ', (string) json_decode($body, true, 512, JSON_THROW_ON_ERROR)['error'], 'the wire body stays the frozen legacy sentence');
        self::assertSame('ip_role_not_permitted', $this->lastDenialReason(), 'the audit row names the role, not a missing entry');
        self::assertSame(403, $this->request('GET', '/mecm-api.php?action=getDeviceList')[0], 'the device list is the MECM server\'s');
        self::assertSame(403, $this->request('POST', '/mecm_packages.php')[0], 'so is the package catalog');
    }

    public function testTheSameIpPassesTheGateOfTheMacCallback(): void
    {
        $this->allowlistOwnIp('ansible_host');

        // The Ansible preflight's own probe: an IP that passes the gate reaches
        // the method check (405); a refused one gets the 403.
        [$status, $body] = $this->request('GET', '/db_importMAC.php');
        self::assertNotSame(403, $status, $body);
        self::assertNotSame(403, $this->request('GET', '/deploy_vm_touch.php')[0], 'the K15 touch admission is the Ansible host\'s too');
    }

    public function testAClientIpReachesClientEndpointsOnly(): void
    {
        $this->allowlistOwnIp('client');

        [$status, $body] = $this->request('GET', '/mecm-api.php?action=getDeviceInfos&mac=02:00:5e:20:20:20');
        self::assertSame(200, $status, $body);
        self::assertSame(403, $this->request('GET', '/db_importMAC.php')[0], 'a client IP is no Ansible host');
        self::assertSame('ip_role_not_permitted', $this->lastDenialReason());
    }

    public function testAnEntryWithoutARoleOpensEveryEndpointAsBeforeAndIsCounted(): void
    {
        $before = repo_api_access_without_role_count($this->db);
        $this->allowlistOwnIp(null);

        self::assertNotSame(403, $this->request('POST', '/mecm_updateid.php?action=updateDevice')[0], 'a legacy entry keeps updateDevice open');
        self::assertNotSame(403, $this->request('GET', '/db_importMAC.php')[0], 'and the MAC callback');
        self::assertSame($before + 1, repo_api_access_without_role_count($this->db), 'System status counts the entry without a role');
    }

    public function testAnUnknownIpKeepsItsOwnCause(): void
    {
        self::assertSame(403, $this->request('POST', '/mecm_updateid.php?action=updateDevice')[0]);
        self::assertSame('ip_not_allowlisted', $this->lastDenialReason());
    }

    private function allowlistOwnIp(?string $role): void
    {
        $ip = $this->ownClientIp();
        repo_execute($this->db, "INSERT INTO deploy_accessToWebAPI (ipAddress, description, role) VALUES (?, 'phpunit k20 role', ?)", 'ss', [$ip, $role]);
    }

    private function lastDenialReason(): ?string
    {
        $category = VIRTUSPHERE_LOG_CATEGORY_MACHINE_API;
        $row = repo_fetch_one($this->db, 'SELECT context_json FROM deploy_logs WHERE category = ? ORDER BY id DESC LIMIT 1', 's', [$category]);
        self::assertNotNull($row, 'a refusal must leave an audit row');
        $context = json_decode((string) $row['context_json'], true);

        return is_array($context) && isset($context['reason']) ? (string) $context['reason'] : null;
    }

    /** @return array{0:int,1:string} */
    private function request(string $method, string $path): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => "Content-Type: application/json\r\n",
                'content' => $method === 'POST' ? '{}' : '',
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
