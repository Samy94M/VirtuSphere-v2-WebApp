<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/permissions.php';
require_once dirname(__DIR__, 2) . '/lib/audit_registry.php';
require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/layout_response.php';
require_once dirname(__DIR__, 2) . '/lib/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/request.php';
require_once dirname(__DIR__, 2) . '/lib/credentials_actions.php';
require_once dirname(__DIR__, 2) . '/lib/repo/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/repo/settings.php';

/**
 * AL-1 (R19): a credential write of the credentials page and its credential.changed row
 * commit or roll back together. Before, the write committed first and the unchecked
 * audit_event() ran after it, so a failed audit insert left a change nobody could
 * attribute. A delete from a stale page (the row is already gone) writes no line and
 * says so in the portal language; before, it showed the raw English repo diagnostic.
 *
 * The realistic failing insert is an unknown user: deploy_logs.user_id rejects it, while
 * update, activation and delete write nothing else that names the user.
 */
final class CredentialAuditTransactionIntegrationTest extends TestCase
{
    private const UNKNOWN_USER = 2147483647;

    private mysqli $db;
    private int $userId = 0;
    private string $token = '';
    private string $previousAnsibleSetting = '0';
    /** @var list<int> */
    private array $credentialIds = [];
    /** @var array<string, mixed> */
    private array $postBefore = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        if ($this->userId <= 0) {
            self::markTestSkipped('No user exists for audit provenance.');
        }
        self::assertSame(0, (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_users WHERE id = ?', 'i', [self::UNKNOWN_USER]));
        $this->token = bin2hex(random_bytes(5));
        $this->previousAnsibleSetting = repo_setting_value($this->db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL, '0');
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_start();
        }
        unset($_SESSION['_flash']);
        $this->postBefore = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBefore;
        unset($_SESSION['_flash']);
        if (!isset($this->db)) {
            return;
        }
        repo_set_setting($this->db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL, $this->previousAnsibleSetting);
        foreach ($this->credentialIds as $id) {
            repo_execute($this->db, 'DELETE FROM deploy_jobs WHERE credential_esxi_id = ? OR credential_ansible_id = ?', 'ii', [$id, $id]);
            repo_execute($this->db, "DELETE FROM deploy_logs WHERE object_type = 'credential' AND object_id = ?", 's', [(string) $id]);
            repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$id]);
        }
    }

    public function testARefusedAuditKeepsTheDeletedCredentialAndTheInventorySelection(): void
    {
        $id = $this->ansibleCredential();
        repo_set_setting($this->db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL, (string) $id);

        $_POST = $this->post('delete', ['credential_id' => (string) $id]);
        credentials_handle_post($this->db, $this->user(self::UNKNOWN_USER));

        self::assertNotNull(repo_credential($this->db, $id), 'the delete rolled back with its refused audit row');
        self::assertSame((string) $id, repo_setting_value($this->db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL, '0'), 'the cleared selection rolled back too');
        self::assertSame([], $this->auditActions($id));
        self::assertSame(['error'], array_column(flash_messages(), 'type'));
    }

    public function testDeletingAMissingCredentialWritesNoRowAndReportsIt(): void
    {
        $id = $this->ansibleCredential();
        repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$id]);

        $_POST = $this->post('delete', ['credential_id' => (string) $id]);
        credentials_handle_post($this->db, $this->user($this->userId));

        self::assertSame([], $this->auditActions($id), 'no "deleted" line for a row that was already gone');
        $flashes = flash_messages();
        self::assertSame(['error'], array_column($flashes, 'type'));
        self::assertSame(__t('credentials.err_not_found'), (string) $flashes[0]['message']);
    }

    public function testADeleteWritesTheRowAndItsAuditTogether(): void
    {
        $id = $this->ansibleCredential();

        $_POST = $this->post('delete', ['credential_id' => (string) $id]);
        credentials_handle_post($this->db, $this->user($this->userId));

        self::assertNull(repo_credential($this->db, $id));
        self::assertSame(['deleted'], $this->auditActions($id));
        self::assertSame(['success'], array_column(flash_messages(), 'type'));
    }

    public function testARefusedAuditRollsTheUpdateBack(): void
    {
        $id = $this->ansibleCredential();
        $before = repo_credential($this->db, $id) ?? [];

        $_POST = $this->post('update', [
            'credential_id' => (string) $id,
            'type' => 'ansible',
            'name' => 'phpunit-al1-renamed-' . $this->token,
            'host' => (string) $before['host'],
            'port' => (string) $before['port'],
            'username' => (string) $before['username'],
            'secret' => '',
        ]);
        credentials_handle_post($this->db, $this->user(self::UNKNOWN_USER));

        $after = repo_credential($this->db, $id) ?? [];
        self::assertSame((string) $before['name'], (string) $after['name'], 'the rename rolled back with its refused audit row');
        self::assertSame((int) $before['config_revision'], (int) $after['config_revision']);
        self::assertSame([], $this->auditActions($id));
        self::assertSame(['error'], array_column(flash_messages(), 'type'));
    }

    public function testARefusedAuditRollsTheStrictActivationBack(): void
    {
        $id = repo_create_credential($this->db, [
            'type' => 'esxi',
            'name' => 'phpunit-al1-esxi-' . $this->token,
            'host' => 'https://esxi-al1-' . $this->token . '.invalid',
            'port' => 443,
            'username' => 'root',
            'esxi_cert_kind' => VIRTUSPHERE_ESXI_CERT_CA_BUNDLE,
            'esxi_certificate_pem' => $this->certificate(),
        ], 'fixture-secret', $this->userId);
        $this->credentialIds[] = $id;
        repo_execute($this->db, 'UPDATE deploy_credentials SET esxi_trust_mode = ? WHERE id = ?', 'si', [VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, $id]);
        self::assertTrue(repo_record_esxi_strict_test_success($this->db, $id));

        $_POST = $this->post('activate_strict', ['credential_id' => (string) $id]);
        credentials_handle_post($this->db, $this->user(self::UNKNOWN_USER));

        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, (string) (repo_credential($this->db, $id) ?? [])['esxi_trust_mode']);
        self::assertSame([], $this->auditActions($id));
        self::assertSame(['error'], array_column(flash_messages(), 'type'));
    }

    private function ansibleCredential(): int
    {
        $name = 'phpunit-al1-ansible-' . $this->token . '-' . count($this->credentialIds);
        $host = 'ansible-al1-' . $this->token . '.invalid';
        $port = 22;
        $username = 'svc';
        $ciphertext = 'sealed-' . $this->token;
        $type = VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE;
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssiss', $type, $name, $host, $port, $username, $ciphertext);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $this->credentialIds[] = $id;

        return $id;
    }

    /** @param array<string, string> $fields @return array<string, string> */
    private function post(string $action, array $fields): array
    {
        return array_merge(['action' => $action, '_csrf' => 'phpunit'], $fields);
    }

    /** @return array<string, mixed> */
    private function user(int $id): array
    {
        return ['id' => $id, 'role' => VIRTUSPHERE_ROLE_ADMIN];
    }

    /** @return list<string> the actions of the credential.changed rows of one credential, oldest first */
    private function auditActions(int $credentialId): array
    {
        $event = VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_CHANGED;
        $objectId = (string) $credentialId;
        $stmt = $this->db->prepare("SELECT context_json FROM deploy_logs WHERE event_code = ? AND object_type = 'credential' AND object_id = ? ORDER BY id");
        $stmt->bind_param('ss', $event, $objectId);
        $stmt->execute();
        $actions = [];
        foreach (repo_fetch_all($stmt->get_result()) as $row) {
            $context = json_decode((string) $row['context_json'], true, 8, JSON_THROW_ON_ERROR);
            $actions[] = is_array($context) ? (string) ($context['action'] ?? '') : '';
        }

        return $actions;
    }

    private function certificate(): string
    {
        $pem = file_get_contents(dirname(__DIR__) . '/fixtures/https/valid.crt.txt');
        self::assertIsString($pem);

        return $pem;
    }
}
