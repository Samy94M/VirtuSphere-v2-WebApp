<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';
require_once dirname(__DIR__, 2) . '/lib/mission_transfer.php';

/**
 * A mission column has to be named in several places: the update, the checked
 * update, the clone, the template capture, the importer and the export field
 * list. Before ADR-0025 they were six hand-copied literals, so a new field could
 * be saved by the editor and silently dropped by the clone. They now share
 * REPO_MISSION_EDITABLE_COLUMNS / REPO_MISSION_COPYABLE_COLUMNS, and this test
 * pins every path that has to carry the autostart policy.
 *
 * It also pins the transfer format's tolerance: an export written before the
 * feature existed must still import, landing on the schema defaults, because
 * VIRTUSPHERE_MISSION_EXPORT_VERSION deliberately stays at 1.
 */
final class AutostartPropagationTest extends TestCase
{
    private const PREFIX = 'phpunit_autostart_';

    private ?mysqli $db = null;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testTheEditorSavesThePolicy(): void
    {
        $missionId = $this->makeMission(self::PREFIX . 'edit');

        repo_update_mission_checked($this->db, $missionId, [
            'autostart_enabled' => '1',
            'autostart_start_delay' => '45',
            'autostart_stop_delay' => '0',
            'autostart_stop_action' => 'powerOff',
            'autostart_wait_for_heartbeat' => '1',
        ], '', false);

        $mission = repo_get_mission($this->db, $missionId);
        self::assertSame(1, (int) $mission['autostart_enabled']);
        self::assertSame(45, (int) $mission['autostart_start_delay']);
        // 0 is a legal stop delay ("no wait"), not an empty value.
        self::assertSame(0, (int) $mission['autostart_stop_delay']);
        self::assertSame('powerOff', $mission['autostart_stop_action']);
        self::assertSame(1, (int) $mission['autostart_wait_for_heartbeat']);
    }

    public function testAnInvalidStopActionIsRejectedRatherThanLowerCased(): void
    {
        // Validator::enum() would lower-case this into a value ESXi does not know.
        $missionId = $this->makeMission(self::PREFIX . 'enum');

        $this->expectException(ValidationException::class);
        repo_update_mission_checked($this->db, $missionId, ['autostart_stop_action' => 'guestshutdown'], '', false);
    }

    public function testOutOfRangeMissionDelayIsRejected(): void
    {
        $missionId = $this->makeMission(self::PREFIX . 'range');

        $this->expectException(ValidationException::class);
        // A mission default has nothing to inherit from, so -1 is out of range.
        repo_update_mission_checked($this->db, $missionId, ['autostart_start_delay' => '-1'], '', false);
    }

    public function testTemplateCloneCarriesThePolicyIntoTheNewMission(): void
    {
        $templateId = $this->makeMission(VIRTUSPHERE_TEMPLATE_PREFIX . self::PREFIX . 'tpl');
        repo_update_mission_checked($this->db, $templateId, [
            'autostart_enabled' => '1',
            'autostart_start_delay' => '30',
            'autostart_stop_action' => 'suspend',
        ], '', false);
        $this->makeVm($templateId, 'PHPUNITAS1', ['autostart_enabled' => '1', 'autostart_start_delay' => '7']);

        $result = repo_clone_template_to_new_mission($this->db, $templateId, self::PREFIX . 'clone', 1);
        $clone = repo_get_mission($this->db, (int) $result['target_mission_id']);

        self::assertSame(1, (int) $clone['autostart_enabled']);
        self::assertSame(30, (int) $clone['autostart_start_delay']);
        self::assertSame('suspend', $clone['autostart_stop_action']);

        $vms = getVMs($this->db, (int) $clone['id']);
        self::assertSame(1, (int) $vms[0]['autostart_enabled']);
        self::assertSame(7, (int) $vms[0]['autostart_start_delay']);
        // The untouched delay inherits, and inheritance survives the clone.
        self::assertSame(VIRTUSPHERE_AUTOSTART_DELAY_INHERIT, (int) $vms[0]['autostart_stop_delay']);
    }

    public function testSaveAsTemplateCarriesThePolicy(): void
    {
        $missionId = $this->makeMission(self::PREFIX . 'src');
        repo_update_mission_checked($this->db, $missionId, ['autostart_enabled' => '1', 'autostart_stop_delay' => '15'], '', false);

        $result = repo_save_mission_as_template($this->db, $missionId, VIRTUSPHERE_TEMPLATE_PREFIX . self::PREFIX . 'captured', 1);
        $template = repo_get_mission($this->db, (int) $result['target_mission_id']);

        self::assertSame(1, (int) $template['autostart_enabled']);
        self::assertSame(15, (int) $template['autostart_stop_delay']);
    }

    public function testExportImportRoundTripCarriesThePolicy(): void
    {
        $missionId = $this->makeMission(self::PREFIX . 'exp');
        repo_update_mission_checked($this->db, $missionId, ['autostart_enabled' => '1', 'autostart_start_delay' => '90'], '', false);
        $this->makeVm($missionId, 'PHPUNITAS2', ['autostart_enabled' => '1', 'autostart_stop_delay' => '0']);

        $payload = mission_export_payload($this->db, $missionId);
        // The format version must NOT be bumped: the check is an equality, so a
        // bump would make every file already on disk unimportable.
        self::assertSame(1, $payload['format_version']);
        self::assertSame('1', $payload['mission']['autostart_enabled']);
        self::assertSame('90', $payload['mission']['autostart_start_delay']);

        deleteMission($missionId, $this->db);
        $report = mission_import($this->db, $payload, self::PREFIX . 'imp', false, 1);
        $imported = repo_get_mission($this->db, (int) $report['mission_id']);

        self::assertSame(1, (int) $imported['autostart_enabled']);
        self::assertSame(90, (int) $imported['autostart_start_delay']);

        $vms = getVMs($this->db, (int) $imported['id']);
        self::assertSame(1, (int) $vms[0]['autostart_enabled']);
        self::assertSame(0, (int) $vms[0]['autostart_stop_delay']);
        self::assertSame(VIRTUSPHERE_AUTOSTART_DELAY_INHERIT, (int) $vms[0]['autostart_start_delay']);
    }

    public function testAnExportFromBeforeTheFeatureStillImports(): void
    {
        $missionId = $this->makeMission(self::PREFIX . 'old');
        $this->makeVm($missionId, 'PHPUNITAS3', []);
        $payload = mission_export_payload($this->db, $missionId);
        deleteMission($missionId, $this->db);

        // Strip every autostart key, exactly as a v1 file written before ADR-0025.
        foreach (REPO_MISSION_AUTOSTART_COLUMNS as $column) {
            unset($payload['mission'][$column]);
        }
        foreach (array_keys($payload['vms']) as $index) {
            unset(
                $payload['vms'][$index]['autostart_enabled'],
                $payload['vms'][$index]['autostart_start_delay'],
                $payload['vms'][$index]['autostart_stop_delay']
            );
        }

        $report = mission_import($this->db, $payload, self::PREFIX . 'oldimp', false, 1);
        self::assertTrue($report['imported']);

        $imported = repo_get_mission($this->db, (int) $report['mission_id']);
        // Schema defaults, not '' into an INT NOT NULL column.
        self::assertSame(0, (int) $imported['autostart_enabled']);
        self::assertSame(VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT, (int) $imported['autostart_start_delay']);
        self::assertSame('guestShutdown', $imported['autostart_stop_action']);

        $vms = getVMs($this->db, (int) $imported['id']);
        self::assertSame(0, (int) $vms[0]['autostart_enabled']);
        self::assertSame(VIRTUSPHERE_AUTOSTART_DELAY_INHERIT, (int) $vms[0]['autostart_start_delay']);
    }

    public function testABlankVmDelayIsStoredAsInheritNotZero(): void
    {
        $missionId = $this->makeMission(self::PREFIX . 'blank');
        // What the editor posts when the operator clears the field.
        $this->makeVm($missionId, 'PHPUNITAS4', ['autostart_enabled' => '1', 'autostart_start_delay' => '', 'autostart_stop_delay' => '0']);

        $vms = getVMs($this->db, $missionId);
        self::assertSame(VIRTUSPHERE_AUTOSTART_DELAY_INHERIT, (int) $vms[0]['autostart_start_delay']);
        self::assertSame(0, (int) $vms[0]['autostart_stop_delay']);
    }

    private function makeMission(string $name): int
    {
        return repo_create_mission($this->db, [
            'mission_name' => $name,
            'hypervisor_datastorage' => 'ds1',
            'hypervisor_datacenter' => 'DC1',
            'domain' => 'dc.example.com',
        ], true);
    }

    private function makeVm(int $missionId, string $name, array $autostart): void
    {
        repo_save_vm(
            $this->db,
            $missionId,
            null,
            $autostart + [
                'vm_name' => $name,
                'vm_hostname' => $name,
                'vm_os' => 'Windows Server 2019',
                'vm_domain' => 'dc.example.com',
                'vm_guest_id' => 'windows2019srv_64Guest',
            ],
            [['ip' => '10.0.0.9', 'subnet' => '255.255.255.0', 'gateway' => '10.0.0.1', 'mode' => 'static', 'type' => 'vmxnet3', 'vlan' => '']],
            [['disk_name' => 'System', 'disk_size' => 40, 'disk_type' => 'thick']],
            [],
            '',
            1
        );
    }

    private function cleanup(): void
    {
        $like = self::PREFIX . '%';
        $templateLike = VIRTUSPHERE_TEMPLATE_PREFIX . self::PREFIX . '%';
        foreach ([$like, $templateLike] as $pattern) {
            $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
            $stmt->bind_param('s', $pattern);
            $stmt->execute();
        }
    }
}
