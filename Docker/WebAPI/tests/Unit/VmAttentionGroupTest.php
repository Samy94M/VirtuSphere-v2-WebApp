<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/vm_overview_page.php';
require_once dirname(__DIR__, 2) . '/lib/missions_page.php';
require_once dirname(__DIR__, 2) . '/lib/repo/vms_operations.php';

/**
 * SP4 R2 (F6) and R1: the four "check due" groups of the dashboard are filters of
 * the VM overview with the same name, each the twin of one VM finding; the mission
 * list shows how far its VMs are, one badge per reached phase.
 */
final class VmAttentionGroupTest extends TestCase
{
    protected function tearDown(): void
    {
        Lang::load('de');
    }

    public function testTheFourGroupsAreOverviewFiltersAndEachIsTheTwinOfOneFinding(): void
    {
        self::assertSame([
            VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_OVERDUE => VIRTUSPHERE_VM_FINDING_MECM_PENDING_OVERDUE,
            VIRTUSPHERE_VM_OVERVIEW_FILTER_OS_INSTALL_OVERDUE => VIRTUSPHERE_VM_FINDING_OS_INSTALL_OVERDUE,
            VIRTUSPHERE_VM_OVERVIEW_FILTER_JOB_FAILED => VIRTUSPHERE_VM_FINDING_JOB_FAILED,
            VIRTUSPHERE_VM_OVERVIEW_FILTER_CREATE_UNCERTAIN => VIRTUSPHERE_VM_FINDING_CREATE_UNCERTAIN,
        ], VIRTUSPHERE_VM_OVERVIEW_ATTENTION_FINDINGS);

        foreach (array_keys(VIRTUSPHERE_VM_OVERVIEW_ATTENTION_FINDINGS) as $filter) {
            self::assertContains($filter, VIRTUSPHERE_VM_OVERVIEW_FILTERS);
            self::assertSame($filter, vm_overview_filter($filter), 'the link of a group is accepted by the list');
            [$sql] = repo_vm_overview_predicate($filter);
            self::assertStringContainsString('LEFT(m.mission_name, 1) <> ?', $sql, $filter . ': template VMs do not count (F6)');
        }
    }

    public function testEveryGroupHasItsOwnWordsInBothLocalesAndNeverItsToken(): void
    {
        foreach (['de', 'en'] as $locale) {
            Lang::load($locale);
            $labels = [];
            foreach (VIRTUSPHERE_VM_OVERVIEW_FILTERS as $filter) {
                $label = vm_overview_filter_label($filter);
                self::assertStringNotContainsString($filter, $label, "{$locale}: {$filter} shows its token");
                self::assertStringNotContainsString(':hours', $label);
                $labels[] = $label;
                self::assertNotSame('', vm_overview_intro($filter));
                self::assertNotSame('', vm_overview_empty_text($filter));
            }
            self::assertSame(count($labels), count(array_unique($labels)), "{$locale}: two filters share a label");
        }

        Lang::load('de');
        self::assertStringContainsString((string) intdiv(VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS, 3600) . ' h', vm_overview_filter_label(VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_OVERDUE));
        self::assertStringContainsString((string) intdiv(VIRTUSPHERE_VM_OS_INSTALL_WARN_SECONDS, 3600) . ' h', vm_overview_filter_label(VIRTUSPHERE_VM_OVERVIEW_FILTER_OS_INSTALL_OVERDUE));
        self::assertSame(vm_overview_filter_label(VIRTUSPHERE_VM_OVERVIEW_FILTER_JOB_FAILED), vm_overview_title(VIRTUSPHERE_VM_OVERVIEW_FILTER_JOB_FAILED), 'the list carries the name of its group');
        self::assertStringContainsString('unbekannt', vm_overview_intro(VIRTUSPHERE_VM_OVERVIEW_FILTER_JOB_FAILED), 'an unknown outcome is not called a failure (D4)');
    }

    public function testMecmWaitingReadsOnlyThePendingValue(): void
    {
        [$sql, $types, $params] = repo_vm_progress_overdue_sql(VIRTUSPHERE_VM_PROGRESS_MECM_PENDING, 'v.');
        self::assertSame([VIRTUSPHERE_MECM_SYNC_PENDING, VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS], $params);
        self::assertSame('si', $types);
        self::assertStringContainsString('v.mecm_sync_state = ?', $sql);
        self::assertStringNotContainsString('<>', $sql, 'waiting is the pending value, never "anything but registered"');

        [$attention, , $attentionParams] = repo_vm_progress_attention_sql();
        [$install, , $installParams] = repo_vm_progress_overdue_sql(VIRTUSPHERE_VM_PROGRESS_OS_INSTALLING);
        [$mecm, , $mecmParams] = repo_vm_progress_overdue_sql(VIRTUSPHERE_VM_PROGRESS_MECM_PENDING);
        self::assertSame('(' . $mecm . ' OR ' . $install . ')', $attention, 'the mission column reads the same two halves');
        self::assertSame(array_merge($mecmParams, $installParams), $attentionParams);

        $this->expectException(InvalidArgumentException::class);
        repo_vm_progress_overdue_sql(VIRTUSPHERE_VM_PROGRESS_MECM_PENDING, 'v; DROP');
    }

    public function testTheAttentionTileListsOnlyGroupsWithHitsAndLinksEachToItsFilter(): void
    {
        Lang::load('en');
        $counts = [
            VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_OVERDUE => 3,
            VIRTUSPHERE_VM_OVERVIEW_FILTER_OS_INSTALL_OVERDUE => 0,
            VIRTUSPHERE_VM_OVERVIEW_FILTER_JOB_FAILED => 1,
            VIRTUSPHERE_VM_OVERVIEW_FILTER_CREATE_UNCERTAIN => 0,
        ];
        ob_start();
        vm_overview_render_attention_tile($counts, 1792400000);
        $html = (string) ob_get_clean();

        self::assertStringContainsString(h(__t('dashboard.kpi_vm_attention')), $html);
        self::assertStringContainsString('href="' . h(vm_overview_url(VIRTUSPHERE_VM_OVERVIEW_FILTER_MECM_OVERDUE, ['snapshot' => ['counted' => 1792400000, 'expected' => 3]])) . '"', $html);
        self::assertStringContainsString('href="' . h(vm_overview_url(VIRTUSPHERE_VM_OVERVIEW_FILTER_JOB_FAILED, ['snapshot' => ['counted' => 1792400000, 'expected' => 1]])) . '"', $html);
        self::assertStringNotContainsString('filter=os_install_overdue', $html, 'a group without hits is not listed');
        self::assertStringNotContainsString('missions.php?type=missions&attention=1', $html);
        self::assertSame(2, substr_count($html, '<a '));

        ob_start();
        vm_overview_render_attention_tile(array_fill_keys(array_keys($counts), 0), 1792400000);
        self::assertSame('', trim((string) ob_get_clean()), 'no hits, no tile');
    }

    public function testThePhaseCellShowsOneBadgePerReachedPhaseInChainOrder(): void
    {
        Lang::load('de');
        $mission = [
            'id' => 17,
            'vm_count' => 5,
            'phase_counts' => [
                VIRTUSPHERE_VM_PHASE_CREATED => 1,
                VIRTUSPHERE_VM_PHASE_HANDED_TO_MECM => 1,
                VIRTUSPHERE_VM_PHASE_CLIENT_DONE => 3,
            ],
        ];
        $html = missions_phase_cell($mission, []);

        $created = strpos($html, portal_vm_phase_label(VIRTUSPHERE_VM_PHASE_CREATED));
        $done = strpos($html, portal_vm_phase_label(VIRTUSPHERE_VM_PHASE_CLIENT_DONE));
        self::assertNotFalse($created);
        self::assertNotFalse($done);
        self::assertLessThan($done, $created, 'phases read along the chain');
        self::assertStringContainsString(portal_badge('success', __t('missions.phase_count', ['phase' => portal_vm_phase_label(VIRTUSPHERE_VM_PHASE_CLIENT_DONE), 'count' => 3])), $html);
        self::assertStringContainsString(portal_badge('info', __t('missions.phase_count', ['phase' => portal_vm_phase_label(VIRTUSPHERE_VM_PHASE_HANDED_TO_MECM), 'count' => 1])), $html);
        self::assertStringNotContainsString(portal_vm_phase_label(VIRTUSPHERE_VM_PHASE_ON_ESXI), $html, 'an empty phase is left out');
        self::assertSame(1, substr_count($html, '<a '), 'one link per mission: every phase would open the same list');
        self::assertStringContainsString('href="' . h(portal_work_context_vm_list_url(17, portal_work_context_with_vm_list([], 'status', 'asc'))) . '"', $html);
        self::assertStringContainsString('sort=status', $html);
        self::assertDoesNotMatchRegularExpression('/\sstyle=/', $html);

        self::assertSame('&mdash;', missions_phase_cell(['id' => 18, 'vm_count' => 0, 'phase_counts' => []], []));
    }

    public function testTheCatalogKeysExistInBothLocales(): void
    {
        $keys = [
            'missions' => ['th_phases', 'phase_count', 'phases_link_title'],
            'vm_overview' => ['filter_mecm_overdue', 'filter_os_install_overdue', 'filter_job_failed', 'filter_create_uncertain',
                'intro_mecm_overdue', 'intro_os_install_overdue', 'intro_job_failed', 'intro_create_uncertain',
                'empty_mecm_overdue', 'empty_os_install_overdue', 'empty_job_failed', 'empty_create_uncertain'],
            'help_missions' => ['badge_mission_phases'],
            'help_overview' => ['dashboard_groups_p1'],
        ];
        foreach ($keys as $module => $moduleKeys) {
            foreach (['de', 'en'] as $locale) {
                $catalog = require dirname(__DIR__, 2) . '/lang/' . $locale . '/' . $module . '.php';
                foreach ($moduleKeys as $key) {
                    self::assertArrayHasKey($key, $catalog, "{$locale}/{$module}.php misses {$key}");
                    foreach (["\u{2014}", "\u{2013}"] as $dash) {
                        self::assertStringNotContainsString($dash, $catalog[$key], "{$locale}/{$module}.{$key} uses a dash");
                    }
                }
            }
        }
    }
}
