<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/missions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';

/**
 * H2b-4 (TS1-K3): the VM name may be one character longer than a Windows
 * hostname, and both limits stay. With the hostname field empty the VM name
 * stands in; when it is too long for that, the refusal says so and names the
 * field to fill, instead of the generic hostname rule for a value the operator
 * never typed.
 */
final class VmHostnameFromVmNameTest extends TestCase
{
    private const PREFIX = 'phpunit_hostname_from_name_';

    private ?mysqli $db = null;
    private int $missionId = 0;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
        $this->missionId = repo_create_mission($this->db, ['mission_name' => self::PREFIX . 'mission']);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
    }

    public function testAVmNameTooLongForAHostnameAsksForAHostname(): void
    {
        $name = str_repeat('a', (int) VIRTUSPHERE_VM_LIMITS['vm_name_max_length']);
        $errors = $this->errors(['vm_name' => $name, 'vm_hostname' => '']);

        self::assertArrayNotHasKey('vm_name', $errors, 'the VM name itself is valid: the limits stay apart');
        self::assertSame($this->tooLong(), $errors['vm_hostname'] ?? null);
    }

    /** The new sentence, as the active catalog (or the English fallback) renders it. */
    private function tooLong(): string
    {
        $text = validator_text('validate.vm_hostname_from_vm_name_too_long', 'The VM name is too long for a Windows hostname; enter a Windows hostname.');
        self::assertNotSame('', $text);

        return $text;
    }

    public function testATypedHostnameKeepsTheHostnameRule(): void
    {
        $name = str_repeat('a', (int) VIRTUSPHERE_VM_LIMITS['vm_name_max_length']);
        $errors = $this->errors(['vm_name' => $name, 'vm_hostname' => $name]);

        self::assertArrayHasKey('vm_hostname', $errors);
        self::assertNotSame(
            $this->tooLong(),
            $errors['vm_hostname'],
            'a typed value is judged by the hostname rule, not described as the VM name'
        );
    }

    public function testAnInvalidVmNameKeepsItsOwnMessage(): void
    {
        // The name fails on its own; the stand-in does not claim "too long" for it.
        $errors = $this->errors(['vm_name' => 'bad_name', 'vm_hostname' => '']);

        self::assertArrayHasKey('vm_name', $errors);
        self::assertNotSame($this->tooLong(), $errors['vm_hostname'] ?? '');
    }

    public function testAShortVmNameStandsInWithoutAnError(): void
    {
        $values = repo_validate_vm_payload($this->db, $this->missionId, $this->payload(['vm_name' => 'phpunithost1', 'vm_hostname' => '']));

        self::assertSame('phpunithost1', $values['vm_hostname']);
    }

    /** @return array<string,string> */
    private function errors(array $overrides): array
    {
        try {
            repo_validate_vm_payload($this->db, $this->missionId, $this->payload($overrides));
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
        self::fail('The payload was expected to be refused.');
    }

    /** @return array<string,mixed> */
    private function payload(array $overrides): array
    {
        return $overrides + [
            'vm_domain' => 'phpunit.example.local',
            'vm_os' => 'phpunit-os',
            'vm_ram' => '2048',
            'vm_cpu' => '2',
            'vm_guest_id' => VIRTUSPHERE_VM_DEFAULTS['guest_id'],
        ];
    }

    private function cleanup(): void
    {
        $pattern = self::PREFIX . '%';
        $stmt = $this->db->prepare('DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)');
        $stmt->bind_param('s', $pattern);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
        $stmt->bind_param('s', $pattern);
        $stmt->execute();
    }
}
