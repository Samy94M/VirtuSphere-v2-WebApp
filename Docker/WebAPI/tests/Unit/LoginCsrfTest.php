<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/login_csrf.php';

/** SEC2 (C-02): the sign-in token is bound to its cookie, not to a session. */
final class LoginCsrfTest extends TestCase
{
    private const KEY = 'unit-test-key-32-bytes-long-----';

    /** @return array<string, string> */
    private static function cookies(string $nonce): array
    {
        return [VIRTUSPHERE_LOGIN_CSRF_COOKIE => $nonce];
    }

    public function testAFieldMadeForTheCookieVerifies(): void
    {
        $nonce = login_csrf_nonce([]);
        self::assertTrue(login_csrf_nonce_valid($nonce));
        $field = login_csrf_field_value($nonce, self::KEY);

        self::assertTrue(login_csrf_verify(self::cookies($nonce), $field, self::KEY));
    }

    public function testAnExistingCookieKeepsItsNonceSoTwoTabsStayValid(): void
    {
        $nonce = str_repeat('ab', 32);
        self::assertSame($nonce, login_csrf_nonce(self::cookies($nonce)));
        self::assertNotSame('not-a-nonce', login_csrf_nonce(self::cookies('not-a-nonce')));
    }

    public function testEverythingElseIsRefused(): void
    {
        $nonce = str_repeat('0f', 32);
        $field = login_csrf_field_value($nonce, self::KEY);

        self::assertFalse(login_csrf_verify([], $field, self::KEY), 'no cookie (a cross-site POST under SameSite=Strict)');
        self::assertFalse(login_csrf_verify(self::cookies(str_repeat('1f', 32)), $field, self::KEY), 'another cookie');
        self::assertFalse(login_csrf_verify(self::cookies($nonce), $nonce, self::KEY), 'the bare nonce as field (plain double submit)');
        self::assertFalse(login_csrf_verify(self::cookies($nonce), $field, 'another-key-32-bytes-long-------'), 'another APP_KEY');
        self::assertFalse(login_csrf_verify(self::cookies($nonce), [$field], self::KEY), 'an array field rejects, never throws');
        self::assertFalse(login_csrf_verify(self::cookies($nonce), null, self::KEY), 'no field');
        self::assertFalse(login_csrf_verify(self::cookies(strtoupper($nonce)), $field, self::KEY), 'a cookie outside the nonce format');
        self::assertFalse(login_csrf_verify([VIRTUSPHERE_LOGIN_CSRF_COOKIE => [$nonce]], $field, self::KEY), 'an array cookie');
    }
}
