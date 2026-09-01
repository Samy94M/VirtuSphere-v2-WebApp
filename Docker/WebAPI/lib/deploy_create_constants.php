<?php

declare(strict_types=1);

require_once __DIR__ . '/remote_execution_constants.php';

/**
 * SSoT of the per-VM create contract (Etappe 14B, plan section 6.3).
 *
 * One VM of one deploy job is one create unit with one durable row in
 * deploy_create_vm_results. Everything a reader has to agree on about such a
 * row lives here: its closed status set, the two actions a queued job can
 * materialize, the outcome vocabulary, the closed error codes and the timing
 * bounds. deploy_constants.php loads this module as a facade, so no page,
 * worker or test keeps a second hand-written list.
 *
 * What this module deliberately does NOT own: anything about the remote
 * handle. The unit's async directory, its cleanup state machine, its retry
 * budget and its last cleanup error belong to deploy_remote_executions, and a
 * create row reaches them through remote_execution_id. Copying them here would
 * give one question two owners, which is the defect this plan exists to
 * remove, one level down.
 */

// Status values in ENUM mirror order (ADR-0016, check-enum-sync). The order is
// the path through the machine: a unit is pending until its live identity
// check passed, prepared until its async job is durably known, running while
// exactly one async job of this deploy job exists, and then terminal. uncertain
// sits after the terminal pair on purpose: it is not a softer failure, it is
// the state in which VirtuSphere knows that it does not know, and it always
// stops the job instead of continuing with the next VM.
const VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING = 'pending';
const VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED = 'prepared';
const VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING = 'running';
const VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED = 'succeeded';
const VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED = 'failed';
const VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN = 'uncertain';
const VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED = 'skipped';

const VIRTUSPHERE_CREATE_RESULT_STATUSES = [
    VIRTUSPHERE_CREATE_RESULT_STATUS_PENDING,
    VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED,
    VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING,
    VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
    VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
    VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
    VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED,
];

// "Bearbeitet" in the plan's vocabulary: the unit will not be worked on again
// inside this job. Progress counts against this set, never against "not
// pending".
const VIRTUSPHERE_CREATE_RESULT_PROCESSED_STATUSES = [
    VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
    VIRTUSPHERE_CREATE_RESULT_STATUS_FAILED,
    VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
    VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED,
];

// A unit that is in flight. The next unit may only start when none of these is
// present, which is invariant 10 of the plan and the reason the worker can
// never have two async jobs of one deploy job at once.
const VIRTUSPHERE_CREATE_RESULT_INFLIGHT_STATUSES = [
    VIRTUSPHERE_CREATE_RESULT_STATUS_PREPARED,
    VIRTUSPHERE_CREATE_RESULT_STATUS_RUNNING,
    VIRTUSPHERE_CREATE_RESULT_STATUS_UNCERTAIN,
];

// Create succeeded for this unit. `skipped` belongs here: a retry that verified
// an earlier confirmed success live did not create anything, and counting it as
// a failure would send an operator after a VM that is provably there.
const VIRTUSPHERE_CREATE_RESULT_SUCCESSFUL_STATUSES = [
    VIRTUSPHERE_CREATE_RESULT_STATUS_SUCCEEDED,
    VIRTUSPHERE_CREATE_RESULT_STATUS_SKIPPED,
];

// Action values in ENUM mirror order. Exactly two, because resuming is not a
// third one: a resumed unit keeps its row, its action and its async job id and
// only moves through the status machine.
const VIRTUSPHERE_CREATE_ACTION_CREATE = 'create';
const VIRTUSPHERE_CREATE_ACTION_VERIFY_SKIP = 'verify_skip';

const VIRTUSPHERE_CREATE_ACTIONS = [
    VIRTUSPHERE_CREATE_ACTION_CREATE,
    VIRTUSPHERE_CREATE_ACTION_VERIFY_SKIP,
];

// Outcome values in ENUM mirror order; set only for a successful or skipped
// unit. They answer "what did the module do", never "is the VM there".
const VIRTUSPHERE_CREATE_OUTCOME_CREATED = 'created';
const VIRTUSPHERE_CREATE_OUTCOME_UPDATED = 'updated';
const VIRTUSPHERE_CREATE_OUTCOME_UNCHANGED = 'unchanged';

const VIRTUSPHERE_CREATE_OUTCOMES = [
    VIRTUSPHERE_CREATE_OUTCOME_CREATED,
    VIRTUSPHERE_CREATE_OUTCOME_UPDATED,
    VIRTUSPHERE_CREATE_OUTCOME_UNCHANGED,
];

// Closed error codes (plan section 6.4). The code comes from the controlled
// branch that established the failure, never from Ansible prose: a module
// message is free text that changes with an upstream release, and a portal
// decision made from it would change with it.
const VIRTUSPHERE_CREATE_ERROR_IDENTITY_CONFLICT = 'identity_conflict';
const VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED = 'module_failed';
const VIRTUSPHERE_CREATE_ERROR_LAUNCH_UNCONFIRMED = 'launch_unconfirmed';
const VIRTUSPHERE_CREATE_ERROR_ASYNC_STATE_MISSING = 'async_state_missing';
const VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID = 'identity_result_invalid';
const VIRTUSPHERE_CREATE_ERROR_TRANSPORT_LOST = 'transport_lost';
const VIRTUSPHERE_CREATE_ERROR_JOB_TIMEOUT = 'job_timeout';
const VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR = 'protocol_error';
const VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST = 'ownership_lost';
const VIRTUSPHERE_CREATE_ERROR_OPERATOR_RELEASED = 'operator_released';

const VIRTUSPHERE_CREATE_ERROR_CODES = [
    VIRTUSPHERE_CREATE_ERROR_IDENTITY_CONFLICT,
    VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED,
    VIRTUSPHERE_CREATE_ERROR_LAUNCH_UNCONFIRMED,
    VIRTUSPHERE_CREATE_ERROR_ASYNC_STATE_MISSING,
    VIRTUSPHERE_CREATE_ERROR_IDENTITY_RESULT_INVALID,
    VIRTUSPHERE_CREATE_ERROR_TRANSPORT_LOST,
    VIRTUSPHERE_CREATE_ERROR_JOB_TIMEOUT,
    VIRTUSPHERE_CREATE_ERROR_PROTOCOL_ERROR,
    VIRTUSPHERE_CREATE_ERROR_OWNERSHIP_LOST,
    VIRTUSPHERE_CREATE_ERROR_OPERATOR_RELEASED,
];

// Which codes an automatic retry may re-run without a human deciding first.
// Everything outside this set names a state where re-running could create a
// second VM or overwrite evidence: the outcome has to be established, by a
// resumed poll or by an operator, before anything starts again.
const VIRTUSPHERE_CREATE_AUTO_RETRYABLE_ERROR_CODES = [
    VIRTUSPHERE_CREATE_ERROR_MODULE_FAILED,
    VIRTUSPHERE_CREATE_ERROR_OPERATOR_RELEASED,
];

// Timing. Every value is seconds, and every relation between them is pinned by
// DeployCreateConstantsContractTest rather than by comment: a poll interval
// above the stale limit would let the job look abandoned between two healthy
// polls, and a discovery interval above its own timeout would never poll.
const VIRTUSPHERE_CREATE_POLL_INTERVAL_SECONDS = 30;
const VIRTUSPHERE_CREATE_CONTROL_IDLE_TIMEOUT_SECONDS = 120;
const VIRTUSPHERE_CREATE_CONTROL_TOTAL_TIMEOUT_SECONDS = 300;
const VIRTUSPHERE_CREATE_JID_DISCOVERY_INTERVAL_SECONDS = 5;
const VIRTUSPHERE_CREATE_JID_DISCOVERY_TIMEOUT_SECONDS = 90;
const VIRTUSPHERE_CREATE_TRANSPORT_BACKOFF_MIN_SECONDS = 5;
const VIRTUSPHERE_CREATE_TRANSPORT_BACKOFF_MAX_SECONDS = 60;

/**
 * Wall-clock budget of the create section of one deploy job, measured from
 * deploy_jobs.create_started_at. Derived, not repeated: the freed decision F2
 * keeps the create budget at the SSH total budget, and a second copy of that
 * number here would stop moving when the first one does.
 */
function deploy_create_total_budget_seconds(): int
{
    return VIRTUSPHERE_SSH_TOTAL_TIMEOUT_SECONDS;
}

// The async job id comes back from a remote host and is later part of a remote
// command line. It is validated against this class BEFORE it is stored, not
// before it is used: a value that never enters the database cannot reach a
// shell through some later path that forgot to check.
const VIRTUSPHERE_CREATE_JID_PATTERN = '/^[A-Za-z0-9._-]{1,191}$/';
const VIRTUSPHERE_CREATE_JID_MAX_LENGTH = 191;

// Fixed subdirectory of the bound remote handle's directory. Never a home
// subfolder and never a portal-supplied path: the handle already carries the
// instance, generation, job, attempt, step key and run token, so this name is
// the only part left to fix.
const VIRTUSPHERE_CREATE_ASYNC_DIR_NAME = 'async';

// The one machine-readable stdout contract of the create playbooks
// (plan section 8.4): the prefix, the protocol version, and nothing else that
// a reader is allowed to parse.
const VIRTUSPHERE_CREATE_MARKER_PREFIX = '::virtusphere-create::';
const VIRTUSPHERE_CREATE_PROTOCOL_VERSION = 1;

// The four control playbooks of one create unit, plus the two files they share.
// They are NOT deploy modes and therefore not in VIRTUSPHERE_PLAYBOOKS; they
// are steps the worker drives, one VM at a time. They are listed here because
// the artifact upload has to carry them: a playbook that is dispatched but not
// uploaded dies on the host with "could not be found", which this project has
// already paid for once with the inventory playbook.
//
// The shared identity tasks live as a FLAT file rather than under tasks/,
// because the SFTP upload copies only the top level of the work directory. A
// subdirectory would be dropped silently.
const VIRTUSPHERE_CREATE_PLAYBOOK_PREPARE = 'createVMPrepare-ESXi_playbook.yml';
const VIRTUSPHERE_CREATE_PLAYBOOK_LAUNCH = 'createVMLaunch-ESXi_playbook.yml';
const VIRTUSPHERE_CREATE_PLAYBOOK_STATUS = 'createVMStatus-ESXi_playbook.yml';
const VIRTUSPHERE_CREATE_PLAYBOOK_CLEANUP = 'createVMCleanup-ESXi_playbook.yml';
const VIRTUSPHERE_CREATE_IDENTITY_TASKS = 'create_identity_check_tasks.yml';
const VIRTUSPHERE_CREATE_RESULT_EMITTER = 'emit_create_result.py';

/**
 * The extra-vars the worker passes to a create control playbook, per playbook.
 *
 * This is the third source a playbook variable can come from, next to the
 * generated serverlist and accounts files, and it is declared here so it is
 * checkable in both directions: a playbook that reads a name nobody passes
 * fails on the host, and a name declared here that no playbook reads is a
 * leftover. AnsiblePlaybookVariableContractTest walks both directions.
 *
 * Everything here is worker-controlled. Nothing in this list may ever be filled
 * from a portal form: the async directory comes from the bound remote handle,
 * the job id from what the launch stored, and the target from the materialized
 * unit.
 */
const VIRTUSPHERE_CREATE_EXTRA_VARS = [
    VIRTUSPHERE_CREATE_PLAYBOOK_PREPARE => ['vs_portal_vm_id', 'vs_result_file'],
    VIRTUSPHERE_CREATE_PLAYBOOK_LAUNCH => [
        'vs_portal_vm_id', 'vs_result_file', 'vs_async_dir', 'vs_async_timeout',
        'vs_expected_existed_before', 'vs_expected_moid', 'vs_expected_instance_uuid',
    ],
    VIRTUSPHERE_CREATE_PLAYBOOK_STATUS => ['vs_portal_vm_id', 'vs_result_file', 'vs_async_dir', 'vs_async_jid'],
    VIRTUSPHERE_CREATE_PLAYBOOK_CLEANUP => ['vs_async_jid', 'vs_async_dir'],
];

const VIRTUSPHERE_CREATE_ARTIFACTS = [
    VIRTUSPHERE_CREATE_PLAYBOOK_PREPARE,
    VIRTUSPHERE_CREATE_PLAYBOOK_LAUNCH,
    VIRTUSPHERE_CREATE_PLAYBOOK_STATUS,
    VIRTUSPHERE_CREATE_PLAYBOOK_CLEANUP,
    VIRTUSPHERE_CREATE_IDENTITY_TASKS,
    VIRTUSPHERE_CREATE_RESULT_EMITTER,
];

/**
 * True when the value may be stored as an async job id. Length is checked
 * explicitly as well as by the pattern, because the column is VARCHAR(191) and
 * a silently truncated id would poll a job that does not exist.
 */
function deploy_create_jid_is_valid(string $jid): bool
{
    return $jid !== ''
        && strlen($jid) <= VIRTUSPHERE_CREATE_JID_MAX_LENGTH
        && preg_match(VIRTUSPHERE_CREATE_JID_PATTERN, $jid) === 1;
}

/** The remote handle's step key for the unit at this one-based position. */
function deploy_create_step_key(int $position): string
{
    if ($position < 1) {
        throw new InvalidArgumentException('Create position is one-based.');
    }

    return 'create.vm.' . $position;
}
