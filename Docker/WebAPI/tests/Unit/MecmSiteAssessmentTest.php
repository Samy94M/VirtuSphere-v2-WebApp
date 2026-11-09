<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/integration_health.php';
require_once dirname(__DIR__, 2) . '/lib/system_status_mecm_panels.php';

/**
 * The portal's reading of the MECM site verdict (ADR-0018 amendment
 * 2026-11-09): one type table, critical stays critical unless every critical
 * part is a supporting type and the list is complete, never better or worse
 * than MECM otherwise, and the site's own words on card, heading and tile.
 * Synthetic values only (site P01).
 */
final class MecmSiteAssessmentTest extends TestCase
{
    private const NOW = 1791201600;

    protected function setUp(): void
    {
        Lang::load('de');
    }

    /**
     * @param array<string,mixed>|null $parts
     * @return array<string,mixed>
     */
    private function row(string $category, ?array $parts, int $age = 60): array
    {
        $status = match ($category) {
            VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL => VIRTUSPHERE_RUN_OUTCOME_FAIL,
            VIRTUSPHERE_RUN_ERROR_SITE_WARNING => VIRTUSPHERE_RUN_OUTCOME_WARNING,
            '' => VIRTUSPHERE_RUN_OUTCOME_OK,
            default => VIRTUSPHERE_RUN_OUTCOME_UNKNOWN,
        };
        $summary = ['site_code' => 'P01', 'raw_status' => 2];
        if ($parts !== null) {
            $summary[VIRTUSPHERE_SITE_PARTS_FIELD] = $parts + ['roles' => [], 'components' => [], 'omitted' => 0];
        }

        return [
            'last_result_at' => gmdate('Y-m-d H:i:s', self::NOW - $age),
            'last_status' => $status,
            'last_error_category' => $category,
            'interval_seconds' => 300,
            'last_summary' => json_encode($summary, JSON_THROW_ON_ERROR),
        ];
    }

    /** The situation of 09.11.2026, synthetic: only distribution, discovery and cloud parts. */
    private function supportingOnly(): array
    {
        return [
            'roles' => [
                ['name' => 'SMS Distribution Point', 'status' => 2, 'count' => 4],
                ['name' => 'SMS Distribution Point', 'status' => 1, 'count' => 1],
            ],
            'components' => [
                ['name' => 'SMS_AD_SYSTEM_DISCOVERY_AGENT', 'status' => 2, 'count' => 1],
                ['name' => 'SMS_DISTRIBUTION_MANAGER', 'status' => 2, 'count' => 1],
                ['name' => 'SMS_CLOUDCONNECTION', 'status' => 1, 'count' => 1],
                ['name' => 'SMS_HIERARCHY_MANAGER', 'status' => 1, 'count' => 1],
                ['name' => 'SMS_SERVICE_CONNECTOR', 'status' => 1, 'count' => 1],
            ],
        ];
    }

    public function testOnlySupportingPartsCriticalIsLimitedWithCounts(): void
    {
        $a = mecm_site_assessment($this->row(VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL, $this->supportingOnly()));
        self::assertSame(VIRTUSPHERE_MECM_SITE_VERDICT_LIMITED, $a['verdict']);
        self::assertSame(VIRTUSPHERE_MECM_SITE_VERDICT_CRITICAL, $a['reported']);
        self::assertTrue($a['explained']);
        self::assertSame(['roles' => 4, 'components' => 2], $a['critical']);
        self::assertSame(['roles' => 1, 'components' => 3], $a['warning']);
        self::assertSame('SMS Distribution Point', $a['parts'][0]['name'], 'the most critical type comes first');
        self::assertSame(['critical' => 4, 'warning' => 1], ['critical' => $a['parts'][0]['critical'], 'warning' => $a['parts'][0]['warning']]);
        self::assertSame('warning', virtusphere_site_completed_state($this->row(VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL, $this->supportingOnly()), self::NOW));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function coreOrUnknownCritical(): iterable
    {
        yield 'core role' => [VIRTUSPHERE_SITE_PART_KIND_ROLES, 'SMS Management Point'];
        yield 'core component' => [VIRTUSPHERE_SITE_PART_KIND_COMPONENTS, 'SMS_EXECUTIVE'];
        yield 'unknown role type' => [VIRTUSPHERE_SITE_PART_KIND_ROLES, 'SMS Future Role'];
        yield 'unclassified component' => [VIRTUSPHERE_SITE_PART_KIND_COMPONENTS, 'SMS_OFFER_MANAGER'];
        yield 'unclassified role' => [VIRTUSPHERE_SITE_PART_KIND_ROLES, 'SMS Component Server'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('coreOrUnknownCritical')]
    public function testACoreOrUnknownCriticalPartKeepsTheMecmVerdict(string $kind, string $name): void
    {
        $parts = $this->supportingOnly();
        $parts[$kind][] = ['name' => $name, 'status' => 2, 'count' => 1];
        $row = $this->row(VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL, $parts);
        self::assertSame(VIRTUSPHERE_MECM_SITE_VERDICT_CRITICAL, mecm_site_assessment($row)['verdict']);
        self::assertSame('danger', virtusphere_site_completed_state($row, self::NOW));
    }

    public function testAnIncompleteOrMissingListKeepsTheMecmVerdict(): void
    {
        $cut = $this->supportingOnly() + ['omitted' => 2];
        $a = mecm_site_assessment($this->row(VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL, $cut));
        self::assertSame(VIRTUSPHERE_MECM_SITE_VERDICT_CRITICAL, $a['verdict']);
        self::assertSame(2, $a['omitted']);

        $none = mecm_site_assessment($this->row(VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL, null));
        self::assertSame(VIRTUSPHERE_MECM_SITE_VERDICT_CRITICAL, $none['verdict']);
        self::assertFalse($none['has_parts']);

        $onlyWarnings = mecm_site_assessment($this->row(VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL, [
            'components' => [['name' => 'SMS_DISTRIBUTION_MANAGER', 'status' => 1, 'count' => 1]],
        ]));
        self::assertSame(VIRTUSPHERE_MECM_SITE_VERDICT_CRITICAL, $onlyWarnings['verdict']);
        self::assertFalse($onlyWarnings['explained']);
    }

    public function testThePortalNeverRatesOtherVerdictsDifferentlyThanMecm(): void
    {
        $core = ['roles' => [['name' => 'SMS Site Server', 'status' => 2, 'count' => 1]]];
        self::assertSame(VIRTUSPHERE_MECM_SITE_VERDICT_WARNING, mecm_site_assessment($this->row(VIRTUSPHERE_RUN_ERROR_SITE_WARNING, $core))['verdict']);
        self::assertSame(VIRTUSPHERE_MECM_SITE_VERDICT_OK, mecm_site_assessment($this->row('', $core))['verdict']);
        self::assertSame(VIRTUSPHERE_MECM_SITE_VERDICT_UNKNOWN, mecm_site_assessment($this->row(VIRTUSPHERE_RUN_ERROR_QUERY_FAILED, $core))['verdict']);
    }

    /** @return iterable<string, array{0: string}> */
    public static function corruptStoredParts(): iterable
    {
        yield 'not json' => ['{'];
        yield 'parts is a string' => ['{"site_parts":"x"}'];
        yield 'server name smuggled in' => ['{"site_parts":{"roles":[{"name":"cm01.example.test","status":2,"count":1}]}}'];
        yield 'unknown key' => ['{"site_parts":{"servers":[]}}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('corruptStoredParts')]
    public function testACorruptStoredValueReadsAsNoParts(string $json): void
    {
        $row = ['last_status' => 'fail', 'last_error_category' => 'site_critical', 'last_summary' => $json];
        self::assertNull(mecm_site_stored_parts($row));
        self::assertSame(VIRTUSPHERE_MECM_SITE_VERDICT_CRITICAL, mecm_site_assessment($row)['verdict']);
    }

    public function testTheTypeTableIsWellFormedAndEveryRoleLabelExistsInBothLocales(): void
    {
        $catalogs = [];
        foreach (['de', 'en'] as $locale) {
            $catalogs[$locale] = require dirname(__DIR__, 2) . '/lang/' . $locale . '/system_status.php';
        }
        self::assertSame(VIRTUSPHERE_SITE_PART_KINDS, array_keys(VIRTUSPHERE_MECM_SITE_PART_TYPES));
        foreach (VIRTUSPHERE_MECM_SITE_PART_TYPES as $kind => $types) {
            self::assertNotSame([], $types);
            foreach ($types as $name => $type) {
                self::assertMatchesRegularExpression(VIRTUSPHERE_SITE_PART_NAME_PATTERN, $name, 'a table row the wire can never carry is dead');
                self::assertContains($type['class'], [VIRTUSPHERE_MECM_SITE_PART_CORE, VIRTUSPHERE_MECM_SITE_PART_SUPPORTING], $name);
                if ($kind === VIRTUSPHERE_SITE_PART_KIND_ROLES) {
                    self::assertArrayHasKey('label', $type, $name);
                }
                if (isset($type['label'])) {
                    foreach ($catalogs as $locale => $catalog) {
                        self::assertNotSame('', $catalog['site_role_' . $type['label']] ?? '', $locale . ': ' . $name);
                    }
                }
            }
        }
        foreach ([VIRTUSPHERE_MECM_SITE_PART_CORE, VIRTUSPHERE_MECM_SITE_PART_SUPPORTING, VIRTUSPHERE_MECM_SITE_PART_UNCLASSIFIED] as $class) {
            foreach ($catalogs as $locale => $catalog) {
                self::assertArrayHasKey('site_part_class_' . $class, $catalog, $locale);
            }
        }
    }

    /**
     * The user's examples are pinned by name, so a reclassification is a
     * visible decision here and in the register, not a side effect.
     */
    public function testTheDecidedExamplesKeepTheirClass(): void
    {
        foreach (['SMS Site Server', 'SMS SQL Server', 'SMS Provider', 'SMS Management Point'] as $role) {
            self::assertSame(VIRTUSPHERE_MECM_SITE_PART_CORE, mecm_site_part_class(VIRTUSPHERE_SITE_PART_KIND_ROLES, $role), $role);
        }
        foreach (['SMS_EXECUTIVE', 'SMS_SITE_COMPONENT_MANAGER'] as $component) {
            self::assertSame(VIRTUSPHERE_MECM_SITE_PART_CORE, mecm_site_part_class(VIRTUSPHERE_SITE_PART_KIND_COMPONENTS, $component), $component);
        }
        foreach (['SMS Distribution Point', 'SMS Software Update Point', 'SMS Dmp Connector', 'SMS Cloud Proxy Connector'] as $role) {
            self::assertSame(VIRTUSPHERE_MECM_SITE_PART_SUPPORTING, mecm_site_part_class(VIRTUSPHERE_SITE_PART_KIND_ROLES, $role), $role);
        }
        // Service connection point and cloud services stay supporting also in an
        // air gap: shown as degraded, never hidden (coordinator decision 09.11.).
        foreach (['SMS_DISTRIBUTION_MANAGER', 'SMS_AD_SYSTEM_DISCOVERY_AGENT', 'SMS_SERVICE_CONNECTOR', 'SMS_CLOUDCONNECTION', 'SMS_CLOUD_PROXYCONNECTOR', 'SMS_WSUS_SYNC_MANAGER'] as $component) {
            self::assertSame(VIRTUSPHERE_MECM_SITE_PART_SUPPORTING, mecm_site_part_class(VIRTUSPHERE_SITE_PART_KIND_COMPONENTS, $component), $component);
        }
        // Not proven by a source: stays unclassified, so MECM's verdict applies.
        foreach (['SMS Site System', 'SMS Component Server'] as $role) {
            self::assertSame(VIRTUSPHERE_MECM_SITE_PART_UNCLASSIFIED, mecm_site_part_class(VIRTUSPHERE_SITE_PART_KIND_ROLES, $role), $role);
        }
    }

    public function testTheCardSpeaksTheSitesOwnWordsAndNamesTheParts(): void
    {
        $row = $this->row(VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL, $this->supportingOnly());
        $entries = integration_health_site_evidence_rows([
            ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH, 'row' => $row, 'state' => 'unknown'],
        ], self::NOW);
        self::assertSame(VIRTUSPHERE_MECM_SITE_VERDICT_LIMITED, $entries[0]['site_state']);
        self::assertSame(VIRTUSPHERE_MECM_SITE_VERDICT_LIMITED, integration_health_site_group_state($entries));
        ob_start();
        system_status_render_site($entries);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Eingeschränkt', $html);
        self::assertStringContainsString(h(__t('system_status.err_site_critical')), $html, 'what MECM itself reported stays visible');
        self::assertStringContainsString('4 Rollen kritisch, 2 Komponenten kritisch, 1 Rolle mit Warnung, 3 Komponenten mit Warnung', $html);
        self::assertStringContainsString('Verteilungspunkt', $html);
        self::assertStringContainsString('<code>SMS_DISTRIBUTION_MANAGER</code>', $html);
        self::assertStringContainsString('Unterstützend', $html);
        self::assertStringContainsString(h(__t('system_status.site_hint_limited')), $html);
        self::assertStringNotContainsString('Ausgefallen', $html);
        self::assertStringNotContainsString('Verzögert', $html);
    }

    public function testAnOldReporterWithoutPartsShowsCriticalInTheNewWords(): void
    {
        $entries = integration_health_site_evidence_rows([
            ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH, 'row' => $this->row(VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL, null), 'state' => 'unknown'],
        ], self::NOW);
        ob_start();
        system_status_render_site($entries);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('>Kritisch<', $html);
        self::assertStringContainsString(h(__t('system_status.site_parts_none')), $html);
        self::assertStringContainsString(h(__t('system_status.site_hint_console')), $html);
        self::assertStringNotContainsString('Ausgefallen', $html);
        self::assertStringNotContainsString('<table', $html);
    }

    public function testAHealthySiteShowsNoPartsBlock(): void
    {
        $entries = integration_health_site_evidence_rows([
            ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH, 'row' => $this->row('', null), 'state' => 'unknown'],
        ], self::NOW);
        ob_start();
        system_status_render_site($entries);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('>OK<', $html);
        self::assertStringNotContainsString(h(__t('system_status.site_parts_none')), $html);
        self::assertStringNotContainsString('<table', $html);
    }

    public function testTheGroupStateSeparatesNoReportAndAnOverdueReporter(): void
    {
        self::assertSame('none', integration_health_site_group_state([
            ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH, 'row' => null, 'state' => 'unknown'],
        ]));
        $old = integration_health_site_evidence_rows([
            ['source' => VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH, 'row' => $this->row(VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL, null, 3600), 'state' => 'unknown'],
        ], self::NOW);
        self::assertSame('stale', $old[0]['site_state']);
        self::assertSame('overdue', integration_health_site_group_state($old));
    }

    public function testTheOverviewCardShowsTheWorseGroupInItsOwnWords(): void
    {
        $snapshot = static fn (string $sync, string $siteRank, string $site): array => [
            'mecm_sync' => ['state' => $sync],
            'mecm_site' => ['state' => $siteRank, 'site_state' => $site],
        ];
        $critical = system_status_mecm_overview_badge($snapshot('ok', 'danger', 'critical'));
        self::assertStringContainsString('badge-danger', $critical);
        self::assertStringContainsString('>Kritisch<', $critical);
        self::assertStringContainsString('>Eingeschränkt<', system_status_mecm_overview_badge($snapshot('ok', 'warning', 'limited')));
        self::assertStringContainsString('>Überfällig<', system_status_mecm_overview_badge($snapshot('ok', 'warning', 'overdue')));
        // A failed sync keeps its own words, also next to a limited site.
        self::assertStringContainsString('>Ausgefallen<', system_status_mecm_overview_badge($snapshot('danger', 'warning', 'limited')));
        self::assertStringContainsString('>OK<', system_status_mecm_overview_badge($snapshot('ok', 'ok', 'ok')));
        self::assertStringContainsString('>Noch keine Daten<', system_status_mecm_overview_badge($snapshot('unknown', 'unknown', 'none')));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function badges(): iterable
    {
        yield 'ok' => ['ok', 'badge-success', 'OK'];
        yield 'warning' => ['warning', 'badge-warning', 'Warnung'];
        yield 'limited' => ['limited', 'badge-warning', 'Eingeschränkt'];
        yield 'critical' => ['critical', 'badge-danger', 'Kritisch'];
        yield 'unknown' => ['unknown', 'badge-neutral', 'Unbekannt'];
        yield 'overdue reporter' => ['overdue', 'badge-warning', 'Überfällig'];
        yield 'stale evidence' => ['stale', 'badge-neutral', 'Veraltet'];
        yield 'no report yet' => ['none', 'badge-neutral', 'Noch keine Daten'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badges')]
    public function testTheSiteBadgeNeverUsesReporterLivenessWords(string $state, string $class, string $label): void
    {
        $html = mecm_site_badge($state);
        self::assertStringContainsString($class, $html);
        self::assertStringContainsString('>' . $label . '<', $html);
        self::assertStringNotContainsString('Ausgefallen', $html);
        self::assertStringNotContainsString('Verzögert', $html);
        $row = portal_signal_row('MECM-Site', 'danger', $html);
        self::assertStringContainsString('<span class="signal-label">MECM-Site</span>' . $html, $row);
    }
}
