<?php

declare(strict_types=1);

require_once __DIR__ . '/defaults.php';

// Declaration order is the ENUM mirror order (ADR-0016, check-enum-sync):
// cancelling sits between running and the terminal states, exactly where the
// machine passes through it. A running job whose cancel was requested keeps
// lock, heartbeat and every protective effect until the worker confirms at a
// step boundary or the reaper converges a dead worker (ADR-0033, decision 4).
const VIRTUSPHERE_DEPLOY_STATUS_QUEUED = 'queued';
const VIRTUSPHERE_DEPLOY_STATUS_RUNNING = 'running';
const VIRTUSPHERE_DEPLOY_STATUS_CANCELLING = 'cancelling';
const VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED = 'succeeded';
const VIRTUSPHERE_DEPLOY_STATUS_FAILED = 'failed';
const VIRTUSPHERE_DEPLOY_STATUS_CANCELLED = 'cancelled';
const VIRTUSPHERE_DEPLOY_STATUS_PARTIAL = 'partial';

// Active = the job still owns its mission (delete/enqueue blocks, the
// one-job-per-mission and one-pull-per-credential guards). Cancelling belongs
// here: the playbook may still be executing its current step.
const VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES = [
    VIRTUSPHERE_DEPLOY_STATUS_QUEUED,
    VIRTUSPHERE_DEPLOY_STATUS_RUNNING,
    VIRTUSPHERE_DEPLOY_STATUS_CANCELLING,
];

// What the cancel button (and a cancel POST) may act on. Deliberately NOT the
// active set: a cancelling job is active but already has its wish recorded; a
// second cancel is idempotent in the repo and pointless in the UI.
const VIRTUSPHERE_DEPLOY_JOB_CANCELLABLE_STATUSES = [
    VIRTUSPHERE_DEPLOY_STATUS_QUEUED,
    VIRTUSPHERE_DEPLOY_STATUS_RUNNING,
];

// `partial` is terminal: the sequence ended and the per-VM result is durable in
// result_json. Membership here also opts partial jobs into the retention purges
// (repo_purge_deploy_job_logs / repo_purge_finished_system_jobs), which is
// intended - a partial job ages like any finished job.
const VIRTUSPHERE_DEPLOY_JOB_TERMINAL_STATUSES = [
    VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED,
    VIRTUSPHERE_DEPLOY_STATUS_FAILED,
    VIRTUSPHERE_DEPLOY_STATUS_CANCELLED,
    VIRTUSPHERE_DEPLOY_STATUS_PARTIAL,
];

const VIRTUSPHERE_DEPLOY_LOG_STDOUT = 'stdout';
const VIRTUSPHERE_DEPLOY_LOG_STDERR = 'stderr';
const VIRTUSPHERE_DEPLOY_LOG_SYSTEM = 'system';

const VIRTUSPHERE_DEPLOY_LOG_STREAMS = [
    VIRTUSPHERE_DEPLOY_LOG_STDOUT,
    VIRTUSPHERE_DEPLOY_LOG_STDERR,
    VIRTUSPHERE_DEPLOY_LOG_SYSTEM,
];

const VIRTUSPHERE_DEPLOY_MODE_FULL = 'full';
const VIRTUSPHERE_DEPLOY_HEARTBEAT_INTERVAL_SECONDS = 30;
const VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS = 600;

// SSH transport hardening (AP6). A remote command used to run with an
// unbounded timeout, and a timed-out exec came back as exit 0. These four
// constants are the SSoT for the bounded transport in lib/ssh.php:
//  - KEEPALIVE: SSH_MSG_IGNORE interval, keeps NAT/firewall state alive.
//  - SILENCE_TICK: read-slice length; every slice without remote output calls
//    the onSilence hook, which is what makes the worker heartbeat time-based
//    instead of output-based. Must stay below HEARTBEAT_INTERVAL and far below
//    STALE_AFTER, otherwise a silent-but-alive playbook gets reaped mid-run.
//  - IDLE: no remote output at all for this long fails the command. Generous,
//    because vmware_guest clones a template for many minutes without printing.
//  - TOTAL: wall-clock cap per remote command, however chatty. A full pipeline
//    over many VMs is slow, but nothing legitimate runs for four hours.
const VIRTUSPHERE_SSH_KEEPALIVE_INTERVAL_SECONDS = 15;
const VIRTUSPHERE_SSH_SILENCE_TICK_SECONDS = 15;
const VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS = 1800;
const VIRTUSPHERE_SSH_TOTAL_TIMEOUT_SECONDS = 14400;

// SFTP upload of the generated deploy artifacts (serverlist.yml, accounts.yml,
// the patched upload script - all a few KB). Two bounds, mirroring the exec
// path: a per-operation read timeout so one stalled transfer cannot hang the
// worker forever, and a wall-clock cap across the whole directory. Both are
// tight because these files are small; a legitimate upload finishes in seconds.
const VIRTUSPHERE_SFTP_OP_TIMEOUT_SECONDS = 120;
const VIRTUSPHERE_SFTP_TOTAL_TIMEOUT_SECONDS = 300;

// Second reaper (AP6): the deploy worker reaps only at its own loop start, so
// a worker stuck inside a blocking transport call would never be reaped until
// it returns. The maintenance worker runs the same reap on this interval.
const VIRTUSPHERE_DEPLOY_REAP_INTERVAL_SECONDS = 120;

// Convergence sweep interval (L4): the maintenance worker periodically fails
// VMs stuck in `deploying` whose mission has no queued/running job left. That
// covers the fault the deploy worker cannot: it died (or was cancelled and then
// died) before its own catch could mark the VMs, and the heartbeat reaper only
// touches jobs that are still `running`.
const VIRTUSPHERE_DEPLOY_VM_SWEEP_INTERVAL_SECONDS = 300;

// Scheduling (ADR-0022). scheduled_at is stored in UTC and compared against
// UTC_TIMESTAMP(); staggering only makes sense for the power-on modes.
const VIRTUSPHERE_DEPLOY_SCHEDULE_HORIZON_DAYS = 30;
const VIRTUSPHERE_DEPLOY_SCHEDULE_PAST_GRACE_SECONDS = 300;
const VIRTUSPHERE_DEPLOY_STAGGER_MIN = 1;
const VIRTUSPHERE_DEPLOY_STAGGER_MAX = 120;
const VIRTUSPHERE_DEPLOY_STAGGER_MODES = ['full', 'powercycle', 'start'];

// Deploy modes that create VMs and thus change ESXi resource usage (datastore
// allocation, portgroups in use). A successful job in one of these triggers an
// inventory refresh for its ESXi credential (ADR-0023, E3.4b). Power-cycle,
// start and export do not create resources and are excluded.
const VIRTUSPHERE_DEPLOY_INVENTORY_REFRESH_MODES = ['create', VIRTUSPHERE_DEPLOY_MODE_FULL];

// ESXi inventory (ADR-0023): a system deploy mode that is never shown in the UI
// (deliberately absent from virtusphere_deploy_mode_labels()). Its playbook is
// read-only and runs without a mission.
const VIRTUSPHERE_DEPLOY_MODE_INVENTORY = 'inventory';
const VIRTUSPHERE_SYSTEM_PLAYBOOKS = [
    VIRTUSPHERE_DEPLOY_MODE_INVENTORY => 'inventoryESXi_playbook.yml',
];

// ESXi autostart policy (ADR-0025). The deploy mode writes the host's autostart
// configuration; it creates no resources and moves no power, so it is in neither
// VIRTUSPHERE_DEPLOY_STAGGER_MODES nor VIRTUSPHERE_DEPLOY_INVENTORY_REFRESH_MODES.
const VIRTUSPHERE_DEPLOY_MODE_AUTOSTART = 'autostart';

// SSoT for the deploy_missions.autostart_stop_action ENUM (mirrored order-exact
// in struktur.sql + migrate.php, checked by scripts/check-enum-sync.sh).
// These are community.vmware.vmware_host_auto_start's system_defaults.stop_action
// values verbatim; do not translate or re-case them, the module compares strings.
const VIRTUSPHERE_AUTOSTART_STOP_ACTION_GUEST_SHUTDOWN = 'guestShutdown';
const VIRTUSPHERE_AUTOSTART_STOP_ACTION_POWER_OFF = 'powerOff';
const VIRTUSPHERE_AUTOSTART_STOP_ACTION_SUSPEND = 'suspend';
const VIRTUSPHERE_AUTOSTART_STOP_ACTION_NONE = 'none';
const VIRTUSPHERE_AUTOSTART_STOP_ACTIONS = [
    VIRTUSPHERE_AUTOSTART_STOP_ACTION_GUEST_SHUTDOWN,
    VIRTUSPHERE_AUTOSTART_STOP_ACTION_POWER_OFF,
    VIRTUSPHERE_AUTOSTART_STOP_ACTION_SUSPEND,
    VIRTUSPHERE_AUTOSTART_STOP_ACTION_NONE,
];

// Delay bounds in seconds. The mission default must be a real wait (>= 0); a VM
// may additionally store VIRTUSPHERE_AUTOSTART_DELAY_INHERIT, which the module
// reads as "use the host default". 0 and -1 are NOT interchangeable: 0 means
// "no wait", -1 means "inherit". Keep them apart in every layer.
const VIRTUSPHERE_AUTOSTART_DELAY_INHERIT = -1;
const VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT = 120;
const VIRTUSPHERE_AUTOSTART_DELAY_MIN = 0;
const VIRTUSPHERE_AUTOSTART_DELAY_MAX = 3600;

// The autostart entry that means "this VM does not participate". Writing it is
// how the deactivation path removes a VM from the host's autostart list.
const VIRTUSPHERE_AUTOSTART_START_ACTION_ON = 'powerOn';
const VIRTUSPHERE_AUTOSTART_START_ACTION_OFF = 'none';

// SSoT for the deploy_esxi_inventory.kind ENUM (mirrored order-exact in
// struktur.sql + migrate.php, checked by scripts/check-enum-sync.sh, ADR-0016).
const VIRTUSPHERE_INVENTORY_KIND_DATACENTER = 'datacenter';
const VIRTUSPHERE_INVENTORY_KIND_DATASTORE = 'datastore';
const VIRTUSPHERE_INVENTORY_KIND_NETWORK = 'network';
const VIRTUSPHERE_INVENTORY_KIND_HOST = 'host';
const VIRTUSPHERE_INVENTORY_KIND_VM = 'vm';
const VIRTUSPHERE_INVENTORY_KINDS = [
    VIRTUSPHERE_INVENTORY_KIND_DATACENTER,
    VIRTUSPHERE_INVENTORY_KIND_DATASTORE,
    VIRTUSPHERE_INVENTORY_KIND_NETWORK,
    VIRTUSPHERE_INVENTORY_KIND_HOST,
    VIRTUSPHERE_INVENTORY_KIND_VM,
];

// Outcome of a single query inside one inventory pull. A pull is several
// separate queries and only the first one (datacenters) is the connection
// canary, so a pull can succeed while one query answered nothing. These three
// words are what separates "the host has none" from "my call never got there",
// which an empty list alone cannot say. Job-log vocabulary, not a portal
// vocabulary: nothing here colours a badge or blocks anything.
const VIRTUSPHERE_INVENTORY_QUERY_ANSWERED = 'answered';
const VIRTUSPHERE_INVENTORY_QUERY_REJECTED = 'rejected';
const VIRTUSPHERE_INVENTORY_QUERY_SKIPPED = 'skipped';
// The module's own message is kept for the log, but only its beginning: the
// full playbook output is already in the same job log, and one runaway message
// must not push the summary line out of a reader's view.
const VIRTUSPHERE_INVENTORY_QUERY_MESSAGE_MAX_LENGTH = 200;

// Connection failure categories: the shared vocabulary for the inventory fetch
// (stored in deploy_esxi_inventory_state.last_error_category) and the credential
// connection test. Two classifiers feed it, because their inputs differ:
// ansible_categorize_inventory_error() reads playbook stdout,
// connection_error_category() reads PHP/OpenSSL/phpseclib error text.
// Only AUTH pauses a credential (repo_esxi_inventory_record_failure).
const VIRTUSPHERE_INVENTORY_ERROR_DNS = 'dns';
const VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE = 'unreachable';
const VIRTUSPHERE_INVENTORY_ERROR_CERTIFICATE = 'certificate';
const VIRTUSPHERE_INVENTORY_ERROR_TLS = 'tls';
const VIRTUSPHERE_INVENTORY_ERROR_AUTH = 'auth';
const VIRTUSPHERE_INVENTORY_ERROR_AUTHZ = 'authz';
const VIRTUSPHERE_INVENTORY_ERROR_HTTP = 'http';
const VIRTUSPHERE_INVENTORY_ERROR_SSH = 'ssh';
const VIRTUSPHERE_INVENTORY_ERROR_WORKER = 'worker';
const VIRTUSPHERE_INVENTORY_ERROR_PARSE = 'parse';
const VIRTUSPHERE_INVENTORY_ERROR_CONFIG = 'config';
const VIRTUSPHERE_INVENTORY_ERROR_CATEGORIES = [
    VIRTUSPHERE_INVENTORY_ERROR_DNS,
    VIRTUSPHERE_INVENTORY_ERROR_UNREACHABLE,
    VIRTUSPHERE_INVENTORY_ERROR_CERTIFICATE,
    VIRTUSPHERE_INVENTORY_ERROR_TLS,
    VIRTUSPHERE_INVENTORY_ERROR_AUTH,
    VIRTUSPHERE_INVENTORY_ERROR_AUTHZ,
    VIRTUSPHERE_INVENTORY_ERROR_HTTP,
    VIRTUSPHERE_INVENTORY_ERROR_SSH,
    VIRTUSPHERE_INVENTORY_ERROR_WORKER,
    VIRTUSPHERE_INVENTORY_ERROR_PARSE,
    VIRTUSPHERE_INVENTORY_ERROR_CONFIG,
];

// Why the interval automation would skip an ESXi credential. The scheduler
// decides with these and the Credentials page names them, so a fourth blocker
// cannot reach one of the two without the other. Order is the order an operator
// has to fix them in, not the order of the checks: a global switch outranks a
// per-credential pause, because un-pausing cannot start a cycle that the
// interval or the missing Ansible host has already stopped.
const VIRTUSPHERE_ESXI_AUTOMATION_INTERVAL_OFF = 'interval_off';
const VIRTUSPHERE_ESXI_AUTOMATION_NO_ANSIBLE_HOST = 'no_ansible_host';
// A dead deploy worker stops the cycle as thoroughly as a switched-off interval:
// the pull is a deploy JOB, so the maintenance worker enqueues it and the deploy
// worker executes it. With no deploy worker the job is queued and nothing runs
// it, while the cadence line promised a cycle. Ranked ahead of the per-credential
// pause and behind the two global settings, in fix order: un-pausing one
// credential cannot start a cycle that has no executor.
const VIRTUSPHERE_ESXI_AUTOMATION_NO_WORKER = 'no_deploy_worker';
const VIRTUSPHERE_ESXI_AUTOMATION_PAUSED = 'paused';
const VIRTUSPHERE_ESXI_AUTOMATION_BLOCKERS = [
    VIRTUSPHERE_ESXI_AUTOMATION_INTERVAL_OFF,
    VIRTUSPHERE_ESXI_AUTOMATION_NO_ANSIBLE_HOST,
    VIRTUSPHERE_ESXI_AUTOMATION_NO_WORKER,
    VIRTUSPHERE_ESXI_AUTOMATION_PAUSED,
];

// Inventory settings + defaults.
const VIRTUSPHERE_SETTING_ESXI_INVENTORY_INTERVAL_HOURS = 'esxi_inventory_interval_hours';
const VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL = 'esxi_inventory_ansible_credential';
const VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_DEFAULT = 6;
// Bounds of the interval an admin may set. 0 is not "no interval" but "off": the
// automatic pull stops and only the manual refresh remains. The upper bound is a
// week, past which a cached inventory says more about the past than the present.
// Named because the number lived in three places at once (the POST validation,
// the input's max attribute and the error text), and a change to any one of them
// left the other two lying.
const VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_MIN = 0;
const VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_MAX = 168;
// How often the maintenance worker re-evaluates which credentials are due.
const VIRTUSPHERE_ESXI_INVENTORY_SCHEDULE_CHECK_SECONDS = 300;
// ESXi host clock skew above this (seconds) is flagged as a warning (E9).
const VIRTUSPHERE_ESXI_CLOCK_SKEW_WARN_SECONDS = 120;

// Inventory fetch traffic light (esxi_inventory_ampel): danger at this many
// consecutive failures; a last success older than STALE_FACTOR x interval turns
// warning. The system-status legend interpolates these same constants, so the
// user-facing text cannot drift from the code.
const VIRTUSPHERE_ESXI_INVENTORY_FAILURE_STREAK_DANGER = 3;
const VIRTUSPHERE_ESXI_INVENTORY_STALE_FACTOR = 2;

// Capability facts of a SUCCESSFUL pull (ADR-0023 amendment 3), stored on
// deploy_esxi_inventory_state. Deliberately separate from the error categories
// above: those describe a fetch that failed, these describe what the host is.
// A NULL value means "not known", never "false".
const VIRTUSPHERE_ESXI_CAPABILITY_FIELDS = [
    'api_type',
    'product_version',
    'license_product',
    'license_free',
    'in_ha_cluster',
    'in_maintenance',
];

// Substrings that identify a free (non-write-capable) ESXi license in the
// about-info's licenseProductName. Broadcom reinstated the free hypervisor with
// ESXi 8.0 U3e; its API is read-only, so deploy and autostart cannot work there.
const VIRTUSPHERE_ESXI_FREE_LICENSE_MARKERS = ['hypervisor', 'free'];

/**
 * Every mode a stored payload may legally carry, system modes included. Read
 * side: the worker reads back a queued job's payload with this.
 */
function virtusphere_deploy_modes(): array
{
    return array_merge(
        array_keys(VIRTUSPHERE_PLAYBOOKS),
        [VIRTUSPHERE_DEPLOY_MODE_FULL],
        array_keys(VIRTUSPHERE_SYSTEM_PLAYBOOKS)
    );
}

/**
 * The modes an operator may ask for, which is exactly the set the deploy form
 * offers. Write side: a mission job is created only with one of these.
 *
 * The label map is the source of truth for it, so a mode without a label cannot
 * be posted and a postable mode cannot be unlabelled. System modes (inventory)
 * are deliberately absent from the labels, and were previously accepted by the
 * job validator anyway: a crafted POST could queue a mission job the worker then
 * routed into the mission-less inventory branch.
 */
function virtusphere_user_deploy_modes(): array
{
    return array_keys(virtusphere_deploy_mode_labels());
}

/**
 * Whether a mode needs the mission's datacenter and datastore. Autostart writes
 * the host's boot configuration and touches neither, so refusing it because a
 * mission has no datastore would be a gate answering the wrong question.
 * Everything else creates, powers or exports VMs and needs a location.
 *
 * It reads the mode the way the validators write it, because three gates ask
 * this question (the page, the repository, the Ansible layer) and a bare
 * inequality answered "needs a location" for every spelling it did not
 * recognise. A mode differing only in case therefore made the page refuse what
 * the enqueue path would accept. An unknown mode throws rather than inheriting
 * that answer: a location-free mode added later must be given one here, and
 * DeployModeGateTest walks the mode list so the omission fails the build.
 */
function virtusphere_deploy_mode_needs_location(string $mode): bool
{
    $mode = strtolower(trim($mode));
    if (!in_array($mode, virtusphere_deploy_modes(), true)) {
        throw new LogicException('Unknown deploy mode: ' . $mode . '.');
    }

    return $mode !== VIRTUSPHERE_DEPLOY_MODE_AUTOSTART;
}

function virtusphere_deploy_mode_labels(): array
{
    return [
        VIRTUSPHERE_DEPLOY_MODE_FULL => 'Full pipeline',
        'create' => 'Create VMs',
        'powercycle' => 'Power-Cycle + Export MACs',
        'export' => 'Export MACs',
        'start' => 'Start VMs',
        VIRTUSPHERE_DEPLOY_MODE_AUTOSTART => 'Apply ESXi autostart policy',
    ];
}
