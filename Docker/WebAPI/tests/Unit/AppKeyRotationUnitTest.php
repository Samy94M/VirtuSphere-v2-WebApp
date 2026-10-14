<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/crypto.php';

/**
 * Unit cases of the APP_KEY rotation (K22): the key format, the v1 crypto with an explicit key, and the backup gate.
 *
 * No database is used. The rotation module lib/app_key_rotate.php is loaded when it exists, so a missing function
 * fails the tests that need it by name instead of as a fatal error.
 */
final class AppKeyRotationUnitTest extends TestCase
{
    /** A fixed "now" for the backup gate, so the age boundaries are exact. */
    private const NOW = 1_800_000_000;

    protected function setUp(): void
    {
        $module = dirname(__DIR__, 2) . '/lib/app_key_rotate.php';
        if (is_file($module)) {
            require_once $module;
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAppKeys(): iterable
    {
        yield '31 bytes with base64: prefix' => ['base64:' . base64_encode(random_bytes(31))];
        yield '31 bytes in bare base64' => [base64_encode(random_bytes(31))];
        yield '33 bytes with base64: prefix' => ['base64:' . base64_encode(random_bytes(33))];
        yield '33 bytes in bare base64' => [base64_encode(random_bytes(33))];
        yield 'text that is not base64' => ['this is not a key!'];
    }

    /** envboot_parse_app_key() accepts the base64: form and the bare base64 form and returns the same 32 raw bytes. */
    public function testParseAppKeyAcceptsPrefixedAndBareBase64AsTheSame32Bytes(): void
    {
        self::assertTrue(function_exists('envboot_parse_app_key'), 'envboot_parse_app_key is missing (K22)');

        $bytes = random_bytes(32);

        self::assertSame($bytes, envboot_parse_app_key('base64:' . base64_encode($bytes)));
        self::assertSame($bytes, envboot_parse_app_key(base64_encode($bytes)));
    }

    /** envboot_parse_app_key() refuses a key of 31 or 33 bytes and text that is not base64. */
    #[DataProvider('invalidAppKeys')]
    public function testParseAppKeyRefusesWrongLengthAndNonBase64(string $raw): void
    {
        self::assertTrue(function_exists('envboot_parse_app_key'), 'envboot_parse_app_key is missing (K22)');

        $this->expectException(RuntimeException::class);
        envboot_parse_app_key($raw);
    }

    /** crypto_encrypt_secret_with_key() round-trips through crypto_decrypt_secret_with_key() in the v1 format, and a different key throws RuntimeException. */
    public function testWithKeyRoundTripsInTheV1FormatAndAWrongKeyThrows(): void
    {
        self::assertTrue(function_exists('crypto_encrypt_secret_with_key'), 'crypto_encrypt_secret_with_key is missing (K22)');
        self::assertTrue(function_exists('crypto_decrypt_secret_with_key'), 'crypto_decrypt_secret_with_key is missing (K22)');

        $key = random_bytes(32);
        $plaintext = 'K22-unit-' . bin2hex(random_bytes(6));
        $stored = crypto_encrypt_secret_with_key($plaintext, $key);

        self::assertStringStartsWith('v1:', $stored);
        self::assertStringNotContainsString($plaintext, $stored);
        self::assertSame($plaintext, crypto_decrypt_secret_with_key($stored, $key));

        try {
            crypto_decrypt_secret_with_key($stored, random_bytes(32));
        } catch (RuntimeException) {
            return;
        }
        self::fail('A value encrypted with one key decrypted with a different key.');
    }

    /** A value from crypto_encrypt_secret_with_key() with the running key decrypts via crypto_decrypt_secret(), and the reverse. */
    public function testWithKeyAndTheEnvKeyFunctionsShareOneFormat(): void
    {
        self::assertTrue(function_exists('crypto_encrypt_secret_with_key'), 'crypto_encrypt_secret_with_key is missing (K22)');
        self::assertTrue(function_exists('crypto_decrypt_secret_with_key'), 'crypto_decrypt_secret_with_key is missing (K22)');

        // The test sets the running key and restores the previous value afterwards.
        $previous = getenv('APP_KEY');
        putenv('APP_KEY=base64:' . base64_encode(random_bytes(32)));
        try {
            $plaintext = 'K22-compat-' . bin2hex(random_bytes(6));
            $stored = crypto_encrypt_secret_with_key($plaintext, envboot_app_key_bytes());
            self::assertSame($plaintext, crypto_decrypt_secret($stored));

            $fromEnv = crypto_encrypt_secret($plaintext);
            self::assertSame($plaintext, crypto_decrypt_secret_with_key($fromEnv, envboot_app_key_bytes()));
        } finally {
            if ($previous === false) {
                putenv('APP_KEY');
            } else {
                putenv('APP_KEY=' . $previous);
            }
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>|null, string|null}> */
    public static function backupStatuses(): iterable
    {
        yield 'no backup line' => ['unknown', null, 'backup_missing'];
        yield 'newest run failed' => ['failed', ['status' => 'failed', 'ts' => self::NOW - 60], 'backup_failed'];
        yield 'newest run ok, 7201 s old' => ['ok', ['status' => 'ok', 'ts' => self::NOW - 7201], 'backup_too_old'];
        yield 'newest run ok, exactly 7200 s old' => ['ok', ['status' => 'ok', 'ts' => self::NOW - 7200], null];
        yield 'newest run ok, fresh' => ['ok', ['status' => 'ok', 'ts' => self::NOW - 60], null];
    }

    /** app_key_rotate_backup_blocker() names why a rotation is blocked and returns null for an ok backup up to 7200 s old. */
    #[DataProvider('backupStatuses')]
    public function testBackupBlockerNamesTheReasonOrAllowsAnOkBackupUpToTheLimit(string $state, ?array $last, ?string $expected): void
    {
        self::assertTrue(function_exists('app_key_rotate_backup_blocker'), 'app_key_rotate_backup_blocker is missing (K22)');

        self::assertSame($expected, app_key_rotate_backup_blocker(['state' => $state, 'last' => $last], self::NOW));
    }

    /** The backup age limit of the rotation gate is 7200 seconds (two hours). */
    public function testBackupMaxAgeConstantIsTwoHours(): void
    {
        // constant() throws an Error naming the constant while the module is missing.
        self::assertSame(7200, constant('VIRTUSPHERE_APP_KEY_ROTATE_BACKUP_MAX_AGE_SECONDS'));
    }
}
