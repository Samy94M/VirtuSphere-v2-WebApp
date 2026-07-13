<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/vms.php';

/**
 * The vNIC type is an enum (VIRTUSPHERE_INTERFACE_TYPES): vmxnet3, e1000, e1000e.
 * community.vmware.vmware_guest hard-fails on any other device_type, so the repo
 * validator rejects it at the input boundary (portal, import and legacy API) rather
 * than letting it reach ESXi. repo_validate_interfaces() is pure, so no DB here.
 */
final class InterfaceTypeValidationTest extends TestCase
{
    private function iface(array $overrides = []): array
    {
        return array_merge(['mode' => 'dhcp', 'vlan' => 'VLAN10', 'type' => 'vmxnet3'], $overrides);
    }

    public function testAcceptedTypesPass(): void
    {
        foreach (['vmxnet3', 'e1000', 'e1000e'] as $type) {
            $rows = repo_validate_interfaces([$this->iface(['type' => $type])]);
            self::assertSame($type, $rows[0]['type']);
        }
    }

    public function testTypeIsCaseInsensitiveAndCanonicalized(): void
    {
        $rows = repo_validate_interfaces([$this->iface(['type' => 'E1000E'])]);
        self::assertSame('e1000e', $rows[0]['type']);
    }

    public function testEmptyTypeDefaultsToVmxnet3(): void
    {
        $rows = repo_validate_interfaces([$this->iface(['type' => ''])]);
        self::assertSame('vmxnet3', $rows[0]['type']);
    }

    public function testRetiredTypeIsRejected(): void
    {
        // vmxnet2 / pcnet32 were dropped from the supported set.
        $this->expectException(ValidationException::class);
        repo_validate_interfaces([$this->iface(['type' => 'vmxnet2'])]);
    }

    public function testUnknownTypeIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        repo_validate_interfaces([$this->iface(['type' => 'pvrdma'])]);
    }
}
