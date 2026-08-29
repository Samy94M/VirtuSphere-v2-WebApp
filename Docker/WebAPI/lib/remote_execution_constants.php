<?php

declare(strict_types=1);

const VIRTUSPHERE_REMOTE_PROTOCOL_VERSION = 1;

const VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY = 'legacy_v1';
const VIRTUSPHERE_EXECUTION_CONTRACT_REMOTE = 'remote_v1';
const VIRTUSPHERE_EXECUTION_CONTRACTS = [
    VIRTUSPHERE_EXECUTION_CONTRACT_LEGACY,
    VIRTUSPHERE_EXECUTION_CONTRACT_REMOTE,
];

const VIRTUSPHERE_REMOTE_ACTIVATION_DISABLED = 'disabled';
const VIRTUSPHERE_REMOTE_ACTIVATION_LEGACY = 'legacy_explicit';
const VIRTUSPHERE_REMOTE_ACTIVATION_PILOT = 'pilot_remote';
const VIRTUSPHERE_REMOTE_ACTIVATION_ENABLED = 'remote_enabled';
const VIRTUSPHERE_REMOTE_ACTIVATION_ROLLBACK = 'rollback_pending';
const VIRTUSPHERE_REMOTE_ACTIVATION_STATES = [
    VIRTUSPHERE_REMOTE_ACTIVATION_DISABLED,
    VIRTUSPHERE_REMOTE_ACTIVATION_LEGACY,
    VIRTUSPHERE_REMOTE_ACTIVATION_PILOT,
    VIRTUSPHERE_REMOTE_ACTIVATION_ENABLED,
    VIRTUSPHERE_REMOTE_ACTIVATION_ROLLBACK,
];

const VIRTUSPHERE_SUPERVISOR_CONTRACT_WORKER = 'worker_v1';
const VIRTUSPHERE_SUPERVISOR_CONTRACT_SUPERVISOR = 'supervisor_v1';
const VIRTUSPHERE_SUPERVISOR_CONTRACTS = [
    VIRTUSPHERE_SUPERVISOR_CONTRACT_WORKER,
    VIRTUSPHERE_SUPERVISOR_CONTRACT_SUPERVISOR,
];

const VIRTUSPHERE_RUNTIME_ROTATION_INSTALL = 'install';
const VIRTUSPHERE_RUNTIME_ROTATION_RESTORE = 'restore';
const VIRTUSPHERE_RUNTIME_ROTATION_CLONE = 'clone';
const VIRTUSPHERE_RUNTIME_ROTATION_REASONS = [
    VIRTUSPHERE_RUNTIME_ROTATION_INSTALL,
    VIRTUSPHERE_RUNTIME_ROTATION_RESTORE,
    VIRTUSPHERE_RUNTIME_ROTATION_CLONE,
];

const VIRTUSPHERE_DEPLOY_RECOVERY_REMOTE_OBSERVATION = 'remote_observation';
const VIRTUSPHERE_DEPLOY_RECOVERY_LEGACY_UNCERTAIN = 'legacy_uncertain';
const VIRTUSPHERE_DEPLOY_RECOVERY_FOREIGN_GENERATION = 'foreign_generation';
const VIRTUSPHERE_DEPLOY_RECOVERY_REASONS = [
    VIRTUSPHERE_DEPLOY_RECOVERY_REMOTE_OBSERVATION,
    VIRTUSPHERE_DEPLOY_RECOVERY_LEGACY_UNCERTAIN,
    VIRTUSPHERE_DEPLOY_RECOVERY_FOREIGN_GENERATION,
];

const VIRTUSPHERE_REMOTE_CONTROLLER_STATES = [
    'prepared', 'active', 'exited_0', 'exited_nonzero', 'exited_signal',
    'lost_after_start', 'never_started', 'protocol_error',
];
const VIRTUSPHERE_REMOTE_EFFECT_STATES = ['not_started', 'active_or_possible', 'goal_verified', 'divergence_verified', 'unknown'];
const VIRTUSPHERE_REMOTE_RECONCILIATION_STATES = ['not_required', 'pending', 'running', 'resolved_success', 'resolved_failure', 'manual_required'];
const VIRTUSPHERE_REMOTE_CLEANUP_STATES = ['pending', 'eligible', 'running', 'cleaned', 'failed'];

const VIRTUSPHERE_REMOTE_PROTOCOL_DOCUMENT_MAX_BYTES = 65536;

// The three axes of the deploy service snapshot (Etappe 13R).
//
// They are separate because they are independently true. A worker can be busy
// AND set to pause after the current job; it can be offline AND have a case
// waiting for a person. Folding them into one word forces a choice between two
// facts, and whichever one loses is the one the operator needed. A compact
// badge is still derived from all three, but every detail view shows all three.
const VIRTUSPHERE_DEPLOY_AVAILABILITY_READY = 'ready';
const VIRTUSPHERE_DEPLOY_AVAILABILITY_BUSY = 'busy';
const VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED = 'degraded';
const VIRTUSPHERE_DEPLOY_AVAILABILITY_COOLDOWN = 'cooldown';
const VIRTUSPHERE_DEPLOY_AVAILABILITY_OFFLINE = 'offline';
const VIRTUSPHERE_DEPLOY_AVAILABILITY_STATES = [
    VIRTUSPHERE_DEPLOY_AVAILABILITY_READY,
    VIRTUSPHERE_DEPLOY_AVAILABILITY_BUSY,
    VIRTUSPHERE_DEPLOY_AVAILABILITY_DEGRADED,
    VIRTUSPHERE_DEPLOY_AVAILABILITY_COOLDOWN,
    VIRTUSPHERE_DEPLOY_AVAILABILITY_OFFLINE,
];

// The claim axis is persisted, because it is an operator's decision and must
// survive a worker restart. `pause_after_current` exists so a pause never has
// to interrupt work that is already changing ESXi: the worker stops taking new
// jobs immediately and converts to `paused` itself once its current job reaches
// a terminal state.
const VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING = 'accepting';
const VIRTUSPHERE_DEPLOY_CLAIM_PAUSE_AFTER_CURRENT = 'pause_after_current';
const VIRTUSPHERE_DEPLOY_CLAIM_PAUSED = 'paused';
const VIRTUSPHERE_DEPLOY_CLAIM_STATES = [
    VIRTUSPHERE_DEPLOY_CLAIM_ACCEPTING,
    VIRTUSPHERE_DEPLOY_CLAIM_PAUSE_AFTER_CURRENT,
    VIRTUSPHERE_DEPLOY_CLAIM_PAUSED,
];

// What a person still has to look at. Terminal history rows and plain cleanup
// retries deliberately do NOT move this axis: an old resolved case is not
// current attention, and treating it as one trains people to ignore the signal.
const VIRTUSPHERE_DEPLOY_ATTENTION_NONE = 'none';
const VIRTUSPHERE_DEPLOY_ATTENTION_RECOVERING = 'recovering';
const VIRTUSPHERE_DEPLOY_ATTENTION_MANUAL_REVIEW = 'manual_review';
const VIRTUSPHERE_DEPLOY_ATTENTION_STATES = [
    VIRTUSPHERE_DEPLOY_ATTENTION_NONE,
    VIRTUSPHERE_DEPLOY_ATTENTION_RECOVERING,
    VIRTUSPHERE_DEPLOY_ATTENTION_MANUAL_REVIEW,
];

// What an external check may conclude (Etappe 13R). A closed set, because the
// entry is evidence a later reader has to be able to compare with other
// entries; free text as the verdict would make the column unreadable at scale
// and unsearchable at any scale. The operator's own words go in `reason`, which
// is where they belong.
const VIRTUSPHERE_RECOVERY_RESOLUTION_CONFIRMED_APPLIED = 'confirmed_applied';
const VIRTUSPHERE_RECOVERY_RESOLUTION_CONFIRMED_NOT_APPLIED = 'confirmed_not_applied';
const VIRTUSPHERE_RECOVERY_RESOLUTION_INCONCLUSIVE = 'inconclusive';
const VIRTUSPHERE_RECOVERY_RESOLUTION_CODES = [
    VIRTUSPHERE_RECOVERY_RESOLUTION_CONFIRMED_APPLIED,
    VIRTUSPHERE_RECOVERY_RESOLUTION_CONFIRMED_NOT_APPLIED,
    // "I looked and still cannot tell" is a real and useful answer. Without it
    // people pick one of the other two, and the record then states a certainty
    // nobody had.
    VIRTUSPHERE_RECOVERY_RESOLUTION_INCONCLUSIVE,
];

// How long a queued job may sit past its due time while the service claims to
// be accepting before the service is degraded rather than ready. It is a grace,
// not a target: a claim takes a moment, and calling one second of scheduling
// latency a fault would make the signal useless.
const VIRTUSPHERE_DEPLOY_CLAIM_GRACE_SECONDS = 120;
const VIRTUSPHERE_REMOTE_OUTPUT_CHUNK_MAX_BYTES = 1048576;
const VIRTUSPHERE_REMOTE_OBSERVATION_MAX_BYTES = 1468000;

const VIRTUSPHERE_DEPLOY_WORKER_LEASE_NAME = 'deploy-worker';
