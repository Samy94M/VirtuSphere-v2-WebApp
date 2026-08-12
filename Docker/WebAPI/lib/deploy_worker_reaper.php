<?php

declare(strict_types=1);

require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/integration_health.php';
require_once __DIR__ . '/repo/deploy_jobs.php';
require_once __DIR__ . '/deploy_worker_runtime.php';
require_once __DIR__ . '/deploy_worker_vm_state.php';

/**
 * The stale-job reaper and the observer window that decides whether this
 * process is allowed to judge silence at all.
 *
 * A failure detector must not count silence it was not awake to observe: while
 * the database was unreachable nothing could write a heartbeat, so the moment
 * it returns every running job looks abandoned. The grace gate therefore sits
 * inside deploy_worker_reap_stale_jobs(), where no caller can forget it, and an
 * unset observer counts as blind.
 */
/**
 * When the CURRENT database connection of this process was established, i.e.
 * the earliest moment this process could have observed anything. Both workers
 * set it right after connecting AND after every reconnect, because a reconnect
 * means the gap before it was unobserved.
 *
 * A process-local static rather than a parameter: the reap gate has to sit
 * inside deploy_worker_reap_stale_jobs() where no caller can forget it, and a
 * DB row would answer the wrong question (it says when somebody last wrote, not
 * whether THIS process was awake). Never reads as "long ago" by accident - the
 * unset value is null, which counts as blind.
 */
function deploy_reap_observer_since(?int $set = null): ?int
{
    static $since = null;

    if ($set !== null) {
        $since = $set;
    }

    return $since;
}

/**
 * Whether this observer has been connected too briefly to trust what it sees.
 * Pure, so the rule is testable without a worker or a clock.
 */
function deploy_reap_observer_is_blind(?int $observingSince, ?int $now = null): bool
{
    if ($observingSince === null) {
        return true;
    }

    return (($now ?? time()) - $observingSince) < VIRTUSPHERE_DEPLOY_REAP_OBSERVER_GRACE_SECONDS;
}

/**
 * @return int Number of stale running jobs reaped. Called from the deploy
 *         worker's loop start and, since AP6, from the maintenance worker's
 *         interval as well - both go through this function so the MAC-aware
 *         VM convergence below can never be bypassed by one of the callers.
 *         The observer grace sits here for the same reason.
 *
 * One consequence worth stating, because it is a tool contract and not an
 * accident: `deploy_worker.php --once` connects and reaps immediately, so it is
 * always inside its own grace and never reaps anything. That is wanted. A
 * process that has observed nothing cannot tell a dead worker from its own
 * blind spot, and a one-shot debugging run has no business concluding somebody
 * else's job. Forcing a reap would need its own named operator switch;
 * DeployReapObserverGraceTest pins both halves of this.
 */
function deploy_worker_reap_stale_jobs(mysqli $db): int
{
    // Just (re)connected: the silence in front of us is our own, not the
    // workers'. Skipping costs one interval; reaping here fails healthy jobs.
    $observingSince = deploy_reap_observer_since();
    if (deploy_reap_observer_is_blind($observingSince)) {
        // Once per connection, not once per pass: the deploy worker reaps at
        // every loop start (--sleep=5), so a line per call is two dozen
        // identical lines per grace window, and a message that is always there
        // is one nobody reads when it finally means something.
        static $announcedFor = null;
        if ($announcedFor !== $observingSince) {
            $announcedFor = $observingSince;
            fwrite(STDOUT, '[deploy-reap] holding off for up to ' . VIRTUSPHERE_DEPLOY_REAP_OBSERVER_GRACE_SECONDS
                . " s: this process has just (re)connected and cannot tell a dead worker from its own blind spot\n");
        }

        return 0;
    }

    // Recorded while the row is still being written, because afterwards nothing
    // can reconstruct it - but recorded as a SEPARATE observation, not as the
    // cause. The singleton status row says whether a deploy service is
    // reporting now; it does not identify the process that held this job. A
    // restart writes a fresh row, so "reporting" does not mean this job's owner
    // survived, and "not reporting" does not mean it died. The two sentences
    // that used to stand here claimed exactly that, and each implied an
    // instruction ("restart the service" / "do not restart it") that was as
    // likely wrong as right.
    $cause = integration_deploy_worker_alive_now($db)
        ? 'Separate observation at this moment: a deploy service is reporting its status row. That is a statement about now, not about the process that held this job, which may have been restarted since.'
        : 'Separate observation at this moment: no deploy service is reporting its status row.';

    $reaped = 0;
    foreach (repo_reap_stale_deploy_jobs($db, VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS, $cause) as $job) {
        $payload = deploy_worker_payload($job);
        $vmIds = $payload['vm_ids'] ?? [];
        $jobId = (int) $job['id'];
        // A reaped job may already carry a committed MAC import: those VMs are
        // really deployed and keep their state; only the rest converges to
        // failed/failed. The note names what actually happened: a converged
        // cancellation is the operator's wish, not a stale-heartbeat failure.
        // "the worker died before confirming" was asserted, never established:
        // the same path is reached when the worker is alive and only its
        // heartbeat could not be written, so the status event stated a cause
        // that had not been checked. It now says what was observed.
        $result = deploy_worker_job_mac_result($db, $jobId);
        $note = ($job['reaped_to'] ?? '') === VIRTUSPHERE_DEPLOY_STATUS_CANCELLED
            ? 'deploy job ' . $jobId . ' cancelled; converged by the reaper after a stale heartbeat'
            : 'deploy job ' . $jobId . ' reaped after stale heartbeat';
        deploy_worker_mark_vms_failed(
            $db,
            (int) $job['mission_id'],
            $note,
            $vmIds,
            $result !== null ? $result['successful_vm_ids'] : []
        );
        $reaped++;
    }

    return $reaped;
}
