<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PortalActionOutcomeContractTest extends TestCase
{
    public function testDeployOutcomesUseJobAndGroupReadPaths(): void
    {
        $actions = file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_actions.php');
        $panel = file_get_contents(dirname(__DIR__, 2) . '/lib/deploy_jobs_panel.php');

        self::assertIsString($actions);
        self::assertIsString($panel);
        self::assertGreaterThanOrEqual(4, substr_count($actions, "'url' => deploy_job_log_url("));
        self::assertStringContainsString("deploy_mission_url(\$missionIdPost) . '#deploy-jobs'", $actions);
        self::assertStringContainsString('id="deploy-jobs" tabindex="-1"', $panel);
    }

    public function testBulkPartialOutcomeIsWarningAndKeepsExactScope(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/portal/vms.php');

        self::assertIsString($source);
        self::assertStringContainsString("\$selected = count(\$ids);", $source);
        self::assertStringContainsString("\$skipped = count(\$result['skipped']);", $source);
        self::assertStringContainsString("flash_set(\$skipped > 0 ? 'warning' : 'success'", $source);
        self::assertStringContainsString('VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM', $source);
    }

    public function testMessagesCarryScopeAndEffectBoundaryInBothLocales(): void
    {
        foreach (['de', 'en'] as $locale) {
            $deploy = file_get_contents(dirname(__DIR__, 2) . '/lang/' . $locale . '/deploy.php');
            $vms = file_get_contents(dirname(__DIR__, 2) . '/lang/' . $locale . '/vms.php');

            self::assertIsString($deploy);
            self::assertIsString($vms);
            self::assertStringContainsString("'flash_open_job_log'", $deploy);
            self::assertStringContainsString("'flash_open_jobs'", $deploy);
            self::assertStringContainsString("'bulk_delete_done'", $vms);
            self::assertStringContainsString("'bulk_reset_done'", $vms);
            self::assertStringContainsString(':selected', $vms);
        }
    }

    public function testHelpSectionIsClosedAndRendered(): void
    {
        $registry = file_get_contents(dirname(__DIR__, 2) . '/lib/help_page.php');
        $partial = file_get_contents(dirname(__DIR__, 2) . '/lib/help/deploy.php');

        self::assertIsString($registry);
        self::assertIsString($partial);
        self::assertStringContainsString("'help-action-outcomes' => 'deploy'", $registry);
        self::assertStringContainsString('id="help-action-outcomes"', $partial);
    }
}
