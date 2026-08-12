<?php

declare(strict_types=1);

// The mission-activity fact of the System status Ansible card: an outcome badge
// and the one line that says how far the last processed job got. It reads
// deploy_jobs (through the snapshot) and the deploy-job presenters, which is a
// different source from every other panel in lib/system_status_panels.php, and
// that file sits at its ADR-0006 ceiling; Etappe 13 splits the rest along the
// same seam.
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/deploy_urls.php';
// deploy_job_payload_summary(): the same mode summary the deploy list and the
// job log behind the link show, rather than a second reading of payload_json.
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/system_status_shared_panels.php';

/** A mission-job outcome is operational history, not the Ansible health Ampel. */
function system_status_ansible_job_badge(string $status): string
{
    $key = match ($status) {
        VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED => 'ansible_job_succeeded',
        VIRTUSPHERE_DEPLOY_STATUS_PARTIAL => 'ansible_job_partial',
        VIRTUSPHERE_DEPLOY_STATUS_FAILED => 'ansible_job_failed',
        VIRTUSPHERE_DEPLOY_STATUS_CANCELLED => 'ansible_job_cancelled',
        default => 'ansible_job_unknown',
    };
    // Colour meaning stays in the shared deploy-job mapper. The localized text
    // beside it makes the outcome explicit and keeps colour from being the only
    // signal. Unknown data is defensive-only and remains neutral.
    $variant = in_array($status, VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES, true)
        ? deploy_job_status_badge_class($status)
        : 'neutral';

    return portal_badge($variant, __t('system_status.' . $key));
}

/** @param array<string,mixed>|null $job */
function system_status_ansible_job_fact(?array $job, bool $canOpenLog): string
{
    if ($job === null) {
        return h(__t('system_status.ansible_job_none'));
    }

    $jobId = (int) ($job['id'] ?? 0);
    $missionName = (string) ($job['mission_name'] ?? '');
    $identity = __t('system_status.ansible_job_identity', [
        'id' => $jobId,
        'mission' => $missionName,
    ]);
    $identityHtml = h($identity);
    if ($canOpenLog && $jobId > 0) {
        $identityHtml = '<a href="' . h(deploy_job_log_url($jobId)) . '">' . $identityHtml . '</a>';
    }

    // What a processed job proves depends on the mode it ran, and the help says
    // exactly that; without naming the mode here the operator cannot act on it.
    $mode = __t('system_status.ansible_job_mode', [
        'mode' => deploy_job_payload_summary(isset($job['payload_json']) ? (string) $job['payload_json'] : null),
    ]);

    return system_status_ansible_job_badge((string) ($job['status'] ?? ''))
        . ' &middot; ' . system_status_fact_time(isset($job['updated_at']) ? (string) $job['updated_at'] : null)
        . ' &middot; ' . $identityHtml
        . ' &middot; ' . h($mode);
}
