<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/machine_api.php';

/**
 * K20 (finding A3): the audit throttle of a machine-API refusal is keyed by
 * (category, event, scope). The scope carries the closed refusal cause next to
 * the IP, so the second cause of one IP is not suppressed by the first one for
 * an hour. Without a closed cause the scope stays the bare IP, as before.
 */
final class MachineApiDenialScopeTest extends TestCase
{
    public function testAnAllowlistRefusalIsScopedToTheIpWithTheNotAllowlistedSuffix(): void
    {
        self::assertSame('10.0.0.5|n', machine_api_denial_throttle_scope('10.0.0.5', VIRTUSPHERE_MACHINE_API_DENY_NOT_ALLOWLISTED));
    }

    public function testARoleRefusalIsScopedToTheIpWithTheRoleSuffix(): void
    {
        self::assertSame('10.0.0.5|r', machine_api_denial_throttle_scope('10.0.0.5', VIRTUSPHERE_MACHINE_API_DENY_ROLE));
    }

    public function testWithoutACauseTheScopeStaysTheBareIp(): void
    {
        self::assertSame('10.0.0.5', machine_api_denial_throttle_scope('10.0.0.5', null));
    }

    public function testAnEmptyIpStaysEmptyForEveryCause(): void
    {
        // The audit writer refuses an empty scope, as it did before the cause was added.
        self::assertSame('', machine_api_denial_throttle_scope('', VIRTUSPHERE_MACHINE_API_DENY_NOT_ALLOWLISTED));
        self::assertSame('', machine_api_denial_throttle_scope('', VIRTUSPHERE_MACHINE_API_DENY_ROLE));
    }

    public function testAForeignCauseDoesNotInventASuffix(): void
    {
        self::assertSame('10.0.0.5', machine_api_denial_throttle_scope('10.0.0.5', 'some_other_cause'));
    }

    public function testTheTwoCausesOfOneIpAreTwoScopes(): void
    {
        self::assertNotSame(
            machine_api_denial_throttle_scope('10.0.0.5', VIRTUSPHERE_MACHINE_API_DENY_NOT_ALLOWLISTED),
            machine_api_denial_throttle_scope('10.0.0.5', VIRTUSPHERE_MACHINE_API_DENY_ROLE)
        );
    }

    public function testTheLongestIpv6TextWithTheSuffixFitsTheThrottleScopeColumn(): void
    {
        // 45 characters is the longest textual IPv6 form (with an embedded IPv4 tail).
        $longest = 'ffff:ffff:ffff:ffff:ffff:ffff:255.255.255.255';
        self::assertSame(45, strlen($longest));

        foreach ([VIRTUSPHERE_MACHINE_API_DENY_NOT_ALLOWLISTED, VIRTUSPHERE_MACHINE_API_DENY_ROLE] as $cause) {
            self::assertLessThanOrEqual(
                64,
                strlen(machine_api_denial_throttle_scope($longest, $cause)),
                'deploy_audit_throttle.scope is VARCHAR(64)'
            );
        }
    }
}
