<?php

declare(strict_types=1);

// Portal authentication sources. The values mirror deploy_users.auth_source
// and deploy_login_attempts.auth_source; this file is their PHP SSoT.
const VIRTUSPHERE_AUTH_SOURCE_LOCAL = 'local';
const VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY = 'active_directory';

const VIRTUSPHERE_AUTH_SOURCES = [
    VIRTUSPHERE_AUTH_SOURCE_LOCAL,
    VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY,
];

// Directory endpoint and input bounds. An explicit FQDN is required so peer
// name verification has a stable name; IP targets and serverless discovery are
// intentionally outside this release.
const VIRTUSPHERE_DIRECTORY_DEFAULT_PORT = 636;
const VIRTUSPHERE_DIRECTORY_HOST_MAX_CHARS = 253;
const VIRTUSPHERE_DIRECTORY_UPN_MAX_CHARS = 255;
const VIRTUSPHERE_DIRECTORY_SEARCH_MIN_CHARS = 2;
const VIRTUSPHERE_DIRECTORY_SEARCH_MAX_CHARS = 120;
const VIRTUSPHERE_DIRECTORY_SEARCH_RESULT_LIMIT = 25;
const VIRTUSPHERE_DIRECTORY_CA_MAX_BYTES = 262144;
// A password is never sent to a controller above this many bytes (N1a, A8).
// Windows passwords end at 256 characters; four bytes per character plus
// headroom keeps every real password and stops a 20 MB body at the portal.
const VIRTUSPHERE_DIRECTORY_PASSWORD_MAX_BYTES = 1024;

// One request may try more than one controller only after a technical failure.
// An authoritative credential rejection always stops immediately.
const VIRTUSPHERE_DIRECTORY_NETWORK_TIMEOUT_SECONDS = 3;
const VIRTUSPHERE_DIRECTORY_OPERATION_TIMEOUT_SECONDS = 5;
const VIRTUSPHERE_DIRECTORY_TOTAL_TIMEOUT_SECONDS = 8;
const VIRTUSPHERE_DIRECTORY_CONTROLLER_COOLDOWN_SECONDS = 60;

// AD sessions are periodically revalidated with the read-only search account.
// A short outage gets a bounded grace period; local accounts are unaffected.
const VIRTUSPHERE_DIRECTORY_SESSION_RECHECK_SECONDS = 300;
const VIRTUSPHERE_DIRECTORY_SESSION_GRACE_SECONDS = 900;
const VIRTUSPHERE_DIRECTORY_IMPORT_CANDIDATE_TTL_SECONDS = 300;

// There is deliberately no periodic controller re-test (section 9.4 of the
// integration plan): status freshness/expiry-warning windows below are the
// only staleness signal, so they are wider than a polled interval's factor.
// A usable controller's last confirmed success ages out of "fresh" evidence
// after this many days without a new one (login, session recheck or manual
// test); mirrors VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS's convention
// for another manually-triggered, unscheduled check.
const VIRTUSPHERE_DIRECTORY_OBSERVATION_STALE_AFTER_DAYS = 7;
// A usable controller's certificate (known from its last manual test and,
// since N1a F7, from the daily read after a successful contact) is flagged
// this many days before certificate_not_after, so a rotation can happen
// before the certificate actually expires and the controller turns red on
// its own next TLS handshake.
const VIRTUSPHERE_DIRECTORY_CERTIFICATE_EXPIRY_WARNING_DAYS = 30;
// N1a F7 (user decision 2026-11-09): after a successful directory contact the
// portal reads the controller certificate again with one extra TLS handshake,
// at most once per this many seconds per controller.
const VIRTUSPHERE_DIRECTORY_CERTIFICATE_REFRESH_SECONDS = 86400;

// Per-controller and overall Ampel state sets (lib/directory_status.php),
// shape-matched to the existing ESXi/Ansible ones so the shared legend
// renderer (system_status_legend_items()) needs no directory-specific case.
const VIRTUSPHERE_DIRECTORY_CONTROLLER_AMPEL_STATES = ['ok', 'warning', 'danger', 'stale', 'unknown'];
const VIRTUSPHERE_DIRECTORY_AMPEL_STATES = ['ok', 'warning', 'danger', 'unknown'];

// Typed outcomes are the only directory diagnostics allowed into DB, audit or
// UI. Raw LDAP diagnostic messages may contain DNs and directory internals.
const VIRTUSPHERE_DIRECTORY_OUTCOME_OK = 'ok';
const VIRTUSPHERE_DIRECTORY_OUTCOME_EXTENSION_MISSING = 'extension_missing';
const VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED = 'tls_failed';
const VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE = 'unavailable';
const VIRTUSPHERE_DIRECTORY_OUTCOME_TIMEOUT = 'timeout';
// LDAP result 49 has different product meaning depending on which identity was
// bound. Keep both codes distinct so a rejected search account can never be
// counted as a user's bad password (or fanned out to another controller).
const VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED = 'service_bind_rejected';
const VIRTUSPHERE_DIRECTORY_OUTCOME_USER_BIND_REJECTED = 'user_bind_rejected';
// N1a A7: the cause of a search-account rejection, read only from the hex
// value after "data " in the controller's diagnostic (directory_ldap_bind_code()).
// Every one of them stops the search account like service_bind_rejected.
const VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_PASSWORD_EXPIRED = 'service_password_expired';
const VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_ACCOUNT_DISABLED = 'service_account_disabled';
const VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_ACCOUNT_LOCKED = 'service_account_locked';
const VIRTUSPHERE_DIRECTORY_OUTCOME_SECRET_UNREADABLE = 'secret_unreadable';
const VIRTUSPHERE_DIRECTORY_OUTCOME_SEARCH_FAILED = 'search_failed';
const VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_FOUND = 'not_found';
const VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_AUTHORIZED = 'not_authorized';
const VIRTUSPHERE_DIRECTORY_OUTCOME_AMBIGUOUS = 'ambiguous';
const VIRTUSPHERE_DIRECTORY_OUTCOME_DOMAIN_MISMATCH = 'domain_mismatch';
const VIRTUSPHERE_DIRECTORY_OUTCOME_RODC_UNSUPPORTED = 'read_only_controller_unsupported';
const VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE = 'invalid_response';

const VIRTUSPHERE_DIRECTORY_OUTCOMES = [
    VIRTUSPHERE_DIRECTORY_OUTCOME_OK,
    VIRTUSPHERE_DIRECTORY_OUTCOME_EXTENSION_MISSING,
    VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED,
    VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE,
    VIRTUSPHERE_DIRECTORY_OUTCOME_TIMEOUT,
    VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED,
    VIRTUSPHERE_DIRECTORY_OUTCOME_USER_BIND_REJECTED,
    VIRTUSPHERE_DIRECTORY_OUTCOME_SECRET_UNREADABLE,
    VIRTUSPHERE_DIRECTORY_OUTCOME_SEARCH_FAILED,
    VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_FOUND,
    VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_AUTHORIZED,
    VIRTUSPHERE_DIRECTORY_OUTCOME_AMBIGUOUS,
    VIRTUSPHERE_DIRECTORY_OUTCOME_DOMAIN_MISMATCH,
    VIRTUSPHERE_DIRECTORY_OUTCOME_RODC_UNSUPPORTED,
    VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE,
    VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_PASSWORD_EXPIRED,
    VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_ACCOUNT_DISABLED,
    VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_ACCOUNT_LOCKED,
];

// Every rejection of the search account. Each one trips the revision-wide
// circuit breaker and is an infrastructure fault for a signing-in user.
const VIRTUSPHERE_DIRECTORY_SERVICE_REJECTION_OUTCOMES = [
    VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED,
    VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_PASSWORD_EXPIRED,
    VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_ACCOUNT_DISABLED,
    VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_ACCOUNT_LOCKED,
];

// Outcomes that say nothing about the person signing in: the portal frees the
// attempt budget and answers "directory unavailable". Transport failures are
// added by their flag, not listed here.
const VIRTUSPHERE_DIRECTORY_INFRASTRUCTURE_OUTCOMES = [
    VIRTUSPHERE_DIRECTORY_OUTCOME_EXTENSION_MISSING,
    ...VIRTUSPHERE_DIRECTORY_SERVICE_REJECTION_OUTCOMES,
    VIRTUSPHERE_DIRECTORY_OUTCOME_SECRET_UNREADABLE,
    VIRTUSPHERE_DIRECTORY_OUTCOME_SEARCH_FAILED,
    VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE,
    VIRTUSPHERE_DIRECTORY_OUTCOME_AMBIGUOUS,
    VIRTUSPHERE_DIRECTORY_OUTCOME_DOMAIN_MISMATCH,
    VIRTUSPHERE_DIRECTORY_OUTCOME_RODC_UNSUPPORTED,
];

// N1a F5: why the controller refused a user's password, mapped only from the
// hex value after "data " (directory_ldap_bind_code()). Audit only: the sign-in
// page stays generic, because AD answers "locked" for a wrong password too.
const VIRTUSPHERE_DIRECTORY_BIND_REASON_INVALID = 'invalid';
const VIRTUSPHERE_DIRECTORY_BIND_REASON_PASSWORD_EXPIRED = 'password_expired';
const VIRTUSPHERE_DIRECTORY_BIND_REASON_MUST_CHANGE = 'must_change';
const VIRTUSPHERE_DIRECTORY_BIND_REASON_LOCKED = 'locked';
const VIRTUSPHERE_DIRECTORY_BIND_REASON_DISABLED = 'disabled';
const VIRTUSPHERE_DIRECTORY_BIND_REASON_EXPIRED = 'expired';
const VIRTUSPHERE_DIRECTORY_BIND_REASON_LOGON_HOURS = 'logon_hours';
const VIRTUSPHERE_DIRECTORY_BIND_REASONS = [
    VIRTUSPHERE_DIRECTORY_BIND_REASON_INVALID,
    VIRTUSPHERE_DIRECTORY_BIND_REASON_PASSWORD_EXPIRED,
    VIRTUSPHERE_DIRECTORY_BIND_REASON_MUST_CHANGE,
    VIRTUSPHERE_DIRECTORY_BIND_REASON_LOCKED,
    VIRTUSPHERE_DIRECTORY_BIND_REASON_DISABLED,
    VIRTUSPHERE_DIRECTORY_BIND_REASON_EXPIRED,
    VIRTUSPHERE_DIRECTORY_BIND_REASON_LOGON_HOURS,
];

// The AD sub-codes (hex after "data " in a result-49 diagnostic) that carry a
// meaning here. Sources are secondary (Broadcom KB, ldapwiki); Gate 0B checks
// the mapping against the target AD. Anything else is "invalid".
const VIRTUSPHERE_DIRECTORY_BIND_CODE_REASONS = [
    '52e' => VIRTUSPHERE_DIRECTORY_BIND_REASON_INVALID,
    '530' => VIRTUSPHERE_DIRECTORY_BIND_REASON_LOGON_HOURS,
    '532' => VIRTUSPHERE_DIRECTORY_BIND_REASON_PASSWORD_EXPIRED,
    '533' => VIRTUSPHERE_DIRECTORY_BIND_REASON_DISABLED,
    '701' => VIRTUSPHERE_DIRECTORY_BIND_REASON_EXPIRED,
    '773' => VIRTUSPHERE_DIRECTORY_BIND_REASON_MUST_CHANGE,
    '775' => VIRTUSPHERE_DIRECTORY_BIND_REASON_LOCKED,
];

// userAccountControl / msDS-User-Account-Control-Computed bits read here.
const VIRTUSPHERE_DIRECTORY_UAC_ACCOUNTDISABLE = 0x0002;
const VIRTUSPHERE_DIRECTORY_UAC_LOCKOUT = 0x0010;

function directory_auth_source_is_valid(string $source): bool
{
    return in_array($source, VIRTUSPHERE_AUTH_SOURCES, true);
}

function directory_auth_source_require(string $source): string
{
    if (!directory_auth_source_is_valid($source)) {
        throw new InvalidArgumentException('invalid_auth_source');
    }

    return $source;
}
