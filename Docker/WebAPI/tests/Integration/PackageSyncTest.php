<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';

// E3 hardening contract: retire instead of delete, assignment relink on
// version bumps, threshold brake. Runs in-stack (db() + HTTP against
// webserver) and allowlists its own container IP for the duration.
final class PackageSyncTest extends TestCase
{
    private const PREFIX = 'PhpunitE3App';

    private ?mysqli $db = null;

    private bool $allowlisted = false;

    private string $ownIp = '';

    /** @var list<string> existing active package names to keep untouched */
    private array $preexisting = [];

    protected function setUp(): void
    {
        $health = @file_get_contents(virtusphere_test_base_url() . '/portal/health.php');
        if ($health === false) {
            self::markTestSkipped('VirtuSphere test stack is not reachable.');
        }

        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }

        // Discover our outbound IP via the 403 echo, then self-allowlist.
        [$status, $body] = $this->post([['type' => 'Package', 'name' => self::PREFIX . '-Probe-1.0']]);
        if ($status === 403) {
            $payload = json_decode($body, true);
            if (preg_match('/Ihre IP: (\S+)$/', (string) ($payload['error'] ?? ''), $match) !== 1) {
                self::markTestSkipped('Could not discover own client IP.');
            }
            $this->ownIp = $match[1];
            $stmt = $this->db->prepare("INSERT INTO deploy_accessToWebAPI (ipAddress, description) VALUES (?, 'phpunit e3 temp')");
            $stmt->bind_param('s', $this->ownIp);
            $stmt->execute();
            $this->allowlisted = true;
        }

        // Never retire real catalog entries: remember active names and always
        // include them in every payload this test sends.
        $result = $this->db->query("SELECT package_name FROM deploy_packages WHERE package_status <> 'Retired'");
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            if (!str_starts_with((string) $row['package_name'], self::PREFIX)) {
                $this->preexisting[] = (string) $row['package_name'];
            }
        }

        $this->cleanupTestRows();
    }

    protected function tearDown(): void
    {
        if ($this->db === null) {
            return;
        }
        $this->cleanupTestRows();
        if ($this->allowlisted) {
            $stmt = $this->db->prepare("DELETE FROM deploy_accessToWebAPI WHERE ipAddress = ? AND description = 'phpunit e3 temp'");
            $stmt->bind_param('s', $this->ownIp);
            $stmt->execute();
        }
    }

    public function testMissingPackageIsRetiredNotDeletedAndAssignmentsRelink(): void
    {
        // Seed catalog with two versions' history: first sync brings v1.
        [$status, $body] = $this->post($this->payload([self::PREFIX . '-1.0', self::PREFIX . 'Other-2.0']));
        self::assertSame(200, $status, $body);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Daten erfolgreich empfangen', $response['success']);

        $v1 = $this->packageRow(self::PREFIX . '-1.0');
        self::assertNotNull($v1);
        self::assertSame(self::PREFIX, $v1['package_basename']);
        self::assertSame('1.0', $v1['package_version']);

        // Link a test VM to v1.
        $this->db->query("INSERT INTO deploy_missions (mission_name, mission_status) VALUES ('phpunit_e3_mission', 'active')");
        $missionId = $this->db->insert_id;
        $this->db->query("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES ({$missionId}, 'PHPUNIT-E3', 'PHPUNIT-E3')");
        $vmId = $this->db->insert_id;
        $this->db->query('INSERT INTO deploy_vm_packages (vm_id, package_id) VALUES (' . $vmId . ', ' . (int) $v1['id'] . ')');

        // Second sync: v1 gone, v2 present -> retire + relink.
        [$status, $body] = $this->post($this->payload([self::PREFIX . '-2.0', self::PREFIX . 'Other-2.0']));
        self::assertSame(200, $status, $body);

        $v1After = $this->packageRow(self::PREFIX . '-1.0');
        self::assertNotNull($v1After, 'retired row must still exist');
        self::assertSame('Retired', $v1After['package_status']);
        self::assertNotNull($v1After['retired_at']);

        $v2 = $this->packageRow(self::PREFIX . '-2.0');
        self::assertNotNull($v2);
        $link = $this->db->query('SELECT package_id FROM deploy_vm_packages WHERE vm_id = ' . $vmId)->fetch_all(MYSQLI_ASSOC);
        self::assertSame([(int) $v2['id']], array_map(static fn (array $r): int => (int) $r['package_id'], $link), 'assignment must move to the successor');

        // Third sync: v1 re-appears -> un-retire.
        [$status] = $this->post($this->payload([self::PREFIX . '-1.0', self::PREFIX . '-2.0', self::PREFIX . 'Other-2.0']));
        self::assertSame(200, $status);
        $v1Again = $this->packageRow(self::PREFIX . '-1.0');
        self::assertSame('Aktiv', $v1Again['package_status']);
        self::assertNull($v1Again['retired_at']);
    }

    public function testRelinkKeepsExactlyOneLinkWhenVmHadBothVersions(): void
    {
        [$status] = $this->post($this->payload([self::PREFIX . '-1.0', self::PREFIX . '-2.0']));
        self::assertSame(200, $status);
        $v1 = $this->packageRow(self::PREFIX . '-1.0');
        $v2 = $this->packageRow(self::PREFIX . '-2.0');

        $this->db->query("INSERT INTO deploy_missions (mission_name, mission_status) VALUES ('phpunit_e3_mission', 'active')");
        $missionId = $this->db->insert_id;
        $this->db->query("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES ({$missionId}, 'PHPUNIT-E3', 'PHPUNIT-E3')");
        $vmId = $this->db->insert_id;
        $this->db->query('INSERT INTO deploy_vm_packages (vm_id, package_id) VALUES (' . $vmId . ', ' . (int) $v1['id'] . '), (' . $vmId . ', ' . (int) $v2['id'] . ')');

        [$status] = $this->post($this->payload([self::PREFIX . '-2.0']));
        self::assertSame(200, $status);

        $links = $this->db->query('SELECT package_id FROM deploy_vm_packages WHERE vm_id = ' . $vmId)->fetch_all(MYSQLI_ASSOC);
        self::assertSame([(int) $v2['id']], array_map(static fn (array $r): int => (int) $r['package_id'], $links), 'PK collision case must leave exactly the new link');
    }

    public function testMassRetireIsRejectedWith409(): void
    {
        // Seed enough packages to clear the min-active floor.
        $names = [];
        for ($i = 1; $i <= 8; $i++) {
            $names[] = self::PREFIX . 'Bulk' . $i . '-1.0';
        }
        [$status] = $this->post($this->payload($names));
        self::assertSame(200, $status);

        // Payload keeping only one test package would retire far above 30%.
        [$status, $body] = $this->post($this->payload([self::PREFIX . 'Bulk1-1.0']));
        self::assertSame(409, $status, $body);
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $payload);

        // Nothing was retired by the rejected sync.
        $active = (int) $this->db->query("SELECT COUNT(*) FROM deploy_packages WHERE package_name LIKE '" . self::PREFIX . "Bulk%' AND package_status <> 'Retired'")->fetch_row()[0];
        self::assertSame(8, $active);
    }

    /**
     * Payload always includes all pre-existing active names so this test can
     * never retire real catalog data.
     */
    private function payload(array $testNames): array
    {
        $entries = [];
        foreach (array_merge($this->preexisting, $testNames) as $name) {
            $entries[] = ['type' => 'Package', 'name' => $name];
        }
        // Keep the OS side untouched on purpose (no TaskSequence entries).

        return $entries;
    }

    private function packageRow(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM deploy_packages WHERE package_name = ? LIMIT 1');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return is_array($row) ? $row : null;
    }

    private function cleanupTestRows(): void
    {
        $this->db->query("DELETE FROM deploy_packages WHERE package_name LIKE '" . self::PREFIX . "%'");
        $this->db->query("DELETE FROM deploy_missions WHERE mission_name = 'phpunit_e3_mission'");
    }

    /**
     * @return array{0:int,1:string}
     */
    private function post(array $payload): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);
        $body = @file_get_contents(virtusphere_test_base_url() . '/mecm_packages.php', false, $context);
        if ($body === false) {
            self::markTestSkipped('VirtuSphere test endpoint is not reachable.');
        }

        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return [$status, $body];
    }
}
