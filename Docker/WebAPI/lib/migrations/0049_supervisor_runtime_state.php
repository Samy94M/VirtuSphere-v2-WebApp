<?php

declare(strict_types=1);

/**
 * The supervisor's published state (Etappe 14C, plan section 21.1/21.2).
 *
 * It lands on `deploy_runtime_identity`, the singleton that already owns the
 * process contract, the claim axis and the runtime generation, and NOT in
 * `deploy_integration_heartbeats`. That table is read through
 * VIRTUSPHERE_INTEGRATION_SOURCES and feeds the "internal services" traffic
 * light; a source added there would be permanently rowless while the contract
 * is `worker_v1`, which means permanently red for a service that is not
 * supposed to be running. A warning for a planned absence is the kind of signal
 * people learn to ignore, and then it is not there on the day it matters.
 *
 * Keeping it in the same row as the contract has a second, harder reason: the
 * snapshot decides `offline`/`cooldown`/`degraded` from the contract AND from
 * this state, and two rows could disagree about which process shape is running.
 *
 * Everything is nullable and nothing is backfilled. Under `worker_v1` these
 * columns stay NULL, which is exactly the truth: no supervisor has reported.
 */
function migrate_0049_supervisor_runtime_state(mysqli $db): void
{
    // The last time the supervisor said it was alive. This is the PUBLISHED
    // copy, written on a much slower cadence than the local heartbeat file: the
    // file is what the container healthcheck and the supervisor's own loop use,
    // this is what the portal in another container can read at all.
    migrator_add_column($db, 'deploy_runtime_identity', 'supervisor_heartbeat_at', 'TIMESTAMP NULL AFTER supervisor_contract');
    migrator_add_column($db, 'deploy_runtime_identity', 'supervisor_pid', 'INT UNSIGNED NULL AFTER supervisor_heartbeat_at');

    // What the supervisor is doing. A closed vocabulary, mirrored from
    // VIRTUSPHERE_SUPERVISOR_PHASES in lib/deploy_supervisor_policy.php, which
    // stays the SSoT (ADR-0016); this CHECK is an order-exact mirror.
    migrator_add_column($db, 'deploy_runtime_identity', 'supervisor_phase', 'VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER supervisor_pid');
    if (migrator_check_exists($db, 'deploy_runtime_identity', 'deploy_runtime_supervisor_phase_check')) {
        $db->query('ALTER TABLE deploy_runtime_identity DROP CHECK deploy_runtime_supervisor_phase_check');
    }
    // `_ascii`, matching the column, and matching what struktur.sql now writes.
    // MySQL narrows the literal to the compared column's charset anyway, so an
    // `_utf8mb4` introducer here would be stored as `_ascii` and read back
    // differently from what this file says - and a fresh schema whose literals
    // were recorded with the connection charset would then differ textually
    // from a migrated one for identical semantics. That divergence is exactly
    // what check-schema-convergence.sh caught when this migration first
    // rebuilt the table.
    $db->query(
        "ALTER TABLE deploy_runtime_identity
         ADD CONSTRAINT deploy_runtime_supervisor_phase_check CHECK (
             supervisor_phase IS NULL OR supervisor_phase IN (
                 _ascii'idle', _ascii'running', _ascii'stopping', _ascii'cooldown',
                 _ascii'wait_retry', _ascii'manual', _ascii'stopped'
             )
         )"
    );

    // The child, as the supervisor sees it. `child_pid` is diagnostic only: the
    // portal must never act on it, because a pid means nothing outside the
    // container that owns it.
    migrator_add_column($db, 'deploy_runtime_identity', 'supervisor_child_pid', 'INT UNSIGNED NULL AFTER supervisor_phase');
    migrator_add_column($db, 'deploy_runtime_identity', 'supervisor_child_started_at', 'TIMESTAMP NULL AFTER supervisor_child_pid');

    // The restart window, so a reader can tell a single hiccup from a pattern
    // and so `next_retry_at` survives a supervisor restart. Without the stored
    // deadline a crash during the wait would turn the backoff into an immediate
    // retry, which is the hot loop the plan forbids.
    migrator_add_column($db, 'deploy_runtime_identity', 'supervisor_restart_window_started_at', 'TIMESTAMP NULL AFTER supervisor_child_started_at');
    migrator_add_column($db, 'deploy_runtime_identity', 'supervisor_restart_count', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER supervisor_restart_window_started_at');
    migrator_add_column($db, 'deploy_runtime_identity', 'supervisor_next_retry_at', 'TIMESTAMP NULL AFTER supervisor_restart_count');

    // Who switched the process contract and when. The switch is an audited
    // maintenance window, never automatic, and this is the row's own record of
    // it next to the value it changed.
    migrator_add_column($db, 'deploy_runtime_identity', 'supervisor_contract_changed_at', 'TIMESTAMP NULL AFTER supervisor_next_retry_at');
    migrator_add_column($db, 'deploy_runtime_identity', 'supervisor_contract_changed_by', 'INT NULL AFTER supervisor_contract_changed_at');
    if (!migrator_foreign_key_exists($db, 'deploy_runtime_identity', 'fk_deploy_runtime_supervisor_actor')) {
        $db->query(
            'ALTER TABLE deploy_runtime_identity
             ADD CONSTRAINT fk_deploy_runtime_supervisor_actor
             FOREIGN KEY (supervisor_contract_changed_by) REFERENCES deploy_users(id) ON DELETE SET NULL'
        );
    }

    migrator_out('0049: the deploy runtime row carries the supervisor state it publishes');
}
