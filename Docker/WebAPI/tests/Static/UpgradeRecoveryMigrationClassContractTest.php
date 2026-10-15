<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * docs/operations/upgrade-recovery.md classifies every migration by its return
 * boundary (A additive, N normalising, C contract boundary). An operator reads
 * that table to decide between a rollback and a restore, so a migration that is
 * missing from it, listed twice, or listed without existing is a wrong answer
 * that no test of the migration itself would show.
 *
 * The migration world is derived from two sources and their union is the
 * expected set. Files: lib/migrations/NNNN_*.php. Registry: the `'NNNN_name' =>`
 * keys of lib/migrate.php, because 0001-0041 have no file of their own; they
 * are closures inside the registry. A file-only derivation would call those 41
 * classified migrations "without a migration file" and fail on a correct table.
 */
final class UpgradeRecoveryMigrationClassContractTest extends TestCase
{
    /** @var list<string> */
    private const CLASSES = ['A', 'N', 'C'];

    public function testEveryMigrationHasExactlyOneReturnBoundaryClass(): void
    {
        $webApi = dirname(__DIR__, 2);
        $files = glob($webApi . '/lib/migrations/[0-9][0-9][0-9][0-9]_*.php') ?: [];
        self::assertNotSame([], $files, 'lib/migrations holds no migration file; the glob moved');
        $fileNumbers = array_map(static fn (string $path): string => substr(basename($path), 0, 4), $files);

        $registry = (string) file_get_contents($webApi . '/lib/migrate.php');
        preg_match_all("/^\s*'([0-9]{4})_[a-z0-9_]+'\s*=>/m", $registry, $registered);
        self::assertNotSame([], $registered[1], 'lib/migrate.php registers no migration; the registry moved');

        $doc = (string) file_get_contents(dirname(__DIR__, 4) . '/docs/operations/upgrade-recovery.md');
        self::assertSame([], $this->problems($doc, $fileNumbers, $registered[1]));
    }

    public function testGuardDetectsUnclassifiedDuplicatedPhantomAndMissingRows(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 4) . '/docs/operations/upgrade-recovery.md');
        $all = array_map(static fn (int $n): string => sprintf('%04d', $n), range(1, 64));

        self::assertSame([], $this->problems($doc, $all, $all), 'the real table must be clean before the mutations mean anything');

        $mutations = [
            'unclassified 0064' => str_replace(', 0064 |', ' |', $doc),
            'listed in two classes' => str_replace('| 0028, 0034,', '| 0001, 0028, 0034,', $doc),
            'listed twice in one class' => str_replace('| 0003, 0008,', '| 0003, 0003, 0008,', $doc),
            'phantom 0099' => str_replace(', 0064 |', ', 0064, 0099 |', $doc),
            'class N row removed' => (string) preg_replace('/^\| N — .*\n/m', '', $doc),
            'non-numeric cell' => str_replace('| 0001, 0002,', '| 0001 bis 0002,', $doc),
        ];
        foreach ($mutations as $name => $mutated) {
            self::assertNotSame($doc, $mutated, $name . ': the mutation did not change the table text');
            self::assertNotSame([], $this->problems($mutated, $all, $all), $name . ': the guard stayed green');
        }
    }

    /**
     * @param list<string> $fileNumbers     NNNN of every lib/migrations/NNNN_*.php
     * @param list<string> $registryNumbers NNNN of every key registered in lib/migrate.php
     * @return list<string> empty when the table classifies exactly the migration world
     */
    private function problems(string $markdown, array $fileNumbers, array $registryNumbers): array
    {
        $problems = [];
        $rows = $this->classRows($markdown);
        $placed = [];
        foreach (self::CLASSES as $class) {
            $cells = $rows[$class] ?? [];
            if (count($cells) !== 1) {
                $problems[] = sprintf('class %s must have exactly one row, found %d', $class, count($cells));
                continue;
            }
            if (preg_match('/^\s*[0-9]{4}(\s*,\s*[0-9]{4})*\s*$/', $cells[0]) !== 1) {
                $problems[] = sprintf('class %s lists something other than comma-separated 4-digit numbers', $class);
            }
            preg_match_all('/[0-9]{4}/', $cells[0], $numbers);
            foreach ($numbers[0] as $number) {
                $placed[$number][] = $class;
            }
        }

        $known = array_values(array_unique([...$fileNumbers, ...$registryNumbers]));
        foreach ($known as $number) {
            $count = count($placed[$number] ?? []);
            if ($count === 0) {
                $problems[] = sprintf('migration %s is in no class row', $number);
            }
        }
        foreach ($placed as $number => $classes) {
            if (!in_array((string) $number, $known, true)) {
                $problems[] = sprintf('class row lists %s, which is no migration', $number);
            }
            if (count($classes) > 1) {
                $problems[] = sprintf('migration %s is listed %d times (%s)', $number, count($classes), implode(', ', $classes));
            }
        }

        return $problems;
    }

    /**
     * The cell with the migration numbers, one per row whose first column starts
     * with the class letter and the em dash.
     *
     * @return array<string, list<string>> class letter => raw cell text of each matching row
     */
    private function classRows(string $markdown): array
    {
        preg_match_all('/^\| ([ANC]) — [^|]*\|([^|]*)\|/m', $markdown, $matches, PREG_SET_ORDER);
        $rows = [];
        foreach ($matches as $match) {
            $rows[$match[1]][] = $match[2];
        }

        return $rows;
    }
}
