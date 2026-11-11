<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SY2: the headline over the System status overview answers "is everything in
 * order, and if not, where" in one sentence. Areas needing attention come
 * first, worst first; grey areas are never "in order"; the headline reads the
 * same tones the cards render, and it is the page's only live region.
 */
final class SystemStatusHeadlineTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/portal_time.php';
        require_once dirname(__DIR__, 2) . '/lib/csrf.php';
        require_once dirname(__DIR__, 2) . '/lib/layout.php';
        require_once dirname(__DIR__, 2) . '/lib/system_status_panels.php';
        Lang::load('de');
    }

    /** @return array{anchor: string, label: string, tone: string, html: string} */
    private static function card(string $anchor, string $label, string $tone): array
    {
        return ['anchor' => $anchor, 'label' => $label, 'tone' => $tone, 'html' => status_tone_badge($tone)];
    }

    /** @return list<array{anchor: string, label: string, tone: string, html: string}> */
    private static function cards(string ...$tones): array
    {
        $anchors = [
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEPLOY_SERVICE,
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM,
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ANSIBLE,
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI,
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEVIATIONS,
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_HOST_BACKUP,
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_INTERNAL,
        ];
        $cards = [];
        foreach ($tones as $i => $tone) {
            $cards[] = self::card($anchors[$i], 'Bereich' . ($i + 1), $tone);
        }

        return $cards;
    }

    /** @return list<string> the link labels of the headline, in order */
    private static function links(string $html): array
    {
        preg_match_all('/<a href="[^"]*">([^<]+)<\/a>/', $html, $m);

        return $m[1];
    }

    public function testAllInOrderSaysSoWithoutLinks(): void
    {
        $html = system_status_render_overview_headline(self::cards('ok', 'ok', 'running', 'ok', 'ok', 'current', 'disabled'));
        self::assertStringContainsString('role="status"', $html);
        self::assertStringContainsString('data-status-headline="ok"', $html);
        self::assertStringContainsString(h(__t('system_status_overview.headline_ok')), $html);
        self::assertSame([], self::links($html));
    }

    public function testAttentionComesWorstFirstThenPageOrderAndGreyApart(): void
    {
        // Page order: warning (1), grey (2), critical (3), limited (4), warning (5).
        $html = system_status_render_overview_headline(self::cards('warning', 'no_data', 'critical', 'limited', 'warning', 'ok', 'ok'));

        self::assertSame(['Bereich3', 'Bereich4', 'Bereich1', 'Bereich5', 'Bereich2'], self::links($html));
        self::assertStringContainsString(h(__t('system_status_overview.headline_attention_many', ['count' => 4])), $html);
        self::assertStringContainsString(h(__t('system_status_overview.headline_no_evidence_one')), $html);
        self::assertStringContainsString('data-status-headline="critical"', $html);
        self::assertStringContainsString('badge-icon-critical', $html);
        self::assertStringNotContainsString(h(__t('system_status_overview.headline_ok')), $html);
    }

    public function testOnlyGreyIsNeverInOrder(): void
    {
        foreach (['unknown', 'stale', 'no_data'] as $grey) {
            $html = system_status_render_overview_headline(self::cards('ok', $grey, 'ok', $grey, 'ok', 'ok', 'ok'));
            self::assertStringNotContainsString(h(__t('system_status_overview.headline_ok')), $html, $grey);
            self::assertStringContainsString(h(__t('system_status_overview.headline_no_evidence_many', ['count' => 2])), $html, $grey);
            self::assertSame(['Bereich2', 'Bereich4'], self::links($html));
            self::assertStringContainsString('data-status-headline="' . $grey . '"', $html);
        }
    }

    public function testOneAreaUsesTheSingularSentence(): void
    {
        $html = system_status_render_overview_headline(self::cards('ok', 'ok', 'unreachable', 'ok', 'ok', 'ok', 'ok'));
        self::assertStringContainsString(h(__t('system_status_overview.headline_attention_one')), $html);
        self::assertSame(['Bereich3'], self::links($html));
    }

    public function testTheLinksJumpThroughTheUrlBuilderWithTheCurrentSelection(): void
    {
        $html = system_status_render_overview_headline(self::cards('critical', 'ok', 'ok', 'ok', 'ok', 'ok', 'ok'), ['inventory' => 7]);
        self::assertStringContainsString('href="' . h(system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEPLOY_SERVICE, ['inventory' => 7])) . '"', $html);
    }

    /**
     * The real strip: the headline reads the tones the cards carry, so a red
     * backup card is named and a healthy page reads "in order" only when no
     * card is grey (R58).
     */
    public function testTheRenderedHeadlineReadsTheSameTonesAsTheCards(): void
    {
        $snapshot = [
            'mecm_sync' => ['state' => 'ok'],
            'mecm_site' => ['state' => 'ok', 'site_state' => VIRTUSPHERE_MECM_SITE_VERDICT_OK],
            'ansible' => ['state' => 'ok'],
            'esxi' => ['state' => 'ok'],
            'internal' => ['state' => 'ok'],
        ];
        $service = [
            'availability' => VIRTUSPHERE_DEPLOY_AVAILABILITY_READY,
            'claim_state' => VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING,
            'recovery_attention' => VIRTUSPHERE_DEPLOY_ATTENTION_NONE,
            'badge' => 'success',
        ];
        $okHost = [
            'backup' => ['state' => VIRTUSPHERE_BACKUP_STATE_OK, 'last' => ['ts' => 1_800_000_000]],
            'drill' => ['state' => VIRTUSPHERE_BACKUP_EVIDENCE_OK, 'ts' => 1_800_000_000, 'age_days' => 3],
            'scrub' => ['state' => VIRTUSPHERE_BACKUP_EVIDENCE_OK, 'ts' => 1_800_000_000],
            'https' => null,
        ];
        $render = static function (array $host) use ($snapshot, $service): string {
            ob_start();
            system_status_render_overview($snapshot, 0, [], $service, null, $host, ['fully_current' => true]);

            return (string) ob_get_clean();
        };

        $healthy = $render($okHost);
        self::assertStringContainsString('data-status-headline="ok"', $healthy);
        self::assertSame(1, substr_count($healthy, 'role="status"'), 'only the headline is a live region');
        self::assertStringNotContainsString('role="status"', substr($healthy, (int) strpos($healthy, '<nav')), 'no card announces itself');

        $failed = $render(['backup' => ['state' => VIRTUSPHERE_BACKUP_STATE_FAILED, 'last' => ['ts' => 1_800_000_000]]] + $okHost);
        self::assertStringContainsString('data-status-headline="critical"', $failed);
        self::assertSame([__t('system_status.overview_host_backup')], self::links(substr($failed, 0, (int) strpos($failed, '<nav'))));

        // Every card carries the tone of the badge it renders.
        preg_match_all('/data-overview-tone="([a-z_]+)">\s*<span class="status-overview-label">[^<]*<\/span>\s*(.*?)\s*<\/a>/s', $failed, $cards, PREG_SET_ORDER);
        self::assertCount(7, $cards);
        foreach ($cards as [, $tone, $html]) {
            self::assertStringContainsString('badge-' . status_tone_meta($tone)['variant'], $html, $tone);
        }
    }

    public function testTheCardsRunInSectionOrderWithTheDirectorySecond(): void
    {
        $snapshot = [
            'mecm_sync' => ['state' => 'ok'],
            'mecm_site' => ['state' => 'ok', 'site_state' => VIRTUSPHERE_MECM_SITE_VERDICT_OK],
            'ansible' => ['state' => 'ok'],
            'esxi' => ['state' => 'ok'],
            'internal' => ['state' => 'ok'],
        ];
        $anchors = array_column(system_status_overview_cards($snapshot, 0, null, ['snapshot' => ['overall' => 'ok']], [], null), 'anchor');
        self::assertSame([
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEPLOY_SERVICE,
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DIRECTORY,
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM,
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ANSIBLE,
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI,
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEVIATIONS,
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_HOST_BACKUP,
            VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_INTERNAL,
        ], $anchors);
    }
}
