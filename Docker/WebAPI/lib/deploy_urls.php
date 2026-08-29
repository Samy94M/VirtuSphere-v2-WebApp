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

function deploy_job_raw_log_url(int $jobId): string
{
    return deploy_job_log_url($jobId) . '&format=raw';
}

function deploy_mission_url(int $missionId): string
{
    if ($missionId <= 0) {
        throw new InvalidArgumentException('Mission id must be positive.');
    }

    return sprintf('deploy.php?mission_id=%d', $missionId);
}

function mission_details_url(int $missionId): string
{
    if ($missionId <= 0) {
        throw new InvalidArgumentException('Mission id must be positive.');
    }

    return sprintf('mission_details.php?id=%d', $missionId);
}

const VIRTUSPHERE_DEPLOY_JOB_ORIGIN_LOG = 'job_log';

/**
 * Closed origin token for cancel POSTs. It is a route name, never a URL, so a
 * crafted form cannot turn the post-handler into an open redirect.
 */
function deploy_job_cancel_redirect_url(array $job, string $originToken): string
{
    if ($originToken === '') {
        return deploy_job_origin_url($job);
    }
    if ($originToken === VIRTUSPHERE_DEPLOY_JOB_ORIGIN_LOG) {
        return deploy_job_log_url((int) ($job['id'] ?? 0));
    }

    throw new InvalidArgumentException('Invalid deploy job origin token.');
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
        return deploy_mission_url($missionId);
    }

    $credentialId = (int) ($job['credential_esxi_id'] ?? 0);
    if ($credentialId > 0) {
        return system_status_url('credential-' . $credentialId, ['inventory' => $credentialId]);
    }

    return system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI);
}
