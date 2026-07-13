<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * os.php, packages.php and vlans.php share one catalog status filter. The token
 * set lives in VIRTUSPHERE_CATALOG_FILTERS (lib/constants.php) so the three
 * pages cannot drift apart. This pins that: no page may re-inline the
 * ['active','retired','all'] literal, and each must reference the constant. A
 * new catalog page copy-pasting the old literal fails here instead of silently
 * gaining a fourth, unvalidated token or losing one.
 */
final class CatalogFilterContractTest extends TestCase
{
    private const PAGES = ['portal/os.php', 'portal/packages.php', 'portal/vlans.php'];

    private function source(string $page): string
    {
        $path = str_replace('\\', '/', dirname(__DIR__, 2)) . '/' . $page;
        self::assertFileExists($path, $page . ' must exist');

        return (string) file_get_contents($path);
    }

    public function testNoPageInlinesTheFilterTokenList(): void
    {
        foreach (self::PAGES as $page) {
            $source = $this->source($page);
            self::assertSame(
                0,
                preg_match("/\\[\\s*'active'\\s*,\\s*'retired'\\s*,\\s*'all'\\s*\\]/", $source),
                $page . ' re-inlines the catalog filter list; use VIRTUSPHERE_CATALOG_FILTERS instead'
            );
        }
    }

    public function testEveryPageReferencesTheConstant(): void
    {
        foreach (self::PAGES as $page) {
            self::assertStringContainsString(
                'VIRTUSPHERE_CATALOG_FILTERS',
                $this->source($page),
                $page . ' must validate its status filter against VIRTUSPHERE_CATALOG_FILTERS'
            );
        }
    }
}
