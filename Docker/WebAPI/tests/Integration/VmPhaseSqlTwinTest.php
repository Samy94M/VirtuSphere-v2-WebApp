<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/repo/client_events.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vm_evidence.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase.php';

/**
 * The SQL twin virtusphere_vm_phase_sql() must rank every stored VM the way the
 * PHP rule does, and it must order a prepared query by the same ranks. The
 * fixture holds every lifecycle x MECM x binding combination the state machine
 * can store, plus two installed VMs whose client evidence decides rank 7 or 6.
 */
final class VmPhaseSqlTwinTest extends TestCase
{
    private const PREFIX = 'phpunit_vmphase_';
    private const MAC = '02:00:00:00:A7:01';

    private mysqli $db;
    private int $missionId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }

        $this->cleanup();
        $this->missionId = repo_create_mission($this->db, ['mission_name' => self::PREFIX . bin2hex(random_bytes(3))]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    public function testTheTwinRanksEveryStoredVmLikeThePhpRule(): void
    {
        $this->seedEveryCombination();
        $this->seedInstalledClients();

        $vms = $this->vmRows();
        $ranks = $this->twinRanks();
        self::assertCount(count($vms), $ranks, 'every VM of the mission was ranked by the twin');

        $evidence = repo_vm_evidence($this->db, $vms);
        $reached = [];
        foreach ($vms as $vm) {
            $id = (int) $vm['id'];
            $expected = virtusphere_vm_phase_rank(virtusphere_vm_phase($vm, repo_vm_evidence_for_vm($evidence, $vm, false)));
            self::assertSame($expected, $ranks[$id], 'VM ' . $vm['vm_name'] . ' ranks differently in SQL and PHP');
            $reached[$expected] = true;
        }

        ksort($reached);
        self::assertSame(range(1, 7), array_keys($reached), 'the fixture reaches every ranked phase');
    }

    public function testTheTwinSortsAPreparedQueryFromTheHighestRankDown(): void
    {
        $this->seedEveryCombination();
        $this->seedInstalledClients();
        $twin = virtusphere_vm_phase_sql('v');

        $stmt = $this->db->prepare(
            'SELECT v.id, ' . $twin . ' AS phase_rank FROM deploy_vms v WHERE v.mission_id = ? ORDER BY ' . $twin . ' DESC, v.id'
        );
        $stmt->bind_param('i', $this->missionId);
        $stmt->execute();
        $ranks = array_map(
            static fn (array $row): int => (int) $row['phase_rank'],
            repo_fetch_all($stmt->get_result())
        );

        self::assertNotSame([], $ranks);
        $sorted = $ranks;
        rsort($sorted);
        self::assertSame($sorted, $ranks, 'the twin orders the prepared query by rank, highest first');
        self::assertSame(7, $ranks[0], 'the installed VM with all four client phases finished comes first');
    }

    private function seedEveryCombination(): void
    {
        $index = 0;
        foreach (VIRTUSPHERE_LIFECYCLE_STATES as $lifecycle) {
            foreach (VIRTUSPHERE_MECM_SYNC_STATES as $mecm) {
                foreach ([true, false] as $bound) {
                    $index++;
                    // An unbound VM stores NULL or the empty string; the twin and the PHP predicate both read both as unbound.
                    $uuid = $bound ? sprintf('42422d12-0000-4000-8000-%012d', $index) : ($index % 2 === 0 ? null : '');
                    $this->insertVm($index, $lifecycle, $mecm, $uuid);
                }
            }
        }
    }

    private function seedInstalledClients(): void
    {
        $clean = $this->insertVm(900, VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_REGISTERED, 'uuid-clean');
        foreach (VIRTUSPHERE_CLIENT_PHASES as $phase) {
            repo_record_client_event($this->db, $clean, self::MAC, $phase, VIRTUSPHERE_CLIENT_EVENT_FINISHED, null, '127.0.0.1');
        }

        // Three phases finished. The fourth finished and then failed, so its latest event is no finish.
        $flaky = $this->insertVm(901, VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, VIRTUSPHERE_MECM_SYNC_REGISTERED, 'uuid-flaky');
        $phases = VIRTUSPHERE_CLIENT_PHASES;
        foreach (array_slice($phases, 0, 3) as $phase) {
            repo_record_client_event($this->db, $flaky, self::MAC, $phase, VIRTUSPHERE_CLIENT_EVENT_FINISHED, null, '127.0.0.1');
        }
        $last = $phases[3];
        repo_record_client_event($this->db, $flaky, self::MAC, $last, VIRTUSPHERE_CLIENT_EVENT_FINISHED, null, '127.0.0.1');
        repo_record_client_event($this->db, $flaky, self::MAC, $last, VIRTUSPHERE_CLIENT_EVENT_FAILED, 'synthetic failure', '127.0.0.1');
    }

    private function insertVm(int $index, string $lifecycle, string $mecm, ?string $uuid): int
    {
        $name = sprintf('PHT%03d', $index);
        repo_execute(
            $this->db,
            'INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state, vm_instance_uuid) VALUES (?, ?, ?, ?, ?, ?)',
            'isssss',
            [$this->missionId, $name, $name, $lifecycle, $mecm, $uuid]
        );

        return (int) $this->db->insert_id;
    }

    /** @return list<array<string,mixed>> */
    private function vmRows(): array
    {
        $stmt = $this->db->prepare('SELECT v.* FROM deploy_vms v WHERE v.mission_id = ? ORDER BY v.id');
        $stmt->bind_param('i', $this->missionId);
        $stmt->execute();

        return repo_fetch_all($stmt->get_result());
    }

    /** @return array<int,int> vm id => rank computed by the SQL twin */
    private function twinRanks(): array
    {
        $stmt = $this->db->prepare(
            'SELECT v.id, ' . virtusphere_vm_phase_sql('v') . ' AS phase_rank FROM deploy_vms v WHERE v.mission_id = ?'
        );
        $stmt->bind_param('i', $this->missionId);
        $stmt->execute();

        $ranks = [];
        foreach (repo_fetch_all($stmt->get_result()) as $row) {
            $ranks[(int) $row['id']] = (int) $row['phase_rank'];
        }

        return $ranks;
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
        $stmt->bind_param('s', $like);
        $stmt->execute();
    }
}
