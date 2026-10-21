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
//
// The phase and the finding of a VM (lib/vm_phase.php) are two more closed
// vocabularies, derived at read time. Their words follow the same rule: an
// unknown value gets a neutral sentence, never the raw token. Timestamps go
// through portal_format_datetime(), modes through deploy_mode_label().
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/vm_phase.php';
require_once __DIR__ . '/portal_time.php';
require_once __DIR__ . '/deploy_mode_labels.php';

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

/**
 * The human name of a client deploy phase (fixed set, full-literal keys so the
 * lang catalog test can verify them). Moved unchanged from layout_presenters.php.
 */
function client_phase_label(string $phase): string
{
    return match ($phase) {
        VIRTUSPHERE_CLIENT_PHASE_GETINFO => __t('vm_edit.phase_getinfo'),
        VIRTUSPHERE_CLIENT_PHASE_HOSTNAME => __t('vm_edit.phase_hostname'),
        VIRTUSPHERE_CLIENT_PHASE_STATICIP => __t('vm_edit.phase_staticip'),
        VIRTUSPHERE_CLIENT_PHASE_DISKS => __t('vm_edit.phase_disks'),
        default => $phase,
    };
}

/** The visible name of a VM phase; an unknown value gets the neutral sentence. */
function portal_vm_phase_label(string $phase): string
{
    return match ($phase) {
        VIRTUSPHERE_VM_PHASE_CREATED => __t('status.phase_created'),
        VIRTUSPHERE_VM_PHASE_IN_PROGRESS => __t('status.phase_in_progress'),
        VIRTUSPHERE_VM_PHASE_ON_ESXI => __t('status.phase_on_esxi'),
        VIRTUSPHERE_VM_PHASE_MAC_KNOWN => __t('status.phase_mac_known'),
        VIRTUSPHERE_VM_PHASE_HANDED_TO_MECM => __t('status.phase_handed_to_mecm'),
        VIRTUSPHERE_VM_PHASE_WINDOWS_REPORTS => __t('status.phase_windows_reports'),
        VIRTUSPHERE_VM_PHASE_CLIENT_DONE => __t('status.phase_client_done'),
        VIRTUSPHERE_VM_PHASE_TEMPLATE => __t('status.phase_template'),
        default => __t('status.phase_unknown'),
    };
}

/**
 * The sentence of one finding, built from its kind and params (lib/vm_phase.php).
 * A missing time, mode or name selects the variant without it, so no empty
 * placeholder reaches the reader.
 *
 * @param array<string,mixed> $finding one entry of virtusphere_vm_findings()
 */
function portal_vm_finding_text(array $finding): string
{
    $kind = (string) ($finding['kind'] ?? '');
    $params = (array) ($finding['params'] ?? []);
    $text = static fn (string $name): string => trim((string) ($params[$name] ?? ''));
    $time = static fn (string $name): string => portal_format_datetime($text($name));

    return match ($kind) {
        VIRTUSPHERE_VM_FINDING_CREATE_UNCERTAIN => __t('status.finding_create_uncertain', [
            'job_id' => (int) ($params['job_id'] ?? 0),
            'position' => (int) ($params['position'] ?? 0),
        ]),
        VIRTUSPHERE_VM_FINDING_JOB_FAILED => $text('time') !== '' && $text('mode') !== ''
            ? __t('status.finding_job_failed', ['mode' => deploy_mode_label($text('mode')), 'time' => $time('time')])
            : __t('status.finding_job_failed_unknown'),
        VIRTUSPHERE_VM_FINDING_MISSING_FROM_INVENTORY => $text('host') !== ''
            ? __t('status.finding_missing_from_inventory', ['host' => $text('host'), 'time' => $time('time')])
            : __t('status.finding_missing_from_inventory_no_host', ['time' => $time('time')]),
        VIRTUSPHERE_VM_FINDING_JOB_RUNNING => $text('since') !== ''
            ? __t('status.finding_job_running', ['since' => $time('since'), 'mode' => deploy_mode_label($text('mode'))])
            : __t('status.finding_job_running_unknown'),
        VIRTUSPHERE_VM_FINDING_OLD_MECM_DEVICE => __t('status.finding_old_mecm_device', ['id' => $text('id')]),
        // An overdue wait without its since-time cannot occur (the overdue rule
        // reads the same timestamp); it falls back to the plain wait, not a gap.
        VIRTUSPHERE_VM_FINDING_MECM_PENDING_OVERDUE => $text('since') !== ''
            ? __t('status.finding_mecm_pending_overdue', ['since' => $time('since'), 'hours' => (int) ($params['hours'] ?? 0)])
            : __t('status.finding_mecm_pending'),
        VIRTUSPHERE_VM_FINDING_MECM_PENDING => $text('since') !== ''
            ? __t('status.finding_mecm_pending_since', ['since' => $time('since')])
            : __t('status.finding_mecm_pending'),
        VIRTUSPHERE_VM_FINDING_POWERED_OFF => __t('status.finding_powered_off', ['time' => $time('time')]),
        VIRTUSPHERE_VM_FINDING_OS_INSTALL_OVERDUE => __t('status.finding_os_install_overdue', ['hours' => (int) ($params['hours'] ?? 0)]),
        VIRTUSPHERE_VM_FINDING_PXE_UNWATCHED => __t('status.finding_pxe_unwatched'),
        VIRTUSPHERE_VM_FINDING_OS_INSTALL_WATCHING => __t('status.finding_os_install_watching', ['since' => $time('since')]),
        VIRTUSPHERE_VM_FINDING_CLIENT_PHASE_FAILED => __t('status.finding_client_phase_failed', ['phase' => client_phase_label($text('phase'))]),
        VIRTUSPHERE_VM_FINDING_CLIENT_PHASE_UNCONFIRMED => __t('status.finding_client_phase_unconfirmed', [
            'phase' => client_phase_label($text('phase')),
            'minutes' => (int) ($params['minutes'] ?? 0),
        ]),
        VIRTUSPHERE_VM_FINDING_PACKAGE_RUN_FAILED => __t('status.finding_package_run_failed', ['package' => $text('package')]),
        VIRTUSPHERE_VM_FINDING_MECM_SYNC_FAILED => __t('status.mecm_failed'),
        default => __t('status.finding_unknown'),
    };
}
