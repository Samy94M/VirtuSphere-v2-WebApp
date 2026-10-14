<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/envboot.php';
require_once dirname(__DIR__, 2) . '/lib/crypto.php';
require_once dirname(__DIR__, 2) . '/lib/deploy_constants.php';
require_once dirname(__DIR__, 2) . '/lib/credentials.php';
require_once dirname(__DIR__, 2) . '/lib/repo/helpers.php';

/**
 * The APP_KEY rotation tool (K22): lib/app_key_rotate.php moves every stored secret from an old key to the running APP_KEY.
 *
 * Runs against a real MySQL server. Every test uses a random old key, so the rows it creates can only be read with that key
 * and the shared database is never left poisoned. Rows that already exist are sealed with the running APP_KEY and are
 * counted as "already". The deploy_ad_config row is saved in setUp and restored in tearDown. No host is ever contacted.
 *
 * The tests that call a rotation function, or start the CLI, first assert that the module exists, so a missing module
 * fails them by name.
 */
final class AppKeyRotationTest extends TestCase
{
    private const EVENT = 'credential.app_key_rotated';
    private const PREFIX = 'phpunit-k22-';
    private const UPN = 'phpunit-k22@example.invalid';
    private const CLI = 'lib/app_key_rotate.php';

    private mysqli $db;
    private string $token = '';
    private string $oldKey = '';
    private string $newKey = '';
    private bool $snapshotTaken = false;
    private int $auditWatermark = 0;
    /** @var array<string, mixed>|null the deploy_ad_config row as it was before this test */
    private ?array $savedDirectory = null;
    /** @var array<string, string> the plaintext of every fixture secret, by label */
    private array $plain = [];
    private int $esxiId = 0;
    private int $ansibleId = 0;
    private int $controlId = 0;
    /** @var list<int> */
    private array $credentialIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = db(true);
        } catch (Throwable $exception) {
            self::markTestSkipped('Database is not reachable: ' . $exception->getMessage());
        }
        // The module is loaded when it exists. Until then the tests that need it fail by name.
        $module = dirname(__DIR__, 2) . '/lib/app_key_rotate.php';
        if (is_file($module)) {
            require_once $module;
        }

        $this->token = bin2hex(random_bytes(5));
        $this->oldKey = random_bytes(32);
        $this->newKey = envboot_app_key_bytes();
        // Audit rows are found by id above this watermark, so rows of other writers are never touched.
        $this->auditWatermark = (int) repo_scalar($this->db, 'SELECT COALESCE(MAX(id), 0) FROM deploy_logs');
        $this->savedDirectory = repo_fetch_one($this->db, 'SELECT * FROM deploy_ad_config WHERE id = 1');
        $this->snapshotTaken = true;
        $this->removeLeftovers();
    }

    protected function tearDown(): void
    {
        // Without a saved snapshot the directory row was never touched, so nothing may be restored.
        if (!isset($this->db) || !$this->snapshotTaken) {
            return;
        }
        $this->removeLeftovers();
        repo_execute($this->db, 'DELETE FROM deploy_logs WHERE event_code = ? AND id > ?', 'si', [self::EVENT, $this->auditWatermark]);
        $this->restoreDirectory();
    }

    /** Dry run: counts the rewrites an apply would make, changes no row and writes no audit row. */
    public function testDryRunCountsTheRewritesAndChangesNothing(): void
    {
        self::requireFunction('crypto_encrypt_secret_with_key');
        self::requireFunction('app_key_rotate');
        $this->seedFixture($this->oldKey);
        $before = $this->fixtureSnapshot();

        $result = app_key_rotate($this->db, $this->oldKey, $this->newKey, false);

        self::assertFalse($result['applied']);
        self::assertSame(2, $result['credentials']);
        self::assertSame(1, $result['directory']);
        self::assertGreaterThanOrEqual(1, $result['already'], 'the control row is already readable with the new key');
        self::assertSame($before, $this->fixtureSnapshot(), 'a dry run changes no fixture row');
        self::assertSame([], $this->rotationAuditRows(), 'a dry run writes no audit row');
    }

    /** Apply: the old-key rows decrypt only with the new key, the control row stays byte-identical and one audit row is written. */
    public function testApplyRewritesTheOldKeyRowsAndLeavesTheControlRowAlone(): void
    {
        self::requireFunction('crypto_encrypt_secret_with_key');
        self::requireFunction('crypto_decrypt_secret_with_key');
        self::requireFunction('app_key_rotate');
        $this->seedFixture($this->oldKey);
        $before = $this->fixtureSnapshot();

        $result = app_key_rotate($this->db, $this->oldKey, $this->newKey, true);

        self::assertTrue($result['applied']);
        self::assertSame(2, $result['credentials']);
        self::assertSame(1, $result['directory']);
        self::assertGreaterThanOrEqual(1, $result['already']);

        foreach (['esxi' => $this->esxiId, 'ansible' => $this->ansibleId] as $label => $credentialId) {
            $row = $this->credentialRow($credentialId);
            self::assertSame($this->plain[$label], crypto_decrypt_secret_with_key((string) $row['secret_ciphertext'], $this->newKey), $label . ' reads with the new key');
            $this->assertNotDecryptable((string) $row['secret_ciphertext'], $this->oldKey, $label . ' no longer reads with the old key');
            self::assertSame($before['credentials'][$credentialId]['config_revision'], $row['config_revision'], $label . ' keeps its config_revision');
        }

        $directory = $this->directoryRow();
        self::assertSame($this->plain['directory'], crypto_decrypt_secret_with_key((string) $directory['bind_secret_ciphertext'], $this->newKey), 'the directory reads with the new key');
        $this->assertNotDecryptable((string) $directory['bind_secret_ciphertext'], $this->oldKey, 'the directory no longer reads with the old key');
        self::assertSame($before['directory']['revision'], $directory['revision'], 'the directory keeps its revision');

        self::assertSame($before['credentials'][$this->controlId], $this->credentialRow($this->controlId), 'the control row is byte-identical');

        $audit = $this->rotationAuditRows();
        self::assertCount(1, $audit, 'exactly one audit row for one apply');
        $context = json_decode((string) $audit[0]['context_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $context['credential_count']);
        self::assertSame(1, $context['directory_count']);
        self::assertSame($result['already'], $context['already_count']);
    }

    /** A second apply finds every row readable with the new key: it rewrites nothing, counts them as already and writes no audit row. */
    public function testASecondApplyRewritesNothingAndWritesNoAuditRow(): void
    {
        self::requireFunction('crypto_encrypt_secret_with_key');
        self::requireFunction('app_key_rotate');
        $this->seedFixture($this->oldKey);
        $first = app_key_rotate($this->db, $this->oldKey, $this->newKey, true);
        $afterFirst = $this->fixtureSnapshot();

        $second = app_key_rotate($this->db, $this->oldKey, $this->newKey, true);

        self::assertSame(0, $second['credentials']);
        self::assertSame(0, $second['directory']);
        // The two credential rows and the directory row were rewritten by the first apply and now count as already.
        self::assertSame($first['already'] + 3, $second['already']);
        self::assertSame($afterFirst, $this->fixtureSnapshot(), 'the second apply writes no row');
        self::assertCount(1, $this->rotationAuditRows(), 'still only the audit row of the first apply');
    }

    /** A fault after the first rewritten row propagates and rolls every row back, and no audit row is written. */
    public function testAFaultAfterTheFirstRewrittenRowRollsEveryRowBackAndWritesNoAuditRow(): void
    {
        self::requireFunction('crypto_encrypt_secret_with_key');
        self::requireFunction('app_key_rotate');
        $this->seedFixture($this->oldKey);
        $before = $this->fixtureSnapshot();
        $calls = 0;
        $afterRow = static function (string $table, int $id) use (&$calls): void {
            $calls++;
            throw new RuntimeException('K22 injected fault after ' . $table . ':' . $id);
        };

        $caught = null;
        try {
            app_key_rotate($this->db, $this->oldKey, $this->newKey, true, $afterRow);
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(RuntimeException::class, $caught, 'the injected fault propagates');
        self::assertStringContainsString('K22 injected fault', $caught->getMessage());
        self::assertSame(1, $calls, 'the seam fired once, after the first rewritten row');
        self::assertSame($before, $this->fixtureSnapshot(), 'the rollback leaves every fixture row byte-identical');
        self::assertSame(
            $this->plain['esxi'],
            crypto_decrypt_secret_with_key((string) $this->credentialRow($this->esxiId)['secret_ciphertext'], $this->oldKey),
            'the ESXi row still reads with the old key'
        );
        self::assertSame([], $this->rotationAuditRows(), 'a rolled back run writes no audit row');
    }

    /** A row that neither key reads refuses the run with reason unreadable; the message names the row and no secret. */
    public function testARowThatNeitherKeyReadsRefusesTheRunAndNamesTheRow(): void
    {
        self::requireFunction('crypto_encrypt_secret_with_key');
        self::requireFunction('app_key_rotate');
        $this->seedFixture($this->oldKey);
        $unknownId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22, 'unknown', random_bytes(32));
        $before = $this->fixtureSnapshot();

        $exception = $this->expectRefusal(fn (): mixed => app_key_rotate($this->db, $this->oldKey, $this->newKey, true));

        self::assertSame('unreadable', $exception->reason);
        self::assertMatchesRegularExpression('/deploy_credentials:' . $unknownId . '(?!\d)/', $exception->getMessage(), 'the message names the unreadable row');
        foreach ($this->plain as $label => $plain) {
            self::assertStringNotContainsString($plain, $exception->getMessage(), 'the message carries no secret (' . $label . ')');
        }
        self::assertSame($before, $this->fixtureSnapshot(), 'the refused run changes no row');
        self::assertSame([], $this->rotationAuditRows(), 'the refused run writes no audit row');
    }

    /** An active deploy job refuses the run with reason active_jobs and changes nothing. */
    public function testAnActiveDeployJobRefusesTheRunAndChangesNothing(): void
    {
        self::requireFunction('crypto_encrypt_secret_with_key');
        self::requireFunction('app_key_rotate');
        $this->seedFixture($this->oldKey);
        $this->insertActiveJob(VIRTUSPHERE_DEPLOY_STATUS_RUNNING);
        $before = $this->fixtureSnapshot();

        $exception = $this->expectRefusal(fn (): mixed => app_key_rotate($this->db, $this->oldKey, $this->newKey, true));

        self::assertSame('active_jobs', $exception->reason);
        self::assertSame($before, $this->fixtureSnapshot(), 'the refused run changes no row');
        self::assertSame([], $this->rotationAuditRows(), 'the refused run writes no audit row');
    }

    /** An old key equal to the new key refuses the run with reason same_key and changes nothing. */
    public function testAnOldKeyEqualToTheNewKeyIsRefusedWithSameKey(): void
    {
        self::requireFunction('crypto_encrypt_secret_with_key');
        self::requireFunction('app_key_rotate');
        // Every row reads with the running key here, so no other refusal can apply.
        $this->seedFixture($this->newKey);
        $before = $this->fixtureSnapshot();

        $exception = $this->expectRefusal(fn (): mixed => app_key_rotate($this->db, $this->newKey, $this->newKey, true));

        self::assertSame('same_key', $exception->reason);
        self::assertSame($before, $this->fixtureSnapshot(), 'the refused run changes no row');
        self::assertSame([], $this->rotationAuditRows(), 'the refused run writes no audit row');
    }

    /** A rotation called inside repo_transaction() throws LogicException and changes no row. */
    public function testARotationInsideARepoTransactionThrowsLogicExceptionAndChangesNothing(): void
    {
        self::requireFunction('crypto_encrypt_secret_with_key');
        self::requireFunction('app_key_rotate');
        $this->seedFixture($this->oldKey);
        $before = $this->fixtureSnapshot();
        $logicException = null;

        try {
            repo_transaction($this->db, function () use (&$logicException): void {
                try {
                    app_key_rotate($this->db, $this->oldKey, $this->newKey, true);
                } catch (LogicException $exception) {
                    $logicException = $exception;
                }
                // Throwing makes repo_transaction() roll the outer transaction back, so no write of this test survives.
                throw new RuntimeException('K22 rollback of the outer transaction');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('K22 rollback of the outer transaction', $exception->getMessage());
        }

        self::assertInstanceOf(LogicException::class, $logicException, 'a rotation inside repo_transaction() throws LogicException');
        self::assertSame($before, $this->fixtureSnapshot(), 'the rotation inside the transaction changes no row');
        self::assertSame([], $this->rotationAuditRows());
    }

    /** CLI: empty STDIN is a usage error and exits 2. */
    public function testCliWithEmptyStdinIsAUsageErrorWithExitCode2(): void
    {
        $result = $this->runCli([], '');

        self::assertSame(2, $result['exit'], $result['output']);
    }

    /** CLI: an unknown argument is a usage error and exits 2. */
    public function testCliWithAnUnknownArgumentIsAUsageErrorWithExitCode2(): void
    {
        $result = $this->runCli(['--bogus'], $this->oldKeyLine());

        self::assertSame(2, $result['exit'], $result['output']);
    }

    /** CLI: a dry run with the old key exits 0, changes no row and prints neither a plaintext secret nor a v1: ciphertext. */
    public function testCliDryRunExitsZeroChangesNothingAndPrintsNoSecret(): void
    {
        self::requireFunction('crypto_encrypt_secret_with_key');
        self::requireFunction('app_key_rotate');
        $this->seedFixture($this->oldKey);
        $before = $this->fixtureSnapshot();

        $result = $this->runCli([], $this->oldKeyLine());

        self::assertSame(0, $result['exit'], $result['output']);
        $this->assertNoSecretInOutput($result['output']);
        self::assertSame($before, $this->fixtureSnapshot(), 'a CLI dry run changes no row');
        self::assertSame([], $this->rotationAuditRows(), 'a CLI dry run writes no audit row');
    }

    /** CLI: --apply without a backup status line exits 1 with a message about the backup and changes no row. */
    public function testCliApplyWithoutABackupStatusExitsOneWithABackupMessageAndChangesNothing(): void
    {
        self::requireFunction('crypto_encrypt_secret_with_key');
        self::requireFunction('app_key_rotate');
        $this->seedFixture($this->oldKey);
        $before = $this->fixtureSnapshot();

        $result = $this->runCli(['--apply'], $this->oldKeyLine());

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsStringIgnoringCase('backup', $result['output'], 'the refusal names the backup');
        $this->assertNoSecretInOutput($result['output']);
        self::assertSame($before, $this->fixtureSnapshot(), 'a CLI apply refused for the backup changes no row');
        self::assertSame([], $this->rotationAuditRows(), 'a CLI apply refused for the backup writes no audit row');
    }

    /** Fails by name while a function of the rotation module does not exist yet. */
    private static function requireFunction(string $name): void
    {
        self::assertTrue(function_exists($name), $name . ' is missing (K22)');
    }

    /**
     * Runs a rotation that must be refused and returns the refusal; a rotation that goes through fails the test.
     *
     * @param callable(): mixed $rotation
     * @return AppKeyRotationRefused
     */
    private function expectRefusal(callable $rotation): AppKeyRotationRefused
    {
        self::assertTrue(class_exists('AppKeyRotationRefused'), 'AppKeyRotationRefused is missing (K22)');
        try {
            $rotation();
        } catch (AppKeyRotationRefused $exception) {
            return $exception;
        }
        self::fail('The rotation was not refused with AppKeyRotationRefused.');
    }

    /** Asserts that $stored does not decrypt with $key. */
    private function assertNotDecryptable(string $stored, string $key, string $message): void
    {
        try {
            crypto_decrypt_secret_with_key($stored, $key);
        } catch (RuntimeException) {
            return;
        }
        self::fail($message . ': the value still decrypts with that key.');
    }

    /** Asserts that CLI output carries no fixture plaintext and no v1: ciphertext. */
    private function assertNoSecretInOutput(string $output): void
    {
        foreach ($this->plain as $label => $plain) {
            self::assertStringNotContainsString($plain, $output, 'the CLI output carries the ' . $label . ' secret');
        }
        self::assertStringNotContainsString('v1:', $output, 'the CLI output carries a ciphertext');
    }

    /** Removes what an earlier run of this suite left behind, identified by the name prefix. */
    private function removeLeftovers(): void
    {
        // Deleting a mission cascades its jobs.
        repo_execute($this->db, 'DELETE FROM deploy_missions WHERE mission_name LIKE ?', 's', [self::PREFIX . '%']);
        repo_execute($this->db, 'DELETE FROM deploy_credentials WHERE name LIKE ?', 's', [self::PREFIX . '%']);
    }

    /** Puts the directory row back exactly as setUp saw it, or removes it when setUp found none. */
    private function restoreDirectory(): void
    {
        if ($this->savedDirectory === null) {
            repo_execute($this->db, 'DELETE FROM deploy_ad_config WHERE id = 1');

            return;
        }
        // UPDATE, not REPLACE: a REPLACE deletes the row, which cascades to its controllers.
        $saved = $this->savedDirectory;
        $columns = array_values(array_filter(array_keys($saved), static fn (string $column): bool => $column !== 'id'));
        $assignments = implode(', ', array_map(static fn (string $column): string => '`' . $column . '` = ?', $columns));
        $values = array_map(static fn (string $column): mixed => $saved[$column], $columns);
        repo_execute($this->db, 'UPDATE deploy_ad_config SET ' . $assignments . ' WHERE id = 1', str_repeat('s', count($columns)), $values);
    }

    /** Seeds the fixture: an ESXi and an Ansible credential and the directory sealed with $sealKey, and a control credential sealed with the running key. */
    private function seedFixture(string $sealKey): void
    {
        $this->esxiId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ESXI, 443, 'esxi', $sealKey);
        $this->ansibleId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22, 'ansible', $sealKey);
        $this->controlId = $this->insertCredential(VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE, 22, 'control', $this->newKey);
        $this->seedDirectory($sealKey);
    }

    /** Inserts one fixture credential whose secret is sealed with $sealKey and returns its id. */
    private function insertCredential(string $type, int $port, string $label, string $sealKey): int
    {
        $plain = 'K22-' . strtoupper($label) . '-' . $this->token;
        $name = self::PREFIX . $this->token . '-' . $label;
        $host = $label . '-' . $this->token . '.example.invalid';
        $ciphertext = crypto_encrypt_secret_with_key($plain, $sealKey);
        $this->plain[$label] = $plain;

        repo_execute(
            $this->db,
            'INSERT INTO deploy_credentials (type, name, host, port, username, secret_ciphertext) VALUES (?, ?, ?, ?, ?, ?)',
            'sssiss',
            [$type, $name, $host, $port, 'svc', $ciphertext]
        );
        $credentialId = (int) $this->db->insert_id;
        $this->credentialIds[] = $credentialId;

        return $credentialId;
    }

    /** Stores the directory bind secret sealed with $sealKey: the saved row is updated, an absent row is inserted. */
    private function seedDirectory(string $sealKey): void
    {
        $plain = 'K22-DIRECTORY-' . $this->token;
        $this->plain['directory'] = $plain;
        $ciphertext = crypto_encrypt_secret_with_key($plain, $sealKey);

        if ($this->savedDirectory === null) {
            repo_execute(
                $this->db,
                "INSERT INTO deploy_ad_config (id, enabled, revision, bind_upn, bind_secret_ciphertext, ca_certificate_pem) VALUES (1, 0, 1, ?, ?, '')",
                'ss',
                [self::UPN, $ciphertext]
            );
        } else {
            repo_execute($this->db, 'UPDATE deploy_ad_config SET bind_secret_ciphertext = ? WHERE id = 1', 's', [$ciphertext]);
        }
    }

    /** Inserts a mission with one job in the given status, the state that blocks a rotation. */
    private function insertActiveJob(string $status): void
    {
        repo_execute($this->db, "INSERT INTO deploy_missions (mission_name, mission_status) VALUES (?, 'active')", 's', [self::PREFIX . $this->token . '-mission']);
        $missionId = (int) $this->db->insert_id;
        repo_execute(
            $this->db,
            'INSERT INTO deploy_jobs (mission_id, status, payload_json) VALUES (?, ?, ?)',
            'iss',
            [$missionId, $status, json_encode(['mode' => 'start', 'vm_ids' => []], JSON_THROW_ON_ERROR)]
        );
    }

    /** @return array<string, mixed>|null the full row of a credential */
    private function credentialRow(int $credentialId): ?array
    {
        return repo_fetch_one($this->db, 'SELECT * FROM deploy_credentials WHERE id = ?', 'i', [$credentialId]);
    }

    /** @return array<string, mixed>|null the full deploy_ad_config row */
    private function directoryRow(): ?array
    {
        return repo_fetch_one($this->db, 'SELECT * FROM deploy_ad_config WHERE id = 1');
    }

    /** @return array{credentials: array<int, array<string, mixed>|null>, directory: array<string, mixed>|null} */
    private function fixtureSnapshot(): array
    {
        $credentials = [];
        foreach ($this->credentialIds as $credentialId) {
            $credentials[$credentialId] = $this->credentialRow($credentialId);
        }

        return ['credentials' => $credentials, 'directory' => $this->directoryRow()];
    }

    /** @return list<array<string, mixed>> the rotation audit rows written after setUp */
    private function rotationAuditRows(): array
    {
        $stmt = $this->db->prepare('SELECT id, object_type, object_id, result, context_json FROM deploy_logs WHERE event_code = ? AND id > ? ORDER BY id');
        $code = self::EVENT;
        $watermark = $this->auditWatermark;
        $stmt->bind_param('si', $code, $watermark);
        $stmt->execute();

        return repo_fetch_all($stmt->get_result());
    }

    private function oldKeyLine(): string
    {
        return 'base64:' . base64_encode($this->oldKey) . "\n";
    }

    /**
     * Runs the CLI from Docker/WebAPI with the test environment.
     *
     * @return array{exit: int, output: string} the exit code and stdout plus stderr
     */
    private function runCli(array $arguments, string $stdin): array
    {
        self::assertFileExists(dirname(__DIR__, 2) . '/' . self::CLI, self::CLI . ' is missing (K22)');

        $pipes = [];
        $process = proc_open(
            array_merge([PHP_BINARY, self::CLI], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            getenv()
        );
        self::assertIsResource($process, 'could not start the rotation CLI');

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['exit' => $exit, 'output' => $stdout . $stderr];
    }
}
