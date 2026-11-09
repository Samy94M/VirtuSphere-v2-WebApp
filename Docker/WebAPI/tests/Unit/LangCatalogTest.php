<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LangCatalogTest extends TestCase
{
    public function testCatalogPlaceholderParityAndNoEmptyStrings(): void
    {
        // Module lists first: when glob() misses a whole file (seen once as an
        // unreproducible failure on the container bind mount), the assertion
        // should name the missing module instead of dumping a thousand-key diff.
        self::assertSame($this->modules('de'), $this->modules('en'), 'DE and EN must ship the same lang modules');

        $catalogs = [
            'de' => $this->catalog('de'),
            'en' => $this->catalog('en'),
        ];

        self::assertSame(array_keys($catalogs['de']), array_keys($catalogs['en']));

        foreach ($catalogs['de'] as $key => $deValue) {
            $enValue = $catalogs['en'][$key];
            self::assertNotSame('', trim($deValue), $key . ' has empty DE text');
            self::assertNotSame('', trim($enValue), $key . ' has empty EN text');
            self::assertSame($this->placeholders($deValue), $this->placeholders($enValue), $key . ' placeholder parity');
        }
    }

    public function testTranslationLiteralsUsedByPortalAndLibExistInGermanCatalog(): void
    {
        $catalog = $this->catalog('de');
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/portal/*.php') ?: [],
            array_filter(glob($root . '/lib/*.php') ?: [], static fn (string $file): bool => basename($file) !== 'lang.php'),
            glob($root . '/lib/repo/*.php') ?: []
        );

        $missing = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            // Only fully static __t('key') / __t('key', [...]) calls can be
            // resolved here; keys assembled by concatenation (__t('prefix' . $x))
            // are dynamic and validated by their catalog entries at runtime, so
            // the trailing ) or , requirement skips them deliberately.
            preg_match_all("/__t\\('([^']+)'\\s*[),]/", $source, $matches);
            foreach ($matches[1] as $key) {
                if (!array_key_exists($key, $catalog)) {
                    $missing[] = str_replace($root . '/', '', $file) . ':' . $key;
                }
            }
        }

        self::assertSame([], array_values(array_unique($missing)));
    }

    public function testNumberedHelpListsRenderEveryCatalogKeyWithoutAGap(): void
    {
        // The identity-cause help loops to Lang::countNumbered() instead of a
        // written count; a gap in the numbering would end the loop early and
        // hide every later key, so the keys must run 1..n without a hole.
        $prefix = 'help_system_status.system_status_identity_';
        $counts = [];
        foreach (Lang::LOCALES as $locale) {
            $numbers = [];
            foreach (array_keys($this->catalog($locale)) as $key) {
                if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $key, $match) === 1) {
                    $numbers[] = (int) $match[1];
                }
            }
            sort($numbers);
            self::assertNotSame([], $numbers, $locale . ': no numbered key under ' . $prefix);
            self::assertSame(range(1, count($numbers)), $numbers, $locale . ': numbered keys must run 1..n without a gap');
            $counts[$locale] = Lang::countNumbered($prefix, $locale);
            self::assertSame(count($numbers), $counts[$locale], $locale . ': countNumbered must reach the last key');
        }
        self::assertSame($counts['de'], $counts['en']);

        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/help/system_status.php');
        self::assertStringContainsString("Lang::countNumbered('" . $prefix . "')", $template);
        self::assertDoesNotMatchRegularExpression('/\$identityCause\s*<=\s*\d/', $template, 'the loop bound must not be a written number');
    }

    public function testCountNumberedIsZeroForAnAbsentPrefixAndFallsBackForAnUnknownLocale(): void
    {
        self::assertSame(0, Lang::countNumbered('help_system_status.no_such_numbered_key_', 'de'));
        // An unknown locale falls back to the default catalog, as getIn() does.
        self::assertSame(
            Lang::countNumbered('help_system_status.system_status_identity_', 'de'),
            Lang::countNumbered('help_system_status.system_status_identity_', 'xx')
        );
    }

    /**
     * @return list<string>
     */
    private function modules(string $locale): array
    {
        $files = glob(dirname(__DIR__, 2) . '/lang/' . $locale . '/*.php') ?: [];
        sort($files);

        return array_map(static fn (string $file): string => basename($file, '.php'), $files);
    }

    /**
     * @return array<string, string>
     */
    private function catalog(string $locale): array
    {
        $root = dirname(__DIR__, 2) . '/lang/' . $locale;
        $files = glob($root . '/*.php') ?: [];
        sort($files);

        $catalog = [];
        foreach ($files as $file) {
            $module = basename($file, '.php');
            $data = require $file;
            self::assertIsArray($data, $file);
            foreach ($data as $key => $value) {
                self::assertIsString($key, $file);
                self::assertIsString($value, $file . ':' . $key);
                $catalog[$module . '.' . $key] = $value;
            }
        }
        ksort($catalog);

        return $catalog;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $value): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $value, $matches);
        $placeholders = $matches[1];
        sort($placeholders);

        return $placeholders;
    }
}
