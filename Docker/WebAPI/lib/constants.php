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
// `submitted` used to sit between pending and registered. It was read in four
// places and written in none, so no code path could ever put a VM there. A state
// that half exists is worse than one that does not: every reader carried it and
// every new reader had to guess what it meant. Withdrawn in migration 0028; do
// not reintroduce a value without the writer that reaches it.
const VIRTUSPHERE_MECM_SYNC_NOT_READY = 'not_ready';
const VIRTUSPHERE_MECM_SYNC_PENDING = 'pending';
const VIRTUSPHERE_MECM_SYNC_REGISTERED = 'registered';
const VIRTUSPHERE_MECM_SYNC_FAILED = 'failed';

const VIRTUSPHERE_MECM_SYNC_STATES = [
    VIRTUSPHERE_MECM_SYNC_NOT_READY,
    VIRTUSPHERE_MECM_SYNC_PENDING,
    VIRTUSPHERE_MECM_SYNC_REGISTERED,
    VIRTUSPHERE_MECM_SYNC_FAILED,
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
// Refused machine access: an IP that is not allowlisted, a rejected report token,
// a legacy token request that was turned down. Deliberately its own category and
// NOT `mecm` or `legacy_api`: those two are operational views and live in the
// deploy and system tabs, while this answers a security question. Somebody
// looking in the security tab for foreign access used to find portal sign-ins
// and nothing else, although the machine surface is the part of the system that
// faces the deploy VLAN.
const VIRTUSPHERE_LOG_CATEGORY_MACHINE_API = 'machine_api';

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
    VIRTUSPHERE_LOG_CATEGORY_MACHINE_API,
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
        // A refused machine access is a security event, so it inherits the long
        // retention window of this tab on purpose: the misconfiguration it
        // reports (a missing IP allowlist entry) can sit unnoticed for months.
        VIRTUSPHERE_LOG_CATEGORY_MACHINE_API,
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

// Integration result-reporting sources (deploy_integration_heartbeats.source).
// The three MECM sync tasks and the MECM site-health reporter post results
// through mecm_report.php?action=reportRun. The legacy action=heartbeat still
// accepts the three sync sources for older script versions. The maintenance
// worker writes its own internal source directly, never over the wire.
const VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC = 'device-sync';
const VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC = 'packages-sync';
const VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER = 'autoimporter';
const VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH = 'mecm-site-health';
const VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE = 'maintenance-worker';
// The deploy worker had no traffic light at all. Its only liveness signal was a
// tmpfs file for the container healthcheck, which the PHP container cannot read,
// so a stopped or crash-looping worker left the System status page fully green
// above a deploy queue that had stopped moving: the operator saw "everything ok"
// and a job sitting at `queued` forever. It writes into the same table as the
// MECM tasks now, directly (never over the wire), like the maintenance worker.
const VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER = 'deploy-worker';

// Legacy heartbeat wire sources: the three sync tasks whose older versions may
// still call action=heartbeat. Result reports (action=reportRun) additionally
// accept the site-health reporter.
const VIRTUSPHERE_INTEGRATION_WIRE_SOURCES = [
    VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC,
    VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC,
    VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER,
];
const VIRTUSPHERE_INTEGRATION_RUN_SOURCES = [
    VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC,
    VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC,
    VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER,
    VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH,
];

// Fachliche Gruppen fuer Systemstatus und Dashboard. Die Gruppen sind
// absichtlich getrennt: ein kritischer MECM-Site-Zustand ist kein Beweis fuer
// einen ausgefallenen Datenfluss, und ein ausgefallener Sync behauptet nicht,
// MECM selbst sei kritisch. Ein ausgefallener interner Wartungsdienst ist kein
// Beweis fuer eine ausgefallene MECM-Synchronisation.
const VIRTUSPHERE_INTEGRATION_MECM_SYNC_SOURCES = [
    VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC,
    VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC,
    VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER,
];
const VIRTUSPHERE_INTEGRATION_MECM_SITE_SOURCES = [
    VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH,
];
const VIRTUSPHERE_INTEGRATION_INTERNAL_SOURCES = [
    VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE,
    VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER,
];

// Stable fragment targets used by settings, credentials, dashboard, help and
// log feedback. Keeping them here prevents links from drifting away from the
// rendered section IDs.
const VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_MECM = 'mecm';
const VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ANSIBLE = 'ansible';
const VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ESXI = 'esxi';
const VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_INTERNAL = 'internal-services';
const VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_DEVIATIONS = 'deviations';

// The same idea one page over: settings.php spreads its forms over tabs, and a
// link that names the page without its panel lands on the first tab, which is
// not the one holding the field the message just named. Nothing errors, so the
// operator reads the message as wrong rather than the link. These are the ids
// settings.php actually renders; settings_url() (lib/settings_page.php) is the
// only place allowed to turn one into a URL, and
// tests/Static/SettingsDeepLinkContractTest.php pins both directions.
const VIRTUSPHERE_SETTINGS_TAB_DEPLOY = 'deploy';
const VIRTUSPHERE_SETTINGS_TAB_MACHINE_API = 'machine-api';
const VIRTUSPHERE_SETTINGS_TAB_CATALOG = 'catalog';
const VIRTUSPHERE_SETTINGS_TAB_HTTPS = 'https';
const VIRTUSPHERE_SETTINGS_TAB_SYSTEM = 'system';

const VIRTUSPHERE_SETTINGS_TABS = [
    VIRTUSPHERE_SETTINGS_TAB_DEPLOY,
    VIRTUSPHERE_SETTINGS_TAB_MACHINE_API,
    VIRTUSPHERE_SETTINGS_TAB_CATALOG,
    VIRTUSPHERE_SETTINGS_TAB_HTTPS,
    VIRTUSPHERE_SETTINGS_TAB_SYSTEM,
];

// Sections inside a tab that a link may target directly. core.js opens the
// owning tab and scrolls to them, so they are valid anchors even though they
// are not tabpanels; the dashboard's backup banner uses one.
const VIRTUSPHERE_SETTINGS_SECTIONS = [
    'time',
    'session',
    'password-policy',
    'retention',
    'backup',
];

const VIRTUSPHERE_INTEGRATION_SOURCES = [
    VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC,
    VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC,
    VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER,
    VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH,
    VIRTUSPHERE_INTEGRATION_SOURCE_MAINTENANCE,
    VIRTUSPHERE_INTEGRATION_SOURCE_DEPLOY_WORKER,
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

// SSoT for the three Ampel value sets the System status page renders. The page
// legend and the help panel both iterate these instead of hand-listing states.
// They used to hand-list them and had already drifted: `missing` was explained
// in help and absent from the page's own legend, so the badge an operator can
// actually see there had no entry to look up. Order is display order, worst
// last so a legend reads from healthy to broken. `legacy` is a fresh V1
// heartbeat whose result the script has not yet confirmed (script rollout gap).
const VIRTUSPHERE_HEARTBEAT_STATES = ['ok', 'legacy', 'warning', 'missing', 'danger', 'unknown'];
const VIRTUSPHERE_ESXI_AMPEL_STATES = ['ok', 'warning', 'danger', 'unknown'];
// `stale` sits next to `ok` because that is what it was: a passing preflight
// whose age has taken it out of evidence, not a new kind of problem.
const VIRTUSPHERE_ANSIBLE_AMPEL_STATES = ['ok', 'stale', 'warning', 'danger', 'unknown'];

// How long a passing Ansible preflight stays evidence. The test runs on click
// only (there is no scheduler), so this is the window after which the portal
// stops claiming the deploy chain works and says "unconfirmed" instead.
// Deliberately its own constant rather than a multiple of the ESXi inventory
// interval: that setting may be 0, and its 6h default would grey out a manual
// test overnight, which trains operators to ignore the badge.
const VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS = 7;

// mecm-category audits from the machine surface are throttled per tag so a
// misbehaving 10s sync loop cannot flood deploy_logs (error_log always logs).
const VIRTUSPHERE_MECM_AUDIT_THROTTLE_SECONDS = 3600;
// How far back the System status looks for refused machine accesses. A day,
// deliberately much wider than the audit throttle window: the point is to
// recognise "somebody is being turned away" over a shift, not to count attempts.
// A task that polls every minute leaves one throttled row per hour, so a day
// still shows a handful even for a single misconfigured host.
const VIRTUSPHERE_MACHINE_API_DENIAL_WINDOW_SECONDS = 86400;

// Successful legacy-token issuance is throttled per (user, IP) window so a client
// that re-authenticates in a loop instead of caching its hour-valid token cannot
// flood deploy_logs (error_log always logs every issuance for forensics). Finer
// than the mecm window because this is an auth event: 5-minute buckets still show
// when an identity was active while collapsing a per-second flood. Only the
// success path is throttled; failed logins stay unthrottled as a brute-force
// signal.
const VIRTUSPHERE_LEGACY_TOKEN_AUDIT_THROTTLE_SECONDS = 300;

// Result reporting (mecm_report.php?action=reportRun, ADR-0018): the three MECM
// sync tasks and the site-health reporter announce the start and the actual
// outcome of every run. The portal never connects to the MECM server; it only
// receives these reports. Arrival order is the truth (the client sends
// sequentially and keeps no replay queue), so a completed report is always
// taken unless it repeats the last run_id.
const VIRTUSPHERE_INTEGRATION_EVENT_HEARTBEAT = 'heartbeat';
const VIRTUSPHERE_RUN_EVENT_STARTED = 'started';
const VIRTUSPHERE_RUN_EVENT_COMPLETED = 'completed';
const VIRTUSPHERE_RUN_EVENTS = [
    VIRTUSPHERE_RUN_EVENT_STARTED,
    VIRTUSPHERE_RUN_EVENT_COMPLETED,
];
// Full last_event vocabulary a row can hold (drives the display semantics).
const VIRTUSPHERE_INTEGRATION_EVENTS = [
    VIRTUSPHERE_INTEGRATION_EVENT_HEARTBEAT,
    VIRTUSPHERE_RUN_EVENT_STARTED,
    VIRTUSPHERE_RUN_EVENT_COMPLETED,
];

const VIRTUSPHERE_RUN_OUTCOME_OK = 'ok';
const VIRTUSPHERE_RUN_OUTCOME_WARNING = 'warning';
const VIRTUSPHERE_RUN_OUTCOME_FAIL = 'fail';
const VIRTUSPHERE_RUN_OUTCOME_UNKNOWN = 'unknown';
const VIRTUSPHERE_RUN_OUTCOMES = [
    VIRTUSPHERE_RUN_OUTCOME_OK,
    VIRTUSPHERE_RUN_OUTCOME_WARNING,
    VIRTUSPHERE_RUN_OUTCOME_FAIL,
    VIRTUSPHERE_RUN_OUTCOME_UNKNOWN,
];

// A completed run that is not `ok` must name a known error category. The three
// sync sources use the generic set; the site-health source uses its own set
// with a fixed outcome binding (site_warning<->warning, site_critical<->fail,
// the provider/query errors <->unknown, so a provider fault is grey, never
// "MECM critical").
const VIRTUSPHERE_RUN_ERROR_PORTAL_UNREACHABLE = 'portal_unreachable';
const VIRTUSPHERE_RUN_ERROR_MECM_UNAVAILABLE = 'mecm_unavailable';
const VIRTUSPHERE_RUN_ERROR_PARTIAL_FAILURE = 'partial_failure';
const VIRTUSPHERE_RUN_ERROR_SOURCE_MISSING = 'source_missing';
const VIRTUSPHERE_RUN_ERROR_CATALOG_CONFLICT = 'catalog_conflict';
const VIRTUSPHERE_RUN_SYNC_ERROR_CATEGORIES = [
    VIRTUSPHERE_RUN_ERROR_PORTAL_UNREACHABLE,
    VIRTUSPHERE_RUN_ERROR_MECM_UNAVAILABLE,
    VIRTUSPHERE_RUN_ERROR_PARTIAL_FAILURE,
    VIRTUSPHERE_RUN_ERROR_SOURCE_MISSING,
    VIRTUSPHERE_RUN_ERROR_CATALOG_CONFLICT,
];
const VIRTUSPHERE_RUN_ERROR_SITE_WARNING = 'site_warning';
const VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL = 'site_critical';
const VIRTUSPHERE_RUN_ERROR_PROVIDER_ACCESS_DENIED = 'provider_access_denied';
const VIRTUSPHERE_RUN_ERROR_PROVIDER_UNREACHABLE = 'provider_unreachable';
const VIRTUSPHERE_RUN_ERROR_QUERY_FAILED = 'query_failed';
// The site-health category<->outcome binding is fixed and validated on the wire.
// Its keys are the full set of allowed site-health error categories (SSoT), so no
// separate category list is kept.
const VIRTUSPHERE_RUN_SITE_ERROR_OUTCOME = [
    VIRTUSPHERE_RUN_ERROR_SITE_WARNING => VIRTUSPHERE_RUN_OUTCOME_WARNING,
    VIRTUSPHERE_RUN_ERROR_SITE_CRITICAL => VIRTUSPHERE_RUN_OUTCOME_FAIL,
    VIRTUSPHERE_RUN_ERROR_PROVIDER_ACCESS_DENIED => VIRTUSPHERE_RUN_OUTCOME_UNKNOWN,
    VIRTUSPHERE_RUN_ERROR_PROVIDER_UNREACHABLE => VIRTUSPHERE_RUN_OUTCOME_UNKNOWN,
    VIRTUSPHERE_RUN_ERROR_QUERY_FAILED => VIRTUSPHERE_RUN_OUTCOME_UNKNOWN,
];

// Allowed summary object keys per source (validated on the wire). Sync counters
// are non-negative integers; site-health carries site_code/provider strings and
// an integer raw_status.
const VIRTUSPHERE_RUN_SUMMARY_FIELDS = [
    VIRTUSPHERE_INTEGRATION_SOURCE_DEVICE_SYNC => [
        'received', 'imported', 'item_failures', 'data_warnings', 'resource_update_failures',
    ],
    VIRTUSPHERE_INTEGRATION_SOURCE_PACKAGES_SYNC => [
        'packages', 'task_sequences', 'sent', 'unchanged',
    ],
    VIRTUSPHERE_INTEGRATION_SOURCE_AUTOIMPORTER => [
        'folders', 'created', 'removed', 'open_points', 'unchanged',
    ],
    VIRTUSPHERE_INTEGRATION_SOURCE_SITE_HEALTH => [
        'site_code', 'provider', 'raw_status',
    ],
];
// Summary keys whose value is a short string rather than a counter.
const VIRTUSPHERE_RUN_SUMMARY_STRING_FIELDS = ['site_code', 'provider'];

// Wire bounds for a run report. detail reuses the client-event body cap; the
// client truncates it in bytes before sending so the 8 KB body limit holds.
const VIRTUSPHERE_RUN_ID_PATTERN = '/\A[0-9a-f]{32}\z/';
const VIRTUSPHERE_RUN_SUMMARY_VALUE_MAX = 1000000000;
const VIRTUSPHERE_RUN_SUMMARY_STRING_MAX_CHARS = 64;
const VIRTUSPHERE_RUN_DURATION_MS_MAX = 86400000;
const VIRTUSPHERE_RUN_SCRIPT_VERSION_MAX_CHARS = 32;
// While a run is in progress the row is not treated as stale until the run
// exceeds this grace, alongside the usual 3x-interval / 60s floors.
const VIRTUSPHERE_RUN_GRACE_SECONDS = 600;
// How often each worker wakes up to look for work (overridable with --sleep=N).
// The help quotes both cadences, so they are named rather than left as literals
// in the option defaults.
const VIRTUSPHERE_DEPLOY_WORKER_SLEEP_SECONDS = 5;
const VIRTUSPHERE_MAINTENANCE_WORKER_SLEEP_SECONDS = 15;
const VIRTUSPHERE_MAINTENANCE_HEARTBEAT_INTERVAL_SECONDS = 60;
const VIRTUSPHERE_MAINTENANCE_RETENTION_INTERVAL_SECONDS = 3600;
// The deploy worker's heartbeat cadence for the System status row. Deliberately
// not its sleep interval: it sleeps every few seconds and would write hundreds
// of rows an hour, while the staleness thresholds are multiples of the reported
// interval and would go red for a worker that is merely busy inside one long
// playbook. This is the same order as the MECM tasks, so the row reads the same.
const VIRTUSPHERE_DEPLOY_WORKER_HEARTBEAT_INTERVAL_SECONDS = 60;

// Container liveness heartbeat (AP8): both loop workers touch this file on
// every loop iteration, on every DB-reconnect attempt and on every transport
// heartbeat tick; the compose healthcheck runs lib/worker_healthcheck.php,
// which only checks the file's age. Path and window live here so the compose
// file hardcodes neither. The window must stay above the largest legitimate
// touch gap, which is the 30s DB-reconnect backoff, with generous margin.
const VIRTUSPHERE_WORKER_HEARTBEAT_FILE = '/tmp/virtusphere-worker-heartbeat';
const VIRTUSPHERE_WORKER_HEARTBEAT_MAX_AGE_SECONDS = 120;

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
