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

    $reaped = repo_reap_stale_deploy_jobs($db, VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS, $cause);
    // The cleanup has committed. Only now its inventory requests are enqueued,
    // each in a transaction of its own (TS1-K4, G1-4).
    foreach ($reaped as $job) {
        deploy_worker_request_inventory_after_reap($db, $job);
    }

    return count($reaped);
}

/**
 * The inventory pull a concluded create/full job owes its ESXi credential, for a
 * job the stale-job cleanup ended (TS1-K4, G1-4). The cleanup decided it inside
 * its transaction (`inventory_refresh`, `inventory_paused`, from
 * deploy_worker_inventory_refresh_due()); this runs after the commit.
 *
 * Fail-soft like the live worker's refresh: an enqueue or audit failure goes to
 * the container log and never undoes the cleanup, which has already committed;
 * the queued job and its `deploy.inventory_requested` row commit together or
 * not at all (R19). The request goes through the
 * one enqueue owner, esxi_inventory_enqueue_for_credential(), so an already
 * waiting pull is not duplicated (`already_pending`) and the automatic interval,
 * even at 0, plays no part. A paused credential is not pulled; the job log
 * already says so.
 *
 * @param array<string,mixed> $job one row returned by repo_reap_stale_deploy_jobs()
 */
function deploy_worker_request_inventory_after_reap(mysqli $db, array $job): void
{
    if (!($job['inventory_refresh'] ?? false)) {
        return;
    }
    $jobId = (int) ($job['id'] ?? 0);
    $credentialId = (int) ($job['credential_esxi_id'] ?? 0);
    if ($job['inventory_paused'] ?? false) {
        fwrite(STDOUT, '[deploy-reap] job ' . $jobId . ': no inventory pull for ESXi credential ' . $credentialId . ", it is paused after a sign-in failure\n");

        return;
    }
    try {
        // The queued job and its audit row commit together (R19). An enqueue
        // that failed half-way reports `error` from inside its own catch; the
        // throw below rolls that rest back instead of committing it.
        $context = repo_transaction($db, static function () use ($db, $credentialId): array {
            $enqueue = esxi_inventory_enqueue_for_credential($db, $credentialId);
            $reason = (string) ($enqueue['reason'] ?? VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_QUEUED);
            if ($reason === VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_ERROR) {
                throw new RuntimeException((string) ($enqueue['message'] ?? 'the inventory job could not be queued'));
            }
            $context = ['reason' => $reason];
            if (isset($enqueue['job_id'])) {
                $context['job_id'] = (int) $enqueue['job_id'];
            }
            $requested = $enqueue['enqueued'] || $reason === VIRTUSPHERE_AUDIT_INVENTORY_REQUEST_ALREADY_PENDING;
            audit_event_required(
                $db,
                VIRTUSPHERE_AUDIT_EVENT_DEPLOY_INVENTORY_REQUESTED,
                'credential',
                $credentialId,
                $requested ? VIRTUSPHERE_AUDIT_RESULT_SUCCESS : VIRTUSPHERE_AUDIT_RESULT_WARNING,
                $context,
                null,
                'cli'
            );

            return $context + ['requested' => $requested];
        });
        fwrite($context['requested'] ? STDOUT : STDERR, '[deploy-reap] job ' . $jobId . ': inventory pull for ESXi credential ' . $credentialId
            . ' after the stale-job cleanup: ' . $context['reason'] . (isset($context['job_id']) ? ' (job ' . $context['job_id'] . ')' : '') . "\n");
    } catch (Throwable $exception) {
        fwrite(STDERR, '[deploy-reap] job ' . $jobId . ': inventory pull after the stale-job cleanup failed: ' . virtusphere_redact_log_text($exception->getMessage()) . "\n");
    }
}
