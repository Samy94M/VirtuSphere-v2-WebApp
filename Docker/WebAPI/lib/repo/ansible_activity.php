<?php

declare(strict_types=1);

require_once __DIR__ . '/../deploy_constants.php';
require_once __DIR__ . '/helpers.php';

/**
 * Latest mission job a worker actually processed, per Ansible credential.
 *
 * deploy_jobs is the SSoT for actual execution history. This reader deliberately
 * does not copy a timestamp or outcome into the manual preflight table: a mission
 * mode exercises only its own playbooks, while the on-demand full test also
 * checks the complete toolchain, SFTP and the portal return path. System jobs
 * such as inventory pulls are excluded because the Ansible card explicitly
 * labels this evidence as a mission job.
 *
 * `attempts > 0` is what separates a processed job from a wish: the column is
 * incremented exactly once, when a worker claims the job (repo_claim_deploy_job).
 * A job cancelled straight out of the queue therefore ends terminal with
 * attempts = 0, and without this condition that never-executed row would displace
 * the last job an operator can actually learn something from. Active jobs are
 * excluded too, so "processed" has one exact meaning; updated_at is the terminal
 * transition time and id breaks the one-second TIMESTAMP tie. payload_json comes
 * along because how much a job proves depends on the mode that ran: a start job
 * never touches the MAC return path, and the card would otherwise say "processed"
 * without saying what was processed. The mission name is the current one, not a
 * snapshot: a renamed mission reads under its new name here.
 *
 * One indexed read per credential instead of one window function over the whole
 * table. Mission history is never purged, so the ranking form degraded with it:
 * measured against 201,545 rows it scanned the table, sorted and materialized
 * 193,333 rows (EXPLAIN ANALYZE 6.9 s). This form walks deploy_jobs_ansible_activity
 * backwards per credential and stops at the first match - no temporary table, no
 * filesort, 3.5 ms on the same data even with 1,500 skipped never-processed and
 * mission-less rows at the head of the partition. N is the number of Ansible
 * credentials, which is the same set the caller is about to render.
 *
 * @param list<int> $credentialIds Ansible credential ids to report on
 * @return array<int,array<string,mixed>> keyed by credential_ansible_id
 */
function repo_latest_completed_ansible_mission_jobs(mysqli $db, array $credentialIds): array
{
    $credentialIds = array_values(array_unique(array_filter(
        array_map('intval', $credentialIds),
        static fn (int $id): bool => $id > 0
    )));
    if ($credentialIds === []) {
        return [];
    }

    $statuses = VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES;
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = $db->prepare(
        'SELECT j.id, j.credential_ansible_id, j.mission_id, m.mission_name, j.status, j.payload_json, j.updated_at
           FROM deploy_jobs j
           INNER JOIN deploy_missions m ON m.id = j.mission_id
          WHERE j.credential_ansible_id = ?
            AND j.mission_id IS NOT NULL
            AND j.attempts > 0
            AND j.status IN (' . $placeholders . ')
          ORDER BY j.updated_at DESC, j.id DESC
          LIMIT 1'
    );

    $byCredential = [];
    foreach ($credentialIds as $credentialId) {
        $stmt->bind_param('i' . str_repeat('s', count($statuses)), $credentialId, ...$statuses);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row !== null) {
            $byCredential[(int) $row['credential_ansible_id']] = $row;
        }
    }

    return $byCredential;
}
