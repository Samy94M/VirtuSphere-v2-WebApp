<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ansible_inventory.php';
require_once dirname(__DIR__, 2) . '/lib/esxi_capabilities.php';

/**
 * Capability facts (ADR-0023 amendment 3). The rule that matters: every fact is
 * tri-state and NULL means "not known", never false. A false license_free
 * derived from silence would promise a write the host cannot perform, which is
 * exactly the failure the facts exist to prevent.
 *
 * Parsing runs against fixture data, so no ESXi host is needed.
 */
final class EsxiCapabilitiesTest extends TestCase
{
    // --- parsing -----------------------------------------------------------

    public function testStandaloneLicensedHost(): void
    {
        $facts = ansible_parse_inventory_capabilities([
            'apiType' => 'HostAgent',
            'productFullName' => 'VMware ESXi',
            'version' => '8.0.3',
            'licenseProductName' => 'VMware ESX Server',
        ], ['runtime.inMaintenanceMode' => false]);

        self::assertSame('HostAgent', $facts['api_type']);
        self::assertSame('VMware ESXi 8.0.3', $facts['product_version']);
        self::assertFalse($facts['license_free']);
        self::assertFalse($facts['in_maintenance']);
        // No dasHostState key at all: we cannot prove the host is standalone.
        self::assertNull($facts['in_ha_cluster']);
    }

    public function testFreeLicenceIsDetectedFromTheProductName(): void
    {
        $facts = ansible_parse_inventory_capabilities(['licenseProductName' => 'VMware vSphere Hypervisor'], []);
        self::assertTrue($facts['license_free']);

        $facts = ansible_parse_inventory_capabilities(['licenseProductName' => 'VMware ESX Server'], []);
        self::assertFalse($facts['license_free']);
    }

    public function testEverythingIsNullWhenTheModulesReportedNothing(): void
    {
        // A failing about_info task leaves the key absent. Nothing may be guessed.
        $facts = ansible_parse_inventory_capabilities([], []);

        foreach (['api_type', 'product_version', 'license_product', 'license_free', 'in_ha_cluster', 'in_maintenance'] as $key) {
            self::assertNull($facts[$key], $key);
        }
    }

    public function testHaClusterMembershipComesFromThePresenceOfDasHostState(): void
    {
        self::assertTrue(ansible_parse_inventory_capabilities([], ['runtime.dasHostState' => 'master'])['in_ha_cluster']);
        // Present but empty: the property was gathered and the host is not in HA.
        self::assertFalse(ansible_parse_inventory_capabilities([], ['runtime.dasHostState' => ''])['in_ha_cluster']);
        self::assertNull(ansible_parse_inventory_capabilities([], [])['in_ha_cluster']);
    }

    public function testProductVersionDoesNotRepeatAVersionTheNameAlreadyCarries(): void
    {
        $facts = ansible_parse_inventory_capabilities(['productFullName' => 'VMware ESXi 8.0.3 build-1', 'version' => '8.0.3'], []);
        self::assertSame('VMware ESXi 8.0.3 build-1', $facts['product_version']);
    }

    public function testTheParserExposesCapabilitiesOnTheInventoryPayload(): void
    {
        $payload = json_encode([
            'datacenters' => ['ha-datacenter'],
            'about' => ['apiType' => 'VirtualCenter', 'licenseProductName' => 'VMware VirtualCenter Server'],
            'host_runtime' => ['runtime.inMaintenanceMode' => true],
        ], JSON_THROW_ON_ERROR);
        $stdout = 'VIRTUSPHERE_INVENTORY_B64_BEGIN' . base64_encode($payload) . 'VIRTUSPHERE_INVENTORY_B64_END';

        $parsed = ansible_parse_inventory_output($stdout);

        self::assertSame('VirtualCenter', $parsed['capabilities']['api_type']);
        self::assertTrue($parsed['capabilities']['in_maintenance']);
    }

    // --- state row -> tri-state --------------------------------------------

    public function testStateRowNullsSurviveAsNull(): void
    {
        $facts = esxi_capabilities(['license_free' => null, 'in_ha_cluster' => null, 'api_type' => null]);

        self::assertNull($facts['license_free']);
        self::assertNull($facts['in_ha_cluster']);
        self::assertNull($facts['api_type']);
    }

    public function testAMissingStateRowIsAllUnknown(): void
    {
        self::assertNull(esxi_capabilities(null)['license_free']);
        self::assertSame([], esxi_capability_warnings(null));
    }

    public function testOnlyKnownAndTrueFactsWarn(): void
    {
        self::assertSame([], esxi_capability_warnings(['license_free' => 0, 'in_ha_cluster' => 0, 'in_maintenance' => 0]));

        $warnings = esxi_capability_warnings(['license_free' => 1, 'in_ha_cluster' => 1, 'in_maintenance' => 1]);
        self::assertSame(
            [
                ['key' => 'license_free', 'level' => 'warning'],
                ['key' => 'in_ha_cluster', 'level' => 'warning'],
                ['key' => 'in_maintenance', 'level' => 'info'],
            ],
            $warnings
        );
    }

    // --- freshness ---------------------------------------------------------

    public function testFactsWithoutASuccessfulPullAreNeverFresh(): void
    {
        self::assertFalse(esxi_capabilities_fresh(null, 6));
        self::assertFalse(esxi_capabilities_fresh(['last_success_at' => null], 6));
    }

    public function testStalenessUsesTheSameWindowAsTheTrafficLight(): void
    {
        $now = 1_800_000_000;
        $withinWindow = gmdate('Y-m-d H:i:s', $now - (VIRTUSPHERE_ESXI_INVENTORY_STALE_FACTOR * 6 * 3600) + 60);
        $pastWindow = gmdate('Y-m-d H:i:s', $now - (VIRTUSPHERE_ESXI_INVENTORY_STALE_FACTOR * 6 * 3600) - 60);

        self::assertTrue(esxi_capabilities_fresh(['last_success_at' => $withinWindow], 6, $now));
        self::assertFalse(esxi_capabilities_fresh(['last_success_at' => $pastWindow], 6, $now));
    }

    public function testIntervalZeroMakesAgeMeaningless(): void
    {
        // Automation deliberately off: age proves nothing, exactly as in the ampel.
        $ancient = gmdate('Y-m-d H:i:s', 1_000_000_000);
        self::assertTrue(esxi_capabilities_fresh(['last_success_at' => $ancient], 0, 1_800_000_000));
    }

    // --- preflight ---------------------------------------------------------

    public function testFreshFreeLicenceBlocks(): void
    {
        $state = ['last_success_at' => gmdate('Y-m-d H:i:s'), 'license_free' => 1];
        $verdict = esxi_autostart_preflight($state, 6);

        self::assertSame('block', $verdict['verdict']);
        self::assertSame('license_free', $verdict['reason']);
    }

    public function testFreshHaClusterSkips(): void
    {
        $state = ['last_success_at' => gmdate('Y-m-d H:i:s'), 'in_ha_cluster' => 1];
        self::assertSame('skip', esxi_autostart_preflight($state, 6)['verdict']);
    }

    public function testALicenceProblemOutranksAnHaCluster(): void
    {
        $state = ['last_success_at' => gmdate('Y-m-d H:i:s'), 'license_free' => 1, 'in_ha_cluster' => 1];
        self::assertSame('block', esxi_autostart_preflight($state, 6)['verdict']);
    }

    public function testStaleFactsNeverBlock(): void
    {
        // Cache-never-blocks (ADR-0023): refusing a job on a month-old assumption
        // would be a guess with consequences. ESXi stays the authority.
        $state = ['last_success_at' => gmdate('Y-m-d H:i:s', time() - 30 * 86400), 'license_free' => 1, 'in_ha_cluster' => 1];
        self::assertSame('ok', esxi_autostart_preflight($state, 6)['verdict']);
    }

    public function testUnknownFactsNeverBlock(): void
    {
        $state = ['last_success_at' => gmdate('Y-m-d H:i:s'), 'license_free' => null, 'in_ha_cluster' => null];
        self::assertSame('ok', esxi_autostart_preflight($state, 6)['verdict']);
        self::assertSame('ok', esxi_autostart_preflight(null, 6)['verdict']);
    }

    // --- rollup ------------------------------------------------------------

    public function testStateRankOrdersDangerAboveWarningAboveUnknown(): void
    {
        self::assertGreaterThan(esxi_state_rank('warning'), esxi_state_rank('danger'));
        self::assertGreaterThan(esxi_state_rank('unknown'), esxi_state_rank('warning'));
        self::assertGreaterThan(esxi_state_rank('ok'), esxi_state_rank('unknown'));
    }

    /**
     * The traffic light is fetch health only. A host whose pull is perfect stays
     * green even when it has a free licence or is in an HA cluster: those are
     * properties of a successful pull, not fetch problems. They surface as their
     * own badges (esxi_capability_warnings) and gate deploys through
     * esxi_autostart_preflight, never by repainting a healthy pull amber.
     */
    public function testACapabilityDoesNotColourAHealthyPull(): void
    {
        $healthy = ['last_attempt_at' => gmdate('Y-m-d H:i:s'), 'last_success_at' => gmdate('Y-m-d H:i:s'), 'last_status' => 'ok'];

        self::assertSame('ok', esxi_credential_state($healthy, 6));
        self::assertSame('ok', esxi_credential_state($healthy + ['license_free' => 1], 6));
        self::assertSame('ok', esxi_credential_state($healthy + ['in_ha_cluster' => 1], 6));
        self::assertSame('ok', esxi_credential_state($healthy + ['in_maintenance' => 1], 6));

        // The capability is still reported, just not through the traffic light.
        self::assertSame(
            [['key' => 'license_free', 'level' => 'warning']],
            esxi_capability_warnings($healthy + ['license_free' => 1])
        );
    }

    public function testAFetchFailureIsDangerRegardlessOfCapabilities(): void
    {
        // A paused credential is danger; a capability fact never lowers it.
        $paused = ['last_attempt_at' => gmdate('Y-m-d H:i:s'), 'paused_until_credential_change' => 1, 'license_free' => 1];

        self::assertSame('danger', esxi_credential_state($paused, 6));
    }

    public function testAnUnknownCredentialStaysUnknownRatherThanWarning(): void
    {
        // Never pulled: no facts, so nothing to warn about.
        self::assertSame('unknown', esxi_credential_state(null, 6));
    }

    public function testTheLogLineNamesUnknownFactsAsUnknown(): void
    {
        $line = esxi_capabilities_log_line(esxi_capabilities(null), false);

        self::assertStringContainsString('free=unknown', $line);
        self::assertStringContainsString('ha_cluster=unknown', $line);
        self::assertStringContainsString('facts=stale-or-missing', $line);
    }
}
