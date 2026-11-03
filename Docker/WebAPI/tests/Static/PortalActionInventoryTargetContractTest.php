<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/PortalActionInventory.php';

/**
 * A form belongs to the page its POST goes to, not to the page whose file renders
 * it. credentials.php renders the remote-secret acknowledge form through a shared
 * lib notice that carries the system_status name, and that form posts to
 * credentials.php. Attributing it to system_status.php would leave the credentials
 * page's closed inventory without its prompt and its e2e proof.
 */
final class PortalActionInventoryTargetContractTest extends TestCase
{
    public function testRemoteSecretAcknowledgeBelongsToCredentialsNotSystemStatus(): void
    {
        $root = dirname(__DIR__, 2);
        $notice = (string) file_get_contents($root . '/lib/system_status_remote_secret_notice.php');
        self::assertStringContainsString('action="credentials.php"', $notice, 'the notice must still post to credentials.php');

        $actions = PortalActionInventory::postableActions(PortalActionInventory::portalPages($root));

        self::assertArrayHasKey('credentials.php:remote_secret_acknowledge', $actions);
        self::assertArrayNotHasKey('system_status.php:remote_secret_acknowledge', $actions);
        self::assertTrue($actions['credentials.php:remote_secret_acknowledge'], 'the acknowledge form keeps its data-confirm prompt');
    }

    public function testEveryNamedActionTargetIsAnExistingPortalPage(): void
    {
        $root = dirname(__DIR__, 2);
        $named = 0;
        foreach (PortalActionInventory::formSites($root) as $site) {
            if ($site['target'] === null) {
                continue;
            }
            ++$named;
            $where = $site['file'] . ':' . $site['line'];
            self::assertMatchesRegularExpression('/^[a-z0-9_]+\.php$/', $site['target'], $where . ' names a target that is not a bare page name');
            self::assertFileExists($root . '/portal/' . $site['target'], $where . ' posts to ' . $site['target'] . ', which is no portal page');
        }
        self::assertGreaterThan(0, $named, 'no form names a portal page; the target rule has nothing to check');
    }

    public function testFormScanSeesEveryFormOpeningAndClosingTag(): void
    {
        // A form the block pattern cannot close would vanish from the inventory
        // without a finding, so the tag counts of the tree must match the blocks.
        $root = dirname(__DIR__, 2);
        $sources = [];
        foreach (glob($root . '/portal/*.php') ?: [] as $path) {
            $sources[] = (string) file_get_contents($path);
        }
        $lib = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/lib', FilesystemIterator::SKIP_DOTS));
        $libFiles = 0;
        foreach ($lib as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                ++$libFiles;
                $sources[] = (string) file_get_contents($file->getPathname());
            }
        }
        self::assertGreaterThan(100, $libFiles, 'the lib walk saw too few files');

        $opening = 0;
        $closing = 0;
        foreach ($sources as $source) {
            $opening += (int) preg_match_all('/<form\b/', $source);
            $closing += substr_count($source, '</form>');
        }
        $sites = PortalActionInventory::formSites($root);
        self::assertGreaterThan(0, count($sites), 'the form scan found no form at all');
        self::assertSame($opening, count($sites), 'every <form opening must produce exactly one block');
        self::assertSame($closing, count($sites), 'every </form> must close exactly one block');
    }
}
