<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible.php';

/**
 * The serverlist is consumed by Ansible, which parses YAML through PyYAML, i.e.
 * YAML 1.1. Under 1.1 a bare `yes`/`no`/`on`/`off`/`y`/`n` (any case) is a
 * boolean and a bare number is an int, so any value that is really a string has
 * to be quoted or it silently changes type on the way into the playbook (the
 * "Norway problem": a portgroup or datastore literally named `no` would become
 * boolean false). ansible_yaml_string() quotes+escapes every string field and
 * ansible_yaml_bare() only leaves a scalar bare when it matches a safe charset.
 *
 * No YAML parser ships in the air-gapped container, so this pins the *emitted*
 * quoting: the version-independent proof that a hostile scalar cannot be
 * re-typed. A regression that drops a pair of quotes would pass a YAML 1.2 check
 * but break under Ansible's 1.1 loader, so the substring form is what matters.
 *
 * Pure string generation, no DB.
 */
final class AnsibleServerlistYamlSafetyTest extends TestCase
{
    private function vm(array $overrides = []): array
    {
        return array_merge([
            'vm_name' => 'edge01',
            'vm_os' => 'Windows Server 2019',
            'vm_ram' => '4096',
            'vm_cpu' => '2',
            'vm_guest_id' => 'windows2019srv_64Guest',
            'disks' => [],
            'interfaces' => [],
            'packages' => [],
        ], $overrides);
    }

    private function mission(array $overrides = []): array
    {
        return array_merge([
            'mission_name' => 'EDGE',
            'hypervisor_datacenter' => 'DC1',
            'hypervisor_datastorage' => 'ds1',
            'wds_vlan' => 'VLAN10',
        ], $overrides);
    }

    /** A package literally named `yes` must stay a string, not become boolean true. */
    public function testYamlBooleanTokenPackageIsQuoted(): void
    {
        $yml = ansible_serverlist_yml(
            $this->mission(),
            [$this->vm(['packages' => [['package_name' => 'yes'], ['package_name' => 'no']]])]
        );

        self::assertMatchesRegularExpression('/^\s*- "yes"\s*$/m', $yml);
        self::assertMatchesRegularExpression('/^\s*- "no"\s*$/m', $yml);
        // The bare forms would parse as booleans under YAML 1.1.
        self::assertDoesNotMatchRegularExpression('/^\s*- yes\s*$/m', $yml);
        self::assertDoesNotMatchRegularExpression('/^\s*- no\s*$/m', $yml);
    }

    /** A numeric-looking package name must stay a string, not an int. */
    public function testNumericPackageIsQuoted(): void
    {
        $yml = ansible_serverlist_yml($this->mission(), [$this->vm(['packages' => [['package_name' => '12345']]])]);

        self::assertMatchesRegularExpression('/^\s*- "12345"\s*$/m', $yml);
        self::assertDoesNotMatchRegularExpression('/^\s*- 12345\s*$/m', $yml);
    }

    /** A Norway-token value in a mapping (per-VM datacenter) must be quoted too. */
    public function testBooleanTokenDatacenterValueIsQuoted(): void
    {
        $yml = ansible_serverlist_yml($this->mission(), [$this->vm(['vm_datacenter' => 'no'])]);

        self::assertStringContainsString('datacenter_name: "no"', $yml);
        self::assertStringNotContainsString('datacenter_name: no', $yml);
    }

    /** Colons, embedded quotes and hashes in a datastore name must be escaped. */
    public function testDatastoreWithYamlMetacharactersIsEscaped(): void
    {
        $yml = ansible_serverlist_yml(
            $this->mission(['hypervisor_datastorage' => 'ds: "prod" #1']),
            [$this->vm()]
        );

        self::assertStringContainsString('datastore_name: "ds: \\"prod\\" #1"', $yml);
        self::assertStringContainsString('mission_datastore: "ds: \\"prod\\" #1"', $yml);
    }

    /** An embedded quote in an interface (portgroup) name must be escaped. */
    public function testInterfaceNameWithQuoteIsEscaped(): void
    {
        $yml = ansible_serverlist_yml(
            $this->mission(),
            [$this->vm(['interfaces' => [['vlan' => 'VLAN:evil "x"', 'type' => 'e1000']]])]
        );

        self::assertStringContainsString('name: "VLAN:evil \\"x\\""', $yml);
    }

    /**
     * device_type is emitted bare for a safe value but must be quoted for a value
     * outside the bare charset, so ansible_yaml_bare() cannot produce broken YAML.
     */
    public function testInterfaceDeviceTypeBareVsQuoted(): void
    {
        $yml = ansible_serverlist_yml(
            $this->mission(),
            [$this->vm(['interfaces' => [
                ['vlan' => 'VLAN10', 'type' => 'vmxnet3'],
                ['vlan' => 'VLAN11', 'type' => 'weird type'],
            ]])]
        );

        self::assertMatchesRegularExpression('/^\s*device_type: vmxnet3\s*$/m', $yml);
        self::assertStringContainsString('device_type: "weird type"', $yml);
    }

    /** A newline and quotes in mission notes must be escaped, not split the mapping. */
    public function testMissionNotesNewlineAndQuotesAreEscaped(): void
    {
        $yml = ansible_serverlist_yml(
            $this->mission(['mission_notes' => "Line1 \"q\"\nLine2: after"]),
            [$this->vm()]
        );

        self::assertStringContainsString('mission_notes: "Line1 \\"q\\"\\nLine2: after"', $yml);
        // The value stays on one physical line: no raw newline inside the quotes.
        self::assertMatchesRegularExpression('/^  mission_notes: ".*"$/m', $yml);
    }

    /** A non-ASCII datacenter name is preserved verbatim inside quotes (UTF-8). */
    public function testNonAsciiDatacenterIsPreservedAndQuoted(): void
    {
        $yml = ansible_serverlist_yml($this->mission(['hypervisor_datacenter' => 'DC-Süd']), [$this->vm()]);

        self::assertStringContainsString('mission_datacenter: "DC-Süd"', $yml);
        self::assertStringContainsString('datacenter_name: "DC-Süd"', $yml);
    }

    /**
     * YAML 1.1 forbids raw C0 control characters and DEL even inside a
     * double-quoted scalar (tab, CR and LF excepted). A single such byte left
     * literal in a free-text field makes PyYAML reject the whole document and
     * fails the deploy, not one field. The validators only trim + length-check
     * free text, so the escaper is the chokepoint: it must emit \xNN, and the
     * rendered serverlist must carry no forbidden raw control byte.
     */
    public function testControlCharactersAreHexEscaped(): void
    {
        self::assertSame('"a\\x00b"', ansible_yaml_string("a\x00b"));
        self::assertSame('"a\\x01\\x1B\\x7Fb"', ansible_yaml_string("a\x01\x1b\x7fb"));
        // \t, \r, \n keep their existing handling and are not hex-escaped.
        self::assertSame('"a\\rb"', ansible_yaml_string("a\rb"));

        $yml = ansible_serverlist_yml(
            $this->mission(['mission_notes' => "note\x00with\x07controls"]),
            [$this->vm(['vm_name' => "edge\x1b01"])]
        );
        // No forbidden raw control byte survives anywhere in the rendered doc.
        self::assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $yml);
        self::assertStringContainsString('mission_notes: "note\\x00with\\x07controls"', $yml);
    }
}
