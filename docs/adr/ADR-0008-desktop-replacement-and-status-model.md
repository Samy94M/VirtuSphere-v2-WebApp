# ADR-0008: Desktop Replacement and Status Model

Date: 2026-06-28
Status: Accepted. The desktop replacement in the title is completed by ADR-0035; the status model below stays in force.

## Context

VM state is currently encoded in five legacy strings and the MECM `updated` flag.

## Decision

Introduce `lifecycle_state` and `mecm_sync_state` internally. Keep legacy strings as compatibility labels at integration boundaries.

## Consequences

Normal VM edits must not reset machine-owned state. Template clone resets runtime data explicitly.

## Amendment 1 (2026-11-25): the stored legacy string is the last evidence behind a worker mark

The deploy worker writes two lifecycle values that are marks, not reports: `deploying` when a job claims a VM and `failed` when a job that reached the VM fails or is cancelled. These writes overwrite the lifecycle (and with `failed` the MECM axis) and never touch the stored legacy string `vm_status`. Every writer that delivers evidence stores that string together with the lifecycle: creation, the MAC import, "reset MECM ID", the device sync and the client ACK.

Decision (VB4): behind one of the two marks the VM phase reads the stored legacy string as the last evidence (`lib/vm_phase_evidence.php`). Everywhere else the two axes decide, and the string stays a compatibility label. Nothing changes at the integration boundary: the machine API keeps computing its wire value from the two axes, and no value is stored differently.

Consequences. The reading holds only while the two kinds of writers stay apart: a writer that sets a lifecycle without the legacy string is a mark owner, and a writer of the legacy string stores a lifecycle with it. `tests/Static/VmPhaseEvidenceWriterContractTest.php` derives every `UPDATE deploy_vms` of the application and fails a writer that is neither. A new mark, or a writer that should set a lifecycle without evidence, needs a decision in `lib/vm_phase_evidence.php` first.

Rejected: the job journal's prestate (captured at the claim, so a report that arrives while the job runs is lost, and gone with the job row), the status history (written best effort and age-limited) and a new stored column (it would repeat what the legacy string already records).
