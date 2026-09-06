<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A prefix comparison on an indexed name column must not go through LIKE.
 *
 * `deploy_missions.mission_name` is utf8mb4_unicode_ci with a unique index, and
 * MySQL 8.4 plans `LIKE 'prefix%'` on it as an index RANGE once the table is
 * big enough. A row whose character immediately after the prefix lies in a
 * supplementary plane (U+10000 and above) sorts outside the computed endpoints
 * and is silently missed. Measured, 36 rows: the range answers 19 of 21 while
 * `IGNORE INDEX` and `LEFT()` both answer 21.
 *
 * The defect is not a property of DELETE, which is how it was first written
 * down: a SELECT taking the same range loses the same rows. A small fixture
 * gets a full index scan instead and looks healthy, so nothing below the size
 * where the optimizer switches can see this.
 *
 * A two-sided pattern (`%term%`) is deliberately NOT covered: it can never form
 * a range, so `lib/repo/log.php`'s free-text search over `log_message`/`name`
 * is correct as it stands. What this guard forbids is the prefix shape.
 */
final class PrefixMatchContractTest extends TestCase
{
    /**
     * The file's code with its comments removed.
     *
     * Every rule here is one a comment has a legitimate reason to name: the
     * predicate in `0050` explains in prose why LIKE is wrong, and it says so
     * by quoting the wrong form. Matching the explanation of a rule as a
     * violation of it is how a guard teaches people to stop writing the
     * explanation, which is why `PortalPageNavContractTest` strips first too.
     */
    private function code(string $file): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** @return list<string> */
    private function libraryFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/lib/*.php') ?: [],
            glob($root . '/lib/*/*.php') ?: []
        );
        sort($files);

        return $files;
    }

    public function testNoLibraryFileBuildsALikePrefixPattern(): void
    {
        $files = $this->libraryFiles();
        self::assertNotSame([], $files, 'The library glob matched nothing.');

        foreach ($files as $file) {
            // `LIKE CONCAT(` is the prefix shape and has no legitimate use left:
            // a contains-search binds its own '%term%' and never concatenates
            // a wildcard in SQL.
            self::assertStringNotContainsString(
                'LIKE CONCAT(',
                $this->code($file),
                str_replace('\\', '/', $file) . ' compares a prefix with LIKE; use LEFT(col, CHAR_LENGTH(?)) = ?'
            );
        }
    }

    public function testTheTemplatePrefixIsNeverComparedWithLike(): void
    {
        $files = $this->libraryFiles();
        $scanned = 0;

        foreach ($files as $file) {
            $source = $this->code($file);
            if (!str_contains($source, 'VIRTUSPHERE_TEMPLATE_PREFIX')) {
                continue;
            }
            $scanned++;
            self::assertDoesNotMatchRegularExpression(
                '/\bNOT\s+LIKE\b|\bLIKE\s+[?\'"]/i',
                $source,
                str_replace('\\', '/', $file) . ' matches the template prefix with LIKE'
            );
        }

        // The constant is the anchor of this rule; a scan that stopped finding
        // it would report a clean repository while checking nothing.
        self::assertGreaterThan(0, $scanned, 'No library file mentions VIRTUSPHERE_TEMPLATE_PREFIX any more.');
    }

    public function testMigrationZeroFiftyUsesTheSafePredicate(): void
    {
        $file = dirname(__DIR__, 2) . '/lib/migrations/0050_mecm_rollout_hostname.php';
        $source = (string) file_get_contents($file);

        // All three predicates, so a partial correction cannot pass: two select
        // the ordinary missions, one selects the templates.
        self::assertSame(
            2,
            substr_count($source, 'LEFT(m.mission_name, CHAR_LENGTH(?)) <> ?'),
            'Both non-template predicates of 0050 must use LEFT()'
        );
        self::assertSame(
            1,
            substr_count($source, 'LEFT(m.mission_name, CHAR_LENGTH(?)) = ?'),
            'The template predicate of 0050 must use LEFT()'
        );
    }
}
