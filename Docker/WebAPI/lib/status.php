<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';

function virtusphere_status_meta(string $legacyStatus): array
{
    return match ($legacyStatus) {
        VIRTUSPHERE_STATUS_INITIALIZING => ['label' => 'Initializing', 'badge' => 'neutral'],
        VIRTUSPHERE_STATUS_REGISTERED => ['label' => 'Registered', 'badge' => 'info'],
        VIRTUSPHERE_STATUS_DEPLOYED => ['label' => 'Deployed', 'badge' => 'success'],
        VIRTUSPHERE_STATUS_OS_INSTALLING => ['label' => 'OS Installing', 'badge' => 'warning'],
        VIRTUSPHERE_STATUS_OS_INSTALLED => ['label' => 'OS Installed', 'badge' => 'success'],
        default => ['label' => $legacyStatus, 'badge' => 'neutral'],
    };
}

function virtusphere_lifecycle_meta(string $lifecycleState): array
{
    return match ($lifecycleState) {
        VIRTUSPHERE_LIFECYCLE_INITIALIZING => ['badge' => 'neutral'],
        VIRTUSPHERE_LIFECYCLE_READY => ['badge' => 'info'],
        VIRTUSPHERE_LIFECYCLE_DEPLOYING => ['badge' => 'warning'],
        VIRTUSPHERE_LIFECYCLE_DEPLOYED => ['badge' => 'success'],
        VIRTUSPHERE_LIFECYCLE_OS_INSTALLING => ['badge' => 'warning'],
        VIRTUSPHERE_LIFECYCLE_OS_INSTALLED => ['badge' => 'success'],
        VIRTUSPHERE_LIFECYCLE_FAILED => ['badge' => 'danger'],
        default => ['badge' => 'neutral'],
    };
}

function virtusphere_mecm_sync_meta(string $mecmSyncState): array
{
    return match ($mecmSyncState) {
        VIRTUSPHERE_MECM_SYNC_NOT_READY => ['badge' => 'neutral'],
        VIRTUSPHERE_MECM_SYNC_PENDING => ['badge' => 'warning'],
        VIRTUSPHERE_MECM_SYNC_SUBMITTED => ['badge' => 'warning'],
        VIRTUSPHERE_MECM_SYNC_REGISTERED => ['badge' => 'success'],
        VIRTUSPHERE_MECM_SYNC_FAILED => ['badge' => 'danger'],
        default => ['badge' => 'neutral'],
    };
}

function virtusphere_legacy_status_from_states(string $lifecycleState, string $mecmSyncState): string
{
    if ($lifecycleState === VIRTUSPHERE_LIFECYCLE_OS_INSTALLED) {
        return VIRTUSPHERE_STATUS_OS_INSTALLED;
    }
    if ($lifecycleState === VIRTUSPHERE_LIFECYCLE_OS_INSTALLING || $mecmSyncState === VIRTUSPHERE_MECM_SYNC_REGISTERED) {
        return VIRTUSPHERE_STATUS_OS_INSTALLING;
    }
    if ($lifecycleState === VIRTUSPHERE_LIFECYCLE_DEPLOYED || $mecmSyncState === VIRTUSPHERE_MECM_SYNC_PENDING || $mecmSyncState === VIRTUSPHERE_MECM_SYNC_SUBMITTED) {
        return VIRTUSPHERE_STATUS_DEPLOYED;
    }
    if ($lifecycleState === VIRTUSPHERE_LIFECYCLE_READY) {
        return VIRTUSPHERE_STATUS_REGISTERED;
    }

    return VIRTUSPHERE_STATUS_INITIALIZING;
}

function virtusphere_states_from_legacy_status(string $legacyStatus): array
{
    return match ($legacyStatus) {
        VIRTUSPHERE_STATUS_OS_INSTALLED => ['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, 'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_REGISTERED],
        VIRTUSPHERE_STATUS_OS_INSTALLING => ['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, 'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_REGISTERED],
        VIRTUSPHERE_STATUS_DEPLOYED => ['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_DEPLOYED, 'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_PENDING],
        VIRTUSPHERE_STATUS_REGISTERED => ['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_READY, 'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_NOT_READY],
        default => ['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_INITIALIZING, 'mecm_sync_state' => VIRTUSPHERE_MECM_SYNC_NOT_READY],
    };
}

function virtusphere_is_lifecycle_state(string $state): bool
{
    return in_array($state, VIRTUSPHERE_LIFECYCLE_STATES, true);
}

function virtusphere_assert_lifecycle_state(string $state): string
{
    if (!virtusphere_is_lifecycle_state($state)) {
        throw new InvalidArgumentException('Unknown VM lifecycle state: ' . $state);
    }

    return $state;
}

// --- Integration heartbeat staleness (ADR-0018) -----------------------------
// Display-only derivation; timestamps are server-side (NOW()) and untrusted
// client clocks never enter these calculations.

function virtusphere_heartbeat_staleness(?string $lastSeenAt, int $intervalSeconds, ?string $lastStatus = null, ?int $now = null): string
{
    if ($lastStatus === VIRTUSPHERE_HEARTBEAT_STATUS_FAIL) {
        return 'danger';
    }
    if ($lastSeenAt === null || $lastSeenAt === '') {
        return 'unknown';
    }

    $seenTs = strtotime($lastSeenAt);
    if ($seenTs === false) {
        return 'unknown';
    }

    $age = ($now ?? time()) - $seenTs;
    $warnAfter = max($intervalSeconds * VIRTUSPHERE_HEARTBEAT_WARN_MULTIPLIER, VIRTUSPHERE_HEARTBEAT_WARN_FLOOR_SECONDS);
    $dangerAfter = max($intervalSeconds * VIRTUSPHERE_HEARTBEAT_DANGER_MULTIPLIER, VIRTUSPHERE_HEARTBEAT_DANGER_FLOOR_SECONDS);

    if ($age > $dangerAfter) {
        return 'danger';
    }
    if ($age > $warnAfter) {
        return 'warning';
    }

    return 'ok';
}

function virtusphere_heartbeat_meta(string $staleness): array
{
    return match ($staleness) {
        'ok' => ['badge' => 'success'],
        // 'legacy' = a fresh V1 heartbeat whose result the script has not yet
        // confirmed. Yellow like a warning, but a distinct, benign label.
        'legacy' => ['badge' => 'warning'],
        'warning' => ['badge' => 'warning'],
        // 'missing' = expected but never seen (another source already reported).
        'missing' => ['badge' => 'warning'],
        'danger' => ['badge' => 'danger'],
        default => ['badge' => 'neutral'],
    };
}

/**
 * Severity order of an Ampel state, for "worst of a group" roll-ups. One ranking
 * for every group (heartbeat sources, ESXi credentials, Ansible credentials):
 * three copies used to exist and had already disagreed about whether `missing`
 * outranks `warning`, which is a difference the tiles must never show.
 */
function virtusphere_heartbeat_state_rank(string $state): int
{
    return match ($state) {
        'danger' => 5,
        // Worse than a delayed source: it has never reported at all, while its
        // siblings do, so nothing is going to recover on its own.
        'missing' => 4,
        'warning' => 3,
        // A fresh legacy heartbeat: the task is alive but its result is
        // unconfirmed, milder than a stale reporter.
        'legacy' => 2,
        'ok' => 0,
        default => 1,
    };
}

// --- V2 result-reporting derivation (ADR-0018 reportRun) --------------------
// Display-only, server-clock only. `last_event` is the sole driver of legacy vs
// V2 and running vs completed; `report_version` never enters these.

// Maps an integration source to its display group kind: 'sync' (the three MECM
// sync tasks, subject to the V2/legacy result semantics), 'site' (the MECM
// site-health reporter, completed-only) or 'internal' (the maintenance worker,
// plain liveness that is never shown as legacy).
function virtusphere_integration_source_kind(string $source): string
{
    if (in_array($source, VIRTUSPHERE_INTEGRATION_MECM_SYNC_SOURCES, true)) {
        return 'sync';
    }
    if (in_array($source, VIRTUSPHERE_INTEGRATION_MECM_SITE_SOURCES, true)) {
        return 'site';
    }

    return 'internal';
}

// One completed run: fail is always red; otherwise the last result ages out
// through the normal staleness thresholds and a fresh result shows its own
// outcome (ok=green, warning=yellow, unknown=grey). An `unknown` counts as
// activity, so its last_result_at is fresh and it stays grey until the reporter
// actually goes silent.
function virtusphere_run_completed_state(array $row, ?int $now = null): string
{
    $outcome = (string) ($row['last_status'] ?? '');
    if ($outcome === VIRTUSPHERE_RUN_OUTCOME_FAIL) {
        return 'danger';
    }

    $resultAt = isset($row['last_result_at']) ? (string) $row['last_result_at'] : null;
    if ($resultAt === null || $resultAt === '') {
        return $outcome === VIRTUSPHERE_RUN_OUTCOME_OK ? 'ok'
            : ($outcome === VIRTUSPHERE_RUN_OUTCOME_WARNING ? 'warning' : 'unknown');
    }

    $stale = virtusphere_heartbeat_staleness($resultAt, (int) ($row['interval_seconds'] ?? 0), null, $now);
    if ($stale === 'danger' || $stale === 'warning') {
        return $stale;
    }

    return match ($outcome) {
        VIRTUSPHERE_RUN_OUTCOME_OK => 'ok',
        VIRTUSPHERE_RUN_OUTCOME_WARNING => 'warning',
        default => 'unknown',
    };
}

// A run in progress (two-clock model): the badge keeps the last completed
// result, and the row is not treated as stale until the run itself exceeds
// max(3x interval, 60s, RUN_GRACE). Beyond that the reporter is stuck and ages
// out normally.
function virtusphere_run_running_state(array $row, ?int $now = null): string
{
    $interval = (int) ($row['interval_seconds'] ?? 0);
    $attemptAt = isset($row['last_attempt_at']) ? (string) $row['last_attempt_at'] : null;
    $attemptTs = $attemptAt !== null && $attemptAt !== '' ? strtotime($attemptAt) : false;
    if ($attemptTs !== false) {
        $age = ($now ?? time()) - $attemptTs;
        $grace = max(
            $interval * VIRTUSPHERE_HEARTBEAT_WARN_MULTIPLIER,
            VIRTUSPHERE_HEARTBEAT_WARN_FLOOR_SECONDS,
            VIRTUSPHERE_RUN_GRACE_SECONDS
        );
        if ($age > $grace) {
            $dangerAfter = max(
                $interval * VIRTUSPHERE_HEARTBEAT_DANGER_MULTIPLIER,
                VIRTUSPHERE_HEARTBEAT_DANGER_FLOOR_SECONDS
            );

            return $age > $dangerAfter ? 'danger' : 'warning';
        }
    }

    if (empty($row['last_result_at'])) {
        return 'unknown';
    }

    return match ((string) ($row['last_status'] ?? '')) {
        VIRTUSPHERE_RUN_OUTCOME_FAIL => 'danger',
        VIRTUSPHERE_RUN_OUTCOME_OK => 'ok',
        VIRTUSPHERE_RUN_OUTCOME_WARNING => 'warning',
        default => 'unknown',
    };
}

// A source that only sent a legacy heartbeat: a fresh beat is 'legacy' (yellow,
// result not confirmed) and it ages out through the normal thresholds. Follows
// last_event, so a script rollback from V2 back to heartbeats shows legacy again.
function virtusphere_run_legacy_state(array $row, ?int $now = null): string
{
    $stale = virtusphere_heartbeat_staleness(
        isset($row['last_seen_at']) ? (string) $row['last_seen_at'] : null,
        (int) ($row['interval_seconds'] ?? 0),
        isset($row['last_status']) ? (string) $row['last_status'] : null,
        $now
    );

    return $stale === 'ok' ? 'legacy' : $stale;
}

// Central display-state derivation for one integration source row (ADR-0018).
// $kind comes from virtusphere_integration_source_kind(); $anySeen is whether
// any source in the SAME group has reported, so missing is derived per group.
function virtusphere_integration_row_state(?array $row, string $kind, bool $anySeen, ?int $now = null): string
{
    if ($row === null) {
        return $anySeen ? 'missing' : 'unknown';
    }

    if ($kind === 'internal') {
        return virtusphere_heartbeat_staleness(
            isset($row['last_seen_at']) ? (string) $row['last_seen_at'] : null,
            (int) ($row['interval_seconds'] ?? 0),
            isset($row['last_status']) ? (string) $row['last_status'] : null,
            $now
        );
    }

    if ($kind === 'site') {
        return virtusphere_run_completed_state($row, $now);
    }

    return match ((string) ($row['last_event'] ?? VIRTUSPHERE_INTEGRATION_EVENT_HEARTBEAT)) {
        VIRTUSPHERE_RUN_EVENT_COMPLETED => virtusphere_run_completed_state($row, $now),
        VIRTUSPHERE_RUN_EVENT_STARTED => virtusphere_run_running_state($row, $now),
        default => virtusphere_run_legacy_state($row, $now),
    };
}

// Derives the display state of one client deploy phase from its latest event
// row (deploy_client_events): none|running|unconfirmed|finished|failed.
function virtusphere_client_phase_state(?array $latestEvent, ?int $now = null): string
{
    if ($latestEvent === null) {
        return 'none';
    }

    $event = (string) ($latestEvent['event'] ?? '');
    if ($event === VIRTUSPHERE_CLIENT_EVENT_FINISHED) {
        return 'finished';
    }
    if ($event === VIRTUSPHERE_CLIENT_EVENT_FAILED) {
        return 'failed';
    }
    if ($event === VIRTUSPHERE_CLIENT_EVENT_STARTED) {
        $startedTs = strtotime((string) ($latestEvent['created_at'] ?? ''));
        if ($startedTs !== false && (($now ?? time()) - $startedTs) > VIRTUSPHERE_CLIENT_PHASE_UNCONFIRMED_AFTER_SECONDS) {
            return 'unconfirmed';
        }

        return 'running';
    }

    return 'none';
}

function virtusphere_client_phase_meta(string $phaseState): array
{
    return match ($phaseState) {
        'running' => ['badge' => 'info'],
        'unconfirmed' => ['badge' => 'warning'],
        'finished' => ['badge' => 'success'],
        'failed' => ['badge' => 'danger'],
        default => ['badge' => 'neutral'],
    };
}

function virtusphere_is_mecm_sync_state(string $state): bool
{
    return in_array($state, VIRTUSPHERE_MECM_SYNC_STATES, true);
}

function virtusphere_assert_mecm_sync_state(string $state): string
{
    if (!virtusphere_is_mecm_sync_state($state)) {
        throw new InvalidArgumentException('Unknown MECM sync state: ' . $state);
    }

    return $state;
}