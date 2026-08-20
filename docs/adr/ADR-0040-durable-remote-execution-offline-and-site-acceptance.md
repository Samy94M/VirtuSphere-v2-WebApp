# ADR-0040: Durable Remote Execution separates offline implementation from site acceptance

Date: 2026-08-20
Status: Accepted

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
