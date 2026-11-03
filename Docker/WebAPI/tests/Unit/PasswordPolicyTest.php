<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/password_policy.php';

/**
 * password_policy_error() is the one length check behind account.php and both
 * users.php password paths. It counts characters (mb_strlen), not bytes: the
 * form promises "12 characters" to a user typing umlauts, and a multi-byte
 * password must not pass or fail depending on its encoding width.
 */
final class PasswordPolicyTest extends TestCase
{
    public function testExactMinimumPasses(): void
    {
        self::assertNull(password_policy_error(str_repeat('a', 12), 12, 'users.err_password_min'));
    }

    public function testOneBelowMinimumFails(): void
    {
        $error = password_policy_error(str_repeat('a', 11), 12, 'users.err_password_min');
        self::assertIsString($error);
        self::assertStringContainsString('12', $error, 'the message must name the configured minimum');
    }

    public function testUmlautsCountAsOneCharacter(): void
    {
        // 12 umlauts are 24 bytes; byte-based strlen would wrongly pass an
        // 11-umlaut password and mb_strlen must accept the 12-umlaut one.
        self::assertNull(password_policy_error(str_repeat('ä', 12), 12, 'users.err_password_min'));
        self::assertIsString(password_policy_error(str_repeat('ä', 11), 12, 'users.err_password_min'));
    }

    public function testTightenedMinimumApplies(): void
    {
        $error = password_policy_error(str_repeat('a', 12), 16, 'account.err_new_password_min');
        self::assertIsString($error);
        self::assertStringContainsString('16', $error);
        self::assertNull(password_policy_error(str_repeat('a', 16), 16, 'account.err_new_password_min'));
    }
}
