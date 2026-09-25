<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OfflineToolsBundleContractTest extends TestCase
{
    public function testCoreAndOptionalToolsHaveSeparateClosedManifests(): void
    {
        $root = dirname(__DIR__, 4);
        $source = (string) file_get_contents($root . '/scripts/build-offline-bundle.sh');

        self::assertStringContainsString('docker compose --project-directory "$ROOT" config --images', $source);
        self::assertStringContainsString('comm -13 "$STAGE/.core-refs" "$STAGE/.all-refs"', $source);
        self::assertStringContainsString('$STAGE/images.txt', $source);
        self::assertStringContainsString('$STAGE/tools/images.txt', $source);
        self::assertStringContainsString('save_image_set "$STAGE/.core-refs"', $source);
        self::assertStringContainsString('save_image_set "$STAGE/.tool-refs"', $source);
        self::assertStringContainsString('sha256sum -c SHA256SUMS', $source);
        self::assertStringContainsString('docker compose --profile tools up -d phpmyadmin', $source);
        self::assertStringContainsString('PHP_IMAGE=virtusphere-php:8.4-tooling', $source);
        self::assertStringNotContainsString('PHP_IMAGE=virtusphere-v2-webapp-php', $source);
    }
}
