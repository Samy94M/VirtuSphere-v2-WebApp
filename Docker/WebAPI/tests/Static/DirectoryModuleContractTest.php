<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DirectoryModuleContractTest extends TestCase
{
    public function testDirectoryModulesStayFocusedAndBelowTheFileSizeBoundary(): void
    {
        $root = dirname(__DIR__, 2) . '/lib';
        $files = glob($root . '/directory*.php') ?: [];
        self::assertNotSame([], $files, 'No directory module was found.');

        $oversized = [];
        foreach ($files as $file) {
            $lines = file($file);
            self::assertIsArray($lines);
            if (count($lines) >= 400) {
                $oversized[basename($file)] = count($lines);
            }
        }
        self::assertSame([], $oversized, 'Directory modules must be split before reaching 400 lines.');
    }

    public function testTheOldAmbiguousBindOutcomeAndSourceFallbackAreGone(): void
    {
        $constants = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/directory_constants.php');
        $allSources = implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents($path),
            glob(dirname(__DIR__, 2) . '/lib/directory*.php') ?: []
        ));

        self::assertStringNotContainsString('VIRTUSPHERE_DIRECTORY_OUTCOME_BIND_REJECTED', $allSources);
        self::assertStringNotContainsString('directory_auth_source_normalize', $constants);
        self::assertStringContainsString('VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED', $constants);
        self::assertStringContainsString('VIRTUSPHERE_DIRECTORY_OUTCOME_USER_BIND_REJECTED', $constants);
    }

    public function testTlsAndIdentityOwnersAreLoadedByTheNativeAdapter(): void
    {
        $adapter = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/directory_ldap.php');

        self::assertStringContainsString("require_once __DIR__ . '/directory_identity.php';", $adapter);
        self::assertStringContainsString("require_once __DIR__ . '/directory_result.php';", $adapter);
        self::assertStringContainsString("require_once __DIR__ . '/directory_tls.php';", $adapter);
        self::assertStringNotContainsString('function directory_ca_file(', $adapter);
        self::assertStringNotContainsString('function directory_guid_display(', $adapter);
    }

    public function testAuthKeepsRateLimitAndDirectoryLoginBehindFocusedModules(): void
    {
        $auth = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/auth.php');
        $rateLimit = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/auth_rate_limit.php');
        $directoryLogin = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/auth_directory_login.php');

        self::assertStringContainsString("require_once __DIR__ . '/auth_rate_limit.php';", $auth);
        self::assertStringContainsString("require_once __DIR__ . '/auth_directory_login.php';", $auth);
        self::assertStringNotContainsString('function auth_record_failed_login(', $auth);
        self::assertStringNotContainsString('function auth_login_directory(', $auth);
        self::assertStringContainsString('function auth_record_failed_login(', $rateLimit);
        self::assertStringContainsString('function auth_login_directory(', $directoryLogin);
    }

    public function testLoginUsesOneCooldownRecoveryProbeAndAFinalLockedSnapshot(): void
    {
        $repo = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/repo/directory.php');
        $usersRepo = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/repo/directory_users.php');
        $login = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/auth_directory_login.php');

        self::assertStringContainsString('function directory_select_login_controllers(', $repo);
        self::assertStringContainsString('return $controllers === [] ? [] : [$controllers[0]];', $repo);
        self::assertStringContainsString("require_once __DIR__ . '/directory_users.php';", $repo);
        self::assertStringContainsString('function repo_directory_login_snapshot(', $usersRepo);
        self::assertStringContainsString('LIMIT 1 FOR UPDATE', $usersRepo);
        self::assertStringContainsString('repo_directory_login_snapshot(', $login);
        self::assertStringContainsString('return auth_complete_login(', $login);
    }

    public function testBroadSearchReturnsTruncationSeparatelyFromAmbiguity(): void
    {
        $adapter = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/directory_ldap.php');
        $handler = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/users_directory_admin.php');

        self::assertStringContainsString("return ['rows' => \$normalized, 'truncated' => \$truncated];", $adapter);
        self::assertStringContainsString("\$result['truncated']", $handler);
        self::assertStringContainsString('directory.flash_search_truncated', $handler);
    }

    public function testLoginReservationSessionCommitAndConfigCasStayExplicit(): void
    {
        $auth = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/auth.php');
        $rate = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/auth_rate_limit.php');
        $directoryLogin = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/auth_directory_login.php');
        $repo = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/repo/directory.php');

        self::assertStringContainsString('auth_reserve_login_attempt(', $auth);
        self::assertStringContainsString('SELECT GET_LOCK(', $rate);
        self::assertStringContainsString("VIRTUSPHERE_LOGIN_RESULT_INFRASTRUCTURE", $rate);
        self::assertStringContainsString('return auth_complete_login($user, $source);', $directoryLogin);
        self::assertStringNotContainsString('return auth_complete_login($db,', $directoryLogin);
        self::assertStringContainsString('$expectedRevision !== $currentRevision', $repo);
        self::assertStringContainsString('repo_directory_apply_controller_test_success(', $repo);
    }

    public function testEveryBlockingLdapOperationReceivesTheMonotonicDeadline(): void
    {
        $adapter = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/directory_ldap.php');
        $service = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/directory_service.php');

        self::assertStringContainsString('function directory_ldap_apply_deadline(', $adapter);
        self::assertStringContainsString('directory_ldap_require_option(null, LDAP_OPT_X_TLS_CACERTFILE', $adapter);
        self::assertStringContainsString('directory_deadline_now() + VIRTUSPHERE_DIRECTORY_TOTAL_TIMEOUT_SECONDS', $service);
        self::assertStringContainsString('$operation($connection, $runtime, $controller, $deadline)', $service);
    }

    public function testTheDeadlineClampsTheSynchronousAnswerWaitOfEveryHandle(): void
    {
        // N1a A2: NETWORK_TIMEOUT ends at the TLS handshake; only TIMEOUT
        // bounds the wait for a bind/search answer from a silent controller.
        $adapter = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/directory_ldap.php');
        self::assertSame(1, preg_match('/function directory_ldap_apply_deadline\(.*?\n\}/s', $adapter, $body));
        self::assertStringContainsString('LDAP_OPT_TIMEOUT,', $body[0]);
        self::assertStringContainsString('LDAP_OPT_NETWORK_TIMEOUT,', $body[0]);
        self::assertStringContainsString('directory_deadline_remaining_seconds($deadline', $body[0]);
        // A6: PHP 8.4 has no LDAP_OPT_X_TLS_NEWCTX; the dead branch stays gone.
        self::assertStringNotContainsString('LDAP_OPT_X_TLS_NEWCTX', $adapter);
        // A5: no fail-open defined() bracket around the TLS floor.
        self::assertStringNotContainsString("if (defined('LDAP_OPT_X_TLS_PROTOCOL_MIN')", $adapter);
        self::assertStringContainsString('directory_ldap_tls_minimum();', $adapter);
    }

    /**
     * N1a A7/F5: the controller's diagnostic text is read in exactly one place
     * and only its "data <hex>" value leaves that function. ldap_error() and
     * the error string option, which carry the same text, are used nowhere.
     */
    public function testRawLdapDiagnosticsNeverLeaveTheSubCodeReader(): void
    {
        $root = dirname(__DIR__, 2);
        $readers = [];
        $files = array_merge(
            glob($root . '/lib/*.php') ?: [],
            glob($root . '/lib/*/*.php') ?: [],
            glob($root . '/portal/*.php') ?: [],
            glob($root . '/*.php') ?: []
        );
        self::assertGreaterThan(50, count($files), 'zero-match guard: the scan found no PHP sources');
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $relative = substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', $root)) + 1);
            self::assertDoesNotMatchRegularExpression('/\bldap_error\s*\(/', $source, $relative . ' reads the raw LDAP error text');
            self::assertStringNotContainsString('LDAP_OPT_ERROR_STRING', $source, $relative);
            if (str_contains($source, 'LDAP_OPT_DIAGNOSTIC_MESSAGE')) {
                $readers[] = $relative;
            }
        }
        self::assertSame(['lib/directory_ldap.php'], $readers);

        $adapter = (string) file_get_contents($root . '/lib/directory_ldap.php');
        self::assertSame(1, substr_count($adapter, 'LDAP_OPT_DIAGNOSTIC_MESSAGE'));
        self::assertSame(1, preg_match('/function directory_ldap_bind_code\(.*?\n\}/s', $adapter, $body));
        self::assertStringContainsString('LDAP_OPT_DIAGNOSTIC_MESSAGE', $body[0], 'the diagnostic is read only inside the sub-code reader');
        self::assertMatchesRegularExpression(
            '/return directory_bind_code_from_diagnostic\(is_string\(\$diagnostic\) \? \$diagnostic : \'\'\);/',
            $body[0],
            'the only use of the text is the fixed "data <hex>" pattern'
        );
        self::assertSame(4, preg_match_all('/\$diagnostic(?!\w)/', $body[0]), 'the text variable is declared, filled, checked and handed to the pattern, nothing else');
    }

    /**
     * N1a A2 residual (user decision 2026-11-09, variant A): LDAP_OPT_TIMEOUT
     * ends a wait on a silent controller only after a TLS 1.2 handshake
     * (measured 3.0 s); after TLS 1.3 libldap hangs. This pins the boundary
     * so nobody mistakes the gap for closed: the fixture proof stays on TLS
     * 1.2 (a TLS 1.3 silent fixture would hang phpunit-full), the client sets
     * no TLS maximum (TLS 1.3 stays allowed), the FPM wall clock that bounds
     * the residual exists, and ADR and runbook name the gap.
     */
    public function testTheSilentControllerTimeoutIsProvenOnlyForTls12AndTheTls13GapIsBoundByFpm(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $entrypoint = $repoRoot . '/Docker/ldap-fixture/entrypoint.sh';
        if (!is_file($entrypoint)) {
            self::markTestSkipped('LDAP fixture is outside this partial WebAPI mount.');
        }
        $fixture = (string) file_get_contents($entrypoint);
        self::assertSame(1, preg_match('/^tls-silent\)\n(.*?)\n    ;;/ms', $fixture, $silent), 'tls-silent role not found');
        self::assertStringContainsString('openssl-max-proto-version=TLS1.2', $silent[1], 'the silent proof must stay on TLS 1.2');

        $adapter = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/directory_ldap.php');
        self::assertStringNotContainsString('LDAP_OPT_X_TLS_PROTOCOL_MAX', $adapter, 'variant A: no TLS maximum on the client');

        $fpm = (string) file_get_contents($repoRoot . '/Docker/php/fpm.d/zz-virtusphere.conf');
        self::assertMatchesRegularExpression('/^request_terminate_timeout = 300s$/m', $fpm, 'the wall clock bounds the TLS 1.3 residual');

        $adr = (string) file_get_contents($repoRoot . '/docs/adr/ADR-0039-active-directory-authentication-over-ldaps.md');
        self::assertStringContainsString('After a TLS 1.3 handshake this does not hold', $adr);
        $runbook = (string) file_get_contents($repoRoot . '/docs/operations/active-directory.md');
        self::assertStringContainsString('Nach einem TLS-1.3-Handshake greift diese Frist in libldap nicht', $runbook);
    }

    public function testQaPinsTheDirectoryCaCacheToTheFpmUser(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $qaCompose = $repoRoot . '/Docker/qa/docker-compose.qa.yml';
        if (!is_file($qaCompose)) {
            self::markTestSkipped('QA Compose override is outside this partial WebAPI mount.');
        }
        $source = (string) file_get_contents($qaCompose);

        self::assertStringContainsString(
            '/tmp/virtusphere-directory:uid=33,gid=33,mode=0700',
            $source,
            'The QA PHP container must pre-own the strict CA cache as www-data; a root docker exec must not create it first.'
        );
    }
}
