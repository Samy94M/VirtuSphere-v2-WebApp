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
require_once dirname(__DIR__, 2) . '/lib/credentials_status.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/esxi_inventory_scheduler.php';
require_once dirname(__DIR__, 2) . '/lib/repo/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
require_once dirname(__DIR__, 2) . '/lib/repo/esxi_inventory_state.php';
require_once dirname(__DIR__, 2) . '/lib/repo/settings.php';

/**
 * The ESXi trust mode through the POST dispatch of the credentials page
 * (credentials_handle_post): the audit rows and the flash of a create and of the
 * downgrade, the inventory pull a save or an activation enqueues, and the open
 * pull that disables the row button.
 *
 * Every case pins the one Ansible credential the pull runs over, so the result
 * does not depend on other Ansible credentials in the database. The previous
 * setting is restored in tearDown.
 */
final class CredentialTrustActionIntegrationTest extends TestCase
{
    private mysqli $db;
    private int $userId = 0;
    private int $ansibleId = 0;
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
        $this->token = bin2hex(random_bytes(5));
        $this->previousAnsibleSetting = repo_setting_value($this->db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL, '0');
        $this->ansibleId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 'ansible-' . $this->token . '.invalid', 22);
        repo_set_setting($this->db, VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL, (string) $this->ansibleId);
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
        }
        repo_execute($this->db, 'DELETE FROM deploy_jobs WHERE credential_ansible_id = ?', 'i', [$this->ansibleId]);
        foreach (array_reverse($this->credentialIds) as $id) {
            repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$id]);
        }
        repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$this->ansibleId]);
    }

    public function testCreateWithNoneAuditsTheLegacyModeAndCreateWithACertificateAuditsStrict(): void
    {
        $noneName = 'phpunit-trust-none-' . $this->token;
        $_POST = $this->post('create', [
            'type' => 'esxi',
            'name' => $noneName,
            'host' => 'https://esxi-none-' . $this->token . '.invalid',
            'port' => '443',
            'username' => 'root',
            'secret' => 'fixture-secret',
            'esxi_cert_kind' => VIRTUSPHERE_ESXI_CERT_NONE,
            'esxi_certificate_pem' => '',
        ]);
        self::assertSame('credentials.php', credentials_handle_post($this->db, $this->manager()));
        $noneId = $this->idByName($noneName);
        $this->credentialIds[] = $noneId;
        $created = $this->auditRows($noneId, 'created');
        self::assertCount(1, $created);
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, $created[0]['trust_mode']);

        $strictName = 'phpunit-trust-strict-' . $this->token;
        $_POST = $this->post('create', [
            'type' => 'esxi',
            'name' => $strictName,
            'host' => 'https://esxi-strict-' . $this->token . '.invalid',
            'port' => '443',
            'username' => 'root',
            'secret' => 'fixture-secret',
            'esxi_cert_kind' => VIRTUSPHERE_ESXI_CERT_CA_BUNDLE,
            'esxi_certificate_pem' => $this->certificate(),
        ]);
        credentials_handle_post($this->db, $this->manager());
        $strictId = $this->idByName($strictName);
        $this->credentialIds[] = $strictId;
        $created = $this->auditRows($strictId, 'created');
        self::assertCount(1, $created);
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_STRICT, $created[0]['trust_mode']);
    }

    public function testDowngradeWritesTheTrustChangeAuditAndTheWarningFlash(): void
    {
        $id = $this->esxiCredential(VIRTUSPHERE_ESXI_CERT_SERVER, $this->certificate());
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_STRICT, repo_credential($this->db, $id)['esxi_trust_mode']);

        $_POST = $this->post('update', $this->esxiForm($id, VIRTUSPHERE_ESXI_CERT_NONE, ''));
        self::assertSame('credentials.php', credentials_handle_post($this->db, $this->manager()));

        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, repo_credential($this->db, $id)['esxi_trust_mode']);
        $actions = array_column($this->auditRows($id), 'action');
        self::assertContains('updated', $actions);
        self::assertContains('trust_mode_changed', $actions);
        $trustRows = array_values(array_filter($this->auditRows($id), static fn (array $row): bool => $row['action'] === 'trust_mode_changed'));
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, $trustRows[0]['trust_mode']);

        $flashes = flash_messages();
        self::assertNotSame([], $flashes);
        self::assertSame('warning', $flashes[0]['type']);
        self::assertSame(__t('credentials.flash_verification_disabled'), $flashes[0]['message']);
    }

    public function testAVerifyingUpdateOfAStrictRowWritesNoDowngradeAudit(): void
    {
        $id = $this->esxiCredential(VIRTUSPHERE_ESXI_CERT_SERVER, $this->certificate());

        $_POST = $this->post('update', $this->esxiForm($id, VIRTUSPHERE_ESXI_CERT_CA_BUNDLE, $this->certificate()));
        credentials_handle_post($this->db, $this->manager());

        self::assertSame(VIRTUSPHERE_ESXI_TRUST_STRICT, repo_credential($this->db, $id)['esxi_trust_mode']);
        self::assertNotContains('trust_mode_changed', array_column($this->auditRows($id), 'action'));
        $flashes = flash_messages();
        self::assertSame('success', $flashes[0]['type'] ?? '');
    }

    public function testStagedCertificateOnALegacyRowEnqueuesAStrictProbe(): void
    {
        $id = $this->esxiCredential(VIRTUSPHERE_ESXI_CERT_NONE, '');
        repo_execute($this->db, 'DELETE FROM deploy_jobs WHERE credential_esxi_id = ?', 'i', [$id]);

        $_POST = $this->post('update', $this->esxiForm($id, VIRTUSPHERE_ESXI_CERT_CA_BUNDLE, $this->certificate()));
        credentials_handle_post($this->db, $this->manager());

        $job = $this->openJob($id);
        self::assertNotNull($job, 'the save enqueues one pull over the Ansible host');
        self::assertTrue(deploy_job_payload(json_decode((string) $job['payload_json'], true, 512, JSON_THROW_ON_ERROR))['strict_trust_probe']);
        self::assertSame(VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE, repo_credential($this->db, $id)['esxi_trust_mode']);
    }

    public function testActivatingStrictEnqueuesAPlainInventoryPull(): void
    {
        $id = $this->esxiCredential(VIRTUSPHERE_ESXI_CERT_CA_BUNDLE, $this->certificate(), VIRTUSPHERE_ESXI_TRUST_LEGACY_INSECURE);
        self::assertTrue(repo_record_esxi_strict_test_success($this->db, $id));
        repo_execute($this->db, 'DELETE FROM deploy_jobs WHERE credential_esxi_id = ?', 'i', [$id]);

        $_POST = $this->post('activate_strict', ['credential_id' => (string) $id]);
        credentials_handle_post($this->db, $this->manager());

        self::assertSame(VIRTUSPHERE_ESXI_TRUST_STRICT, repo_credential($this->db, $id)['esxi_trust_mode']);
        $job = $this->openJob($id);
        self::assertNotNull($job, 'a successful activation starts the pull a save starts');
        self::assertFalse(deploy_job_payload(json_decode((string) $job['payload_json'], true, 512, JSON_THROW_ON_ERROR))['strict_trust_probe']);
    }

    public function testOpenPullIsMarkedQueuedThenRunningAndAbsentWithoutOne(): void
    {
        $id = $this->esxiCredential(VIRTUSPHERE_ESXI_CERT_NONE, '');
        repo_execute($this->db, 'DELETE FROM deploy_jobs WHERE credential_esxi_id = ?', 'i', [$id]);
        self::assertNull($this->pendingNoticeFor($id), 'no open pull, no notice and no disabled button');

        $enqueued = esxi_inventory_enqueue_for_credential($this->db, $id, $this->userId);
        self::assertTrue($enqueued['enqueued']);
        $jobId = (int) $enqueued['job_id'];

        $notice = $this->pendingNoticeFor($id);
        self::assertNotNull($notice);
        self::assertSame($jobId, $notice['job_id']);
        self::assertFalse($notice['running'], 'a queued pull is not running yet');
        self::assertStringContainsString('#' . $jobId, $notice['title']);
        self::assertSame('deploy_log.php?id=' . $jobId, $notice['url']);

        repo_execute($this->db, 'UPDATE deploy_jobs SET status = ? WHERE id = ?', 'si', [VIRTUSPHERE_DEPLOY_STATUS_RUNNING, $jobId]);
        $notice = $this->pendingNoticeFor($id);
        self::assertNotNull($notice);
        self::assertTrue($notice['running']);
        self::assertSame(__t('credentials.inventory_running_line'), $notice['line']);

        // The server refuses a second pull with the same predicate: the safety net behind the disabled button.
        self::assertSame('already_pending', esxi_inventory_enqueue_for_credential($this->db, $id, $this->userId)['reason']);

        $otherId = $this->esxiCredential(VIRTUSPHERE_ESXI_CERT_NONE, '');
        self::assertNull($this->pendingNoticeFor($otherId), 'a pull of another credential does not mark this row');
    }

    /**
     * What the panel reads for one row: the one table-wide query, then the helper the row uses.
     *
     * @return array{job_id: int, running: bool, title: string, line: string, url: string}|null
     */
    private function pendingNoticeFor(int $credentialId): ?array
    {
        $jobs = repo_esxi_inventory_pending_jobs($this->db);

        return credential_inventory_pending_notice($jobs[$credentialId] ?? null);
    }

    private function esxiCredential(string $choice, string $pem, string $mode = VIRTUSPHERE_ESXI_TRUST_STRICT): int
    {
        $id = repo_create_credential($this->db, [
            'type' => 'esxi',
            'name' => 'phpunit-trust-action-' . bin2hex(random_bytes(4)),
            'host' => 'https://esxi-' . bin2hex(random_bytes(4)) . '.invalid',
            'port' => 443,
            'username' => 'root',
            'esxi_cert_kind' => $choice,
            'esxi_certificate_pem' => $pem,
        ], 'fixture-secret', $this->userId);
        $this->credentialIds[] = $id;
        if ($mode !== VIRTUSPHERE_ESXI_TRUST_STRICT) {
            // A legacy row with a stored certificate is what the staged activation starts from.
            repo_execute($this->db, 'UPDATE deploy_credentials SET esxi_trust_mode = ? WHERE id = ?', 'si', [$mode, $id]);
        }

        return $id;
    }

    private function insertCredential(string $type, string $host, int $port): int
    {
        $name = 'phpunit-trust-' . $type . '-' . $this->token;
        $username = 'svc';
        $ciphertext = 'sealed-' . $this->token;
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssiss', $type, $name, $host, $port, $username, $ciphertext);
        $stmt->execute();

        return (int) $this->db->insert_id;
    }

    /** @param array<string, string> $fields @return array<string, string> */
    private function post(string $action, array $fields): array
    {
        return array_merge(['action' => $action, '_csrf' => 'phpunit'], $fields);
    }

    /** @return array<string, string> */
    private function esxiForm(int $id, string $choice, string $pem): array
    {
        $row = repo_credential($this->db, $id) ?? [];

        return [
            'credential_id' => (string) $id,
            'type' => 'esxi',
            'name' => (string) ($row['name'] ?? ''),
            'host' => (string) ($row['host'] ?? ''),
            'port' => (string) ($row['port'] ?? ''),
            'username' => (string) ($row['username'] ?? ''),
            'secret' => '',
            'esxi_cert_kind' => $choice,
            'esxi_certificate_pem' => $pem,
        ];
    }

    /** @return array<string, mixed> */
    private function manager(): array
    {
        return ['id' => $this->userId, 'role' => VIRTUSPHERE_ROLE_ADMIN];
    }

    private function idByName(string $name): int
    {
        return (int) repo_scalar($this->db, 'SELECT id FROM deploy_credentials WHERE name = ?', 's', [$name]);
    }

    /** @return array<string, mixed>|null the open inventory job of one ESXi credential */
    private function openJob(int $credentialId): ?array
    {
        return repo_fetch_one(
            $this->db,
            "SELECT id, status, payload_json FROM deploy_jobs WHERE credential_esxi_id = ? AND mission_id IS NULL AND status IN ('queued', 'running', 'cancelling') AND cancelled_at IS NULL ORDER BY id DESC LIMIT 1",
            'i',
            [$credentialId]
        );
    }

    /**
     * The credential.changed audit rows of one credential, oldest first, decoded.
     *
     * @return list<array<string, mixed>>
     */
    private function auditRows(int $credentialId, ?string $action = null): array
    {
        $event = VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_CHANGED;
        $objectId = (string) $credentialId;
        $stmt = $this->db->prepare("SELECT context_json FROM deploy_logs WHERE event_code = ? AND object_type = 'credential' AND object_id = ? ORDER BY id");
        $stmt->bind_param('ss', $event, $objectId);
        $stmt->execute();
        $rows = [];
        foreach (repo_fetch_all($stmt->get_result()) as $row) {
            $context = json_decode((string) $row['context_json'], true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($context)) {
                continue;
            }
            if ($action === null || ($context['action'] ?? '') === $action) {
                $rows[] = $context;
            }
        }

        return $rows;
    }

    private function certificate(): string
    {
        $pem = file_get_contents(dirname(__DIR__) . '/fixtures/https/valid.crt.txt');
        self::assertIsString($pem);

        return $pem;
    }
}
