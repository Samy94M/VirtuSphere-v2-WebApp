<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/run_report.php';

/**
 * Pure wire validation for mecm_report.php?action=reportRun (ADR-0018). Every
 * rejection returns the same {error, status:400} envelope the endpoint emits,
 * and every accepted report is normalized for repo_record_run_report().
 */
final class RunReportValidateTest extends TestCase
{
    private const HEX = '00112233445566778899aabbccddeeff';

    /** @param array<string,mixed> $data */
    private function base(array $data): array
    {
        return array_merge(['source' => 'device-sync', 'event' => 'started', 'run_id' => self::HEX, 'interval_seconds' => 10], $data);
    }

    public function testRejectsUnknownSource(): void
    {
        $r = run_report_validate($this->base(['source' => 'nope']));
        self::assertSame('Invalid source', $r['error']);
        self::assertSame(400, $r['status']);
    }

    public function testRejectsUnknownEvent(): void
    {
        self::assertSame('Invalid event', run_report_validate($this->base(['event' => 'paused']))['error']);
    }

    public function testSiteHealthMayNotAnnounceAStart(): void
    {
        $r = run_report_validate($this->base(['source' => 'mecm-site-health', 'event' => 'started', 'interval_seconds' => 300]));
        self::assertSame('Invalid event', $r['error']);
    }

    public function testRejectsMalformedRunId(): void
    {
        self::assertSame('Invalid run_id', run_report_validate($this->base(['run_id' => 'short']))['error']);
        self::assertSame('Invalid run_id', run_report_validate($this->base(['run_id' => strtoupper(self::HEX)]))['error']);
    }

    public function testRejectsIntervalOutOfBounds(): void
    {
        self::assertSame('Invalid interval_seconds', run_report_validate($this->base(['interval_seconds' => 0]))['error']);
        self::assertSame('Invalid interval_seconds', run_report_validate($this->base(['interval_seconds' => 99999]))['error']);
    }

    public function testCompletedRequiresAKnownOutcome(): void
    {
        self::assertSame('Invalid outcome', run_report_validate($this->base(['event' => 'completed', 'outcome' => 'meh']))['error']);
    }

    public function testANonOkOutcomeRequiresAKnownCategory(): void
    {
        self::assertSame('Invalid error_category', run_report_validate($this->base(['event' => 'completed', 'outcome' => 'fail']))['error']);
        // A sync source may not borrow a site category.
        self::assertSame('Invalid error_category', run_report_validate($this->base(['event' => 'completed', 'outcome' => 'fail', 'error_category' => 'site_critical']))['error']);
    }

    public function testSiteCategoryOutcomeBindingIsEnforced(): void
    {
        $bad = run_report_validate([
            'source' => 'mecm-site-health', 'event' => 'completed', 'run_id' => self::HEX, 'interval_seconds' => 300,
            'outcome' => 'fail', 'error_category' => 'site_warning',
        ]);
        self::assertSame('Invalid error_category', $bad['error']);
        // provider faults must be unknown, never fail.
        $bad2 = run_report_validate([
            'source' => 'mecm-site-health', 'event' => 'completed', 'run_id' => self::HEX, 'interval_seconds' => 300,
            'outcome' => 'fail', 'error_category' => 'provider_unreachable',
        ]);
        self::assertSame('Invalid error_category', $bad2['error']);
    }

    public function testRejectsBadDurationAndSummary(): void
    {
        self::assertSame('Invalid duration_ms', run_report_validate($this->base(['event' => 'completed', 'outcome' => 'ok', 'duration_ms' => 999999999999]))['error']);
        self::assertSame('Invalid summary', run_report_validate($this->base(['event' => 'completed', 'outcome' => 'ok', 'summary' => ['bogus' => 1]]))['error']);
        self::assertSame('Invalid summary', run_report_validate($this->base(['event' => 'completed', 'outcome' => 'ok', 'summary' => ['received' => 'x']]))['error']);
        self::assertSame('Invalid summary', run_report_validate($this->base(['event' => 'completed', 'outcome' => 'ok', 'summary' => [1, 2, 3]]))['error']);
        // A device-sync may not carry a packages-sync key.
        self::assertSame('Invalid summary', run_report_validate($this->base(['event' => 'completed', 'outcome' => 'ok', 'summary' => ['packages' => 1]]))['error']);
    }

    public function testAcceptsAValidStartedReport(): void
    {
        $r = run_report_validate($this->base([]));
        self::assertArrayHasKey('report', $r);
        self::assertSame('started', $r['report']['event']);
        self::assertArrayNotHasKey('outcome', $r['report']);
    }

    public function testAcceptsAValidCompletedReportAndSanitizesDetail(): void
    {
        $r = run_report_validate($this->base([
            'event' => 'completed', 'outcome' => 'ok',
            'summary' => ['received' => 5, 'imported' => 5],
            'duration_ms' => 100, 'detail' => "line\x00two", 'script_version' => '2.0.0',
        ]));
        self::assertArrayHasKey('report', $r);
        self::assertSame('ok', $r['report']['outcome']);
        self::assertNull($r['report']['error_category']);
        self::assertSame(['received' => 5, 'imported' => 5], $r['report']['summary']);
        self::assertSame('line two', $r['report']['detail']);
        self::assertSame('2.0.0', $r['report']['script_version']);
    }

    /**
     * The warning path of device-sync and the autoimporter used to send
     * `detail: null`, so the System status card showed "data warnings: 3" and
     * never named a single VM. They now send a cause line built from a closed
     * vocabulary (Format-VsRunDetail), naming the VM and the collection. That
     * needs no wire change, and this pins it: the shape those scripts emit must
     * survive validation unchanged, including the separators the operator reads.
     */
    public function testAcceptsTheCauseVocabularyDetailOfAWarningRun(): void
    {
        $detail = 'collection_missing target=WEB01 collection=Firefox-115.0; mac_conflict target=DB02; (+4 weitere)';

        $r = run_report_validate($this->base([
            'event' => 'completed', 'outcome' => 'warning', 'error_category' => 'partial_failure',
            'summary' => ['received' => 6, 'imported' => 1, 'item_failures' => 2, 'data_warnings' => 4],
            'detail' => $detail,
        ]));

        self::assertArrayHasKey('report', $r, 'the warning detail must not be rejected');
        self::assertSame($detail, $r['report']['detail'], 'the cause line must arrive verbatim; the operator reads it');
        self::assertSame('partial_failure', $r['report']['error_category']);
        self::assertSame(4, $r['report']['summary']['data_warnings']);
    }

    /** A detail longer than the column allows is cut, never rejected: the run itself is not the problem. */
    public function testALongCauseListIsTruncatedRatherThanRefused(): void
    {
        $r = run_report_validate($this->base([
            'event' => 'completed', 'outcome' => 'warning', 'error_category' => 'partial_failure',
            'detail' => str_repeat('mac_missing target=VM01; ', 500),
        ]));

        self::assertArrayHasKey('report', $r);
        self::assertSame(VIRTUSPHERE_CLIENT_EVENT_DETAIL_MAX_CHARS, mb_strlen((string) $r['report']['detail']));
    }

    public function testAcceptsSiteCriticalWithStringSummary(): void
    {
        $r = run_report_validate([
            'source' => 'mecm-site-health', 'event' => 'completed', 'run_id' => self::HEX, 'interval_seconds' => 300,
            'outcome' => 'fail', 'error_category' => 'site_critical',
            'summary' => ['site_code' => 'P01', 'provider' => 'srv01', 'raw_status' => 2],
        ]);
        self::assertArrayHasKey('report', $r);
        self::assertSame('site_critical', $r['report']['error_category']);
        self::assertSame('P01', $r['report']['summary']['site_code']);
        self::assertSame(2, $r['report']['summary']['raw_status']);
    }
}
