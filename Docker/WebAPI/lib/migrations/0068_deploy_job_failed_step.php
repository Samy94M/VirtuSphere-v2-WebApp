<?php

declare(strict_types=1);

/**
 * The remote step a failed job stopped at (K4b, 30.10.2026). "Run again" of a
 * full pipeline that failed only at start or autostart repeated the MAC export
 * and never started the VMs, because nothing durable said which step had
 * failed: the step existed only as prose in last_error ("playbook step: X").
 *
 *  - `failed_step`: the VIRTUSPHERE_PLAYBOOKS key of the remote step whose
 *    failure ended the job, written by the worker in the same terminal write as
 *    last_error. NULL: no remote step failed (a failure before, between or
 *    after the steps, the create section, every other status) or the row
 *    predates this migration; a retry then plans exactly as before.
 *
 * Additive and idempotent through the column and constraint probes. The CHECK
 * pins the value set only, not the status, so no other writer of the row can
 * trip over it.
 */
function migrate_0068_deploy_job_failed_step(mysqli $db): void
{
    if (!migrator_table_exists($db, 'deploy_jobs')) {
        return;
    }
    migrator_add_column($db, 'deploy_jobs', 'failed_step',
        'VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER terminal_reason_detail');

    if (!migrator_check_exists($db, 'deploy_jobs', 'deploy_jobs_failed_step_check')) {
        $db->query("ALTER TABLE deploy_jobs ADD CONSTRAINT deploy_jobs_failed_step_check CHECK (
            failed_step IS NULL OR failed_step IN (_ascii'powercycle',_ascii'export',_ascii'start',_ascii'autostart')
        )");
    }

    migrator_out('0068: failed jobs record the remote step they stopped at');
}
