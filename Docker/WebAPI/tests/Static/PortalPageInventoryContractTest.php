<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * QUALITY-GATES.md promises that the health matrix and the axe scan visit every
 * portal page. That promise lives in a hand-written list (tests/e2e/lib/pages.js),
 * and a hand-written list is incomplete from the day it is written: the package
 * report pages shipped without an entry and nothing noticed. This test derives
 * the set from Docker/WebAPI/portal/ and requires every file to sit in exactly
 * one of the three lists: scanned page, non-page endpoint, or deferred page
 * with a reason.
 */
final class PortalPageInventoryContractTest extends TestCase
{
    public function testEveryPortalFileIsClassifiedExactlyOnce(): void
    {
        $lists = self::lists(self::pagesSource());
        $portal = self::portalFiles();

        self::assertSame([], self::problems($portal, $lists));
    }

    public function testGuardRejectsAnUnlistedPortalFile(): void
    {
        $lists = ['pages' => ['a.php'], 'endpoints' => [], 'deferred' => []];

        self::assertSame(
            ['portal/b.php is in none of PORTAL_PAGES, NON_PAGE_ENDPOINTS, MATRIX_DEFERRED_PAGES.'],
            self::problems(['a.php', 'b.php'], $lists)
        );
    }

    public function testGuardRejectsDoubleAndStaleEntries(): void
    {
        $lists = ['pages' => ['a.php', 'gone.php'], 'endpoints' => ['a.php'], 'deferred' => []];

        self::assertSame(
            [
                'portal/a.php is listed 2 times across the page lists.',
                'gone.php is listed but no longer exists in portal/.',
            ],
            self::problems(['a.php'], $lists)
        );
    }

    public function testGuardRejectsAnEmptyScan(): void
    {
        self::assertSame(
            ['No portal files found (zero-match must not pass).', 'PORTAL_PAGES matched nothing (zero-match must not pass).'],
            self::problems([], ['pages' => [], 'endpoints' => [], 'deferred' => []])
        );
    }

    /**
     * @param list<string> $portal
     * @param array{pages: list<string>, endpoints: list<string>, deferred: list<string>} $lists
     * @return list<string>
     */
    public static function problems(array $portal, array $lists): array
    {
        $problems = [];
        if ($portal === []) {
            $problems[] = 'No portal files found (zero-match must not pass).';
        }
        if ($lists['pages'] === []) {
            $problems[] = 'PORTAL_PAGES matched nothing (zero-match must not pass).';
        }

        // A path may appear twice in PORTAL_PAGES with different queries; that
        // is one classification, not two.
        $counts = array_count_values(array_merge(
            array_values(array_unique($lists['pages'])),
            $lists['endpoints'],
            $lists['deferred']
        ));
        foreach ($portal as $file) {
            $count = $counts[$file] ?? 0;
            if ($count === 0) {
                $problems[] = sprintf('portal/%s is in none of PORTAL_PAGES, NON_PAGE_ENDPOINTS, MATRIX_DEFERRED_PAGES.', $file);
            } elseif ($count > 1) {
                $problems[] = sprintf('portal/%s is listed %d times across the page lists.', $file, $count);
            }
        }
        foreach (array_keys($counts) as $listed) {
            if (!in_array((string) $listed, $portal, true)) {
                $problems[] = sprintf('%s is listed but no longer exists in portal/.', $listed);
            }
        }

        return $problems;
    }

    /**
     * Paths per list, without query strings.
     *
     * @return array{pages: list<string>, endpoints: list<string>, deferred: list<string>}
     */
    public static function lists(string $source): array
    {
        return [
            'pages' => self::pathsIn(self::block($source, 'PORTAL_PAGES'), "/path:\s*'([a-z0-9_]+\.php)/"),
            'endpoints' => self::pathsIn(self::block($source, 'NON_PAGE_ENDPOINTS'), "/^\s*'([a-z0-9_]+\.php)'/m"),
            'deferred' => self::pathsIn(self::block($source, 'MATRIX_DEFERRED_PAGES'), "/path:\s*'([a-z0-9_]+\.php)/"),
        ];
    }

    private static function block(string $source, string $name): string
    {
        $start = strpos($source, 'const ' . $name . ' = [');
        if ($start === false) {
            return '';
        }
        $end = strpos($source, "\n];", $start);

        return $end === false ? '' : substr($source, $start, $end - $start);
    }

    /** @return list<string> */
    private static function pathsIn(string $block, string $pattern): array
    {
        // Comment lines name pages in prose; only code lines classify.
        $code = (string) preg_replace('#^\s*//.*$#m', '', $block);
        preg_match_all($pattern, $code, $matches);

        return $matches[1];
    }

    private static function pagesSource(): string
    {
        $path = dirname(__DIR__, 4) . '/tests/e2e/lib/pages.js';
        if (!is_file($path)) {
            self::markTestSkipped('Repository root is not visible in this container mount.');
        }

        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    /** @return list<string> */
    private static function portalFiles(): array
    {
        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/portal/*.php') ?: []);
        sort($files);

        return $files;
    }
}
