<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/esxi_inventory.php';

/**
 * ADR-0023: the datacenter/datastore pickers group per credential only when the
 * credentials really disagree, and preselect only when the union describes every
 * possible deploy target. The number of credentials alone decides neither.
 */
final class EsxiInventoryOptionFlagsTest extends TestCase
{
    /** @param array<int,array<int,string>> $perCredential */
    private function flags(array $perCredential, int $credentialCount): array
    {
        $groups = [];
        $names = [];
        foreach ($perCredential as $credentialNames) {
            $groups[] = ['names' => $credentialNames];
            foreach ($credentialNames as $name) {
                $names[mb_strtolower($name)] = $name;
            }
        }

        return esxi_inventory_option_flags($groups, array_values($names), $credentialCount);
    }

    public function testThreeStandaloneHostsAllReportingHaDatacenterStayFlatAndExact(): void
    {
        // The case that matters in practice: grouping would print the same single
        // entry three times and imply a difference that does not exist.
        $flags = $this->flags([['ha-datacenter'], ['ha-datacenter'], ['ha-datacenter']], 3);

        self::assertFalse($flags['grouped']);
        self::assertTrue($flags['exact'], 'every host offers it, so the deploy target does not matter');
    }

    public function testSingleCredentialIsFlatAndExact(): void
    {
        $flags = $this->flags([['datastore1']], 1);

        self::assertFalse($flags['grouped']);
        self::assertTrue($flags['exact']);
    }

    public function testDisagreeingCredentialsAreGroupedAndNotExact(): void
    {
        $flags = $this->flags([['datastore1', 'ssd-fast'], ['datastore1']], 2);

        self::assertTrue($flags['grouped']);
        self::assertFalse($flags['exact'], 'ssd-fast only exists on one host');
    }

    public function testANeverPulledCredentialBlocksExactness(): void
    {
        // Two credentials configured, only one has inventory rows. The list is
        // flat (nothing to compare against) but cannot be trusted as complete.
        $flags = $this->flags([['ha-datacenter']], 2);

        self::assertFalse($flags['grouped']);
        self::assertFalse($flags['exact']);
    }

    public function testCaseVariantsAcrossHostsCountAsAgreement(): void
    {
        // The inventory de-duplicates case-insensitively, so the union has one
        // entry and both hosts must be seen as reporting it.
        $flags = $this->flags([['ha-datacenter'], ['HA-Datacenter']], 2);

        self::assertFalse($flags['grouped']);
        self::assertTrue($flags['exact']);
    }

    public function testEmptyInventoryIsNeitherGroupedNorExact(): void
    {
        $flags = $this->flags([], 1);

        self::assertFalse($flags['grouped']);
        self::assertFalse($flags['exact'], 'an empty picker must not preselect or hide anything');
    }

    public function testAnEmptyInventoryNeverMarksAValueAsUnknown(): void
    {
        // The empty-guard of ADR-0023: a never-pulled inventory is no absence
        // proof. Both the deviation report and the picker label read this.
        self::assertFalse(esxi_inventory_value_unknown('DC-Nord', []));
        self::assertFalse(esxi_inventory_value_unknown('', ['dc-nord' => true]));
    }

    public function testAKnownValueIsRecognisedRegardlessOfCase(): void
    {
        self::assertFalse(esxi_inventory_value_unknown('DC-NORD', ['dc-nord' => true]));
        self::assertTrue(esxi_inventory_value_unknown('DC-Sued', ['dc-nord' => true]));
    }
}
