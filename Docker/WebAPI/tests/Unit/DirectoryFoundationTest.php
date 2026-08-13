<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/directory_identity.php';
require_once dirname(__DIR__, 2) . '/lib/directory_result.php';
require_once dirname(__DIR__, 2) . '/lib/directory_tls.php';
require_once dirname(__DIR__, 2) . '/lib/directory_service.php';
require_once dirname(__DIR__, 2) . '/lib/repo/directory.php';
require_once dirname(__DIR__, 2) . '/lib/users_page.php';

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
}

function directory_test_session_has_import_token(string $token): bool
{
    $state = $_SESSION['directory_import_candidates'] ?? null;

    return is_array($state) && is_array($state['items'] ?? null) && array_key_exists($token, $state['items']);
}
