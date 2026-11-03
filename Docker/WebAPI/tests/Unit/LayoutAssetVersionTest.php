<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';

final class LayoutAssetVersionTest extends TestCase
{
    public function testVersionChangesWithContentWhenMtimeIsPreserved(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'virtusphere-asset-');
        self::assertIsString($path);

        try {
            file_put_contents($path, 'first-content');
            touch($path, 1_700_000_000);
            $first = layout_asset_version($path);

            file_put_contents($path, 'other-content');
            touch($path, 1_700_000_000);
            clearstatcache(true, $path);
            $second = layout_asset_version($path);

            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $second);
            self::assertNotSame($first, $second);
        } finally {
            @unlink($path);
        }
    }

    public function testMissingAssetNeverLooksLikeAnImmutableDigest(): void
    {
        $path = sys_get_temp_dir() . '/virtusphere-missing-' . bin2hex(random_bytes(8));

        self::assertSame('missing', layout_asset_version($path));
    }

    public function testRegisteredAssetUrlCarriesItsFullContentDigest(): void
    {
        $url = layout_asset_url('assets/core.js');

        self::assertMatchesRegularExpression('/^assets\/core\.js\?v=[0-9a-f]{64}$/', $url);
    }
}
