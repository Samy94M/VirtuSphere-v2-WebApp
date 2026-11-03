<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/audit_event_definitions.php';
require_once __DIR__ . '/../../lib/audit_registry.php';
require_once __DIR__ . '/../../lib/log_filter_vocabulary.php';

/**
 * Every value the audit filter offers has a localized name in both catalogs.
 *
 * The picker used to print the registry identifier itself. Nothing else in the
 * portal shows an event code, not the audit table and not the CSV export, so
 * the list was a vocabulary an operator could neither read nor learn; and a
 * leaf element whose entire text is `directory.bind_rejected` is byte for byte
 * what an untranslated `__t()` key looks like, which is exactly what the
 * browser i18n scan reported on logs.php. Six codes collided with a catalog
 * name by accident, and the picker of the deploy tab would have added sixteen
 * more the moment that tab was scanned.
 *
 * Both directions are checked, because each catches a different mistake. A
 * registered code without a label is a new event that would render raw. A label
 * without a registered code is a stale entry that outlived the event and would
 * quietly translate nothing.
 *
 * The check is a registry walk rather than a list: `audit_event_registry()` is
 * the single owner of what an audit row may be (webapi rules), so a new event
 * fails the build here instead of reaching an operator as an identifier.
 */
final class LogFilterVocabularyLabelTest extends TestCase
{
    private const LOCALES = ['de', 'en'];

    /** @return array<string, string> key => value of one locale's logs catalog */
    private function catalog(string $locale): array
    {
        $path = str_replace('\\', '/', dirname(__DIR__, 2)) . '/lang/' . $locale . '/logs.php';
        self::assertFileExists($path, $locale . ' logs catalog must exist');

        /** @var array<string, string> $catalog */
        $catalog = require $path;

        return $catalog;
    }

    /** The catalog key that names one event code. Mirrors the renderer. */
    private function eventKey(string $code): string
    {
        return 'eventcode_' . str_replace('.', '_', $code);
    }

    public function testEveryRegisteredEventCodeHasALabelInBothLocales(): void
    {
        $codes = log_filter_event_codes();
        self::assertNotSame([], $codes, 'the audit registry is empty; this walk would pass vacuously');

        foreach (self::LOCALES as $locale) {
            $catalog = $this->catalog($locale);
            $missing = [];
            foreach ($codes as $code) {
                $key = $this->eventKey($code);
                if (!isset($catalog[$key]) || trim((string) $catalog[$key]) === '') {
                    $missing[] = $key;
                }
            }
            self::assertSame(
                [],
                $missing,
                'lang/' . $locale . '/logs.php has no name for these event codes, so the filter would render '
                . 'their raw identifier'
            );
        }
    }

    public function testEveryRegisteredObjectTypeHasALabelInBothLocales(): void
    {
        $types = log_filter_object_types();
        self::assertNotSame([], $types, 'no object type is registered; this walk would pass vacuously');

        foreach (self::LOCALES as $locale) {
            $catalog = $this->catalog($locale);
            $missing = [];
            foreach ($types as $type) {
                $key = 'objecttype_' . $type;
                if (!isset($catalog[$key]) || trim((string) $catalog[$key]) === '') {
                    $missing[] = $key;
                }
            }
            self::assertSame([], $missing, 'lang/' . $locale . '/logs.php has no name for these object types');
        }
    }

    public function testNoLabelSurvivesItsValue(): void
    {
        $expected = [];
        foreach (log_filter_event_codes() as $code) {
            $expected[$this->eventKey($code)] = true;
        }
        foreach (log_filter_object_types() as $type) {
            $expected['objecttype_' . $type] = true;
        }

        foreach (self::LOCALES as $locale) {
            $stale = [];
            foreach (array_keys($this->catalog($locale)) as $key) {
                if (!str_starts_with($key, 'eventcode_') && !str_starts_with($key, 'objecttype_')) {
                    continue;
                }
                if (!isset($expected[$key])) {
                    $stale[] = $key;
                }
            }
            self::assertSame(
                [],
                $stale,
                'lang/' . $locale . '/logs.php names a vocabulary value the audit registry no longer has'
            );
        }
    }

    public function testTheRendererLabelsThroughTheHelperAndKeepsTheTokenVisible(): void
    {
        $path = str_replace('\\', '/', dirname(__DIR__, 2)) . '/lib/logs_filter_form.php';
        $source = (string) file_get_contents($path);

        self::assertMatchesRegularExpression(
            '/function logs_filter_vocabulary_label\(string \$key, string \$code\): string\s*\{\s*'
            . 'return __t\(\$key\) \. \' \(\' \. \$code \. \'\)\';/',
            $source,
            'the label helper must render the localized name AND the identifier; a runbook names the code'
        );
        self::assertSame(
            0,
            preg_match('/\$groups\[\$group\]\[\$code\]\s*=\s*\$code;/', $source),
            'the event picker must not use the code as its own label again'
        );
        self::assertSame(
            0,
            preg_match('/\$objectTypes\[\$objectType\]\s*=\s*\$objectType;/', $source),
            'the object type picker must not use the token as its own label again'
        );
    }
}
