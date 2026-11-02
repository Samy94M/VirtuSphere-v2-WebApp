<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/audit_presenter.php';
require_once dirname(__DIR__, 2) . '/lib/audit_vocabulary_lists.php';

/**
 * The closed words of an audit context reach a German reader as German words
 * (HB-08 rest), while the stored English sentence keeps the word as written.
 *
 * Everything here is derived: the word lists from their owners
 * (audit_code_vocabularies()), the translated fields and context keys from the
 * presenter source, the integration states from lib/status.php. A new word, a new
 * field or a writer that types a word by hand fails here without a list to edit.
 */
final class AuditCodeVocabularyContractTest extends TestCase
{
    private const SOURCE_ROOT = __DIR__ . '/../..';

    /** @return array<string,string> */
    private function catalog(string $locale): array
    {
        $catalog = require self::SOURCE_ROOT . '/lang/' . $locale . '/audit_log.php';
        self::assertIsArray($catalog);

        return $catalog;
    }

    public function testEveryClosedWordHasALabelInBothLocalesAndTheEnglishLabelIsTheWord(): void
    {
        $en = $this->catalog('en');
        $de = $this->catalog('de');
        $vocabularies = audit_code_vocabularies();
        self::assertNotEmpty($vocabularies, 'zero-match: no vocabulary');

        $count = 0;
        foreach ($vocabularies as $field => $words) {
            self::assertNotEmpty($words, $field . ': zero-match, the list is empty');
            $keys = [];
            foreach ($words as $word) {
                $key = audit_code_key($field, $word);
                self::assertArrayNotHasKey($key, $keys, $field . ': "' . $word . '" and "' . ($keys[$key] ?? '') . '" share the key ' . $key);
                $keys[$key] = $word;
                self::assertArrayHasKey($key, $en, $field . ': "' . $word . '" has no English label (' . $key . ')');
                self::assertArrayHasKey($key, $de, $field . ': "' . $word . '" has no German label (' . $key . ')');
                // The writers render with 'en' and store the result, so this is what
                // keeps every stored sentence byte for byte what it was.
                self::assertSame($word, $en[$key], $key . ': the English label must be the stored word itself');
                self::assertNotSame('', trim($de[$key]), $key . ': empty German label');
                self::assertStringNotContainsString("\u{2014}", $de[$key], $key . ': em dash');
                self::assertStringNotContainsString("\u{2013}", $de[$key], $key . ': en dash');
                $count++;
            }
        }
        self::assertGreaterThan(0, $count);
    }

    public function testNoCodeLabelIsLeftOverFromAWordThatNoLongerExists(): void
    {
        $expected = [];
        foreach (audit_code_vocabularies() as $field => $words) {
            foreach ($words as $word) {
                $expected[audit_code_key($field, $word)] = true;
            }
        }
        foreach (array_keys($this->catalog('en')) as $key) {
            if (str_starts_with($key, 'code_')) {
                self::assertArrayHasKey($key, $expected, $key . ' labels a word no vocabulary lists');
            }
        }
    }

    public function testThePresenterShowsEveryVocabularyFieldAndNoOther(): void
    {
        $used = array_keys($this->translatedContextKeysByField());
        sort($used);
        $declared = array_keys(audit_code_vocabularies());
        sort($declared);

        self::assertSame($declared, $used, 'the presenter fields and audit_code_vocabularies() differ');
    }

    public function testNoAuditWriterTypesATranslatedWordByHand(): void
    {
        $keys = array_values(array_unique(array_merge(...array_values($this->translatedContextKeysByField()))));
        self::assertNotEmpty($keys, 'zero-match: no translated context key');
        $quotedKeys = implode('|', array_map(static fn (string $key): string => preg_quote($key, '/'), $keys));

        $calls = 0;
        $literals = [];
        foreach ($this->firstPartyFiles() as $relative => $source) {
            foreach ($this->auditCalls($source) as $call) {
                $calls++;
                if (preg_match_all("/'(" . $quotedKeys . ")'\s*=>\s*(?:[^,\]\n]*\?\s*)?'([^']*)'/", $call, $matches, PREG_SET_ORDER) > 0) {
                    foreach ($matches as $match) {
                        $literals[] = $relative . ": '" . $match[1] . "' => '" . $match[2] . "'";
                    }
                }
            }
        }

        self::assertGreaterThan(20, $calls, 'zero-match: the audit call scan found almost nothing');
        self::assertSame([], $literals, 'an audit writer types a closed word instead of using its constant (lib/audit_vocabulary.php or the list owner)');
    }

    public function testTheIntegrationStatesAreTheOnesLibStatusAssigns(): void
    {
        $source = (string) file_get_contents(self::SOURCE_ROOT . '/lib/status.php');
        preg_match('/function virtusphere_heartbeat_state_rank\(.*?\n\}/s', $source, $rank);
        self::assertNotEmpty($rank, 'virtusphere_heartbeat_state_rank() not found');
        preg_match_all("/'([a-z_]+)'\s*=>\s*\d/", $rank[0], $arms);
        preg_match_all("/'([a-z_]+)' shares the rank/", $rank[0], $shared);
        $states = array_values(array_unique([...$arms[1], ...$shared[1], 'unknown']));
        sort($states);
        $declared = VIRTUSPHERE_AUDIT_INTEGRATION_STATES;
        sort($declared);

        self::assertNotEmpty($arms[1], 'zero-match: no rank arm');
        self::assertSame($states, $declared, 'VIRTUSPHERE_AUDIT_INTEGRATION_STATES differs from the states lib/status.php ranks');
    }

    public function testTheGermanModeWordIsTheModeDisplayLabel(): void
    {
        $de = $this->catalog('de');
        $status = require self::SOURCE_ROOT . '/lang/de/status.php';
        foreach (audit_code_vocabularies()['deploy_mode'] as $mode) {
            // i18n R9: a single mode is named by its status.mode_* label only.
            self::assertSame($status['mode_' . $mode] ?? null, $de[audit_code_key('deploy_mode', $mode)] ?? null, $mode);
        }
    }

    /**
     * Field => context keys, read from the presenter: every call of
     * audit_code_label() names its field and, on the same line, the context key
     * it reads.
     *
     * @return array<string, list<string>>
     */
    private function translatedContextKeysByField(): array
    {
        $map = [];
        foreach (['lib/audit_presenter.php', 'lib/audit_presenter_parts.php'] as $file) {
            foreach (explode("\n", (string) file_get_contents(self::SOURCE_ROOT . '/' . $file)) as $line) {
                if (preg_match_all("/audit_code_label\(\\\$locale, '([a-z_]+)'/", $line, $fields) === 0) {
                    continue;
                }
                preg_match_all("/\\\$context\['([a-z_]+)'\]/", $line, $keys);
                self::assertNotEmpty($keys[1], $file . ': a code label must read its context key on the same line: ' . trim($line));
                foreach ($fields[1] as $field) {
                    $map[$field] = array_values(array_unique([...($map[$field] ?? []), ...$keys[1]]));
                }
            }
        }
        self::assertNotEmpty($map, 'zero-match: the presenter calls audit_code_label() nowhere');

        return $map;
    }

    /** @return list<string> The argument text of every audit write call. */
    private function auditCalls(string $source): array
    {
        $calls = [];
        $offset = 0;
        while (preg_match('/(?<![\w>:$])(audit_event_required|audit_event|machine_api_audit_warning)\s*\(/', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1] + strlen($match[0][0]);
            $depth = 1;
            $end = $start;
            $length = strlen($source);
            while ($end < $length && $depth > 0) {
                $char = $source[$end];
                if ($char === "'" || $char === '"') {
                    $close = strpos($source, $char, $end + 1);
                    while ($close !== false && $source[$close - 1] === '\\') {
                        $close = strpos($source, $char, $close + 1);
                    }
                    $end = $close === false ? $length : $close + 1;
                    continue;
                }
                $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
                $end++;
            }
            $calls[] = substr($source, $start, $end - $start);
            $offset = $end;
        }

        return $calls;
    }

    /** @return array<string,string> */
    private function firstPartyFiles(): array
    {
        $app = str_replace('\\', '/', (string) realpath(self::SOURCE_ROOT));
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (!str_ends_with($path, '.php')
                || str_contains($path, '/tests/')
                || str_contains($path, '/vendor/')
                || str_contains($path, '/lang/')
                || str_ends_with($path, '/lib/repo/log.php')) {
                continue;
            }
            $files[substr($path, strlen($app) + 1)] = $this->withoutComments((string) file_get_contents($path));
        }
        self::assertNotEmpty($files, 'zero-match: the first-party file scan matched nothing');

        return $files;
    }

    private function withoutComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            $out .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
        }

        return $out;
    }
}
