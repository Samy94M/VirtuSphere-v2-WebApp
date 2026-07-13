<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/repo/catalog.php';

/**
 * catalog_normalize_status() is the single source that folds the free-text
 * catalog status onto the two stored values. The repo validators call it on
 * every write and migration 0017 applies the same mapping to existing rows, so
 * the badge renderer only ever sees the canonical spellings for known values.
 * Unknown text passes through untouched (narrowing the legacy API is E3).
 */
final class CatalogNormalizeStatusTest extends TestCase
{
    public function testActiveSynonymsFoldToCanonicalDefault(): void
    {
        foreach (['Aktiv', 'aktiv', 'active', 'ACTIVE', '  active  '] as $input) {
            self::assertSame(VIRTUSPHERE_CATALOG_STATUS_DEFAULT, catalog_normalize_status($input), $input);
        }
    }

    public function testRetiredSynonymsFoldToCanonicalRetired(): void
    {
        foreach (['Retired', 'retired', 'RETIRED'] as $input) {
            self::assertSame(VIRTUSPHERE_CATALOG_STATUS_RETIRED, catalog_normalize_status($input), $input);
        }
    }

    public function testUnknownStatusPassesThroughUnchanged(): void
    {
        self::assertSame('Testing', catalog_normalize_status('Testing'));
        self::assertSame('', catalog_normalize_status(''));
    }

    public function testNormalizationIsIdempotent(): void
    {
        $once = catalog_normalize_status('active');
        self::assertSame($once, catalog_normalize_status($once));
    }
}
