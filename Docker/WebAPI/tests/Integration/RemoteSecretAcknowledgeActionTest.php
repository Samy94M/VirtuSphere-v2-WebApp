<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/permissions.php';
require_once dirname(__DIR__, 2) . '/lib/audit_registry.php';
require_once dirname(__DIR__, 2) . '/lib/layout.php';
require_once dirname(__DIR__, 2) . '/lib/layout_response.php';
require_once dirname(__DIR__, 2) . '/lib/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/request.php';
require_once dirname(__DIR__, 2) . '/lib/credentials_actions.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/repo/helpers.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_jobs.php';
// The acknowledgement module (repo_remote_secret_acknowledge, K21-F) is required in setUp, where a missing
// file fails each case instead of aborting the suite. credentials_actions.php is the page's own dispatch.

/**
 * F-UI (K21-F): the POST action remote_secret_acknowledge, dispatched by
 * credentials_handle_post() (lib/credentials_actions.php). The page
 * portal/credentials.php checks credentials.manage and CSRF before it dispatches;
 * the permission itself is covered by RemoteSecretNoticeRenderTest.
 *
 * The dispatch ends the request with exit for an unknown action, so every case
 * runs in its own process: a failing case then reports itself instead of ending
 * the whole run.
 *
 * What the action must do: close every open row of one credential and target
 * (host, port) that no lease or active job holds, through
 * repo_remote_secret_acknowledge(), which writes the audit row in the same
 * transaction; refuse a reason below the minimum with the reason's own sentence;
 * return to the page the form was sent from, by a closed token only.
 */
final class RemoteSecretAcknowledgeActionTest extends TestCase
{
    private const EVENT = 'deploy.remote_secret_acknowledged';
    private const REASON = 'Von Hand entfernt, Host außer Betrieb.';

    private mysqli $db;
    private string $token = '';
    private string $host = '';
    private int $userId = 0;
    private int $ansibleId = 0;
    private int $nextJobId = 0;
    /** @var array<string, mixed> */
    private array $postBefore = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        $this->requireModule('lib/repo/deploy_remote_secret_notice.php');
        $this->requireModule('lib/repo/deploy_remote_secret_fence.php');
        $this->userId = (int) repo_scalar($this->db, 'SELECT id FROM deploy_users ORDER BY id LIMIT 1');
        if ($this->userId <= 0) {
            self::markTestSkipped('No user exists for audit provenance.');
        }
        $this->token = bin2hex(random_bytes(5));
        $this->host = 'ack-' . $this->token . '.example.invalid';
        $this->nextJobId = random_int(1500000000, 1900000000);
        $this->ansibleId = $this->insertAnsibleCredential();
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
        repo_execute($this->db, 'DELETE FROM deploy_remote_secret_files WHERE target_host LIKE ?', 's', ['%' . $this->token . '%']);
        repo_execute($this->db, 'DELETE FROM deploy_logs WHERE event_code = ? AND context_json LIKE ?', 'ss', [self::EVENT, '%' . $this->token . '%']);
        repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$this->ansibleId]);
    }

    /** The module is the interface under test; a missing file fails each case instead of aborting the run. */
    private function requireModule(string $relative): void
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        if (!is_file($path)) {
            self::fail('Interface missing: ' . $relative . ' does not exist yet.');
        }
        require_once $path;
    }

    /** @return array<string, mixed> */
    private function manager(): array
    {
        return ['id' => $this->userId, 'role' => VIRTUSPHERE_ROLE_ADMIN];
    }

    private function insertAnsibleCredential(): int
    {
        $type = VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE;
        $name = 'phpunit_k21f_ack_' . $this->token;
        $port = 22;
        $username = 'svc';
        $ciphertext = 'sealed-' . $this->token;
        $stmt = $this->db->prepare('INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssiss', $type, $name, $this->host, $port, $username, $ciphertext);
        $stmt->execute();
        $credentialId = (int) $this->db->insert_id;
        repo_execute($this->db, 'UPDATE deploy_credentials SET ansible_host_fingerprint = ? WHERE id = ?', 'si', ['SHA256:' . $this->token, $credentialId]);

        return $credentialId;
    }

    /**
     * Rows of the current target of the credential, uploaded $ageSeconds ago.
     * Each row belongs to a job id that does not exist, which counts as inactive.
     *
     * @return list<int> the row ids
     */
    private function seedRows(int $count, int $ageSeconds): array
    {
        $target = repo_fetch_one($this->db, 'SELECT host, port, ansible_host_fingerprint FROM deploy_credentials WHERE id = ?', 'i', [$this->ansibleId]);
        self::assertNotNull($target);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $jobId = $this->nextJobId++;
            $remoteDir = '/tmp/vs-k21f-' . $this->token . '/job-' . $jobId;
            $host = (string) $target['host'];
            $port = (int) $target['port'];
            $fingerprint = (string) $target['ansible_host_fingerprint'];
            $stmt = $this->db->prepare(
                'INSERT INTO deploy_remote_secret_files (job_id, credential_ansible_id, remote_dir, target_host, target_port, target_fingerprint, uploaded_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, NOW(6) - INTERVAL ? SECOND)'
            );
            $stmt->bind_param('iissisi', $jobId, $this->ansibleId, $remoteDir, $host, $port, $fingerprint, $ageSeconds);
            $stmt->execute();
            $ids[] = (int) $this->db->insert_id;
        }

        return $ids;
    }

    private function oldAge(): int
    {
        return VIRTUSPHERE_REMOTE_SECRET_NOTICE_AFTER_SECONDS + 3600;
    }

    private function openRows(): int
    {
        return (int) repo_scalar($this->db, 'SELECT COUNT(*) FROM deploy_remote_secret_files WHERE target_host = ?', 's', [$this->host]);
    }

    /** @return array{result: string, context_json: string}|null the acknowledgement's audit row, if one was written */
    private function ackAudit(): ?array
    {
        $stmt = $this->db->prepare('SELECT result, context_json FROM deploy_logs WHERE event_code = ? AND context_json LIKE ? ORDER BY id DESC LIMIT 1');
        $event = self::EVENT;
        $like = '%' . $this->token . '%';
        $stmt->bind_param('ss', $event, $like);
        $stmt->execute();
        $rows = repo_fetch_all($stmt->get_result());

        return $rows === [] ? null : $rows[0];
    }

    /** @return list<array{type: string, message: string, detail: string, action: mixed}> */
    private function flashes(): array
    {
        return flash_messages();
    }

    /** @param array{type: string, message: string, detail: string, action: mixed} $flash the text the alert shows: message and detail */
    private function flashText(array $flash): string
    {
        return trim($flash['message'] . ' ' . $flash['detail']);
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function post(array $overrides = []): array
    {
        return array_merge([
            'action' => 'remote_secret_acknowledge',
            'credential_id' => (string) $this->ansibleId,
            'target_host' => $this->host,
            'target_port' => '22',
            'reason' => self::REASON,
            'return_to' => 'system_status',
            '_csrf' => 'phpunit',
        ], $overrides);
    }

    #[RunInSeparateProcess]
    public function testManagerClosesTheNoticeAndIsSentBackToTheStatusPage(): void
    {
        $this->seedRows(2, $this->oldAge());
        $_POST = $this->post();

        self::assertSame('system_status.php', credentials_handle_post($this->db, $this->manager()));

        self::assertSame(0, $this->openRows());
        $audit = $this->ackAudit();
        self::assertNotNull($audit, 'the acknowledgement writes its audit row in the same transaction');
        self::assertSame(VIRTUSPHERE_AUDIT_RESULT_SUCCESS, $audit['result']);
        $context = json_decode($audit['context_json'], true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(2, $context['count']);
        self::assertSame($this->host, $context['host']);
        self::assertSame(22, $context['port']);
        self::assertSame(self::REASON, $context['reason']);
        self::assertSame($this->ansibleId, $context['credential_id']);
        self::assertArrayHasKey('oldest', $context);

        $flashes = $this->flashes();
        self::assertCount(1, $flashes);
        self::assertSame('success', $flashes[0]['type']);
        self::assertStringContainsString(__t('credentials.remote_secret_acknowledged', ['target' => $this->host . ':22']), $this->flashText($flashes[0]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function successReturnPages(): iterable
    {
        yield 'status page' => ['system_status', 'system_status.php'];
        yield 'credentials page' => ['credentials', 'credentials.php'];
    }

    #[RunInSeparateProcess]
    #[DataProvider('successReturnPages')]
    public function testASuccessfulAcknowledgementCarriesThePasswordRecommendationOnBothReturnPages(string $returnTo, string $expected): void
    {
        $this->seedRows(1, $this->oldAge());
        $_POST = $this->post(['return_to' => $returnTo]);

        self::assertSame($expected, credentials_handle_post($this->db, $this->manager()));

        self::assertSame(0, $this->openRows());
        $flashes = $this->flashes();
        self::assertCount(1, $flashes);
        self::assertSame('success', $flashes[0]['type']);
        self::assertStringContainsString(__t('credentials.remote_secret_acknowledged', ['target' => $this->host . ':22']), $this->flashText($flashes[0]));
        // The recommendation is part of the success flash itself, not a separate notice.
        self::assertStringContainsString(__t('system_status.remote_secret_password_hint'), $this->flashText($flashes[0]), 'the success flash recommends changing the ESXi password');
    }

    #[RunInSeparateProcess]
    public function testAReasonBelowTheMinimumChangesNothingAndNamesTheReason(): void
    {
        $this->seedRows(2, $this->oldAge());

        foreach (['', "   \t ", 'kurz'] as $reason) {
            $_POST = $this->post(['reason' => $reason]);
            credentials_handle_post($this->db, $this->manager());

            self::assertSame(2, $this->openRows(), 'a refused reason closes nothing');
            self::assertNull($this->ackAudit(), 'a refused reason writes no audit row');
            $flashes = $this->flashes();
            self::assertCount(1, $flashes);
            self::assertSame('error', $flashes[0]['type']);
            // The notice sits on the status page, which shows no field errors: the flash carries the
            // reason's own sentence, not the generic "Validation failed.".
            self::assertSame(
                __t('credentials.remote_secret_reason_required', ['min' => VIRTUSPHERE_REMOTE_SECRET_REASON_MIN_CHARS]),
                $flashes[0]['message']
            );
        }
    }

    #[RunInSeparateProcess]
    public function testOrphanedNoticeIsAcknowledgedWithoutACredentialId(): void
    {
        $this->seedRows(1, $this->oldAge());
        // The credential goes; its rows keep the target and lose the credential (ON DELETE SET NULL).
        repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE id = ?', 'i', [$this->ansibleId]);
        // The form of an orphaned group posts an empty credential_id and no return token.
        $_POST = $this->post(['credential_id' => '', 'return_to' => '']);

        self::assertSame('credentials.php', credentials_handle_post($this->db, $this->manager()));

        self::assertSame(0, $this->openRows());
        $audit = $this->ackAudit();
        self::assertNotNull($audit);
        $context = json_decode($audit['context_json'], true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(0, $context['credential_id'], 'a deleted credential is recorded as 0');
        self::assertSame(1, $context['count']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function returnTokens(): iterable
    {
        yield 'status page' => ['system_status', 'system_status.php'];
        yield 'credentials page' => ['credentials', 'credentials.php'];
        yield 'no token' => ['', 'credentials.php'];
        yield 'foreign URL' => ['https://evil.example/', 'credentials.php'];
    }

    #[RunInSeparateProcess]
    #[DataProvider('returnTokens')]
    public function testOnlyAClosedReturnTokenChoosesThePage(string $returnTo, string $expected): void
    {
        $this->seedRows(1, $this->oldAge());
        $_POST = $this->post(['return_to' => $returnTo]);

        $redirect = credentials_handle_post($this->db, $this->manager());

        self::assertSame($expected, $redirect);
        self::assertStringNotContainsString('evil.example', $redirect);
    }

    #[RunInSeparateProcess]
    public function testAcknowledgementClosesYoungRowsOfTheTargetAndLeavesALeasedRow(): void
    {
        $this->seedRows(1, 3600);
        [$leased] = $this->seedRows(1, $this->oldAge());
        repo_execute($this->db, 'UPDATE deploy_remote_secret_files SET lease_until = NOW(6) + INTERVAL 600 SECOND WHERE id = ?', 'i', [$leased]);
        $_POST = $this->post();

        credentials_handle_post($this->db, $this->manager());

        self::assertSame(1, $this->openRows(), 'the row under a running lease belongs to a status query and stays');
        $audit = $this->ackAudit();
        self::assertNotNull($audit);
        self::assertSame(1, json_decode($audit['context_json'], true, 8, JSON_THROW_ON_ERROR)['count']);
        $flashes = $this->flashes();
        self::assertSame('success', $flashes[0]['type']);
    }

    #[RunInSeparateProcess]
    public function testAcknowledgementOfATargetWithoutOpenRowsWritesNothing(): void
    {
        $_POST = $this->post(['target_host' => 'none-' . $this->token . '.example.invalid']);

        credentials_handle_post($this->db, $this->manager());

        self::assertNull($this->ackAudit());
        $flashes = $this->flashes();
        self::assertCount(1, $flashes);
        self::assertSame('warning', $flashes[0]['type']);
        self::assertSame(
            __t('credentials.remote_secret_nothing_open', ['target' => 'none-' . $this->token . '.example.invalid:22']),
            $flashes[0]['message']
        );
    }
}
