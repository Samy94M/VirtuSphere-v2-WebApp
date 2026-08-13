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
];

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
