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
        VIRTUSPHERE_MECM_NOT_READY => ['badge' => 'neutral'],
        VIRTUSPHERE_MECM_PENDING => ['badge' => 'warning'],
        VIRTUSPHERE_MECM_SUBMITTED => ['badge' => 'warning'],
        VIRTUSPHERE_MECM_REGISTERED => ['badge' => 'success'],
        VIRTUSPHERE_MECM_FAILED => ['badge' => 'danger'],
        default => ['badge' => 'neutral'],
    };
}

function virtusphere_legacy_status_from_states(string $lifecycleState, string $mecmSyncState): string
{
    if ($lifecycleState === VIRTUSPHERE_LIFECYCLE_OS_INSTALLED) {
        return VIRTUSPHERE_STATUS_OS_INSTALLED;
    }
    if ($lifecycleState === VIRTUSPHERE_LIFECYCLE_OS_INSTALLING || $mecmSyncState === VIRTUSPHERE_MECM_REGISTERED) {
        return VIRTUSPHERE_STATUS_OS_INSTALLING;
    }
    if ($lifecycleState === VIRTUSPHERE_LIFECYCLE_DEPLOYED || $mecmSyncState === VIRTUSPHERE_MECM_PENDING || $mecmSyncState === VIRTUSPHERE_MECM_SUBMITTED) {
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
        VIRTUSPHERE_STATUS_OS_INSTALLED => ['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLED, 'mecm_sync_state' => VIRTUSPHERE_MECM_REGISTERED],
        VIRTUSPHERE_STATUS_OS_INSTALLING => ['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_OS_INSTALLING, 'mecm_sync_state' => VIRTUSPHERE_MECM_REGISTERED],
        VIRTUSPHERE_STATUS_DEPLOYED => ['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_DEPLOYED, 'mecm_sync_state' => VIRTUSPHERE_MECM_PENDING],
        VIRTUSPHERE_STATUS_REGISTERED => ['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_READY, 'mecm_sync_state' => VIRTUSPHERE_MECM_NOT_READY],
        default => ['lifecycle_state' => VIRTUSPHERE_LIFECYCLE_INITIALIZING, 'mecm_sync_state' => VIRTUSPHERE_MECM_NOT_READY],
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
        'warning' => ['badge' => 'warning'],
        // 'missing' = expected but never seen (another source already reported).
        'missing' => ['badge' => 'warning'],
        'danger' => ['badge' => 'danger'],
        default => ['badge' => 'neutral'],
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