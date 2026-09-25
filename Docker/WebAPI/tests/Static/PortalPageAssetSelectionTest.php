<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';

final class PortalPageAssetSelectionTest extends TestCase
{
    public function testEveryPageHasItsExactScriptSet(): void
    {
        $expected = [];
        foreach (VIRTUSPHERE_LAYOUT_PAGES as $page) {
            $expected[$page] = ['assets/core.js'];
        }
        $expected['mission_details.php'][] = 'assets/unsaved_changes.js';
        $expected['credentials.php'][] = 'assets/forms.js';
        $expected['vm_edit.php'] = ['assets/core.js', 'assets/unsaved_changes.js', 'assets/effective_values.js', 'assets/forms.js', 'assets/vm-network.js'];
        $expected['vms.php'][] = 'assets/forms.js';
        $expected['deploy_log.php'][] = 'assets/deploy_log.js';
        $expected['deploy.php'] = [
            'assets/core.js',
            'assets/deploy_form.js',
            'assets/deploy_warnings.js',
            'assets/deploy_blockers.js',
            'assets/deploy_storage.js',
        ];

        foreach ($expected as $page => $assets) {
            self::assertSame($assets, layout_assets_for_page(layout_script_registry(), $page), $page);
        }
    }

    public function testEveryPageHasItsExactStylesheetSetInCascadeOrder(): void
    {
        $commonBefore = ['assets/css/base.css', 'assets/css/layout.css', 'assets/css/panels.css'];
        $commonAfter = ['assets/css/controls.css', 'assets/css/feedback.css'];
        $tablePages = [
            'credentials.php', 'dashboard.php', 'deploy.php', 'deploy_log.php',
            'help.php', 'logs.php', 'missions.php', 'os.php', 'packages.php',
            'settings.php', 'system_status.php', 'users.php', 'vlans.php',
            'vm_edit.php', 'vms.php',
        ];

        foreach (VIRTUSPHERE_LAYOUT_PAGES as $page) {
            $expected = $commonBefore;
            if (in_array($page, $tablePages, true)) {
                $expected[] = 'assets/css/tables.css';
            }
            array_push($expected, ...$commonAfter);
            if (in_array($page, ['deploy_log.php', 'system_status.php'], true)) {
                $expected[] = 'assets/css/status.css';
            }
            $expected[] = 'assets/css/forced-colors.css';

            self::assertSame($expected, layout_assets_for_page(layout_style_registry(), $page), $page);
        }
    }

    public function testAnUnclassifiedPageFailsClosed(): void
    {
        $this->expectException(LogicException::class);
        layout_assets_for_page(layout_script_registry(), 'new-page.php');
    }

    public function testServerPathResolvesOnlyToAClassifiedBasename(): void
    {
        self::assertSame('deploy.php', layout_asset_page('/portal/deploy.php'));
        self::assertSame('login.php', layout_asset_page('C:\\portal\\login.php'));
    }
}
