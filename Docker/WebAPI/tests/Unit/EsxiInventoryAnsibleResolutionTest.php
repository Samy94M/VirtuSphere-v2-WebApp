<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/esxi_inventory.php';

final class EsxiInventoryAnsibleResolutionTest extends TestCase
{
    public function testNoCredentialIsNone(): void
    {
        $result = esxi_inventory_ansible_resolve([], 0);
        self::assertSame('none', $result['state']);
        self::assertNull($result['credential_id']);
    }

    public function testOneCredentialIsAlwaysAutomatic(): void
    {
        $credential = ['id' => 7, 'name' => 'Ansible A'];
        $result = esxi_inventory_ansible_resolve([$credential], 999);
        self::assertSame('automatic', $result['state']);
        self::assertSame(7, $result['credential_id']);
        self::assertSame(999, $result['configured_id']);
    }

    public function testSeveralCredentialsRequireASelection(): void
    {
        $credentials = [['id' => 7], ['id' => 8]];
        self::assertSame('ambiguous', esxi_inventory_ansible_resolve($credentials, 0)['state']);

        $selected = esxi_inventory_ansible_resolve($credentials, 8);
        self::assertSame('selected', $selected['state']);
        self::assertSame(8, $selected['credential_id']);

        $invalid = esxi_inventory_ansible_resolve($credentials, 999);
        self::assertSame('invalid', $invalid['state']);
        self::assertNull($invalid['credential_id']);
    }
}
