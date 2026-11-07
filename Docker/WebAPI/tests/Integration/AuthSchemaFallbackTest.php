<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/auth.php';
require_once dirname(__DIR__, 2) . '/lib/directory_config.php';
require_once dirname(__DIR__, 2) . '/lib/repo/helpers.php';

/**
 * C3: the auth readers try their current SQL form first and fall back to the
 * pre-0040 form only on a confirmed missing column/table (1054/1146). The old
 * column set is a TEMPORARY table on a connection of its own: it shadows the
 * real table for that connection only, so the rollout window ("image runs,
 * migration not yet") is reproduced without touching the shared schema.
 */
final class AuthSchemaFallbackTest extends TestCase
{
    private const PREFIX = 'phpunit_c3_';
    private const CLIENT_IP = '198.51.100.93';

    private ?mysqli $db = null;
    private ?mysqli $connection = null;
    private int $userId = 0;
    private string $userName = '';
    private string $password = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
            $this->connection = $this->openConnection();
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
        $_SERVER['REMOTE_ADDR'] = self::CLIENT_IP;

        // A real row, so the audit FK (deploy_logs.user_id) holds for the
        // shadow row that carries the same id.
        $this->userName = self::PREFIX . bin2hex(random_bytes(3));
        $hash = password_hash($this->password, PASSWORD_DEFAULT);
        $role = VIRTUSPHERE_ROLE_USER;
        $stmt = $this->db->prepare('INSERT INTO deploy_users (name, password, role, is_active, must_change_password) VALUES (?, ?, ?, 1, 0)');
        $stmt->bind_param('sss', $this->userName, $hash, $role);
        $stmt->execute();
        $this->userId = (int) $this->db->insert_id;

        session_start_secure();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }
        if ($this->db !== null) {
            $this->cleanup();
        }
        unset($_SERVER['REMOTE_ADDR']);
    }

    private function openConnection(): mysqli
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $connection = new mysqli(envboot_required('DB_HOST'), envboot_required('DB_USER'), envboot_required('DB_PASS'), envboot_required('DB_NAME'), (int) envboot_optional('DB_PORT', '3306'));
        $connection->set_charset('utf8mb4');
        $connection->query("SET time_zone = '+00:00'");

        return $connection;
    }

    private function cleanup(): void
    {
        if ($this->userId > 0) {
            $stmt = $this->db->prepare('DELETE FROM deploy_logs WHERE user_id = ?');
            $stmt->bind_param('i', $this->userId);
            $stmt->execute();
        }
        $this->db->query("DELETE FROM deploy_logs WHERE category = 'auth' AND ip = '" . self::CLIENT_IP . "'");
        $this->db->query("DELETE FROM deploy_login_attempts WHERE ip_address = '" . self::CLIENT_IP . "'");
        $this->db->query("DELETE FROM deploy_users WHERE name LIKE '" . self::PREFIX . "%'");
        $this->userId = 0;
    }

    /** The pre-0040 deploy_users column set, holding this test's account. */
    private function shadowLegacyUsers(mysqli $connection): void
    {
        $connection->query(
            "CREATE TEMPORARY TABLE deploy_users (
                id INT PRIMARY KEY,
                name VARCHAR(191) NOT NULL,
                password VARCHAR(255) NOT NULL,
                email VARCHAR(191) NULL,
                role ENUM('admin','user') NOT NULL DEFAULT 'user',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                must_change_password TINYINT(1) NOT NULL DEFAULT 0,
                password_changed_at TIMESTAMP NULL,
                last_seen_at TIMESTAMP NULL,
                locked_until TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB"
        );
        $hash = password_hash($this->password, PASSWORD_DEFAULT);
        $role = VIRTUSPHERE_ROLE_USER;
        $stmt = $connection->prepare('INSERT INTO deploy_users (id, name, password, role) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('isss', $this->userId, $this->userName, $hash, $role);
        $stmt->execute();
    }

    /** The pre-0040 deploy_login_attempts column set (no auth_source, no result). */
    private function shadowLegacyAttempts(mysqli $connection): void
    {
        $connection->query(
            'CREATE TEMPORARY TABLE deploy_login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                success TINYINT(1) NOT NULL DEFAULT 0,
                attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB'
        );
    }

    private function preparedStatements(mysqli $connection): int
    {
        $row = $connection->query("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'")->fetch_assoc();

        return (int) ($row['Value'] ?? -1);
    }

    private function signIn(): void
    {
        $_SESSION['user_id'] = $this->userId;
        $_SESSION['session_expires_at'] = time() + 600;
    }

    public function testTheCurrentSchemaIsReadWithoutAProbe(): void
    {
        $this->signIn();
        $before = $this->preparedStatements($this->connection);
        $user = current_user($this->connection);
        $afterRead = $this->preparedStatements($this->connection);

        self::assertNotNull($user);
        self::assertSame(VIRTUSPHERE_AUTH_SOURCE_LOCAL, (string) $user['auth_source']);
        self::assertSame(1, $afterRead - $before, 'only the user read itself, no schema probe ahead of it');
        self::assertTrue(auth_schema_known($this->connection, VIRTUSPHERE_SCHEMA_USER_SOURCE));

        self::assertTrue(auth_user_source_schema_available($this->connection));
        self::assertSame($afterRead, $this->preparedStatements($this->connection), 'a later decision answers from the verdict');
    }

    public function testTheLegacyUserColumnsFallBackAndAreRememberedForTheConnection(): void
    {
        $this->shadowLegacyUsers($this->connection);
        $this->signIn();

        $user = current_user($this->connection);
        self::assertNotNull($user);
        self::assertSame($this->userId, (int) $user['id']);
        self::assertSame(VIRTUSPHERE_AUTH_SOURCE_LOCAL, (string) $user['auth_source'], 'the old schema only knows local accounts');
        self::assertNull($user['ad_object_guid']);
        self::assertFalse(auth_schema_known($this->connection, VIRTUSPHERE_SCHEMA_USER_SOURCE));

        $before = $this->preparedStatements($this->connection);
        self::assertNotNull(current_user($this->connection));
        self::assertSame(1, $this->preparedStatements($this->connection) - $before, 'the next read goes straight to the older form');
        self::assertFalse(auth_user_source_schema_available($this->connection));

        // The verdict belongs to that connection only.
        self::assertTrue(auth_user_source_schema_available($this->db));
    }

    public function testADirectoryNeedsEveryFeatureAndTheVerdictsStayExact(): void
    {
        $this->shadowLegacyUsers($this->connection);

        self::assertFalse(directory_schema_available($this->connection));
        self::assertFalse(directory_is_enabled($this->connection));
        self::assertFalse(auth_schema_known($this->connection, VIRTUSPHERE_SCHEMA_USER_SOURCE));
        self::assertTrue(auth_schema_known($this->connection, VIRTUSPHERE_SCHEMA_ATTEMPT_SOURCE));
        self::assertTrue(auth_schema_known($this->connection, VIRTUSPHERE_SCHEMA_ATTEMPT_RESULT));
        self::assertTrue(auth_schema_known($this->connection, VIRTUSPHERE_SCHEMA_AD_CONFIG));

        self::assertTrue(directory_schema_available($this->db));
    }

    public function testALocalSignInWorksOnTheLegacySchema(): void
    {
        $this->shadowLegacyUsers($this->connection);
        $this->shadowLegacyAttempts($this->connection);

        $result = login($this->userName, $this->password, $this->connection);
        self::assertTrue($result['ok'] ?? false);
        self::assertSame(VIRTUSPHERE_AUTH_SOURCE_LOCAL, $_SESSION['auth_source'] ?? null);
        self::assertFalse(auth_schema_known($this->connection, VIRTUSPHERE_SCHEMA_ATTEMPT_SOURCE));
        self::assertFalse(auth_schema_known($this->connection, VIRTUSPHERE_SCHEMA_ATTEMPT_RESULT));

        self::assertSame(1, (int) repo_scalar($this->connection, 'SELECT COUNT(*) FROM deploy_login_attempts WHERE success = 1'), 'the attempt landed in the older column set');
        self::assertSame(1, (int) repo_scalar($this->connection, 'SELECT COUNT(*) FROM deploy_login_attempts'));
        $seen = repo_scalar($this->connection, 'SELECT last_seen_at FROM deploy_users WHERE id = ?', 'i', [$this->userId]);
        self::assertNotNull($seen);

        self::assertNotNull(current_user($this->connection));
    }

    public function testLegacyFailuresStillCountAndTheLockHelpersFallBack(): void
    {
        $this->shadowLegacyUsers($this->connection);
        $this->shadowLegacyAttempts($this->connection);

        $result = login($this->userName, 'wrong-password', $this->connection);
        self::assertFalse($result['ok'] ?? true);
        self::assertSame(1, auth_failed_attempt_count($this->connection, $this->userName));
        self::assertSame(1, auth_failed_ip_attempt_count($this->connection));

        self::assertSame([], auth_local_login_lock_ends($this->connection, [$this->userName]));
        self::assertSame(0, auth_clear_local_login_lock($this->connection, $this->userName));
        self::assertSame(1, (int) repo_scalar($this->connection, 'SELECT COUNT(*) FROM deploy_login_attempts'), 'the fallback removed nothing');
    }

    public function testTheCurrentSchemaClearsALockThroughTheTriedForm(): void
    {
        for ($i = 0; $i < VIRTUSPHERE_LOGIN_USER_FAILURE_LIMIT; $i++) {
            login($this->userName, 'wrong-password', $this->connection);
        }
        self::assertArrayHasKey($this->userName, auth_local_login_lock_ends($this->connection, [$this->userName]));
        self::assertSame(VIRTUSPHERE_LOGIN_USER_FAILURE_LIMIT, auth_clear_local_login_lock($this->connection, $this->userName));
        self::assertSame([], auth_local_login_lock_ends($this->connection, [$this->userName]));
        self::assertTrue(auth_schema_known($this->connection, VIRTUSPHERE_SCHEMA_ATTEMPT_RESULT));
    }

    public function testAFailedTryLeavesTheOpenTransactionWhole(): void
    {
        $this->shadowLegacyAttempts($this->connection);
        $connection = $this->connection;

        try {
            repo_transaction($connection, function () use ($connection): void {
                $connection->query("INSERT INTO deploy_login_attempts (username, ip_address, success) VALUES ('c3-probe', '" . self::CLIENT_IP . "', 0)");
                self::assertSame(0, auth_clear_local_login_lock($connection, $this->userName));
                self::assertSame(1, (int) repo_scalar($connection, 'SELECT COUNT(*) FROM deploy_login_attempts'));

                throw new RuntimeException('roll back');
            });
            self::fail('the transaction must roll back');
        } catch (RuntimeException $exception) {
            self::assertSame('roll back', $exception->getMessage());
        }

        self::assertSame(0, (int) repo_scalar($connection, 'SELECT COUNT(*) FROM deploy_login_attempts'), 'the failed try neither committed nor split the transaction');
    }

    public function testAnotherDatabaseErrorPropagatesUnchanged(): void
    {
        $legacyRan = false;
        try {
            auth_schema_attempt(
                $this->connection,
                [VIRTUSPHERE_SCHEMA_USER_SOURCE],
                static fn (): never => throw new mysqli_sql_exception('synthetic lock wait timeout', 1205),
                static function () use (&$legacyRan): string {
                    $legacyRan = true;

                    return 'legacy';
                },
            );
            self::fail('a lock wait timeout must not be answered by the older form');
        } catch (mysqli_sql_exception $exception) {
            self::assertSame(1205, $exception->getCode());
        }
        self::assertFalse($legacyRan);
        self::assertNull(auth_schema_known($this->connection, VIRTUSPHERE_SCHEMA_USER_SOURCE), 'a foreign error is no verdict');
    }

    public function testAnUnknownColumnThatIsNotTheFeatureIsNotMasked(): void
    {
        $connection = $this->connection;
        try {
            auth_schema_attempt(
                $connection,
                [VIRTUSPHERE_SCHEMA_USER_SOURCE],
                static fn (): ?array => repo_fetch_one($connection, 'SELECT c3_no_such_column FROM deploy_users LIMIT 1'),
                static fn (): ?array => null,
            );
            self::fail('a typo in the statement must surface');
        } catch (mysqli_sql_exception $exception) {
            self::assertSame(1054, $exception->getCode());
        }
        self::assertTrue(auth_schema_known($connection, VIRTUSPHERE_SCHEMA_USER_SOURCE), 'the probe confirmed the feature exists');
    }
}
