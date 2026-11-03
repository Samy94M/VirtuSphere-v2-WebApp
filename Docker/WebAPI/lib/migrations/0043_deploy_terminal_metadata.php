<?php

declare(strict_types=1);

/** Additive terminal metadata; historical rows deliberately remain NULL. */
function migrate_0043_deploy_terminal_metadata(mysqli $db): void
{
    migrator_add_column($db, 'deploy_jobs', 'terminal_reason_code', 'VARCHAR(32) NULL AFTER result_json');
    migrator_add_column($db, 'deploy_jobs', 'terminal_reason_detail', 'VARCHAR(1024) NULL AFTER terminal_reason_code');

    if (!migrator_check_exists($db, 'deploy_jobs', 'deploy_jobs_terminal_reason_check')) {
        $db->query("ALTER TABLE deploy_jobs ADD CONSTRAINT deploy_jobs_terminal_reason_check CHECK (
            (terminal_reason_code IS NULL AND terminal_reason_detail IS NULL) OR
            (status = _utf8mb4'succeeded' AND terminal_reason_code = _utf8mb4'completed') OR
            (status = _utf8mb4'partial' AND terminal_reason_code = _utf8mb4'partial_result') OR
            (status = _utf8mb4'failed' AND terminal_reason_code IN (_utf8mb4'execution_failed',_utf8mb4'timeout',_utf8mb4'stale_heartbeat',_utf8mb4'ownership_lost')) OR
            (status = _utf8mb4'cancelled' AND terminal_reason_code IN (_utf8mb4'operator_cancelled',_utf8mb4'cancel_converged'))
        )");
    }

    migrator_out('0043: deploy terminal metadata added without historical backfill');
}
