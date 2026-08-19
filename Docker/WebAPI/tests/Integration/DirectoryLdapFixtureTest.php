<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/directory_ldap.php';
require_once dirname(__DIR__) . '/Support/DirectoryLdapFixture.php';

/**
 * Hermetic LDAP-TLS fixture proof (Plan section 18.3): the native PHP/
 * ext-ldap adapter (lib/directory_ldap.php) against real slapd/TLS
 * listeners, not a PHP-level mock. No database needed here; every scenario
 * below calls the connect/bind/search primitives directly with an explicit
 * host, port and trust bundle. Docker/qa/docker-compose.qa.yml publishes the
 * fixture hostnames only inside the QA project network, so this suite skips
 * when they don't resolve (a plain dev-container `composer test`) and never
 * skips inside the Integration lane, where check.ps1 keeps the fixture
 * always up (`--fail-on-skipped`).
 */
final class DirectoryLdapFixtureTest extends TestCase
{
    protected function setUp(): void
    {
        if (!DirectoryLdapFixture::available()) {
            self::markTestSkipped('LDAP fixture hostnames not resolvable (not running inside the QA compose network).');
        }
    }

    public function testTrustedCertificateReachesARealBindAndSearch(): void
    {
        $caFile = directory_ca_file(DirectoryLdapFixture::trustedBundle());
        $connection = directory_ldap_connect(DirectoryLdapFixture::HOST_DC1, DirectoryLdapFixture::PORT, $caFile, directory_deadline_now() + 5);
        directory_ldap_bind($connection, DirectoryLdapFixture::SERVICE_BIND_DN, DirectoryLdapFixture::SERVICE_BIND_PASSWORD, VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, directory_deadline_now() + 5);

        $entry = directory_ldap_find_user_by_upn($connection, DirectoryLdapFixture::USER_SEARCH_BASE, DirectoryLdapFixture::ALICE_UPN, directory_deadline_now() + 5);
        @ldap_unbind($connection);

        self::assertSame(DirectoryLdapFixture::aliceGuidBytes(), $entry['guid_bytes'], 'objectGUID must round-trip as exact 16 raw bytes.');
        self::assertSame(DirectoryLdapFixture::ALICE_DN, $entry['dn']);
        self::assertTrue($entry['enabled']);
    }

    public function testSecondControllerCarriesTheSameIdentity(): void
    {
        $caFile = directory_ca_file(DirectoryLdapFixture::trustedBundle());
        $connection = directory_ldap_connect(DirectoryLdapFixture::HOST_DC2, DirectoryLdapFixture::PORT, $caFile, directory_deadline_now() + 5);
        directory_ldap_bind($connection, DirectoryLdapFixture::SERVICE_BIND_DN, DirectoryLdapFixture::SERVICE_BIND_PASSWORD, VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, directory_deadline_now() + 5);
        $entry = directory_ldap_find_user_by_guid($connection, DirectoryLdapFixture::USER_SEARCH_BASE, DirectoryLdapFixture::aliceGuidBytes(), directory_deadline_now() + 5);
        @ldap_unbind($connection);

        self::assertSame(DirectoryLdapFixture::ALICE_UPN, $entry['upn'], 'dc2 mirrors the same AD replica as dc1.');
    }

    public function testUserBindWithTheCorrectPasswordSucceeds(): void
    {
        $caFile = directory_ca_file(DirectoryLdapFixture::trustedBundle());
        $connection = directory_ldap_connect(DirectoryLdapFixture::HOST_DC1, DirectoryLdapFixture::PORT, $caFile, directory_deadline_now() + 5);
        directory_ldap_bind($connection, DirectoryLdapFixture::ALICE_DN, DirectoryLdapFixture::ALICE_PASSWORD, VIRTUSPHERE_DIRECTORY_OUTCOME_USER_BIND_REJECTED, directory_deadline_now() + 5);
        self::assertSame(0, ldap_errno($connection), 'A successful bind must leave no LDAP error on the connection.');
        @ldap_unbind($connection);
    }

    public function testUserBindWithTheWrongPasswordIsAnAuthoritativeRejectionNotATransportFailure(): void
    {
        $caFile = directory_ca_file(DirectoryLdapFixture::trustedBundle());
        $connection = directory_ldap_connect(DirectoryLdapFixture::HOST_DC1, DirectoryLdapFixture::PORT, $caFile, directory_deadline_now() + 5);
        try {
            directory_ldap_bind($connection, DirectoryLdapFixture::ALICE_DN, 'not-the-real-password', VIRTUSPHERE_DIRECTORY_OUTCOME_USER_BIND_REJECTED, directory_deadline_now() + 5);
            self::fail('Expected a rejected user bind.');
        } catch (DirectoryLdapException $exception) {
            self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOME_USER_BIND_REJECTED, $exception->outcome);
            self::assertFalse($exception->transportFailure, 'A wrong password is authoritative; it must never look like a controller outage.');
        } finally {
            @ldap_unbind($connection);
        }
    }

    /** @return array<string,array{0:string}> */
    public static function brokenCertificateHosts(): array
    {
        return [
            'unknown CA (untrusted issuer, correct name, valid dates)' => [DirectoryLdapFixture::HOST_UNKNOWN_CA],
            'expired (trusted issuer, correct name, notAfter in the past)' => [DirectoryLdapFixture::HOST_EXPIRED],
            'wrong name (trusted issuer, valid dates, SAN mismatch)' => [DirectoryLdapFixture::HOST_WRONGNAME],
        ];
    }

    #[DataProvider('brokenCertificateHosts')]
    public function testEachBrokenCertificatePropertyIsRejectedAsATransportFailure(string $host): void
    {
        $caFile = directory_ca_file(DirectoryLdapFixture::trustedBundle());
        $connection = directory_ldap_connect($host, DirectoryLdapFixture::PORT, $caFile, directory_deadline_now() + 5);
        try {
            // The real service-account password on purpose: proves the
            // rejection happens during the TLS handshake, before any
            // credential is ever evaluated, not because the password is
            // wrong.
            directory_ldap_bind($connection, DirectoryLdapFixture::SERVICE_BIND_DN, DirectoryLdapFixture::SERVICE_BIND_PASSWORD, VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, directory_deadline_now() + 5);
            self::fail("Expected $host to fail TLS validation.");
        } catch (DirectoryLdapException $exception) {
            self::assertTrue($exception->transportFailure, "$host must fail over like any other unreachable controller, never look like a credential problem.");
            self::assertNotSame(VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, $exception->outcome);
        } finally {
            @ldap_unbind($connection);
        }
    }

    public function testAConnectionThatAcceptsButNeverAnswersHitsTheNetworkTimeoutDeadline(): void
    {
        $caFile = directory_ca_file(DirectoryLdapFixture::trustedBundle());
        $connection = directory_ldap_connect(DirectoryLdapFixture::HOST_BLACKHOLE, DirectoryLdapFixture::PORT, $caFile, directory_deadline_now() + 5);
        $start = directory_deadline_now();
        try {
            directory_ldap_bind($connection, DirectoryLdapFixture::SERVICE_BIND_DN, DirectoryLdapFixture::SERVICE_BIND_PASSWORD, VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, directory_deadline_now() + 5);
            self::fail('Expected the blackhole listener to time out.');
        } catch (DirectoryLdapException $exception) {
            $elapsed = directory_deadline_now() - $start;
            self::assertTrue($exception->transportFailure);
            self::assertLessThan(
                VIRTUSPHERE_DIRECTORY_NETWORK_TIMEOUT_SECONDS + 2,
                $elapsed,
                'A connection that never answers must fail at the configured network timeout, not hang indefinitely.'
            );
        } finally {
            @ldap_unbind($connection);
        }
    }

    public function testReferralsAreOffSoAnOutOfBandContinuationIsNeverChased(): void
    {
        // seed/base.ldif plants a subordinate referral to referral-target.invalid
        // (an unresolvable host) inside the user search subtree. If
        // LDAP_OPT_REFERRALS were not 0, chasing it would either hang for a DNS
        // timeout or error the whole search; instead the search must return
        // exactly alice, quickly.
        $caFile = directory_ca_file(DirectoryLdapFixture::trustedBundle());
        $connection = directory_ldap_connect(DirectoryLdapFixture::HOST_DC1, DirectoryLdapFixture::PORT, $caFile, directory_deadline_now() + 5);
        directory_ldap_bind($connection, DirectoryLdapFixture::SERVICE_BIND_DN, DirectoryLdapFixture::SERVICE_BIND_PASSWORD, VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, directory_deadline_now() + 5);

        $start = directory_deadline_now();
        $result = directory_ldap_search_users($connection, DirectoryLdapFixture::USER_SEARCH_BASE, '(userPrincipalName=' . DirectoryLdapFixture::ALICE_UPN . ')', directory_deadline_now() + 5);
        $elapsed = directory_deadline_now() - $start;
        @ldap_unbind($connection);

        self::assertCount(1, $result['rows']);
        self::assertFalse($result['truncated']);
        self::assertLessThan(2.0, $elapsed, 'A chased referral to an unresolvable host would take far longer than a local search.');
    }

    public function testCaRotationTrustsOldAndNewOverlappingThenCutsOverInTheSameProcess(): void
    {
        // Mirrors the documented rotation procedure (plan section 9.3): trust
        // old+new together, prove both, then cut over to new-only. All three
        // bundles are exercised as fresh connections inside this one PHP
        // process/request, the same way a long-lived FPM worker would see
        // them across unrelated requests without ever restarting.
        $old = DirectoryLdapFixture::HOST_DC1;
        $new = DirectoryLdapFixture::HOST_DC_ROTATED;

        $this->assertBindOutcome($old, DirectoryLdapFixture::bundle('root-a'), true);
        $this->assertBindOutcome($new, DirectoryLdapFixture::bundle('root-a'), false);

        $this->assertBindOutcome($old, DirectoryLdapFixture::bundle('root-a', 'root-c'), true);
        $this->assertBindOutcome($new, DirectoryLdapFixture::bundle('root-a', 'root-c'), true);

        $this->assertBindOutcome($old, DirectoryLdapFixture::bundle('root-c'), false);
        $this->assertBindOutcome($new, DirectoryLdapFixture::bundle('root-c'), true);
    }

    private function assertBindOutcome(string $host, string $caBundle, bool $expectSuccess): void
    {
        $caFile = directory_ca_file($caBundle);
        $connection = directory_ldap_connect($host, DirectoryLdapFixture::PORT, $caFile, directory_deadline_now() + 5);
        try {
            directory_ldap_bind($connection, DirectoryLdapFixture::SERVICE_BIND_DN, DirectoryLdapFixture::SERVICE_BIND_PASSWORD, VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, directory_deadline_now() + 5);
            self::assertTrue($expectSuccess, "$host: expected bind to fail with this bundle.");
        } catch (DirectoryLdapException $exception) {
            self::assertFalse($expectSuccess, "$host: expected bind to succeed with this bundle, got {$exception->outcome}.");
        } finally {
            @ldap_unbind($connection);
        }
    }
}
