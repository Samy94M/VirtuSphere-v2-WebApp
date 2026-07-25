<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/esxi_inventory.php';

/**
 * The location picker groups its options by **how many credentials report a
 * name**, not by which one did (ADR-0023 amendment). The operator's question at
 * that field is "does this value survive the host choice I make later", and the
 * per-credential grouping answered a different one: a datastore shared by four
 * hosts appeared four times, identically, carrying `selected` in each group.
 *
 * Pure, so the whole 0/1/many matrix of a mixed fleet runs without an ESXi host.
 */
final class EsxiInventoryPresenceBucketsTest extends TestCase
{
    /**
     * @param array<int, string> $names
     * @param array<string, ?int> $free
     * @return array<string, mixed>
     */
    private function group(string $credential, array $names, array $free = []): array
    {
        $byKey = [];
        foreach ($names as $name) {
            $byKey[esxi_inventory_name_key($name)] = $free[$name] ?? null;
        }

        return [
            'credential_id' => crc32($credential),
            'credential_name' => $credential,
            'credential_host' => '10.0.5.' . (crc32($credential) % 200),
            'names' => $names,
            'free_by_key' => $byKey,
        ];
    }

    /** @param array<int, array<string, mixed>> $buckets */
    private function scopes(array $buckets): array
    {
        return array_map(static fn (array $bucket): array => [$bucket['scope'], $bucket['names']], $buckets);
    }

    public function testAMixedFleetSplitsIntoSharedAndPerHostBuckets(): void
    {
        $buckets = esxi_inventory_presence_buckets([
            $this->group('esxi-prod-02', ['datastore-shared', 'datastore-local-02']),
            $this->group('esxi-prod-05', ['datastore-shared', 'datastore-local-05']),
        ], 2);

        self::assertSame([
            ['all', ['datastore-shared']],
            ['only', ['datastore-local-02']],
            ['only', ['datastore-local-05']],
        ], $this->scopes($buckets));

        // The shared name appears exactly once in the whole rendering, so no
        // browser has to decide which of several `selected` options wins.
        $rendered = array_merge(...array_map(static fn (array $b): array => $b['names'], $buckets));
        self::assertSame(array_unique($rendered), $rendered);
    }

    public function testABucketNamesTheCredentialsItBelongsTo(): void
    {
        $buckets = esxi_inventory_presence_buckets([
            $this->group('esxi-a', ['ds-local-a']),
            $this->group('esxi-b', ['ds-local-b']),
        ], 2);

        self::assertSame('esxi-a', $buckets[0]['credentials'][0]['name']);
        self::assertNotSame('', $buckets[0]['credentials'][0]['host'], 'the address is what makes "esxi1..esxi6" usable');

        // The all-bucket names no credential: naming them would list every host
        // in a label whose whole point is that the host does not matter.
        $everywhere = esxi_inventory_presence_buckets([$this->group('solo', ['x'])], 1);
        self::assertSame('all', $everywhere[0]['scope']);
        self::assertSame([], $everywhere[0]['credentials']);
    }

    public function testATrueSubsetGetsItsOwnBucket(): void
    {
        $buckets = esxi_inventory_presence_buckets([
            $this->group('esxi-a', ['ds-pair']),
            $this->group('esxi-b', ['ds-pair']),
            $this->group('esxi-c', ['ds-solo']),
        ], 3);

        self::assertSame([
            ['some', ['ds-pair']],
            ['only', ['ds-solo']],
        ], $this->scopes($buckets));
        self::assertSame(['esxi-a', 'esxi-b'], array_column($buckets[0]['credentials'], 'name'));
    }

    public function testTwoNamesOnTheSameHostPairShareOneBucket(): void
    {
        $buckets = esxi_inventory_presence_buckets([
            $this->group('esxi-a', ['ds-one', 'ds-two']),
            $this->group('esxi-b', ['ds-one', 'ds-two']),
            $this->group('esxi-c', []),
        ], 3);

        self::assertSame([['some', ['ds-one', 'ds-two']]], $this->scopes($buckets));
    }

    public function testTheDenominatorIsTheProvenCredentialsAndNotTheGroups(): void
    {
        // esxi-c pulled successfully and genuinely holds no datastore. Counting
        // groups would promote ds-pair to "on all credentials" while a whole
        // host is missing from the denominator.
        $groups = [$this->group('esxi-a', ['ds-pair']), $this->group('esxi-b', ['ds-pair'])];

        self::assertSame([['some', ['ds-pair']]], $this->scopes(esxi_inventory_presence_buckets($groups, 3)));
        self::assertSame([['all', ['ds-pair']]], $this->scopes(esxi_inventory_presence_buckets($groups, 2)));
    }

    public function testSixCredentialsWithTwoPulledClaimNothingAboutTheOtherFour(): void
    {
        // Both proven hosts agree, so the list is a single bucket and renders
        // flat; the "may be missing later" note comes from `exact`, not here.
        $buckets = esxi_inventory_presence_buckets([
            $this->group('esxi-01', ['ha-datacenter']),
            $this->group('esxi-02', ['ha-datacenter']),
        ], 2);

        self::assertSame([['all', ['ha-datacenter']]], $this->scopes($buckets));
        self::assertFalse(esxi_inventory_options_are_bucketed(['buckets' => $buckets]));
    }

    public function testWithoutASingleSuccessfulPullNothingIsClaimedToBeEverywhere(): void
    {
        // Rows without a recorded success (a restored dump, a migrated cache):
        // the cache may be right, but nothing has proven it, so no name may be
        // labelled "present on every credential".
        $buckets = esxi_inventory_presence_buckets([
            $this->group('esxi-a', ['ds1']),
            $this->group('esxi-b', ['ds1']),
        ], 0);

        self::assertSame([['some', ['ds1']]], $this->scopes($buckets));
    }

    public function testCaseVariantsAcrossHostsAreOneName(): void
    {
        $buckets = esxi_inventory_presence_buckets([
            $this->group('esxi-a', ['DataStore1']),
            $this->group('esxi-b', ['datastore1']),
        ], 2);

        self::assertSame([['all', ['DataStore1']]], $this->scopes($buckets), 'first spelling wins, like every other dedupe');
    }

    public function testTheFreeSpaceOfASharedNameIsTheTightestHost(): void
    {
        // The target host is still open, so the smallest number is the only one
        // that cannot mislead.
        $buckets = esxi_inventory_presence_buckets([
            $this->group('esxi-a', ['ds-shared'], ['ds-shared' => 900]),
            $this->group('esxi-b', ['ds-shared'], ['ds-shared' => 400]),
        ], 2);

        self::assertSame(400, $buckets[0]['free_by_key']['ds-shared']);
    }

    public function testAnUnknownFreeValueNeverWinsTheMinimum(): void
    {
        // NULL is a hole in the cache, not a zero: it must neither become the
        // minimum nor hide a number another host did report.
        $mixed = esxi_inventory_presence_buckets([
            $this->group('esxi-a', ['ds-shared'], ['ds-shared' => 500]),
            $this->group('esxi-b', ['ds-shared'], ['ds-shared' => null]),
        ], 2);
        self::assertSame(500, $mixed[0]['free_by_key']['ds-shared']);

        $none = esxi_inventory_presence_buckets([
            $this->group('esxi-a', ['dc-one'], ['dc-one' => null]),
        ], 1);
        self::assertNull($none[0]['free_by_key']['dc-one']);
    }

    public function testADatastoreInMaintenanceOnOneHostCarriesNoUsableNumber(): void
    {
        // The target host is still open, so one host in maintenance is enough:
        // showing the other host's number would promise space the chosen host
        // does not have. Same "worst reporting host wins" rule as the minimum.
        $a = $this->group('esxi-a', ['ds-shared'], ['ds-shared' => 900]);
        $b = $this->group('esxi-b', ['ds-shared'], ['ds-shared' => 400]);
        $b['unusable_keys'] = ['ds-shared' => true];

        $buckets = esxi_inventory_presence_buckets([$a, $b], 2);

        self::assertTrue($buckets[0]['unusable_by_key']['ds-shared']);
        self::assertNull($buckets[0]['free_by_key']['ds-shared']);
    }

    public function testAHealthyNameKeepsItsNumberNextToAnUnusableSibling(): void
    {
        // The flag is per name, not per host: marking every datastore of a host
        // that has one in maintenance would hide the ones that are fine.
        $a = $this->group('esxi-a', ['ds-work', 'ds-fine'], ['ds-work' => 100, 'ds-fine' => 700]);
        $a['unusable_keys'] = ['ds-work' => true];

        $buckets = esxi_inventory_presence_buckets([$a], 1);

        self::assertNull($buckets[0]['free_by_key']['ds-work']);
        self::assertSame(700, $buckets[0]['free_by_key']['ds-fine']);
        self::assertFalse($buckets[0]['unusable_by_key']['ds-fine']);
    }

    public function testTheFlatListFollowsTheSameMaintenanceRule(): void
    {
        // A single-bucket picker renders from free_by_key/unusable_by_key of the
        // whole option set, so the two paths may not answer differently.
        $a = $this->group('esxi-a', ['ds-shared'], ['ds-shared' => 900]);
        $b = $this->group('esxi-b', ['ds-shared'], ['ds-shared' => 400]);
        $b['unusable_keys'] = ['ds-shared' => true];

        self::assertNull(esxi_inventory_free_union([$a, $b])['ds-shared']);
        self::assertSame(['ds-shared' => true], esxi_inventory_unusable_union([$a, $b]));
    }

    public function testNoCredentialsAndNoNamesProduceNoBuckets(): void
    {
        self::assertSame([], esxi_inventory_presence_buckets([], 0));
        self::assertSame([], esxi_inventory_presence_buckets([$this->group('esxi-a', [])], 1));
        self::assertSame([], esxi_inventory_presence_buckets([$this->group('esxi-a', ['  '])], 1));
    }

    public function testTheNotesFollowWhatTheFieldRendersAndNotTheCredentialCount(): void
    {
        // The B1 defect: six credentials, one pulled. The list is flat, and the
        // note used to say it was "grouped per credential".
        $flatButIncomplete = ['names' => ['ha-datacenter'], 'exact' => false, 'buckets' => [['scope' => 'all']]];
        self::assertSame(['host_choice'], esxi_inventory_location_notes([$flatButIncomplete]));

        $bucketed = ['names' => ['a', 'b'], 'exact' => false, 'buckets' => [['scope' => 'all'], ['scope' => 'only']]];
        self::assertSame(['host_choice', 'buckets'], esxi_inventory_location_notes([$bucketed]));

        $complete = ['names' => ['ha-datacenter'], 'exact' => true, 'buckets' => [['scope' => 'all']]];
        self::assertSame([], esxi_inventory_location_notes([$complete]));

        // An empty option set falls back to a free-text input and claims nothing.
        self::assertSame([], esxi_inventory_location_notes([['names' => [], 'exact' => false, 'buckets' => []]]));

        // One incomplete field is enough; the note sits under both controls.
        self::assertSame(['host_choice'], esxi_inventory_location_notes([$complete, $flatButIncomplete]));
    }

    public function testANeverPulledCredentialIsExplainedAtTheFieldEvenWithAnEmptyPicker(): void
    {
        // The ADR-0023 dead end: a value that exists only on an unpulled host is
        // neither offered nor typeable. The operator used to meet the effect at
        // the field and the cause only in the documentation.
        $emptyPicker = ['names' => [], 'exact' => false, 'buckets' => [], 'credential_count' => 2, 'eligible_count' => 0];
        self::assertSame(['never_pulled'], esxi_inventory_location_notes([$emptyPicker]));

        $partial = ['names' => ['ds1'], 'exact' => false, 'buckets' => [['scope' => 'all']], 'credential_count' => 6, 'eligible_count' => 2];
        self::assertSame(['host_choice', 'never_pulled'], esxi_inventory_location_notes([$partial]));

        // Everything pulled: nothing to explain, and a note that always shows is
        // a note nobody reads.
        $proven = ['names' => ['ds1'], 'exact' => true, 'buckets' => [['scope' => 'all']], 'credential_count' => 2, 'eligible_count' => 2];
        self::assertSame([], esxi_inventory_location_notes([$proven]));
    }
}
