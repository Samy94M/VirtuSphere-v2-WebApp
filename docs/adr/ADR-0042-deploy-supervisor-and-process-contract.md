# ADR-0042: The deploy service has two process shapes, and switching between them is a decision

Date: 2026-09-02
Status: Accepted; the supervised shape is implemented and not activated

## Context

The deploy worker is PID 1 of its container. When it stops answering, nothing
inside the container notices: the compose healthcheck reports the container
unhealthy, and `restart: unless-stopped` does not act on unhealthy, only on
exit. A hung worker therefore sits there, healthy-looking to Docker and silent
to everyone else, while its queue stops moving.

The obvious fix - have something restart it - is the dangerous one. A deploy job
changes ESXi through a playbook that keeps running on the Ansible host whether
or not this process is alive. A second worker started while the first is still
in there would run the same job a second time and, since Etappe 14B, create the
same VM a second time. That is precisely the failure the create repair exists to
make impossible, and a naive supervisor would reintroduce it one layer up.

## Decision

**Two named process shapes, and the stored contract says which one is running.**
`deploy_runtime_identity.supervisor_contract` is `worker_v1` (the worker is the
container's own main process) or `supervisor_v1` (a supervisor holds exactly one
worker as its child). The service snapshot reports the health of the shape the
contract names, and when the observed shape contradicts it, the state is
`degraded` - fail closed, never guessed.

**A second child is impossible for two independent reasons.** The policy returns
`start` only after waitpid has confirmed the previous child is gone, and the
process seam refuses `start()` while a handle is open. Two reasons, so neither
has to be perfect on its own. A child that survives TERM and then KILL ends in
`manual`: a process that ignores SIGKILL is a kernel-level state, and a
replacement for it would be a second executor of work that is still running.

**The restart decision reads the child's liveness FILE and never the database.**
This is the most consequential line here. A worker sitting out a database outage
is healthy by explicit decision (Etappe 2, which is the entire reason the worker
has a database channel). A supervisor that consulted the database would answer a
database outage by killing the one process that is correctly surviving it, in
the middle of a playbook that is changing ESXi. The supervisor publishes its
state to the database, best effort, because the portal runs in another container
and cannot read that file at all - but it never decides from it.

**One confirmed failure is several observations, not one.** A single look at a
file that is being written at that moment is not a finding, and the price of
being wrong is a healthy worker killed inside a playbook. After the confirmed
failure the sequence is fixed: TERM, wait out the grace, KILL, wait, give up.

**Escalating restarts stop.** More restarts than the window allows is a pattern,
not an accident; the supervisor then waits until a stored `next_retry_at` rather
than looping. The deadline is stored, so a supervisor that itself dies during
the wait does not come back and retry immediately.

**The switch is a maintenance window, never automatic.** A CLI
(`lib/deploy_supervisor_switch.php`) performs a compare-and-swap and writes one
audit row per attempt - the refusal included, because a refusal names the
condition that is the operator's next task. Its preconditions: every activation
row of every active Ansible credential is `remote_enabled` or `disabled`; no
active, unresolved or foreign-generation job holds `legacy_v1`, which since
Etappe 14B includes a job with an `uncertain` create unit; claims are paused;
the queue is drained. Rolling back additionally requires that no supervisor is
still reporting.

**The container healthcheck judges supervisor liveness, not queue success.** A
deliberately paused but responsive service is `healthy`. Docker `unhealthy` is
nowhere in this product a restart claim; it is a report.

**Compose does not change by default.** `docker-compose.yml` still starts the
worker; `docker-compose.supervisor.yml` moves the command and the healthcheck
together, and is applied as part of the window. Half of it would be worse than
none: a supervisor judged by the worker's liveness file reports unhealthy during
every legitimate restart cooldown, because during a cooldown the child is
correctly gone.

## Consequences

- `pcntl` joins the PHP image. It is bundled with PHP, so nothing is downloaded
  and the air-gap bundle is unaffected. It is there for one reason: PID 1
  without an installed signal handler IGNORES SIGTERM, so `docker stop` would
  sit out its whole grace period and then SIGKILL both processes mid-playbook.
  Waiting for a child needs no pcntl (`proc_get_status()` reaps), and sending a
  signal needs only `posix_kill` with a number, which is why the process seam
  works in an image without the extension.
- The availability axis gains a `child_alive` fact and one rule. The order is
  load-bearing: `cooldown` beats "the child is missing", because a supervisor
  inside its restart window legitimately holds no child, and the other way round
  every planned restart would be reported as a breakage.
- The System status card names the process contract instead of printing
  `worker_v1` at a person, and shows the supervisor and the child as two facts,
  because `cooldown` with a healthy supervisor and `degraded` with a hung child
  look the same in one word and are two different next steps.
- The supervisor is not allowed to touch a job. Not tidiness: a supervisor that
  could claim, finish or reap one would be the second executor this decision
  exists to prevent.
- Migration 0049 puts the published state on `deploy_runtime_identity` rather
  than in `deploy_integration_heartbeats`. A source in that table would be
  permanently rowless under `worker_v1`, hence permanently red for a service
  that is not supposed to be running, and a warning for a planned absence is a
  warning people learn to ignore.

## Verification

`DeploySupervisorPolicyTest` drives the machine as a pure function, including
the negative assertion the whole etappe rests on: no state in which the child
may still be alive ever answers `start`. `DeploySupervisorFaultRunTest` runs a
REAL child that beats, stops beating and ignores SIGTERM, and proves through
waitpid that it is escalated rather than replaced and that exactly one child
exists at every point of the run. `DeploySupervisorContractTest` pins the
default compose shape, the CLI guards, the healthcheck's independence from the
database, the absence of any job call, the single start call site and the
closed blocker vocabulary in both directions.
`DeployServiceHealthTest` covers the supervisor branch of the availability
precedence, including the cooldown-before-missing-child order.

Not covered here and deliberately open: that a healed worker creates no second
VM on ESXi. This build proves the whole chain in front of that - no second
child, no second job, no second create unit, no second async job id - and the
VM itself is the site acceptance (create plan, section 15.6, case 11). The
measured cooldown and window values come from a local fault run of consecutive
hangs, which is what the combined plan's measurement rule asks for; they are
local process bounds, not site-dependent ones.
