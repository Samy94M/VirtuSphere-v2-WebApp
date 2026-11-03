<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/log.php';
require_once dirname(__DIR__, 2) . '/lib/vm_save_service.php';

/**
 * AV-R12: saving a registered VM queues its assignments for the device-sync inside
 * the save's own transaction, unless the wanted operating system deviates from the
 * one MECM holds. Then only the explicit transfer button sends the change. Each
 * test states the MECM side it assumes: the owned rules are what the device-sync
 * reported, so an unreported assignment is a pending addition.
 */
final class VmSaveMecmTransferTest extends TestCase
{
    private mysqli $db;
    private string $prefix;
    private int $missionId;
    private int $userId;
    /** @var list<int> */
    private array $vmIds = [];
    /** @var array<string, int> */
    private array $packageIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->prefix = 'mecm-save-' . bin2hex(random_bytes(4));
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $this->userId);
        repo_execute($this->db, "INSERT INTO deploy_missions (mission_name,mission_status,hypervisor_datacenter,hypervisor_datastorage,wds_vlan) VALUES (?,'active','DC1','DS1','WDS')", 's', [$this->prefix]);
        $this->missionId = (int) $this->db->insert_id;
        $this->packageIds['P1'] = $this->package('P1');
        $this->packageIds['P2'] = $this->package('P2');
    }

    protected function tearDown(): void
    {
        if (!isset($this->missionId)) {
            return;
        }
        foreach ($this->vmIds as $vmId) {
            repo_execute($this->db, 'DELETE FROM deploy_logs WHERE object_type = ? AND object_id = ?', 'ss', ['vm', (string) $vmId]);
        }
        repo_execute($this->db, 'DELETE FROM deploy_missions WHERE id = ?', 'i', [$this->missionId]);
        foreach ($this->packageIds as $packageId) {
            repo_execute($this->db, 'DELETE FROM deploy_packages WHERE id = ?', 'i', [$packageId]);
        }
    }

    public function testAPackageChangeOnARegisteredVmQueuesTheTransferInTheSave(): void
    {
        $vmId = $this->registeredVm('OS-A', ['P1']);

        $saved = $this->save($vmId, ['packages' => [$this->packageIds['P1'], $this->packageIds['P2']]]);

        self::assertTrue($saved['mecm']['queued']);
        self::assertSame([$this->packageName('P2')], $saved['mecm']['added']);
        self::assertSame([], $saved['mecm']['removed']);
        self::assertSame(1, $this->updatedFlag($vmId));
        self::assertSame(1, $this->mecmRows($vmId, 'queued_mecm_transfer_on_save'), 'the queue has its own audit row');
    }

    public function testAPlainSaveSendsADifferenceLeftFromAnEarlierEdit(): void
    {
        $vmId = $this->registeredVm('OS-A', ['P1']);
        // Linked outside a save: the plan has a pending addition this save does not change.
        // Without the old transfer button, the next save is the only way it reaches MECM.
        $this->linkPackage($vmId, $this->packageIds['P2']);

        $saved = $this->save($vmId);

        self::assertTrue($saved['mecm']['queued']);
        self::assertSame([$this->packageName('P2')], $saved['mecm']['added']);
        self::assertFalse($saved['mecm']['held']);
        self::assertSame(1, $this->updatedFlag($vmId));
        self::assertSame(1, $this->mecmRows($vmId, 'queued_mecm_transfer_on_save'));
    }

    public function testASaveWithoutAnyDifferenceQueuesNothing(): void
    {
        $vmId = $this->registeredVm('OS-A', ['P1']);

        $saved = $this->save($vmId);

        self::assertFalse($saved['mecm']['queued']);
        self::assertFalse($saved['mecm']['held']);
        self::assertSame(0, $this->updatedFlag($vmId));
        self::assertSame(0, $this->mecmRows($vmId));
    }

    public function testADeviatingOsIsSavedInThePortalOnlyWithoutTheTransferButton(): void
    {
        $vmId = $this->registeredVm('OS-A', ['P1']);

        $saved = $this->save($vmId, ['vm_os' => 'OS-B']);

        self::assertFalse($saved['mecm']['queued']);
        self::assertTrue($saved['mecm']['held'], 'the save names the change MECM does not have yet');
        self::assertSame(0, $this->updatedFlag($vmId));
        self::assertSame(0, $this->mecmRows($vmId));
        self::assertSame('OS-B', (string) repo_scalar($this->db, 'SELECT vm_os FROM deploy_vms WHERE id = ?', 'i', [$vmId]), 'the portal keeps the value');
    }

    public function testThePortalOnlyChoiceHoldsADeviatingSaveBack(): void
    {
        $vmId = $this->registeredVm('OS-A', ['P1']);

        $saved = $this->save($vmId, ['vm_os' => 'OS-B'], VIRTUSPHERE_VM_MECM_SAVE_PORTAL_ONLY);

        self::assertFalse($saved['mecm']['queued']);
        self::assertSame(0, $this->updatedFlag($vmId));
    }

    public function testADeviatingOsWithTheTransferButtonQueuesTheWholeStateAndNamesTheRemoval(): void
    {
        $vmId = $this->registeredVm('OS-A', ['P1']);

        $saved = $this->save($vmId, ['vm_os' => 'OS-B'], VIRTUSPHERE_VM_MECM_SAVE_TRANSFER);

        self::assertTrue($saved['mecm']['queued']);
        self::assertSame(['OS-B'], $saved['mecm']['added']);
        self::assertSame(['OS-A'], $saved['mecm']['removed']);
        self::assertSame(1, $this->updatedFlag($vmId));
        self::assertSame(1, $this->mecmRows($vmId, 'queued_mecm_transfer_os_confirmed'));
    }

    public function testAnExplicitTransferSendsAStoredDeviationWithoutAnyChange(): void
    {
        $vmId = $this->registeredVm('OS-A', ['P1']);
        $this->save($vmId, ['vm_os' => 'OS-B'], VIRTUSPHERE_VM_MECM_SAVE_PORTAL_ONLY);
        self::assertSame(0, $this->updatedFlag($vmId));

        $saved = $this->save($vmId, [], VIRTUSPHERE_VM_MECM_SAVE_TRANSFER);

        self::assertTrue($saved['mecm']['queued']);
        self::assertSame(1, $this->updatedFlag($vmId));
    }

    public function testATemplateAndAnUnregisteredVmNeverQueue(): void
    {
        $registered = $this->registeredVm('OS-A', ['P1']);
        $template = $this->save($registered, ['packages' => [$this->packageIds['P1'], $this->packageIds['P2']]], '', true);
        self::assertFalse($template['mecm']['queued'], 'a template is never transferred');
        self::assertSame(0, $this->updatedFlag($registered));

        $unregistered = $this->vm('OS-A', ['P1']);
        $saved = $this->save($unregistered, ['packages' => [$this->packageIds['P1'], $this->packageIds['P2']]]);
        self::assertFalse($saved['mecm']['queued'], 'an unregistered VM travels with its first sync');
        self::assertSame(0, $this->updatedFlag($unregistered));
        self::assertSame(0, $this->mecmRows($unregistered));
    }

    public function testAFailedAuditRollsTheQueueBackWithTheSave(): void
    {
        $vmId = $this->registeredVm('OS-A', ['P1']);

        try {
            $this->save($vmId, ['packages' => [$this->packageIds['P1'], $this->packageIds['P2']]], '', false, 2147483647);
            self::fail('an unknown user must make the audit insert fail');
        } catch (mysqli_sql_exception) {
            // Expected: deploy_logs.user_id rejects the audit row.
        }

        self::assertSame(0, $this->updatedFlag($vmId));
        self::assertSame(1, (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_vm_packages WHERE vm_id = ?', 'i', [$vmId]), 'the package change rolled back too');
    }

    private function package(string $label): int
    {
        $name = $this->prefix . '-' . $label;
        $basename = $name;
        $version = '1.0';
        $status = VIRTUSPHERE_CATALOG_STATUS_DEFAULT;
        $stmt = $this->db->prepare('INSERT INTO deploy_packages (package_name, package_basename, package_version, package_status) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('ssss', $name, $basename, $version, $status);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function packageName(string $label): string
    {
        return $this->prefix . '-' . $label;
    }

    /** A VM as the editor creates it: never registered with MECM. */
    private function vm(string $os, array $packageLabels): int
    {
        // VM name 16 and NetBIOS hostname 15 characters at most: the random part of the prefix, not all of it.
        $name = 'ms' . substr($this->prefix, -8) . 'v' . count($this->vmIds);
        $packages = array_map(fn (string $label): array => ['id' => $this->packageIds[$label]], $packageLabels);
        $saved = vm_save_with_audit($this->db, $this->missionId, 0, [], false, ['vm_name' => $name, 'vm_hostname' => $name, 'vm_os' => $os], [], [], $packages, '', $this->userId);
        $this->vmIds[] = $saved['vm_id'];

        return $saved['vm_id'];
    }

    /**
     * A VM MECM holds: registered, with the rules the device-sync reported for its
     * OS, its packages and its mission collection. Nothing is pending.
     */
    private function registeredVm(string $os, array $packageLabels): int
    {
        $vmId = $this->vm($os, $packageLabels);
        repo_execute($this->db, "UPDATE deploy_vms SET mecm_sync_state = 'registered', updated = 0 WHERE id = ?", 'i', [$vmId]);
        $this->ownRule($vmId, 'os', $os);
        foreach ($packageLabels as $label) {
            $this->ownRule($vmId, 'package', $this->packageName($label));
        }
        $this->ownRule($vmId, 'mission', $this->prefix);

        return $vmId;
    }

    private function ownRule(int $vmId, string $type, string $name): void
    {
        $collectionId = 'C' . $vmId . '-' . substr(hash('sha256', $type . $name), 0, 6);
        repo_execute(
            $this->db,
            'INSERT INTO deploy_vm_mecm_rules (vm_id, collection_id, collection_name, collection_type, origin) VALUES (?, ?, ?, ?, ?)',
            'issss',
            [$vmId, $collectionId, $name, $type, VIRTUSPHERE_MECM_RULE_ORIGIN_CREATED]
        );
    }

    private function linkPackage(int $vmId, int $packageId): void
    {
        repo_execute($this->db, 'INSERT INTO deploy_vm_packages (vm_id, package_id) VALUES (?, ?)', 'ii', [$vmId, $packageId]);
    }

    /**
     * One editor save of an existing VM. $change may set vm_os and packages (package
     * ids); everything else is kept as stored, with the stored edit version.
     *
     * @param array<string, mixed> $change
     * @return array{vm_id: int, frozen_snapshot: string, mecm: array{queued: bool, held: bool, added: list<string>, removed: list<string>}}
     */
    private function save(int $vmId, array $change = [], string $mode = '', bool $isTemplate = false, ?int $userId = null): array
    {
        $before = repo_get_vm_bundle($this->db, $vmId);
        self::assertNotNull($before);
        $packageIds = $change['packages'] ?? array_map(static fn (array $row): int => (int) $row['id'], (array) $before['packages']);

        return vm_save_with_audit(
            $this->db,
            $this->missionId,
            $vmId,
            $before,
            $isTemplate,
            [
                'vm_name' => (string) $before['vm_name'],
                'vm_hostname' => (string) $before['vm_hostname'],
                'vm_os' => (string) ($change['vm_os'] ?? $before['vm_os']),
            ],
            [],
            [],
            array_map(static fn (int $id): array => ['id' => $id], $packageIds),
            (string) $before['edit_version'],
            $userId ?? $this->userId,
            $mode
        );
    }

    private function updatedFlag(int $vmId): int
    {
        return (int) repo_scalar($this->db, 'SELECT updated FROM deploy_vms WHERE id = ?', 'i', [$vmId]);
    }

    /** MECM queue audit rows of one VM, optionally of one action. */
    private function mecmRows(int $vmId, ?string $action = null): int
    {
        $sql = 'SELECT COUNT(*) FROM deploy_logs WHERE object_type = ? AND object_id = ? AND event_code = ?';
        $types = 'sss';
        $params = ['vm', (string) $vmId, VIRTUSPHERE_AUDIT_EVENT_VM_MECM_CHANGED];
        if ($action !== null) {
            $sql .= " AND JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.action')) = ?";
            $types .= 's';
            $params[] = $action;
        }

        return (int) repo_scalar($this->db, $sql, $types, $params);
    }
}
