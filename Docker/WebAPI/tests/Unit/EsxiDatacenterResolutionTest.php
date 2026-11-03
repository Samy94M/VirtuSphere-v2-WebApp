<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/esxi_datacenter_resolution.php';
require_once dirname(__DIR__, 2) . '/lib/esxi_datacenter_presenter.php';

final class EsxiDatacenterResolutionTest extends TestCase
{
    public function testFreshnessBoundaryIsInclusiveAndObservationIsPreserved(): void
    {
        $now = strtotime('2026-08-31 12:00:00 UTC');
        $confirmed = gmdate('Y-m-d H:i:s', $now - VIRTUSPHERE_ESXI_DATACENTER_DERIVATION_MAX_AGE_SECONDS);
        $observation = ['attempted_at' => '2026-08-31 11:00:00', 'outcome' => 'failed', 'reason_code' => 'transport', 'job_id' => 41];
        $resolved = esxi_datacenter_resolution([['name' => 'DC-Ä', 'meta_json' => ['source_count' => 1]]], $confirmed, 2, $observation, $now);

        self::assertSame('resolved', $resolved['resolution']);
        self::assertSame('DC-Ä', $resolved['name']);
        self::assertSame(0, $resolved['remaining_seconds']);
        self::assertSame(41, $resolved['observation']['job_id']);

        $expired = esxi_datacenter_resolution([['name' => 'DC-Ä']], gmdate('Y-m-d H:i:s', $now - VIRTUSPHERE_ESXI_DATACENTER_DERIVATION_MAX_AGE_SECONDS - 1), 2, [], $now);
        self::assertSame('expired', $expired['resolution']);
    }

    public function testSemanticsZeroMatchUnsupportedAndSourceAmbiguityStayDistinct(): void
    {
        $now = strtotime('2026-08-31 12:00:00 UTC');
        $fresh = gmdate('Y-m-d H:i:s', $now);
        self::assertSame('semantics_unverified', esxi_datacenter_resolution([['name' => 'DC1']], $fresh, 1, [], $now)['resolution']);
        self::assertSame('answered_empty', esxi_datacenter_resolution([], $fresh, 2, ['outcome' => 'answered', 'raw_item_count' => 0], $now)['resolution']);
        self::assertSame('answered_empty', esxi_datacenter_resolution([], $fresh, 2, ['outcome' => 'failed', 'raw_item_count' => 7], $now)['resolution']);
        self::assertSame('unsupported', esxi_datacenter_resolution([['name' => " DC1"]], $fresh, 2, ['outcome' => 'answered', 'raw_item_count' => 1], $now)['resolution']);
        self::assertSame('unsupported', esxi_datacenter_resolution([['name' => 'DC1'], ['name' => 'DC2 ']], $fresh, 2, ['outcome' => 'answered', 'raw_item_count' => 2], $now)['resolution']);
        self::assertSame('ambiguous', esxi_datacenter_resolution([['name' => 'DC1', 'meta_json' => ['source_count' => 2]]], $fresh, 2, [], $now)['resolution']);
        self::assertSame('ambiguous', esxi_datacenter_resolution([['name' => 'DC1'], ['name' => 'DC2']], $fresh, 2, [], $now)['resolution']);
    }

    public function testPresenterReasonRegistryFallsClosedForUnknownValues(): void
    {
        self::assertSame('expired', esxi_datacenter_resolution_reason(['resolution' => 'expired']));
        self::assertSame('never_confirmed', esxi_datacenter_resolution_reason(['resolution' => 'future_value']));
        self::assertContains('resolved', VIRTUSPHERE_ESXI_DATACENTER_RESOLUTIONS);
    }
}
