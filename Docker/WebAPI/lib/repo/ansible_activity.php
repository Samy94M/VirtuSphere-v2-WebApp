<?php

declare(strict_types=1);

require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/helpers.php';

/**
 * Latest completed mission job per Ansible credential.
 *
 * deploy_jobs is the SSoT for actual execution history. This reader deliberately
 * does not copy a timestamp or outcome into the manual preflight table: a mission
 * mode exercises only its own playbooks, while the on-demand full test also
 * checks the complete toolchain, SFTP and the portal return path. System jobs
 * such as inventory pulls are excluded because the Ansible card explicitly
 * labels this evidence as a mission job.
 *
 * Active jobs are excluded too. "Completed" then has one exact meaning, and a
 * queued job cannot hide the last outcome an operator can inspect. updated_at is
 * the terminal transition time; id breaks the one-second TIMESTAMP tie.
 *
 * @return array<int,array<string,mixed>> keyed by credential_ansible_id
 */
function repo_latest_completed_ansible_mission_jobs(mysqli $db): array
{
    $statuses = VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES;
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = $db->prepare(
        'WITH ranked_ansible_jobs AS (
            SELECT j.id, j.credential_ansible_id, j.mission_id, m.mission_name,
                   j.status, j.updated_at,
                   ROW_NUMBER() OVER (
                       PARTITION BY j.credential_ansible_id
                       ORDER BY j.updated_at DESC, j.id DESC
                   ) AS activity_rank
              FROM deploy_jobs j
              INNER JOIN deploy_missions m ON m.id = j.mission_id
             WHERE j.credential_ansible_id IS NOT NULL
               AND j.mission_id IS NOT NULL
               AND j.status IN (' . $placeholders . ')
        )
        SELECT id, credential_ansible_id, mission_id, mission_name, status, updated_at
          FROM ranked_ansible_jobs
         WHERE activity_rank = 1'
    );
    $stmt->bind_param(str_repeat('s', count($statuses)), ...$statuses);
    $stmt->execute();

    $byCredential = [];
    foreach (repo_fetch_all($stmt->get_result()) as $row) {
        $byCredential[(int) $row['credential_ansible_id']] = $row;
    }

    return $byCredential;
}
