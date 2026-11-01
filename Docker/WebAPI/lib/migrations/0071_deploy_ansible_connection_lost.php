<?php

declare(strict_types=1);

/**
 * U-01 (user decision 30.10.2026): the terminal reason `ansible_connection_lost`.
 * A worker that loses its SSH connection to the Ansible host while a playbook
 * step runs cannot know whether the step finished there (measured 01.11.2026:
 * in the black-hole case it did). It ends the job `outcome_unknown` like the
 * reaper does for silence, with this reason instead of `stale_heartbeat`.
 *
 * The CHECK admits the new reason only with `outcome_unknown`; every other
 * branch is unchanged. No backfill: a job that ended `failed` after a lost
 * connection keeps it, because nothing recorded proves today what its step did.
 *
 * Idempotent: the CHECK is dropped and re-added with the same text struktur.sql
 * declares (pattern 0046/0065). No column changes; the status ENUM already
 * carries `outcome_unknown` since 0065.
 */
function migrate_0071_deploy_ansible_connection_lost(mysqli $db): void
{
    if (!migrator_table_exists($db, 'deploy_jobs') || !migrator_column_exists($db, 'deploy_jobs', 'terminal_reason_code')) {
        return;
    }
    if (migrator_check_exists($db, 'deploy_jobs', 'deploy_jobs_terminal_reason_check')) {
        $db->query('ALTER TABLE deploy_jobs DROP CHECK deploy_jobs_terminal_reason_check');
    }
    $db->query("ALTER TABLE deploy_jobs ADD CONSTRAINT deploy_jobs_terminal_reason_check CHECK (
        (terminal_reason_code IS NULL AND terminal_reason_detail IS NULL) OR
        (status = _utf8mb4'succeeded' AND terminal_reason_code = _utf8mb4'completed') OR
        (status = _utf8mb4'partial' AND terminal_reason_code = _utf8mb4'partial_result') OR
        (status = _utf8mb4'failed' AND terminal_reason_code IN (_utf8mb4'execution_failed',_utf8mb4'timeout',_utf8mb4'stale_heartbeat',_utf8mb4'ownership_lost',_utf8mb4'configuration_blocked')) OR
        (status = _utf8mb4'cancelled' AND terminal_reason_code IN (_utf8mb4'operator_cancelled',_utf8mb4'cancel_converged')) OR
        (status = _utf8mb4'outcome_unknown' AND terminal_reason_code IN (_utf8mb4'stale_heartbeat',_utf8mb4'ansible_connection_lost'))
    )");
    migrator_out('0071: a deploy job may end outcome_unknown after a lost Ansible host connection');
}
