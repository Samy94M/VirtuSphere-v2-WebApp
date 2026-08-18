<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/connection_errors.php';

/**
 * Holds the three hand-written copies of one fact together (Etappe 7 review):
 * which categories connection_error_category() can actually return.
 *
 * The fact lives in its `$needles` keys plus the `parse` fallback. It is
 * mirrored twice more:
 *
 *  - in the `@return` union of its own docblock, which is the ONLY thing that
 *    lets PHPStan prove the match in ansible_connection_error_category_for_text()
 *    exhaustive;
 *  - in that match's own subjects.
 *
 * Without this test the two mirrors can drift silently in the one direction
 * that hurts: add a needle group, forget the docblock, and PHPStan stays green
 * because it believes the docblock, while the match now has no arm for the new
 * value. The result is an \UnhandledMatchError raised INSIDE the inventory
 * worker's `catch (Throwable)` - an \Error is not caught by that same catch, so
 * the job would lose its terminal state entirely instead of recording a wrong
 * category. Same shape, and same remedy, as DiskTypeLabelTest holding the
 * disk_type_label() union against VIRTUSPHERE_DISK_TYPES.
 *
 * Every extraction is guarded against a zero match, because an empty set would
 * make every comparison below trivially true and the guard permanently green.
 */
final class ConnectionErrorMappingContractTest extends TestCase
{
    private const FILE = __DIR__ . '/../../lib/connection_errors.php';

    public function testTheDocblockUnionListsExactlyWhatTheClassifierCanReturn(): void
    {
        $produced = $this->producibleCategories();
        $documented = $this->documentedUnion();

        self::assertSame(
            $this->sorted($produced),
            $this->sorted($documented),
            'The @return union of connection_error_category() no longer matches its own $needles keys plus the '
            . 'parse fallback. PHPStan trusts that union to prove the match in '
            . 'ansible_connection_error_category_for_text() exhaustive, so a stale union turns a build error into '
            . 'an \UnhandledMatchError inside the worker. Fix the docblock, not this test.'
        );
    }

    public function testTheAnsibleQualifierHasAnArmForEveryProducibleCategory(): void
    {
        $produced = $this->producibleCategories();
        $subjects = $this->matchSubjects();

        self::assertSame(
            $this->sorted($produced),
            $this->sorted($subjects),
            'ansible_connection_error_category_for_text() does not match on exactly the categories '
            . 'connection_error_category() can return. A missing arm is a runtime \UnhandledMatchError in the '
            . 'inventory worker; a surplus arm is dead code that hides which values are real.'
        );
    }

    public function testTheQualifierOnlyEverProducesAnsibleOriginCodes(): void
    {
        $targets = $this->matchTargets();

        foreach ($targets as $category) {
            self::assertTrue(
                inventory_error_is_ansible($category),
                sprintf(
                    'ansible_connection_error_category_for_text() maps onto "%s", which inventory_error_is_ansible() '
                    . 'does not recognise. The whole point of the qualifier is that its answer names the Ansible host.',
                    $category
                )
            );
        }
    }

    /**
     * The categories connection_error_category() can hand back: one per
     * $needles group, plus its fallback return.
     *
     * @return array<int, string>
     */
    private function producibleCategories(): array
    {
        $body = $this->functionSource('connection_error_category');

        self::assertSame(
            1,
            preg_match('/return\s+VIRTUSPHERE_INVENTORY_ERROR_([A-Z_]+);/', $body, $fallback),
            'connection_error_category() no longer has exactly one constant fallback return.'
        );

        preg_match_all('/VIRTUSPHERE_INVENTORY_ERROR_([A-Z_]+)\s*=>/', $body, $groups);
        self::assertNotSame([], $groups[1], 'Zero match: no $needles groups found in connection_error_category().');

        return $this->resolve(array_merge($groups[1], [$fallback[1]]));
    }

    /** @return array<int, string> The literals of the @return union. */
    private function documentedUnion(): array
    {
        $doc = (new ReflectionFunction('connection_error_category'))->getDocComment();
        self::assertIsString($doc, 'connection_error_category() lost its docblock, so PHPStan can no longer narrow it.');

        self::assertSame(1, preg_match("/@return\s+((?:'[a-z_]+'\s*\|\s*)*'[a-z_]+')/", $doc, $union), '@return union not found.');
        preg_match_all("/'([a-z_]+)'/", $union[1], $literals);
        self::assertNotSame([], $literals[1], 'Zero match: the @return union is empty.');

        return $literals[1];
    }

    /** @return array<int, string> The left-hand sides of the match arms. */
    private function matchSubjects(): array
    {
        $subjects = [];
        foreach (explode("\n", $this->matchArms()) as $line) {
            $left = str_contains($line, '=>') ? substr($line, 0, (int) strpos($line, '=>')) : $line;
            if (preg_match_all('/VIRTUSPHERE_INVENTORY_ERROR_([A-Z_]+)/', $left, $found) > 0) {
                $subjects = array_merge($subjects, $found[1]);
            }
        }
        self::assertNotSame([], $subjects, 'Zero match: no match subjects found.');

        return $this->resolve($subjects);
    }

    /** @return array<int, string> The right-hand sides of the match arms. */
    private function matchTargets(): array
    {
        $targets = [];
        foreach (explode("\n", $this->matchArms()) as $line) {
            if (!str_contains($line, '=>')) {
                continue;
            }
            $right = substr($line, (int) strpos($line, '=>'));
            if (preg_match_all('/VIRTUSPHERE_INVENTORY_ERROR_([A-Z_]+)/', $right, $found) > 0) {
                $targets = array_merge($targets, $found[1]);
            }
        }
        self::assertNotSame([], $targets, 'Zero match: no match targets found.');

        return $this->resolve($targets);
    }

    private function matchArms(): string
    {
        $body = $this->functionSource('ansible_connection_error_category_for_text');

        $match = strpos($body, 'match (');
        self::assertNotFalse($match, 'ansible_connection_error_category_for_text() no longer uses a match.');
        $open = strpos($body, '{', $match);
        self::assertNotFalse($open);
        $close = strpos($body, '};', $open);
        self::assertNotFalse($close, 'The match in ansible_connection_error_category_for_text() is not closed by "};".');

        return substr($body, $open + 1, $close - $open - 1);
    }

    /** Source of one top-level function, from its signature to its column-0 brace. */
    private function functionSource(string $name): string
    {
        $source = (string) file_get_contents(self::FILE);
        self::assertNotSame('', $source, 'connection_errors.php is unreadable.');

        $start = strpos($source, "\nfunction " . $name . "(");
        self::assertNotFalse($start, sprintf('function %s() not found in connection_errors.php.', $name));
        $end = strpos($source, "\n}", $start);
        self::assertNotFalse($end, sprintf('function %s() has no closing brace at column 0.', $name));

        return substr($source, $start, $end - $start);
    }

    /**
     * Constant suffixes to their stored values, so the comparison is against
     * what the database actually holds rather than against PHP identifiers.
     *
     * @param array<int, string> $suffixes
     * @return array<int, string>
     */
    private function resolve(array $suffixes): array
    {
        return array_map(static function (string $suffix): string {
            $name = 'VIRTUSPHERE_INVENTORY_ERROR_' . $suffix;
            self::assertTrue(defined($name), sprintf('%s is referenced but not defined.', $name));

            return (string) constant($name);
        }, $suffixes);
    }

    /** @param array<int, string> $values @return array<int, string> */
    private function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
