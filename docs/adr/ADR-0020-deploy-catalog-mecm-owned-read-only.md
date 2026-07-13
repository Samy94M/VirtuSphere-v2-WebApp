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
