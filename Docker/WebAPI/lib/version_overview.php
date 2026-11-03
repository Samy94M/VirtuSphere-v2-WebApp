<?php

declare(strict_types=1);

/**
 * Version overview for the settings tab "Version". It answers one operator question
 * from data the portal already holds: does every component run the same release as
 * this portal? (plan docs/audits/2026-10-15-versioning-plan.md)
 *
 * The MECM tasks report their release inside the run identifier, the Ansible hosts
 * report whether the collection-version probe passed, and the database reports
 * whether its migrations match the code. Each state function is pure and decides from
 * one input, so the rules are unit-tested without a database. version_overview() only
 * gathers the inputs.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/release_version.php';
require_once __DIR__ . '/repo/migration_state.php';
require_once __DIR__ . '/repo/heartbeats.php';
require_once __DIR__ . '/repo/ansible_preflight.php';
require_once __DIR__ . '/repo/credentials.php';

const VIRTUSPHERE_VERSION_STATE_OK = 'ok';
const VIRTUSPHERE_VERSION_STATE_DEVIATES = 'deviates';
const VIRTUSPHERE_VERSION_STATE_UNKNOWN = 'unknown';

/**
 * State of one MECM source against the portal release.
 *
 * Only a release that was seen to match counts as ok. A missing report, an identifier
 * without a release (old commit or placeholder forms) or a portal without a readable
 * release is unknown, never green. A deviating file (`-abw`) is reported before any
 * release comparison, because a hand-changed file deviates whatever release it claims.
 *
 * @param array<string,mixed>|null $heartbeat the deploy_integration_heartbeats row, or null when the source never reported
 * @return array{state: string, reason: ?string, release: ?string, identifier: ?string, reported_at: ?string}
 */
function version_mecm_source_state(?string $portalRelease, ?array $heartbeat): array
{
    if ($heartbeat === null) {
        return version_mecm_result(VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'no_report', null, null, null);
    }

    $identifier = trim((string) ($heartbeat['last_script_version'] ?? ''));
    $identifier = $identifier === '' ? null : $identifier;
    $reportedAt = isset($heartbeat['last_seen_at']) ? (string) $heartbeat['last_seen_at'] : null;
    if ($identifier === null) {
        return version_mecm_result(VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'no_release', null, null, $reportedAt);
    }

    $parsed = release_from_script_identifier($identifier);
    $release = $parsed['release'];
    if ($parsed['deviates']) {
        return version_mecm_result(VIRTUSPHERE_VERSION_STATE_DEVIATES, 'files', $release, $identifier, $reportedAt);
    }
    if ($release === null) {
        return version_mecm_result(VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'no_release', null, $identifier, $reportedAt);
    }
    if ($portalRelease === null) {
        return version_mecm_result(VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'portal_unknown', $release, $identifier, $reportedAt);
    }
    if ($release !== $portalRelease) {
        return version_mecm_result(VIRTUSPHERE_VERSION_STATE_DEVIATES, 'release', $release, $identifier, $reportedAt);
    }

    return version_mecm_result(VIRTUSPHERE_VERSION_STATE_OK, null, $release, $identifier, $reportedAt);
}

/**
 * @return array{state: string, reason: ?string, release: ?string, identifier: ?string, reported_at: ?string}
 */
function version_mecm_result(string $state, ?string $reason, ?string $release, ?string $identifier, ?string $reportedAt): array
{
    return [
        'state' => $state,
        'reason' => $reason,
        'release' => $release,
        'identifier' => $identifier,
        'reported_at' => $reportedAt,
    ];
}

/**
 * Whether the database holds every migration the code defines.
 *
 * Applied names are judged by their position in the code list, not by the order rows
 * were recorded in. Applied names the code no longer defines are ignored: they cannot
 * leave the schema behind the code.
 *
 * @param list<string> $codeMigrations
 * @param list<string> $appliedMigrations
 * @return array{state: string, last_applied: ?string, pending: int}
 */
function version_database_state(array $codeMigrations, array $appliedMigrations): array
{
    if ($codeMigrations === []) {
        return ['state' => VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'last_applied' => null, 'pending' => 0];
    }

    $applied = array_fill_keys($appliedMigrations, true);
    $pending = 0;
    $lastApplied = null;
    foreach ($codeMigrations as $name) {
        if (isset($applied[$name])) {
            $lastApplied = $name;
        } else {
            $pending++;
        }
    }

    return [
        'state' => $pending > 0 ? VIRTUSPHERE_VERSION_STATE_DEVIATES : VIRTUSPHERE_VERSION_STATE_OK,
        'last_applied' => $lastApplied,
        'pending' => $pending,
    ];
}

/**
 * State of one Ansible host from its last preflight.
 *
 * Only a result for the current credential configuration counts: evidence_current
 * compares the tested revision and generation with the stored ones, so an edited host
 * never keeps a green state it has not earned. The collection-version probe is the one
 * link where a release mismatch on a host appears, so its failure is "deviates". Any
 * other failed link means the chain did not reach the comparison, which is unknown.
 *
 * @param array<string,mixed>|null $preflight the repo_ansible_preflight_states() row, or null when the host was never tested
 * @return array{state: string, reason: ?string, checked_at: ?string}
 */
function version_ansible_host_state(?array $preflight): array
{
    if ($preflight === null) {
        return version_ansible_result(VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'not_tested', null);
    }

    $checkedAt = isset($preflight['last_checked_at']) ? (string) $preflight['last_checked_at'] : null;
    if (($preflight['evidence_current'] ?? false) !== true) {
        return version_ansible_result(VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'stale_evidence', $checkedAt);
    }

    $status = (string) ($preflight['last_status'] ?? '');
    // A warning means the whole chain, including the collection-version probe, was green.
    if ($status === VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK || $status === VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_WARNING) {
        return version_ansible_result(VIRTUSPHERE_VERSION_STATE_OK, null, $checkedAt);
    }
    if ($status === VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_FAILED) {
        // 'runtime-versions' is the component name that ansible_command_preflight.php
        // gives the version probe. It is compared as a plain string on purpose, so this
        // file does not have to load the Ansible command builders for one label.
        if (($preflight['last_component'] ?? null) === 'runtime-versions') {
            return version_ansible_result(VIRTUSPHERE_VERSION_STATE_DEVIATES, 'runtime_versions', $checkedAt);
        }

        return version_ansible_result(VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'chain_failed', $checkedAt);
    }

    return version_ansible_result(VIRTUSPHERE_VERSION_STATE_UNKNOWN, 'not_tested', $checkedAt);
}

/**
 * @return array{state: string, reason: ?string, checked_at: ?string}
 */
function version_ansible_result(string $state, ?string $reason, ?string $checkedAt): array
{
    return ['state' => $state, 'reason' => $reason, 'checked_at' => $checkedAt];
}

/**
 * Everything the Version tab shows, gathered in one read.
 *
 * @return array{
 *     portal: array{release: ?string, commit: ?string},
 *     database: array{state: string, last_applied: ?string, pending: int},
 *     runtime: array{php: string, mysql: string},
 *     ansible_hosts: list<array{id: int, name: string, state: string, reason: ?string, checked_at: ?string}>,
 *     mecm: array<string, array{state: string, reason: ?string, release: ?string, identifier: ?string, reported_at: ?string}>
 * }
 */
function version_overview(mysqli $db): array
{
    $release = portal_release();

    $preflightStates = repo_ansible_preflight_states($db);
    $ansibleHosts = [];
    foreach (repo_credentials_by_type($db, VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE) as $credential) {
        $id = (int) $credential['id'];
        $host = version_ansible_host_state($preflightStates[$id] ?? null);
        $ansibleHosts[] = [
            'id' => $id,
            'name' => (string) $credential['name'],
            'state' => $host['state'],
            'reason' => $host['reason'],
            'checked_at' => $host['checked_at'],
        ];
    }

    $heartbeats = repo_integration_heartbeats($db);
    $mecm = [];
    foreach (VIRTUSPHERE_INTEGRATION_RUN_SOURCES as $source) {
        $mecm[$source] = version_mecm_source_state($release, $heartbeats[$source] ?? null);
    }

    return [
        'portal' => ['release' => $release, 'commit' => portal_build_commit()],
        'database' => version_database_state(release_code_migrations(), repo_applied_migration_names($db)),
        'runtime' => ['php' => PHP_VERSION, 'mysql' => (string) $db->server_info],
        'ansible_hosts' => $ansibleHosts,
        'mecm' => $mecm,
    ];
}
