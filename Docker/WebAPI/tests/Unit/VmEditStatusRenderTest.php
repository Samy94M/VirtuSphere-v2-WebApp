<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/portal_status_display.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_display.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_urls.php';
require_once dirname(__DIR__, 2) . '/lib/package_report_page.php';
require_once dirname(__DIR__, 2) . '/lib/system_status_urls.php';
require_once dirname(__DIR__, 2) . '/lib/vm_phase.php';
require_once dirname(__DIR__, 2) . '/lib/vm_inventory_observation.php';
require_once dirname(__DIR__, 2) . '/lib/vm_timeline.php';
require_once dirname(__DIR__, 2) . '/lib/vm_edit_status.php';

/**
 * The VM editor's status panel (plan ST-2, section 4) rendered from fixtures, with
 * no database: the phase badge and the finding links, the technical block with
 * its raw values, the inventory line, and the status history as an open table.
 *
 * The rule under test is the operator's: a raw token (a lifecycle, a MECM state,
 * a job status, a power state) never reaches the visible text, and every value
 * that is empty says so. Attributes are not visible text; the checks read the
 * text a person reads.
 */
final class VmEditStatusRenderTest extends TestCase
{
    private const MISSION_ID = 42;
    private const VM_ID = 7;
    private const JOB_ID = 9;
    private const UUID = '5f1c9a3e-2b7d-4c1e-9a8f-0e2d3c4b5a69';
    private const MOID = 'vm-4711';
    private const HOST = 'esxi-07.example.test';
    private const RESOURCE_ID = 'RES-4711';
    private const OLD_RESOURCE_ID = 'OLD-R1';
    private const RUN_ID = '018f2f49-5e41-4d55-8f05-8f55a5335e01';
    private const FRESH_AT = '2026-10-20 10:00:00';
    private const FOREIGN_TOKENS = ['os_installing', 'poweredOff', 'succeeded', 'handed_to_mecm', 'windows_reports'];

    /** @return iterable<string, array{0:string, 1:string}> */
    public static function editorAnchors(): iterable
    {
        yield 'client phase failed' => [VIRTUSPHERE_VM_FINDING_CLIENT_PHASE_FAILED, '#vm-client-phases'];
        yield 'client phase unconfirmed' => [VIRTUSPHERE_VM_FINDING_CLIENT_PHASE_UNCONFIRMED, '#vm-client-phases'];
        yield 'PXE unwatched' => [VIRTUSPHERE_VM_FINDING_PXE_UNWATCHED, '#vm-progress-watch'];
        yield 'OS installation watching' => [VIRTUSPHERE_VM_FINDING_OS_INSTALL_WATCHING, '#vm-progress-watch'];
        yield 'OS installation overdue' => [VIRTUSPHERE_VM_FINDING_OS_INSTALL_OVERDUE, '#vm-progress-watch'];
        yield 'powered off' => [VIRTUSPHERE_VM_FINDING_POWERED_OFF, '#vm-technical'];
    }

    /** @return iterable<string, array{0:array<string,mixed>, 1:string}> */
    public static function inventoryTexts(): iterable
    {
        yield 'unbound' => [['state' => VIRTUSPHERE_VM_INVENTORY_UNBOUND, 'power' => null, 'time' => ''], 'Nicht an eine ESXi-VM gebunden.'];
        yield 'none' => [
            ['state' => VIRTUSPHERE_VM_INVENTORY_NONE, 'power' => null, 'time' => ''],
            'Für den gebundenen Zugang liegt noch kein Inventar vor.',
        ];
        yield 'unanswered' => [
            ['state' => VIRTUSPHERE_VM_INVENTORY_UNANSWERED, 'power' => null, 'time' => self::FRESH_AT],
            'Das letzte Inventar hat die VMs dieses Zugangs nicht beantwortet; kein Beleg.',
        ];
        yield 'stale with its time' => [
            ['state' => VIRTUSPHERE_VM_INVENTORY_STALE, 'power' => null, 'time' => self::FRESH_AT],
            'Inventar veraltet (Stand {time}); kein Beleg für Zustand oder Fehlen.',
        ];
        yield 'missing' => [
            ['state' => VIRTUSPHERE_VM_INVENTORY_MISSING, 'power' => null, 'time' => self::FRESH_AT],
            'Nicht im Inventar vom {time}.',
        ];
        yield 'present and powered on' => [
            ['state' => VIRTUSPHERE_VM_INVENTORY_PRESENT, 'power' => 'poweredOn', 'time' => self::FRESH_AT],
            'Laut Inventar vom {time} eingeschaltet.',
        ];
        yield 'present without a reported power state' => [
            ['state' => VIRTUSPHERE_VM_INVENTORY_PRESENT, 'power' => null, 'time' => self::FRESH_AT],
            'Laut Inventar vom {time} vorhanden, Energiezustand nicht gemeldet.',
        ];
    }

    protected function tearDown(): void
    {
        Lang::load(Lang::DEFAULT_LOCALE);
    }

    public function testThePanelHeaderShowsTheDerivedPhaseBadge(): void
    {
        Lang::load('de');
        $html = $this->panel(self::vm(), self::evidence());

        preg_match('/<div class="vm-status-head">(.*?)<\/div>/s', $html, $head);
        self::assertArrayHasKey(1, $head, 'the panel has no status header');
        self::assertStringContainsString(vm_phase_badge(VIRTUSPHERE_VM_PHASE_HANDED_TO_MECM), $head[1]);
        self::assertStringContainsString('An MECM übergeben', self::visible($head[1]));
        self::assertStringContainsString('Status und Deploy-Fortschritt', self::visible($html));
    }

    public function testEachFindingIsALinkToItsTargetWrappingItsBadgeInSeverityOrder(): void
    {
        Lang::load('de');
        $vm = self::vm();
        $evidence = self::evidence([
            'create_uncertain' => [['job_id' => self::JOB_ID, 'position' => 2]],
            'client_phases' => self::clientPhases([VIRTUSPHERE_CLIENT_PHASE_HOSTNAME => self::phaseEvent('failed')]),
            'inventory' => self::inventory(['vms' => [self::UUID => 'poweredOff']]),
        ]);
        $findings = virtusphere_vm_findings($vm, $evidence, self::now());

        self::assertSame(
            [
                VIRTUSPHERE_VM_FINDING_CREATE_UNCERTAIN,
                VIRTUSPHERE_VM_FINDING_OLD_MECM_DEVICE,
                VIRTUSPHERE_VM_FINDING_POWERED_OFF,
                VIRTUSPHERE_VM_FINDING_PXE_UNWATCHED,
                VIRTUSPHERE_VM_FINDING_CLIENT_PHASE_FAILED,
            ],
            array_column($findings, 'kind'),
            'the fixture must produce the five findings this test is about'
        );

        $expectedHrefs = [
            deploy_job_log_url(self::JOB_ID),
            'system_status.php#mecm',
            '#vm-technical',
            '#vm-progress-watch',
            '#vm-client-phases',
        ];
        $anchors = self::anchors($this->panel($vm, $evidence));

        self::assertCount(count($findings), $anchors, 'one link per finding');
        foreach ($findings as $index => $finding) {
            self::assertSame($expectedHrefs[$index], $anchors[$index]['href'], $finding['kind'] . ' links to its target');
            self::assertSame(vm_finding_badge($finding), $anchors[$index]['inner'], $finding['kind'] . ' wraps exactly its badge');
        }
    }

    public function testAnEmptyFindingListSaysSoAndLinksNothing(): void
    {
        Lang::load('de');
        $vm = self::vm(['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, 'mecm_previous_id' => null]);

        $html = $this->panel($vm, self::evidence());

        self::assertStringContainsString('Kein Befund.', self::visible($html));
        self::assertStringNotContainsString('vm-finding-list', $html);
        self::assertSame([], self::anchors($html));
    }

    public function testTheClientPhaseTrackAndTheTechnicalBlockKeepTheirAnchors(): void
    {
        Lang::load('de');
        $html = $this->panel(self::vm(), self::evidence());

        self::assertStringContainsString('<h3 id="vm-client-phases">', $html);
        self::assertStringContainsString('<ol class="phase-track">', $html);
        self::assertStringContainsString('<h3 id="vm-technical">', $html);
        self::assertStringNotContainsString('<details', $html, 'the client event table moved to the timeline');
    }

    public function testTheTechnicalBlockShowsTheStageAndTheMecmIdentity(): void
    {
        Lang::load('de');
        $vm = self::vm();
        $evidence = self::evidence(['inventory' => self::inventory(['vms' => [self::UUID => 'poweredOn']])]);

        $values = self::definitions($this->panel($vm, $evidence));

        foreach (['4/5 OS Installing', self::RESOURCE_ID, self::OLD_RESOURCE_ID, self::UUID, self::MOID, self::HOST, '3'] as $value) {
            self::assertContains($value, $values, 'the technical block shows ' . $value);
        }
        self::assertContains(portal_lifecycle_label(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING), $values);
        self::assertContains(portal_mecm_sync_label(VIRTUSPHERE_MECM_SYNC_REGISTERED), $values);
        self::assertContains(
            vm_edit_inventory_text(virtusphere_vm_inventory_observation($vm, $evidence['inventory'])),
            $values,
            'the inventory line is the observation read by the same rule as the findings'
        );
    }

    public function testAnEmptyTechnicalValueSaysItIsNotSet(): void
    {
        Lang::load('de');
        $vm = self::vm([
            'mecm_id' => null,
            'mecm_previous_id' => null,
            'mecm_rollout_revision' => null,
            'vm_instance_uuid' => null,
            'vm_moid' => null,
            'vm_bound_esxi_host' => null,
        ]);

        $values = self::definitions($this->panel($vm, self::evidence()));

        self::assertGreaterThanOrEqual(6, count(array_filter($values, static fn (string $value): bool => $value === 'nicht gesetzt')));
        self::assertContains(vm_edit_inventory_text(virtusphere_vm_inventory_observation($vm, null)), $values, 'an unbound VM says so');
    }

    public function testTheEnglishPanelNamesThePhaseTheFindingsAndNoRawToken(): void
    {
        Lang::load('en');
        $evidence = self::evidence([
            'client_phases' => self::clientPhases([VIRTUSPHERE_CLIENT_PHASE_HOSTNAME => self::phaseEvent('failed')]),
            'inventory' => self::inventory(['vms' => [self::UUID => 'poweredOff']]),
        ]);

        $html = $this->panel(self::vm(), $evidence);
        $visible = self::visible($html);

        self::assertStringContainsString('Handed to MECM', $visible);
        self::assertStringContainsString('PXE evidence missing', $visible);
        self::assertStringContainsString('Delete the old MECM device', $visible);
        self::assertStringContainsString('Technical', $visible);
        self::assertStringNotContainsString('vm_edit.', $visible, 'an untranslated key is shown');
        self::assertStringNotContainsString('status.', $visible, 'an untranslated key is shown');
        foreach (self::FOREIGN_TOKENS as $token) {
            self::assertStringNotContainsString($token, $visible, 'the raw token ' . $token . ' is shown');
        }
    }

    /** @param string $kind @param string $anchor the editor anchor, or the page a finding belongs to */
    #[PHPUnit\Framework\Attributes\DataProvider('editorAnchors')]
    public function testAFindingOfTheEditorTargetLandsOnItsSectionOfTheSamePage(string $kind, string $anchor): void
    {
        $finding = [
            'kind' => $kind,
            'severity' => VIRTUSPHERE_VM_FINDINGS[$kind],
            'params' => [],
            'link' => ['target' => VIRTUSPHERE_VM_FINDING_LINK_VM_EDITOR],
        ];

        self::assertSame($anchor, vm_edit_finding_href($finding, self::MISSION_ID, self::VM_ID, []));
    }

    /** @return iterable<string, array{0:array<string,mixed>, 1:string}> */
    public static function delegatedTargets(): iterable
    {
        yield 'job log' => [['target' => VIRTUSPHERE_VM_FINDING_LINK_JOB_LOG, 'job_id' => self::JOB_ID], 'deploy_job_log_url'];
        yield 'MECM status' => [['target' => VIRTUSPHERE_VM_FINDING_LINK_SYSTEM_STATUS_MECM], 'system_status.php#mecm'];
        yield 'ESXi status' => [['target' => VIRTUSPHERE_VM_FINDING_LINK_SYSTEM_STATUS_ESXI], 'system_status.php#esxi'];
        yield 'deploy page' => [['target' => VIRTUSPHERE_VM_FINDING_LINK_DEPLOY], 'deploy.php?mission_id=42'];
        yield 'package run' => [['target' => VIRTUSPHERE_VM_FINDING_LINK_PACKAGE_RUN, 'run_id' => self::RUN_ID], 'package_run'];
    }

    /** @param array<string,mixed> $link @param string $expected a literal href, or the name of the helper that builds it */
    #[PHPUnit\Framework\Attributes\DataProvider('delegatedTargets')]
    public function testAnyOtherTargetIsTheFindingHrefOfTheSharedCell(array $link, string $expected): void
    {
        $finding = ['kind' => VIRTUSPHERE_VM_FINDING_JOB_RUNNING, 'severity' => 'info', 'params' => [], 'link' => $link];
        $href = vm_edit_finding_href($finding, self::MISSION_ID, self::VM_ID, []);

        self::assertSame(vm_finding_href($finding, self::MISSION_ID, self::VM_ID, []), $href);
        $literal = match ($expected) {
            'deploy_job_log_url' => deploy_job_log_url(self::JOB_ID),
            'package_run' => package_report_detail_url(self::MISSION_ID, self::VM_ID, self::RUN_ID, []),
            default => $expected,
        };
        self::assertSame($literal, $href);
    }

    public function testTheHistoryIsHiddenWithoutEvents(): void
    {
        Lang::load('de');

        self::assertSame('', trim($this->capture(static fn () => render_vm_status_history(['entries' => [], 'truncated' => false], self::MISSION_ID, self::VM_ID, []))));
    }

    public function testTheHistoryIsAnOpenTableThatNamesEachEventInWords(): void
    {
        Lang::load('de');
        $html = $this->history($this->timelineEntries(), false);
        $visible = self::visible($html);

        self::assertStringContainsString('<section', $html);
        self::assertStringContainsString('aria-labelledby="vm-timeline-heading"', $html);
        self::assertStringContainsString('<h2 id="vm-timeline-heading">', $html);
        self::assertStringContainsString('<table', $html);
        self::assertStringNotContainsString('<details', $html, 'the timeline is open without a click');

        self::assertStringContainsString(__t('vm_timeline.heading'), $visible);
        self::assertStringContainsString(__t('vm_timeline.hint', ['days' => VIRTUSPHERE_STATUS_EVENT_RETENTION_DAYS]), $visible);
        foreach (['source_status', 'source_job', 'source_package', 'source_client'] as $source) {
            self::assertStringContainsString(__t('vm_timeline.' . $source), $visible, $source);
        }

        self::assertStringContainsString(portal_format_timestamp(self::FRESH_AT), $visible, 'the time is shown in the portal timezone');
        self::assertStringContainsString(portal_lifecycle_label(VIRTUSPHERE_LIFECYCLE_OS_INSTALLING), $visible);
        self::assertStringContainsString(portal_mecm_sync_label(VIRTUSPHERE_MECM_SYNC_REGISTERED), $visible);
        self::assertStringContainsString('admin', $visible, 'the actor of a status event is named');
        self::assertStringContainsString(virtusphere_vm_status_note_text('mecm update id', 'de'), $visible, 'the note is shown in words');
        self::assertStringNotContainsString('mecm update id', $visible, 'the raw note is not shown');

        self::assertStringContainsString(__t('vm_timeline.job_started', ['id' => self::JOB_ID, 'mode' => deploy_mode_label('create')]), $visible);
        self::assertStringContainsString(__t('vm_timeline.job_finished', ['id' => self::JOB_ID]), $visible);
        self::assertStringContainsString(deploy_job_status_label(VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED), $visible);

        self::assertStringContainsString(__t('vm_timeline.package_received', ['package' => 'Paket 1.0']), $visible);
        self::assertStringContainsString(__t('vm_timeline.package_completed', ['package' => 'Paket 1.0']), $visible);
        self::assertStringContainsString(__t('vm_timeline.package_failed'), $visible);

        self::assertStringContainsString(client_phase_label(VIRTUSPHERE_CLIENT_PHASE_HOSTNAME) . ': ' . __t('vm_timeline.client_finished'), $visible);
        self::assertStringContainsString('synthetic detail', $visible);

        foreach (self::FOREIGN_TOKENS as $token) {
            self::assertStringNotContainsString($token, $visible, 'the raw token ' . $token . ' is shown');
        }
        self::assertStringNotContainsString('vm_timeline.', $visible, 'an untranslated key is shown');
        self::assertStringNotContainsString('vm_edit.', $visible, 'an untranslated key is shown');
    }

    public function testTheHistoryLinksEachJobAndPackageRunToItsPage(): void
    {
        Lang::load('de');
        $html = $this->history($this->timelineEntries(), false);

        $hrefs = array_column(self::anchors($html), 'href');
        self::assertContains(deploy_job_log_url(self::JOB_ID), $hrefs);
        self::assertContains(package_report_detail_url(self::MISSION_ID, self::VM_ID, self::RUN_ID, []), $hrefs);
    }

    public function testTheTruncationNoticeAppearsOnlyWhenTheListWasCut(): void
    {
        Lang::load('de');
        $notice = __t('vm_timeline.truncated', ['limit' => VIRTUSPHERE_VM_TIMELINE_LIMIT]);

        self::assertStringContainsString($notice, self::visible($this->history($this->timelineEntries(), true)));
        self::assertStringNotContainsString($notice, self::visible($this->history($this->timelineEntries(), false)));
    }

    public function testTheEnglishHistoryNamesTheTranslatedNote(): void
    {
        Lang::load('en');
        $visible = self::visible($this->history($this->timelineEntries(), false));

        self::assertStringContainsString(virtusphere_vm_status_note_text('mecm update id', 'en'), $visible);
        self::assertStringNotContainsString('mecm update id', $visible);
        self::assertStringNotContainsString('vm_timeline.', $visible, 'an untranslated key is shown');
    }

    /** @param array<string,mixed> $observation @param string $expected the German wording, with {time} for the observation's time */
    #[PHPUnit\Framework\Attributes\DataProvider('inventoryTexts')]
    public function testTheInventoryLineNamesTheStateAndItsTimeInGerman(array $observation, string $expected): void
    {
        Lang::load('de');

        self::assertSame(str_replace('{time}', portal_format_timestamp(self::FRESH_AT), $expected), vm_edit_inventory_text($observation));
    }

    public function testTheInventoryLineHasNoPlaceholderAndNoKeyInEitherLocale(): void
    {
        $observations = [
            ['state' => VIRTUSPHERE_VM_INVENTORY_UNBOUND, 'power' => null, 'time' => ''],
            ['state' => VIRTUSPHERE_VM_INVENTORY_NONE, 'power' => null, 'time' => ''],
            ['state' => VIRTUSPHERE_VM_INVENTORY_UNANSWERED, 'power' => null, 'time' => self::FRESH_AT],
            ['state' => VIRTUSPHERE_VM_INVENTORY_STALE, 'power' => null, 'time' => self::FRESH_AT],
            ['state' => VIRTUSPHERE_VM_INVENTORY_STALE, 'power' => null, 'time' => ''],
            ['state' => VIRTUSPHERE_VM_INVENTORY_MISSING, 'power' => null, 'time' => self::FRESH_AT],
            ['state' => VIRTUSPHERE_VM_INVENTORY_PRESENT, 'power' => 'poweredOn', 'time' => self::FRESH_AT],
            ['state' => VIRTUSPHERE_VM_INVENTORY_PRESENT, 'power' => 'poweredOff', 'time' => self::FRESH_AT],
            ['state' => VIRTUSPHERE_VM_INVENTORY_PRESENT, 'power' => 'suspended', 'time' => self::FRESH_AT],
            ['state' => VIRTUSPHERE_VM_INVENTORY_PRESENT, 'power' => null, 'time' => self::FRESH_AT],
        ];

        foreach (['de', 'en'] as $locale) {
            Lang::load($locale);
            foreach ($observations as $observation) {
                $text = vm_edit_inventory_text($observation);
                $label = $locale . ' ' . $observation['state'] . '/' . ($observation['power'] ?? 'none');

                self::assertNotSame('', trim($text), $label);
                self::assertStringNotContainsString('vm_edit.', $text, $label . ' shows its key');
                self::assertDoesNotMatchRegularExpression('/:[a-z]/', $text, $label . ' keeps a placeholder');
                // An unanswered inventory is no evidence, so its line names no time, even when one is stored.
                if ($observation['time'] !== '' && $observation['state'] !== VIRTUSPHERE_VM_INVENTORY_UNANSWERED) {
                    self::assertStringContainsString(portal_format_timestamp(self::FRESH_AT), $text, $label . ' does not show its time');
                }
                if ($observation['time'] !== '' && $observation['state'] === VIRTUSPHERE_VM_INVENTORY_UNANSWERED) {
                    self::assertStringNotContainsString(portal_format_timestamp(self::FRESH_AT), $text, $label . ' names a time');
                }
            }
        }
    }

    public function testAPoweredOffOrSuspendedVmIsNamedInGermanWithItsTime(): void
    {
        Lang::load('de');
        $time = portal_format_timestamp(self::FRESH_AT);

        $off = vm_edit_inventory_text(['state' => VIRTUSPHERE_VM_INVENTORY_PRESENT, 'power' => 'poweredOff', 'time' => self::FRESH_AT]);
        $suspended = vm_edit_inventory_text(['state' => VIRTUSPHERE_VM_INVENTORY_PRESENT, 'power' => 'suspended', 'time' => self::FRESH_AT]);

        self::assertStringContainsString('ausgeschaltet', $off);
        self::assertStringContainsString($time, $off);
        self::assertStringContainsString('angehalten', $suspended);
        self::assertStringContainsString($time, $suspended);
    }

    /** @return array<string,mixed> */
    private static function vm(array $overrides = []): array
    {
        return $overrides + [
            'id' => self::VM_ID,
            'mission_id' => self::MISSION_ID,
            'vm_name' => 'E2EVM-RENDER',
            'vm_status' => VIRTUSPHERE_STATUS_OS_INSTALLING,
            'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLING,
            'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_REGISTERED,
            'mecm_id' => self::RESOURCE_ID,
            'mecm_previous_id' => self::OLD_RESOURCE_ID,
            'mecm_rollout_revision' => 3,
            'vm_instance_uuid' => self::UUID,
            'vm_moid' => self::MOID,
            'vm_bound_esxi_host' => self::HOST,
            'vm_bound_credential_id' => null,
            'mecm_pending_since' => null,
            'os_install_watch_started_at' => null,
            'updated' => 0,
            'updated_at' => null,
        ];
    }

    /** @return array<string,mixed> */
    private static function evidence(array $overrides = []): array
    {
        return $overrides + [
            'is_template' => false,
            'last_job' => null,
            'create_uncertain' => [],
            'client_phases' => self::clientPhases([]),
            'last_package_run' => null,
            'inventory' => null,
        ];
    }

    /**
     * @param array<string,array<string,mixed>|null> $phases
     * @return array<string,array<string,mixed>|null>
     */
    private static function clientPhases(array $phases): array
    {
        return array_replace(array_fill_keys(VIRTUSPHERE_CLIENT_PHASES, null), $phases);
    }

    /** @return array<string,mixed> */
    private static function phaseEvent(string $event): array
    {
        return ['event' => $event, 'created_at' => self::FRESH_AT];
    }

    /** @return array<string,mixed> */
    private static function inventory(array $overrides = []): array
    {
        return $overrides + [
            'fresh' => true,
            'answered' => true,
            'fresh_at' => self::FRESH_AT,
            'vms' => [],
        ];
    }

    private static function now(): int
    {
        return strtotime('2026-10-25 12:00:00 UTC');
    }

    /** @return list<array{at:string,source:string,kind:string,row:array<string,mixed>}> one entry of every kind */
    private function timelineEntries(): array
    {
        return [
            [
                'at' => self::FRESH_AT,
                'source' => VIRTUSPHERE_VM_TIMELINE_SOURCE_STATUS,
                'kind' => VIRTUSPHERE_VM_TIMELINE_STATUS,
                'row' => [
                    'lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLING,
                    'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_REGISTERED,
                    'legacy_status' => VIRTUSPHERE_STATUS_OS_INSTALLING,
                    'note' => 'mecm update id',
                    'created_at' => self::FRESH_AT,
                    'actor_name' => 'admin',
                ],
            ],
            [
                'at' => '2026-10-20 09:00:00',
                'source' => VIRTUSPHERE_VM_TIMELINE_SOURCE_JOB,
                'kind' => VIRTUSPHERE_VM_TIMELINE_JOB_STARTED,
                'row' => ['id' => self::JOB_ID, 'status' => VIRTUSPHERE_DEPLOY_STATUS_RUNNING, 'mode' => 'create'],
            ],
            [
                'at' => '2026-10-20 09:30:00',
                'source' => VIRTUSPHERE_VM_TIMELINE_SOURCE_JOB,
                'kind' => VIRTUSPHERE_VM_TIMELINE_JOB_FINISHED,
                'row' => ['id' => self::JOB_ID, 'status' => VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED, 'mode' => 'create'],
            ],
            [
                'at' => '2026-10-20 08:00:00',
                'source' => VIRTUSPHERE_VM_TIMELINE_SOURCE_PACKAGE,
                'kind' => VIRTUSPHERE_VM_TIMELINE_PACKAGE_RECEIVED,
                'row' => [
                    'run_id' => self::RUN_ID,
                    'project_name' => 'Paket',
                    'package_version' => '1.0',
                    'completed_received_at' => '2026-10-20 08:10:00',
                    'wrapper_result' => 'failed',
                    'reported_failures' => 1,
                ],
            ],
            [
                'at' => '2026-10-20 08:10:00',
                'source' => VIRTUSPHERE_VM_TIMELINE_SOURCE_PACKAGE,
                'kind' => VIRTUSPHERE_VM_TIMELINE_PACKAGE_COMPLETED,
                'row' => [
                    'run_id' => self::RUN_ID,
                    'project_name' => 'Paket',
                    'package_version' => '1.0',
                    'completed_received_at' => '2026-10-20 08:10:00',
                    'wrapper_result' => 'failed',
                    'reported_failures' => 1,
                ],
            ],
            [
                'at' => '2026-10-20 07:00:00',
                'source' => VIRTUSPHERE_VM_TIMELINE_SOURCE_CLIENT,
                'kind' => VIRTUSPHERE_VM_TIMELINE_CLIENT,
                'row' => [
                    'phase' => VIRTUSPHERE_CLIENT_PHASE_HOSTNAME,
                    'event' => VIRTUSPHERE_CLIENT_EVENT_FINISHED,
                    'detail' => 'synthetic detail',
                    'created_at' => '2026-10-20 07:00:00',
                ],
            ],
        ];
    }

    /**
     * @param list<array{at:string,source:string,kind:string,row:array<string,mixed>}> $entries
     */
    private function history(array $entries, bool $truncated): string
    {
        return $this->capture(static fn () => render_vm_status_history(
            ['entries' => $entries, 'truncated' => $truncated],
            self::MISSION_ID,
            self::VM_ID,
            []
        ));
    }

    private function panel(array $vm, array $evidence): string
    {
        $phase = virtusphere_vm_phase($vm, $evidence);
        $findings = virtusphere_vm_findings($vm, $evidence, self::now());

        return $this->capture(static fn () => vm_edit_render_status_panel(
            $vm,
            $phase,
            $findings,
            $evidence['client_phases'],
            $evidence['inventory'],
            self::MISSION_ID,
            []
        ));
    }

    private function capture(callable $render): string
    {
        ob_start();
        $render();

        return (string) ob_get_clean();
    }

    /** The text a person reads: tags and attributes removed, entities decoded, spaces collapsed. */
    private static function visible(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return list<string> the visible text of every value of the technical block */
    private static function definitions(string $html): array
    {
        preg_match_all('/<dd[^>]*>(.*?)<\/dd>/s', $html, $matches);

        return array_map(static fn (string $dd): string => self::visible($dd), $matches[1]);
    }

    /** @return list<array{href:string, inner:string}> every link of the markup, in order */
    private static function anchors(string $html): array
    {
        preg_match_all('/<a href="([^"]*)">(.*?)<\/a>/s', $html, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match): array => [
            'href' => html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'inner' => $match[2],
        ], $matches);
    }
}
