<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible_inventory.php';
require_once dirname(__DIR__, 2) . '/lib/esxi_datastore_health.php';

/**
 * Datastore health (ADR-0023 amendment). The pull always carried `accessible`
 * and `maintenanceMode`; the parser threw both away, so a datastore in
 * maintenance was indistinguishable from one with room, and its free space was
 * counted as available.
 *
 * Tri-state throughout, like the host capabilities: a field the module did not
 * report must read as "not known" and never as healthy. The reverse also holds,
 * which is why an unknown health never withdraws a number the cache does have
 * (cache-never-blocks, ADR-0023).
 */
final class EsxiDatastoreHealthTest extends TestCase
{
    public function testTheParserKeepsBothHealthFieldsAsMeta(): void
    {
        $parsed = $this->parse([
            'datastores' => [
                ['name' => 'ds-ok', 'capacity' => 1000, 'freeSpace' => 400, 'accessible' => true, 'maintenanceMode' => 'normal'],
            ],
        ]);

        self::assertSame(['accessible' => true, 'maintenance' => 'normal'], $parsed['datastores'][0]['meta_json']);
        self::assertSame(400, $parsed['datastores'][0]['free_bytes'], 'the size fields must keep working unchanged');
    }

    public function testADatastoreWithoutHealthFieldsWritesNoMetaAtAll(): void
    {
        // An older playbook, or a module build that reports neither: meta_json
        // stays NULL rather than becoming an object full of nulls.
        $parsed = $this->parse(['datastores' => [['name' => 'ds-bare', 'capacity' => 1000, 'freeSpace' => 400]]]);

        self::assertNull($parsed['datastores'][0]['meta_json']);
        self::assertSame(['accessible' => null, 'maintenance' => null], esxi_datastore_health(null));
    }

    public function testTheVsphereMaintenanceEnumIsReadInEveryState(): void
    {
        // DatastoreSummaryMaintenanceModeState. `enteringMaintenance` counts as
        // maintenance: placement is already refused there.
        self::assertTrue(esxi_datastore_health(['maintenance' => 'inMaintenance'])['maintenance']);
        self::assertTrue(esxi_datastore_health(['maintenance' => 'enteringMaintenance'])['maintenance']);
        self::assertFalse(esxi_datastore_health(['maintenance' => 'normal'])['maintenance']);
        // Some module builds report a plain bool instead of the enum.
        self::assertTrue(esxi_datastore_health(['maintenance' => true])['maintenance']);
        self::assertFalse(esxi_datastore_health(['maintenance' => false])['maintenance']);
    }

    public function testAnUnrecognisedStateIsNotGuessedToBeHealthy(): void
    {
        // Guessing "normal" is the one answer this module may not give: it would
        // present a datastore nobody can use as one with room.
        self::assertNull(esxi_datastore_health(['maintenance' => 'frobnicating'])['maintenance']);
        self::assertNull(esxi_datastore_health(['maintenance' => ['a']])['maintenance']);
        self::assertNull(esxi_datastore_health(['maintenance' => ''])['maintenance']);
    }

    public function testHealthIsReadFromTheStoredJsonColumnToo(): void
    {
        // The read paths hand over the raw meta_json column, not a decoded array.
        $health = esxi_datastore_health('{"accessible":false,"maintenance":"inMaintenance"}');

        self::assertFalse($health['accessible']);
        self::assertTrue($health['maintenance']);
        self::assertSame(['accessible' => null, 'maintenance' => null], esxi_datastore_health('not json'));
    }

    public function testOnlyAProvenProblemMakesTheFreeSpaceUnusable(): void
    {
        self::assertTrue(esxi_datastore_is_unusable(['maintenance' => 'inMaintenance']));
        self::assertTrue(esxi_datastore_is_unusable(['accessible' => false]));
        self::assertFalse(esxi_datastore_is_unusable(['accessible' => true, 'maintenance' => 'normal']));
        // Unknown is not unusable: a cache that did not report a field may not
        // withdraw a number it does have.
        self::assertFalse(esxi_datastore_is_unusable(null));
        self::assertFalse(esxi_datastore_is_unusable([]));
    }

    public function testTheSharedFreeSpaceReaderCollapsesBothWaysANumberCanBeMissing(): void
    {
        self::assertSame(400, esxi_datastore_usable_free_bytes(400, ['maintenance' => 'normal']));
        self::assertNull(esxi_datastore_usable_free_bytes(null, ['maintenance' => 'normal']), 'the cache never had a number');
        self::assertNull(esxi_datastore_usable_free_bytes(400, ['maintenance' => 'inMaintenance']), 'the number is not space anybody can use');
        // A real zero is a fact and survives: a full datastore says so.
        self::assertSame(0, esxi_datastore_usable_free_bytes(0, null));
    }

    public function testTheLogLineSpeaksInTheGoodCaseToo(): void
    {
        // The lesson of the portgroup query that reported 0 for months: a field
        // path that stops matching looks exactly like a healthy fleet, and only
        // a line that also speaks when everything is fine tells them apart.
        $healthy = ansible_inventory_datastore_health_log_line([
            ['name' => 'ds1', 'meta_json' => ['accessible' => true, 'maintenance' => 'normal']],
            ['name' => 'ds2', 'meta_json' => ['accessible' => true, 'maintenance' => 'normal']],
        ]);

        self::assertStringContainsString('accessibility reported for 2', $healthy);
        self::assertStringContainsString('maintenance mode reported for 2', $healthy);
        self::assertStringNotContainsString('In maintenance:', $healthy);
    }

    public function testTheLogLineNamesWhatIsWrongAndWhatWasNeverReported(): void
    {
        $line = ansible_inventory_datastore_health_log_line([
            ['name' => 'ds-work', 'meta_json' => ['accessible' => true, 'maintenance' => 'inMaintenance']],
            ['name' => 'ds-dead', 'meta_json' => ['accessible' => false, 'maintenance' => 'normal']],
            ['name' => 'ds-quiet', 'meta_json' => null],
        ]);

        self::assertStringContainsString('3 datastore(s)', $line);
        self::assertStringContainsString('accessibility reported for 2', $line);
        self::assertStringContainsString('In maintenance: ds-work.', $line);
        self::assertStringContainsString('Inaccessible: ds-dead.', $line);
    }

    public function testAPullWithoutDatastoresSaysNothingRatherThanZero(): void
    {
        // The item counts one line above already report the 0; a second line
        // claiming health facts about nothing would be noise.
        self::assertNull(ansible_inventory_datastore_health_log_line([]));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function parse(array $payload): array
    {
        $marker = 'VIRTUSPHERE_INVENTORY_B64_BEGIN' . base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)) . 'VIRTUSPHERE_INVENTORY_B64_END';

        return ansible_parse_inventory_output("noise\n" . $marker . "\nmore noise");
    }
}
