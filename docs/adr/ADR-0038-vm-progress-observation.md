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

## Amendment 1 (2026-10-05): MECM pending is watched whatever the lifecycle

ADR-0019 amendment 4 (VT-E1 C) lets the client ACK reach 5/5 while the VM is
still MECM `pending`. The pending observation therefore no longer requires
`deployed`: every VM at MECM `pending` with an overdue `mecm_pending_since`
warns, including `os_installed` / `pending` and a `deploying` VM that was
already waiting for MECM. The ACK preserves the clock. The OS-install
observation is unchanged. `virtusphere_vm_progress_watch_kind()` decides, and
`repo_vm_progress_attention_sql()` is its one SQL form for both counts.

## Amendment 2 (2026-11-16): the OS-install clock starts on power-on evidence

The operator's click stays, but it is no longer the only start. A VM is started
partly from the portal (full pipeline, "Start VMs") and partly by hand in the
vSphere client, and nobody opens the editor of every registered VM to say so.
`os_install_watch_started_at` therefore starts by itself as soon as a VM is
`os_installing` / `registered` and proof exists that it is switched on. No new
signal from MECM or the task sequence is used and no wire contract changes
(user decision of 2026-11-16).

What the clock means changes with it: "registered and switched on since", not
"PXE confirmed". The finding for a registered VM without a clock is named
"power-on evidence missing"; its token `pxe_unwatched` is unchanged.

Two proofs count, and `virtusphere_vm_power_evidence()` (`lib/vm_progress.php`,
pure) is their only definition:

- Portal start: the job of the VM's newest journal row ran its start step to
  the end. That is a succeeded job whose sequence contains the start playbook
  (`ansible_playbooks_for_mode()`), or a job that failed only in the autostart
  step after it. A failed start step is proof for no VM of the job, `partial`,
  `cancelled` and `outcome_unknown` are not proof, and log lines never count.
- Inventory: a fresh, answered `vm` inventory of the bound access shows the VM
  powered on (`virtusphere_vm_inventory_observation()`).

The newer reading decides. A mission job moves the power of its VMs: a power
cycle switches them on and off again. An inventory reading therefore says
something only if it was taken after the job of the VM's newest journal row
ended, and nothing while that job is still active
(`virtusphere_vm_reading_follows_job()`). Such a reading confirms a portal start
or, if it shows the VM powered off or absent from the host, takes it back. A
stale, unanswered or unbound inventory says nothing either way.

Evidence must belong to the VM's current rollout. A start from the round before
a MECM-ID reset proves nothing about the round after it. No column stores the
start of a rollout, and none was added:

- Only a MECM-ID reset starts a new rollout without a deploy job, and it always
  raises `mecm_rollout_revision`. A VM still at the initial revision was never
  reset; everything known about it belongs to its only rollout. A stored NULL
  is the initial revision, as `mecm_rollout_fence_revision_current()` reads it.
- For any other revision the evidence must not be older than
  `mecm_pending_since`. The reset writes that clock and no later write moves it
  backwards. The registration clears it, so the registration callback reads it
  under the row lock before its state write.
- After the registration the start of the rollout is not stored. Past evidence
  of such a VM is then refused, and only evidence that arises in the trigger
  itself counts: the job that is ending (a reset is refused while a job of the
  mission is active) and the inventory that is being applied.
- The time of a job is the immutable claim time of its journal row
  (`deploy_job_vm_states.captured_at`).

The fence is deliberately one-sided: it may refuse true evidence, never accept
false evidence. A hostname identity change also raises the revision and is
fenced like a reset. An operator who restarts the pending observation moves
`mecm_pending_since` forward, which makes the fence stricter. A refused VM keeps
the finding "power-on evidence missing" until the next inventory pull or the
operator's click.

One writer, `repo_start_vm_install_watch_on_evidence()`
(`lib/repo/vms_progress_watch.php`), sets an empty clock only. It never restarts
a clock, leaves `updated_at`, lifecycle and MECM state untouched and records a
status note that names the source; that note is the only stored record of the
source. Three triggers ask the same rule:

1. the registration callback (`mecm_updateid.php`), inside its transaction
   (`repo_start_vm_install_watch_at_registration()`);
2. the end of a job with a start step (`deploy_worker_after_start_step()`);
3. an applied inventory whose `vm` kind answered
   (`deploy_worker_after_vm_inventory()`). The pull carries its claim time: the
   host was read no earlier, so a job that ended after that claim still
   outdates the reading.

All three are fail-soft: a clock that could not be set never costs a
registration its binding, a job its terminal state or a pull its cache. The two
worker triggers run after their own result is committed. The registration
trigger runs inside the callback's transaction and lets exactly one failure
through: a deadlock or lock wait timeout, after which MySQL has discarded the
transaction and nothing may be committed. `start` and `autostart` still own no lifecycle or MECM
state (`deploy_worker_mode_preserves_vm_state()`).

A job with a start step also requests an inventory pull for its access, whether
the step succeeded or failed. After a failed start step only the inventory can
say which VMs are on. An access that is paused after an authentication failure
is not asked (ADR-0023).

Known limit: a stored inventory reading counts for as long as the inventory
counts as fresh (the evidence window of the configured interval). A VM that was
switched off by hand after that reading, with no job in between, still gets its
clock at the registration. The next pull then shows it powered off, and the
finding `powered_off` says so; the clock is not taken back.

The operator action stays as "Restart observation" and, without evidence, as
"Start observation manually". The editor names the source of a running clock
from the newest observation note written with it; when the history no longer
holds that note it names none.

Verification: `VmPowerEvidenceTest` pins the rule with its edge cases, first of
all the start from before a reset. `VmProgressWatchTest` and
`DeployWorkerInstallWatchTest` cover the writer and the three triggers
(idempotent, never a restart, `updated_at` and state untouched, evidence from
before a reset refused, through the registration callback as well).
`VmProgressWatchContractTest` derives the single clock writer from all sources
and pins the trigger call sites; `StatusWriterContractTest` pins that the clock
modules write no stage. The Playwright VM CRUD flow shows the source in the
editor after an automatic start and keeps both branches of the button.
