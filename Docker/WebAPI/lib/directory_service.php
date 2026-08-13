<?php

declare(strict_types=1);

require_once __DIR__ . '/audit_events.php';
require_once __DIR__ . '/directory_config.php';
require_once __DIR__ . '/directory_ldap.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/repo/directory.php';

/** @return array{host:string,port:int} */
function directory_controller_endpoint(array $controller): array
{
    return ['host' => (string) $controller['host'], 'port' => (int) $controller['port']];
}

function directory_outcome_is_transport(string $outcome): bool
{
    return in_array($outcome, [
        VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED,
        VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE,
        VIRTUSPHERE_DIRECTORY_OUTCOME_TIMEOUT,
    ], true);
}

function directory_outcome_message(string $outcome): string
{
    $key = match ($outcome) {
        VIRTUSPHERE_DIRECTORY_OUTCOME_EXTENSION_MISSING => 'outcome_extension_missing',
        VIRTUSPHERE_DIRECTORY_OUTCOME_TLS_FAILED => 'outcome_tls_failed',
        VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE => 'outcome_unavailable',
        VIRTUSPHERE_DIRECTORY_OUTCOME_TIMEOUT => 'outcome_timeout',
        VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED => 'outcome_bind_rejected',
        VIRTUSPHERE_DIRECTORY_OUTCOME_USER_BIND_REJECTED => 'outcome_not_authorized',
        VIRTUSPHERE_DIRECTORY_OUTCOME_SECRET_UNREADABLE => 'outcome_secret_unreadable',
        VIRTUSPHERE_DIRECTORY_OUTCOME_SEARCH_FAILED => 'outcome_search_failed',
        VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_FOUND => 'outcome_not_found',
        VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_AUTHORIZED => 'outcome_not_authorized',
        VIRTUSPHERE_DIRECTORY_OUTCOME_AMBIGUOUS => 'outcome_ambiguous',
        VIRTUSPHERE_DIRECTORY_OUTCOME_DOMAIN_MISMATCH => 'outcome_domain_mismatch',
        VIRTUSPHERE_DIRECTORY_OUTCOME_RODC_UNSUPPORTED => 'outcome_rodc_unsupported',
        VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE => 'outcome_invalid_response',
        default => 'outcome_unknown',
    };

    return __t('directory.' . $key);
}

/** Records only typed state and a throttled transition audit. */
function directory_observe_controller(mysqli $db, array $controller, int $revision, DirectoryLdapException|string $result, array $certificate = []): void
{
    $outcome = $result instanceof DirectoryLdapException ? $result->outcome : $result;
    $transport = $result instanceof DirectoryLdapException
        ? $result->transportFailure
        : directory_outcome_is_transport($outcome);
    $changed = repo_directory_record_controller_outcome(
        $db,
        (int) $controller['id'],
        $revision,
        $outcome,
        $transport,
        $certificate
    );
    if ($changed) {
        audit(
            $db,
            VIRTUSPHERE_LOG_CATEGORY_DIRECTORY,
            'directory controller ' . (int) $controller['id'] . ' state changed to ' . $outcome
        );
    }
}

/**
 * Tests one saved controller. This is the only operation that can admit it for
 * login on the current configuration revision.
 *
 * @return array{ok:bool,outcome:string}
 */
function directory_test_saved_controller(mysqli $db, int $controllerId, int $actorId): array
{
    $config = repo_directory_config($db);
    $controller = repo_directory_controller($db, $controllerId);
    if ($config === null || $controller === null) {
        return ['ok' => false, 'outcome' => VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE];
    }
    $revision = (int) $config['revision'];
    try {
        $runtime = directory_runtime_config($config);
        $result = directory_ldap_test_controller(directory_controller_endpoint($controller), $runtime);
        if (!repo_directory_apply_controller_test_success($db, $controllerId, $revision, $result['default_naming_context'], $actorId)) {
            return ['ok' => false, 'outcome' => VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE];
        }
        directory_observe_controller($db, $controller, $revision, VIRTUSPHERE_DIRECTORY_OUTCOME_OK, $result['certificate']);
        audit($db, VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, 'tested directory controller ' . $controllerId . ': ok', $actorId);

        return ['ok' => true, 'outcome' => VIRTUSPHERE_DIRECTORY_OUTCOME_OK];
    } catch (DirectoryLdapException $exception) {
        repo_directory_clear_controller_validation($db, $controllerId, $revision, $actorId);
        directory_observe_controller($db, $controller, $revision, $exception);
        audit($db, VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, 'tested directory controller ' . $controllerId . ': ' . $exception->outcome, $actorId);

        return ['ok' => false, 'outcome' => $exception->outcome];
    } catch (Throwable) {
        repo_directory_clear_controller_validation($db, $controllerId, $revision, $actorId);
        $exception = new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_INVALID_RESPONSE);
        directory_observe_controller($db, $controller, $revision, $exception);
        audit($db, VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, 'tested directory controller ' . $controllerId . ': ' . $exception->outcome, $actorId);

        return ['ok' => false, 'outcome' => $exception->outcome];
    }
}

/**
 * Tests a not-yet-saved configuration while AD is active. The current config
 * remains untouched unless this succeeds; the caller atomically saves it with
 * the returned naming context and controller id.
 *
 * @param array<string,mixed> $candidate
 * @return array{default_naming_context:string,certificate:array{fingerprint:string,not_after:string}}
 */
function directory_test_candidate(mysqli $db, array $candidate, int $controllerId): array
{
    $controller = repo_directory_controller($db, $controllerId);
    if ($controller === null) {
        throw new ValidationException(['controller_id' => __t('directory.err_controller_required')]);
    }

    return directory_ldap_test_controller(directory_controller_endpoint($controller), [
        'bind_upn' => (string) $candidate['bind_upn'],
        'bind_password' => (string) $candidate['bind_password'],
        'ca_certificate_pem' => (string) $candidate['ca_certificate_pem'],
        'user_search_base_dn' => (string) $candidate['user_search_base_dn'],
        'default_naming_context' => (string) $candidate['default_naming_context'],
    ]);
}

/**
 * Opens and service-binds one configured controller. A rejected search-account
 * secret pauses every validated controller for this revision: trying the same
 * secret against all DCs would multiply an account lockout.
 *
 * @return mixed LDAP\Connection
 */
function directory_open_service_connection(mysqli $db, array $storedConfig, array $runtimeConfig, array $controller, ?float $deadline = null): mixed
{
    $revision = (int) $storedConfig['revision'];
    try {
        $caFile = directory_ca_file((string) $runtimeConfig['ca_certificate_pem']);
        $connection = directory_ldap_connect((string) $controller['host'], (int) $controller['port'], $caFile, $deadline);
        directory_ldap_bind(
            $connection,
            (string) $runtimeConfig['bind_upn'],
            (string) $runtimeConfig['bind_password'],
            VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED,
            $deadline
        );
        return $connection;
    } catch (DirectoryLdapException $exception) {
        directory_observe_controller($db, $controller, $revision, $exception);
        if ($exception->outcome === VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED) {
            repo_directory_pause_controllers_for_bind_rejection($db, $revision);
            audit($db, VIRTUSPHERE_LOG_CATEGORY_DIRECTORY, 'directory search account bind rejected; automatic attempts paused');
        }
        throw $exception;
    }
}

/**
 * Runs a read operation with deterministic failover. Not-found may fail over
 * before any user password bind to cover UPN/object replication lag.
 *
 * @template T
 * @param callable(mixed,array<string,mixed>,array<string,mixed>,float):T $operation
 * @return T
 */
function directory_read_with_failover(mysqli $db, callable $operation, bool $failoverOnNotFound = false): mixed
{
    $stored = repo_directory_config($db);
    if ($stored === null || (int) $stored['enabled'] !== 1) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE, true);
    }
    if ((int) ($stored['automatic_bind_blocked_revision'] ?? 0) === (int) $stored['revision']) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_SERVICE_BIND_REJECTED);
    }
    try {
        $runtime = directory_runtime_config($stored);
    } catch (Throwable) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_SECRET_UNREADABLE);
    }
    $controllers = repo_directory_login_controllers($db, (int) $stored['revision']);
    if ($controllers === []) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE, true);
    }
    $deadline = directory_deadline_now() + VIRTUSPHERE_DIRECTORY_TOTAL_TIMEOUT_SECONDS;
    $last = new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_UNAVAILABLE, true);
    foreach ($controllers as $controller) {
        if (directory_deadline_now() >= $deadline) {
            throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_TIMEOUT, true);
        }
        $connection = null;
        $serviceBound = false;
        try {
            $connection = directory_open_service_connection($db, $stored, $runtime, $controller, $deadline);
            $serviceBound = true;
            $value = $operation($connection, $runtime, $controller, $deadline);
            directory_observe_controller($db, $controller, (int) $stored['revision'], VIRTUSPHERE_DIRECTORY_OUTCOME_OK);

            return $value;
        } catch (DirectoryLdapException $exception) {
            if ($serviceBound) {
                $healthyOutcome = in_array($exception->outcome, [
                    VIRTUSPHERE_DIRECTORY_OUTCOME_USER_BIND_REJECTED,
                    VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_AUTHORIZED,
                    VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_FOUND,
                    VIRTUSPHERE_DIRECTORY_OUTCOME_AMBIGUOUS,
                ], true);
                directory_observe_controller(
                    $db,
                    $controller,
                    (int) $stored['revision'],
                    $healthyOutcome ? VIRTUSPHERE_DIRECTORY_OUTCOME_OK : $exception
                );
            }
            $last = $exception;
            $mayContinue = $exception->transportFailure
                || ($failoverOnNotFound && $exception->outcome === VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_FOUND);
            if (!$mayContinue) {
                throw $exception;
            }
        } finally {
            if (is_object($connection)) {
                @ldap_unbind($connection);
            }
        }
    }

    throw $last;
}

function directory_user_search_base(array $runtime): string
{
    $base = trim((string) $runtime['user_search_base_dn']);

    return $base !== '' ? $base : trim((string) $runtime['default_naming_context']);
}

/** @return array{rows:list<array<string,mixed>>,truncated:bool} */
function directory_search_users(mysqli $db, string $term): array
{
    $term = trim($term);
    if (mb_strlen($term) < VIRTUSPHERE_DIRECTORY_SEARCH_MIN_CHARS || mb_strlen($term) > VIRTUSPHERE_DIRECTORY_SEARCH_MAX_CHARS || str_contains($term, "\0")) {
        throw new ValidationException(['directory_search' => __t('directory.err_search_term', [
            'min' => VIRTUSPHERE_DIRECTORY_SEARCH_MIN_CHARS,
            'max' => VIRTUSPHERE_DIRECTORY_SEARCH_MAX_CHARS,
        ])]);
    }
    if (!directory_ldap_available()) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_EXTENSION_MISSING);
    }
    $escaped = ldap_escape($term, '', LDAP_ESCAPE_FILTER);
    $filter = '(&(objectCategory=person)(objectClass=user)(sAMAccountType=805306368)'
        . '(|(userPrincipalName=*' . $escaped . '*)(sAMAccountName=*' . $escaped . '*)(displayName=*' . $escaped . '*)))';

    return directory_read_with_failover(
        $db,
        static fn (mixed $connection, array $runtime, array $controller, float $deadline): array => directory_ldap_search_users(
            $connection,
            directory_user_search_base($runtime),
            $filter,
            $deadline
        )
    );
}

/** @return array<string,mixed> */
function directory_find_user_by_upn(mysqli $db, string $upn): array
{
    if (!directory_upn_is_valid($upn)) {
        throw new DirectoryLdapException(VIRTUSPHERE_DIRECTORY_OUTCOME_NOT_FOUND);
    }

    return directory_read_with_failover(
        $db,
        static fn (mixed $connection, array $runtime, array $controller, float $deadline): array => directory_ldap_find_user_by_upn(
            $connection,
            directory_user_search_base($runtime),
            $upn,
            $deadline
        ),
        true
    );
}

/** @return array<string,mixed> */
function directory_find_user_by_guid(mysqli $db, string $guidBytes): array
{
    return directory_read_with_failover(
        $db,
        static fn (mixed $connection, array $runtime, array $controller, float $deadline): array => directory_ldap_find_user_by_guid(
            $connection,
            directory_user_search_base($runtime),
            $guidBytes,
            $deadline
        ),
        true
    );
}

/**
 * Search results are held server-side for five minutes. The browser gets a
 * random one-time token, never an authoritative GUID/DN hidden field.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function directory_store_import_candidates(array $rows): array
{
    $stored = [];
    $display = [];
    foreach ($rows as $row) {
        $token = bin2hex(random_bytes(16));
        $stored[$token] = bin2hex((string) $row['guid_bytes']);
        $copy = $row;
        unset($copy['guid_bytes'], $copy['dn']);
        $copy['import_token'] = $token;
        $display[] = $copy;
    }
    $_SESSION['directory_import_candidates'] = [
        'expires_at' => time() + VIRTUSPHERE_DIRECTORY_IMPORT_CANDIDATE_TTL_SECONDS,
        'items' => $stored,
    ];

    return $display;
}

function directory_take_import_candidate(string $token): string
{
    $state = $_SESSION['directory_import_candidates'] ?? null;
    if (!is_array($state) || (int) ($state['expires_at'] ?? 0) < time()) {
        unset($_SESSION['directory_import_candidates'], $_SESSION['directory_search_display']);
        throw new ValidationException(['import_token' => __t('directory.err_import_expired')]);
    }
    $hex = is_array($state['items'] ?? null) ? (string) ($state['items'][$token] ?? '') : '';
    $bytes = $hex !== '' ? hex2bin($hex) : false;
    if (!is_string($bytes) || strlen($bytes) !== 16) {
        throw new ValidationException(['import_token' => __t('directory.err_import_expired')]);
    }
    unset($state['items'][$token]);
    $_SESSION['directory_import_candidates'] = $state;
    $displayState = $_SESSION['directory_search_display'] ?? null;
    if (is_array($displayState) && is_array($displayState['rows'] ?? null)) {
        $displayState['rows'] = array_values(array_filter(
            $displayState['rows'],
            static fn (mixed $row): bool => !is_array($row) || (string) ($row['import_token'] ?? '') !== $token
        ));
        $_SESSION['directory_search_display'] = $displayState;
    }

    return $bytes;
}

/** @return array{created:bool,user_id:int,reason:string} */
function directory_import_candidate(mysqli $db, string $token, string $role, int $actorId): array
{
    $guid = directory_take_import_candidate($token);
    $entry = directory_find_user_by_guid($db, $guid);
    if (empty($entry['enabled'])) {
        throw new ValidationException(['import_token' => __t('directory.err_import_disabled')]);
    }
    $result = repo_directory_import_user($db, $entry, role_normalize($role));
    if ($result['reason'] === 'name_conflict') {
        throw new ValidationException(['import_token' => __t('directory.err_import_name_conflict')]);
    }
    if ($result['created']) {
        audit($db, VIRTUSPHERE_LOG_CATEGORY_USERS, 'imported Active Directory user id ' . $result['user_id'], $actorId);
    }

    return $result;
}
