<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PortalEffectiveValuesContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testPresenterDelegatesToTheDeployValueOwners(): void
    {
        $source = (string) file_get_contents($this->root . '/lib/portal_effective_values.php');

        foreach (['ansible_effective_datastore(', 'ansible_effective_datacenter(',
            'ansible_mission_autostart(', 'ansible_vm_autostart(', 'repo_vm_delay_value('] as $owner) {
            self::assertStringContainsString($owner, $source);
        }
        self::assertStringNotContainsString('$_POST', $source);
        self::assertStringNotContainsString('UPDATE ', $source);
        self::assertStringNotContainsString('INSERT ', $source);
    }

    public function testLiveModuleIsScopedAndDoesNotPersistOrSubmit(): void
    {
        $source = (string) file_get_contents($this->root . '/portal/assets/effective_values.js');
        $layout = (string) file_get_contents($this->root . '/lib/layout.php');

        self::assertStringContainsString("'assets/effective_values.js' => ['vm_edit.php']", $layout);
        foreach (['localStorage', 'sessionStorage', 'fetch(', 'requestSubmit(', '.submit('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
        self::assertStringContainsString("target.value = '';", $source);
        self::assertStringContainsString("new Event('change', {bubbles: true})", $source);
    }

    public function testOnlyInheritedFieldsOfferTheNonWritingReset(): void
    {
        $source = (string) file_get_contents($this->root . '/lib/portal_effective_values.php');

        self::assertStringContainsString("\$row['format'] !== 'toggle'", $source);
        self::assertStringContainsString('type="button" data-effective-reset=', $source);
        self::assertStringNotContainsString('data-confirm=', $source);
    }
}
