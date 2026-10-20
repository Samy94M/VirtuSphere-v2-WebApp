<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/audit_presenter.php';

/**
 * The German rendering of the audit sentences (lang/de/audit_log.php). The English
 * rendering is pinned by AuditPresenterGoldenTest; this file proves that every golden
 * row also renders in German without a gap, and that the catalogs agree.
 */
final class AuditPresenterLocaleTest extends TestCase
{
    /** @return list<array{id:string,event:string,object_type:string,object_id:?string,result:string,context:array<string,mixed>}> */
    private function cases(): array
    {
        $cases = require dirname(__DIR__) . '/fixtures/audit_presenter_cases.php';
        self::assertIsArray($cases);
        self::assertNotEmpty($cases, 'zero-match: the case fixture must hold cases');

        return $cases;
    }

    /** @return list<string> */
    private function placeholderWords(string $text): array
    {
        preg_match_all('/:[a-z][a-z0-9_]*/', $text, $matches);
        // A set, not a sequence: German word order may place the values differently.
        $words = array_values(array_unique($matches[0]));
        sort($words, SORT_STRING);

        return $words;
    }

    private function render(array $case, string $locale): string
    {
        return audit_event_description($case['event'], $case['object_type'], $case['object_id'], $case['result'], $case['context'], $locale);
    }

    public function testEveryGoldenCaseRendersInGermanWithoutAGap(): void
    {
        foreach ($this->cases() as $case) {
            $en = $this->render($case, 'en');
            $de = $this->render($case, 'de');

            self::assertNotSame('', $de, $case['id']);
            self::assertStringNotContainsString('audit_log.', $de, $case['id'] . ': a key is missing from the German catalog');
            self::assertStringNotContainsString("\u{2014}", $de, $case['id'] . ': an em dash in German prose');
            self::assertStringNotContainsString("\u{2013}", $de, $case['id'] . ': an en dash in German prose');
            // A placeholder that was not filled shows its name, and the English text of the
            // same row has that name filled in. Values appear in both, so only a leftover
            // placeholder can be in the German text alone.
            self::assertSame(
                [],
                array_values(array_diff($this->placeholderWords($de), $this->placeholderWords($en))),
                $case['id'] . ': a placeholder was not filled in: ' . $de
            );
        }
    }

    public function testTheGermanSentencesReadAsGermanForRepresentativeRows(): void
    {
        self::assertSame(
            'Anmeldung erfolgreich (directory, Controller 3)',
            audit_event_description(VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN, 'user', '42', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['source' => 'directory', 'controller_id' => 3], 'de')
        );
        self::assertSame(
            'Anmeldung abgewiesen für Benutzer „jörg.müller“: invalid credentials',
            audit_event_description(VIRTUSPHERE_AUDIT_EVENT_AUTH_LOGIN, 'user', '42', VIRTUSPHERE_AUDIT_RESULT_DENIED, ['source' => 'local', 'reason' => 'invalid credentials', 'username' => 'jörg.müller'], 'de')
        );
        self::assertSame(
            'VM mit ID 99 („srv-sql-01“) in Mission 12 (cpu, memory) geändert',
            audit_event_description(VIRTUSPHERE_AUDIT_EVENT_VM_CHANGED, 'vm', '99', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['action' => 'updated', 'mission_id' => 12, 'name' => 'srv-sql-01', 'changes' => 'cpu, memory'], 'de')
        );
        self::assertSame(
            'Bereitstellungsauftrag mit ID 812 (Mission 12, Modus full): failed: ESXi host unreachable: timeout after 300 s',
            audit_event_description(VIRTUSPHERE_AUDIT_EVENT_DEPLOY_OUTCOME, 'deploy_job', '812', VIRTUSPHERE_AUDIT_RESULT_FAILURE, ['mission_id' => 12, 'mode' => 'full', 'status' => 'failed', 'reason' => 'ESXi host unreachable: timeout after 300 s'], 'de')
        );
        self::assertSame(
            'Einstellung portal timezone geändert: Europe/Berlin -> UTC',
            audit_event_description(VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting', 'portal_timezone', VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['action' => 'updated', 'old_value' => 'Europe/Berlin', 'new_value' => 'UTC'], 'de')
        );
    }

    public function testTheEnglishDefaultIsTheStoredSentence(): void
    {
        $case = $this->cases()[0];

        self::assertSame(
            audit_event_description($case['event'], $case['object_type'], $case['object_id'], $case['result'], $case['context']),
            $this->render($case, 'en'),
            'the writers rely on the default locale being English'
        );
    }

    public function testAnUnknownLocaleRendersTheDefaultLocale(): void
    {
        $case = $this->cases()[0];

        self::assertSame($this->render($case, 'de'), $this->render($case, 'fr'));
    }

    public function testTheGermanAndEnglishCatalogsHaveTheSameKeysAndPlaceholders(): void
    {
        $en = require dirname(__DIR__, 2) . '/lang/en/audit_log.php';
        $de = require dirname(__DIR__, 2) . '/lang/de/audit_log.php';

        $enKeys = array_keys($en);
        $deKeys = array_keys($de);
        sort($enKeys, SORT_STRING);
        sort($deKeys, SORT_STRING);
        self::assertSame($enKeys, $deKeys, 'the German and English key sets differ');
        foreach ($en as $key => $text) {
            self::assertSame(
                $this->placeholderWords((string) $text),
                $this->placeholderWords((string) $de[$key]),
                $key . ': the placeholders differ between DE and EN'
            );
        }
    }

    public function testEveryActionTokenTheCodeWritesHasALabelInBothLocales(): void
    {
        $en = require dirname(__DIR__, 2) . '/lang/en/audit_log.php';
        $de = require dirname(__DIR__, 2) . '/lang/de/audit_log.php';

        $tokens = $this->actionTokensWrittenByTheCode();
        self::assertNotEmpty($tokens, 'zero-match: the scan found no action tokens');
        foreach ($tokens as $token) {
            self::assertArrayHasKey('action_' . $token, $en, $token . ' has no English label');
            self::assertArrayHasKey('action_' . $token, $de, $token . ' has no German label');
            self::assertSame($token, $en['action_' . $token], $token . ': the English label is the token itself');
        }
    }

    /**
     * Every literal action token that a writer puts into an audit context: a quoted
     * value after 'action' =>, both arms of a ternary there, and the VM MECM action
     * constants. Derived from the sources, so a new writer cannot add a token that is
     * shown untranslated without this test failing.
     *
     * @return list<string>
     */
    private function actionTokensWrittenByTheCode(): array
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/lib/*.php') ?: [],
            glob($root . '/lib/*/*.php') ?: [],
            glob($root . '/portal/*.php') ?: [],
            glob($root . '/*.php') ?: []
        );
        $tokens = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all("/'action' => '([a-z][a-z0-9_]*)'/", $source, $literal);
            preg_match_all("/'action' => [^?\\n]*\\?\\s*'([a-z][a-z0-9_]*)'\\s*:\\s*'([a-z][a-z0-9_]*)'/", $source, $ternary);
            preg_match_all("/const VIRTUSPHERE_VM_MECM_ACTION_[A-Z_]+ = '([a-z_]+)';/", $source, $constant);
            $tokens = [...$tokens, ...$literal[1], ...$ternary[1], ...$ternary[2], ...$constant[1]];
        }

        return array_values(array_unique($tokens));
    }
}
