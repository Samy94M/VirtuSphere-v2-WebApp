# ADR-0033: Cancellation is a confirmed state machine

Status: accepted (2026-07-27). Decision 4 of the 2026-07 hardening campaign.

## Context

`repo_cancel_deploy_job()` set even a running job straight to `cancelled` and
nulled its lock and heartbeat. The worker honours a stop only at step
boundaries (by design, AP6: killing a create/powercycle mid-run leaves ESXi
state no later step can reason about). For the length of the current playbook
the portal therefore showed a terminal job whose sequence was still executing:

- the mission-delete and enqueue guards count only queued/running, so a
  mission could be deleted or a NEW job enqueued OVER a still-running sequence;
- db_importMAC.php accepts callbacks only for `running` jobs, so the sequence's
  own valid MAC upload bounced with 409 and the addresses were lost;
- the nulled heartbeat made the row invisible to the reaper, so a worker that
  died mid-sequence after a cancel was never converged.

## Decision

- **queued → cancelled** directly: nothing started, the wish is the end state.
- **running → cancelling → cancelled**: the cancel request sets
  `cancel_requested_at`/`cancel_requested_by` and the status `cancelling`;
  lock, heartbeat and every protective effect of an active job stay. The
  worker confirms at its next step boundary via an ownership CAS
  (`cancelling → cancelled`, only under its own lock). A dead worker is
  converged by the heartbeat reaper: a stale `cancelling` job becomes
  `cancelled`, never `failed` - the operator asked for exactly this outcome.
- **cancelled_at names the end state only.** The wish carries its own
  timestamp and actor; a `cancelling` job has `cancelled_at IS NULL`.
- **Active means queued, running or cancelling** (one SSoT constant). Deleting
  the mission, enqueueing a second job and enqueueing a second system pull for
  the same credential stay blocked until the cancellation is confirmed.
- **The MAC callback window follows the machine, not the wish.** db_importMAC
  accepts a scoped callback while the job is running OR cancelling (the
  sequence that produced the MACs is still the owner); only `cancelled` and the
  other terminal states answer 409, and that rejection leaves a job-log line
  and a throttled portal audit row. VMs whose import committed keep their
  deployed state through the confirmation (the cancel convergence only touches
  VMs still `deploying`).
- **Cancelling is not retryable and not cancellable again**; the cancel button
  renders only for queued/running (VIRTUSPHERE_DEPLOY_JOB_CANCELLABLE_STATUSES),
  a repeated cancel POST is idempotent.
- The legacy job_id-less callback path is untouched here; it falls with E3
  (Etappe 5b), not with this ADR.
- **The reaper may only judge silence it was awake for** (amendment
  2026-08-11). A stale heartbeat proves that nobody wrote, not that the worker
  died: while the database was unreachable nothing could write, so the moment it
  returns every running job looks abandoned. A process records when its current
  connection was established and waits out
  VIRTUSPHERE_DEPLOY_REAP_OBSERVER_GRACE_SECONDS before reaping; an observer
  that was connected throughout was never blind and reaps without any delay.
  The verdict names the cause it established, never one it assumed - the
  convergence note claimed "the worker died before confirming" on a path that
  is also reached with the worker alive. This is a bound on WHEN the reaper
  speaks, not a change to the state machine above: a stale `cancelling` job
  still converges to `cancelled`, never to `failed`.

## Consequences

- New ENUM value `cancelling` in both schema mirrors (position 3); migration
  0031 adds it plus cancel_requested_at/cancel_requested_by (plain INT,
  historical actor, deliberately no FK: the log line names the user anyway and
  a deleted account must not erase who asked).
- The heartbeat UPDATE covers running and cancelling, so a cancelling job's
  current step keeps proving liveness.
- deploy_job_retry_plan/deploy_job_is_retryable unchanged: cancelled keeps its
  plain re-queue semantics, cancelling is active and never offered.
- Help explains the two words: "Abbruch angefordert" (worker will stop at the
  next step boundary; the job still holds its locks) vs. "abgebrochen"
  (confirmed end state).
