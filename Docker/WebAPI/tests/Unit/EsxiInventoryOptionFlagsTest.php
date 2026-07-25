<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/esxi_inventory.php';

/**
 * ADR-0023: the datacenter/datastore pickers preselect only when the union
 * describes every possible deploy target. The number of credentials alone does
 * not decide it, and neither does the number that happen to hold rows.
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

    public function testThreeStandaloneHostsAllReportingHaDatacenterAreExact(): void
    {
        // The case that matters in practice: every host offers it, so whichever
        // host the deploy picks, the value is there.
        self::assertTrue($this->flags([['ha-datacenter'], ['ha-datacenter'], ['ha-datacenter']], 3)['exact']);
    }

    public function testSingleCredentialIsExact(): void
    {
        self::assertTrue($this->flags([['datastore1']], 1)['exact']);
    }

    public function testDisagreeingCredentialsAreNotExact(): void
    {
        self::assertFalse($this->flags([['datastore1', 'ssd-fast'], ['datastore1']], 2)['exact'], 'ssd-fast only exists on one host');
    }

    public function testANeverPulledCredentialBlocksExactness(): void
    {
        // Two credentials configured, only one has inventory rows: the list
        // cannot be trusted as complete.
        self::assertFalse($this->flags([['ha-datacenter']], 2)['exact']);
    }

    public function testCaseVariantsAcrossHostsCountAsAgreement(): void
    {
        // The inventory de-duplicates case-insensitively, so the union has one
        // entry and both hosts must be seen as reporting it.
        self::assertTrue($this->flags([['ha-datacenter'], ['HA-Datacenter']], 2)['exact']);
    }

    public function testEmptyInventoryIsNotExact(): void
    {
        self::assertFalse($this->flags([], 1)['exact'], 'an empty picker must not preselect or hide anything');
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

    public function testTheUnionKeepsTheFirstSpellingOfACaseVariant(): void
    {
        // Two hosts reporting the same datastore differently. Which spelling the
        // picker shows may not depend on the credential name the groups are
        // sorted by: an operator renaming a credential would silently relabel an
        // option. Same rule as esxi_inventory_missing_values().
        $union = esxi_inventory_name_union([
            ['names' => ['DataStore1', 'ssd-fast']],
            ['names' => ['datastore1']],
        ]);

        self::assertSame(['DataStore1', 'ssd-fast'], $union['names']);
        self::assertSame(['datastore1' => true, 'ssd-fast' => true], $union['name_set']);
    }

    public function testTheUnionDropsEmptyNamesAndSurvivesAGroupWithoutAny(): void
    {
        // A credential that was pulled but holds no row of this kind still shows
        // up as a group; it must not add an empty option or fatal.
        $union = esxi_inventory_name_union([['names' => []], ['names' => ['  ', 'ds1']]]);

        self::assertSame(['ds1'], $union['names']);
        self::assertSame(['names' => [], 'name_set' => []], esxi_inventory_name_union([]));
    }
}
