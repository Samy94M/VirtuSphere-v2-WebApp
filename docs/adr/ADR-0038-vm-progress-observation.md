# ADR-0038: Long-running VM progress is observed, never inferred from edits

- Status: Accepted
- Date: 2026-08-09

## Context

A VM can remain at MECM pending or OS installing indefinitely without a failed
job. The generic `updated_at` column cannot measure either wait: portal edits,
callbacks and unrelated assignment changes all update it. MECM registration
also does not prove that an operator has triggered PXE, because PXE remains a
separate manual step.

Automatically failing or deleting such a VM would turn missing external
evidence into an irreversible lifecycle decision. A scheduled reaper would add
the same risk and make warnings depend on worker availability.

## Decision

`deploy_vms` carries two dedicated, server-written clocks:

- `mecm_pending_since` starts when a VM actually enters MECM pending. A warning
  appears only after more than two hours.
- `os_install_watch_started_at` starts only through the confirmed operator
  action “Observe PXE now”. A warning appears only after more than six hours.

The exact boundary is not overdue. Missing, malformed or future timestamps do
not create a warning, and no code falls back to `updated_at`. Repeated state
callbacks preserve the current applicable clock; a real state transition
clears clocks that are no longer applicable. An explicit restart changes only
the dedicated clock, records a status-history note and preserves `updated_at`,
lifecycle and MECM state.

Warnings are derived at read time and are display-only. There is no maintenance
task that fails, deletes or advances a VM because time elapsed. The dashboard
links to the affected missions, the mission list can filter and count them, and
the VM list/editor name the check and its next action. An unobserved OS install
is visible as such instead of looking fresh or overdue.

Migration `0038_vm_progress_watch` backfills existing MECM-pending rows with
the migration time. It deliberately does not derive an age from historic
`updated_at`; existing OS-installing rows remain unobserved until an operator
confirms PXE.

## Consequences

- Rollout creates no immediate historic overdue warnings.
- A portal or API edit cannot postpone an operational warning.
- A clock may be restarted after a documented check without falsifying VM edit
  recency or lifecycle history.
- No VMware, MECM or Windows runtime is required for the calculation, schema,
  UI and browser tests. Whether a real external system is genuinely stuck
  remains a hardware/staging observation and is not claimed by local tests.

## Verification

`VmProgressAttentionTest` covers both exact boundaries, explicit PXE start,
missing/future/malformed timestamps and the absence of an `updated_at`
fallback. `VmProgressWatchTest` covers migration-backed writes, repeatability,
history and state preservation. `VmProgressWatchContractTest` pins all writers,
both indexes, the non-destructive maintenance boundary and browser proof. The
Playwright VM CRUD flow proves both confirmation-dialog branches and that only
the dedicated clock changes.
