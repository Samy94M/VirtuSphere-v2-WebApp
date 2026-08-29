# ADR-0040: Durable Remote Execution separates offline implementation from site acceptance

Date: 2026-08-20
Status: Accepted; revised 2026-08-29 (the portal reads one service snapshot, see below)

## Context

Etappe 8R requires a real air-gapped Ansible host with a systemd user manager,
linger, cgroup ownership, offline artifacts and approved ESXi missions. The
repository team permanently has no direct access to that environment. Treating
local containers or mocks as the missing host evidence would invent the most
important safety proof. Stopping all implementation, however, would also leave
the protocol, fencing and fail-closed activation work undone.

## Decision

8R has two independently reported stages:

- **8R-O** implements and locally verifies the durable protocol, runner,
  launcher, offline bundle, additive schema, runtime identity, leases, fencing,
  disabled mode activations and recovery consumers. Every new activation starts
  `disabled`. Local fixtures can prove parsing, CAS, idempotency, bounds and
  failure handling, but never produce a site fingerprint.
- **8R-S** is the only stage allowed to prove the target host and activate a
  mode. An authorized operator runs the versioned offline bundle at the site.
  The bundle emits bounded, redacted evidence for versions, user bus, linger,
  cgroup, filesystem, resource enforcement, real faults, measurements,
  observation windows and rollback. It reads secrets only through the existing
  credential owner and never exports them.

An imported site result is accepted only when its protocol version, repository
revision, bundle SHA-256, credential/host identity, mode, required cases and
validity window match. Missing, stale, unknown or inconsistent evidence keeps
the mode `disabled`. `pilot_remote` and `remote_enabled` are unreachable without
that result. No exception, recovery path or operator action silently chooses
legacy execution.

An 8R-O package may be green while 8R-S remains open. Documentation and release
reports must always state both results. Create and Full remain blocked until
14B regardless of 8R-S.

## Consequences

- Repository development can continue without falsifying production readiness.
- Schema and code can ship inertly, but deploying or activating them at the
  unknown site remains a controlled 8R-S migration decision.
- Existing explicit `legacy_v1` operation is not called durable and retains its
  documented risks until an independent operating decision or successful 8R-S
  transition.
- Resource and retention values that require target measurements have no
  production value in 8R-O. `MemoryMax` and `TasksMax` remain absent until real
  enforcement is proven.
- If no authorized site run ever occurs, every remote mode stays disabled and
  8R-S remains permanently open rather than being rounded to success.

## Verification

8R-O must include positive, negative and zero-match tests proving that local
fixtures cannot mint a site acceptance, that an incomplete or mismatched result
cannot change an activation, and that no remote-enabled mode falls back to a
legacy path. 8R-S evidence and each mode activation are separately auditable.

## Revision 2026-08-29: One snapshot, three axes, one claim gate (Etappe 13R)

The offline foundation of 8R-O is now visible in the portal, and the shape it is
visible in is a decision rather than a layout. `deploy_service_health_snapshot()`
(`lib/deploy_service_health.php`) is the ONLY source for the dashboard, the
deploy page, the System status card and the anonymous health endpoint. Four
surfaces deriving "is the deploy service alright" from four combinations of
heartbeat, queue and job rows are four chances to disagree, and the health
endpoint had already disagreed: it read a stale-heartbeat query, which cannot
tell a deliberate pause from a fault.

The snapshot carries three independent axes, because folding them into one word
forces a choice between two facts and whichever one loses is the one somebody
needed. `availability` (ready, busy, degraded, cooldown, offline) answers
whether work is being executed; `claim_state` (accepting, pause_after_current,
paused) answers whether new work is being taken; `recovery_attention` (none,
recovering, manual_review) answers whether a person still has to look. Each has
a fixed precedence and a pure derivation over a fact struct, so its corners are
testable without a database. A compact badge is derived from all three for a
table cell, but every detail view shows all three: `busy + pause_after_current`
and `offline + manual_review` are both real at once.

Three things deliberately do not make the service degraded: a claim pause, a job
scheduled for later, and a purely historical failure. A signal that lights up in
normal operation is a signal people stop reading.

The claim axis is persisted on the singleton runtime row (migration 0045) and
every transition is a compare-and-swap, because an operator, a second browser
tab and the worker all write it. `pause_after_current` exists so a pause never
interrupts work that is already changing ESXi: the worker stops taking new jobs
immediately and converts the requested pause into a real one in the same
transaction as its terminal write. A resume issued while that job was still
running therefore wins. The gate sits inside the claim transaction, not in the
worker loop, so no future second caller can bypass it.

All five operator actions are database-only. None reaches the Ansible host,
because the situation they exist for is the one where it cannot be reached: a
recovery review requests what the policy allows and lets the worker perform it,
a cleanup retry re-enters the queue and is refused outright when the evidence
hash moved between looking and clicking, and an external check appends one
evidence row and never replaces an earlier one. The recovery POLICY is not
reimplemented for them; `remote_recovery_decision()` stays the one classifier,
so a case it calls manual cannot be talked out of that by a button.

The anonymous health endpoint stays as terse as before and still answers 200 for
`degraded`; only its INPUT changed. A snapshot that cannot be computed is
`degraded`, never a 503: the database already answered, and turning an internal
derivation fault into an address-probe failure would stop every client script in
the deploy VLAN over a portal detail none of them read.
