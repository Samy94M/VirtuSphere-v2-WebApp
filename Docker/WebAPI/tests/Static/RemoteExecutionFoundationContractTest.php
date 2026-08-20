<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/remote_execution_constants.php';
require_once dirname(__DIR__, 2) . '/lib/repo/deploy_remote_mode_activation.php';

final class RemoteExecutionFoundationContractTest extends TestCase
{
    private string $migration;

    protected function setUp(): void
    {
        $registry = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/migrate.php');
        $module = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/migrations/0042_remote_execution_foundation.php');
        self::assertStringContainsString("'0042_remote_execution_foundation' => migrate_0042_remote_execution_foundation(...)", $registry);
        self::assertStringContainsString("require_once __DIR__ . '/migrations/0042_remote_execution_foundation.php'", $registry);
        $this->migration = $registry . "\n" . $module;
    }

    private function freshSchema(): string
    {
        $path = dirname(__DIR__, 2) . '/../mysql/mysql-init/struktur.sql';
        if (!is_file($path)) {
            self::markTestSkipped('Repo root not visible; fresh schema is outside the WebAPI-only container mount.');
        }
        $source = (string) file_get_contents($path);
        self::assertNotSame('', $source, 'fresh schema is empty');
        return $source;
    }

    public function testMigrationAndFreshSchemaOwnTheSameFoundation(): void
    {
        $schema = $this->freshSchema();
        $tables = [
            'deploy_runtime_identity',
            'deploy_worker_leases',
            'deploy_remote_mode_activations',
            'deploy_remote_executions',
            'deploy_recovery_resolutions',
        ];
        foreach ($tables as $table) {
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS ' . $table, $this->migration, $table . ' missing from migration');
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS ' . $table, $schema, $table . ' missing from fresh schema');
        }
        foreach (['lock_token', 'worker_epoch', 'execution_contract', 'execution_generation_id', 'recovery_count', 'recovery_reason', 'recovery_requested_at'] as $column) {
            self::assertStringContainsString("'deploy_jobs', '" . $column . "'", $this->migration, $column . ' missing from migration');
            self::assertMatchesRegularExpression('/\b' . preg_quote($column, '/') . '\b/', $schema, $column . ' missing from fresh schema');
        }
    }

    public function testRuntimeGenerationIsRandomOnceAndClaimPauseStartsClosed(): void
    {
        foreach ([$this->migration, $this->freshSchema()] as $source) {
            self::assertStringContainsString("VALUES (1, RANDOM_BYTES(16), 'worker_v1', 'install')", $source);
            self::assertStringContainsString('ON DUPLICATE KEY UPDATE id = VALUES(id)', $source);
            self::assertStringNotContainsString('current_generation_id = VALUES(current_generation_id)', $source);
            self::assertStringContainsString('claims_paused TINYINT(1) NOT NULL DEFAULT 1', $source);
            self::assertStringContainsString("'8R-S site acceptance missing'", $source);
        }
    }

    public function testEveryKnownModeIsMaterializedDisabledAndNeverRemote(): void
    {
        preg_match("/CROSS JOIN \((.*?)\) modes/s", $this->migration, $match);
        self::assertNotEmpty($match, 'migration contains no explicit activation-mode materialization');
        preg_match_all("/'([a-z_]+)'/", $match[1], $tokens);
        $found = array_values(array_unique($tokens[1]));
        sort($found);
        $expected = virtusphere_deploy_modes();
        sort($expected);
        self::assertSame($expected, $found);
        self::assertStringContainsString("SELECT c.id, modes.mode, 'disabled', NULL", $this->migration);
    }

    public function testActivationContractIsClosedAndDisabledIsNonExecutable(): void
    {
        self::assertNull(remote_activation_contract(['state' => 'disabled', 'contract_version' => null]));
        self::assertSame('legacy_v1', remote_activation_contract(['state' => 'legacy_explicit', 'contract_version' => 'legacy_v1']));
        self::assertSame('remote_v1', remote_activation_contract(['state' => 'pilot_remote', 'contract_version' => 'remote_v1']));

        foreach ([
            ['state' => 'disabled', 'contract_version' => 'remote_v1'],
            ['state' => 'legacy_explicit', 'contract_version' => null],
            ['state' => 'unknown', 'contract_version' => null],
        ] as $invalid) {
            $this->assertActivationRejected($invalid);
        }
    }

    public function testFoundationRepositoriesAreReportOnly(): void
    {
        $paths = [
            'lib/repo/deploy_runtime_identity.php',
            'lib/repo/deploy_worker_lease.php',
        ];
        foreach ($paths as $path) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
            self::assertDoesNotMatchRegularExpression('/\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i', $source, $path . ' must remain report-only in 8R-O-2');
        }
        $activation = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/repo/deploy_remote_mode_activation.php');
        self::assertStringContainsString("INSERT IGNORE INTO deploy_remote_mode_activations", $activation);
        self::assertStringContainsString("'disabled', NULL", $activation);
        self::assertStringContainsString("NOT (state = 'disabled' AND contract_version IS NULL)", $activation);
        self::assertStringContainsString('DELETE FROM deploy_remote_mode_activations WHERE credential_ansible_id = ?', $activation);
        self::assertDoesNotMatchRegularExpression('/\b(?:UPDATE|REPLACE|ALTER|CREATE|DROP)\b/i', $activation);
        self::assertStringNotContainsString("'pilot_remote'", substr($activation, (int) strpos($activation, 'function repo_materialize_disabled_remote_activations')));
        self::assertStringNotContainsString("'remote_enabled'", substr($activation, (int) strpos($activation, 'function repo_materialize_disabled_remote_activations')));
    }

    public function testCredentialCreateMaterializesDisabledRowsInTheSameTransaction(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/repo/credentials.php');
        self::assertStringContainsString('return repo_transaction($db', $source);
        self::assertStringContainsString('repo_sync_disabled_remote_activations($db, $credentialId', $source);
        self::assertStringContainsString('repo_sync_disabled_remote_activations($db, $id', $source);
    }

    public function testStateRegistriesAreNonEmptyAndDuplicateFree(): void
    {
        foreach ([
            VIRTUSPHERE_EXECUTION_CONTRACTS,
            VIRTUSPHERE_REMOTE_ACTIVATION_STATES,
            VIRTUSPHERE_SUPERVISOR_CONTRACTS,
            VIRTUSPHERE_RUNTIME_ROTATION_REASONS,
            VIRTUSPHERE_DEPLOY_RECOVERY_REASONS,
            VIRTUSPHERE_REMOTE_CONTROLLER_STATES,
            VIRTUSPHERE_REMOTE_EFFECT_STATES,
            VIRTUSPHERE_REMOTE_RECONCILIATION_STATES,
            VIRTUSPHERE_REMOTE_CLEANUP_STATES,
        ] as $values) {
            self::assertNotSame([], $values);
            self::assertSame($values, array_values(array_unique($values)));
        }
    }

    private function assertActivationRejected(array $row): void
    {
        try {
            remote_activation_contract($row);
        } catch (RuntimeException) {
            return;
        }
        self::fail('invalid activation unexpectedly produced a contract');
    }
}
