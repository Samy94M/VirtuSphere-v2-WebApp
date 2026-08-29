<?php

declare(strict_types=1);

// Portal-only labels for deploy jobs.
//
// Same boundary as lib/portal_status_display.php: `deploy_jobs.status` and the
// `mode` payload stay technical in the database, in the worker, in the retained
// job logs and in the polling JSON's existing `status` field. Only the visible
// text comes from here.
//
// virtusphere_deploy_mode_labels() stays the technical validation SSoT and is
// deliberately NOT localized: it decides which modes may be posted, and a
// display language must never be able to widen or narrow that set. This module
// names the same modes for a reader, plus `inventory`, which the portal can
// SHOW but nobody can post, because the scheduler is its only producer.
require_once __DIR__ . '/deploy_constants.php';

/**
 * The human name of a deploy-job status.
 *
 * The seven values are exactly the active and terminal sets; an eighth value
 * would be a schema change, and it lands on the neutral fallback rather than in
 * front of an operator as a raw token.
 */
function deploy_job_status_label(string $status): string
{
    return match ($status) {
        VIRTUSPHERE_DEPLOY_STATUS_QUEUED => __t('status.job_queued'),
        VIRTUSPHERE_DEPLOY_STATUS_RUNNING => __t('status.job_running'),
        VIRTUSPHERE_DEPLOY_STATUS_CANCELLING => __t('status.job_cancelling'),
        VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED => __t('status.job_succeeded'),
        VIRTUSPHERE_DEPLOY_STATUS_FAILED => __t('status.job_failed'),
        VIRTUSPHERE_DEPLOY_STATUS_CANCELLED => __t('status.job_cancelled'),
        VIRTUSPHERE_DEPLOY_STATUS_PARTIAL => __t('status.job_partial'),
        default => __t('status.job_unknown'),
    };
}

/**
 * The badge for a deploy-job status: variant from the existing class helper,
 * text from the label above. Callers render this instead of pairing a variant
 * with the raw value themselves.
 */
function deploy_job_status_badge(string $status): string
{
    return portal_badge(deploy_job_status_badge_class($status), deploy_job_status_label($status));
}

/**
 * The human name of a deploy mode.
 *
 * The six postable modes are exactly the keys of
 * virtusphere_deploy_mode_labels(); `inventory` is the seventh value a reader
 * can meet, because the ESXi scheduler queues it and it therefore appears in
 * the job list, on the System status Ansible card and in a job's own header.
 * It stays out of the technical set on purpose: that set decides what a POST
 * may contain, and a mode nobody can post must not become postable by being
 * given a name.
 */
function deploy_mode_label(string $mode): string
{
    return match ($mode) {
        VIRTUSPHERE_DEPLOY_MODE_FULL => __t('status.mode_full'),
        'create' => __t('status.mode_create'),
        'powercycle' => __t('status.mode_powercycle'),
        'export' => __t('status.mode_export'),
        'start' => __t('status.mode_start'),
        VIRTUSPHERE_DEPLOY_MODE_AUTOSTART => __t('status.mode_autostart'),
        VIRTUSPHERE_DEPLOY_MODE_INVENTORY => __t('status.mode_inventory'),
        default => __t('status.mode_unknown'),
    };
}

/**
 * The visible summary of a job's payload.
 *
 * deploy_job_payload_summary() stays exactly as it is and keeps writing the
 * technical summary into the retained job log, where it is evidence and must
 * not move with a display language. This is its portal-facing twin and the two
 * differ deliberately in one more way: `-vvv` survives verbatim, because it is
 * the Ansible flag itself and not a word this portal invented, while the scope
 * is a counted sentence rather than a bracketed number.
 */
function deploy_job_payload_display(?string $payloadJson): string
{
    if ($payloadJson === null || trim($payloadJson) === '') {
        return deploy_mode_label(VIRTUSPHERE_DEPLOY_MODE_FULL);
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return __t('status.mode_invalid_payload');
    }

    $parts = [deploy_mode_label((string) ($payload['mode'] ?? VIRTUSPHERE_DEPLOY_MODE_FULL))];
    if (!empty($payload['verbose'])) {
        $parts[] = '-vvv';
    }
    $vmIds = is_array($payload['vm_ids'] ?? null) ? $payload['vm_ids'] : [];
    if ($vmIds !== []) {
        $count = count($vmIds);
        // __t() substitutes but does not pluralize, so the sentence is chosen
        // by count instead of printing "VM(s)".
        $parts[] = __t($count === 1 ? 'status.mode_scope_one' : 'status.mode_scope_many', ['count' => (string) $count]);
    }

    return implode(' ', $parts);
}
