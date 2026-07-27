<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/mecm_plan.php';

/**
 * The reconciliation plan is a pure function over shared vectors (ADR-0034):
 * the PowerShell implementation runs the SAME fixture file, so the portal
 * preview and the device-sync can never disagree about what a transfer does.
 */
final class MecmPlanVectorsTest extends TestCase
{
    public function testEveryVectorProducesItsExpectedPlan(): void
    {
        $raw = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/mecm-plan-vectors.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $vectors = (array) ($raw['vectors'] ?? []);
        self::assertNotEmpty($vectors, 'zero-match: the vector file must hold vectors');

        foreach ($vectors as $vector) {
            $plan = mecm_membership_plan(
                (array) $vector['desired'],
                (array) $vector['owned'],
                (array) $vector['present']
            );

            $actual = [
                'add' => array_column($plan['add'], 'name'),
                'preserve' => array_column($plan['preserve'], 'collection_id'),
                'preserve_manual' => array_column($plan['preserve_manual'], 'collection_id'),
                'remove' => array_column($plan['remove'], 'collection_id'),
                'stale_owned' => array_column($plan['stale_owned'], 'collection_id'),
                'foreign' => array_column($plan['foreign'], 'collection_id'),
            ];
            foreach ($actual as &$bucket) {
                sort($bucket);
            }
            unset($bucket);

            self::assertSame($vector['expected'], $actual, (string) $vector['name'] . ': ' . (string) $vector['why']);
        }
    }

    public function testTheRevisionChangesWithDesiredAndOwnedAndOnlyWithThem(): void
    {
        $desired = [['name' => 'Win11-24H2', 'type' => 'os']];
        $owned = [['collection_id' => 'VS1', 'collection_name' => 'Win10-22H2']];

        $base = mecm_transfer_revision($desired, $owned);
        self::assertSame($base, mecm_transfer_revision($desired, $owned), 'stable for unchanged inputs');
        // Order of entries must not matter: the preview renders from arrays
        // whose order is an implementation detail of the queries behind them.
        self::assertSame($base, mecm_transfer_revision($desired, array_reverse($owned)));
        self::assertNotSame($base, mecm_transfer_revision([['name' => 'Win11-25H1', 'type' => 'os']], $owned));
        self::assertNotSame($base, mecm_transfer_revision($desired, []));
    }
}
