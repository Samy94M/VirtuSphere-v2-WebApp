<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/status.php';

/**
 * MX0 (migration 0070): `failed` on the MECM axis became `deploy_failed`.
 *
 *  - check-enum-sync.sh compares only the first ENUM of the column in
 *    struktur.sql and lib/migrate.php. The migration file narrows to the final
 *    list in its LAST definition, so that one is derived and compared here, and
 *    its widening step must be exactly the final list plus the withdrawn token.
 *  - getDeviceList keeps the wire name `failed` for the deploy failure mark. The
 *    wire value must not collide with a stored value: reintroducing a real
 *    `failed` state turns this red, so the alias is resolved on purpose, not by
 *    accident.
 */
final class MecmSyncDeployFailedContractTest extends TestCase
{
    private const MIGRATION = '/lib/migrations/0070_mecm_sync_deploy_failed.php';

    public function testTheMigrationEndsWithThePhpValueListAndWidensOnlyByTheWithdrawnToken(): void
    {
        $definitions = self::enumDefinitions((string) file_get_contents(dirname(__DIR__, 2) . self::MIGRATION));

        self::assertCount(2, $definitions, 'widen once, narrow once');
        self::assertSame(VIRTUSPHERE_MECM_SYNC_STATES, $definitions[1], 'the final ENUM drifted from VIRTUSPHERE_MECM_SYNC_STATES');
        $union = $definitions[0];
        self::assertSame(['failed'], array_values(array_diff($union, VIRTUSPHERE_MECM_SYNC_STATES)));
        self::assertSame([], array_values(array_diff(VIRTUSPHERE_MECM_SYNC_STATES, $union)));
    }

    public function testTheGuardReadsBothDefinitions(): void
    {
        $fixture = "MODIFY mecm_sync_state ENUM('a','b') NOT NULL\nMODIFY mecm_sync_state ENUM('a') NOT NULL";

        self::assertSame([['a', 'b'], ['a']], self::enumDefinitions($fixture));
        self::assertSame([], self::enumDefinitions('MODIFY other_column ENUM(\'a\')'));
    }

    public function testTheWireKeepsFailedForTheDeployFailureMarkAndNothingElseChanges(): void
    {
        self::assertSame('failed', virtusphere_mecm_sync_wire_value(VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED));
        foreach (VIRTUSPHERE_MECM_SYNC_STATES as $state) {
            if ($state === VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED) {
                continue;
            }
            self::assertSame($state, virtusphere_mecm_sync_wire_value($state));
        }
        self::assertNotContains(
            virtusphere_mecm_sync_wire_value(VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED),
            VIRTUSPHERE_MECM_SYNC_STATES,
            'the wire alias now collides with a stored state; decide the wire value on purpose'
        );
    }

    public function testTheComputedStageValueIsTheOneTheOldTokenProduced(): void
    {
        foreach (VIRTUSPHERE_LIFECYCLE_STATES as $lifecycle) {
            self::assertSame(
                virtusphere_legacy_status_from_states($lifecycle, 'failed'),
                virtusphere_legacy_status_from_states($lifecycle, VIRTUSPHERE_MECM_SYNC_DEPLOY_FAILED),
                $lifecycle
            );
        }
    }

    public function testGetDeviceListAppliesTheWireMapping(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/mecm-api.php');

        self::assertMatchesRegularExpression(
            "/\\\$vm\\['mecm_sync_state'\\]\\s*=\\s*virtusphere_mecm_sync_wire_value\\(/",
            $source,
            'getDeviceList must emit the wire value, not the stored token'
        );
    }

    /** @return list<list<string>> */
    private static function enumDefinitions(string $source): array
    {
        preg_match_all("/MODIFY\\s+mecm_sync_state\\s+ENUM\\(([^)]*)\\)/", $source, $matches);

        return array_map(
            static fn (string $list): array => array_map(static fn (string $value): string => trim($value, " '"), explode(',', $list)),
            $matches[1]
        );
    }
}
