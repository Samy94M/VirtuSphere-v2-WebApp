# ADR-0041: Creating VMs is one worker-driven unit per VM, with a persisted job id

Date: 2026-09-02
Status: Accepted

## Context

A production job over fifteen VMs ended after 1800 seconds with
`Remote command produced no output (idle timeout)` while fourteen of the fifteen
VMs had appeared on ESXi. Three separate properties of the old path combined into
that outcome, and each of them was individually reasonable:

- Create was **one** `ansible-playbook` call that looped `vmware_guest` over the
  whole selection. Between two VMs there was no moment at which anything durable
  could be written, so after the abort nobody could say which VMs existed.
- Python buffers stdout blockwise once it is not a terminal, and the worker reads
  over an SSH pipe. The result lines of the loop therefore sat in the buffer
  until the process ended, which is what the idle detector saw as silence.
- The remote call was synchronous. Losing the channel lost the only handle to the
  work, so the sole remaining options were to do nothing or to run the whole
  create again, and running it again is how a second VM gets built.

The idle timeout was not wrong about the silence. It was wrong about what silence
meant, and nothing in the system could tell it otherwise.

## Decision

**One unit per VM, driven by the worker.** The queue materializes the selected
scope into one durable `deploy_create_vm_results` row per VM, in the same
transaction that creates the job, ordered by `vm_name, id`. Position `n` names
the same VM in the portal, in the job log and on a retry. An empty selection is
resolved at queue time; a VM created afterwards cannot widen a job that is
waiting for its scheduled start. Create is no longer a step of the remote
sequence at all: the worker drives four control playbooks per unit
(`createVMPrepare`, `createVMLaunch`, `createVMStatus`, `createVMCleanup`).

**The next VM starts only when the previous one has an established result.** That
answer is read from the rows, never from the process's memory, so a restarted
worker reaches the same conclusion. `uncertain` is a first-class state and not a
softer `failed`: it means the outcome on the host is unknown, and it stops the
job rather than letting the next VM overwrite the question.

**The async job id is the handle, and it is derivable.** `createVMLaunch` starts
exactly one `vmware_guest` call with `async` and `poll: 0`; the id lands in
`<remote job dir>/create.vm.<position>/async`, a path derived from the already
deterministic job work directory rather than stored a second time. After a lost
channel the worker polls that same id in that same source job. It never recreates
by name. A launch result file alone is not terminal; the job id plus the live
identity read back from the host is.

**The status decision hangs off the status file, not the module's verdict.**
`async_status` answers a vanished job id with `finished: true` and, where the call
suppresses its own failure, `failed: false`. A lost job would otherwise be
indistinguishable from a finished one.

**Output is unbuffered and the run is polled.** Every `ansible-playbook` this
product starts runs with `PYTHONUNBUFFERED=1`, and each unit is polled at a fixed
interval. A long-running disk therefore no longer looks like silence. The overall
create stretch keeps a wall-clock cap, derived from the SSH total budget rather
than copied, measured from `deploy_jobs.create_started_at`, which is set once with
`COALESCE` so a resumed job does not restart its own clock. Exceeding it produces
`uncertain`, never a blind retry.

**Retry is locked until the outcome is decided.** An unresolved unit blocks
"run again" (`retry_create_unresolved`). The release that lifts it demands a
successful inventory pull strictly newer than both the job and the unit, with
neither the snapshot name nor the VM's stored UUID present in it, and no other
active job of the mission. The operator's Recent-Tasks statement is required by
the action and recorded as their statement; it is never treated as something
VirtuSphere established, because this system cannot query a host's task list.

**No fallback path.** `createVMs-ESXi_playbook.yml` is deleted rather than kept
as a fallback. A second create path would be a second answer to "did this VM get
created", and the whole point of the change is that there is exactly one. A job
without materialized rows is refused with an instruction instead of being routed
around them.

**Cleanup never destroys evidence.** An `EXIT` trap is impossible with one command
per step, since it would fire after the first one; each step traps HUP/INT/TERM,
and the worker removes the work directory itself after a sequence ended. A step
that never returned is deliberately left in place and said so in the log.

## Consequences

### Amendment: partial Create summaries retain their retry plan (SC-002)

A worker conclusion stores a strict version-1 `kind=deploy_job` summary when no
richer result exists. Retry accepts its `partial` outcome as a stopped Create
section only when the terminal source job, original Create-capable mode, complete
explicit VM selection and ordered durable units agree. The units must include
both confirmed success and unfinished or failed work. Unknown protocols, missing
units or deleted selected VMs fail closed; unresolved units keep their existing
release requirement.

The retry evaluation owns one plan and the locked source rows. Queue insertion
uses that same plan: Create and Full retain the entire original selection,
confirmed units become `verify_skip` with their source reference, and failed or
not-started units become `create`. Full continues its remaining pipeline across
that entire selection after all Create units are confirmed. A valid partial MAC
result still plans Export for only its failed VM IDs. Both retry confirmations
describe the evaluation rather than decoding the result again.

`DeployCreateTerminalRetryTest` drives real claim, per-VM identity commit, worker
conclusion and retry materialization for Create and Full, plus the MAC conclusion
and blocked countercases. `DeployCreateRetryEvidenceTest` checks malformed summary
types, forged or incomplete unit evidence, scope ordering and DE/EN confirmation.

### Amendment: historical Create effects fence fresh admission (SC-007)

Queue, stagger, claim and the worker's pre-remote check share
`repo/deploy_create_fence.php`. A prepared, running or uncertain historical unit
blocks another job on its portal VM identity, even after a rename or changed
target. An exact snapshot name or known UUID also blocks an overlapping host
scope, including another credential for that host. VM names and UUID evidence
are compared exactly; DNS host spelling is case-insensitive, and a different
scheme or port alone does not establish another standalone host. Missing host
evidence is treated as unknown, so it cannot prove that scopes are independent.
DNS aliases and IP/name equivalence are not inferred.

Claim skips a blocked queued job without changing its status or attempt and
continues to independent jobs, including inventory pulls needed for recovery.
The existing reviewed release remains the owner of resolution; admitting a new
job never resolves a historical row. Credential target changes and deletion are
refused while active jobs or unresolved Create rows still reference that target;
access repairs remain possible. Previously changed credential targets cannot be
reconstructed from the old schema, which stored only their credential reference.

`DeployCreateHistoricalFenceTest` covers the three unresolved states, queue and
stagger rollback, exact/disjoint scope, credential aliases, claim continuation,
worker recheck, credential evidence protection and the reviewed release path.
Its two-connection cases hold an older parent job while a newer independent
claim completes and prove that a pinned transaction snapshot cannot hide a newly
committed historical effect or its credential association. The history read locks
only Create rows (`FOR UPDATE OF r`), never earlier parent jobs or joined
credentials. Claim locks Mission -> Job -> Runtime before those rows; release
locks Mission -> Job -> Unit. This preserves the forward lock direction of
service pause, reaping and credential edits.

- Cancelling during create now has a per-VM boundary: the VM in flight may still
  come into existence completely, and no further VM begins. That is a stronger
  promise than the per-step boundary of ADR-0033, and it is stated in the help in
  exactly those terms.
- A job that created fourteen of fifteen VMs is `partial`, not `failed`. It
  changed the target host, and calling that a failure is what sends an operator
  to delete work that is fine.
- Create-only binds identity itself (ADR-0036 amendment) and no longer needs a
  later export to learn it.
- The Ansible host's `/tmp` becomes load-bearing: a mount that is recreated per
  session destroys the only handle an interrupted job has to its own run.
- The fixed post-create wait (`CreateSettleSeconds`, 60 s) is gone. Waiting was a
  substitute for evidence, and the evidence now exists.
- ADR-0033 (cancellation) and ADR-0038 (progress is observed, never inferred) are
  related decisions and are not reinterpreted by this one: no percentage inside a
  single VM is displayed anywhere, because no component reports one.

## Verification

`CreateFlowBaselineContractTest` holds the six facts that changed, each written
first as the starting point and then rewritten by the sub-stage that changed it.
`CreateFlowWorkerContractTest` proves the state machine without a database and
without SSH; `DeployCreateWorkerFlowTest` proves it against real MySQL on a
deterministic fifteen-unit fixture, including the identity commit in all five
branches, a foreign fence writing nothing and an idempotent reaper.
`DeployCreateRetryMatrixTest`, `DeployJobRetryFlowTest` and
`DeployCreateReleaseTest` cover the retry lock and the release conditions. The
containerised `ansible-create-async` gate proves the async seam against a sleep
stub, and `ansible-output-buffering` keeps both the buffered control case and the
unbuffered production case measurable. `deploy-create-progress.spec.js` covers the
per-VM card in a browser.

Not covered here and deliberately open: transport loss, a cancel during a running
VM and a database outage are proven against the pure functions and the stored
rows, not against a real SSH transport, and the controlled standalone-ESXi staging
cases remain a site acceptance.
