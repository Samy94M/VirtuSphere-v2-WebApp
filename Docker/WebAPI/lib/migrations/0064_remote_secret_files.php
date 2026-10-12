<?php

declare(strict_types=1);

/**
 * K21 (VT-04): one row per (job, remote directory) for an ESXi access file
 * accounts.yml that may still be on the Ansible host. The row means "the file
 * may still be there and its removal is not confirmed"; a confirmed removal
 * deletes it.
 *
 * The job id has no foreign key on purpose: deleting a mission cascades its
 * jobs, and the file on the host survives that delete, so the row must too.
 * The credential is ON DELETE SET NULL like the job's own credential columns.
 * No backfill: a file uploaded before this migration has no row, as before.
 */
function migrate_0064_remote_secret_files(mysqli $db): void
{
    $db->query("CREATE TABLE IF NOT EXISTS deploy_remote_secret_files (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        credential_ansible_id INT NULL,
        remote_dir VARCHAR(255) NOT NULL,
        uploaded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        UNIQUE KEY deploy_remote_secret_file_unique (job_id, remote_dir),
        INDEX deploy_remote_secret_file_credential (credential_ansible_id, id),
        CONSTRAINT fk_deploy_remote_secret_file_credential FOREIGN KEY (credential_ansible_id) REFERENCES deploy_credentials(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
