<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
// The purge is part of the contract here: it and the relink compose into the one
// path that could lose an assignment for good.
require_once dirname(__DIR__, 2) . '/lib/repo/catalog.php';

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

    /**
     * A package that merely vanished from one payload is a GAP, not an upgrade,
     * so the assignment stays where the operator put it.
     *
     * This is the decision that replaced the old behaviour, and it is the reason
     * the relink is bounded to "the successor is new in this payload". Before, a
     * transient MECM outage (a hiccup, an admin mid-edit) rewrote assignments to
     * whatever else shared the basename, and the row it moved them off then lost
     * its purge protection. Both versions already existing is exactly the case
     * that used to look like an upgrade and is not one.
     */
    public function testATransientDisappearanceLeavesTheAssignmentAlone(): void
    {
        [$status] = $this->post($this->payload([self::PREFIX . '-1.0', self::PREFIX . '-2.0']));
        self::assertSame(200, $status);
        $v1 = $this->packageRow(self::PREFIX . '-1.0');
        $v2 = $this->packageRow(self::PREFIX . '-2.0');
        $vmId = $this->seedVmWithPackages([(int) $v1['id']]);

        // v1 drops out of the payload while v2 is nothing new.
        [$status] = $this->post($this->payload([self::PREFIX . '-2.0']));
        self::assertSame(200, $status);

        self::assertSame([(int) $v1['id']], $this->linkedPackageIds($vmId), 'no newer row appeared, so nothing was an upgrade to follow');
        self::assertSame('Retired', $this->packageRow(self::PREFIX . '-1.0')['package_status'], 'the row is still retired; only the assignment is untouched');
        self::assertNull($this->packageRow(self::PREFIX . '-1.0')['assignments_relinked_at'], 'nothing was relinked, so nothing is marked');
    }

    /**
     * The successor is the higher VERSION, not the higher row id. A catalog whose
     * versions were imported out of order (a re-created package, a backported
     * fix) moved the assignment to the wrong, possibly older package.
     */
    public function testTheSuccessorIsChosenByVersionAndNotByRowId(): void
    {
        // Insert 3.0 FIRST so the newest row id carries the LOWER version.
        [$status] = $this->post($this->payload([self::PREFIX . '-2.0', self::PREFIX . '-3.0']));
        self::assertSame(200, $status);
        $v2 = $this->packageRow(self::PREFIX . '-2.0');
        $v3 = $this->packageRow(self::PREFIX . '-3.0');
        // Force the id order to disagree with the version order.
        $this->db->query('UPDATE deploy_packages SET id = id + 1000 WHERE id = ' . (int) $v2['id']);
        $v2 = $this->packageRow(self::PREFIX . '-2.0');
        self::assertGreaterThan((int) $v3['id'], (int) $v2['id'], 'the fixture needs the lower version on the higher id');

        $vmId = $this->seedVmWithPackages([(int) $v2['id']]);

        // v2 disappears, and 4.0 arrives as the genuinely new row.
        [$status] = $this->post($this->payload([self::PREFIX . '-3.0', self::PREFIX . '-4.0']));
        self::assertSame(200, $status);
        $v4 = $this->packageRow(self::PREFIX . '-4.0');

        self::assertSame([(int) $v4['id']], $this->linkedPackageIds($vmId), 'the assignment must follow the highest version created by this payload');
    }

    /** A VM holding both versions ends up with one link, not a duplicate. */
    public function testAVmHoldingBothVersionsKeepsExactlyTheSuccessorLink(): void
    {
        [$status] = $this->post($this->payload([self::PREFIX . '-1.0']));
        self::assertSame(200, $status);
        $v1 = $this->packageRow(self::PREFIX . '-1.0');

        // Second payload creates 2.0 (new) and drops 1.0: a real version bump.
        // The VM is linked to both beforehand, which is the PK collision case.
        $vmId = $this->seedVmWithPackages([(int) $v1['id']]);
        [$status] = $this->post($this->payload([self::PREFIX . '-1.0', self::PREFIX . '-2.0']));
        self::assertSame(200, $status);
        $v2 = $this->packageRow(self::PREFIX . '-2.0');
        $this->db->query('INSERT INTO deploy_vm_packages (vm_id, package_id) VALUES (' . $vmId . ', ' . (int) $v2['id'] . ')');

        // Now 1.0 goes away together with a newly created 3.0.
        [$status] = $this->post($this->payload([self::PREFIX . '-2.0', self::PREFIX . '-3.0']));
        self::assertSame(200, $status);
        $v3 = $this->packageRow(self::PREFIX . '-3.0');

        $links = $this->linkedPackageIds($vmId);
        self::assertNotContains((int) $v1['id'], $links, 'the relinked row must not keep a second link for the same package');
        self::assertContains((int) $v3['id'], $links);
    }

    /**
     * The composition that could lose a package for good: the relink removes the
     * reference, and the purge protected the row only while that reference
     * existed. ADR-0020 calls the deletion safe BECAUSE linked rows are kept, and
     * that held for every row except the ones that had assignments.
     */
    public function testThePurgeKeepsARowWhoseAssignmentsTheRelinkMovedAway(): void
    {
        [$status] = $this->post($this->payload([self::PREFIX . '-1.0']));
        self::assertSame(200, $status);
        $v1 = $this->packageRow(self::PREFIX . '-1.0');
        $vmId = $this->seedVmWithPackages([(int) $v1['id']]);

        [$status] = $this->post($this->payload([self::PREFIX . '-2.0']));
        self::assertSame(200, $status);
        $v2 = $this->packageRow(self::PREFIX . '-2.0');
        self::assertSame([(int) $v2['id']], $this->linkedPackageIds($vmId), 'a real version bump still relinks');

        $v1After = $this->packageRow(self::PREFIX . '-1.0');
        self::assertNotNull($v1After['assignments_relinked_at'], 'the relink has to record that it removed the reference');

        // Age it well past the purge window and run the real purge.
        $this->db->query('UPDATE deploy_packages SET retired_at = DATE_SUB(NOW(), INTERVAL ' . (VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS + 10) . ' DAY) WHERE id = ' . (int) $v1['id']);
        repo_purge_retired_packages($this->db);

        self::assertNotNull($this->packageRow(self::PREFIX . '-1.0'), 'a row that carried assignments must survive the purge');
    }

    /** The counter-direction: a retired row that never carried one is still purged. */
    public function testThePurgeStillRemovesARowThatNeverCarriedAnAssignment(): void
    {
        [$status] = $this->post($this->payload([self::PREFIX . 'Lonely-1.0', self::PREFIX . '-1.0']));
        self::assertSame(200, $status);
        $lonely = $this->packageRow(self::PREFIX . 'Lonely-1.0');
        self::assertNotNull($lonely);

        [$status] = $this->post($this->payload([self::PREFIX . '-1.0']));
        self::assertSame(200, $status);

        $this->db->query('UPDATE deploy_packages SET retired_at = DATE_SUB(NOW(), INTERVAL ' . (VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS + 10) . ' DAY) WHERE id = ' . (int) $lonely['id']);
        repo_purge_retired_packages($this->db);

        self::assertNull($this->packageRow(self::PREFIX . 'Lonely-1.0'), 'the purge must still do its job for an unassigned row');
    }

    /** @param list<int> $packageIds */
    private function seedVmWithPackages(array $packageIds): int
    {
        $this->db->query("INSERT INTO deploy_missions (mission_name, mission_status) VALUES ('phpunit_e3_mission', 'active')");
        $missionId = $this->db->insert_id;
        $this->db->query("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES ({$missionId}, 'PHPUNIT-E3', 'PHPUNIT-E3')");
        $vmId = (int) $this->db->insert_id;
        foreach ($packageIds as $packageId) {
            $this->db->query('INSERT INTO deploy_vm_packages (vm_id, package_id) VALUES (' . $vmId . ', ' . $packageId . ')');
        }

        return $vmId;
    }

    /** @return list<int> */
    private function linkedPackageIds(int $vmId): array
    {
        $rows = $this->db->query('SELECT package_id FROM deploy_vm_packages WHERE vm_id = ' . $vmId . ' ORDER BY package_id')->fetch_all(MYSQLI_ASSOC);

        return array_map(static fn (array $row): int => (int) $row['package_id'], $rows);
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
