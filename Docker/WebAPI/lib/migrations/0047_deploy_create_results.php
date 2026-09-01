<?php

declare(strict_types=1);

/**
 * Per-VM create results (Etappe 14B, Teiletappe C).
 *
 * The incident this answers: a fifteen-VM create job timed out on silence while
 * fourteen VMs existed on ESXi, and nothing in the database could say which
 * fourteen. One durable row per VM per job is that answer, and it is what makes
 * a retry able to skip a confirmed success instead of creating a second VM.
 *
 * Additive and fail-closed: no existing column changes meaning, jobs without
 * rows stay readable as legacy, and nothing reads these rows yet.
 */
function migrate_0047_deploy_create_results(mysqli $db): void
{
    // Wall-clock start of the create section, the persistent source of its
    // budget. Deliberately not set at claim time: a job that waits in the queue
    // has not started creating anything, and a budget that starts there would
    // shrink with queue length.
    migrator_add_column($db, 'deploy_jobs', 'create_started_at', 'DATETIME NULL AFTER scheduled_at');

    // The ENUM order of action, status and outcome mirrors the const order in
    // lib/deploy_create_constants.php (ADR-0016); check-enum-sync compares both
    // directions, table-scoped, because `status` is a column name this schema
    // uses more than once.
    //
    // Not here on purpose: the async directory, the cleanup counters, the
    // cleanup backoff and the last cleanup error. Those belong to the generic
    // remote handle this row binds to through remote_execution_id, and a second
    // copy would give "may this directory be removed yet" two owners that drift.
    // The async directory is derived from the handle's immutable remote_dir.
    $db->query("CREATE TABLE IF NOT EXISTS deploy_create_vm_results (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        vm_id INT NULL,
        vm_name VARCHAR(191) NOT NULL,
        position INT UNSIGNED NOT NULL,
        total INT UNSIGNED NOT NULL,
        action ENUM('create','verify_skip') NOT NULL,
        status ENUM('pending','prepared','running','succeeded','failed','uncertain','skipped') NOT NULL DEFAULT 'pending',
        outcome ENUM('created','updated','unchanged') NULL,
        changed TINYINT(1) NULL,
        existed_before TINYINT(1) NULL,
        precheck_moid VARCHAR(64) NULL,
        precheck_instance_uuid VARCHAR(64) NULL,
        async_jid VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
        remote_execution_id BIGINT UNSIGNED NULL,
        async_deadline_at DATETIME NULL,
        vm_moid VARCHAR(64) NULL,
        vm_instance_uuid VARCHAR(64) NULL,
        error_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        error_detail TEXT NULL,
        resumed_from_result_id BIGINT UNSIGNED NULL,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY deploy_create_result_position_unique (job_id, position),
        UNIQUE KEY deploy_create_result_vm_unique (job_id, vm_id),
        UNIQUE KEY deploy_create_result_remote_unique (remote_execution_id),
        INDEX deploy_create_result_progress (job_id, status, position),
        CONSTRAINT fk_deploy_create_result_job FOREIGN KEY (job_id) REFERENCES deploy_jobs(id) ON DELETE CASCADE,
        CONSTRAINT fk_deploy_create_result_vm FOREIGN KEY (vm_id) REFERENCES deploy_vms(id) ON DELETE SET NULL,
        CONSTRAINT fk_deploy_create_result_remote FOREIGN KEY (remote_execution_id) REFERENCES deploy_remote_executions(id) ON DELETE SET NULL,
        CONSTRAINT fk_deploy_create_result_resumed FOREIGN KEY (resumed_from_result_id) REFERENCES deploy_create_vm_results(id) ON DELETE SET NULL,
        CONSTRAINT deploy_create_result_position_check CHECK (position >= 1 AND position <= total),
        CONSTRAINT deploy_create_result_flags_check CHECK (
            (changed IS NULL OR changed IN (0,1)) AND (existed_before IS NULL OR existed_before IN (0,1))
        ),
        -- Invariant 2: a running unit has a durable async job id and a deadline.
        -- The third part of that invariant, the bound remote handle, cannot be
        -- checked here: MySQL refuses a column in both a CHECK and a foreign key
        -- with a referential action, and ON DELETE SET NULL is the right action
        -- for that key (RESTRICT could abort a job deletion depending on the
        -- order in which MySQL cascades the two tables). It is enforced in
        -- deploy_create_assert_transition_fields instead, which is also the
        -- only writer.
        CONSTRAINT deploy_create_result_running_check CHECK (
            status <> _utf8mb4'running' OR
            (async_jid IS NOT NULL AND async_deadline_at IS NOT NULL)
        ),
        -- Invariants 3 and 5: success carries its verified identity. A row that
        -- says succeeded without MOID and instance UUID would be a name-based
        -- claim, which is exactly what the identity contract of Etappe 14A
        -- refuses everywhere else.
        CONSTRAINT deploy_create_result_success_check CHECK (
            status NOT IN (_utf8mb4'succeeded', _utf8mb4'skipped') OR
            (outcome IS NOT NULL AND changed IS NOT NULL AND vm_moid IS NOT NULL
             AND vm_instance_uuid IS NOT NULL AND finished_at IS NOT NULL)
        ),
        -- The source row of a skip is likewise enforced in PHP, for the same
        -- reason as above: resumed_from_result_id carries a referential action.
        CONSTRAINT deploy_create_result_skip_check CHECK (
            status <> _utf8mb4'skipped' OR action = _utf8mb4'verify_skip'
        ),
        -- Invariant 4: a failure names its closed code and a redacted reason.
        -- The code set itself stays PHP-enforced rather than a CHECK list: a
        -- hand-kept SQL mirror that no guard walks is how a vocabulary drifts.
        CONSTRAINT deploy_create_result_failure_check CHECK (
            status NOT IN (_utf8mb4'failed', _utf8mb4'uncertain') OR
            (error_code IS NOT NULL AND error_detail IS NOT NULL AND finished_at IS NOT NULL)
        ),
        -- Invariant 14: an unfinished unit has no finish time, so a reader
        -- cannot mistake a resumed uncertain row for a closed one.
        CONSTRAINT deploy_create_result_open_check CHECK (
            status NOT IN (_utf8mb4'pending', _utf8mb4'prepared', _utf8mb4'running') OR finished_at IS NULL
        ),
        -- Invariant 15: no failed or uncertain row carries an alleged outcome.
        CONSTRAINT deploy_create_result_no_false_outcome_check CHECK (
            status IN (_utf8mb4'succeeded', _utf8mb4'skipped') OR
            (outcome IS NULL AND changed IS NULL AND vm_moid IS NULL AND vm_instance_uuid IS NULL)
        ),
        -- Invariants 6 to 9: the three outcomes are exactly the three
        -- combinations of existed_before and changed. The fourth combination
        -- (nothing was there, nothing changed) is not a quiet success; it is
        -- identity_result_invalid and must never reach a stored outcome.
        CONSTRAINT deploy_create_result_outcome_check CHECK (
            outcome IS NULL OR
            (outcome = _utf8mb4'created' AND existed_before = 0 AND changed = 1) OR
            (outcome = _utf8mb4'updated' AND existed_before = 1 AND changed = 1) OR
            (outcome = _utf8mb4'unchanged' AND existed_before = 1 AND changed = 0)
        )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    migrator_out('0047: per-VM create results and deploy_jobs.create_started_at added');
}
