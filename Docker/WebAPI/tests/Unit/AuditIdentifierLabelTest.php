<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/audit_presenter.php';
require_once dirname(__DIR__, 2) . '/lib/constants.php';
require_once dirname(__DIR__, 2) . '/lib/permissions.php';

/**
 * P3-6 and P3-10 (TS1-K3): a German reader gets German words for the four
 * identifiers an audit sentence names most often (the setting, the user role,
 * the log tab of an export, the retention areas), while the stored English
 * sentence keeps the form it has always written (i18n R10).
 *
 * Every list is derived from its writer or owner, never typed here: the setting
 * keys from the settings writers, the tabs from VIRTUSPHERE_LOG_TABS, the
 * retention areas from the retention job. A new setting, tab or area without a
 * label fails here; a label for something no writer names fails too.
 */
final class AuditIdentifierLabelTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return array<string,string> */
    private function catalog(string $locale, string $module = 'audit_log'): array
    {
        $catalog = require self::ROOT . '/lang/' . $locale . '/' . $module . '.php';
        self::assertIsArray($catalog);

        return $catalog;
    }

    /**
     * kind => identifier => the form the stored sentence writes.
     *
     * @return array<string, array<string,string>>
     */
    private function identifiers(): array
    {
        $settings = [];
        foreach ($this->settingIdsWrittenByTheCode() as $id) {
            $settings[$id] = str_replace('_', ' ', $id);
        }
        $tabs = [];
        foreach (array_keys(VIRTUSPHERE_LOG_TABS) as $tab) {
            $tabs[(string) $tab] = (string) $tab;
        }
        $retention = [];
        foreach ($this->retentionAreasWrittenByTheCode() as $name) {
            $retention[$name] = $name;
        }

        return ['setting' => $settings, 'log_tab' => $tabs, 'retention' => $retention];
    }

    public function testEveryIdentifierHasALabelInBothLocalesAndTheEnglishLabelIsTheStoredForm(): void
    {
        $en = $this->catalog('en');
        $de = $this->catalog('de');
        $count = 0;
        foreach ($this->identifiers() as $kind => $identifiers) {
            self::assertNotEmpty($identifiers, $kind . ': zero-match, the derivation found nothing');
            foreach ($identifiers as $identifier => $stored) {
                $key = 'ident_' . $kind . '_' . $identifier;
                self::assertArrayHasKey($key, $en, $key . ': no English label');
                self::assertArrayHasKey($key, $de, $key . ': no German label');
                self::assertSame($stored, $en[$key], $key . ': the English label must be the stored form itself');
                self::assertNotSame('', trim($de[$key]), $key . ': empty German label');
                self::assertSame($stored, audit_identifier_label('en', $kind, $identifier, $stored));
                self::assertSame($de[$key], audit_identifier_label('de', $kind, $identifier, $stored));
                $count++;
            }
        }
        self::assertGreaterThan(20, $count);
    }

    public function testNoIdentifierLabelIsLeftOverFromSomethingNoWriterNames(): void
    {
        $expected = [];
        foreach ($this->identifiers() as $kind => $identifiers) {
            foreach (array_keys($identifiers) as $identifier) {
                $expected['ident_' . $kind . '_' . $identifier] = true;
            }
        }
        foreach (['en', 'de'] as $locale) {
            foreach (array_keys($this->catalog($locale)) as $key) {
                if (str_starts_with($key, 'ident_')) {
                    self::assertArrayHasKey($key, $expected, $locale . ': ' . $key . ' labels an identifier no writer names');
                }
            }
        }
    }

    public function testTheGermanTabAndRoleWordsAreThoseOfThePages(): void
    {
        // One word per thing (i18n R15): the export names the tab as the logs
        // page does, the role change names the role as the navigation does.
        $de = $this->catalog('de');
        $logs = $this->catalog('de', 'logs');
        foreach (array_keys(VIRTUSPHERE_LOG_TABS) as $tab) {
            self::assertSame($logs['tab_' . $tab] ?? null, $de['ident_log_tab_' . $tab] ?? null, (string) $tab);
        }
        $layout = $this->catalog('de', 'layout');
        foreach (VIRTUSPHERE_ROLES as $role) {
            self::assertSame($layout['role_' . $role] ?? null, $de[audit_code_key('user_role', $role)] ?? null, $role);
        }
    }

    public function testTheStoredEnglishSentencesDoNotChange(): void
    {
        $en = static fn (string $event, string $type, ?string $id, array $context): string
            => audit_event_description($event, $type, $id, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, $context, 'en');

        self::assertSame('updated session lifetime minutes 30 -> 45', $en(VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting', 'session_lifetime_minutes', ['action' => 'updated', 'old_value' => '30', 'new_value' => '45']));
        self::assertSame('changed role for user id 5 to admin', $en(VIRTUSPHERE_AUDIT_EVENT_USER_ROLE_CHANGED, 'user', '5', ['role' => 'admin']));
        self::assertSame('exported logs tab security as CSV (3 of 3 row(s))', $en(VIRTUSPHERE_AUDIT_EVENT_LOGS_CSV_EXPORTED, 'log_view', 'security', ['rows_exported' => 3, 'total_rows' => 3]));
        self::assertSame('retention purge: job_log_lines: 33; status_events: 4', $en(VIRTUSPHERE_AUDIT_EVENT_SYSTEM_RETENTION_PURGED, 'maintenance', 'retention', ['items' => ['job_log_lines: 33', 'status_events: 4']]));
    }

    public function testTheGermanSentencesNameTheThingsInGerman(): void
    {
        $de = static fn (string $event, string $type, ?string $id, array $context): string
            => audit_event_description($event, $type, $id, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, $context, 'de');
        $catalog = $this->catalog('de');

        $setting = $de(VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED, 'setting', 'session_lifetime_minutes', ['action' => 'updated', 'old_value' => '30', 'new_value' => '45']);
        self::assertStringContainsString($catalog['ident_setting_session_lifetime_minutes'], $setting);
        self::assertStringNotContainsString('session lifetime minutes', $setting);

        $role = $de(VIRTUSPHERE_AUDIT_EVENT_USER_ROLE_CHANGED, 'user', '5', ['role' => VIRTUSPHERE_ROLE_ADMIN]);
        self::assertStringContainsString($catalog['code_user_role_admin'], $role);
        // An unknown role (a legacy row) shows as written, never as a gap.
        self::assertStringContainsString('deploy_operator', $de(VIRTUSPHERE_AUDIT_EVENT_USER_ROLE_CHANGED, 'user', '5', ['role' => 'deploy_operator']));

        $export = $de(VIRTUSPHERE_AUDIT_EVENT_LOGS_CSV_EXPORTED, 'log_view', 'security', ['rows_exported' => 3, 'total_rows' => 3]);
        self::assertStringContainsString($catalog['ident_log_tab_security'], $export);

        $purge = $de(VIRTUSPHERE_AUDIT_EVENT_SYSTEM_RETENTION_PURGED, 'maintenance', 'retention', ['items' => ['job_log_lines: 33', 'unknown_area: 2', 'free text item']]);
        self::assertStringContainsString($catalog['ident_retention_job_log_lines'] . ': 33', $purge);
        self::assertStringContainsString('unknown_area: 2', $purge, 'an unknown area stays as written');
        self::assertStringContainsString('free text item', $purge, 'an item of another shape stays as written');
        self::assertStringNotContainsString('job_log_lines', $purge);

        // Other item lists keep their items as written: the kind is opt-in.
        $relinked = $de(VIRTUSPHERE_AUDIT_EVENT_MECM_PACKAGES_RELINKED, 'catalog', 'packages', ['items' => ['job_log_lines: 1'], 'item_count' => 1]);
        self::assertStringContainsString('job_log_lines: 1', $relinked);
    }

    /** @return list<string> Every setting key a VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED writer names. */
    private function settingIdsWrittenByTheCode(): array
    {
        $constants = [];
        $sources = [];
        $files = array_merge(glob(self::ROOT . '/lib/*.php') ?: [], glob(self::ROOT . '/lib/*/*.php') ?: [], glob(self::ROOT . '/portal/*.php') ?: []);
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $sources[] = $source;
            preg_match_all("/const (VIRTUSPHERE_[A-Z0-9_]+) = '([a-z0-9_]+)';/", $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $constants[$match[1]] = $match[2];
            }
        }
        $ids = [];
        foreach ($sources as $source) {
            preg_match_all("/VIRTUSPHERE_AUDIT_EVENT_SETTINGS_CHANGED,\s*'setting',\s*(?:'([a-z0-9_]+)'|(VIRTUSPHERE_[A-Z0-9_]+))/", $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                if (($match[1] ?? '') !== '') {
                    $ids[] = $match[1];
                    continue;
                }
                self::assertArrayHasKey($match[2], $constants, $match[2] . ' is not a string constant the scan can resolve');
                $ids[] = $constants[$match[2]];
            }
        }
        $ids = array_values(array_unique($ids));
        self::assertGreaterThan(5, count($ids), 'zero-match: the settings writer scan found almost nothing');

        return $ids;
    }

    /** @return list<string> Every retention area the retention job names. */
    private function retentionAreasWrittenByTheCode(): array
    {
        $source = (string) file_get_contents(self::ROOT . '/lib/maintenance_tasks.php');
        self::assertSame(1, preg_match('/function maintenance_worker_retention\(.*?\n\}/s', $source, $function), 'maintenance_worker_retention() not found');
        preg_match_all("/\\\$purged\['([a-z0-9_]+)'\]/", $function[0], $direct);
        preg_match_all("/'([a-z0-9_]+)' => static fn/", $function[0], $listed);
        $names = array_values(array_unique([...$direct[1], ...$listed[1]]));
        self::assertGreaterThan(5, count($names), 'zero-match: the retention scan found almost nothing');

        return $names;
    }
}
