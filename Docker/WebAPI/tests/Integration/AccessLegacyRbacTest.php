<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/repo/log.php';
require_once dirname(__DIR__, 2) . '/lib/repo/legacy.php';

/**
 * The legacy token API (access.php) authenticates by token only. This test
 * pins the RBAC gate added on top of it: a token issued to a user-role account
 * must not be able to run catalog writes the portal denies that role, while an
 * admin token still can. It also covers the api/login.php fix that must accept
 * passwords containing HTML-special characters.
 */
final class AccessLegacyRbacTest extends TestCase
{
    private const PREFIX = 'phpunit_rbac_';
    private const PASSWORD = 'Str0ng&<>"pass!';

    private ?mysqli $db = null;

    protected function setUp(): void
    {
        $health = @file_get_contents(virtusphere_test_base_url() . '/portal/health.php');
        if ($health === false) {
            self::markTestSkipped('VirtuSphere test stack is not reachable.');
        }
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->cleanupTestRows();
        $this->createUser(self::PREFIX . 'user', VIRTUSPHERE_ROLE_USER);
        $this->createUser(self::PREFIX . 'admin', VIRTUSPHERE_ROLE_ADMIN);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->cleanupTestRows();
        }
    }

    public function testUserRoleTokenCannotCreateOsViaLegacyApi(): void
    {
        $token = generateToken(self::PREFIX . 'user', self::PASSWORD, $this->db);
        self::assertIsString($token);

        [$status, $body] = $this->get('/access.php', [
            'token' => $token,
            'action' => 'createOS',
            'osName' => self::PREFIX . 'os_denied',
            'osStatus' => 'active',
        ]);

        self::assertSame(403, $status, $body);
        self::assertNull($this->osRow(self::PREFIX . 'os_denied'), 'no OS row may be created for a denied request');
    }

    public function testAdminRoleTokenCanCreateOsViaLegacyApi(): void
    {
        $token = generateToken(self::PREFIX . 'admin', self::PASSWORD, $this->db);
        self::assertIsString($token);

        $osName = self::PREFIX . 'os_allowed';
        [$status, $body] = $this->get('/access.php', [
            'token' => $token,
            'action' => 'createOS',
            'osName' => $osName,
            'osStatus' => 'active',
        ]);

        self::assertNotSame(403, $status, 'admin token must pass the RBAC gate: ' . $body);
        self::assertSame(200, $status, $body);
        self::assertNotNull($this->osRow($osName), 'admin createOS should persist the row');
    }

    public function testLoginAcceptsSpecialCharacterPassword(): void
    {
        [$status, $body] = $this->postForm('/api/login.php', [
            'username' => self::PREFIX . 'user',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(200, $status, $body);
        $decoded = json_decode($body, true);
        self::assertIsString($decoded, 'login must return a token string');
        self::assertNotSame('Access Forbidden', $decoded);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $decoded, 'token should be 32 random bytes hex-encoded');
    }

    private function createUser(string $name, string $role): void
    {
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        $email = $name . '@phpunit.local';
        $stmt = $this->db->prepare('INSERT INTO deploy_users (name, email, password, role, is_active) VALUES (?, ?, ?, ?, 1)');
        $stmt->bind_param('ssss', $name, $email, $hash, $role);
        $stmt->execute();
    }

    private function osRow(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT id FROM deploy_os WHERE os_name = ? LIMIT 1');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return is_array($row) ? $row : null;
    }

    private function cleanupTestRows(): void
    {
        // Drop tokens of the temp users first (FK ON DELETE SET NULL would keep
        // orphans otherwise), then the users, then any OS rows they created.
        $this->db->query("DELETE t FROM deploy_tokens t JOIN deploy_users u ON u.id = t.user_id WHERE u.name LIKE '" . self::PREFIX . "%'");
        $this->db->query("DELETE FROM deploy_users WHERE name LIKE '" . self::PREFIX . "%'");
        $this->db->query("DELETE FROM deploy_os WHERE os_name LIKE '" . self::PREFIX . "%'");
    }

    /**
     * @param array<string, string> $query
     * @return array{0:int,1:string}
     */
    private function get(string $path, array $query): array
    {
        $url = virtusphere_test_base_url() . $path . '?' . http_build_query($query);
        $context = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 10]]);
        $body = @file_get_contents($url, false, $context);

        return [$this->statusFromHeaders($http_response_header ?? []), $body === false ? '' : $body];
    }

    /**
     * @param array<string, string> $fields
     * @return array{0:int,1:string}
     */
    private function postForm(string $path, array $fields): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($fields),
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);
        $body = @file_get_contents(virtusphere_test_base_url() . $path, false, $context);

        return [$this->statusFromHeaders($http_response_header ?? []), $body === false ? '' : $body];
    }

    /**
     * @param list<string> $headers
     */
    private function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return $status ?? 0;
    }
}
