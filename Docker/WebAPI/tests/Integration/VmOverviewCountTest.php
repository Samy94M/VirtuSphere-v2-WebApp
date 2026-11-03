<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/vm_overview.php';

/**
 * The dashboard VM tiles and the overview they open read one predicate per
 * filter. For the same unchanged data the tile count equals the list total, and
 * a VM that carries several interfaces and packages is still one row. The shared
 * dev database holds other rows, so every assertion is a delta around the seeded
 * rows or a membership check on their ids.
 */
final class VmOverviewCountTest extends TestCase
{
    private const PREFIX = 'phpunit_vmoverview_';

    private ?mysqli $db = null;

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
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testTileCountAndListTotalAgreeAndTemplatesAreSplitByFilter(): void
    {
        $allBefore = repo_vm_overview_count($this->db, VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL);
        $pendingBefore = repo_vm_overview_count($this->db, VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_PENDING);
        $seeded = $this->seed();

        self::assertSame(
            3,
            repo_vm_overview_count($this->db, VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL) - $allBefore,
            'the VM tile counts the three real-mission VMs and none of the two template VMs'
        );
        self::assertSame(
            4,
            repo_vm_overview_count($this->db, VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_PENDING) - $pendingBefore,
            'the MECM tile keeps its meaning (updated or pending) and counts the two template VMs that match it'
        );
        self::assertSame(
            (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_vms WHERE updated = 1 OR mecm_sync_state = ?', 's', [VIRTUSPHERE_MECM_SYNC_PENDING]),
            repo_vm_overview_count($this->db, VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_PENDING),
            'the reader answers the question the dashboard asked before it was moved into the reader'
        );

        foreach ([VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL, VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_PENDING] as $filter) {
            $listing = repo_vm_overview_page($this->db, $filter, 'mission', 'asc', 1);
            self::assertSame(repo_vm_overview_count($this->db, $filter), $listing['total'], $filter . ': the list total is the tile count');
        }

        $allIds = $this->idsOf(VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL, 'name', 'asc');
        self::assertSame(count($allIds), count(array_unique($allIds)), 'a joined interface or package must not repeat a VM');
        self::assertSame([], array_intersect($allIds, $seeded['template']), 'a template VM is not a row of the VM list');
        self::assertCount(3, array_intersect($allIds, $seeded['real']), 'each real VM appears exactly once despite its relations');

        $pendingIds = $this->idsOf(VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_PENDING, 'mecm', 'desc');
        self::assertSame(count($pendingIds), count(array_unique($pendingIds)));
        self::assertCount(4, array_intersect($pendingIds, array_merge($seeded['template'], $seeded['real'])), 'the template rows that match the flag are listed, marked by the presenter');
        self::assertNotContains($seeded['registered'], $pendingIds, 'a registered VM without a queued transfer is not pending');
    }

    public function testADeletedEntryChangesTheTileAndTheListAlike(): void
    {
        $seeded = $this->seed();
        $before = repo_vm_overview_page($this->db, VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL, 'name', 'asc', 1);
        $snapshot = ['counted' => 1792400000, 'expected' => $before['total']];

        repo_execute($this->db, 'DELETE FROM deploy_vms WHERE id = ?', 'i', [$seeded['registered']]);

        $after = repo_vm_overview_page($this->db, VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL, 'name', 'asc', 1);
        self::assertSame($before['total'] - 1, $after['total'], 'the list shows the newer state with its own total');
        self::assertSame($after['total'], repo_vm_overview_count($this->db, VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL));
        self::assertTrue(vm_overview_count_changed($snapshot, $after['total']), 'the tile number is explained, not reproduced');
    }

    public function testAPageBeyondTheEndIsClampedAndEveryPageIsReadInTheSameOrder(): void
    {
        $this->seed();
        $listing = repo_vm_overview_page($this->db, VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_PENDING, 'mission', 'asc', 999999, 3);
        self::assertSame($listing['pages'], $listing['page'], 'a page after a shrunk list shows the last existing page');
        self::assertNotSame([], $listing['rows']);

        foreach (['mission', 'hostname'] as $sort) {
            $ids = $this->idsOf(VIRTUSPHERE_VM_OVERVIEW_FILTER_ALL, $sort, 'desc');
            self::assertSame(count($ids), count(array_unique($ids)), $sort . ': a LIMIT window repeated or skipped a row');
        }
    }

    /**
     * Walks every page of a list at a small page size, so the rows of several
     * pages are compared with each other as well as with the total.
     *
     * @return list<int>
     */
    private function idsOf(string $filter, string $sort, string $dir): array
    {
        $ids = [];
        $page = 1;
        do {
            $listing = repo_vm_overview_page($this->db, $filter, $sort, $dir, $page, 3);
            foreach ($listing['rows'] as $row) {
                $ids[] = (int) $row['id'];
            }
            $page++;
        } while ($page <= $listing['pages']);

        self::assertCount($listing['total'], $ids, $filter . ' ' . $sort . ': the pages add up to the total');

        return $ids;
    }

    /**
     * Two template VMs (one flagged, one queued), three real VMs (one queued, one
     * pending, one registered and quiet). The real VMs carry several interfaces
     * and packages, which a join on them would multiply.
     *
     * @return array{template:list<int>,real:list<int>,registered:int}
     */
    private function seed(): array
    {
        $template = $this->mission(VIRTUSPHERE_TEMPLATE_PREFIX . self::PREFIX . 'tpl_' . bin2hex(random_bytes(3)));
        $real = $this->mission(self::PREFIX . 'real_' . bin2hex(random_bytes(3)));

        $templatePending = $this->vm($template, 'pending', 0);
        $templateQueued = $this->vm($template, 'registered', 1);
        $queued = $this->vm($real, 'registered', 1);
        $pending = $this->vm($real, 'pending', 0);
        $registered = $this->vm($real, 'registered', 0);

        foreach ([$queued, $pending, $registered] as $vmId) {
            $this->interface($vmId, '10.91.' . $vmId . '.1');
            $this->interface($vmId, '10.92.' . $vmId . '.1');
            $this->interface($vmId, '10.93.' . $vmId . '.1');
        }
        $this->linkPackage($queued, $this->package('a'));
        $this->linkPackage($queued, $this->package('b'));
        $this->linkPackage($pending, $this->package('c'));
        $this->linkPackage($pending, $this->package('d'));

        return [
            'template' => [$templatePending, $templateQueued],
            'real' => [$queued, $pending, $registered],
            'registered' => $registered,
        ];
    }

    private function mission(string $name): int
    {
        $status = VIRTUSPHERE_MISSION_STATUS_DEFAULT;
        $stmt = $this->db->prepare('INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, ?)');
        $stmt->bind_param('ss', $name, $status);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function vm(int $missionId, string $mecmState, int $updated): int
    {
        $name = 'PHPUNIT-OVERVIEW-' . bin2hex(random_bytes(4));
        $os = '';
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os, mecm_sync_state, updated) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('issssi', $missionId, $name, $name, $os, $mecmState, $updated);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function package(string $suffix): int
    {
        $name = self::PREFIX . 'pkg_' . $suffix . '_' . bin2hex(random_bytes(3));
        $basename = self::PREFIX . 'base';
        $version = '1.0';
        $status = VIRTUSPHERE_CATALOG_STATUS_DEFAULT;
        $stmt = $this->db->prepare('INSERT INTO deploy_packages (package_name, package_basename, package_version, package_status) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('ssss', $name, $basename, $version, $status);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function linkPackage(int $vmId, int $packageId): void
    {
        $stmt = $this->db->prepare('INSERT INTO deploy_vm_packages (vm_id, package_id) VALUES (?, ?)');
        $stmt->bind_param('ii', $vmId, $packageId);
        $stmt->execute();
    }

    private function interface(int $vmId, string $ip): void
    {
        $subnet = '';
        $gateway = '';
        $vlan = self::PREFIX . 'vlan';
        $mac = '';
        $mode = 'static';
        $type = 'vmxnet3';
        $stmt = $this->db->prepare('INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac, mode, type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssssss', $vmId, $ip, $subnet, $gateway, $vlan, $mac, $mode, $type);
        $stmt->execute();
    }

    private function cleanup(): void
    {
        // The template prefix is a LIKE wildcard, so it is escaped before the pattern is built.
        $templatePattern = str_replace('_', '\\_', VIRTUSPHERE_TEMPLATE_PREFIX) . self::PREFIX . '%';
        $plainPattern = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ? OR mission_name LIKE ?');
        $stmt->bind_param('ss', $templatePattern, $plainPattern);
        $stmt->execute();

        $packages = $this->db->prepare('DELETE FROM deploy_packages WHERE package_name LIKE ?');
        $packagePattern = self::PREFIX . 'pkg_%';
        $packages->bind_param('s', $packagePattern);
        $packages->execute();
    }
}
