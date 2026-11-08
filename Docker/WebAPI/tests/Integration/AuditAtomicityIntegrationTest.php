<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/request.php';
require_once dirname(__DIR__, 2) . '/lib/forms.php';
require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/layout_response.php';
require_once dirname(__DIR__, 2) . '/lib/audit_event_definitions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/log.php';
require_once dirname(__DIR__, 2) . '/lib/repo/api_access.php';
require_once dirname(__DIR__, 2) . '/lib/settings_allowlist_actions.php';
require_once dirname(__DIR__, 2) . '/lib/users_admin.php';

/**
 * W3A (R19): the settings and user actions that used to commit first and write an
 * unchecked audit_event() afterwards. Now the write and its audit_event_required() row
 * share one repo_transaction(), so a refused row rolls the change back.
 *
 * The realistic failing insert is an unknown actor: deploy_logs.user_id rejects it,
 * while none of these writes names the actor anywhere else.
 */
final class AuditAtomicityIntegrationTest extends TestCase
{
    private const UNKNOWN_USER = 2147483647;
    private const IP = '192.0.2.83'; // TEST-NET-1, never a real machine.

    private mysqli $db;
    private int $userId = 0;
    private string $token = '';
    /** @var list<int> */
    private array $createdUsers = [];
    /** @var array<string,mixed> */
    private array $postBefore = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        if ($this->userId <= 0) {
            self::markTestSkipped('No user exists for audit provenance.');
        }
        self::assertSame(0, (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_users WHERE id = ?', 'i', [self::UNKNOWN_USER]));
        $this->token = bin2hex(random_bytes(5));
        $this->postBefore = $_POST;
        $_SESSION['_flash'] = [];
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBefore;
        $_SESSION['_flash'] = [];
        if (!isset($this->db)) {
            return;
        }
        repo_execute($this->db, 'DELETE FROM deploy_accessToWebAPI WHERE ipAddress = ?', 's', [self::IP]);
        repo_execute($this->db, 'DELETE FROM deploy_logs WHERE event_code = ? AND object_id = ?', 'ss', [VIRTUSPHERE_AUDIT_EVENT_SETTINGS_MACHINE_IP, self::IP]);
        foreach ($this->createdUsers as $id) {
            repo_execute($this->db, "DELETE FROM deploy_logs WHERE object_type = 'user' AND object_id = ?", 's', [(string) $id]);
            repo_execute($this->db, 'DELETE FROM deploy_users WHERE id = ?', 'i', [$id]);
        }
        repo_execute($this->db, 'DELETE FROM deploy_users WHERE name = ?', 's', [$this->userName()]);
    }

    public function testARefusedRowRollsTheAllowlistEntryBack(): void
    {
        $_POST = ['ip_address' => self::IP, 'description' => 'phpunit w3a', 'role' => VIRTUSPHERE_MACHINE_API_ROLES[0]];
        settings_allowlist_handle_action($this->db, ['id' => self::UNKNOWN_USER], 'allow_create');

        self::assertSame(0, $this->allowlistEntries(), 'the entry rolled back with its refused audit row');
        self::assertSame(0, $this->allowlistRows());
        self::assertSame(['error'], array_column($_SESSION['_flash'] ?? [], 'type'));
    }

    public function testAnAllowlistEntryAndItsRowCommitTogether(): void
    {
        $_POST = ['ip_address' => self::IP, 'description' => 'phpunit w3a', 'role' => VIRTUSPHERE_MACHINE_API_ROLES[0]];
        settings_allowlist_handle_action($this->db, ['id' => $this->userId], 'allow_create');

        self::assertSame(1, $this->allowlistEntries());
        self::assertSame(1, $this->allowlistRows());
        self::assertSame(['success'], array_column($_SESSION['_flash'] ?? [], 'type'));
    }

    public function testARefusedRowKeepsTheDeletedAllowlistEntry(): void
    {
        $role = VIRTUSPHERE_MACHINE_API_ROLES[0];
        repo_execute($this->db, 'INSERT INTO deploy_accessToWebAPI (ipAddress, description, role) VALUES (?, ?, ?)', 'sss', [self::IP, 'phpunit w3a', $role]);
        $_POST = ['id' => (string) $this->db->insert_id];
        settings_allowlist_handle_action($this->db, ['id' => self::UNKNOWN_USER], 'allow_delete');

        self::assertSame(1, $this->allowlistEntries(), 'the delete rolled back with its refused audit row');
        self::assertSame(['error'], array_column($_SESSION['_flash'] ?? [], 'type'));
    }

    public function testARefusedRowRollsTheCreatedAccountBack(): void
    {
        $_POST = $this->createPost();
        try {
            users_handle_account_action($this->db, ['id' => self::UNKNOWN_USER], 'create');
            self::fail('The refused audit row did not surface.');
        } catch (Throwable) {
            // expected: the page shell turns it into an error flash
        }

        self::assertSame(0, (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_users WHERE name = ?', 's', [$this->userName()]), 'the account rolled back with its refused audit row');
    }

    public function testACreatedAccountAndItsRowCommitTogether(): void
    {
        $_POST = $this->createPost();
        self::assertTrue(users_handle_account_action($this->db, ['id' => $this->userId], 'create'));

        $id = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users WHERE name = ?', 's', [$this->userName()]);
        self::assertGreaterThan(0, $id);
        $this->createdUsers[] = $id;
        self::assertSame(1, (int) repo_scalar($this->db, "SELECT COUNT(*) FROM deploy_logs WHERE event_code = ? AND object_type = 'user' AND object_id = ?", 'ss', [VIRTUSPHERE_AUDIT_EVENT_USER_CREATED, (string) $id]));
    }

    public function testARefusedRowKeepsTheOldPasswordOnAnAdminReset(): void
    {
        $_POST = $this->createPost();
        users_handle_account_action($this->db, ['id' => $this->userId], 'create');
        $id = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users WHERE name = ?', 's', [$this->userName()]);
        $this->createdUsers[] = $id;
        $before = (string) repo_scalar($this->db, 'SELECT password FROM deploy_users WHERE id = ?', 'i', [$id]);

        $_POST = ['user_id' => (string) $id, 'password' => 'W3a-Reset-' . bin2hex(random_bytes(8))];
        try {
            users_handle_account_action($this->db, ['id' => self::UNKNOWN_USER], 'reset_password');
            self::fail('The refused audit row did not surface.');
        } catch (Throwable) {
            // expected
        }

        self::assertSame($before, (string) repo_scalar($this->db, 'SELECT password FROM deploy_users WHERE id = ?', 'i', [$id]), 'the reset rolled back with its refused audit row');
        self::assertSame(0, (int) repo_scalar($this->db, "SELECT COUNT(*) FROM deploy_logs WHERE event_code = ? AND object_type = 'user' AND object_id = ?", 'ss', [VIRTUSPHERE_AUDIT_EVENT_USER_SECURITY_CHANGED, (string) $id]));
    }

    private function userName(): string
    {
        return 'phpunit-w3a-' . $this->token;
    }

    /** @return array<string,string> */
    private function createPost(): array
    {
        return [
            'name' => $this->userName(),
            'email' => '',
            'password' => 'W3a-Synthetic-' . bin2hex(random_bytes(8)),
            'role' => VIRTUSPHERE_ROLE_USER,
        ];
    }

    private function allowlistEntries(): int
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_accessToWebAPI WHERE ipAddress = ?', 's', [self::IP]);
    }

    private function allowlistRows(): int
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_logs WHERE event_code = ? AND object_id = ?', 'ss', [VIRTUSPHERE_AUDIT_EVENT_SETTINGS_MACHINE_IP, self::IP]);
    }
}
