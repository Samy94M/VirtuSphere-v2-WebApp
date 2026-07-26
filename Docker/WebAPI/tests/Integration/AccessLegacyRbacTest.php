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

    public function testDeactivatingTheOwnerInvalidatesAnAlreadyIssuedToken(): void
    {
        // The asymmetry this pins sat inside one file: token ISSUANCE checked
        // is_active, token VERIFICATION did not. The portal refuses the account
        // on its next click, so nothing showed the gap; meanwhile the resolved
        // fallback role held missions.write, vms.write and deploy.run, and
        // expandToken renewed the 60-minute window on every poll.
        $token = generateToken(self::PREFIX . 'user', self::PASSWORD, $this->db);
        self::assertIsString($token);
        self::assertTrue(verifyToken($token, $this->db), 'a fresh token of an active owner must work');

        $this->setActive(self::PREFIX . 'user', 0);

        self::assertFalse(verifyToken($token, $this->db), 'a deactivated owner keeps no usable token');
        self::assertNull(legacyTokenRole($token, $this->db), 'an unresolvable owner is a deny, not a fallback role');

        // Over the wire: the same 418 every invalid token has always produced,
        // for a read, for a write and for the renewal that made the window
        // unbounded. No new response shape on this frozen surface.
        foreach (['getMissions', 'createMission', 'expandToken'] as $action) {
            [$status] = $this->get('/access.php', ['token' => $token, 'action' => $action]);
            self::assertSame(418, $status, $action . ' must be refused for a deactivated owner');
        }

        // The row is untouched: the read path refuses on its own, without needing
        // the table to change. Revoking the row is the portal handler's half and
        // has its own test below, so this one cannot pass by accident through it.
        self::assertSame(1, $this->unexpiredTokenCount(self::PREFIX . 'user'), 'the read path alone must refuse');
    }

    public function testAnUnattributableTokenCannotAct(): void
    {
        // fk_deploy_tokens_user is ON DELETE SET NULL, so a deleted owner leaves
        // a row that used to satisfy the old token-only predicate. A credential
        // nobody owns must not carry write scopes.
        $token = generateToken(self::PREFIX . 'user', self::PASSWORD, $this->db);
        self::assertIsString($token);

        $stmt = $this->db->prepare('UPDATE deploy_tokens SET user_id = NULL WHERE token = ?');
        $stmt->bind_param('s', $token);
        $stmt->execute();

        self::assertFalse(verifyToken($token, $this->db));
        self::assertNull(legacyTokenRole($token, $this->db));
    }

    public function testExpiredTokenCannotBeResurrectedByRenewal(): void
    {
        // expandToken() sets expired = 0. Without its own guard it would undo a
        // revocation whenever a caller reached it before the verification did.
        $token = generateToken(self::PREFIX . 'user', self::PASSWORD, $this->db);
        self::assertIsString($token);

        $userId = $this->userId(self::PREFIX . 'user');
        self::assertGreaterThan(0, repo_legacy_expire_user_tokens($this->db, $userId));
        self::assertSame(0, repo_legacy_expire_user_tokens($this->db, $userId), 'a second run has nothing left to expire');

        self::assertFalse(expandToken($token, $this->db), 'a revoked token must not renew itself');
        self::assertFalse(verifyToken($token, $this->db));
    }

    public function testReactivatingDoesNotBringRevokedTokensBack(): void
    {
        $token = generateToken(self::PREFIX . 'user', self::PASSWORD, $this->db);
        self::assertIsString($token);
        $userId = $this->userId(self::PREFIX . 'user');
        repo_legacy_expire_user_tokens($this->db, $userId);

        $this->setActive(self::PREFIX . 'user', 1);

        self::assertFalse(verifyToken($token, $this->db), 'reactivating restores the account, not its old credentials');
    }

    private function setActive(string $name, int $active): void
    {
        $stmt = $this->db->prepare('UPDATE deploy_users SET is_active = ? WHERE name = ?');
        $stmt->bind_param('is', $active, $name);
        $stmt->execute();
    }

    private function userId(string $name): int
    {
        $stmt = $this->db->prepare('SELECT id FROM deploy_users WHERE name = ? LIMIT 1');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        self::assertIsArray($row);

        return (int) $row['id'];
    }

    private function unexpiredTokenCount(string $name): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS n FROM deploy_tokens t JOIN deploy_users u ON u.id = t.user_id '
            . 'WHERE u.name = ? AND t.expired = 0 AND t.created_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)'
        );
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return is_array($row) ? (int) $row['n'] : 0;
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
