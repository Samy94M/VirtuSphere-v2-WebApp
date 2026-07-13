<?php

declare(strict_types=1);

const VIRTUSPHERE_PHP_TARGET = '8.4';
const VIRTUSPHERE_TEMPLATE_PREFIX = '_';
// Portal audit log (deploy_logs) retention, split by concern (ADR-0026): the
// security categories (exactly the security tab: auth, users, credentials)
// keep a traceable year, everything else keeps a quarter. Which window a row
// gets is decided by its tab (VIRTUSPHERE_LOG_TABS below), so the security
// category list is never restated; categories unknown to today's taxonomy
// decay on the general window (NOT IN in removeLog()).
const VIRTUSPHERE_LOG_RETENTION_SECURITY_DAYS = 365;
const VIRTUSPHERE_LOG_RETENTION_DAYS = 90;
// How long an account stays locked after too many failed sign-ins. Was written
// straight into the SQL (`INTERVAL 15 MINUTE`) and, separately, into the help
// text; the two could drift apart without anything noticing.
const VIRTUSPHERE_LOGIN_LOCKOUT_MINUTES = 15;
// The window the failure counters look back over. A different thing from the
// lockout duration above, which happens to share its value today: this one asks
// "how many failures recently", that one answers "how long are you out". They
// were both the literal 15 in three SQL statements and two audit sentences, so
// raising the lockout would have silently widened the counting window too, or
// worse, not - and no test would have noticed either way.
const VIRTUSPHERE_LOGIN_FAILURE_WINDOW_MINUTES = 15;

// bcrypt (PASSWORD_DEFAULT) silently truncates its input at 72 BYTES. Beyond
// that, two different passwords verify identically, so the limit has to be
// enforced before hashing rather than discovered afterwards. It is bytes, not
// characters: 40 umlauts are 80 bytes (OWASP password-storage cheat sheet).
const VIRTUSPHERE_PASSWORD_MAX_BYTES = 72;
// deploy_login_attempts is a lockout counter, not an archive; the sign-in story
// lives on the auth audit channel under the security window.
const VIRTUSPHERE_LOGIN_ATTEMPT_RETENTION_DAYS = 7;
// Hard cap for the logs.php CSV export: a year of security rows must not
// produce an unbounded stream. A truncated export is by design, not an error.
const VIRTUSPHERE_LOG_EXPORT_MAX_ROWS = 10000;

// Streamed Ansible output of a FINISHED deploy job. Longer than the audit log,
// because a failed deploy is investigated for days, and shorter than forever:
// the interval inventory pull writes a job every few hours and each one keeps
// its playbook output. Only terminal jobs are pruned, so a long-running job
// never loses the lines it already streamed. The job row itself survives with
// its status and last_error; only the output goes.
const VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS = 30;
// Mission-less inventory jobs are invisible once finished (no page lists them),
// so their rows are removed entirely on the same window; the logs cascade.
const VIRTUSPHERE_SYSTEM_JOB_RETENTION_DAYS = 30;

const VIRTUSPHERE_STATUS_INITIALIZING = '1/5 Initializing';
const VIRTUSPHERE_STATUS_REGISTERED = '2/5 Registered';
const VIRTUSPHERE_STATUS_DEPLOYED = '3/5 Deployed';
const VIRTUSPHERE_STATUS_OS_INSTALLING = '4/5 OS Installing';
const VIRTUSPHERE_STATUS_OS_INSTALLED = '5/5 OS Installed';

const VIRTUSPHERE_VM_STATUS_COMPAT = [
    VIRTUSPHERE_STATUS_INITIALIZING,
    VIRTUSPHERE_STATUS_REGISTERED,
    VIRTUSPHERE_STATUS_DEPLOYED,
    VIRTUSPHERE_STATUS_OS_INSTALLING,
    VIRTUSPHERE_STATUS_OS_INSTALLED,
];

// VM lifecycle states. Must stay in sync with the lifecycle_state ENUM in
// struktur.sql and lib/migrate.php (see docs/adr and .claude/rules/database.md).
const VIRTUSPHERE_LIFECYCLE_INITIALIZING = 'initializing';
const VIRTUSPHERE_LIFECYCLE_READY = 'ready';
const VIRTUSPHERE_LIFECYCLE_DEPLOYING = 'deploying';
const VIRTUSPHERE_LIFECYCLE_DEPLOYED = 'deployed';
const VIRTUSPHERE_LIFECYCLE_OS_INSTALLING = 'os_installing';
const VIRTUSPHERE_LIFECYCLE_OS_INSTALLED = 'os_installed';
const VIRTUSPHERE_LIFECYCLE_FAILED = 'failed';

const VIRTUSPHERE_LIFECYCLE_STATES = [
    VIRTUSPHERE_LIFECYCLE_INITIALIZING,
    VIRTUSPHERE_LIFECYCLE_READY,
    VIRTUSPHERE_LIFECYCLE_DEPLOYING,
    VIRTUSPHERE_LIFECYCLE_DEPLOYED,
    VIRTUSPHERE_LIFECYCLE_OS_INSTALLING,
    VIRTUSPHERE_LIFECYCLE_OS_INSTALLED,
    VIRTUSPHERE_LIFECYCLE_FAILED,
];

// MECM sync states. Must stay in sync with the mecm_sync_state ENUM.
const VIRTUSPHERE_MECM_NOT_READY = 'not_ready';
const VIRTUSPHERE_MECM_PENDING = 'pending';
const VIRTUSPHERE_MECM_SUBMITTED = 'submitted';
const VIRTUSPHERE_MECM_REGISTERED = 'registered';
const VIRTUSPHERE_MECM_FAILED = 'failed';

const VIRTUSPHERE_MECM_SYNC_STATES = [
    VIRTUSPHERE_MECM_NOT_READY,
    VIRTUSPHERE_MECM_PENDING,
    VIRTUSPHERE_MECM_SUBMITTED,
    VIRTUSPHERE_MECM_REGISTERED,
    VIRTUSPHERE_MECM_FAILED,
];

// Bulk VM-list actions cap (Paket C): reject selections larger than this.
const VIRTUSPHERE_VM_BULK_CAP = 200;

// Settings keys (deploy_settings.setting_key).
const VIRTUSPHERE_SETTING_API_BASE_URL = 'api_base_url';
// Display-only portal timezone (ADR-0022). Storage and comparisons stay in UTC;
// this only converts timestamps for display. Never reuse it for auth, RBAC,
// deploy decisions or wire contracts.
const VIRTUSPHERE_SETTING_PORTAL_TIMEZONE = 'portal_timezone';
const VIRTUSPHERE_PORTAL_TIMEZONE_DEFAULT = 'Europe/Berlin';
// SHA-256 hash of the optional machine report token. The plaintext token is
// shown exactly once when generated and lives only in the MECM server registry.
const VIRTUSPHERE_SETTING_MACHINE_REPORT_TOKEN_HASH = 'machine_report_token_hash';

// Absolute portal session lifetime, admin-configured in minutes. The stored
// value is clamped to the bounds on read, so a hand-edited DB row can never
// produce a zero-second (or week-long) session. Applies to new logins and the
// next explicit "Verlaengern", never retroactively to a running session.
// VIRTUSPHERE_SESSION_LIFETIME_SECONDS in lib/auth.php stays the DB-down
// fallback.
const VIRTUSPHERE_SETTING_SESSION_LIFETIME_MINUTES = 'session_lifetime_minutes';
const VIRTUSPHERE_SESSION_LIFETIME_MINUTES_DEFAULT = 60;
const VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MIN = 15;
const VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MAX = 480;

// Minimum password length (characters, mb_strlen), admin-configured. The lower
// bound equals the historical hardcoded 12, so the setting can only tighten
// the baseline, never weaken it. Checked centrally in lib/password_policy.php.
const VIRTUSPHERE_SETTING_PASSWORD_MIN_LENGTH = 'password_min_length';
const VIRTUSPHERE_PASSWORD_MIN_LENGTH_DEFAULT = 12;
const VIRTUSPHERE_PASSWORD_MIN_LENGTH_MIN = 12;
const VIRTUSPHERE_PASSWORD_MIN_LENGTH_MAX = 128;

// HTTPS admin flow (WP7, ADR-0012/ADR-0027). Three independent toggles: serve
// HTTPS, redirect portal HTTP->HTTPS (machine API stays exempt by
// construction: it never loads the portal bootstrap), and HSTS. All '0'/'1'
// strings in deploy_settings.
const VIRTUSPHERE_SETTING_HTTPS_ENABLED = 'https_enabled';
const VIRTUSPHERE_SETTING_HTTPS_REDIRECT_ENABLED = 'https_redirect_enabled';
const VIRTUSPHERE_SETTING_HTTPS_HSTS_ENABLED = 'https_hsts_enabled';
// 180 days: long enough to matter, and rollback pain stays bounded because
// HSTS is a separate opt-in whose hint spells out the browser pinning.
const VIRTUSPHERE_HTTPS_HSTS_MAX_AGE_SECONDS = 15552000;
// A PFX with chain fits comfortably; anything bigger is not a certificate.
const VIRTUSPHERE_HTTPS_UPLOAD_MAX_BYTES = 262144;
const VIRTUSPHERE_HTTPS_CERT_EXPIRY_WARN_DAYS = 30;
// Shared-volume paths (compose mounts them into php AND nginx). Helpers take
// them as parameters so tests write to a temp dir instead.
const VIRTUSPHERE_HTTPS_SSL_DIR = '/etc/nginx/ssl';
const VIRTUSPHERE_HTTPS_CONF_DIR = '/etc/nginx/virtusphere-conf.d';

// Log categories (deploy_logs.category, a VARCHAR - no ENUM mirror to keep in
// sync). Every audit()/addLog() call site must pass one of these so the portal
// logs page can filter/group by category.
//
// `auth` is the security channel: who signed in, who was refused, who changed
// their own password. It is kept apart from `users` on purpose. `users` records
// administration of accounts (an admin created someone, changed a role), which is
// a resource change; `auth` records access to the system itself, is written by
// anonymous requests too, and is the high-volume one. Mixing them would bury a
// brute-force burst under routine account edits.
const VIRTUSPHERE_LOG_CATEGORY_AUTH = 'auth';
const VIRTUSPHERE_LOG_CATEGORY_SYSTEM = 'system';
const VIRTUSPHERE_LOG_CATEGORY_LEGACY_API = 'legacy_api';
const VIRTUSPHERE_LOG_CATEGORY_USERS = 'users';
const VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS = 'credentials';
const VIRTUSPHERE_LOG_CATEGORY_MISSIONS = 'missions';
const VIRTUSPHERE_LOG_CATEGORY_OS = 'os';
const VIRTUSPHERE_LOG_CATEGORY_SETTINGS = 'settings';
const VIRTUSPHERE_LOG_CATEGORY_VMS = 'vms';
const VIRTUSPHERE_LOG_CATEGORY_VLANS = 'vlans';
const VIRTUSPHERE_LOG_CATEGORY_DEPLOY = 'deploy';
const VIRTUSPHERE_LOG_CATEGORY_MECM = 'mecm';

const VIRTUSPHERE_LOG_CATEGORIES = [
    VIRTUSPHERE_LOG_CATEGORY_AUTH,
    VIRTUSPHERE_LOG_CATEGORY_SYSTEM,
    VIRTUSPHERE_LOG_CATEGORY_LEGACY_API,
    VIRTUSPHERE_LOG_CATEGORY_USERS,
    VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS,
    VIRTUSPHERE_LOG_CATEGORY_MISSIONS,
    VIRTUSPHERE_LOG_CATEGORY_OS,
    VIRTUSPHERE_LOG_CATEGORY_SETTINGS,
    VIRTUSPHERE_LOG_CATEGORY_VMS,
    VIRTUSPHERE_LOG_CATEGORY_VLANS,
    VIRTUSPHERE_LOG_CATEGORY_DEPLOY,
    VIRTUSPHERE_LOG_CATEGORY_MECM,
];

// Log page tab grouping (portal UI only). Tabs bundle the flat category set into
// sections so the protocol is not one undifferentiated table. Keys are the
// ?tab= values and are NOT localized; every category above must belong to
// exactly one tab (pinned by LogTabCoverageTest). This is a display grouping,
// not a DB ENUM mirror.
//
// The tabs answer one question each, which is why the old `access` tab was split:
// it mixed security events, configuration changes and machine traffic, so an
// admin looking for a failed login scrolled past settings edits. The first tab is
// the default landing tab, and on an admin-only page that should be security.
const VIRTUSPHERE_LOG_TAB_SECURITY = 'security';
const VIRTUSPHERE_LOG_TAB_RESOURCES = 'resources';
const VIRTUSPHERE_LOG_TAB_DEPLOY = 'deploy';
const VIRTUSPHERE_LOG_TAB_SYSTEM = 'system';

const VIRTUSPHERE_LOG_TABS = [
    // Who got in, who was refused, and who holds the keys.
    VIRTUSPHERE_LOG_TAB_SECURITY => [
        VIRTUSPHERE_LOG_CATEGORY_AUTH,
        VIRTUSPHERE_LOG_CATEGORY_USERS,
        VIRTUSPHERE_LOG_CATEGORY_CREDENTIALS,
    ],
    // What was created, changed or deleted. VMs follow missions, their owner.
    VIRTUSPHERE_LOG_TAB_RESOURCES => [
        VIRTUSPHERE_LOG_CATEGORY_MISSIONS,
        VIRTUSPHERE_LOG_CATEGORY_VMS,
        VIRTUSPHERE_LOG_CATEGORY_VLANS,
        VIRTUSPHERE_LOG_CATEGORY_OS,
    ],
    // What the deploy pipeline did, from queueing to the MECM handover.
    VIRTUSPHERE_LOG_TAB_DEPLOY => [
        VIRTUSPHERE_LOG_CATEGORY_DEPLOY,
        VIRTUSPHERE_LOG_CATEGORY_MECM,
    ],
    // Errors, configuration and the legacy machine surface.
    VIRTUSPHERE_LOG_TAB_SYSTEM => [
        VIRTUSPHERE_LOG_CATEGORY_SYSTEM,
        VIRTUSPHERE_LOG_CATEGORY_SETTINGS,
        VIRTUSPHERE_LOG_CATEGORY_LEGACY_API,
    ],
];

// Client phase reporting (mecm_report.php, deploy_client_events). Plain string
// value sets validated in PHP on purpose - no DB ENUM mirror needed.
const VIRTUSPHERE_CLIENT_PHASE_GETINFO = 'getinfo';
const VIRTUSPHERE_CLIENT_PHASE_HOSTNAME = 'hostname';
const VIRTUSPHERE_CLIENT_PHASE_STATICIP = 'staticip';
const VIRTUSPHERE_CLIENT_PHASE_DISKS = 'disks';

// Fixed display order of the client deploy phases.
const VIRTUSPHERE_CLIENT_PHASES = [
    VIRTUSPHERE_CLIENT_PHASE_GETINFO,
    VIRTUSPHERE_CLIENT_PHASE_HOSTNAME,
    VIRTUSPHERE_CLIENT_PHASE_STATICIP,
    VIRTUSPHERE_CLIENT_PHASE_DISKS,
];

const VIRTUSPHERE_CLIENT_EVENT_STARTED = 'started';
const VIRTUSPHERE_CLIENT_EVENT_FINISHED = 'finished';
const VIRTUSPHERE_CLIENT_EVENT_FAILED = 'failed';

const VIRTUSPHERE_CLIENT_EVENTS = [
    VIRTUSPHERE_CLIENT_EVENT_STARTED,
    VIRTUSPHERE_CLIENT_EVENT_FINISHED,
    VIRTUSPHERE_CLIENT_EVENT_FAILED,
];

const VIRTUSPHERE_CLIENT_EVENT_MAX_BODY_BYTES = 8192;
const VIRTUSPHERE_CLIENT_EVENT_DETAIL_MAX_CHARS = 1024;
const VIRTUSPHERE_CLIENT_EVENT_DEDUPE_SECONDS = 60;
const VIRTUSPHERE_CLIENT_EVENT_MAX_PER_DAY = 300;
const VIRTUSPHERE_CLIENT_EVENT_RETENTION_DAYS = 30;
// A "started" event without a follow-up is shown as "unconfirmed" after this.
const VIRTUSPHERE_CLIENT_PHASE_UNCONFIRMED_AFTER_SECONDS = 900;

// Integration heartbeat sources (deploy_integration_heartbeats.source).
// Wire sources report through mecm_report.php?action=heartbeat; internal
// sources are written by the maintenance worker only.
const VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC = 'device-sync';
const VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC = 'packages-sync';
const VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER = 'autoimporter';
const VIRTUSPHERE_INTEGRATION_SOURCE_MECM_PROBE = 'mecm-server-probe';
const VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE = 'maintenance-worker';

const VIRTUSPHERE_INTEGRATION_WIRE_SOURCES = [
    VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC,
    VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC,
    VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER,
];

const VIRTUSPHERE_INTEGRATION_SOURCES = [
    VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC,
    VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC,
    VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER,
    VIRTUSPHERE_INTEGRATION_SOURCE_MECM_PROBE,
    VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE,
];

const VIRTUSPHERE_HEARTBEAT_STATUS_OK = 'ok';
const VIRTUSPHERE_HEARTBEAT_STATUS_FAIL = 'fail';

// Staleness thresholds: warning after 3x interval (min 60s), danger after
// 10x interval (min 300s). A last_status of 'fail' beats staleness.
const VIRTUSPHERE_HEARTBEAT_WARN_MULTIPLIER = 3;
const VIRTUSPHERE_HEARTBEAT_DANGER_MULTIPLIER = 10;
const VIRTUSPHERE_HEARTBEAT_WARN_FLOOR_SECONDS = 60;
const VIRTUSPHERE_HEARTBEAT_DANGER_FLOOR_SECONDS = 300;
const VIRTUSPHERE_HEARTBEAT_INTERVAL_MIN_SECONDS = 5;
const VIRTUSPHERE_HEARTBEAT_INTERVAL_MAX_SECONDS = 3600;

// mecm-category audits from the machine surface are throttled per tag so a
// misbehaving 10s sync loop cannot flood deploy_logs (error_log always logs).
const VIRTUSPHERE_MECM_AUDIT_THROTTLE_SECONDS = 3600;

// Successful legacy-token issuance is throttled per (user, IP) window so a client
// that re-authenticates in a loop instead of caching its hour-valid token cannot
// flood deploy_logs (error_log always logs every issuance for forensics). Finer
// than the mecm window because this is an auth event: 5-minute buckets still show
// when an identity was active while collapsing a per-second flood. Only the
// success path is throttled; failed logins stay unthrottled as a brute-force
// signal.
const VIRTUSPHERE_LEGACY_TOKEN_AUDIT_THROTTLE_SECONDS = 300;

// Maintenance worker (lib/maintenance_worker.php, ADR-0018): active MECM
// reachability probe and retention jobs. Probe target defaults to the last
// device-sync heartbeat IP; both are overridable through settings.
const VIRTUSPHERE_SETTING_MECM_PROBE_HOST = 'mecm_probe_host';
const VIRTUSPHERE_SETTING_MECM_PROBE_PORT = 'mecm_probe_port';
const VIRTUSPHERE_MECM_PROBE_INTERVAL_SECONDS = 300;
const VIRTUSPHERE_MECM_PROBE_PORT_DEFAULT = 445;
const VIRTUSPHERE_MECM_PROBE_TIMEOUT_SECONDS = 3;
// How often each worker wakes up to look for work (overridable with --sleep=N).
// The help quotes both cadences, so they are named rather than left as literals
// in the option defaults.
const VIRTUSPHERE_DEPLOY_WORKER_SLEEP_SECONDS = 5;
const VIRTUSPHERE_MAINTENANCE_WORKER_SLEEP_SECONDS = 15;
const VIRTUSPHERE_MAINTENANCE_HEARTBEAT_INTERVAL_SECONDS = 60;
const VIRTUSPHERE_MAINTENANCE_RETENTION_INTERVAL_SECONDS = 3600;

// Free-text status columns keep their free-text nature; only the defaults for
// fresh rows are centralized so create paths do not drift.
const VIRTUSPHERE_MISSION_STATUS_DEFAULT = 'active';
const VIRTUSPHERE_CATALOG_STATUS_DEFAULT = 'Aktiv';

// Catalog lifecycle (E3): packages/OS missing from a sync payload are retired
// instead of deleted (the old DELETE cascaded into deploy_vm_packages and
// silently destroyed VM assignments on every version bump).
const VIRTUSPHERE_CATALOG_STATUS_RETIRED = 'Retired';

// URL/query tokens for the catalog status filter (os.php, packages.php,
// vlans.php). These are UI selectors, NOT the stored DB values above: 'active'
// maps to "not retired", 'retired' to VIRTUSPHERE_CATALOG_STATUS_RETIRED, 'all'
// to no filter. Kept as one list so the three pages cannot drift apart.
const VIRTUSPHERE_CATALOG_FILTERS = ['active', 'retired', 'all'];
const VIRTUSPHERE_SETTING_PACKAGE_RETIRE_THRESHOLD = 'package_retire_threshold_percent';
const VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_DEFAULT = 30; // percent
const VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MIN = 5;      // percent, inclusive input bound
const VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MAX = 90;     // percent, inclusive input bound
const VIRTUSPHERE_PACKAGE_RETIRE_MIN_ACTIVE = 5;         // guard only above this
const VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS = 30;
