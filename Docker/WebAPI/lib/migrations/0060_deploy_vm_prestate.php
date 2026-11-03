<?php

declare(strict_types=1);

/** K15: no historical backfill or guessed lifecycle repair. */
function migrate_0060_deploy_vm_prestate(mysqli $db): void
{
    $db->query("CREATE TABLE IF NOT EXISTS deploy_job_vm_states (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        vm_id INT NOT NULL,
        lifecycle_before VARCHAR(32) NOT NULL,
        mecm_before VARCHAR(32) NOT NULL,
        marked_deploying TINYINT(1) NOT NULL DEFAULT 0,
        esxi_touched TINYINT(1) NOT NULL DEFAULT 0,
        create_launch_intent TINYINT(1) NOT NULL DEFAULT 0,
        touch_phase VARCHAR(32) NULL,
        captured_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        UNIQUE KEY deploy_job_vm_state_unique (job_id, vm_id),
        INDEX deploy_job_vm_state_owner (vm_id, id),
        CONSTRAINT fk_deploy_job_vm_state_job FOREIGN KEY (job_id) REFERENCES deploy_jobs(id) ON DELETE CASCADE,
        CONSTRAINT fk_deploy_job_vm_state_vm FOREIGN KEY (vm_id) REFERENCES deploy_vms(id) ON DELETE CASCADE,
        CONSTRAINT deploy_job_vm_state_flags CHECK (marked_deploying IN (0,1) AND esxi_touched IN (0,1) AND create_launch_intent IN (0,1))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
