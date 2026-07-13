<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/log.php';
require_once dirname(__DIR__, 2) . '/lib/repo/legacy.php';

/**
 * A legacy client that re-authenticates in a loop instead of caching its
 * hour-valid token used to write one deploy_logs row per request, flooding the
 * legacy_api channel. Successful issuance is now throttled per (user, IP) window
 * while every issuance still reaches error_log, and each success carries the
 * actor so the retained rows have audit value. Failed logins stay unthrottled.
 */
final class LegacyTokenAuditThrottleTest extends TestCase
{
    private const PREFIX = 'phpunit_tokenaudit_';
    private const PASSWORD = 'Str0ng&<>"pass!';
    private const IP = '203.0.113.77';

    private ?mysqli $db = null;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanup();
        $_SERVER['REMOTE_ADDR'] = self::IP;
        // The legacy API runs without a portal session, so addLog writes a NULL
        // user_id. A prior test in the suite can leave a since-deleted user_id in
        // the session, which would trip the deploy_logs FK; clear it to mirror
        // the real endpoint.
        $_SESSION = [];
        $this->createUser(self::PREFIX . 'alice');
        $this->createUser(self::PREFIX . 'bob');
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanup();
        }
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function testRepeatedSuccessFromOneIdentityCollapsesToOneRow(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertIsString(generateToken(self::PREFIX . 'alice', self::PASSWORD, $this->db));
        }

        // Five tokens were issued (each mints its own row in deploy_tokens), but
        // the audit channel keeps a single representative row for the window.
        self::assertSame(5, $this->tokenCount(self::PREFIX . 'alice'), 'every issuance must still mint a token');
        self::assertSame(1, $this->successRowCount(self::PREFIX . 'alice'), 'burst must collapse to one audit row');
    }

    public function testDifferentIdentitiesEachKeepTheirOwnRow(): void
    {
        generateToken(self::PREFIX . 'alice', self::PASSWORD, $this->db);
        generateToken(self::PREFIX . 'bob', self::PASSWORD, $this->db);

        // The throttle keys on the message, which names the actor, so a second
        // user in the same window is never hidden behind the first.
        self::assertSame(1, $this->successRowCount(self::PREFIX . 'alice'));
        self::assertSame(1, $this->successRowCount(self::PREFIX . 'bob'));
    }

    public function testFailedLoginIsNotThrottled(): void
    {
        for ($i = 0; $i < 3; $i++) {
            self::assertFalse(generateToken(self::PREFIX . 'alice', 'wrong-password', $this->db));
        }

        // Failures are the brute-force signal; each one stays on the record.
        self::assertSame(3, $this->failureRowCount(), 'failed logins must not be collapsed');
    }

    private function successRowCount(string $username): int
    {
        $message = 'Request: generateToken (user: ' . $username . ') | Auth-Token: [redacted]';

        return $this->countRows('log_message = ?', 's', [$message]);
    }

    private function failureRowCount(): int
    {
        return $this->countRows("log_message = 'Request: generateToken | Auth-Token: Invalid login credentials'", '', []);
    }

    /**
     * @param list<string> $params
     */
    private function countRows(string $condition, string $types, array $params): int
    {
        $category = VIRTUSPHERE_LOG_CATEGORY_LEGACY_API;
        $ip = self::IP;
        $sql = 'SELECT COUNT(*) AS c FROM deploy_logs WHERE category = ? AND ip = ? AND ' . $condition;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ss' . $types, $category, $ip, ...$params);
        $stmt->execute();

        return (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    }

    private function tokenCount(string $username): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM deploy_tokens t JOIN deploy_users u ON u.id = t.user_id WHERE u.name = ?');
        $stmt->bind_param('s', $username);
        $stmt->execute();

        return (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    }

    private function createUser(string $name): void
    {
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        $email = $name . '@phpunit.local';
        $role = VIRTUSPHERE_ROLE_USER;
        $stmt = $this->db->prepare('INSERT INTO deploy_users (name, email, password, role, is_active) VALUES (?, ?, ?, ?, 1)');
        $stmt->bind_param('ssss', $name, $email, $hash, $role);
        $stmt->execute();
    }

    private function cleanup(): void
    {
        $this->db->query("DELETE t FROM deploy_tokens t JOIN deploy_users u ON u.id = t.user_id WHERE u.name LIKE '" . self::PREFIX . "%'");
        $this->db->query("DELETE FROM deploy_users WHERE name LIKE '" . self::PREFIX . "%'");
        $this->db->query("DELETE FROM deploy_logs WHERE ip = '" . self::IP . "'");
    }
}
