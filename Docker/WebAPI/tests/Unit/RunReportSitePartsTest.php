<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/run_report.php';

/**
 * Wire validation of the optional `site_parts` object on a completed
 * mecm-site-health report (ADR-0018 amendment 2026-11-09). It is a top-level
 * field so an older portal ignores it; this portal checks its shape strictly,
 * rejects it from any other source and stores it inside the summary.
 * Synthetic values only.
 */
final class RunReportSitePartsTest extends TestCase
{
    private const HEX = '00112233445566778899aabbccddeeff';

    /** @param array<string,mixed> $extra */
    private function site(array $extra = []): array
    {
        return array_merge([
            'source' => 'mecm-site-health', 'event' => 'completed', 'run_id' => self::HEX, 'interval_seconds' => 300,
            'outcome' => 'fail', 'error_category' => 'site_critical',
            'summary' => ['site_code' => 'P01', 'raw_status' => 2],
        ], $extra);
    }

    /** @return array<string,mixed> */
    private function parts(): array
    {
        return [
            'roles' => [
                ['name' => 'SMS Distribution Point', 'status' => 2, 'count' => 4],
                ['name' => 'SMS Distribution Point', 'status' => 1, 'count' => 1],
            ],
            'components' => [
                ['name' => 'SMS_DISTRIBUTION_MANAGER', 'status' => 2, 'count' => 1],
            ],
            'omitted' => 0,
        ];
    }

    public function testValidPartsAreStoredInsideTheSummary(): void
    {
        $r = run_report_validate($this->site(['site_parts' => $this->parts()]));
        self::assertArrayHasKey('report', $r);
        $summary = $r['report']['summary'];
        self::assertSame('P01', $summary['site_code']);
        self::assertSame($this->parts(), $summary[VIRTUSPHERE_SITE_PARTS_FIELD]);
        self::assertArrayNotHasKey('site_parts', $r['report'], 'only the summary carries it to storage');
    }

    public function testPartsWithoutSummaryCreateOne(): void
    {
        $data = $this->site(['site_parts' => ['roles' => [['name' => 'SMS Provider', 'status' => 2, 'count' => 1]]]]);
        unset($data['summary']);
        $r = run_report_validate($data);
        self::assertSame(
            ['roles' => [['name' => 'SMS Provider', 'status' => 2, 'count' => 1]], 'components' => [], 'omitted' => 0],
            $r['report']['summary'][VIRTUSPHERE_SITE_PARTS_FIELD]
        );
    }

    public function testAReportWithoutPartsStaysValid(): void
    {
        $r = run_report_validate($this->site());
        self::assertArrayNotHasKey(VIRTUSPHERE_SITE_PARTS_FIELD, $r['report']['summary']);
        $r = run_report_validate($this->site(['site_parts' => null]));
        self::assertArrayNotHasKey(VIRTUSPHERE_SITE_PARTS_FIELD, $r['report']['summary']);
    }

    public function testTheSummaryKeyItselfStaysClosed(): void
    {
        $r = run_report_validate($this->site(['summary' => ['site_code' => 'P01', 'site_parts' => $this->parts()]]));
        self::assertSame('Invalid summary', $r['error']);
    }

    public function testASyncSourceMayNotSendParts(): void
    {
        $r = run_report_validate([
            'source' => 'device-sync', 'event' => 'completed', 'run_id' => self::HEX, 'interval_seconds' => 300,
            'outcome' => 'fail', 'error_category' => 'mecm_unavailable', 'site_parts' => $this->parts(),
        ]);
        self::assertSame('Invalid site_parts', $r['error']);
        self::assertSame(400, $r['status']);
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function invalidParts(): iterable
    {
        $entry = ['name' => 'SMS Provider', 'status' => 2, 'count' => 1];
        yield 'a list instead of an object' => [[$entry]];
        yield 'a string' => ['roles'];
        yield 'unknown top-level key' => [['roles' => [$entry], 'servers' => []]];
        yield 'roles is an object' => [['roles' => ['a' => $entry]]];
        yield 'entry with extra key' => [['roles' => [$entry + ['server' => 'x']]]];
        yield 'entry missing count' => [['roles' => [['name' => 'SMS Provider', 'status' => 2]]]];
        yield 'FQDN as name' => [['roles' => [['name' => 'cm01.example.test', 'status' => 2, 'count' => 1]]]];
        yield 'path as name' => [['roles' => [['name' => 'SERVER\\share', 'status' => 2, 'count' => 1]]]];
        yield 'name with trailing newline' => [['roles' => [['name' => "SMS Provider\n", 'status' => 2, 'count' => 1]]]];
        yield 'name too long' => [['roles' => [['name' => str_repeat('A', 65), 'status' => 2, 'count' => 1]]]];
        yield 'status 0' => [['roles' => [['name' => 'SMS Provider', 'status' => 0, 'count' => 1]]]];
        yield 'status 3' => [['roles' => [['name' => 'SMS Provider', 'status' => 3, 'count' => 1]]]];
        yield 'status as string' => [['roles' => [['name' => 'SMS Provider', 'status' => '2', 'count' => 1]]]];
        yield 'count 0' => [['roles' => [['name' => 'SMS Provider', 'status' => 2, 'count' => 0]]]];
        yield 'count above the bound' => [['roles' => [['name' => 'SMS Provider', 'status' => 2, 'count' => VIRTUSPHERE_RUN_SUMMARY_VALUE_MAX + 1]]]];
        yield 'repeated name and status' => [['roles' => [$entry, $entry]]];
        yield 'negative omitted' => [['omitted' => -1]];
        yield 'omitted as string' => [['omitted' => '0']];
        yield 'too many entries' => [['components' => array_map(
            static fn (int $i): array => ['name' => sprintf('SMS_C_%02d', $i), 'status' => 1, 'count' => 1],
            range(1, VIRTUSPHERE_SITE_PART_MAX_ENTRIES + 1)
        )]];
    }

    #[DataProvider('invalidParts')]
    public function testRejectsAMalformedPartsObject(mixed $parts): void
    {
        $r = run_report_validate($this->site(['site_parts' => $parts]));
        self::assertSame('Invalid site_parts', $r['error'] ?? null);
        self::assertSame(400, $r['status']);
    }

    public function testExactlyTheEntryCapIsAccepted(): void
    {
        $components = array_map(
            static fn (int $i): array => ['name' => sprintf('SMS_C_%02d', $i), 'status' => 1, 'count' => 1],
            range(1, VIRTUSPHERE_SITE_PART_MAX_ENTRIES)
        );
        $r = run_report_validate($this->site(['site_parts' => ['components' => $components, 'omitted' => 3]]));
        self::assertArrayHasKey('report', $r);
        self::assertSame(3, $r['report']['summary'][VIRTUSPHERE_SITE_PARTS_FIELD]['omitted']);
    }

    public function testTheValidatedPartsFitTheStoredReadBack(): void
    {
        $r = run_report_validate($this->site(['site_parts' => $this->parts()]));
        $row = [
            'last_status' => 'fail', 'last_error_category' => 'site_critical',
            'last_summary' => json_encode($r['report']['summary'], JSON_THROW_ON_ERROR),
        ];
        self::assertSame($this->parts(), mecm_site_stored_parts($row));
    }
}
