<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/repo/catalog.php';
require_once dirname(__DIR__, 2) . '/lib/repo/usage_evidence.php';
require_once dirname(__DIR__, 2) . '/lib/usage_evidence_view.php';

/**
 * F08 "Verwendet in" against the database: multi-use, unused, templates versus
 * missions, open versus finished jobs, the bounded list with its complete total,
 * and the absence of every credential secret from the result and the HTML.
 */
final class UsageEvidenceTest extends TestCase
{
    private const PREFIX = 'phpunit_usage_';
    private const SECRET_MARKER = 'PHPUNIT-USAGE-SECRET-MARKER';
    private const CERT_MARKER = 'PHPUNIT-USAGE-CERT-MARKER';
    private const HOST_MARKER = 'phpunit-usage-host.invalid';
    private const USER_MARKER = 'phpunit-usage-operator';

    private mysqli $db;

    /** @var list<int> */
    private array $missions = [];
    /** @var list<int> */
    private array $packages = [];
    /** @var list<int> */
    private array $credentials = [];
    /** @var list<int> */
    private array $jobs = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    public function testAPackageOnAMissionAndATemplateShowsBothAndMarksTheTemplate(): void
    {
        $f = $this->fixture();
        $evidence = usage_evidence_package($this->db, $f['pkg']);

        $direct = $evidence['groups']['direct'];
        self::assertTrue($direct['applies']);
        self::assertSame(2, $direct['total']);
        $names = array_column($direct['rows'], 'mission_name');
        self::assertContains(self::PREFIX . 'mission_a', $names);
        self::assertContains('_' . self::PREFIX . 'template', $names);
        self::assertFalse($evidence['groups']['mission']['applies']);
        self::assertFalse(usage_evidence_is_unused($evidence));
    }

    public function testAnUnusedPackageHasNoReferenceAndIsReportedAsUnused(): void
    {
        $f = $this->fixture();
        $evidence = usage_evidence_package($this->db, $f['pkg_unused']);

        self::assertSame(0, $evidence['groups']['direct']['total']);
        self::assertSame([], $evidence['groups']['direct']['rows']);
        self::assertTrue(usage_evidence_is_unused($evidence));
    }

    public function testTheOsCounterAndTheOsDetailAnswerTheSameNumber(): void
    {
        $f = $this->fixture();
        $counts = usage_evidence_os_vm_counts($this->db);
        $detail = usage_evidence_os($this->db, self::PREFIX . 'os');

        // vm1 and vm2 in the mission, vm3 in the template: templates count as they always did.
        self::assertSame(3, $counts[self::PREFIX . 'os'] ?? null);
        self::assertSame(3, $detail['groups']['direct']['total']);
        self::assertArrayNotHasKey(self::PREFIX . 'unknown_os', $counts);
        // The catalog's counter is the same reader, so the two cannot diverge.
        self::assertEqualsCanonicalizing($counts, repo_os_vm_counts($this->db));
    }

    public function testAVlanCountsTheStoredValueOncePerVmAndTheWdsPortGroupSeparately(): void
    {
        $f = $this->fixture();
        $evidence = usage_evidence_vlan($this->db, self::PREFIX . 'vlan');

        // vm1 has two adapters on the VLAN: one reference. vm2 holds the upper-case spelling.
        self::assertSame(1, $evidence['groups']['direct']['total']);
        self::assertSame(self::PREFIX . 'vm1', $evidence['groups']['direct']['rows'][0]['vm_name']);
        self::assertSame(1, $evidence['groups']['mission']['total']);
        self::assertSame(self::PREFIX . 'mission_a', $evidence['groups']['mission']['rows'][0]['mission_name']);
        self::assertFalse($evidence['groups']['job_open']['applies']);
    }

    public function testAVlanNameIsMatchedExactlyNotByCase(): void
    {
        $f = $this->fixture();
        $evidence = usage_evidence_vlan($this->db, strtoupper(self::PREFIX . 'vlan'));

        self::assertSame(1, $evidence['groups']['direct']['total']);
        self::assertSame(self::PREFIX . 'vm2', $evidence['groups']['direct']['rows'][0]['vm_name']);
        self::assertSame(0, $evidence['groups']['mission']['total']);
    }

    public function testAnEsxiAccessCountsOnlyProvenBindingsAndNamesTheLimitOfThatProof(): void
    {
        $f = $this->fixture();
        $evidence = usage_evidence_credential($this->db, $f['esxi'], 'esxi');

        self::assertTrue($evidence['groups']['direct']['applies']);
        self::assertSame(1, $evidence['groups']['direct']['total']);
        self::assertContains('vm_binding_unproven', $evidence['notes']);
        self::assertContains('vm_identity', $evidence['sources']);
        self::assertFalse(usage_evidence_is_unused($evidence));

        $ansible = usage_evidence_credential($this->db, $f['ansible'], 'ansible');
        self::assertFalse($ansible['groups']['direct']['applies']);
        self::assertContains('ansible_jobs_only', $ansible['notes']);
    }

    public function testQueuedRunningAndCancellingJobsAreOpenAndFinishedMissionJobsAreHistory(): void
    {
        $f = $this->fixture();
        $esxi = usage_evidence_credential($this->db, $f['esxi'], 'esxi');

        // The queued and running mission jobs and the queued inventory pull are open.
        $open = $esxi['groups']['job_open'];
        self::assertSame(3, $open['total']);
        $statuses = array_column($open['rows'], 'status');
        sort($statuses);
        self::assertSame(['queued', 'queued', 'running'], $statuses);

        // Only the finished mission job is history: the finished inventory pull is not listed.
        $history = $esxi['groups']['job_history'];
        self::assertSame(1, $history['total']);
        self::assertSame('succeeded', $history['rows'][0]['status']);

        $ansible = usage_evidence_credential($this->db, $f['ansible'], 'ansible');
        self::assertSame(1, $ansible['groups']['job_open']['total']);
        self::assertSame('cancelling', $ansible['groups']['job_open']['rows'][0]['status']);
        self::assertSame(0, $ansible['groups']['job_history']['total']);
        self::assertFalse(usage_evidence_is_unused($ansible));
    }

    public function testTheListIsBoundedAndKeepsTheCompleteTotal(): void
    {
        $f = $this->fixture();
        $evidence = usage_evidence_package($this->db, $f['pkg_bulk'], 10);

        self::assertSame(12, $evidence['groups']['direct']['total']);
        self::assertCount(10, $evidence['groups']['direct']['rows']);
        self::assertSame(2, usage_evidence_hidden_count(12, count($evidence['groups']['direct']['rows'])));
    }

    public function testTheRenderedSectionLinksTheReferencesAndNeverPrintsASecret(): void
    {
        $f = $this->fixture();
        $html = usage_evidence_section_html(usage_evidence_credential($this->db, $f['esxi'], 'esxi'), true, false);

        self::assertStringContainsString('usage-evidence', $html);
        self::assertStringContainsString('mission_details.php?id=' . $f['mission_a'], $html);
        self::assertStringContainsString('vm_edit.php?mission_id=' . $f['mission_a'] . '&amp;vm_id=' . $f['vm1'], $html);
        self::assertStringContainsString('deploy_log.php?id=', $html);
        self::assertStringNotContainsString(self::SECRET_MARKER, $html);
        self::assertStringNotContainsString(self::CERT_MARKER, $html);
        self::assertStringNotContainsString(self::HOST_MARKER, $html);
        self::assertStringNotContainsString(self::USER_MARKER, $html);
    }

    public function testAJobLinkIsOnlyRenderedForAReaderWhoMayOpenJobs(): void
    {
        $f = $this->fixture();
        $evidence = usage_evidence_credential($this->db, $f['esxi'], 'esxi');

        self::assertStringNotContainsString('deploy_log.php?id=', usage_evidence_section_html($evidence, false, false));
        self::assertStringContainsString('deploy_log.php?id=', usage_evidence_section_html($evidence, true, false));
    }

    public function testADeletedAccessLeavesItsJobsWithoutAReference(): void
    {
        $f = $this->fixture();
        $gone = $this->credential('esxi', self::PREFIX . 'gone');
        $job = $this->job($f['mission_a'], 'succeeded', $gone, null);

        $this->execSql('DELETE FROM deploy_credentials WHERE id = ?', 'i', [$gone]);
        $this->credentials = array_values(array_diff($this->credentials, [$gone]));

        // The job row survives with its foreign key cleared (ON DELETE SET NULL).
        $row = repo_fetch_one($this->db, 'SELECT credential_esxi_id FROM deploy_jobs WHERE id = ?', 'i', [$job]);
        self::assertNotNull($row);
        self::assertNull($row['credential_esxi_id']);

        // Nothing is reconstructed from the old name: no reference is found.
        $evidence = usage_evidence_credential($this->db, $gone, 'esxi');
        self::assertSame(0, $evidence['groups']['job_history']['total']);
        self::assertSame(0, $evidence['groups']['job_open']['total']);
    }

    /** @return array<string, int> */
    private function fixture(): array
    {
        $missionA = $this->mission(self::PREFIX . 'mission_a', self::PREFIX . 'vlan');
        $template = $this->mission('_' . self::PREFIX . 'template', null);
        $esxi = $this->credential('esxi', self::PREFIX . 'esxi');
        $ansible = $this->credential('ansible', self::PREFIX . 'ansible');

        $vm1 = $this->vm($missionA, self::PREFIX . 'vm1', self::PREFIX . 'os', $esxi);
        $vm2 = $this->vm($missionA, self::PREFIX . 'vm2', self::PREFIX . 'os', null);
        $vm3 = $this->vm($template, self::PREFIX . 'vm3', self::PREFIX . 'os', null);
        $this->iface($vm1, self::PREFIX . 'vlan');
        $this->iface($vm1, self::PREFIX . 'vlan');
        $this->iface($vm2, strtoupper(self::PREFIX . 'vlan'));

        $pkg = $this->package(self::PREFIX . 'pkg');
        $pkgUnused = $this->package(self::PREFIX . 'pkg_unused');
        $pkgBulk = $this->package(self::PREFIX . 'pkg_bulk');
        $this->link($vm1, $pkg);
        $this->link($vm3, $pkg);
        for ($i = 1; $i <= 12; $i++) {
            $bulkVm = $this->vm($missionA, sprintf('%sbulk_%02d', self::PREFIX, $i), null, null);
            $this->link($bulkVm, $pkgBulk);
        }

        $this->job($missionA, 'queued', $esxi, null);
        $this->job($missionA, 'running', $esxi, null);
        $this->job($missionA, 'cancelling', null, $ansible);
        $this->job($missionA, 'succeeded', $esxi, null);
        $this->job(null, 'succeeded', $esxi, null);
        $this->job(null, 'queued', $esxi, null);

        return [
            'mission_a' => $missionA,
            'vm1' => $vm1,
            'esxi' => $esxi,
            'ansible' => $ansible,
            'pkg' => $pkg,
            'pkg_unused' => $pkgUnused,
            'pkg_bulk' => $pkgBulk,
        ];
    }

    private function mission(string $name, ?string $wdsVlan): int
    {
        $id = $this->execSql(
            'INSERT INTO deploy_missions (mission_name, mission_status, wds_vlan) VALUES (?, ?, ?)',
            'sss',
            [$name, 'active', $wdsVlan]
        );
        $this->missions[] = $id;

        return $id;
    }

    private function credential(string $type, string $name): int
    {
        // A direct prepare, so CredentialInsertBindTypesContractTest can read the bind types.
        $host = self::HOST_MARKER;
        $user = self::USER_MARKER;
        $secret = self::SECRET_MARKER;
        $cert = self::CERT_MARKER;
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext, esxi_certificate_pem) VALUES (?, ?, ?, NULL, ?, ?, ?)');
        $stmt->bind_param('ssssss', $type, $name, $host, $user, $secret, $cert);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $this->credentials[] = $id;

        return $id;
    }

    private function vm(int $missionId, string $name, ?string $os, ?int $boundCredentialId): int
    {
        return $this->execSql(
            'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os, vm_bound_credential_id) VALUES (?, ?, ?, ?, ?)',
            'isssi',
            [$missionId, $name, $name, $os, $boundCredentialId]
        );
    }

    private function iface(int $vmId, string $vlan): void
    {
        $this->execSql("INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan) VALUES (?, '', '', '', ?)", 'is', [$vmId, $vlan]);
    }

    private function package(string $name): int
    {
        $id = $this->execSql(
            'INSERT INTO deploy_packages (package_name, package_version, package_status) VALUES (?, ?, ?)',
            'sss',
            [$name, '1.0', VIRTUSPHERE_CATALOG_STATUS_DEFAULT]
        );
        $this->packages[] = $id;

        return $id;
    }

    private function link(int $vmId, int $packageId): void
    {
        $this->execSql('INSERT INTO deploy_vm_packages (vm_id, package_id) VALUES (?, ?)', 'ii', [$vmId, $packageId]);
    }

    private function job(?int $missionId, string $status, ?int $esxiId, ?int $ansibleId): int
    {
        $id = $this->execSql(
            'INSERT INTO deploy_jobs (mission_id, status, credential_esxi_id, credential_ansible_id) VALUES (?, ?, ?, ?)',
            'isii',
            [$missionId, $status, $esxiId, $ansibleId]
        );
        $this->jobs[] = $id;

        return $id;
    }

    /** @param list<string|int|null> $params */
    private function execSql(string $sql, string $types = '', array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function cleanup(): void
    {
        // Jobs first (their foreign keys are SET NULL, but mission jobs cascade with the mission).
        $this->deleteIds('deploy_jobs', $this->jobs);
        $this->deleteIds('deploy_missions', $this->missions);
        $this->deleteIds('deploy_packages', $this->packages);
        $this->deleteIds('deploy_credentials', $this->credentials);
        $this->jobs = [];
        $this->missions = [];
        $this->packages = [];
        $this->credentials = [];

        // Leftovers of an interrupted run, matched by the fixture names.
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], self::PREFIX);
        $this->execSql('DELETE FROM deploy_jobs WHERE credential_esxi_id IN (SELECT id FROM deploy_credentials WHERE name LIKE ?)', 's', [$like . '%']);
        $this->execSql('DELETE FROM deploy_missions WHERE mission_name LIKE ?', 's', [$like . '%']);
        $this->execSql('DELETE FROM deploy_missions WHERE mission_name LIKE ?', 's', ['\\_' . $like . '%']);
        $this->execSql('DELETE FROM deploy_packages WHERE package_name LIKE ?', 's', [$like . '%']);
        $this->execSql('DELETE FROM deploy_credentials WHERE name LIKE ?', 's', [$like . '%']);
    }

    /** @param list<int> $ids */
    private function deleteIds(string $table, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // $table is one of the literal table names above; the values are bound.
        $this->execSql('DELETE FROM ' . $table . ' WHERE id IN (' . $placeholders . ')', str_repeat('i', count($ids)), $ids);
    }
}
