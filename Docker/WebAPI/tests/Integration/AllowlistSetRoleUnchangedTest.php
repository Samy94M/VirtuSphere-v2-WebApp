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

/**
 * K20-A1 edge case: "set the role" on an entry that already has that role is an
 * unchanged answer. It writes no row and records no role_assigned audit row.
 * The session counter Com_update shows whether an UPDATE was sent at all; the
 * row contents cannot show it when the value stays the same.
 */
final class AllowlistSetRoleUnchangedTest extends TestCase
{
    private const IP = '192.0.2.79'; // TEST-NET-2, never a real machine.

    private mysqli $db;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database not reachable: ' . $exception->getMessage());
        }
        $_POST = [];
        $_SESSION['_flash'] = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION['_flash'] = [];
        if (isset($this->db)) {
            repo_execute($this->db, 'DELETE FROM deploy_accessToWebAPI WHERE ipAddress = ?', 's', [self::IP]);
            repo_execute($this->db, 'DELETE FROM deploy_logs WHERE event_code = ? AND object_id = ?', 'ss', [VIRTUSPHERE_AUDIT_EVENT_SETTINGS_MACHINE_IP, self::IP]);
        }
    }

    public function testSettingTheRoleAnEntryAlreadyHasChangesNothingAndRecordsNothing(): void
    {
        $entryId = $this->insertEntry('client');
        $user = repo_fetch_one($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        self::assertNotNull($user, 'the suite needs a portal user to attribute the answer to');
        $_POST = ['id' => (string) $entryId, 'role' => 'client'];

        $updatesBefore = $this->sessionCounter('Com_update');
        settings_allowlist_handle_action($this->db, ['id' => (int) $user['id']], 'allow_set_role');
        $updatesAfter = $this->sessionCounter('Com_update');

        self::assertSame($updatesBefore, $updatesAfter, 'the role the entry already has is not written again');
        self::assertSame(0, $this->roleAssignedRows(), 'and no role_assigned audit row is recorded');
        $row = repo_fetch_one($this->db, 'SELECT role FROM deploy_accessToWebAPI WHERE id = ?', 'i', [$entryId]);
        self::assertSame('client', $row['role'] ?? null, 'the entry keeps its role');
        $flashes = $_SESSION['_flash'] ?? [];
        self::assertCount(1, $flashes, 'the answer is one flash');
        self::assertSame('info', $flashes[0]['type'] ?? null, 'unchanged is information, not a success');
    }

    private function insertEntry(string $role): int
    {
        $ip = self::IP;
        $description = 'phpunit k20 unchanged role';
        $stmt = $this->db->prepare('INSERT INTO deploy_accessToWebAPI (ipAddress, description, role) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $ip, $description, $role);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    private function sessionCounter(string $name): int
    {
        $result = $this->db->query("SHOW SESSION STATUS LIKE '" . $name . "'");
        $row = $result instanceof mysqli_result ? $result->fetch_row() : null;

        return (int) ($row[1] ?? 0);
    }

    private function roleAssignedRows(): int
    {
        $row = repo_fetch_one(
            $this->db,
            'SELECT COUNT(*) AS c FROM deploy_logs WHERE event_code = ? AND object_id = ? AND context_json LIKE ?',
            'sss',
            [VIRTUSPHERE_AUDIT_EVENT_SETTINGS_MACHINE_IP, self::IP, '%role_assigned%']
        );

        return (int) ($row['c'] ?? 0);
    }
}
