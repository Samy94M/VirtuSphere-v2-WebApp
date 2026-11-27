<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/lang.php';
require_once dirname(__DIR__, 2) . '/lib/mecm_terms.php';

/**
 * MECM terms per console language (PR-R3M, i18n R15). Lang fills
 * `{mecm:<name>}` from the term set the environment selects, in the same single
 * pass as the call-site placeholders, and the selection is display-only.
 */
final class LangMecmTermsTest extends TestCase
{
    protected function tearDown(): void
    {
        Lang::useMecmTerms(Lang::MECM_CONSOLE_DEFAULT);
    }

    // A fresh process: an earlier test in the suite may have selected a set.
    #[RunInSeparateProcess]
    public function testWithoutASelectionTheDefaultTermSetApplies(): void
    {
        self::assertSame('de', Lang::MECM_CONSOLE_DEFAULT, 'the user decision of 25.11.2026: German console words by default');
        self::assertSame(Lang::MECM_CONSOLE_DEFAULT, Lang::mecmConsole());
        $site = Lang::mecmTerms(Lang::MECM_CONSOLE_DEFAULT)['site'];
        self::assertSame($site['de'], Lang::fill('{mecm:site}', [], 'de'));
        self::assertSame($site['en'], Lang::fill('{mecm:site}', [], 'en'));
    }

    public function testTermAndCallSitePlaceholdersFillInOnePass(): void
    {
        $terms = Lang::mecmTerms(Lang::MECM_CONSOLE_DEFAULT);
        // The caller's value reads like a term placeholder and like another
        // call-site placeholder; neither may be filled again.
        $text = Lang::fill('{mecm:Site} :name in {mecm:collections}', ['name' => '{mecm:collection} :count', 'count' => 3], 'de');

        self::assertSame(mb_ucfirst($terms['site']['de']) . ' {mecm:collection} :count in ' . $terms['collections']['de'], $text);
    }

    public function testACallSitePlaceholderInsideATermPlaceholderIsNotFilled(): void
    {
        // ':site' is also the tail of '{mecm:site}'; the longer match wins.
        $terms = Lang::mecmTerms(Lang::MECM_CONSOLE_DEFAULT);

        self::assertSame($terms['site']['de'] . ' X', Lang::fill('{mecm:site} :site', ['site' => 'X'], 'de'));
    }

    public function testThePortalLocalePicksTheFormAndTheCapitalVariantRaisesTheFirstLetter(): void
    {
        Lang::useMecmTerms('en');
        $collection = Lang::mecmTerms('en')['collection'];

        self::assertSame($collection['de'], Lang::fill('{mecm:collection}', [], 'de'));
        self::assertSame($collection['en'], Lang::fill('{mecm:collection}', [], 'en'));
        self::assertSame(mb_ucfirst($collection['en']), Lang::fill('{mecm:Collection}', [], 'en'));
        self::assertNotSame($collection['en'], mb_ucfirst($collection['en']), 'the English set writes running text in lower case');
    }

    /**
     * Console paths (`path_*`, PR-R3MP) are the console's own text: the same in
     * both portal languages, with the nodes of the English console path, and a
     * path is either wholly the English one or translated in every node (user
     * rule of 27.11.2026: German only with two independent sources per node,
     * never a mixed path).
     */
    public function testAConsolePathIsConsoleTextAndNeverMixed(): void
    {
        $english = Lang::mecmTerms('en');
        $paths = array_filter(array_keys($english), static fn (string $name): bool => str_starts_with($name, 'path_'));
        self::assertNotSame([], $paths, 'the term sets name console paths');
        foreach (Lang::mecmConsoleLanguages() as $console) {
            $terms = Lang::mecmTerms($console);
            foreach ($paths as $name) {
                $label = $console . ': ' . $name;
                self::assertSame($terms[$name]['de'], $terms[$name]['en'], $label . ' differs between portal languages');
                $nodes = explode(' → ', $terms[$name]['de']);
                $englishNodes = explode(' → ', $english[$name]['de']);
                self::assertCount(count($englishNodes), $nodes, $label . ' has other nodes than the English console path');
                $same = count(array_intersect_assoc($nodes, $englishNodes));
                self::assertTrue($same === 0 || $same === count($nodes), $label . ' mixes English and translated nodes');
            }
        }
    }

    public function testSwitchingTheConsoleLanguageSwitchesTheCatalogText(): void
    {
        $seen = [];
        foreach (Lang::mecmConsoleLanguages() as $console) {
            Lang::useMecmTerms($console);
            self::assertSame($console, Lang::mecmConsole());
            $text = Lang::getIn('de', 'system_status_overview.mecm_site');
            self::assertSame(Lang::mecmTerms($console)['site']['de'], $text, $console);
            self::assertStringNotContainsString('{mecm:', Lang::getIn('en', 'system_status.site_hint_limited'), $console);
            $seen[$text] = true;
        }
        self::assertGreaterThanOrEqual(2, count(Lang::mecmConsoleLanguages()), 'German and English term sets');
        self::assertCount(count(Lang::mecmConsoleLanguages()), $seen, 'each term set names the site differently');
    }

    public function testAnUnknownPlaceholderStaysVisible(): void
    {
        self::assertSame('{mecm:no_such_term}', Lang::fill('{mecm:no_such_term}', [], 'de'));
    }

    public function testAnUnknownConsoleLanguageFallsBackToTheDefault(): void
    {
        Lang::useMecmTerms('en');
        Lang::useMecmTerms('xx');

        self::assertSame(Lang::MECM_CONSOLE_DEFAULT, Lang::mecmConsole());
        self::assertFalse(mecm_console_language_is_valid('xx'));
        self::assertFalse(mecm_console_language_is_valid(''));
        self::assertTrue(mecm_console_language_is_valid(Lang::MECM_CONSOLE_DEFAULT));
    }

    public function testEveryConsoleLanguageHasASettingsLabelInBothLocales(): void
    {
        $languages = Lang::mecmConsoleLanguages();
        self::assertContains(Lang::MECM_CONSOLE_DEFAULT, $languages, 'the default needs its term set');
        foreach ($languages as $language) {
            foreach (Lang::LOCALES as $locale) {
                $key = 'settings.mecm_console_language_' . $language;
                self::assertNotSame($key, Lang::getIn($locale, $key), $locale . ': no label for the term set ' . $language);
            }
        }
    }

    /**
     * Display-only (i18n R6, PR-R3M): the selection is read by the bootstrap and
     * the settings page only, never by an auth, deploy, status or machine API
     * path. Derived from the source tree, not from a list of files to check.
     */
    public function testTheConsoleLanguageIsReadOnlyForTheDisplay(): void
    {
        $root = dirname(__DIR__, 2);
        // constants.php defines the key; lang.php and mecm_terms.php own it.
        $allowed = [
            'lib/bootstrap.php', 'lib/constants.php', 'lib/lang.php', 'lib/mecm_terms.php',
            'lib/settings_actions.php', 'lib/settings_mecm_terms_action.php', 'lib/settings_view_model.php',
        ];
        $files = array_merge(
            glob($root . '/*.php') ?: [],
            glob($root . '/portal/*.php') ?: [],
            glob($root . '/lib/*.php') ?: [],
            glob($root . '/lib/*/*.php') ?: [],
        );
        self::assertNotSame([], $files, 'no PHP sources found; the scan would prove nothing');

        $readers = [];
        foreach ($files as $file) {
            $relative = substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', $root)) + 1);
            $source = (string) file_get_contents($file);
            if (preg_match('/mecm_console_language\s*\(|VIRTUSPHERE_SETTING_MECM_CONSOLE_LANGUAGE|Lang::(useMecmTerms|mecmConsole)\s*\(/', $source) === 1) {
                $readers[] = $relative;
            }
        }
        sort($readers);

        self::assertSame([], array_values(array_diff($readers, $allowed)), 'the MECM console language reaches a non-display path');
        self::assertContains('lib/bootstrap.php', $readers, 'the bootstrap selects the term set');
    }
}
