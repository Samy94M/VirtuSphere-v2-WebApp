# ADR-0020: Deploy Catalog is MECM-owned; Portal Read-only except Admin Delete

Date: 2026-07-08
Status: Accepted

## Context

The deploy catalog tables `deploy_packages` and `deploy_os` are populated by the
MECM packages sync (`mecm_Packages-TaskSeq-sync.ps1` posting to
`mecm_packages.php`), which performs a retire-missing plus upsert from the MECM
catalog: Device Collections under `VirtuSphere_Applications` become packages and
Task Sequences become operating systems. A VM's `vm_os` selects which Task
Sequence runs at PXE boot, so an OS entry is only meaningful when its name
matches a Task Sequence name exactly.

`packages.php` was already read-only for this reason, but `os.php` still exposed
create, update and delete forms. Manual OS edits are redundant (the sync owns the
table) and actively drift: a hand-typed name that matches no Task Sequence is
retired on the next sync; a renamed row is retired and the original re-created,
leaving a duplicate; a manual status change is overwritten by the upsert.

## Decision

The MECM sync is the single writer for create and edit. Portal catalog pages must
not offer create or edit: `packages.php` is fully read-only, and `os.php` renders
read-only rows plus one admin-only delete action (gated by `catalog.write`).

Delete is kept on `os.php` as a safe, self-healing cleanup: if the Task Sequence
still exists in MECM the next sync re-creates the row, and a retired entry that no
VM references can be cleared immediately instead of waiting for the 30-day purge.
It is safe because the real PXE mapping uses the VM's stored `vm_os`, not this
catalog, and the purge already protects referenced rows. Create and edit are not
kept because they do not self-heal (a typed name is only retired, an edit is
overwritten on the next sync).

The `createOS`/`updateOS`/`deleteOS` repo functions (and their VLAN and package
siblings) stay, because the legacy token API (`access.php`) still calls them; that
surface remains legacy-only until the E3 retirement decision (ADR-0009, ADR-0019).

## Consequences

- OS and package definitions have exactly one place they are created and edited
  (MECM on the SCCM server), matching the ownership model stated in the portal help.
- Environments without the MECM sync cannot create OS entries from the portal.
  This is acceptable: the OS to Task Sequence to PXE chain is intrinsically MECM,
  and the Ansible path treats `vm_os` as a free-text label.
- Deleting an OS in the portal during a MECM outage leaves a temporary gap until
  the sync returns; existing VMs are unaffected because deployment uses their
  stored `vm_os` (`mecm_new-device-sync.ps1`), and a referenced OS is purge-protected.
- `PermissionParityTest` lists `os.php` under `catalog.write` again because the
  delete action makes it a write handler; `packages.php` stays fully read-only.

## Amendment 2026-07-26: the relink is bounded, and "purge-protected" means what it says

Two sentences of this decision were true separately and wrong together.

"Existing assignments are relinked to the successor on a version bump" was
implemented for **every** retired row, and the successor was chosen with
`ORDER BY id DESC`, i.e. by row id. So a package that had merely dropped out of
one payload, which is what a MECM hiccup or an admin mid-edit looks like, had its
assignments rewritten to whatever else shared its basename, possibly to an older
version.

"The purge already protects referenced rows" reads
`id NOT IN (SELECT package_id FROM deploy_vm_packages)`, i.e. *currently*
referenced. The relink had just removed that reference. The protection was
therefore lifted by the one mechanism that made the row worth protecting: the
rows with assignments were exactly the ones the purge could delete, and after
`VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS` a re-import created a fresh id with no
history. The deletion was justified as safe *because* linked rows are kept, and
that held for every row except the interesting ones.

Decided:

- **The relink only follows a real version bump.** The successor must be a row
  that THIS payload created for the first time, and its version must be strictly
  higher, compared with `version_compare()` and not by row id. Without a new row
  there is no upgrade, so a transient catalog outage changes no assignment at all;
  the row is still retired, the picker keeps it selectable for the VMs that hold
  it, and the VM editor shows the upgrade hint. The sync says so in the audit log
  rather than staying silent about a no-op.
- **A retired row whose assignments the relink moved away is never purged.**
  `deploy_packages.assignments_relinked_at` (migration 0027) records it, and the
  purge requires it to be NULL in addition to the existing reference check. The
  criterion is "was never assigned", not "is not assigned right now".
- A package re-import continues to touch no VM state whatsoever: no
  `deploy_vms`, no `mecm_sync_state`, no `updated`. That was true by accident and
  is pinned by `PackageSyncScopeContractTest` now, because it is the guarantee an
  operator relies on when a catalog sync runs every minute.
