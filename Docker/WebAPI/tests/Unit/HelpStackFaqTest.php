<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/help_page.php';

/**
 * The stack FAQ is read from the catalog (help_stack_faq()), not from a written
 * table of counts (W3D finding, N1D). This test holds the derivation against the
 * catalog in both directions: every stack_q/stack_a key of each locale renders
 * exactly once, and nothing renders that the catalog lacks. A key the
 * derivation cannot reach (a gap in a numbering, an answer without its
 * question) stays unrendered without any page error, which is the failure the
 * former table had; the synthetic cases prove the comparison catches it.
 */
final class HelpStackFaqTest extends TestCase
{
    private const FAQ_KEY = '/^help_stack\.stack_(q\d+|a\d+_(p|li)\d+)$/';

    public function testTheFaqRendersEveryCatalogKeyOnceAndNothingElse(): void
    {
        $shapes = [];
        foreach (Lang::LOCALES as $locale) {
            $catalogKeys = $this->catalogFaqKeys($locale);
            self::assertGreaterThan(20, count($catalogKeys), $locale . ': no FAQ keys found in help_stack');

            $faq = help_stack_faq(static fn (string $prefix): int => Lang::countNumbered($prefix, $locale));
            self::assertSame([], $this->mismatch($catalogKeys, $faq), $locale . ': FAQ keys the page does not render, or renders without a catalog entry');
            $shapes[$locale] = $faq;
        }

        self::assertSame($shapes['de'], $shapes['en'], 'DE and EN must have the same FAQ shape');
    }

    public function testEveryAnswerStartsWithAParagraphBeforeItsList(): void
    {
        $faq = help_stack_faq(static fn (string $prefix): int => Lang::countNumbered($prefix, Lang::DEFAULT_LOCALE));
        foreach ($faq as $entry) {
            self::assertNotSame([], $entry['before'], $entry['question'] . ' has no answer paragraph');
        }
    }

    public function testEveryOrderedListPinNamesAQuestionWithAList(): void
    {
        $faq = help_stack_faq(static fn (string $prefix): int => Lang::countNumbered($prefix, Lang::DEFAULT_LOCALE));
        self::assertNotSame([], VIRTUSPHERE_HELP_STACK_FAQ_ORDERED_LISTS);
        foreach (VIRTUSPHERE_HELP_STACK_FAQ_ORDERED_LISTS as $question) {
            $entry = $faq[$question - 1] ?? null;
            self::assertNotNull($entry, 'ordered list pin ' . $question . ' names no question');
            self::assertSame('ol', $entry['list_tag'], 'ordered list pin ' . $question . ' names a question without a list');
        }
        $unordered = array_filter($faq, static fn (array $entry): bool => $entry['list_tag'] === 'ul');
        self::assertNotSame([], $unordered, 'the default list kind must still be in use');
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function brokenCatalogs(): iterable
    {
        yield 'gap in the paragraphs' => [
            ['help_stack.stack_q1', 'help_stack.stack_a1_p1', 'help_stack.stack_a1_p3'],
            ['help_stack.stack_a1_p3'],
        ];
        yield 'answer without its question' => [
            ['help_stack.stack_q1', 'help_stack.stack_a1_p1', 'help_stack.stack_a2_p1'],
            ['help_stack.stack_a2_p1'],
        ];
        yield 'gap in the questions' => [
            ['help_stack.stack_q1', 'help_stack.stack_a1_p1', 'help_stack.stack_q3', 'help_stack.stack_a3_p1'],
            ['help_stack.stack_a3_p1', 'help_stack.stack_q3'],
        ];
        yield 'list item without the first one' => [
            ['help_stack.stack_q1', 'help_stack.stack_a1_p1', 'help_stack.stack_a1_li2'],
            ['help_stack.stack_a1_li2'],
        ];
    }

    /**
     * @param list<string> $catalogKeys
     * @param list<string> $expectedMissing
     */
    #[DataProvider('brokenCatalogs')]
    public function testTheComparisonFindsAKeyTheFaqCannotReach(array $catalogKeys, array $expectedMissing): void
    {
        $present = array_fill_keys($catalogKeys, true);
        $count = static function (string $prefix) use ($present): int {
            $n = 0;
            while (isset($present[$prefix . ($n + 1)])) {
                $n++;
            }

            return $n;
        };

        self::assertSame($expectedMissing, $this->mismatch($catalogKeys, help_stack_faq($count)));
    }

    public function testTheTemplateWritesNoCounts(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/help/stack.php');
        self::assertStringContainsString('help_stack_faq(', $template);
        self::assertDoesNotMatchRegularExpression("/'(pre|post|list)'\\s*=>/", $template, 'the FAQ shape must not be written into the template');
        self::assertDoesNotMatchRegularExpression("/__t\\('help_stack\\.stack_(q|a)/", $template, 'FAQ keys come from help_stack_faq(), not from the template');
    }

    /**
     * Catalog keys the FAQ does not render plus rendered keys the catalog lacks,
     * sorted. Duplicates in the rendering count as mismatches too.
     *
     * @param list<string> $catalogKeys
     * @param list<array{question: string, before: list<string>, list_tag: 'ol'|'ul'|null, items: list<string>, after: list<string>}> $faq
     * @return list<string>
     */
    private function mismatch(array $catalogKeys, array $faq): array
    {
        $rendered = [];
        foreach ($faq as $entry) {
            array_push($rendered, $entry['question'], ...$entry['before'], ...$entry['items'], ...$entry['after']);
        }
        $duplicates = array_keys(array_filter(array_count_values($rendered), static fn (int $n): bool => $n > 1));

        $mismatch = array_merge(
            array_diff($catalogKeys, $rendered),
            array_diff($rendered, $catalogKeys),
            $duplicates
        );
        sort($mismatch);

        return array_values(array_unique($mismatch));
    }

    /**
     * Every stack_q/stack_a key of one locale. The module of a key is its catalog
     * file name, so the FAQ keys live in help_stack.php by definition.
     *
     * @return list<string>
     */
    private function catalogFaqKeys(string $locale): array
    {
        $data = require dirname(__DIR__, 2) . '/lang/' . $locale . '/help_stack.php';
        self::assertIsArray($data);

        $keys = [];
        foreach (array_keys($data) as $key) {
            $qualified = 'help_stack.' . $key;
            if (preg_match(self::FAQ_KEY, $qualified) === 1) {
                $keys[] = $qualified;
            }
        }

        return $keys;
    }
}
