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

## Amendment (2026-10-05): an existing own VM is aligned only while powered off (K11)

DF-E1, user decision of 04.10.2026: a new `create` or `full` job that meets an
existing own VM (bound instance UUID) aligns its hardware only while it is
powered off. A powered-on or suspended VM is only verified and reported as
unchanged; an unknown state counts as on, because `vmware_guest state:
present` would otherwise reconfigure a running machine or fail on firmware,
hardware version or a smaller disk.

The create marker contract changes together with its contract tests, on both
sides of the wire (`emit_create_result.py`, `ansible_create_protocol.php`):

- `prepared` gains `precheck_power_state`, read from the same
  `vmware_vm_info` answer as the identity. It is null when no VM existed, and
  may be null for an existing VM whose state the module did not report.
- `vm_not_powered_off` joins the closed error codes. The launch rechecks the
  live state after its identity and drift checks and before its one mutating
  call, because the VM can be switched on between prepare and launch; it then
  reports this code without a mutation. The worker never stores it as a
  failure.
- The state table gains `prepared -> succeeded`. `repo_deploy_create_commit_success()`
  takes it only for an existing VM with `changed = false`, so the one legal
  answer of a launch-less unit is `unchanged`, and the binding goes through the
  same commit as every other success. The schema is unchanged: `skipped` stays
  reserved for `verify_skip` retries.

Two neighbours of the same contract: a failed power-state read after a proven
async success no longer leaves the unit `uncertain`; the status playbook
reports `unknown` (DF-L9). And when the success commit refuses a binding, the
live MOID and instance UUID are kept in `error_detail` and in their own job-log
line (FC2-02), because the async state file is removed afterwards.

The live power-state read and the hardware mutation are separate ESXi calls:
another actor can power on the VM in the remaining interval. The recheck cannot
make them atomic without an ESXi-side lock; it does not guarantee protection
against a power-on after the read. A unit left unchanged because of its power
state requires a new Create job to align it later. Retrying the earlier job only
verifies successful units through `verify_skip`. The reason for not aligning is
recorded only in the job log and is no longer distinguishable after its retention.

## Amendment (2026-10-12): the ESXi access file never outlives its job (K21)

Finding VT-04 of the 2026-10-03 gap matrices showed that `accounts.yml`, which
carries the ESXi credentials to the Ansible host, could stay there after a job
whose create unit was unresolved or whose step never returned. The user decided on
05.10.2026 that the file must not outlive its job. After a job, the async state is
the evidence; the file is not. This amendment was revised on 13.10.2026, before
publication, after the Astra review of 12.10.2026 (conditions K21-A1 to K21-A6):
the target in the row, the state line of the removal, the order and lease of the
sweep, and the measured reasons below.

What changes in "Cleanup never destroys evidence" above: the evidence is the async
state under `create.vm.<position>/async`. `accounts.yml` is not evidence. It is
removed at the end of every job that uploaded it, including an unresolved create
unit and a step still in flight:

- The worker removes the file with `rm -f` whenever it cannot prove the host idle,
  and removes the whole job directory with `rm -rf` only when it can. The
  directory of an unresolved unit is never deleted. Both forms come from
  `ansible_remote_secret_remove_command()` and print a state line after a
  successful removal: `removed` when the file was there, `absent` when it was
  already gone. A removal is confirmed only by exit 0 together with that line,
  because `rm -f` also answers 0 for a path that does not exist.
- Before every upload that carries the file, a write-ahead row is written to
  `deploy_remote_secret_files`. It records the target the file goes to: host,
  effective SSH port and pinned host key of the Ansible credential, read at that
  moment (not `config_revision`, which also moves on a password change). The row
  has no foreign key to the job, so it survives a mission delete. It is deleted
  only after the host confirmed the removal. Existing installations get no
  backfill.
- A failed removal (unreachable host, killed worker, database gone while the row
  is being forgotten) leaves the row and records `last_attempt_at`. The next
  deploy or inventory job of the same Ansible credential removes the file at its
  next contact: after its host preflight and before its own upload, and only for
  rows whose target equals the credential's target now. Each closed row writes
  the audit event `deploy.remote_secret_cleared` with the outcome `removed` or
  `absent` and the target host and port. Rows never tried come first, then the
  longest untried; a removal the host did not confirm moves on to the next row,
  the first transport failure ends the sweep for this contact, and every row
  costs one heartbeat.
- A status query of a create unit outside the running job uploads only
  `accounts.yml` again, holds a lease (`lease_until`) on its row for twice its
  worst case (upload, status command up to `VIRTUSPHERE_CREATE_CONTROL_IDLE_TIMEOUT_SECONDS`,
  removal), removes the file in `finally` also when the query fails, and writes
  nothing to the unit or the job log. A sweep leaves a leased row alone. The
  query has no caller yet; a later operator path is a separate package.

Why removing the file under a step that never returned is safe (measured after
the Astra review of 12.10.2026, gate `ansible-vars-files-lifetime`):

- Ansible does NOT read `vars_files` once at play start. ansible-core 2.19
  loads each of them with `cache='vaulted'`, which caches only encrypted files,
  and resolves the variables for every task. When `accounts.yml` disappears,
  every value from it is undefined from the next task on, also in an `always`
  section. A playbook that kept running after the removal would therefore lose
  its ESXi credentials mid-play, including the `always` that powers a VM off
  again (`powercycle_vm_tasks.yml`).
- It cannot keep running, for two reasons together. A playbook whose reading
  SSH channel is gone ends at its next output with a broken pipe, before its
  next task starts, and the worker removes the file only after its own channel
  to that step is closed. And after ADR-0042 there is exactly one worker, so no
  second job holds a channel to a step of the same host while the sweep of
  another job removes. The sentence in ADR-0042 (Context) that a playbook "keeps
  running on the Ansible host whether or not this process is alive" holds for
  the async create unit, not for a sequence step: such a step lives only until
  its next output.
- A caller outside the worker (the status query) does not have the second
  reason. Its lease takes its place.

The limits are stated, not solved:

- A row is reached only through its own Ansible credential and only while that
  credential points at the recorded target. After the credential is pointed at
  another host or the host key changes, the row stays without an audit row and
  every contact names it in the worker's error log with its host and its oldest
  date; pointing the credential back reaches it again. A host that is reached
  only through another credential keeps the file until this credential is used
  again. Revision 2026-10-13 (ADR-0046): while such a row is open, the portal
  refuses the target change itself, and a moved row is named by the System status
  notice at once; the row is closed by a contact or by a logged acknowledgement.
- A deleted Ansible credential leaves a row that nobody can resolve. The foreign
  key is `ON DELETE SET NULL`, so the row survives and the file stays on the host.
  Revision 2026-10-13 (ADR-0046): the portal refuses the deletion while rows are
  open, so no new orphan arises from a deletion. Orphans that predate the fence
  are named at once by the notice and are closed only by a logged acknowledgement.
- Between the lease recheck and the removal a status query can still start; the
  remaining window closes with the host-side lock of the follow-up package. That
  window stays open until the same lock round (ADR-0046, open items).
- Both measured facts belong to the Ansible runtime. An update that caches
  `vars_files` again or catches the broken pipe turns the gate red; the
  reasoning above has to be re-read then, not the gate.
- Whether Ansible's async wrapper keeps module arguments elsewhere on the host is
  lab probe LP-06. It is not answered here.

Revision 2026-10-13 (K21-F, ADR-0046): the fence, the acknowledgement, the notice,
the sweep after a passed full test and the optional host timer are decided in
ADR-0046. The sweep after a passed full test calls the same owner
(`deploy_remote_secret_sweep()`). The host timer is the only removal outside it.

Verification: `K21RemoteSecretCleanupTest`, `AnsibleRemoteSecretCommandTest`,
`DeployRemoteSecretStateTest`, `RemoteSecretUploadContractTest`,
`DeployTerminalCleanupTest`, gate `ansible-vars-files-lifetime`.

## Amendment (2026-10-29): up to three more attempts for a module failure inside the job

Prod job 1527 (mission ATeP06, full chain, 15 VMs): `vmware_guest` for one VM
broke off with `503 Service Unavailable` from the ESXi host, the only failure of
the run. The next unit carried on, the job ended `partial_result`, and
power-cycle, export and start never ran. The only help was "run again" by hand.
User decisions of 29.10.2026: inside the job, a unit that ended `module_failed`
gets up to three more attempts (four in all), only for that code, each through
Prepare and its identity check, with pauses of at least 5, 15 and 45 seconds.

- **After the last unit, one round per attempt.**
  `deploy_worker_run_create_retry_pass()` runs when the unit loop ended without
  a stop and no unit is `pending`, `prepared`, `running` or `uncertain`. Each
  round re-reads the rows; every unit whose last attempt ended `module_failed`
  (`VIRTUSPHERE_CREATE_AUTO_RETRYABLE_ERROR_CODES`, now only that code) and is
  below `VIRTUSPHERE_CREATE_MAX_ATTEMPTS` (4) is re-opened and driven through
  the normal Prepare, Launch and Status calls, one unit after the other. A unit
  that succeeds or fails otherwise leaves the chain. `operator_released` is not
  in the set: a release happens after its job ended, and the operator's "run
  again" re-creates such a unit.
- **A pause counts from the attempt before.** Before attempt n+1 at least
  `VIRTUSPHERE_CREATE_RETRY_PAUSES_SECONDS[n-1]` (5, 15, 45) seconds have
  passed since attempt n ended (`finished_at`); a unit whose turn comes later
  anyway waits nothing more (`deploy_worker_create_retry_wait_seconds()`). The
  values live in that one constant; `DeployCreateConstantsContractTest` pins
  the floors, the ascending order and the room inside the create budget.
- **Prepare is the availability check.** It only reads. A host that still does
  not answer leaves the unit `failed` (`transport_lost`, or `protocol_error`,
  which stops the section as before) with nothing changed on ESXi, and that
  unit's chain ends. No second probe path exists. A Prepare or Launch without
  its marker now stores the cause the call printed: the protocol error ends in
  ` Cause: TASK [<task>] <failure head>: <reason>` from the same
  `DeployWorkerStepTrace` the mission steps use, normalised, redacted and
  bounded (`deploy_worker_create_control_failure_cause()`).
- **No second VM.** If an earlier attempt registered the VM before it failed,
  the identity matrix of the next Prepare meets a namesake that does not carry
  the stored UUID and answers `identity_conflict`; nothing is launched. The
  gate `ansible-create-async` holds both shapes (`retry-leftover-unbound`,
  `retry-leftover-replacement`), and `CreateInJobRetryTest` holds that the
  driver stops at that Prepare for attempts 2, 3 and 4.
- **Cancel, claim and budget come first.** The pass checks the claim and
  honours a cancel at every boundary through the same check as the unit loop,
  so "no further VM starts" holds for every later attempt. It starts no wait
  the create budget cannot hold (`Create retry stopped`).
- **Same row, own directory.** Migration 0066 adds `attempt` and
  `first_error_code`, `first_error_detail`, `first_async_jid`; a CHECK keeps a
  later attempt and the first attempt's evidence together. The first re-open
  fills them, later ones keep them (`COALESCE`); the attempts in between are in
  the job log. `deploy_worker_create_requeue_unit()` is the one writer of the
  in-job edge `failed -> pending`, which stays out of the closed CAS table. It
  runs under Mission -> Job -> Runtime -> create rows -> journal, with the
  claim and fence, for a job still `running`, and only for a candidate among
  all locked rows of the job. The step key, and with it the async directory,
  derives from the stored attempt: `create.vm.<p>` for the first attempt
  (unchanged), `create.vm.<p>.attempt<n>` for attempts 2 to 4. A shared
  directory could still hold an earlier job id after a failed best-effort
  cleanup, and a job-id search after a lost launch would then report the old,
  finished job while the new one was running.
- **The launch intent belongs to an attempt.** The re-open clears
  `create_launch_intent` in the same transaction. Its async job has a proven
  terminal failure, and without the reset the next launch would only search
  for that job and never start (A49, K15). The first job id stays as
  `first_async_jid`, and the K15 convergence treats a VM whose later attempt
  did not succeed as touched (`fail`), also when that attempt never launched.

The job log carries the evidence: the `FAIL module_failed` line and the module's
Ansible output of each attempt, then `[n/total] RETRY create <vm> module_failed;
attempt <n> of 4[ in <s> s]` before the next one and `[n/total] RUN create <vm>
attempt <n> of 4` at its start. The per-VM card shows the outcome of the last
attempt; the help (`create_progress_p4`, `create_progress_p5`) names the
automatic attempts and when "Run again" is still needed.

Verification: `CreateInJobRetryTest`, `DeployCreateInJobRetryTest`,
`CreateFlowWorkerContractTest`, `DeployCreateConstantsContractTest`,
`CreateInJobRetryContractTest`, gate `ansible-create-async`.
