<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vm_evidence.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase.php';
require_once dirname(__DIR__, 2) . '/lib/vm_overview.php';

/**
 * SP4 R2 (F6): the four "check due" groups of the dashboard. For each group the
 * tile count, the total of the overview with the same filter and its rows answer
 * the same question, and that question is exactly the VM finding of the same
 * name (virtusphere_vm_findings()). Template VMs never count. The shared dev
 * database holds other rows, so counts are deltas around the seeded rows and
 * rows are compared by membership.
 */
final class DashboardAttentionGroupTest extends TestCase
{
    private const PREFIX = 'phpunit_attngroup_';

    private mysqli $db;

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

    public function testEachGroupCountsExactlyTheVmsWithItsFindingAndNoTemplateVm(): void
    {
        $before = repo_vm_overview_attention_counts($this->db);
        self::assertSame(array_keys(VIRTUSPHERE_VM_OVERVIEW_ATTENTION_FINDINGS), array_keys($before), 'one count per group, in group order');

        $real = repo_create_mission($this->db, ['mission_name' => self::PREFIX . 'real_' . bin2hex(random_bytes(3))]);
        $template = repo_create_mission($this->db, ['mission_name' => VIRTUSPHERE_TEMPLATE_PREFIX . self::PREFIX . 'tpl_' . bin2hex(random_bytes(3))]);
        $realIds = $this->seed($real);
        $templateIds = $this->seed($template);

        $after = repo_vm_overview_attention_counts($this->db);
        $expected = $this->expectedByFinding($real, $realIds);

        foreach (VIRTUSPHERE_VM_OVERVIEW_ATTENTION_FINDINGS as $filter => $kind) {
            self::assertNotSame([], $expected[$kind], $filter . ': the fixture reaches this finding');
            self::assertSame(count($expected[$kind]), $after[$filter] - $before[$filter], $filter . ': the group counts the real VMs with finding ' . $kind);
            self::assertSame(repo_vm_overview_count($this->db, $filter), $after[$filter], $filter . ': the tile reads the list predicate');

            $listed = $this->idsOf($filter);
            $seededListed = array_values(array_intersect($listed, $realIds));
            sort($seededListed);
            $wanted = $expected[$kind];
            sort($wanted);
            self::assertSame($wanted, $seededListed, $filter . ': the list shows exactly the VMs with finding ' . $kind);
            self::assertSame([], array_values(array_intersect($listed, $templateIds)), $filter . ': a template VM is not checked (F6)');
        }
    }

    public function testAMecmFailureIsNotWaitingForMecm(): void
    {
        $mission = repo_create_mission($this->db, ['mission_name' => self::PREFIX . 'mx0_' . bin2hex(random_bytes(3))]);
        $before = repo_vm_overview_count($this->db, VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_OVERDUE);
        // An old pending stamp left behind on a deploy failure (deploy_failed since
        // MX0): a failed job is a finding for a person, not a wait for MECM.
        $vm = $this->vm($mission, 'MX0', VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED, 5, null);

        self::assertSame($before, repo_vm_overview_count($this->db, VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_OVERDUE));
        self::assertNotContains($vm, $this->idsOf(VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_OVERDUE));
    }

    /**
     * One VM per case: inside and just outside each group.
     *
     * @return list<int>
     */
    private function seed(int $missionId): array
    {
        $ids = [];
        $ids[] = $this->vm($missionId, 'MOD', VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING, 3, null);
        $ids[] = $this->vm($missionId, 'MFR', VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING, 1, null);
        $ids[] = $this->vm($missionId, 'MNS', VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_PENDING, null, null);
        $ids[] = $this->vm($missionId, 'MFL', VIRTUSPHERE_LIFECYCLE_DEPLOYED, VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED, 3, null);
        $ids[] = $this->vm($missionId, 'IOD', VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, null, 7);
        $ids[] = $this->vm($missionId, 'IFR', VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_REGISTERED, null, 1);
        $ids[] = $this->vm($missionId, 'INR', VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, VIRTUSPHERE_MECM_SYNC_NOT_READY, null, 7);
        $ids[] = $this->vm($missionId, 'IOK', VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_REGISTERED, null, 9);

        $failed = $this->vm($missionId, 'JFL', VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_NOT_READY, null, null);
        $this->journal($this->job($missionId, VIRTUSPHERE_DEPLOY_STATUS_FAILED), $failed);
        $ids[] = $failed;
        // D4: the reaper leaves an unknown outcome as lifecycle failed; it belongs to the group.
        $unknown = $this->vm($missionId, 'JOU', VIRTUSPHERE_LIFECYCLE_FAILED, VIRTUSPHERE_MECM_SYNC_NOT_READY, null, null);
        $this->journal($this->job($missionId, VIRTUSPHERE_DEPLOY_STATUS_OUTCOME_UNKNOWN), $unknown);
        $ids[] = $unknown;
        $ids[] = $this->vm($missionId, 'JRD', VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, null, null);

        $createJob = $this->job($missionId, VIRTUSPHERE_DEPLOY_STATUS_PARTIAL);
        $uncertain = $this->vm($missionId, 'CUN', VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, null, null);
        $this->createUnit($createJob, $uncertain, VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN, 1);
        $ids[] = $uncertain;
        $settled = $this->vm($missionId, 'CFA', VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY, null, null);
        $this->createUnit($createJob, $settled, 'failed', 2);
        $ids[] = $settled;

        return $ids;
    }

    /**
     * finding kind => ids of the seeded VMs the PHP rule gives that finding.
     *
     * @param list<int> $ids
     * @return array<string,list<int>>
     */
    private function expectedByFinding(int $missionId, array $ids): array
    {
        $stmt = $this->db->prepare('SELECT v.* FROM deploy_vms v WHERE v.mission_id = ? ORDER BY v.id');
        $stmt->bind_param('i', $missionId);
        $stmt->execute();
        $vms = repo_fetch_all($stmt->get_result());
        self::assertCount(count($ids), $vms);

        $evidence = repo_vm_evidence($this->db, $vms);
        $expected = array_fill_keys(array_values(VIRTUSPHERE_VM_OVERVIEW_ATTENTION_FINDINGS), []);
        foreach ($vms as $vm) {
            foreach (virtusphere_vm_findings($vm, repo_vm_evidence_for_vm($evidence, $vm, false)) as $finding) {
                if (isset($expected[$finding['kind']])) {
                    $expected[$finding['kind']][] = (int) $vm['id'];
                }
            }
        }

        return $expected;
    }

    /** @return list<int> every id of the list, walked page by page */
    private function idsOf(string $filter): array
    {
        $ids = [];
        $page = 1;
        do {
            $listing = repo_vm_overview_page($this->db, $filter, 'mission', 'asc', $page, 50);
            foreach ($listing['rows'] as $row) {
                $ids[] = (int) $row['id'];
            }
            $page++;
        } while ($page <= $listing['pages']);
        self::assertCount($listing['total'], $ids, $filter . ': the pages add up to the total');

        return $ids;
    }

    private function vm(int $missionId, string $tag, string $lifecycle, string $mecm, ?int $pendingHoursAgo, ?int $watchHoursAgo): int
    {
        $name = 'PAG' . $tag . bin2hex(random_bytes(3));
        repo_execute(
            $this->db,
            'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state, mecm_pending_since, os_install_watch_started_at)'
            . ' VALUES (?, ?, ?, ?, ?,'
            . ' IF(? IS NULL, NULL, DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)),'
            . ' IF(? IS NULL, NULL, DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)))',
            'issssiiii',
            [$missionId, $name, $name, $lifecycle, $mecm, $pendingHoursAgo, $pendingHoursAgo, $watchHoursAgo, $watchHoursAgo]
        );

        return (int) $this->db->insert_id;
    }

    private function job(int $missionId, string $status): int
    {
        repo_execute(
            $this->db,
            'INSERT INTO deploy_jobs (mission_id, status, payload_json, started_at) VALUES (?, ?, ?, UTC_TIMESTAMP())',
            'iss',
            [$missionId, $status, json_encode(['mode' => 'full'])]
        );

        return (int) $this->db->insert_id;
    }

    private function journal(int $jobId, int $vmId): void
    {
        repo_execute(
            $this->db,
            'INSERT INTO deploy_job_vm_states (job_id, vm_id, lifecycle_before, mecm_before) VALUES (?, ?, ?, ?)',
            'iiss',
            [$jobId, $vmId, VIRTUSPHERE_LIFECYCLE_READY, VIRTUSPHERE_MECM_SYNC_NOT_READY]
        );
    }

    private function createUnit(int $jobId, int $vmId, string $status, int $position): void
    {
        repo_execute(
            $this->db,
            "INSERT INTO deploy_create_vm_results (job_id, vm_id, vm_name, position, total, action, status, error_code, error_detail, finished_at) VALUES (?, ?, ?, ?, 2, 'create', ?, 'E_TEST', 'synthetic', UTC_TIMESTAMP())",
            'iisis',
            [$jobId, $vmId, self::PREFIX . 'create', $position, $status]
        );
    }

    private function cleanup(): void
    {
        $templatePattern = str_replace('_', '\\_', VIRTUSPHERE_TEMPLATE_PREFIX) . self::PREFIX . '%';
        $plainPattern = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ? OR mission_name LIKE ?');
        $stmt->bind_param('ss', $templatePattern, $plainPattern);
        $stmt->execute();
    }
}
