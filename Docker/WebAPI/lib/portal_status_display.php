<?php

declare(strict_types=1);

// Portal-only labels for the two VM state axes.
//
// The split is deliberate and it is the whole point of this module: the VALUE
// stays technical everywhere it is persisted or transported (the `lifecycle` and
// `mecm_sync` columns, `virtusphere_legacy_status_from_states()`, the five
// legacy status strings and every machine-API field), and only what a person
// reads passes through here. Nothing may translate on the way IN.
//
// The badge VARIANT is not decided here either. It keeps coming from the meta
// SSoT in lib/status.php, so colour and text cannot drift apart: a new state
// added there gets its colour automatically and shows up in this module's
// constant-walk test as a missing label rather than as a silently grey badge.
//
// An unknown value is answered with a neutral localized sentence and NOT with
// the raw value. Echoing the raw value was the previous behaviour and it is
// what put `os_installing` in front of an operator. It also writes no
// error_log: rendering a list of VMs must not turn one legacy row into a log
// line per page view.
require_once __DIR__ . '/constants.php';

/**
 * The human name of a VM lifecycle state.
 *
 * @param string $lifecycleState a VIRTUSPHERE_LIFECYCLE_* value
 */
function portal_lifecycle_label(string $lifecycleState): string
{
    return match ($lifecycleState) {
        VIRTUSPHERE_LIFECYCLE_INITIALIZING => __t('status.lifecycle_initializing'),
        VIRTUSPHERE_LIFECYCLE_READY => __t('status.lifecycle_ready'),
        VIRTUSPHERE_LIFECYCLE_DEPLOYING => __t('status.lifecycle_deploying'),
        VIRTUSPHERE_LIFECYCLE_DEPLOYED => __t('status.lifecycle_deployed'),
        VIRTUSPHERE_LIFECYCLE_OS_INSTALLING => __t('status.lifecycle_os_installing'),
        VIRTUSPHERE_LIFECYCLE_OS_INSTALLED => __t('status.lifecycle_os_installed'),
        VIRTUSPHERE_LIFECYCLE_FAILED => __t('status.lifecycle_failed'),
        default => __t('status.lifecycle_unknown'),
    };
}

/**
 * The human name of a MECM sync state.
 *
 * @param string $mecmSyncState a VIRTUSPHERE_MECM_SYNC_* value
 */
function portal_mecm_sync_label(string $mecmSyncState): string
{
    return match ($mecmSyncState) {
        VIRTUSPHERE_MECM_SYNC_NOT_READY => __t('status.mecm_not_ready'),
        VIRTUSPHERE_MECM_SYNC_PENDING => __t('status.mecm_pending'),
        VIRTUSPHERE_MECM_SYNC_REGISTERED => __t('status.mecm_registered'),
        VIRTUSPHERE_MECM_SYNC_FAILED => __t('status.mecm_failed'),
        default => __t('status.mecm_unknown'),
    };
}

/**
 * The dashboard's mission status.
 *
 * `deploy_missions.mission_status` is a free VARCHAR that only ever carried
 * `active` from this application, so it is not an enum and must not be rendered
 * as one. Any other stored text is shown AS ITSELF, because inventing a label
 * for a value nobody in this repository writes would be a guess presented as
 * fact.
 *
 * The empty case stays with the caller on purpose: an absent value is a table
 * cell placeholder, which is a layout decision, and giving this function a
 * second owner for the em dash would put the same rule in two places.
 */
function portal_mission_status_label(string $missionStatus): string
{
    $trimmed = trim($missionStatus);

    return $trimmed === VIRTUSPHERE_MISSION_STATUS_DEFAULT ? __t('status.mission_active') : $trimmed;
}
