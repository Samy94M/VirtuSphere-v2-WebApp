<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/release_version.php';

final class ReleaseVersionTest extends TestCase
{
    private const HEX_COMMIT = '0123456789abcdef0123456789abcdef01234567';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->files = [];
    }

    private function tempFile(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'vsrel');
        file_put_contents($file, $content);
        $this->files[] = $file;

        return $file;
    }

    /** @return array<string, array{string}> */
    public static function validReleases(): array
    {
        return [
            '2026.10.1' => ['2026.10.1'],
            '2026.01.99' => ['2026.01.99'],
            '2026.12.10' => ['2026.12.10'],
        ];
    }

    #[DataProvider('validReleases')]
    public function testAcceptsWellFormedReleases(string $value): void
    {
        self::assertTrue(release_is_valid($value));
    }

    /** @return array<string, array{string}> */
    public static function invalidReleases(): array
    {
        return [
            'month without leading zero' => ['2026.1.1'],
            'month 13' => ['2026.13.1'],
            'month 00' => ['2026.00.1'],
            'N zero' => ['2026.10.0'],
            'N above 99' => ['2026.10.100'],
            'N with leading zero' => ['2026.10.01'],
            'leading space' => [' 2026.10.1'],
            'suffix' => ['2026.10.1-rc'],
            'empty' => [''],
            // PCRE's $ would accept this; the exact match must not.
            'trailing newline' => ["2026.10.1\n"],
        ];
    }

    #[DataProvider('invalidReleases')]
    public function testRejectsEverythingElse(string $value): void
    {
        self::assertFalse(release_is_valid($value));
    }

    /** @return array<string, array{string, string}> */
    public static function releaseFileContents(): array
    {
        return [
            'LF' => ["2026.10.1\n", '2026.10.1'],
            'CRLF' => ["2026.10.1\r\n", '2026.10.1'],
            'trailing spaces' => ['2026.10.1   ', '2026.10.1'],
            'no line break' => ['2026.10.1', '2026.10.1'],
        ];
    }

    #[DataProvider('releaseFileContents')]
    public function testPortalReleaseTrimsTheOneLineFile(string $content, string $expected): void
    {
        self::assertSame($expected, portal_release($this->tempFile($content)));
    }

    /** @return array<string, array{string}> */
    public static function unusableReleaseFileContents(): array
    {
        return [
            'old format' => ["2026.1.1\n"],
            'suffix' => ["2026.10.1-rc\n"],
            'empty' => [''],
            'text' => ["release\n"],
        ];
    }

    #[DataProvider('unusableReleaseFileContents')]
    public function testPortalReleaseRejectsInvalidContent(string $content): void
    {
        self::assertNull(portal_release($this->tempFile($content)));
    }

    public function testPortalReleaseOfMissingFileIsNull(): void
    {
        self::assertNull(portal_release('/does/not/exist/VERSION'));
    }

    public function testTheRealReleaseFileGivesAValidRelease(): void
    {
        $release = portal_release();

        self::assertNotNull($release);
        self::assertTrue(release_is_valid($release));
    }

    public function testThePlaceholderBuildFileGivesNoCommit(): void
    {
        // The committed file keeps the export-subst placeholders; only git archive fills them.
        $path = dirname(__DIR__, 2) . '/lib/BUILD.json';
        self::assertFileExists($path);

        self::assertNull(portal_build_commit($this->tempFile((string) file_get_contents($path))));
    }

    public function testAFilledBuildFileGivesTheCommit(): void
    {
        $file = $this->tempFile(json_encode(['commit' => self::HEX_COMMIT, 'date' => '2026-10-15'], JSON_THROW_ON_ERROR));

        self::assertSame(self::HEX_COMMIT, portal_build_commit($file));
    }

    /** @return array<string, array{string}> */
    public static function unusableCommits(): array
    {
        return [
            'uppercase' => [strtoupper(self::HEX_COMMIT)],
            'short' => ['53ba24a'],
            'trailing newline' => [self::HEX_COMMIT . "\n"],
            'placeholder' => ['$Format:%H$'],
        ];
    }

    #[DataProvider('unusableCommits')]
    public function testOnlyAFortyCharacterLowercaseHashIsACommit(string $commit): void
    {
        $file = $this->tempFile(json_encode(['commit' => $commit], JSON_THROW_ON_ERROR));

        self::assertNull(portal_build_commit($file));
    }

    public function testBrokenJsonAndMissingBuildFileGiveNoCommit(): void
    {
        self::assertNull(portal_build_commit($this->tempFile('{"commit": "' . self::HEX_COMMIT)));
        self::assertNull(portal_build_commit($this->tempFile(json_encode(['commit' => 42], JSON_THROW_ON_ERROR))));
        self::assertNull(portal_build_commit('/does/not/exist/BUILD.json'));
    }

    /** @return array<string, array{?string, ?string, bool}> */
    public static function scriptIdentifiers(): array
    {
        return [
            'release' => ['packages-sync/2.0+2026.10.1', '2026.10.1', false],
            'release, deviating' => ['packages-sync/2.0+2026.10.1-abw', '2026.10.1', true],
            'old commit form' => ['device-sync/2.1+53ba24a', null, false],
            'old commit form, deviating' => ['device-sync/2.1+53ba24a-abw', null, true],
            'unknown placeholder' => ['device-sync/2.1+unbekannt', null, false],
            'no manifest placeholder' => ['device-sync/2.1+ohne-manifest', null, false],
            'impossible month' => ['device-sync/2.1+2026.13.1', null, false],
            'no plus sign' => ['device-sync/2.1', null, false],
            'last plus sign wins' => ['base+x+2026.10.1', '2026.10.1', false],
            'surrounding whitespace' => ["  packages-sync/2.0+2026.10.1 \n", '2026.10.1', false],
            'absent' => [null, null, false],
            'empty' => ['', null, false],
            'blank' => ['   ', null, false],
        ];
    }

    #[DataProvider('scriptIdentifiers')]
    public function testSplitsTheReleaseAndTheDeviationFromTheIdentifier(?string $identifier, ?string $release, bool $deviates): void
    {
        self::assertSame(['release' => $release, 'deviates' => $deviates], release_from_script_identifier($identifier));
    }

    public function testTheRealRegistryListsEveryMigrationOnceInAscendingOrder(): void
    {
        $names = release_code_migrations();

        self::assertNotSame([], $names);
        self::assertCount(count(array_unique($names)), $names, 'a migration name is registered twice');
        for ($i = 1, $count = count($names); $i < $count; $i++) {
            self::assertTrue(strcmp($names[$i - 1], $names[$i]) < 0, "not ascending at {$names[$i]}");
        }
    }

    public function testTheRealRegistryStartsWithTheFirstMigration(): void
    {
        self::assertSame(['0001_runtime_columns'], array_slice(release_code_migrations(), 0, 1));
    }

    public function testTheRealRegistryContainsTheHighestNumberedMigrationFile(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/lib/migrations/[0-9][0-9][0-9][0-9]_*.php') ?: [];
        sort($files, SORT_STRING);
        self::assertNotSame([], $files);

        $highest = basename($files[array_key_last($files)], '.php');

        self::assertContains($highest, release_code_migrations());
    }

    public function testReadsTheRegistryKeysAsTextAndNeverRunsTheFile(): void
    {
        // The throw proves the file is read, not included: an include would fail the test.
        $file = $this->tempFile(implode("\n", [
            '<?php',
            '',
            "throw new LogicException('the migration registry must be read, not included');",
            '',
            '$migrations = [',
            "    '0001_first' => function (mysqli \$db): void {",
            '    },',
            "    '0002_second' => migrate_0002_second(...),",
            "        '0003_decoy' => migrate_0003_decoy(...),",
            '];',
        ]));

        self::assertSame(['0001_first', '0002_second'], release_code_migrations($file));
    }

    public function testMissingRegistryGivesNoMigrations(): void
    {
        self::assertSame([], release_code_migrations('/does/not/exist/migrate.php'));
    }
}
