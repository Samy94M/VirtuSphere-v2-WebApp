<?php

declare(strict_types=1);

/** ADR-0044: durable identity, replay markers and bounded package evidence. */
function migrate_0056_package_report_foundation(mysqli $db): void
{
    migrator_add_column(
        $db,
        'deploy_vms',
        'package_report_generation',
        'BINARY(16) NULL AFTER edit_version'
    );
    // Backfill each row independently. Explicitly retain updated_at: assigning
    // an internal evidence fence must not look like a portal edit.
    $db->query('UPDATE deploy_vms
                   SET package_report_generation = UUID_TO_BIN(UUID()),
                       updated_at = updated_at
                 WHERE package_report_generation IS NULL');
    $db->query('ALTER TABLE deploy_vms MODIFY package_report_generation BINARY(16) NOT NULL DEFAULT (UUID_TO_BIN(UUID()))');

    $db->query("CREATE TABLE IF NOT EXISTS deploy_package_report_state (
        id TINYINT UNSIGNED NOT NULL,
        acceptance_generation BINARY(16) NOT NULL,
        rotated_at DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
        PRIMARY KEY (id),
        CONSTRAINT deploy_package_report_state_singleton CHECK (id = 1)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query('INSERT IGNORE INTO deploy_package_report_state (id, acceptance_generation) VALUES (1, UUID_TO_BIN(UUID()))');

    $db->query("CREATE TABLE IF NOT EXISTS deploy_package_run_markers (
        run_id BINARY(16) NOT NULL,
        expires_at DATETIME(6) NOT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
        PRIMARY KEY (run_id),
        INDEX deploy_package_run_markers_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS deploy_package_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        run_id BINARY(16) NOT NULL,
        vm_id INT NOT NULL,
        catalog_id INT NULL,
        device_generation BINARY(16) NOT NULL,
        acceptance_generation BINARY(16) NOT NULL,
        rollout_revision BIGINT UNSIGNED NOT NULL,
        project_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
        package_version VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
        run_context VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        mac_candidates JSON NOT NULL,
        client_started_at DATETIME(6) NOT NULL,
        total BIGINT UNSIGNED NULL,
        first_received_at DATETIME(6) NOT NULL,
        last_evidence_at DATETIME(6) NOT NULL,
        completed_received_at DATETIME(6) NULL,
        client_completed_at DATETIME(6) NULL,
        wrapper_result VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        wrapper_exit_code INT NULL,
        detection_result VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        processed_count BIGINT UNSIGNED NULL,
        ok_count BIGINT UNSIGNED NULL,
        skip_count BIGINT UNSIGNED NULL,
        fail_count BIGINT UNSIGNED NULL,
        last_processed_index BIGINT UNSIGNED NULL,
        payload_omitted_count BIGINT UNSIGNED NULL,
        wrapper_log_path VARCHAR(1024) NULL,
        reporting_log_path VARCHAR(1024) NULL,
        UNIQUE KEY deploy_package_runs_run (run_id),
        INDEX deploy_package_runs_vm_received (vm_id, first_received_at, id),
        INDEX deploy_package_runs_package_received (project_name, package_version, first_received_at, id),
        CONSTRAINT fk_deploy_package_runs_marker FOREIGN KEY (run_id) REFERENCES deploy_package_run_markers(run_id),
        CONSTRAINT fk_deploy_package_runs_vm FOREIGN KEY (vm_id) REFERENCES deploy_vms(id) ON DELETE CASCADE,
        CONSTRAINT fk_deploy_package_runs_catalog FOREIGN KEY (catalog_id) REFERENCES deploy_packages(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS deploy_package_run_events (
        package_run_id BIGINT UNSIGNED NOT NULL,
        event_seq BIGINT UNSIGNED NOT NULL,
        event_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        event_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        semantic_sha256 BINARY(32) NOT NULL,
        received_at DATETIME(6) NOT NULL,
        PRIMARY KEY (package_run_id, event_seq),
        UNIQUE KEY deploy_package_run_events_key (package_run_id, event_key),
        CONSTRAINT fk_deploy_package_run_events_run FOREIGN KEY (package_run_id) REFERENCES deploy_package_runs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->query("CREATE TABLE IF NOT EXISTS deploy_package_step_results (
        package_run_id BIGINT UNSIGNED NOT NULL,
        step_index BIGINT UNSIGNED NOT NULL,
        script_name VARCHAR(255) NOT NULL,
        result VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        storage_class VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        is_first_failure TINYINT(1) NOT NULL DEFAULT 0,
        error_category VARCHAR(255) NULL,
        child_exit_code INT NULL,
        duration_ms BIGINT UNSIGNED NULL,
        detail_path VARCHAR(1024) NULL,
        source_event_seq BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (package_run_id, step_index),
        INDEX deploy_package_step_storage (package_run_id, storage_class, step_index),
        CONSTRAINT fk_deploy_package_step_run FOREIGN KEY (package_run_id) REFERENCES deploy_package_runs(id) ON DELETE CASCADE,
        CONSTRAINT fk_deploy_package_step_event FOREIGN KEY (package_run_id, source_event_seq) REFERENCES deploy_package_run_events(package_run_id, event_seq) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    migrator_out('0056: package report identity, permanent replay markers and bounded evidence tables added');
}
