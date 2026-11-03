<?php

declare(strict_types=1);

/**
 * In-job create retry (prod job 1527, 29.10.2026): a unit whose first attempt
 * ended `module_failed` may get up to three more attempts inside the same job.
 * The row stays the same row, because position is the identity the portal,
 * the log and a later retry share; it records which attempt it is on and keeps
 * the evidence of the first attempt.
 *
 *  - `attempt` 1 is every row so far and every fresh row. The step key and with
 *    it the async directory derive from it (`create.vm.<p>`, `.attempt2` to
 *    `.attempt4`).
 *  - `first_error_code`/`first_error_detail` keep the first attempt's failure;
 *    `first_async_jid` keeps its async job id, which tells the K15 convergence
 *    that the first attempt reached ESXi even when a later one never launched.
 *    A later re-open leaves them as they are; the attempts in between are in
 *    the job log.
 *
 * Additive: existing rows default to attempt 1 with no first-attempt evidence,
 * which is what they are. The CHECK makes the set a unit: a later attempt
 * always names the first failure and its async job, a first attempt never does.
 * Idempotent through the column and constraint probes.
 */
function migrate_0066_create_unit_attempt(mysqli $db): void
{
    if (!migrator_table_exists($db, 'deploy_create_vm_results')) {
        return;
    }
    migrator_add_column($db, 'deploy_create_vm_results', 'attempt',
        'TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER total');
    migrator_add_column($db, 'deploy_create_vm_results', 'first_error_code',
        'VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER error_detail');
    migrator_add_column($db, 'deploy_create_vm_results', 'first_error_detail',
        'TEXT NULL AFTER first_error_code');
    migrator_add_column($db, 'deploy_create_vm_results', 'first_async_jid',
        'VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER first_error_detail');

    if (!migrator_check_exists($db, 'deploy_create_vm_results', 'deploy_create_result_attempt_check')) {
        $db->query("ALTER TABLE deploy_create_vm_results ADD CONSTRAINT deploy_create_result_attempt_check CHECK (
            (attempt = 1 AND first_error_code IS NULL AND first_error_detail IS NULL AND first_async_jid IS NULL)
            OR (attempt > 1 AND first_error_code IS NOT NULL AND first_error_detail IS NOT NULL AND first_async_jid IS NOT NULL)
        )");
    }

    migrator_out('0066: create units record their attempt and the evidence of their first attempt');
}
