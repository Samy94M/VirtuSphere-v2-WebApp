<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/esxi_inventory.php';

final class EsxiInventoryDeviationWarningContractTest extends TestCase
{
    public function testAllPortalWarningSurfacesUseTheEvidenceQualifiedReport(): void
    {
        $root = dirname(__DIR__, 2);
        $deploy = file_get_contents($root . '/lib/deploy_view_model.php');
        $missions = file_get_contents($root . '/portal/missions.php');
        $warnings = file_get_contents($root . '/lib/esxi_inventory_deviation_warnings.php');

        self::assertIsString($deploy);
        self::assertIsString($missions);
        self::assertIsString($warnings);
        self::assertStringContainsString('esxi_inventory_deviation_report(', $deploy);
        self::assertStringContainsString('esxi_inventory_deviation_report(', $missions);
        self::assertStringContainsString('esxi_inventory_deviation_report($db, [$summary]', $warnings);
        self::assertStringContainsString('!empty($report[\'kinds\'][$kind][\'current\'])', $warnings);
        self::assertStringContainsString('empty($otherReport[\'kinds\'][$kind][\'current\'])', $warnings);
    }

    public function testHistoricalOnlyFindingsDoNotBecomeCurrentWarningsOrBadges(): void
    {
        $kind = VIRTUSPHERE_INVENTORY_KIND_DATASTORE;
        $report = [
            'deviations' => [[
                'mission_id' => 17,
                'issues' => [['field' => 'datastore', 'value' => 'old-store']],
            ]],
            'kinds' => [$kind => ['qualified' => true, 'current' => false, 'historical' => true]],
        ];

        self::assertSame([], esxi_inventory_current_deviation_rows($report));
        self::assertSame([], esxi_inventory_current_deviation_mission_ids($report));
        $report['kinds'][$kind]['current'] = true;
        self::assertSame([17 => true], esxi_inventory_current_deviation_mission_ids($report));
    }

    public function testLanguageHelpAndStatusDiagramNameTheCurrentHistoricalBoundary(): void
    {
        $root = dirname(__DIR__, 2);
        $deDeploy = require $root . '/lang/de/help_deploy.php';
        $enDeploy = require $root . '/lang/en/help_deploy.php';
        $deMissions = require $root . '/lang/de/help_missions.php';
        $enMissions = require $root . '/lang/en/help_missions.php';
        $operations = file_get_contents(dirname(__DIR__, 4) . '/docs/operations/system-status-checks.md');

        self::assertStringContainsString('Historische Treffer', $deDeploy['deploy_warn_p1']);
        self::assertStringContainsString('historical findings', $enDeploy['deploy_warn_p1']);
        self::assertStringContainsString('historische Treffer', $deMissions['location_p3']);
        self::assertStringContainsString('historical findings', $enMissions['location_p3']);
        self::assertIsString($operations);
        self::assertStringContainsString('keine aktuelle Deploy-Warnung und kein Missions-Badge', $operations);
        self::assertStringContainsString('mindestens einen anderen Host', $operations);
    }
}
