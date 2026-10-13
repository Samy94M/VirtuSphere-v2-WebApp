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
 *
 * After the Astra review of 12.10.2026, before this migration was published:
 * target_host, target_port and target_fingerprint record where the file went, so
 * a later contact through the same credential removes it only on that target
 * (K21-A2). last_attempt_at orders the sweep after failed attempts (K21-A3), and
 * lease_until keeps a sweep away from the row of a running status query (K21-A4).
 */
function migrate_0064_remote_secret_files(mysqli $db): void
{
    $db->query("CREATE TABLE IF NOT EXISTS deploy_remote_secret_files (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        credential_ansible_id INT NULL,
        remote_dir VARCHAR(255) NOT NULL,
        target_host VARCHAR(255) NOT NULL,
        target_port INT NOT NULL,
        target_fingerprint VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL,
        uploaded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        last_attempt_at DATETIME(6) NULL,
        lease_until DATETIME(6) NULL,
        UNIQUE KEY deploy_remote_secret_file_unique (job_id, remote_dir),
        INDEX deploy_remote_secret_file_credential (credential_ansible_id, last_attempt_at),
        CONSTRAINT fk_deploy_remote_secret_file_credential FOREIGN KEY (credential_ansible_id) REFERENCES deploy_credentials(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
