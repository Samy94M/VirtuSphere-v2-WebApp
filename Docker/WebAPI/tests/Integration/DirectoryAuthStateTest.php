<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/auth_rate_limit.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/directory_restore_converge.php';

final class DirectoryAuthStateTest extends TestCase
{
    private const PREFIX = 'phpunit_directory_auth_';

    private ?mysqli $db = null;
    private bool $ownsConfig = false;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->db->query("DELETE FROM deploy_login_attempts WHERE username LIKE '" . self::PREFIX . "%'");
        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
    }

    protected function tearDown(): void
    {
        if ($this->db === null) {
            return;
        }
        $this->db->query("DELETE FROM deploy_login_attempts WHERE username LIKE '" . self::PREFIX . "%'");
        if ($this->ownsConfig) {
            $this->db->query('DELETE FROM deploy_ad_config WHERE id = 1');
        }
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function testInfrastructureResultReleasesBothFailureBudgets(): void
    {
        $username = self::PREFIX . 'infra@example.test';
        $reservation = auth_reserve_login_attempt($this->db, $username, VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY);
        self::assertTrue($reservation['ok']);
        auth_finish_infrastructure_login($this->db, $reservation['id']);

        self::assertSame(0, auth_failed_attempt_count($this->db, $username, VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY));
        self::assertSame(0, auth_failed_ip_attempt_count($this->db));
    }

    public function testUserBudgetAggregatesCredentialFailuresAcrossIps(): void
    {
        $username = self::PREFIX . 'distributed@example.test';
        for ($index = 0; $index < VIRTUSPHERE_LOGIN_USER_FAILURE_LIMIT; $index++) {
            $_SERVER['REMOTE_ADDR'] = '198.51.100.' . (20 + $index);
            $reservation = auth_reserve_login_attempt($this->db, $username, VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY);
            self::assertTrue($reservation['ok']);
            auth_finish_login_attempt($this->db, $reservation['id'], VIRTUSPHERE_LOGIN_RESULT_CREDENTIAL_FAILURE);
        }
        $_SERVER['REMOTE_ADDR'] = '198.51.100.99';
        $blocked = auth_reserve_login_attempt($this->db, $username, VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY);

        self::assertFalse($blocked['ok']);
        self::assertSame('rate_limited', $blocked['reason']);
    }

    public function testRestoreDisablesDirectoryAndInvalidatesControllerRevision(): void
    {
        if (repo_directory_config($this->db) !== null) {
            self::markTestSkipped('Disposable integration database already has directory configuration.');
        }
        $this->db->query("INSERT INTO deploy_ad_config (id, enabled, revision, bind_upn, bind_secret_ciphertext, ca_certificate_pem) VALUES (1, 1, 7, 'search@example.test', 'ciphertext', 'certificate')");
        $this->db->query("INSERT INTO deploy_ad_controllers (config_id, host, port, priority, enabled, validated_revision) VALUES (1, 'dc01.example.test', 636, 1, 1, 7)");
        $this->ownsConfig = true;

        self::assertTrue(directory_restore_converge($this->db));
        $config = repo_directory_config($this->db);
        $controller = repo_directory_controllers($this->db)[0];

        self::assertSame(0, (int) $config['enabled']);
        self::assertSame(8, (int) $config['revision']);
        self::assertSame(7, (int) $controller['validated_revision']);
    }
}
