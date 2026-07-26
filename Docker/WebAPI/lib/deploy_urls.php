<?php

declare(strict_types=1);

require_once __DIR__ . '/system_status.php';

/**
 * Canonical portal URL for one deploy-job log.
 *
 * The log is linked from the deploy list, enqueue flashes and ESXi inventory
 * cards. Keeping the route here prevents a fourth hand-built query string from
 * drifting when the viewer changes.
 */
function deploy_job_log_url(int $jobId): string
{
    if ($jobId <= 0) {
        throw new InvalidArgumentException('Deploy job id must be positive.');
    }

    return sprintf('deploy_log.php?id=%d', $jobId);
}

/**
 * The page one job belongs to. Mission jobs return to their filtered deploy
 * list; mission-less inventory jobs return to the exact ESXi card that opened
 * their log. A deleted credential leaves the general ESXi section as the only
 * honest destination.
 *
 * @param array<string,mixed> $job
 */
function deploy_job_origin_url(array $job): string
{
    $missionId = (int) ($job['mission_id'] ?? 0);
    if ($missionId > 0) {
        return sprintf('deploy.php?mission_id=%d', $missionId);
    }

    $credentialId = (int) ($job['credential_esxi_id'] ?? 0);
    if ($credentialId > 0) {
        return system_status_url('credential-' . $credentialId, ['inventory' => $credentialId]);
    }

    return system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI);
}
