<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/auth.php';
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
        // The counter keeps only name keys (SEC3); the test addresses are TEST-NET-2.
        $this->db->query("DELETE FROM deploy_login_attempts WHERE ip_address LIKE '198.51.100.%'");
        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
    }

    protected function tearDown(): void
    {
        if ($this->db === null) {
            return;
        }
        $this->db->query("DELETE FROM deploy_login_attempts WHERE ip_address LIKE '198.51.100.%'");
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

    /**
     * A directory name keeps one budget across addresses (SEC3, C-04c applies to
     * local names only): the domain controller locks the account domain-wide, so
     * the portal must not send it more bad binds per extra host.
     */
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

    /**
     * N1a A10 (plan section 12, "lokal deaktiviert: sofort beenden"): the
     * session file of a deactivated account is destroyed with a typed audit
     * row, so a reactivation within its lifetime does not revive it. The rule
     * is source-independent; a local account proves it without a directory.
     */
    public function testALocallyDeactivatedAccountEndsItsSessionForGood(): void
    {
        $name = self::PREFIX . 'deactivated_' . bin2hex(random_bytes(3));
        $hash = password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT);
        $role = VIRTUSPHERE_ROLE_USER;
        $email = $name . '@example.test';
        $stmt = $this->db->prepare('INSERT INTO deploy_users (name, email, password, role, is_active, must_change_password) VALUES (?, ?, ?, ?, 1, 0)');
        $stmt->bind_param('ssss', $name, $email, $hash, $role);
        $stmt->execute();
        $userId = (int) $this->db->insert_id;
        try {
            session_start_secure();
            $_SESSION = ['user_id' => $userId, 'session_expires_at' => time() + 600, 'auth_source' => VIRTUSPHERE_AUTH_SOURCE_LOCAL];
            self::assertNotNull(current_user($this->db), 'precondition: an active account is signed in');

            $this->db->query('UPDATE deploy_users SET is_active = 0 WHERE id = ' . $userId);
            self::assertNull(current_user($this->db));
            self::assertArrayNotHasKey('user_id', $_SESSION, 'the session must be destroyed, not just answered with null');
            $row = $this->db->query("SELECT context_json FROM deploy_logs WHERE event_code = 'auth.session_ended' AND user_id = " . $userId . ' ORDER BY id DESC LIMIT 1')->fetch_assoc();
            self::assertIsArray($row);
            self::assertSame(VIRTUSPHERE_AUDIT_SESSION_REASON_ACCOUNT_DEACTIVATED, json_decode((string) $row['context_json'], true)['reason'] ?? null);

            $this->db->query('UPDATE deploy_users SET is_active = 1 WHERE id = ' . $userId);
            self::assertNull(current_user($this->db), 'reactivating the account does not revive the ended session');
        } finally {
            $_SESSION = [];
            $this->db->query('DELETE FROM deploy_logs WHERE user_id = ' . $userId);
            $this->db->query('DELETE FROM deploy_users WHERE id = ' . $userId);
        }
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
