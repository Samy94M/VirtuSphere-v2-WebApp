<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/credentials_actions.php';

/**
 * The manual test result is one operator-visible fact: current evidence, its
 * typed audit, and its localized flash must agree. These tests use the real
 * database finalizer while replacing only the already completed remote result.
 */
final class CredentialTestCompletionIntegrationTest extends TestCase
{
    private mysqli $db;
    private int $userId;
    private int $credentialId;
    private string $credentialName;

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->userId = (int) ($this->db->query('SELECT id FROM deploy_users ORDER BY id LIMIT 1')->fetch_assoc()['id'] ?? 0);
        if ($this->userId <= 0) {
            self::markTestSkipped('No user exists for credential provenance.');
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_start();
        }
        unset($_SESSION['_flash']);
        $this->credentialName = 'phpunit_credential_test_completion_' . bin2hex(random_bytes(5));
        $this->credentialId = repo_create_credential(
            $this->db,
            $this->payload('ansible-a.example.test'),
            'fixture-secret',
            $this->userId
        );
    }

    protected function tearDown(): void
    {
        Lang::load(Lang::DEFAULT_LOCALE);
        unset($_SESSION['_flash']);
        if (!isset($this->db, $this->credentialId)) {
            return;
        }
        $stmt = $this->db->prepare('DELETE FROM deploy_logs WHERE event_code = ? AND object_type = ? AND object_id = ?');
        $event = VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_TESTED;
        $objectType = 'credential';
        $objectId = (string) $this->credentialId;
        $stmt->bind_param('sss', $event, $objectType, $objectId);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM deploy_credentials WHERE id = ?');
        $stmt->bind_param('i', $this->credentialId);
        $stmt->execute();
    }

    /**
     * @param array{ok: bool, code: string, detail: string, context: array<string, string|int>} $result
     * @param array<string,mixed> $expectedContext
     */
    #[DataProvider('storedResults')]
    public function testCurrentResultPersistsWithMatchingAuditAndLocalizedFlash(
        string $locale,
        array $result,
        string $expectedStatus,
        string $expectedAuditResult,
        array $expectedContext,
        string $messageKey,
        string $flashType
    ): void {
        Lang::load($locale);
        $revision = (int) repo_credential($this->db, $this->credentialId)['config_revision'];
        $generation = repo_ansible_preflight_begin($this->db, $this->credentialId, $revision);

        credentials_complete_ansible_test(
            $this->db,
            $this->credentialId,
            $this->userId,
            $result,
            $revision,
            $generation
        );

        $state = repo_ansible_preflight_state($this->db, $this->credentialId);
        self::assertNotNull($state);
        self::assertSame($expectedStatus, $state['last_status']);
        self::assertSame($generation, (int) $state['test_generation']);
        self::assertTrue($state['evidence_current']);
        $audit = $this->latestAudit();
        self::assertSame($expectedAuditResult, $audit['result']);
        self::assertSame($expectedContext, json_decode((string) $audit['context_json'], true, 8, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('tested credential id ' . $this->credentialId . ':', (string) $audit['log_message']);
        $flash = $this->onlyFlash();
        self::assertSame($flashType, $flash['type']);
        self::assertSame(__t($messageKey, $result['context']), $flash['message']);
        self::assertSame($result['ok'] ? '' : $result['detail'], $flash['detail']);
        self::assertStringNotContainsString('Audit context field is not allowed', $flash['message']);
    }

    /** @return iterable<string,array{string,array{ok:bool,code:string,detail:string,context:array<string,string|int>},string,string,array<string,mixed>,string,string}> */
    public static function storedResults(): iterable
    {
        foreach (Lang::LOCALES as $locale) {
            yield $locale . ' success' => [
                $locale,
                credential_test_result(true, VIRTUSPHERE_CREDENTIAL_TEST_OK),
                VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK,
                VIRTUSPHERE_AUDIT_RESULT_SUCCESS,
                ['evidence_stored' => true, 'outcome' => VIRTUSPHERE_CREDENTIAL_TEST_OK],
                'credentials.test_ok_ansible',
                'success',
            ];
            yield $locale . ' warning' => [
                $locale,
                credential_test_result(true, VIRTUSPHERE_CREDENTIAL_TEST_ALLOWLIST, '', ['ip' => '192.0.2.15']),
                VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_WARNING,
                VIRTUSPHERE_AUDIT_RESULT_WARNING,
                ['component' => VIRTUSPHERE_CREDENTIAL_TEST_ALLOWLIST, 'evidence_stored' => true, 'ip' => '192.0.2.15', 'outcome' => VIRTUSPHERE_CREDENTIAL_TEST_ALLOWLIST],
                'credentials.test_warn_allowlist',
                'warning',
            ];
            yield $locale . ' failure' => [
                $locale,
                credential_test_result(false, VIRTUSPHERE_CREDENTIAL_TEST_SFTP, 'bounded SFTP diagnostic'),
                VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_FAILED,
                VIRTUSPHERE_AUDIT_RESULT_FAILURE,
                ['component' => VIRTUSPHERE_CREDENTIAL_TEST_SFTP, 'evidence_stored' => true, 'outcome' => VIRTUSPHERE_CREDENTIAL_TEST_SFTP],
                'credentials.test_err_sftp',
                'error',
            ];
        }
    }

    #[DataProvider('discardedResults')]
    public function testStaleResultWritesOnlyDiscardedAuditAndLocalizedFlash(string $locale, string $staleAxis): void
    {
        Lang::load($locale);
        $revision = (int) repo_credential($this->db, $this->credentialId)['config_revision'];
        $generation = repo_ansible_preflight_begin($this->db, $this->credentialId, $revision);
        if ($staleAxis === 'configuration') {
            repo_update_credential($this->db, $this->credentialId, $this->payload('ansible-b.example.test'), 'rotated-secret');
        } else {
            $newerGeneration = repo_ansible_preflight_begin($this->db, $this->credentialId, $revision);
            self::assertTrue(repo_ansible_preflight_record(
                $this->db,
                $this->credentialId,
                VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK,
                null,
                $revision,
                $newerGeneration
            ));
        }

        $discardedDetail = 'must not be shown for discarded evidence';
        credentials_complete_ansible_test(
            $this->db,
            $this->credentialId,
            $this->userId,
            credential_test_result(false, VIRTUSPHERE_CREDENTIAL_TEST_SFTP, $discardedDetail),
            $revision,
            $generation
        );

        $state = repo_ansible_preflight_state($this->db, $this->credentialId);
        if ($staleAxis === 'configuration') {
            self::assertNull($state);
        } else {
            self::assertNotNull($state);
            self::assertSame(VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK, $state['last_status']);
            self::assertGreaterThan($generation, (int) $state['test_generation']);
            self::assertTrue($state['evidence_current']);
        }
        $audit = $this->latestAudit();
        self::assertSame(VIRTUSPHERE_AUDIT_RESULT_WARNING, $audit['result']);
        self::assertSame(
            ['evidence_stored' => false, 'outcome' => 'discarded'],
            json_decode((string) $audit['context_json'], true, 8, JSON_THROW_ON_ERROR)
        );
        self::assertSame('tested credential id ' . $this->credentialId . ': discarded', $audit['log_message']);
        self::assertStringNotContainsString($discardedDetail, (string) $audit['context_json']);
        $flash = $this->onlyFlash();
        self::assertSame('warning', $flash['type']);
        self::assertSame(__t('credentials.test_result_discarded'), $flash['message']);
        self::assertSame('', $flash['detail']);
        self::assertStringNotContainsString($discardedDetail, $flash['message']);
    }

    public function testAuditSchemaRefusalRollsBackTheEvidenceRow(): void
    {
        $revision = (int) repo_credential($this->db, $this->credentialId)['config_revision'];
        $generation = repo_ansible_preflight_begin($this->db, $this->credentialId, $revision);

        try {
            credentials_complete_ansible_test(
                $this->db,
                $this->credentialId,
                $this->userId,
                credential_test_result(true, 'not a closed identifier'),
                $revision,
                $generation
            );
            self::fail('The closed audit schema accepted a free-text outcome.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Audit context field must be an identifier: outcome', $exception->getMessage());
        }

        self::assertNull(repo_ansible_preflight_state($this->db, $this->credentialId));
        self::assertSame([], flash_messages());
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS total FROM deploy_logs WHERE event_code = ? AND object_type = ? AND object_id = ?'
        );
        $event = VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_TESTED;
        $objectType = 'credential';
        $objectId = (string) $this->credentialId;
        $stmt->bind_param('sss', $event, $objectType, $objectId);
        $stmt->execute();
        self::assertSame(0, (int) ($stmt->get_result()->fetch_assoc()['total'] ?? -1));
    }

    /** @return iterable<string,array{string,string}> */
    public static function discardedResults(): iterable
    {
        foreach (Lang::LOCALES as $locale) {
            yield $locale . ' configuration' => [$locale, 'configuration'];
            yield $locale . ' generation' => [$locale, 'generation'];
        }
    }

    /** @return array<string,mixed> */
    private function latestAudit(): array
    {
        $stmt = $this->db->prepare(
            'SELECT result, context_json, log_message FROM deploy_logs WHERE event_code = ? AND object_type = ? AND object_id = ? ORDER BY id DESC LIMIT 1'
        );
        $event = VIRTUSPHERE_AUDIT_EVENT_CREDENTIAL_TESTED;
        $objectType = 'credential';
        $objectId = (string) $this->credentialId;
        $stmt->bind_param('sss', $event, $objectType, $objectId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        self::assertIsArray($row, 'credential test audit is missing');
        return $row;
    }

    /** @return array{type:string,message:string,detail:string,action:mixed} */
    private function onlyFlash(): array
    {
        $messages = flash_messages();
        self::assertCount(1, $messages);
        return $messages[0];
    }

    /** @return array<string,mixed> */
    private function payload(string $host): array
    {
        return [
            'type' => VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE,
            'name' => $this->credentialName,
            'host' => $host,
            'port' => 22,
            'username' => 'svc-ansible',
        ];
    }
}
