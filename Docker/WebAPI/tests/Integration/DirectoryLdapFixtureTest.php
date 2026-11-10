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

    // --- N1a ------------------------------------------------------------

    /**
     * A2: the controller completes TLS and then never answers. Before N1a only
     * the TCP stack ended this wait. Bind and search must both end as a typed
     * timeout (a transport failure, so the next controller is tried) inside
     * the request budget.
     */
    public function testAControllerThatGoesSilentAfterTlsEndsAtTheAnswerTimeout(): void
    {
        $caFile = directory_ca_file(DirectoryLdapFixture::trustedBundle());
        foreach (['bind', 'search'] as $operation) {
            $deadline = directory_deadline_now() + VIRTUSPHERE_DIRECTORY_TOTAL_TIMEOUT_SECONDS;
            $connection = directory_ldap_connect(DirectoryLdapFixture::HOST_SILENT, DirectoryLdapFixture::PORT, $caFile, $deadline);
            $start = directory_deadline_now();
            try {
                if ($operation === 'bind') {
                    directory_ldap_bind($connection, DirectoryLdapFixture::SERVICE_BIND_DN, DirectoryLdapFixture::SERVICE_BIND_PASSWORD, VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, $deadline);
                } else {
                    directory_ldap_search_users($connection, DirectoryLdapFixture::USER_SEARCH_BASE, '(objectClass=user)', $deadline);
                }
                self::fail($operation . ': expected the silent controller to time out');
            } catch (DirectoryLdapException $exception) {
                $elapsed = directory_deadline_now() - $start;
                self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOME_TIMEOUT, $exception->outcome, $operation);
                self::assertTrue($exception->transportFailure, $operation . ' must fail over like an outage');
                self::assertLessThan(VIRTUSPHERE_DIRECTORY_TOTAL_TIMEOUT_SECONDS + 2, $elapsed, $operation . ' must end inside the request budget');
            } finally {
                @ldap_unbind($connection);
            }
        }
    }

    /**
     * A5: a trusted, correctly named certificate on a listener that speaks at
     * most TLS 1.1 is refused. The positive control proves the stub really
     * negotiates TLS 1.1, so the refusal is the client's floor.
     */
    public function testAControllerOfferingAtMostTls11IsRefused(): void
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT,
            'ciphers' => 'DEFAULT:@SECLEVEL=0',
        ]]);
        $control = @stream_socket_client('tls://' . DirectoryLdapFixture::HOST_TLS11 . ':' . DirectoryLdapFixture::PORT, $errorNumber, $errorText, 5, STREAM_CLIENT_CONNECT, $context);
        self::assertIsResource($control, 'positive control: the legacy stub must complete a TLS 1.1 handshake when the client allows it');
        fclose($control);

        $caFile = directory_ca_file(DirectoryLdapFixture::trustedBundle());
        $connection = directory_ldap_connect(DirectoryLdapFixture::HOST_TLS11, DirectoryLdapFixture::PORT, $caFile, directory_deadline_now() + 5);
        try {
            directory_ldap_bind($connection, DirectoryLdapFixture::SERVICE_BIND_DN, DirectoryLdapFixture::SERVICE_BIND_PASSWORD, VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, directory_deadline_now() + 5);
            self::fail('expected the TLS 1.1 listener to be refused');
        } catch (DirectoryLdapException $exception) {
            self::assertTrue($exception->transportFailure);
            self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE, $exception->outcome, 'libldap refuses the handshake (errno -1), it does not wait');
        } finally {
            @ldap_unbind($connection);
        }

        try {
            directory_tls_probe(DirectoryLdapFixture::HOST_TLS11, DirectoryLdapFixture::PORT, $caFile, directory_deadline_now() + 5);
            self::fail('the manual test probe must refuse TLS 1.1 as well');
        } catch (DirectoryLdapException $exception) {
            self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED, $exception->outcome);
        }
    }

    /**
     * A certificate without the server-authentication EKU: the manual test
     * (directory_tls_probe) says tls_failed; the libldap sign-in path refuses
     * the handshake through OpenSSL's ssl_server purpose check (errno -1).
     */
    public function testACertificateWithoutServerAuthenticationEkuIsRefusedOnBothPaths(): void
    {
        $caFile = directory_ca_file(DirectoryLdapFixture::trustedBundle());
        try {
            directory_tls_probe(DirectoryLdapFixture::HOST_NO_EKU, DirectoryLdapFixture::PORT, $caFile, directory_deadline_now() + 5);
            self::fail('expected the probe to refuse a clientAuth-only certificate');
        } catch (DirectoryLdapException $exception) {
            self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED, $exception->outcome);
        }

        $connection = directory_ldap_connect(DirectoryLdapFixture::HOST_NO_EKU, DirectoryLdapFixture::PORT, $caFile, directory_deadline_now() + 5);
        try {
            directory_ldap_bind($connection, DirectoryLdapFixture::SERVICE_BIND_DN, DirectoryLdapFixture::SERVICE_BIND_PASSWORD, VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, directory_deadline_now() + 5);
            self::fail('expected the libldap path to refuse a clientAuth-only certificate');
        } catch (DirectoryLdapException $exception) {
            self::assertTrue($exception->transportFailure);
            self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE, $exception->outcome, 'refused in the handshake, not left waiting');
        } finally {
            @ldap_unbind($connection);
        }
    }

    /** @return array<string,array{0:string}> */
    public static function filterMetacharacterUpns(): array
    {
        return [
            'wildcard alone' => ['*@vs-ldap.test'],
            'wildcard prefix' => ['a*@vs-ldap.test'],
            'wildcard everything' => ['*'],
            'filter break-out' => ['alice@vs-ldap.test)(cn=*'],
            'opening parenthesis' => ['(alice@vs-ldap.test'],
            'escaped backslash' => ['alice\\@vs-ldap.test'],
            'hex escape attempt' => ['\\2a@vs-ldap.test'],
            'umlaut look-alike' => ["\u{00e4}lice@vs-ldap.test"],
        ];
    }

    /** A11: filter metacharacters and Unicode never match another account. */
    #[DataProvider('filterMetacharacterUpns')]
    public function testFilterMetacharactersInTheUpnFindNoForeignAccount(string $upn): void
    {
        $connection = $this->serviceConnection();
        try {
            directory_ldap_find_user_by_upn($connection, DirectoryLdapFixture::USER_SEARCH_BASE, $upn, directory_deadline_now() + 5);
            self::fail('expected not_found for ' . $upn);
        } catch (DirectoryLdapException $exception) {
            self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_FOUND, $exception->outcome, $upn);
        } finally {
            @ldap_unbind($connection);
        }
    }

    public function testANonAsciiUpnIsFoundByItsExactValue(): void
    {
        $connection = $this->serviceConnection();
        try {
            $entry = directory_ldap_find_user_by_upn($connection, DirectoryLdapFixture::USER_SEARCH_BASE, DirectoryLdapFixture::JOERG_UPN, directory_deadline_now() + 5);
        } finally {
            @ldap_unbind($connection);
        }
        self::assertSame(DirectoryLdapFixture::JOERG_UPN, $entry['upn']);
        self::assertSame(DirectoryLdapFixture::JOERG_GUID_HEX, bin2hex($entry['guid_bytes']));
    }

    public function testTwoObjectsWithOneUpnAreAmbiguous(): void
    {
        $connection = $this->serviceConnection();
        try {
            directory_ldap_find_user_by_upn($connection, DirectoryLdapFixture::USER_SEARCH_BASE, DirectoryLdapFixture::TWIN_UPN, directory_deadline_now() + 5);
            self::fail('expected ambiguous');
        } catch (DirectoryLdapException $exception) {
            self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOME_AMBIGUOUS, $exception->outcome);
        } finally {
            @ldap_unbind($connection);
        }
    }

    /** A3 on the real path: an account whose userAccountControl is not readable. */
    public function testAnAccountWithoutReadableAccountControlIsAnInvalidResponse(): void
    {
        $connection = $this->serviceConnection();
        try {
            directory_ldap_find_user_by_upn($connection, DirectoryLdapFixture::USER_SEARCH_BASE, DirectoryLdapFixture::NOUAC_UPN, directory_deadline_now() + 5);
            self::fail('expected invalid_response');
        } catch (DirectoryLdapException $exception) {
            self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE, $exception->outcome);
        } finally {
            @ldap_unbind($connection);
        }
    }

    /**
     * A5/A6 in the shipped runtime image: PHP 8.4, no LDAP_OPT_X_TLS_NEWCTX
     * (ext-ldap renews the TLS context itself, php-src PR 17940), the TLS
     * floor constants exist, and libldap is linked against OpenSSL (GnuTLS
     * ignores TLS_PROTOCOL_MIN).
     */
    public function testTheRuntimeImageHasTheLdapTlsBuildThisAdapterRequires(): void
    {
        self::assertGreaterThanOrEqual(80400, PHP_VERSION_ID);
        self::assertFalse(defined('LDAP_OPT_X_TLS_NEWCTX'));
        self::assertTrue(defined('LDAP_OPT_X_TLS_PROTOCOL_MIN') && defined('LDAP_OPT_X_TLS_PROTOCOL_TLS1_2'));
        // The stubs declare the constant, so only the runtime image can prove
        // it: constant() throws an Error when ext-ldap cannot name its TLS
        // library.
        $packageOption = 'LDAP_OPT_X_TLS_PACKAGE';
        $connection = ldap_connect('ldaps://' . DirectoryLdapFixture::HOST_DC1 . ':' . DirectoryLdapFixture::PORT);
        self::assertInstanceOf(LDAP\Connection::class, $connection);
        $package = null;
        self::assertTrue(ldap_get_option($connection, (int) constant($packageOption), $package));
        self::assertSame('OpenSSL', $package);
    }

    /** @return mixed LDAP\Connection bound as the fixture search account on dc1 */
    private function serviceConnection(): mixed
    {
        $caFile = directory_ca_file(DirectoryLdapFixture::trustedBundle());
        $connection = directory_ldap_connect(DirectoryLdapFixture::HOST_DC1, DirectoryLdapFixture::PORT, $caFile, directory_deadline_now() + 5);
        directory_ldap_bind($connection, DirectoryLdapFixture::SERVICE_BIND_DN, DirectoryLdapFixture::SERVICE_BIND_PASSWORD, VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, directory_deadline_now() + 5);

        return $connection;
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
