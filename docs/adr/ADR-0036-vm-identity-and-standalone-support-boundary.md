# ADR-0036: VM identity and the standalone-ESXi support boundary

- Status: Accepted
- Date: 2026-08-08

## Context

Every deploy playbook addressed a VM by `name` and folder `/`. In particular,
`vmware_guest state: present` can treat an existing namesake as its target and
align hardware settings. The MAC callback also resolved its portal row by name.
A VM created elsewhere could therefore be changed, or could donate MAC and
lifecycle state to an unrelated portal record, without any durable identity
being compared.

VirtuSphere also displayed partial vCenter inventory, while production deploy
semantics assume one directly connected standalone ESXi host: one implicit
`ha-datacenter`, the root VM folder, host-local capacity and host autostart.

## Decision

The vSphere `instance_uuid` is the durable VM identity. `vm_moid` is stored as
the current managed-object handle but is not an identity: unregistering and
registering the same VM may change it. Both columns are nullable and receive no
guessed backfill. Inventory mirrors name, UUID and MOID; export learns them from
the live module response.

A cached namesake blocks queueing unless its instance UUID matches the portal
row. The explicit **Adopt identity** action is the only way to bind a pre-existing
namesake. It requires VM-write permission, the shared confirmation dialog, the
mission lock and an idle mission. It copies only UUID and MOID; it changes no
hardware, power state, interface or job.

The host is checked again at execution time. Create lists the live VMs and
allows either an absent name or exactly one name with the stored UUID before
`state: present`. Power-cycle, start and autostart compare the name and UUID from
`vmware_guest_info` before their first mutation. Export retains partial results:
a mismatching successful response is converted into a failed per-VM result
before the callback. The callback independently rejects a stored UUID mismatch
before writing identity, MACs or state.

The full pipeline is the sole exception for an empty stored UUID after create.
Its first step has just proved the name absent and created the VM in the same
sequential remote command; later steps may use that unbound object, and export
then persists its identity. Standalone power, export, start and autostart modes
have no preceding proof and require a stored UUID.

Production deployment support is direct standalone ESXi only. A vCenter
credential remains useful for the documented partial read-only inventory, but
is not a supported deploy target. The support-matrix guard pins that row,
including the first-datacenter and root-folder limits.

## Consequences

- Existing portal VMs remain unbound until a successful full export or explicit
  adoption. This is visible and fail-closed for standalone follow-up modes.
- A changed MOID with the same instance UUID is refreshed instead of treated as
  a collision.
- A foreign namesake can no longer be silently aligned by the normal create/full
  path, nor can its export overwrite portal lifecycle or MECM state.
- Adoption is an ownership decision, not a hardware reconciliation feature.
  Any later hardware-alignment action requires its own confirmation and design.
- vCenter inventory may inform an operator, but it does not expand the product's
  deploy topology.

## Verification

`VmIdentityCollisionTest`, `AnsibleVmIdentityContractTest`, the MAC callback wire
tests and the adoption Playwright scenario cover repository, live-playbook,
callback and browser boundaries. `check-doc-semantics.sh` plus its positive,
negative and zero-match harness cases prevents the vCenter matrix from drifting.

