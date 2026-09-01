<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * layout_app_scripts() is the sole portal script registry. Comparing the
 * directory and registry in both directions makes a newly added IIFE fail the
 * build until its deterministic load position is classified.
 */
final class PortalAssetRegistryContractTest extends TestCase
{
    public function testEveryPortalScriptHasExactlyOneRegisteredLoadPosition(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $files = array_map('basename', glob($root . '/portal/assets/*.js') ?: []);
        sort($files);
        self::assertNotSame([], $files, 'no portal scripts found (zero-match)');

        $layout = (string) file_get_contents($root . '/lib/layout.php');
        preg_match_all("/'assets\/([a-z_-]+\.js)'/", $layout, $matches);
        $registered = $matches[1];
        self::assertSame(
            count($registered),
            count(array_unique($registered)),
            'layout_app_scripts() registers one script more than once'
        );
        sort($registered);
        self::assertSame($files, $registered, 'portal assets and layout_app_scripts() must match in both directions');
    }

    public function testDeployModulesKeepTheirRequiredListenerOrder(): void
    {
        $layout = (string) file_get_contents(str_replace('\\', '/', dirname(__DIR__, 2)) . '/lib/layout.php');
        $positions = [];
        foreach (['deploy_form.js', 'deploy_warnings.js', 'deploy_blockers.js', 'deploy_storage.js'] as $script) {
            $positions[$script] = strpos($layout, "'assets/" . $script . "'");
            self::assertNotFalse($positions[$script], $script . ' is not registered');
        }

        self::assertLessThan($positions['deploy_warnings.js'], $positions['deploy_form.js']);
        self::assertLessThan($positions['deploy_blockers.js'], $positions['deploy_warnings.js']);
        self::assertLessThan($positions['deploy_storage.js'], $positions['deploy_blockers.js']);
    }
}
