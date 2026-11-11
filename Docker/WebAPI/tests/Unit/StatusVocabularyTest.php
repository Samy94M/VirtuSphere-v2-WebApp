<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SY2: one status vocabulary (lib/status_vocabulary.php). Every status badge of
 * System status, the dashboard tiles and the settings backup card is a tone: a
 * fixed colour, icon and word. These tests walk the tone table and the token
 * sets of every source instead of listing words, and pin the inconsistencies
 * the UX review found (qa-artifacts/ux-systemstatus-2026-11-09, matrix):
 * "Aktiv" vs "OK", six words for "never checked", a yellow "Veraltet" only at
 * the backup, "Fehler" for a paused ESXi sign-in, "Abweichung" in two meanings.
 */
final class StatusVocabularyTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/layout.php';
        require_once dirname(__DIR__, 2) . '/lib/backup_status.php';
        require_once dirname(__DIR__, 2) . '/lib/directory_constants.php';
        require_once dirname(__DIR__, 2) . '/lib/mecm_site_display.php';
        require_once dirname(__DIR__, 2) . '/lib/system_status_backup_panels.php';
        Lang::load('de');
    }

    protected function tearDown(): void
    {
        Lang::load(Lang::DEFAULT_LOCALE);
    }

    /** The visible text of a badge: its markup without tags. */
    private static function text(string $badge): string
    {
        return strip_tags($badge);
    }

    public function testEveryToneHasColourIconWordAndLegendInBothLocales(): void
    {
        $icons = [];
        foreach (Lang::LOCALES as $locale) {
            Lang::load($locale);
            foreach (status_tones() as $tone) {
                $meta = status_tone_meta($tone);
                self::assertContains($meta['variant'], ['success', 'warning', 'danger', 'neutral', 'info'], $tone);
                $icons[$tone] = $meta['icon'];
                self::assertNotSame('status_vocabulary.' . $tone, status_tone_word($tone), "$locale: no word for $tone");
                self::assertNotSame('status_vocabulary.legend_' . $tone, __t('status_vocabulary.legend_' . $tone), "$locale: no legend for $tone");
            }
            foreach (VIRTUSPHERE_STATUS_PHRASES as $phrase => $tone) {
                self::assertContains($tone, status_tones(), "$phrase is bound to an unknown tone");
                self::assertNotSame('status_vocabulary.' . $phrase, status_tone_word($phrase), "$locale: no word for $phrase");
                self::assertNotSame('status_vocabulary.legend_' . $phrase, __t('status_vocabulary.legend_' . $phrase), "$locale: no legend for $phrase");
            }
        }
        // One icon per tone; only "OK" and "Aktuell" share the check mark, both green.
        $shared = array_filter(array_count_values($icons), static fn (int $n): bool => $n > 1);
        self::assertSame([], $shared, 'every tone has its own icon class');
    }

    public function testEveryWordIsUniqueWithinALocale(): void
    {
        foreach (Lang::LOCALES as $locale) {
            Lang::load($locale);
            $words = array_map('status_tone_word', [...status_tones(), ...array_keys(VIRTUSPHERE_STATUS_PHRASES)]);
            self::assertSame($words, array_values(array_unique($words)), "$locale: two tones share a word");
        }
    }

    public function testAnUnknownToneIsAnErrorNotAGreyBadge(): void
    {
        $this->expectException(InvalidArgumentException::class);
        status_tone_word('aktiv');
    }

    /** @return iterable<string, array{string, list<string>}> tone kind => the page's own state set */
    public static function tokenSets(): iterable
    {
        require_once dirname(__DIR__, 2) . '/lib/constants.php';
        require_once dirname(__DIR__, 2) . '/lib/directory_constants.php';
        require_once dirname(__DIR__, 2) . '/lib/mecm_site_assessment.php';
        yield 'result' => ['result', VIRTUSPHERE_HEARTBEAT_STATES];
        yield 'liveness' => ['liveness', VIRTUSPHERE_HEARTBEAT_STATES];
        yield 'esxi' => ['esxi', VIRTUSPHERE_ESXI_AMPEL_STATES];
        yield 'ansible' => ['ansible', VIRTUSPHERE_ANSIBLE_AMPEL_STATES];
        yield 'directory' => ['directory', VIRTUSPHERE_DIRECTORY_AMPEL_STATES];
        yield 'directory_controller' => ['directory_controller', VIRTUSPHERE_DIRECTORY_CONTROLLER_AMPEL_STATES];
        yield 'mecm_site' => ['mecm_site', [
            VIRTUSPHERE_MECM_SITE_VERDICT_OK,
            VIRTUSPHERE_MECM_SITE_VERDICT_WARNING,
            VIRTUSPHERE_MECM_SITE_VERDICT_LIMITED,
            VIRTUSPHERE_MECM_SITE_VERDICT_CRITICAL,
            VIRTUSPHERE_MECM_SITE_VERDICT_UNKNOWN,
            'overdue',
            'stale',
            'none',
        ]];
    }

    /** @param list<string> $states */
    #[DataProvider('tokenSets')]
    public function testEveryKindMapsExactlyItsSourceStatesToATone(string $kind, array $states): void
    {
        self::assertEqualsCanonicalizing($states, VIRTUSPHERE_STATUS_TONE_KINDS[$kind]);
        foreach ($states as $state) {
            self::assertContains(status_tone_for($kind, $state), status_tones(), "$kind/$state");
        }
    }

    public function testTheRankOrdersTonesLikeTheirTokens(): void
    {
        // Kritisch > Eingeschränkt > Warnung > grey > green, on the one scale.
        self::assertSame(virtusphere_heartbeat_state_rank('danger'), status_tone_rank('critical'));
        self::assertSame(status_tone_rank('critical'), status_tone_rank('unreachable'));
        self::assertGreaterThan(status_tone_rank('limited'), status_tone_rank('critical'));
        self::assertGreaterThan(status_tone_rank('warning'), status_tone_rank('limited'));
        foreach (['unknown', 'stale', 'no_data'] as $grey) {
            self::assertGreaterThan(status_tone_rank($grey), status_tone_rank('warning'), $grey);
            self::assertGreaterThan(status_tone_rank('ok'), status_tone_rank($grey), "$grey must never rank as OK");
        }
        foreach (['current', 'running', 'disabled'] as $fine) {
            self::assertSame(status_tone_rank('ok'), status_tone_rank($fine), $fine);
        }
    }

    public function testGreenIsAlwaysOkAndNeverAktiv(): void
    {
        foreach ([directory_state_badge('ok'), directory_controller_state_badge('ok'), esxi_state_badge('ok'), ansible_state_badge('ok'), heartbeat_badge('ok'), mecm_site_badge('ok'), status_tone_badge(backup_status_tone(VIRTUSPHERE_BACKUP_STATE_OK))] as $badge) {
            self::assertSame('OK', self::text($badge));
            self::assertStringContainsString('badge-success', $badge);
        }
    }

    public function testNeverCheckedIsOneWordAndOneGreyEverywhere(): void
    {
        $badges = [
            heartbeat_badge('unknown'),
            heartbeat_badge('unknown', 'liveness'),
            esxi_state_badge('unknown'),
            ansible_state_badge('unknown'),
            directory_controller_state_badge('unknown'),
            deviation_count_badge(null, false),
            mecm_site_badge('none'),
            status_tone_badge(backup_status_tone(VIRTUSPHERE_BACKUP_STATE_UNKNOWN)),
            status_tone_badge(system_status_backup_drill_tone(null)),
            status_tone_badge(system_status_backup_scrub_tone(null)),
        ];
        foreach ($badges as $badge) {
            self::assertSame(status_tone_badge('no_data'), $badge);
        }
        self::assertSame('Keine Daten', self::text($badges[0]));
    }

    public function testStaleIsAlwaysGreyAndAnOverdueBackupIsAWarning(): void
    {
        foreach ([heartbeat_badge('stale'), esxi_state_badge('stale'), ansible_state_badge('stale'), directory_controller_state_badge('stale'), mecm_site_badge('stale')] as $badge) {
            self::assertSame(status_tone_badge('stale'), $badge);
            self::assertStringContainsString('badge-neutral', $badge);
        }
        $overdue = status_tone_badge(backup_status_tone(VIRTUSPHERE_BACKUP_STATE_STALE));
        self::assertStringContainsString('badge-warning', $overdue);
        self::assertNotSame(status_tone_word('stale'), self::text($overdue), 'an overdue backup is a warning, not the grey "stale"');
    }

    public function testAPausedOrFailingEsxiSignInIsCriticalNotFehler(): void
    {
        $badge = esxi_state_badge('danger');
        self::assertSame(status_tone_badge('critical'), $badge);
        self::assertSame('Kritisch', self::text($badge));
    }

    public function testAChecksumMismatchNoLongerReadsAbweichung(): void
    {
        $tone = system_status_backup_scrub_tone(['state' => VIRTUSPHERE_BACKUP_EVIDENCE_FAILED, 'ts' => 1_800_000_000]);
        self::assertSame('critical', $tone);
        self::assertStringNotContainsString('Abweichung', status_tone_badge($tone));
    }

    public function testALivenessRowSpeaksReporterWords(): void
    {
        self::assertSame(status_tone_badge('current'), heartbeat_badge('ok', 'liveness'));
        self::assertSame(status_tone_badge('unreachable'), heartbeat_badge('danger', 'liveness'));
        self::assertSame(status_tone_badge('critical'), heartbeat_badge('danger'));
    }

    public function testABadgeCarriesADecorativeIconAndItsWordAsWholeText(): void
    {
        foreach ([...status_tones(), ...array_keys(VIRTUSPHERE_STATUS_PHRASES)] as $toneOrPhrase) {
            $tone = VIRTUSPHERE_STATUS_PHRASES[$toneOrPhrase] ?? $toneOrPhrase;
            $meta = status_tone_meta($tone);
            $badge = status_tone_badge($toneOrPhrase);
            self::assertSame(
                '<span class="badge badge-' . $meta['variant'] . '"><span class="badge-icon badge-icon-' . $meta['icon'] . '" aria-hidden="true"></span>' . h(status_tone_word($toneOrPhrase)) . '</span>',
                $badge
            );
            self::assertSame(status_tone_word($toneOrPhrase), html_entity_decode(self::text($badge)));
        }
        // A plain label badge elsewhere in the portal stays without an icon.
        self::assertSame('<span class="badge badge-info">Vorlage</span>', portal_badge('info', 'Vorlage'));
    }

    public function testEveryIconHasAGlyphRule(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/assets/css/feedback.css');
        foreach (status_tones() as $tone) {
            $icon = status_tone_meta($tone)['icon'];
            self::assertMatchesRegularExpression('/\.badge-icon-' . preg_quote($icon, '/') . '::before[^{]*\{\s*content:\s*"[^"]+";/s', $css, $icon);
        }
    }

    /**
     * The guard against a second vocabulary, derived rather than listed: no
     * System status module builds a badge with portal_badge() directly; every
     * badge goes through status_tone_badge() or status_value_badge(), so it
     * cannot carry a colour without its icon or a level word of its own.
     */
    public function testNoSystemStatusModuleBuildsARawBadge(): void
    {
        $root = dirname(__DIR__, 2);
        $paths = [...(glob($root . '/lib/system_status*.php') ?: []), $root . '/lib/mecm_site_display.php', $root . '/lib/deploy_service_health.php'];
        self::assertGreaterThan(10, count($paths));
        foreach ($paths as $path) {
            self::assertDoesNotMatchRegularExpression('/\bportal_badge\(/', (string) file_get_contents($path), basename($path) . ' builds a raw badge; use status_tone_badge() or status_value_badge()');
        }
        // The presenters that render a source's state map it through the tone table.
        $presenters = (string) file_get_contents($root . '/lib/layout_presenters.php');
        foreach (['heartbeat_badge', 'esxi_state_badge', 'ansible_state_badge', 'directory_state_badge', 'directory_controller_state_badge'] as $function) {
            self::assertSame(1, preg_match('/function ' . $function . '\(.*?\n\}/s', $presenters, $body), $function);
            self::assertStringContainsString('status_tone_badge(status_tone_for(', $body[0], $function . ' must take its word from the tone table');
        }
    }
}
