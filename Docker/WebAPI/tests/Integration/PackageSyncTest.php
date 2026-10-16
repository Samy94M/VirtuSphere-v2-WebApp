<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
// The purge is part of the contract here: it and a relink compose into the one
// path that could lose an assignment for good.
require_once dirname(__DIR__, 2) . '/lib/repo/catalog.php';
require_once dirname(__DIR__, 2) . '/lib/repo/package_sync.php';
require_once dirname(__DIR__, 2) . '/lib/audit_event_definitions.php';

// E3 hardening contract: retire instead of delete, threshold brake, and since
// AV-R08 (15.10.2026) never a relink of a real VM: only template VMs follow a
// version that appears for the first time. Runs in-stack (db() + HTTP against
// webserver) and allowlists its own container IP for the duration.
final class PackageSyncTest extends TestCase
{
    private const PREFIX = 'PhpunitE3App';

    private const REAL_MISSION = 'phpunit_e3_mission';

    private int $vmSeq = 0;

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
    /**
     * AV-R08: a real version bump retires the old row but never moves a real
     * VM. The next MECM transfer would otherwise run the new install.ps1 on an
     * installed system and overwrite what the administrator configured.
     */
    public function testARealVersionBumpRetiresTheOldRowButNeverMovesARealVm(): void
    {
        [$status, $body] = $this->post($this->payload([self::PREFIX . '-1.0', self::PREFIX . 'Other-2.0']));
        self::assertSame(200, $status, $body);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Daten erfolgreich empfangen', $response['success']);

        $v1 = $this->packageRow(self::PREFIX . '-1.0');
        self::assertNotNull($v1);
        self::assertSame(self::PREFIX, $v1['package_basename']);
        self::assertSame('1.0', $v1['package_version']);
        $vmId = $this->seedVmWithPackages([(int) $v1['id']]);
        $editVersion = (int) repo_scalar($this->db, 'SELECT edit_version FROM deploy_vms WHERE id = ?', 'i', [$vmId]);

        // v1 gone and v2 new in the same payload: the case that used to relink.
        [$status, $body] = $this->post($this->payload([self::PREFIX . '-2.0', self::PREFIX . 'Other-2.0']));
        self::assertSame(200, $status, $body);

        $v1After = $this->packageRow(self::PREFIX . '-1.0');
        self::assertNotNull($v1After, 'retired row must still exist');
        self::assertSame('Retired', $v1After['package_status']);
        self::assertNotNull($v1After['retired_at']);
        self::assertNull($v1After['assignments_relinked_at'], 'nothing was moved, so nothing is marked');
        self::assertSame([(int) $v1['id']], $this->linkedPackageIds($vmId), 'a real VM keeps the version the administrator chose');
        self::assertSame($editVersion, (int) repo_scalar($this->db, 'SELECT edit_version FROM deploy_vms WHERE id = ?', 'i', [$vmId]), 'the VM editor state was not touched');

        $entry = $this->auditContext(VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGE_RETIRED_ASSIGNED, self::PREFIX . '-1.0');
        self::assertNotNull($entry, 'the retirement of a version VMs still hold is logged once');
        self::assertStringContainsString(self::PREFIX . '-1.0: 1 VM(s), 0 Vorlagen-VM(s), hoehere Version ' . self::PREFIX . '-2.0', $entry);
        self::assertNull($this->auditContext(VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGES_RELINKED, self::PREFIX . '-1.0'), 'no relink entry for a real VM');

        // v1 re-appears -> un-retire, still nothing moved.
        [$status] = $this->post($this->payload([self::PREFIX . '-1.0', self::PREFIX . '-2.0', self::PREFIX . 'Other-2.0']));
        self::assertSame(200, $status);
        $v1Again = $this->packageRow(self::PREFIX . '-1.0');
        self::assertSame('Aktiv', $v1Again['package_status']);
        self::assertNull($v1Again['retired_at']);
        self::assertSame([(int) $v1['id']], $this->linkedPackageIds($vmId));
    }

    /**
     * A template VM is never installed, so it follows a higher version once,
     * when that version appears for the first time, even while the old one is
     * still active. A real VM holding the same version stays where it is.
     */
    public function testATemplateFollowsAFirstAppearanceWhileARealVmStays(): void
    {
        [$status] = $this->post($this->payload([self::PREFIX . '-1.0']));
        self::assertSame(200, $status);
        $v1 = $this->packageRow(self::PREFIX . '-1.0');
        $realVm = $this->seedVmWithPackages([(int) $v1['id']]);
        $templateVm = $this->seedVmWithPackages([(int) $v1['id']], true);
        $templateEdit = (int) repo_scalar($this->db, 'SELECT edit_version FROM deploy_vms WHERE id = ?', 'i', [$templateVm]);

        // 2.0 appears for the first time; 1.0 stays active (no removeOldVersion).
        [$status] = $this->post($this->payload([self::PREFIX . '-1.0', self::PREFIX . '-2.0']));
        self::assertSame(200, $status);
        $v2 = $this->packageRow(self::PREFIX . '-2.0');

        self::assertSame([(int) $v2['id']], $this->linkedPackageIds($templateVm), 'the template follows the first appearance');
        self::assertGreaterThan($templateEdit, (int) repo_scalar($this->db, 'SELECT edit_version FROM deploy_vms WHERE id = ?', 'i', [$templateVm]));
        self::assertSame([(int) $v1['id']], $this->linkedPackageIds($realVm), 'the real VM is never moved');
        $entry = $this->auditContext(VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGES_RELINKED, self::PREFIX . '-1.0 -> ' . self::PREFIX . '-2.0');
        self::assertNotNull($entry);
        self::assertStringContainsString('(1 Vorlagen-VM(s))', $entry);
        self::assertNull($this->auditContext(VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGE_RETIRED_ASSIGNED, self::PREFIX . '-1.0'), 'nothing was retired');
    }

    /**
     * Once is once: a template set back by hand stays there on every later
     * sync, because 2.0 is no longer a first appearance.
     */
    public function testATemplatePinnedBackByHandIsNotMovedAgain(): void
    {
        [$status] = $this->post($this->payload([self::PREFIX . '-1.0']));
        self::assertSame(200, $status);
        $v1 = $this->packageRow(self::PREFIX . '-1.0');
        $templateVm = $this->seedVmWithPackages([(int) $v1['id']], true);
        [$status] = $this->post($this->payload([self::PREFIX . '-1.0', self::PREFIX . '-2.0']));
        self::assertSame(200, $status);
        $v2 = $this->packageRow(self::PREFIX . '-2.0');
        self::assertSame([(int) $v2['id']], $this->linkedPackageIds($templateVm));

        repo_execute($this->db, 'UPDATE deploy_vm_packages SET package_id = ? WHERE vm_id = ? AND package_id = ?', 'iii', [(int) $v1['id'], $templateVm, (int) $v2['id']]);
        [$status] = $this->post($this->payload([self::PREFIX . '-1.0', self::PREFIX . '-2.0']));
        self::assertSame(200, $status);

        self::assertSame([(int) $v1['id']], $this->linkedPackageIds($templateVm), 'a deliberately pinned template keeps its version');
    }

    public function testTheTemplateScopeHoldsOnlyTemplatesBelowAFirstAppearanceAndLocksThem(): void
    {
        [$status] = $this->post($this->payload([self::PREFIX . '-1.0', self::PREFIX . '-3.0']));
        self::assertSame(200, $status);
        $v1 = $this->packageRow(self::PREFIX . '-1.0');
        $v3 = $this->packageRow(self::PREFIX . '-3.0');
        $realVm = $this->seedVmWithPackages([(int) $v1['id']]);
        $lowTemplate = $this->seedVmWithPackages([(int) $v1['id']], true);
        $highTemplate = $this->seedVmWithPackages([(int) $v3['id']], true);

        $this->db->begin_transaction();
        try {
            $scope = packages_lock_template_follow_scope($this->db, [self::PREFIX . '-1.0', self::PREFIX . '-2.0', self::PREFIX . '-3.0']);
            $known = packages_lock_template_follow_scope($this->db, [self::PREFIX . '-1.0', self::PREFIX . '-3.0']);
        } finally {
            $this->db->rollback();
        }

        $vmIds = array_map(static fn (array $entry): int => $entry['vm_id'], $scope);
        self::assertSame([$lowTemplate], $vmIds, 'only templates below the first-time 2.0; not the real VM, not the template on 3.0');
        self::assertNotContains($realVm, $vmIds);
        self::assertNotContains($highTemplate, $vmIds);
        self::assertSame([], $known, 'names already in the catalog are no first appearance');
    }

    /**
     * A package that merely vanished from one payload is a GAP, not an upgrade,
     * so the assignment stays where the operator put it.
     */
    public function testATransientDisappearanceLeavesTheAssignmentAlone(): void
    {
        [$status] = $this->post($this->payload([self::PREFIX . '-1.0', self::PREFIX . '-2.0']));
        self::assertSame(200, $status);
        $v1 = $this->packageRow(self::PREFIX . '-1.0');
        $vmId = $this->seedVmWithPackages([(int) $v1['id']]);
        $templateVm = $this->seedVmWithPackages([(int) $v1['id']], true);

        // v1 drops out of the payload while v2 is nothing new.
        [$status] = $this->post($this->payload([self::PREFIX . '-2.0']));
        self::assertSame(200, $status);

        self::assertSame([(int) $v1['id']], $this->linkedPackageIds($vmId), 'no newer row appeared, so nothing was an upgrade to follow');
        self::assertSame([(int) $v1['id']], $this->linkedPackageIds($templateVm), 'not even a template follows a version that is not new');
        self::assertSame('Retired', $this->packageRow(self::PREFIX . '-1.0')['package_status'], 'the row is still retired; only the assignment is untouched');
        self::assertNull($this->packageRow(self::PREFIX . '-1.0')['assignments_relinked_at'], 'nothing was relinked, so nothing is marked');
        $entry = $this->auditContext(VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGE_RETIRED_ASSIGNED, self::PREFIX . '-1.0');
        self::assertNotNull($entry);
        self::assertStringContainsString('1 VM(s), 1 Vorlagen-VM(s), hoehere Version ' . self::PREFIX . '-2.0', $entry);
    }

    /**
     * The successor is the higher VERSION, not the higher row id. A catalog whose
     * versions were imported out of order (a re-created package, a backported
     * fix) moved the assignment to the wrong, possibly older package.
     */
    public function testATemplateFollowsTheHighestNewVersionAndNotTheRowId(): void
    {
        [$status] = $this->post($this->payload([self::PREFIX . '-2.0', self::PREFIX . '-3.0']));
        self::assertSame(200, $status);
        $v2 = $this->packageRow(self::PREFIX . '-2.0');
        $v3 = $this->packageRow(self::PREFIX . '-3.0');
        // Force the id order to disagree with the version order.
        repo_execute($this->db, 'UPDATE deploy_packages SET id = id + 1000 WHERE id = ?', 'i', [(int) $v2['id']]);
        $v2 = $this->packageRow(self::PREFIX . '-2.0');
        self::assertGreaterThan((int) $v3['id'], (int) $v2['id'], 'the fixture needs the lower version on the higher id');

        $templateVm = $this->seedVmWithPackages([(int) $v2['id']], true);

        // 4.0 and 3.5 arrive as genuinely new rows; 3.0 is not new.
        [$status] = $this->post($this->payload([self::PREFIX . '-3.0', self::PREFIX . '-3.5', self::PREFIX . '-4.0']));
        self::assertSame(200, $status);
        $v4 = $this->packageRow(self::PREFIX . '-4.0');

        self::assertSame([(int) $v4['id']], $this->linkedPackageIds($templateVm), 'the template must follow the highest version created by this payload');
    }

    /** A template holding two lower versions ends up with one link, not a duplicate. */
    public function testATemplateHoldingTwoLowerVersionsKeepsExactlyOneLink(): void
    {
        [$status] = $this->post($this->payload([self::PREFIX . '-1.0', self::PREFIX . '-2.0']));
        self::assertSame(200, $status);
        $v1 = $this->packageRow(self::PREFIX . '-1.0');
        $v2 = $this->packageRow(self::PREFIX . '-2.0');
        $templateVm = $this->seedVmWithPackages([(int) $v1['id'], (int) $v2['id']], true);

        [$status] = $this->post($this->payload([self::PREFIX . '-1.0', self::PREFIX . '-2.0', self::PREFIX . '-3.0']));
        self::assertSame(200, $status);
        $v3 = $this->packageRow(self::PREFIX . '-3.0');

        self::assertSame([(int) $v3['id']], $this->linkedPackageIds($templateVm), 'both lower links collapse into the one new version');
    }

    /**
     * The composition that could lose a package for good: a relink removes the
     * reference, and the purge protected the row only while that reference
     * existed. Still true for templates.
     */
    public function testThePurgeKeepsARowWhoseTemplateAssignmentsMovedAway(): void
    {
        [$status] = $this->post($this->payload([self::PREFIX . '-1.0']));
        self::assertSame(200, $status);
        $v1 = $this->packageRow(self::PREFIX . '-1.0');
        $templateVm = $this->seedVmWithPackages([(int) $v1['id']], true);

        [$status] = $this->post($this->payload([self::PREFIX . '-2.0']));
        self::assertSame(200, $status);
        $v2 = $this->packageRow(self::PREFIX . '-2.0');
        self::assertSame([(int) $v2['id']], $this->linkedPackageIds($templateVm));

        $v1After = $this->packageRow(self::PREFIX . '-1.0');
        self::assertNotNull($v1After['assignments_relinked_at'], 'the template relink has to record that it removed the reference');
        self::assertNull($this->auditContext(VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGE_RETIRED_ASSIGNED, self::PREFIX . '-1.0'), 'the template moved first, so nobody holds the retired row');

        repo_execute($this->db, 'UPDATE deploy_packages SET retired_at = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE id = ?', 'ii', [VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS + 10, (int) $v1['id']]);
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

        repo_execute($this->db, 'UPDATE deploy_packages SET retired_at = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE id = ?', 'ii', [VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS + 10, (int) $lonely['id']]);
        repo_purge_retired_packages($this->db);

        self::assertNull($this->packageRow(self::PREFIX . 'Lonely-1.0'), 'the purge must still do its job for an unassigned row');
    }

    /** @param list<int> $packageIds */
    private function seedVmWithPackages(array $packageIds, bool $template = false): int
    {
        // Mission names are unique: several VMs of one kind share one mission.
        $missionName = $template ? self::templateMission() : self::REAL_MISSION;
        $missionId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_missions WHERE mission_name = ?', 's', [$missionName]);
        if ($missionId === 0) {
            repo_execute($this->db, 'INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)', 'ss', [$missionName, 'active']);
            $missionId = (int) $this->db->insert_id;
        }
        $this->vmSeq++;
        $vmName = 'PHPUNIT-E3-' . $this->vmSeq;
        repo_execute($this->db, 'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)', 'iss', [$missionId, $vmName, $vmName]);
        $vmId = (int) $this->db->insert_id;
        foreach ($packageIds as $packageId) {
            repo_execute($this->db, 'INSERT INTO deploy_vm_packages (vm_id, package_id) VALUES (?, ?)', 'ii', [$vmId, $packageId]);
        }

        return $vmId;
    }

    private static function templateMission(): string
    {
        return VIRTUSPHERE_TEMPLATE_PREFIX . 'phpunit_e3_template';
    }

    /** @return list<int> */
    private function linkedPackageIds(int $vmId): array
    {
        $stmt = $this->db->prepare('SELECT package_id FROM deploy_vm_packages WHERE vm_id = ? ORDER BY package_id');
        $stmt->bind_param('i', $vmId);
        $stmt->execute();

        return array_map(static fn (array $row): int => (int) $row['package_id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    /** The newest audit context of $event that mentions $needle, or null. */
    private function auditContext(string $event, string $needle): ?string
    {
        $like = '%' . $needle . '%';
        $stmt = $this->db->prepare('SELECT context_json FROM deploy_logs WHERE event_code = ? AND context_json LIKE ? ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('ss', $event, $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();

        return $row === null ? null : (string) $row[0];
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
     * AV-R14 (15.10.2026): a version change is not a catalog loss. A sync that
     * replaces all eight packages by their higher version retires the old rows,
     * but no package is lost, so the brake must not reject it with 409.
     */
    public function testAMassVersionBumpIsNotRejected(): void
    {
        $oldNames = [];
        $newNames = [];
        for ($i = 1; $i <= 8; $i++) {
            $oldNames[] = self::PREFIX . 'Bulk' . $i . '-1.0';
            $newNames[] = self::PREFIX . 'Bulk' . $i . '-2.0';
        }
        [$status, $body] = $this->post($this->payload($oldNames));
        self::assertSame(200, $status, $body);

        [$status, $body] = $this->post($this->payload($newNames));
        self::assertSame(200, $status, $body);

        foreach ($oldNames as $name) {
            $row = $this->packageRow($name);
            self::assertNotNull($row, $name);
            self::assertSame('Retired', $row['package_status'], $name . ' is replaced by its higher version');
        }
        foreach ($newNames as $name) {
            $row = $this->packageRow($name);
            self::assertNotNull($row, $name);
            self::assertNotSame('Retired', $row['package_status'], $name . ' is the active successor');
        }
    }

    /**
     * AV-R14 counter-case: a name replaced only by a LOWER version is a real
     * retirement. Seven packages on 2.0 that come back as 1.0 must still trip the
     * brake, so the version rule cannot be used to slip a catalog loss through.
     */
    public function testAVersionReplacedOnlyByALowerOneStillCounts(): void
    {
        $highNames = [];
        for ($i = 1; $i <= 8; $i++) {
            $highNames[] = self::PREFIX . 'Bulk' . $i . '-2.0';
        }
        [$status, $body] = $this->post($this->payload($highNames));
        self::assertSame(200, $status, $body);

        // Bulk1 keeps its 2.0; Bulk2 to Bulk8 come back as LOWER 1.0 versions.
        $payloadNames = [self::PREFIX . 'Bulk1-2.0'];
        for ($i = 2; $i <= 8; $i++) {
            $payloadNames[] = self::PREFIX . 'Bulk' . $i . '-1.0';
        }
        [$status, $body] = $this->post($this->payload($payloadNames));
        self::assertSame(409, $status, $body);

        foreach ($highNames as $name) {
            $row = $this->packageRow($name);
            self::assertNotNull($row, $name);
            self::assertNotSame('Retired', $row['package_status'], $name . ' must stay active after the rejected sync');
        }
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
        repo_execute($this->db, 'DELETE FROM deploy_missions WHERE mission_name IN (?, ?)', 'ss', [self::REAL_MISSION, self::templateMission()]);
        repo_execute(
            $this->db,
            'DELETE FROM deploy_logs WHERE event_code IN (?, ?) AND context_json LIKE ?',
            'sss',
            [VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGES_RELINKED, VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGE_RETIRED_ASSIGNED, '%' . self::PREFIX . '%']
        );
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
