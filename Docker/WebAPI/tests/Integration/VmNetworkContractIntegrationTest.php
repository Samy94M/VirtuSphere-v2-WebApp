<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_db_channel.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_worker_network_preflight.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_blockers.php';

final class VmNetworkContractIntegrationTest extends TestCase
{
    private mysqli $db;
    private ?mysqli $second = null;
    private string $prefix;
    private int $missionId;
    private int $vmId;
    private int $userId;
    private int $esxiId;
    private int $ansibleId;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
            $this->second = new mysqli(
                envboot_required('DB_HOST'),
                envboot_required('DB_USER'),
                envboot_required('DB_PASS'),
                envboot_required('DB_NAME'),
                (int) envboot_optional('DB_PORT', '3306')
            );
            $this->second->set_charset('utf8mb4');
            $this->second->query("SET time_zone = '+00:00'");
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->prefix = 'phpunit_net14a_' . bin2hex(random_bytes(4));
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $this->userId, 'the integration fixture needs the seeded user');
        $this->esxiId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443);
        $this->ansibleId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22);

        $name = $this->prefix . '_mission';
        $active = 'active';
        $dc = 'DC1';
        $ds = 'DS1';
        $wds = 'WDS';
        $stmt = $this->db->prepare(
            'INSERT INTO deploy_missions (mission_name, mission_status, hypervisor_datacenter, hypervisor_datastorage, wds_vlan) '
            . 'VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('sssss', $name, $active, $dc, $ds, $wds);
        $stmt->execute();
        $this->missionId = (int) $this->db->insert_id;
        $this->vmId = $this->insertVm('VM1', '');
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE id = ?');
        $stmt->bind_param('i', $this->missionId);
        $stmt->execute();
        foreach ([$this->esxiId, $this->ansibleId] as $credentialId) {
            $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE id = ?');
            $stmt->bind_param('i', $credentialId);
            $stmt->execute();
        }
        if ($this->second !== null) {
            $this->second->close();
            $this->second = null;
        }
    }

    public function testHardModesRejectBeforeAnyQueueWriteWhileStartModesWarnAndQueue(): void
    {
        $blocked = [];
        foreach (deploy_modes_with_hard_network_gate() as $mode) {
            try {
                repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => $mode]);
                self::fail($mode . ' must reject the empty VLAN before a queue row exists');
            } catch (VmNetworkPreflightException $exception) {
                self::assertContains(VIRTUSPHERE_VM_NETWORK_EMPTY, array_column($exception->findings, 'code'), $mode);
                $blocked[] = $mode;
            }
            self::assertSame(0, $this->jobCount(), $mode . ' left a partial queue row');
        }
        self::assertNotEmpty($blocked, 'the derived hard-mode set must not silently become empty');

        foreach (['start', VIRTUSPHERE_DEPLOY_MODE_AUTOSTART] as $mode) {
            $preflight = repo_vm_network_preflight($this->db, $this->missionId, [], 'WDS');
            $warnings = repo_vm_network_preflight_warnings($preflight, $mode);
            self::assertContains(VIRTUSPHERE_VM_NETWORK_EMPTY, array_column($warnings, 'code'), $mode);
            self::assertContains(VIRTUSPHERE_WDS_PORTAL_MISSING, array_column($warnings, 'code'), $mode);
            $jobId = repo_create_deploy_job($this->db, $this->missionId, $this->userId, $this->esxiId, $this->ansibleId, ['mode' => $mode]);
            self::assertGreaterThan(0, $jobId);
            $stmt = $this->db->prepare('DELETE FROM deploy_jobs WHERE id = ?');
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
        }
    }

    public function testStaggeredGroupIsAtomicWhenOneVmIsInvalid(): void
    {
        $validVm = $this->insertVm('VM2', 'WDS');
        self::assertGreaterThan(0, $validVm);

        try {
            repo_enqueue_deploy_group(
                $this->db,
                $this->missionId,
                $this->userId,
                $this->esxiId,
                $this->ansibleId,
                ['mode' => VIRTUSPHERE_DEPLOY_MODE_FULL, 'vm_ids' => [$this->vmId, $validVm]],
                gmdate('Y-m-d H:i:s', time() + 3600),
                10
            );
            self::fail('one invalid stagger member must reject the whole group');
        } catch (VmNetworkPreflightException $exception) {
            self::assertContains($this->vmId, array_column($exception->findings, 'vm_id'));
        }
        self::assertSame(0, $this->jobCount(), 'overall and per-VM jobs share one rollback');
    }

    public function testUnchangedInvalidLegacyBundleIsGrandfatheredButAnyChangeMustRepairIt(): void
    {
        $current = repo_vm_network_scope($this->db, $this->missionId, [$this->vmId])[0]['interfaces'];
        self::assertNotEmpty($current, 'the invalid legacy fixture must materially exist');

        repo_transaction($this->db, function () use ($current): void {
            repo_fetch_one($this->db, 'SELECT id FROM deploy_missions WHERE id = ? FOR UPDATE', 'i', [$this->missionId]);
            repo_vm_network_assert_bundle_write_allowed($this->db, $this->missionId, $this->vmId, $this->prefix . '_VM1', $current);
        });
        self::addToAssertionCount(1);

        $changed = $current;
        $changed[0]['ip'] = '10.0.0.44';
        try {
            repo_transaction($this->db, function () use ($changed): void {
                repo_fetch_one($this->db, 'SELECT id FROM deploy_missions WHERE id = ? FOR UPDATE', 'i', [$this->missionId]);
                repo_vm_network_assert_bundle_write_allowed($this->db, $this->missionId, $this->vmId, $this->prefix . '_VM1', $changed);
            });
            self::fail('an unrelated edit cannot carry an invalid VLAN bundle forward');
        } catch (ValidationException $exception) {
            self::assertSame(['interfaces.0.vlan'], array_keys($exception->errors()));
        }
    }

    public function testPreserveWriterRejectsAnIncomingMacAsAuthority(): void
    {
        $storedMac = '02:50:56:aa:bb:01';
        $incomingMac = '02:50:56:aa:bb:02';
        repo_execute(
            $this->db,
            'UPDATE deploy_interfaces SET vlan = ?, mac = ? WHERE vm_id = ?',
            'ssi',
            ['WDS', $storedMac, $this->vmId]
        );
        $interfaces = repo_vm_network_scope($this->db, $this->missionId, [$this->vmId])[0]['interfaces'];
        self::assertCount(1, $interfaces);
        $interfaces[0]['mac'] = $incomingMac;

        repo_transaction($this->db, function () use ($interfaces): void {
            repo_replace_interfaces($this->db, $this->vmId, $interfaces, true);
        });

        self::assertSame(
            $storedMac,
            repo_scalar($this->db, 'SELECT mac FROM deploy_interfaces WHERE vm_id = ? LIMIT 1', 'i', [$this->vmId]),
            'an interface writer must preserve the callback-owned stored MAC even when its input carries another valid MAC'
        );
    }

    public function testRunningScopeRejectsWriterRaceBeforeItsNetworkRowsChange(): void
    {
        $jobId = repo_create_deploy_job(
            $this->db,
            $this->missionId,
            $this->userId,
            $this->esxiId,
            $this->ansibleId,
            ['mode' => 'start']
        );
        repo_execute($this->db, 'UPDATE deploy_jobs SET status = ? WHERE id = ?', 'si', [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $jobId]);
        $current = repo_vm_network_scope($this->db, $this->missionId, [$this->vmId])[0]['interfaces'];
        $current[0]['vlan'] = 'WDS';

        try {
            repo_transaction($this->db, function () use ($current): void {
                repo_fetch_one($this->db, 'SELECT id FROM deploy_missions WHERE id = ? FOR UPDATE', 'i', [$this->missionId]);
                repo_vm_network_assert_bundle_write_allowed($this->db, $this->missionId, $this->vmId, $this->prefix . '_VM1', $current);
            });
            self::fail('a running job owns its selected VM network scope');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('owns this VM network scope', $exception->getMessage());
        }
        self::assertSame('', (string) repo_scalar($this->db, 'SELECT vlan FROM deploy_interfaces WHERE vm_id = ? LIMIT 1', 'i', [$this->vmId]));
    }

    public function testMissionWdsChangeIsAllowedWhileQueuedAndBlockedAfterClaim(): void
    {
        $jobId = repo_create_deploy_job(
            $this->db,
            $this->missionId,
            $this->userId,
            $this->esxiId,
            $this->ansibleId,
            ['mode' => 'start']
        );
        repo_update_mission_checked($this->db, $this->missionId, ['wds_vlan' => 'PXE-QUEUED'], '');
        self::assertSame('PXE-QUEUED', repo_scalar($this->db, 'SELECT wds_vlan FROM deploy_missions WHERE id = ?', 'i', [$this->missionId]));

        try {
            repo_transaction($this->db, function () use ($jobId): void {
                // A pins an old consistent snapshot. B then performs the queued
                // -> running claim CAS and commits on a real second connection
                // while A's transaction stays open. The actual writer below
                // obtains Mission -> Job locks only after that claim, matching
                // the viable overlap (holding Mission first makes B wait on the
                // deploy_jobs foreign-key check by design).
                repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_users');
                self::assertNotNull($this->second);
                $this->second->begin_transaction();
                try {
                    repo_execute(
                        $this->second,
                        'UPDATE deploy_jobs SET status = ? WHERE id = ? AND status = ?',
                        'sis',
                        [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $jobId, VIRTUSPHERE_DEPLOY_STATUS_QUEUED]
                    );
                    $this->second->commit();
                    self::assertSame(
                        VIRTUSPHERE_DEPLOY_STATUS_RUNNING,
                        repo_scalar($this->second, 'SELECT status FROM deploy_jobs WHERE id = ?', 'i', [$jobId]),
                        'the second connection must win the claim boundary'
                    );
                } catch (Throwable $exception) {
                    $this->second->rollback();
                    throw $exception;
                }

                // Nested repo_transaction() joins A. Its Mission and active-job
                // locking reads must see B's commit despite A's old snapshot.
                repo_update_mission_checked($this->db, $this->missionId, ['wds_vlan' => 'PXE-RACING'], '');
            });
            self::fail('a running job must fence the mission WDS writer');
        } catch (VmNetworkScopeActiveException $exception) {
            self::assertSame($jobId, $exception->jobId);
        }
        self::assertSame('PXE-QUEUED', repo_scalar($this->db, 'SELECT wds_vlan FROM deploy_missions WHERE id = ?', 'i', [$this->missionId]));
    }

    /**
     * Correction plan 14.8: the per-job scope cap is a QUEUE decision, and it
     * is exact at its boundary in both directions. One VM over the cap must
     * leave no row behind; exactly at the cap the job is queued like any other,
     * because a limit that also refuses its own maximum is a limit nobody can
     * plan against.
     */
    public function testTheJobScopeCapIsExactAtItsBoundaryAndLeavesNoQueueRow(): void
    {
        // The fixture VM has an empty VLAN, which is its own blocker; `start`
        // only warns about that, so the scope cap is the one thing under test.
        repo_execute($this->db, 'UPDATE deploy_interfaces SET vlan = ? WHERE vm_id = ?', 'si', ['WDS', $this->vmId]);
        $vmIds = [$this->vmId];
        for ($index = 2; $index <= VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_VMS; $index++) {
            $vmIds[] = $this->insertVm('SCOPE' . $index, 'WDS');
        }
        self::assertCount(VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_VMS, $vmIds);

        $atLimit = repo_create_deploy_job(
            $this->db,
            $this->missionId,
            $this->userId,
            $this->esxiId,
            $this->ansibleId,
            ['mode' => 'start', 'vm_ids' => $vmIds]
        );
        self::assertGreaterThan(0, $atLimit, 'exactly the cap must queue');
        repo_execute($this->db, 'DELETE FROM deploy_jobs WHERE id = ?', 'i', [$atLimit]);

        $vmIds[] = $this->insertVm('SCOPEOVER', 'WDS');
        try {
            repo_create_deploy_job(
                $this->db,
                $this->missionId,
                $this->userId,
                $this->esxiId,
                $this->ansibleId,
                ['mode' => 'start', 'vm_ids' => $vmIds]
            );
            self::fail('one VM over the cap must be refused before a queue row exists');
        } catch (ValidationException $exception) {
            self::assertSame(['vm_ids'], array_keys($exception->errors()));
        }
        self::assertSame(0, $this->jobCount(), 'the refused scope left a partial queue row');

        // And the same list is visible as a queue blocker, not only as an
        // exception after the submit.
        $blockers = deploy_queue_blockers($this->db, [
            'mission_id' => $this->missionId,
            'mode' => 'start',
            'credential_esxi_id' => $this->esxiId,
            'credential_ansible_id' => $this->ansibleId,
            'vm_ids' => $vmIds,
        ]);
        self::assertContains('job_scope_limit', array_column($blockers, 'code'));

        // The operator has to be able to ACT on it, so the check goes all the
        // way to the rendered block: the sentence must name the limit and the
        // number actually selected, and it must carry the link that leads to
        // the list where the selection is made. A blocker that only exists in
        // an array is a blocker nobody ever reads.
        $scopeBlocker = array_values(array_filter(
            $blockers,
            static fn (array $blocker): bool => (string) $blocker['code'] === 'job_scope_limit'
        ))[0];
        self::assertStringContainsString((string) VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_VMS, (string) $scopeBlocker['message']);
        self::assertStringContainsString((string) count($vmIds), (string) $scopeBlocker['message']);
        self::assertSame('vms.php?mission_id=' . $this->missionId, (string) $scopeBlocker['action']['url']);

        // The second follow-up answers the question the sentence cannot: why the
        // ceiling exists at all. Built with help_url(), never hand-written.
        self::assertSame(help_url('deploy', 'help-network-contract'), (string) $scopeBlocker['help']['url']);
        self::assertSame(__t('deploy.blocker_help_network_contract'), (string) $scopeBlocker['help']['label']);

        ob_start();
        deploy_render_blockers($blockers, ['id' => $this->userId, 'role' => 'admin'], []);
        $html = (string) ob_get_clean();
        self::assertStringContainsString(htmlspecialchars((string) $scopeBlocker['message'], ENT_QUOTES), $html);
        self::assertStringContainsString('vms.php?mission_id=' . $this->missionId, $html);
        // Both links sit in ONE .alert-actions row with the middle dot between
        // them. Two links separated only by a space read as one long link, and
        // this blocker is the first place in the deploy list that has two.
        self::assertMatchesRegularExpression(
            '#<div class="alert-actions">\s*<a href="vms\.php\?mission_id=' . $this->missionId . '">[^<]+</a>'
            . '\s*<span class="muted" aria-hidden="true">&middot;</span>'
            . '\s*<a href="[^"]*help-network-contract" data-deploy-blocker-help>#',
            $html
        );
        // And the live client must produce the same shape, or the row silently
        // loses its second link on the first refresh.
        $json = deploy_blocker_json($scopeBlocker, ['id' => $this->userId, 'role' => 'admin']);
        self::assertSame($scopeBlocker['help'], $json['help']);
    }

    public function testAnInterfaceCountOverTheCapBlocksTheSameWay(): void
    {
        repo_execute($this->db, 'UPDATE deploy_interfaces SET vlan = ? WHERE vm_id = ?', 'si', ['WDS', $this->vmId]);
        $empty = '';
        for ($index = 1; $index <= VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_INTERFACES_PER_VM; $index++) {
            $vlan = 'VLAN' . $index;
            $stmt = $this->db->prepare('INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('isssss', $this->vmId, $empty, $empty, $empty, $vlan, $empty);
            $stmt->execute();
        }
        // One WDS interface plus MAX distinct extras is MAX+1 rows.
        try {
            repo_create_deploy_job(
                $this->db,
                $this->missionId,
                $this->userId,
                $this->esxiId,
                $this->ansibleId,
                ['mode' => 'start', 'vm_ids' => [$this->vmId]]
            );
            self::fail('a VM over the interface cap must be refused before a queue row exists');
        } catch (ValidationException $exception) {
            self::assertSame(['vm_ids'], array_keys($exception->errors()));
        }
        self::assertSame(0, $this->jobCount());
    }

    private function insertCredential(string $type, int $port): int
    {
        $name = $this->prefix . '_' . $type;
        $host = $type . '.example.invalid';
        $user = 'svc';
        $secret = 'ciphertext';
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssisss', $type, $name, $host, $port, $user, $secret);
        $stmt->execute();
        return (int) $this->db->insert_id;
    }

    private function insertVm(string $suffix, string $vlan): int
    {
        $name = strtoupper($this->prefix . '_' . $suffix);
        $stmt = $this->db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $this->missionId, $name, $name);
        $stmt->execute();
        $vmId = (int) $this->db->insert_id;
        $empty = '';
        $stmt = $this->db->prepare('INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssss', $vmId, $empty, $empty, $empty, $vlan, $empty);
        $stmt->execute();
        return $vmId;
    }

    private function jobCount(): int
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_jobs WHERE mission_id = ?', 'i', [$this->missionId]);
    }
}
