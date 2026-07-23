<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The two host lines the ESXi card and its inventory detail render, pinned at
 * the value level.
 *
 * Both read facts the pull has collected since ADR-0023 but nothing displayed,
 * so they had no test and no rendered proof. The values themselves come from
 * vSphere and are best-effort field paths that ADR-0023 says are verified
 * against the productive host on rollout: this test pins what the portal does
 * with the documented values, so a real host that reports something else fails
 * visibly here (or shows nothing) instead of quietly rendering a wrong endpoint
 * kind. `about.apiType` is `HostAgent` on a standalone ESXi host and
 * `VirtualCenter` through vCenter; anything else must not be guessed at.
 */
final class SystemStatusHostFactsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/layout.php';
        require_once dirname(__DIR__, 2) . '/lib/system_status_esxi_panels.php';
    }

    /** @return array<string, mixed> */
    private function state(?string $apiType, ?string $productVersion): array
    {
        return [
            'api_type' => $apiType,
            'product_version' => $productVersion,
            'license_product' => null,
            'license_free' => null,
            'in_ha_cluster' => null,
            'in_maintenance' => null,
        ];
    }

    public function testTheEndpointKindIsNamedForBothDocumentedApiTypes(): void
    {
        self::assertSame(
            __t('system_status.cap_api_vcenter') . ' · VMware ESXi 8.0.2',
            system_status_capability_facts($this->state('VirtualCenter', 'VMware ESXi 8.0.2'))
        );
        self::assertSame(
            __t('system_status.cap_api_host') . ' · VMware ESXi 8.0.2',
            system_status_capability_facts($this->state('HostAgent', 'VMware ESXi 8.0.2'))
        );
    }

    public function testTheApiTypeIsMatchedCaseInsensitively(): void
    {
        // vSphere spells it `VirtualCenter`; a differently-cased build must not
        // silently lose the line.
        self::assertStringContainsString(
            __t('system_status.cap_api_vcenter'),
            system_status_capability_facts($this->state('virtualcenter', null))
        );
    }

    public function testAnUnknownOrAbsentApiTypeIsNotGuessedAt(): void
    {
        // Neither documented value: name the version, claim no endpoint kind.
        self::assertSame('VMware ESXi 8.0.2', system_status_capability_facts($this->state('Frobnicator', 'VMware ESXi 8.0.2')));
        self::assertSame('VMware ESXi 8.0.2', system_status_capability_facts($this->state(null, 'VMware ESXi 8.0.2')));
    }

    public function testAPullThatReportedNothingRendersNoLineAtAll(): void
    {
        // A dash would state a fact; an absent line states nothing, which is
        // what "not known" has to look like (ADR-0023 tri-state contract).
        self::assertSame('', system_status_capability_facts($this->state(null, null)));
        self::assertSame('', system_status_capability_facts(null));
    }

    public function testTheCoreCountIsShownAndZeroIsNotAFact(): void
    {
        self::assertStringContainsString('48 ' . __t('system_status.inv_cores'), system_status_host_facts([
            'meta_json' => json_encode(['cpu_cores' => 48]),
        ]));
        self::assertSame('', system_status_host_facts(['meta_json' => json_encode(['cpu_cores' => 0])]));
        self::assertSame('', system_status_host_facts(['meta_json' => json_encode(['ram_mb' => 262144])]));
    }

    public function testAClockSkewWarnsOnlyOnceItMatters(): void
    {
        $atThreshold = system_status_host_facts([
            'meta_json' => json_encode(['clock_skew_seconds' => VIRTUSPHERE_ESXI_CLOCK_SKEW_WARN_SECONDS]),
        ]);
        self::assertStringContainsString('badge-warning', $atThreshold);
        self::assertStringNotContainsString(':minutes', $atThreshold);

        // A host that is behind is as broken as one that is ahead.
        self::assertStringContainsString('badge-warning', system_status_host_facts([
            'meta_json' => json_encode(['clock_skew_seconds' => -VIRTUSPHERE_ESXI_CLOCK_SKEW_WARN_SECONDS]),
        ]));

        // Below the threshold a few seconds of drift are normal, and a warning
        // nobody can act on is noise that hides the one they can.
        self::assertSame('', system_status_host_facts([
            'meta_json' => json_encode(['clock_skew_seconds' => VIRTUSPHERE_ESXI_CLOCK_SKEW_WARN_SECONDS - 1]),
        ]));
    }

    public function testAHostRowWithoutUsableMetaIsSilent(): void
    {
        // An older cache row, a pull whose fact task failed, or a column that
        // holds something that is not an object: none of them may fatal or
        // invent a number.
        foreach ([null, '', 'not json', '"a string"', '[1,2,3]', '{}'] as $meta) {
            self::assertSame('', system_status_host_facts(['meta_json' => $meta]), var_export($meta, true));
        }
        self::assertSame('', system_status_host_facts([]));
    }
}
