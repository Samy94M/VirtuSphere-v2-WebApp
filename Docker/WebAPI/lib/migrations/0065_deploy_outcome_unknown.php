<?php

declare(strict_types=1);

/**
 * WK-04/WK-07 A: the terminal job status `outcome_unknown`. The stale-job reaper
 * writes it for a running mission job whose heartbeat went silent: it observes
 * silence and never the remote run, so "failed" claimed a failure nobody saw.
 * The reason stays `stale_heartbeat`; the CHECK now admits it for both
 * `failed` (rows reaped before this migration, and read-only inventory pulls)
 * and `outcome_unknown`.
 *
 * The ENUM value comes last, in VIRTUSPHERE_DEPLOY_STATUS_* declaration order
 * (ADR-0016). MODIFY with a superset never truncates, and the shared status
 * preflight has already rejected any stored value this code does not know.
 * No backfill: an existing failed/stale_heartbeat row keeps its status, because
 * nothing recorded proves today what its remote run did.
 *
 * Idempotent: the MODIFY is a no-op on the fresh path (struktur.sql and the
 * in-place mirrors in migrate.php already carry the value), and the CHECK is
 * dropped and re-added with the same text struktur.sql declares (pattern 0046).
 */
function migrate_0065_deploy_outcome_unknown(mysqli $db): void
{
    if (!migrator_table_exists($db, 'deploy_jobs') || !migrator_column_exists($db, 'deploy_jobs', 'status')) {
        return;
    }
    // The CHECK names the status column, so it goes first and comes back last.
    if (migrator_check_exists($db, 'deploy_jobs', 'deploy_jobs_terminal_reason_check')) {
        $db->query('ALTER TABLE deploy_jobs DROP CHECK deploy_jobs_terminal_reason_check');
    }
    $db->query("ALTER TABLE deploy_jobs
        MODIFY COLUMN status ENUM('queued','running','cancelling','succeeded','failed','cancelled','partial','outcome_unknown') NOT NULL DEFAULT 'queued'");
    $db->query("ALTER TABLE deploy_jobs ADD CONSTRAINT deploy_jobs_terminal_reason_check CHECK (
        (terminal_reason_code IS NULL AND terminal_reason_detail IS NULL) OR
        (status = _utf8mb4'succeeded' AND terminal_reason_code = _utf8mb4'completed') OR
        (status = _utf8mb4'partial' AND terminal_reason_code = _utf8mb4'partial_result') OR
        (status = _utf8mb4'failed' AND terminal_reason_code IN (_utf8mb4'execution_failed',_utf8mb4'timeout',_utf8mb4'stale_heartbeat',_utf8mb4'ownership_lost',_utf8mb4'configuration_blocked')) OR
        (status = _utf8mb4'cancelled' AND terminal_reason_code IN (_utf8mb4'operator_cancelled',_utf8mb4'cancel_converged')) OR
        (status = _utf8mb4'outcome_unknown' AND terminal_reason_code = _utf8mb4'stale_heartbeat')
    )");
    migrator_out('0065: deploy jobs know the terminal status outcome_unknown');
}
