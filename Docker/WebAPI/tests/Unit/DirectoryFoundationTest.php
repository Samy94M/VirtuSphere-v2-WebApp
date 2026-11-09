<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/directory_identity.php';
require_once dirname(__DIR__, 2) . '/lib/directory_result.php';
require_once dirname(__DIR__, 2) . '/lib/directory_tls.php';
require_once dirname(__DIR__, 2) . '/lib/directory_service.php';
require_once dirname(__DIR__, 2) . '/lib/repo/directory.php';
require_once dirname(__DIR__, 2) . '/lib/users_page.php';
require_once dirname(__DIR__, 2) . '/lib/auth_directory_login.php';
require_once dirname(__DIR__, 2) . '/lib/auth_password_rehash.php';

final class DirectoryFoundationTest extends TestCase
{
    public function testAuthenticationSourceNeverFallsBackToLocal(): void
    {
        self::assertTrue(directory_auth_source_is_valid(VIRTUSPHERE_AUTH_SOURCE_LOCAL));
        self::assertTrue(directory_auth_source_is_valid(VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY));
        self::assertFalse(directory_auth_source_is_valid('ldap'));

        $this->expectException(InvalidArgumentException::class);
        directory_auth_source_require('ldap');
    }

    public function testDirectoryOutcomesAreUniqueAndBindOriginsStayDistinct(): void
    {
        self::assertNotSame([], VIRTUSPHERE_DIRECTORY_OUTCOMES);
        self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOMES, array_values(array_unique(VIRTUSPHERE_DIRECTORY_OUTCOMES)));
        self::assertNotSame(
            VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED,
            VIRTUSPHERE_DIRECTORY_OUTCOME_USER_BIND_REJECTED
        );
        self::assertSame(
            VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED,
            (new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED))->getMessage()
        );
    }

    public function testUnknownDirectoryOutcomeCannotEnterLogsOrState(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DirectoryLdapException('raw ldap diagnostic');
    }

    public function testControllerHostRequiresAnAsciiFqdn(): void
    {
        self::assertTrue(directory_host_is_valid('dc01.example.test'));
        foreach (['', 'dc01', 'dc01.example.test.', '127.0.0.1', '::1', '-dc.example.test', 'dc_.example.test', 'dömäne.example'] as $invalid) {
            self::assertFalse(directory_host_is_valid($invalid), $invalid);
        }
    }

    public function testUpnBoundsPreserveUnicodeAndRejectAmbiguousShapes(): void
    {
        self::assertTrue(directory_upn_is_valid('samý.user@example.test'));
        self::assertTrue(directory_upn_is_valid(str_repeat('a', 243) . '@example.com'));
        foreach (['', 'user', '@example.test', 'user@', 'user name@example.test', "user\0@example.test", str_repeat('a', 244) . '@example.com'] as $invalid) {
            self::assertFalse(directory_upn_is_valid($invalid));
        }
    }

    public function testObjectGuidDisplayUsesActiveDirectoryByteOrder(): void
    {
        $bytes = hex2bin('33221100554477668899aabbccddeeff');
        self::assertIsString($bytes);
        self::assertSame('00112233-4455-6677-8899-aabbccddeeff', directory_guid_display($bytes));
        self::assertSame('', directory_guid_display('short'));
    }

    public function testGuidFilterEscapesBinaryFilterMetacharacters(): void
    {
        self::assertTrue(function_exists('ldap_escape'), 'The shipped PHP image must contain ext-ldap.');
        $bytes = "\x00\x2a\x28\x29\x5c" . str_repeat("\x01", 11);
        $escaped = directory_guid_filter_value($bytes);

        self::assertStringStartsWith('\\00\\2a\\28\\29\\5c', strtolower($escaped));
        self::assertStringNotContainsString("\0", $escaped);
    }

    public function testAccountExpiryUsesAnInjectedBoundaryClock(): void
    {
        $now = 1_700_000_000;
        $oneSecondLater = (string) (($now + 11_644_473_600 + 1) * 10_000_000);
        $exactlyNow = (string) (($now + 11_644_473_600) * 10_000_000);

        self::assertTrue(directory_account_is_enabled(0, $oneSecondLater, $now));
        self::assertFalse(directory_account_is_enabled(0, $exactlyNow, $now));
        self::assertFalse(directory_account_is_enabled(0x0002, '0', $now));
        self::assertFalse(directory_account_is_enabled(0, 'not-a-filetime', $now));
    }

    public function testCaBundleRejectsEmptyGarbageAndPrivateKeys(): void
    {
        $rejected = 0;
        foreach (['', 'not a certificate', "-----BEGIN PRIVATE KEY-----\nsecret\n-----END PRIVATE KEY-----"] as $invalid) {
            try {
                directory_normalize_ca_bundle($invalid);
                self::fail('Unsafe CA material was accepted.');
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(3, $rejected);
    }

    public function testCaBundleNormalizesTheExistingCertificateFixture(): void
    {
        $pem = (string) file_get_contents(dirname(__DIR__) . '/fixtures/https/valid.crt.txt');
        $normalized = directory_normalize_ca_bundle("\r\n" . str_replace("\n", "\r\n", $pem) . "\r\n");

        self::assertStringStartsWith('-----BEGIN CERTIFICATE-----', $normalized);
        self::assertStringEndsWith("-----END CERTIFICATE-----\n", $normalized);
        self::assertSame(1, substr_count($normalized, '-----BEGIN CERTIFICATE-----'));
    }

    public function testCaBundleRejectsSurroundingNonCertificateData(): void
    {
        $pem = (string) file_get_contents(dirname(__DIR__) . '/fixtures/https/valid.crt.txt');
        foreach (["garbage\n" . $pem, $pem . "\ngarbage", $pem . "\n-----BEGIN COMMENT-----\nx\n-----END COMMENT-----"] as $invalid) {
            try {
                directory_normalize_ca_bundle($invalid);
                self::fail('Surrounding CA bundle data was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testExpiredMonotonicDeadlineFailsBeforeAnotherOperation(): void
    {
        $this->expectException(DirectoryLdapException::class);
        $this->expectExceptionMessage(VIRTUSPHERE_DIRECTORY_OUTCOME_TIMEOUT);
        directory_deadline_remaining_seconds(directory_deadline_now() - 1, 5);
    }

    public function testUsersLinksAcceptOnlyTheirClosedAnchorSet(): void
    {
        self::assertSame('users.php?view=directory#directory-config', users_url(VIRTUSPHERE_USERS_VIEW_DIRECTORY, 'directory-config'));
        $this->expectException(InvalidArgumentException::class);
        users_url(VIRTUSPHERE_USERS_VIEW_DIRECTORY, 'plausible-but-unknown');
    }

    public function testImportTokensAreConsumedIndividually(): void
    {
        /** @var array<string,mixed> $_SESSION */
        $_SESSION = [];
        $rows = [
            ['guid_bytes' => str_repeat("\x01", 16), 'upn' => 'one@example.test', 'dn' => 'one'],
            ['guid_bytes' => str_repeat("\x02", 16), 'upn' => 'two@example.test', 'dn' => 'two'],
        ];
        $display = directory_store_import_candidates($rows);
        $first = (string) $display[0]['import_token'];
        $second = (string) $display[1]['import_token'];

        self::assertSame(str_repeat("\x01", 16), directory_take_import_candidate($first));
        self::assertTrue(directory_test_session_has_import_token($second));
        try {
            directory_take_import_candidate('invalid');
            self::fail('An unknown token was accepted.');
        } catch (ValidationException) {
            self::assertTrue(directory_test_session_has_import_token($second));
        }
        self::assertSame(str_repeat("\x02", 16), directory_take_import_candidate($second));
    }

    public function testMaterializedCaFileIsContentAddressedAndRejectsModeDrift(): void
    {
        $pem = (string) file_get_contents(dirname(__DIR__) . '/fixtures/https/valid.crt.txt');
        $normalized = directory_normalize_ca_bundle($pem);
        $path = directory_ca_file($pem);
        try {
            self::assertSame('ca-' . hash('sha256', $normalized) . '.pem', basename($path));
            self::assertSame($normalized, file_get_contents($path));
            self::assertSame(0600, ((int) fileperms($path)) & 0777);

            self::assertTrue(chmod($path, 0644));
            $this->expectException(DirectoryLdapException::class);
            directory_ca_file($pem);
        } finally {
            @chmod($path, 0600);
            @unlink($path);
        }
    }

    public function testCooldownSelectionUsesReadyControllersOrOneRecoveryProbe(): void
    {
        $controllers = [
            ['id' => 1, 'is_cooling' => 0],
            ['id' => 2, 'is_cooling' => 0],
            ['id' => 3, 'is_cooling' => 1],
        ];
        self::assertSame([1, 2], array_column(directory_select_login_controllers($controllers), 'id'));

        $cooling = [
            ['id' => 7, 'is_cooling' => 1],
            ['id' => 8, 'is_cooling' => 1],
        ];
        self::assertSame([7], array_column(directory_select_login_controllers($cooling), 'id'));
        self::assertSame([], directory_select_login_controllers([]));
    }

    // --- N1a: AD/LDAPS hardening ------------------------------------------

    public function testAPasswordReachesAControllerOnlyWithoutNulAndWithinTheByteBound(): void
    {
        self::assertTrue(directory_password_is_acceptable('a'));
        self::assertTrue(directory_password_is_acceptable(str_repeat('a', VIRTUSPHERE_DIRECTORY_PASSWORD_MAX_BYTES)));
        self::assertFalse(directory_password_is_acceptable(''));
        self::assertFalse(directory_password_is_acceptable("\0"));
        self::assertFalse(directory_password_is_acceptable("correct\0horse"));
        self::assertFalse(directory_password_is_acceptable(str_repeat('a', VIRTUSPHERE_DIRECTORY_PASSWORD_MAX_BYTES + 1)));
        // The bound is bytes, not characters: 256 four-byte characters fit.
        self::assertTrue(directory_password_is_acceptable(str_repeat("\u{1F511}", 256)));
    }

    /** @return array<string,array{0:string}> */
    public static function refusedBindPasswords(): array
    {
        return [
            'empty' => [''],
            'NUL byte (A1)' => ["pass\0word"],
            'over the byte bound (A8)' => [str_repeat('a', VIRTUSPHERE_DIRECTORY_PASSWORD_MAX_BYTES + 1)],
        ];
    }

    /**
     * The refusal happens before any LDAP call: with a null connection,
     * ldap_set_option()/ldap_bind() would throw a TypeError, never this
     * typed outcome.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedBindPasswords')]
    public function testTheBindRefusesWithTheTypedRejectionBeforeAnyNetworkCall(string $password): void
    {
        foreach ([VIRTUSPHERE_DIRECTORY_OUTCOME_USER_BIND_REJECTED, VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED] as $rejection) {
            try {
                directory_ldap_bind(null, 'cn=someone,dc=example,dc=test', $password, $rejection, directory_deadline_now() + 5);
                self::fail('expected a typed refusal');
            } catch (DirectoryLdapException $exception) {
                self::assertSame($rejection, $exception->outcome);
                self::assertFalse($exception->transportFailure);
            }
        }
    }

    public function testTheSignInRefusesNulOverlongAndNonUpnInputBeforeAnyReservation(): void
    {
        $ad = VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY;
        $local = VIRTUSPHERE_AUTH_SOURCE_LOCAL;
        self::assertTrue(auth_login_input_is_admissible('alice@example.test', 'secret', $ad));
        self::assertFalse(auth_login_input_is_admissible('alice@example.test', "se\0cret", $ad), 'A1');
        self::assertFalse(auth_login_input_is_admissible('admin', "se\0cret", $local), 'A1 applies to both sources');
        self::assertFalse(auth_login_input_is_admissible('alice@example.test', str_repeat('a', VIRTUSPHERE_DIRECTORY_PASSWORD_MAX_BYTES + 1), $ad), 'A8');
        self::assertFalse(auth_login_input_is_admissible('EXAMPLE\\alice', 'secret', $ad), 'F4: DOMAIN\\name');
        self::assertFalse(auth_login_input_is_admissible('alice', 'secret', $ad), 'F4: bare name');
        // A local name is no UPN and a long local password is the hash's business.
        self::assertTrue(auth_login_input_is_admissible('admin', 'secret', $local));
        self::assertTrue(auth_login_input_is_admissible('admin', str_repeat('a', VIRTUSPHERE_DIRECTORY_PASSWORD_MAX_BYTES + 1), $local));
        self::assertFalse(auth_login_input_is_admissible('', 'secret', $local));
        self::assertFalse(auth_login_input_is_admissible('admin', '', $local));
        self::assertFalse(auth_login_input_is_admissible('admin', 'secret', 'ldap'));
    }

    public function testTheLocalPasswordCheckDoesNotThrowOnANulByte(): void
    {
        // Sibling check of A1: password_verify() returns false for a NUL byte
        // instead of throwing like ldap_bind(), for a real and the blind hash.
        $hash = password_hash('correct horse', PASSWORD_DEFAULT);
        self::assertFalse(auth_password_verify_constant_cost("correct\0horse", $hash));
        self::assertFalse(auth_password_verify_constant_cost("\0", null));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function entriesWithoutAUsableAccountControl(): array
    {
        $base = self::ldapEntry();
        $missing = $base;
        unset($missing['useraccountcontrol']);

        return [
            'missing (search account may not read it)' => [$missing],
            'empty' => [['useraccountcontrol' => ['count' => 1, 0 => '']] + $base],
            'not a number' => [['useraccountcontrol' => ['count' => 1, 0 => 'NORMAL_ACCOUNT']] + $base],
            'pwdLastSet missing (F1)' => [array_diff_key($base, ['pwdlastset' => true])],
            'pwdLastSet not a number (F1)' => [['pwdlastset' => ['count' => 1, 0 => 'never']] + $base],
        ];
    }

    /** @param array<string,mixed> $entry */
    #[\PHPUnit\Framework\Attributes\DataProvider('entriesWithoutAUsableAccountControl')]
    public function testAnEntryWithoutAReadableAccountStateIsAnInvalidResponse(array $entry): void
    {
        // N1a A3: (int) '' was 0, i.e. "enabled", for an attribute the search
        // account could not read; disabled accounts kept their sessions.
        try {
            directory_ldap_normalize_user($entry);
            self::fail('expected invalid_response');
        } catch (DirectoryLdapException $exception) {
            self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE, $exception->outcome);
        }
    }

    public function testAReadableEntryCarriesEnabledLockoutAndPasswordGeneration(): void
    {
        $user = directory_ldap_normalize_user(self::ldapEntry());
        self::assertTrue($user['enabled']);
        self::assertFalse($user['locked'], 'the computed attribute is optional; absent means no known lockout');
        self::assertSame('134000000000000000', $user['pwd_last_set']);

        $disabled = directory_ldap_normalize_user(['useraccountcontrol' => ['count' => 1, 0 => '514']] + self::ldapEntry());
        self::assertFalse($disabled['enabled']);
        $locked = directory_ldap_normalize_user(['msds-user-account-control-computed' => ['count' => 1, 0 => '16']] + self::ldapEntry());
        self::assertTrue($locked['locked']);
    }

    public function testAPasswordSetAfterTheSignInIsNewerThanTheSessionBaseline(): void
    {
        self::assertSame('134000000000000000', directory_parse_password_last_set('0134000000000000000'));
        self::assertSame('0', directory_parse_password_last_set('0'));
        self::assertNull(directory_parse_password_last_set(''));
        self::assertNull(directory_parse_password_last_set('-1'));

        self::assertFalse(directory_password_changed_since('134000000000000000', '134000000000000000'));
        self::assertTrue(directory_password_changed_since('134000000000000000', '134000000000000001'));
        self::assertTrue(directory_password_changed_since('99999999999999999', '134000000000000000'), 'compared as numbers, not strings');
        self::assertFalse(directory_password_changed_since('134000000000000001', '134000000000000000'));
        self::assertTrue(directory_password_changed_since('134000000000000000', '0'), 'an admin set "must change at next logon"');
        self::assertFalse(directory_password_changed_since('0', '0'));
    }

    public function testAMissingTlsFloorConstantRefusesLikeAMissingExtension(): void
    {
        // N1a A5: without the constants libldap would connect with no floor.
        foreach (['LDAP_OPT_X_TLS_PROTOCOL_MIN', 'LDAP_OPT_X_TLS_PROTOCOL_TLS1_2'] as $missing) {
            try {
                directory_ldap_tls_minimum(static fn (string $name): bool => $name !== $missing && defined($name));
                self::fail('expected extension_missing without ' . $missing);
            } catch (DirectoryLdapException $exception) {
                self::assertSame(VIRTUSPHERE_DIRECTORY_OUTCOME_EXTENSION_MISSING, $exception->outcome);
            }
        }
        self::assertSame([LDAP_OPT_X_TLS_PROTOCOL_MIN, LDAP_OPT_X_TLS_PROTOCOL_TLS1_2], directory_ldap_tls_minimum());
    }

    /** @return array<string,array{0:string,1:string,2:string,3:string}> */
    public static function adBindDiagnostics(): array
    {
        $text = static fn (string $code): string => '80090308: LdapErr: DSID-0C090447, comment: AcceptSecurityContext error, data ' . $code . ', v4563';

        return [
            'wrong password' => [$text('52e'), '52e', VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, VIRTUSPHERE_DIRECTORY_BIND_REASON_INVALID],
            'logon hours' => [$text('530'), '530', VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, VIRTUSPHERE_DIRECTORY_BIND_REASON_LOGON_HOURS],
            'password expired' => [$text('532'), '532', VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_PASSWORD_EXPIRED, VIRTUSPHERE_DIRECTORY_BIND_REASON_PASSWORD_EXPIRED],
            'account disabled' => [$text('533'), '533', VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_ACCOUNT_DISABLED, VIRTUSPHERE_DIRECTORY_BIND_REASON_DISABLED],
            'account expired' => [$text('701'), '701', VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_ACCOUNT_DISABLED, VIRTUSPHERE_DIRECTORY_BIND_REASON_EXPIRED],
            'must change' => [$text('773'), '773', VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_PASSWORD_EXPIRED, VIRTUSPHERE_DIRECTORY_BIND_REASON_MUST_CHANGE],
            'locked' => [$text('775'), '775', VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_ACCOUNT_LOCKED, VIRTUSPHERE_DIRECTORY_BIND_REASON_LOCKED],
            'upper case hex' => [$text('52E'), '52e', VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, VIRTUSPHERE_DIRECTORY_BIND_REASON_INVALID],
            'unknown code' => [$text('52f'), '52f', VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, VIRTUSPHERE_DIRECTORY_BIND_REASON_INVALID],
            'no data part (slapd)' => ['Invalid credentials', '', VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, VIRTUSPHERE_DIRECTORY_BIND_REASON_INVALID],
            'data inside a word' => ['metadata 532,', '', VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, VIRTUSPHERE_DIRECTORY_BIND_REASON_INVALID],
            'empty' => ['', '', VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED, VIRTUSPHERE_DIRECTORY_BIND_REASON_INVALID],
        ];
    }

    /**
     * N1a A7/F5: only the hex value after "data " leaves the diagnostic, and
     * it maps onto closed words. The real AD mapping is checked in Gate 0B
     * (the slapd fixture cannot produce AD sub-codes).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('adBindDiagnostics')]
    public function testOnlyTheAdSubCodeMapsOntoTypedOutcomes(string $diagnostic, string $code, string $serviceOutcome, string $userReason): void
    {
        self::assertSame($code, directory_bind_code_from_diagnostic($diagnostic));
        self::assertSame($serviceOutcome, directory_service_rejection_outcome($code));
        self::assertContains($serviceOutcome, VIRTUSPHERE_DIRECTORY_SERVICE_REJECTION_OUTCOMES);
        self::assertSame($userReason, directory_user_bind_reason($code));
        $exception = new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_USER_BIND_REJECTED, false, $userReason);
        self::assertStringNotContainsString('DSID', $exception->getMessage() . $exception->bindReason);
    }

    public function testABindReasonOutsideTheClosedListIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_USER_BIND_REJECTED, false, 'data 52e, v4563');
    }

    public function testEveryServiceRejectionIsAnInfrastructureOutcomeForTheSignIn(): void
    {
        foreach (VIRTUSPHERE_DIRECTORY_SERVICE_REJECTION_OUTCOMES as $outcome) {
            self::assertContains($outcome, VIRTUSPHERE_DIRECTORY_OUTCOMES);
            self::assertContains($outcome, VIRTUSPHERE_DIRECTORY_INFRASTRUCTURE_OUTCOMES, $outcome . ' must free the attempt budget, not count as a bad password');
            self::assertNotSame(__t('directory.outcome_unknown'), directory_outcome_message($outcome), $outcome . ' needs its own operator sentence');
        }
        self::assertNotContains(VIRTUSPHERE_DIRECTORY_OUTCOME_USER_BIND_REJECTED, VIRTUSPHERE_DIRECTORY_INFRASTRUCTURE_OUTCOMES);
    }

    public function testTheCertificateReadSlotIsWonOncePerControllerRevisionAndDay(): void
    {
        // N1a F7: at most one TLS handshake per controller and day.
        $dir = sys_get_temp_dir() . '/virtusphere-n1a-slot-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            $now = 1_800_000_000;
            self::assertTrue(directory_certificate_refresh_claim_slot($dir, 7, 3, $now));
            self::assertFalse(directory_certificate_refresh_claim_slot($dir, 7, 3, $now + 60), 'same day: no second read');
            self::assertTrue(directory_certificate_refresh_claim_slot($dir, 8, 3, $now), 'another controller has its own slot');
            self::assertTrue(directory_certificate_refresh_claim_slot($dir, 7, 4, $now), 'a new revision reads again');
            self::assertTrue(directory_certificate_refresh_claim_slot($dir, 7, 3, $now + VIRTUSPHERE_DIRECTORY_CERTIFICATE_REFRESH_SECONDS), 'next day');
            self::assertFalse(directory_certificate_refresh_claim_slot($dir, 0, 3, $now), 'no controller id, no slot');
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    /** @return array<string,mixed> one ext-ldap shaped AD user entry */
    private static function ldapEntry(): array
    {
        $one = static fn (string $value): array => ['count' => 1, 0 => $value];

        return [
            'dn' => 'cn=alice,ou=people,dc=example,dc=test',
            'objectguid' => $one(str_repeat("\x01", 16)),
            'userprincipalname' => $one('alice@example.test'),
            'samaccountname' => $one('alice'),
            'useraccountcontrol' => $one('512'),
            'accountexpires' => $one('0'),
            'pwdlastset' => $one('134000000000000000'),
        ];
    }
}

function directory_test_session_has_import_token(string $token): bool
{
    $state = $_SESSION['directory_import_candidates'] ?? null;

    return is_array($state) && is_array($state['items'] ?? null) && array_key_exists($token, $state['items']);
}
