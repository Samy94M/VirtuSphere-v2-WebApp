<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/constants.php';

/**
 * Pins the PHP session hardening that lives in Docker/php/conf.d/zz-virtusphere.ini.
 *
 * An .ini cannot interpolate a PHP constant, so the one number in it that the
 * application owns (the maximum configurable session lifetime) is a mirror, and a
 * mirror drifts. This is the same failure shape check-bounds-sync.php guards for
 * user-facing text: the code keeps working and only the promise becomes false.
 *
 * Concretely: session.gc_maxlifetime bounds how long PHP keeps the session FILE.
 * The admin can configure a session up to VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MAX
 * minutes. If the .ini is the smaller of the two, the garbage collector deletes
 * the file of a still-valid session, and because the GC fires probabilistically
 * on other people's requests, the operator is thrown out at a random moment that
 * nobody can reproduce. Nothing else in the toolchain reads this file.
 */
final class SessionHardeningContractTest extends TestCase
{
    /** @return array<string, string> */
    private function settings(): array
    {
        // Docker/php is outside the container mount (only Docker/WebAPI is
        // mounted as /var/www/html), so the test skips there and runs from a
        // repo checkout, exactly like HttpsConfigTest reads Docker/nginx.
        $path = dirname(__DIR__, 3) . '/php/conf.d/zz-virtusphere.ini';
        if (!is_file($path)) {
            self::markTestSkipped('Docker/php/conf.d/zz-virtusphere.ini is not visible from this runtime');
        }

        $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);
        self::assertIsArray($parsed, 'zz-virtusphere.ini is not parseable');

        /** @var array<string, string> $parsed */
        return $parsed;
    }

    public function testSessionFileOutlivesTheLongestConfigurableSession(): void
    {
        $settings = $this->settings();
        self::assertArrayHasKey('session.gc_maxlifetime', $settings);

        $gcMaxLifetime = (int) $settings['session.gc_maxlifetime'];
        $longestSession = VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MAX * 60;

        self::assertGreaterThanOrEqual(
            $longestSession,
            $gcMaxLifetime,
            sprintf(
                'session.gc_maxlifetime is %d s, but an admin may configure a session of %d s '
                . '(VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MAX = %d min). PHP would collect the session '
                . 'file while the portal still considers the session valid. Raise the .ini, not this test.',
                $gcMaxLifetime,
                $longestSession,
                VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MAX
            )
        );
    }

    public function testStrictModeIsOn(): void
    {
        $settings = $this->settings();

        // The only defence that keeps PHP from adopting a session ID it never
        // issued. session_regenerate_id() on login is the second layer, not a
        // replacement: it only runs once the victim actually signs in.
        self::assertSame(
            '1',
            $settings['session.use_strict_mode'] ?? '0',
            'session.use_strict_mode must be on: without it PHP accepts an attacker-supplied session ID (fixation).'
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function hardenedFlags(): iterable
    {
        yield 'no JavaScript access to the session cookie' => ['session.cookie_httponly', '1'];
        yield 'no cross-site sending of the session cookie' => ['session.cookie_samesite', 'Strict'];
        yield 'the session ID never comes from the URL' => ['session.use_only_cookies', '1'];
        yield 'no session ID rewritten into links' => ['session.use_trans_sid', '0'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hardenedFlags')]
    public function testHardenedFlag(string $key, string $expected): void
    {
        // These are also set through session_set_cookie_params() in the portal
        // bootstrap. They are pinned here as well because an entry point that
        // starts a session without that bootstrap would otherwise silently fall
        // back to PHP's unsafe defaults.
        $settings = $this->settings();

        self::assertArrayHasKey($key, $settings);
        self::assertSame($expected, $settings[$key]);
    }

    /**
     * SEC2 (C-02): an anonymous request writes no session file. The runtime
     * proof (20 cookieless sign-in forms and 20 invented session cookies: zero
     * files; a sign-in: one) is in the SEC2 register entry; this pins the shape
     * that produced it, so a "simple" session_start() in the bootstrap or a
     * session-bound token on the sign-in form cannot come back unnoticed.
     */
    public function testAnonymousRequestsOpenNoSession(): void
    {
        $root = dirname(__DIR__, 2);
        $bootstrap = (string) file_get_contents($root . '/lib/bootstrap.php');
        self::assertSame(1, substr_count($bootstrap, 'session_start('), 'one place creates a session: virtusphere_create_session()');
        self::assertMatchesRegularExpression(
            '/function virtusphere_start_session\(\): void\s*\{.*?\$_COOKIE\[session_name\(\)\].*?return;.*?virtusphere_create_session\(\);\s*if \(\$_SESSION === \[\]\) \{\s*virtusphere_discard_session\(\);/s',
            $bootstrap,
            'without the session cookie nothing starts, and an empty session is discarded at once'
        );

        $login = (string) file_get_contents($root . '/portal/login.php');
        self::assertDoesNotMatchRegularExpression('/(?<![a-z_])csrf_field\(/', $login, 'the sign-in form token must not live in a session');
        self::assertStringContainsString('login_csrf_verify($_COOKIE,', $login);
        $create = strpos($login, 'virtusphere_create_session();');
        $attempt = strpos($login, '$result = login(');
        self::assertIsInt($create);
        self::assertIsInt($attempt);
        self::assertLessThan($attempt, $create, 'the session exists before auth_complete_login() renews its id');
        self::assertStringContainsString("if (\$_SESSION === []) {\n        virtusphere_discard_session();", $login, 'a failed attempt leaves no file');
    }
}
