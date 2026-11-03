<?php

declare(strict_types=1);

/** Additive, disabled 8R-O-2 storage; no claim or execution activation. */
function migrate_0042_remote_execution_foundation(mysqli $db): void
{
    // Etappe 8R-O-2 is additive and fail-closed. It creates identity,
    // fencing and recovery storage, but does not wire the existing claim or
    // SSH path to unmeasured site values. Every activation starts disabled.
    migrator_add_column($db, 'deploy_jobs', 'lock_token', "CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER locked_by");
    migrator_add_column($db, 'deploy_jobs', 'worker_epoch', 'BIGINT UNSIGNED NULL AFTER lock_token');
    migrator_add_column($db, 'deploy_jobs', 'execution_contract', "VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER worker_epoch");
    migrator_add_column($db, 'deploy_jobs', 'execution_generation_id', 'BINARY(16) NULL AFTER execution_contract');
    migrator_add_column($db, 'deploy_jobs', 'recovery_count', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER execution_generation_id');
    migrator_add_column($db, 'deploy_jobs', 'recovery_reason', 'VARCHAR(32) NULL AFTER recovery_count');
    migrator_add_column($db, 'deploy_jobs', 'recovery_requested_at', 'TIMESTAMP NULL AFTER recovery_reason');
    if (!migrator_check_exists($db, 'deploy_jobs', 'deploy_jobs_execution_contract_check')) {
        $db->query("ALTER TABLE deploy_jobs ADD CONSTRAINT deploy_jobs_execution_contract_check CHECK (execution_contract IS NULL OR execution_contract IN (_ascii'legacy_v1',_ascii'remote_v1'))");
    }
    if (!migrator_check_exists($db, 'deploy_jobs', 'deploy_jobs_recovery_reason_check')) {
        $db->query("ALTER TABLE deploy_jobs ADD CONSTRAINT deploy_jobs_recovery_reason_check CHECK (recovery_reason IS NULL OR recovery_reason IN (_utf8mb4'remote_observation',_utf8mb4'legacy_uncertain',_utf8mb4'foreign_generation'))");
    }

    $db->query("CREATE TABLE IF NOT EXISTS deploy_runtime_identity (
        id TINYINT UNSIGNED PRIMARY KEY,
        current_generation_id BINARY(16) NOT NULL,
        supervisor_contract VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        rotation_reason VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        rotated_by INT NULL,
        CONSTRAINT deploy_runtime_identity_singleton CHECK (id = 1),
        CONSTRAINT deploy_runtime_identity_supervisor_check CHECK (supervisor_contract IN ('worker_v1','supervisor_v1')),
        CONSTRAINT deploy_runtime_identity_reason_check CHECK (rotation_reason IN ('install','restore','clone')),
        CONSTRAINT fk_deploy_runtime_identity_rotated_by FOREIGN KEY (rotated_by) REFERENCES deploy_users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("INSERT INTO deploy_runtime_identity (id, current_generation_id, supervisor_contract, rotation_reason)
        VALUES (1, RANDOM_BYTES(16), 'worker_v1', 'install')
        ON DUPLICATE KEY UPDATE id = VALUES(id)");

    $db->query("CREATE TABLE IF NOT EXISTS deploy_worker_leases (
        lease_name VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
        owner_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        lease_until TIMESTAMP(6) NULL,
        renewed_at TIMESTAMP(6) NULL,
        claims_paused TINYINT(1) NOT NULL DEFAULT 1,
        pause_reason VARCHAR(64) NULL,
        updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        CONSTRAINT deploy_worker_leases_pause_check CHECK (claims_paused IN (0,1))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("INSERT INTO deploy_worker_leases (lease_name, claims_paused, pause_reason)
        VALUES ('deploy-worker', 1, '8R-S site acceptance missing')
        ON DUPLICATE KEY UPDATE lease_name = VALUES(lease_name)");

    $db->query("CREATE TABLE IF NOT EXISTS deploy_remote_mode_activations (
        credential_ansible_id INT NOT NULL,
        mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'disabled',
        contract_version VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
        host_preflight_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        fault_matrix_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        evidence_expires_at TIMESTAMP NULL,
        changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        changed_by INT NULL,
        optimistic_lock_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        PRIMARY KEY (credential_ansible_id, mode),
        CONSTRAINT deploy_remote_activation_state_check CHECK (state IN ('disabled','legacy_explicit','pilot_remote','remote_enabled','rollback_pending')),
        CONSTRAINT deploy_remote_activation_contract_check CHECK (
            (state = 'disabled' AND contract_version IS NULL) OR
            (state = 'legacy_explicit' AND contract_version = 'legacy_v1') OR
            (state IN ('pilot_remote','remote_enabled','rollback_pending') AND contract_version = 'remote_v1')
        ),
        CONSTRAINT fk_deploy_remote_activation_credential FOREIGN KEY (credential_ansible_id) REFERENCES deploy_credentials(id) ON DELETE CASCADE,
        CONSTRAINT fk_deploy_remote_activation_changed_by FOREIGN KEY (changed_by) REFERENCES deploy_users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("INSERT IGNORE INTO deploy_remote_mode_activations (credential_ansible_id, mode, state, contract_version)
        SELECT c.id, modes.mode, 'disabled', NULL
        FROM deploy_credentials c
        CROSS JOIN (
            SELECT 'create' AS mode UNION ALL SELECT 'powercycle' UNION ALL SELECT 'export'
            UNION ALL SELECT 'start' UNION ALL SELECT 'autostart' UNION ALL SELECT 'full'
            UNION ALL SELECT 'inventory'
        ) modes
        WHERE c.type = 'ansible'");

    $db->query("CREATE TABLE IF NOT EXISTS deploy_remote_executions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        job_attempt INT UNSIGNED NOT NULL,
        step_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        protocol_version INT UNSIGNED NOT NULL,
        run_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        unit_name VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        remote_dir VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        instance_id BINARY(16) NOT NULL,
        generation_id BINARY(16) NOT NULL,
        controller_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        effect_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        reconciliation_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        cleanup_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        launch_intent_at TIMESTAMP(6) NULL,
        started_at TIMESTAMP(6) NULL,
        last_observed_at TIMESTAMP(6) NULL,
        finished_at TIMESTAMP(6) NULL,
        exit_code INT NULL,
        exit_signal INT NULL,
        result_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        log_offset BIGINT UNSIGNED NOT NULL DEFAULT 0,
        output_truncated TINYINT(1) NOT NULL DEFAULT 0,
        recovery_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_probe_category VARCHAR(32) NULL,
        last_probe_detail VARCHAR(1024) NULL,
        cleanup_due_at TIMESTAMP(6) NULL,
        cleanup_lease_until TIMESTAMP(6) NULL,
        cleanup_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        cleanup_auto_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        cleanup_last_error VARCHAR(1024) NULL,
        cleanup_finished_at TIMESTAMP(6) NULL,
        created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        UNIQUE KEY deploy_remote_execution_step_unique (job_id, job_attempt, step_key),
        UNIQUE KEY deploy_remote_execution_token_unique (run_token),
        UNIQUE KEY deploy_remote_execution_unit_unique (unit_name),
        UNIQUE KEY deploy_remote_execution_dir_unique (remote_dir),
        INDEX deploy_remote_execution_generation (generation_id, reconciliation_state),
        CONSTRAINT fk_deploy_remote_execution_job FOREIGN KEY (job_id) REFERENCES deploy_jobs(id) ON DELETE CASCADE,
        CONSTRAINT deploy_remote_execution_protocol_check CHECK (protocol_version = 1),
        CONSTRAINT deploy_remote_execution_truncated_check CHECK (output_truncated IN (0,1))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS deploy_recovery_resolutions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        remote_execution_id BIGINT UNSIGNED NULL,
        resolution_scope VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        resolution_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        reason VARCHAR(1024) NOT NULL,
        reference VARCHAR(255) NULL,
        evidence_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        actor_id INT NOT NULL,
        previous_state JSON NOT NULL,
        created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        INDEX deploy_recovery_resolution_job (job_id, id),
        CONSTRAINT deploy_recovery_resolution_scope_check CHECK (
            (resolution_scope = 'remote_execution' AND remote_execution_id IS NOT NULL) OR
            (resolution_scope = 'legacy_job' AND remote_execution_id IS NULL)
        ),
        CONSTRAINT fk_deploy_recovery_resolution_job FOREIGN KEY (job_id) REFERENCES deploy_jobs(id) ON DELETE CASCADE,
        CONSTRAINT fk_deploy_recovery_resolution_remote FOREIGN KEY (remote_execution_id) REFERENCES deploy_remote_executions(id) ON DELETE RESTRICT,
        CONSTRAINT fk_deploy_recovery_resolution_actor FOREIGN KEY (actor_id) REFERENCES deploy_users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    migrator_out('0042: disabled durable remote execution foundation added');
}
