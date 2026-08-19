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
- **A step boundary is one playbook** (Etappe 8 of the deploy-reliability
  masterplan). Until then a mode's playbooks ran as a single remote `a && b`
  chain, so the only boundaries were before the first and after the last one:
  this ADR and the portal promised a stop the worker could not perform for a
  five-playbook pipeline. Each playbook is now its own remote command with an
  ownership/cancel decision between them. The promise is therefore exactly:
  *the step that is currently running can carry out its changes on ESXi
  completely, and no further step is started afterwards* - nothing is killed
  mid-playbook, because a hard stop inside a VM creation leaves a state no
  later step can reason about.
- **The last-step race is decided by competing swaps, never by a status read
  before it.** Success, partial and failure may only finalise from `running`
  under this worker's lock. If the cancel committed first, the terminal swap
  finds zero rows, reloads the row and confirms `cancelled` itself, with a log
  line saying that the step that was running did complete its work; if the
  terminal swap won first, a later cancel POST cannot change the finished job.
  So there is neither "succeeded despite an accepted cancellation" nor a
  `cancelled` job whose next playbook is still running.
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
- **The reap message states observation, never cause** (amendment 2026-08-12).
  `deploy_job_reap_observation()` is the one wording, pure so it can be pinned
  without a database, and it carries exactly what the transaction can see: job
  id, heartbeat age against the limit that made it stale, `locked_by`, and the
  transition. A caller with a further OBSERVATION may append it, labelled as
  separate - the deploy worker appends whether a deploy service is reporting its
  status row right now. That row identifies no process: a restart writes a fresh
  one, so "reporting" does not establish that this job's owner survived and
  "not reporting" does not establish that it died.
- **`--once` never reaps** (amendment 2026-08-12). It connects and reaps
  immediately, so it is always inside its own observer grace. This is a tool
  contract, not a side effect: a one-shot run has observed nothing and has no
  business concluding somebody else's job. A forced reap would need its own
  named operator switch.
- **A database outage during a run is not a job failure** (amendment
  2026-08-12). The playbook executes on the Ansible host; the job log and the
  heartbeat are a side channel, and a side channel must not end the run whose
  exit code is the only remaining evidence about the VMs it created. Every write
  of a running job goes through `DeployWorkerDbChannel`, which owns the live
  connection (callbacks ask for the handle, they never capture one), announces
  an outage exactly once on STDERR redacted, spools finished redacted log lines
  in a bounded FIFO, and attempts at most one backed-off reconnect per tick so
  the SSH stream keeps being read. After a reconnect the order is ownership,
  heartbeat, spool - draining first would write into a job that was reaped and
  re-claimed meanwhile. A job whose ownership is gone stops WITHOUT publishing a
  result, because overwriting an established terminal state with a guess is
  worse than the gap. When the remote command ends during the outage the loop
  worker waits bounded for the database and finalizes exactly once; `--once`
  stays bounded and reports the non-persistable outcome explicitly. This closes
  the reaper's blind spot at its source: fewer heartbeat gaps to judge, and the
  ones that remain are judged only by an observer that was awake.

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
