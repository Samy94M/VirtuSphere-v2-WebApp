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
}
