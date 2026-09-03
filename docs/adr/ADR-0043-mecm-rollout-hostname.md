# ADR-0043: MECM receives a frozen rollout hostname, not the ESXi VM name

Date: 2026-09-03
Status: Accepted; implemented, release blocked until the MECM lab proof and the
coordinated script/client cutover

## Context

MECM imported `vm_name`. That is the ESXi/portal VM name, typically something
like `VM-12345`: an inventory handle, not a computer name. The Windows machine
that came out of the task sequence therefore answered to the ESXi name, while
`vm_hostname` - the field the portal calls "Windows hostname", the one an
operator fills in with `Backup-12345` - reached MECM never. The client's rename
phase fixed it afterwards on the machine itself, which left MECM holding one
name and Windows another for the same device.

Two further problems sat underneath that one, and neither is cosmetic:

`vm_hostname` was live-editable with no record of what had been handed over. A
correction typed after the hand-off would silently reinterpret a device MECM had
already accepted, at the next resync, with nothing in the portal saying so.

A reset opened a fresh queue entry while the *previous* rollout's `updateDevice`
could still be in flight. It arrived seconds later, wrote the old ResourceID,
set the VM to `registered`, and took it straight back out of `getDeviceList`.
The new rollout then never happened, and nothing said why.

The Microsoft contracts bound what a fix may assume.
`Import-CMComputerInformation` / `SMS_Site.ImportMachineEntry` take a NetBIOS
name and the PXE MAC for the *initial* record. Later Discovery Data Records may
update the display name of an existing ResourceID record from the Windows
NetBIOS name. There is no rename command to call, and no supported way to write
into the MECM database.

## Decision

**`vm_hostname` stays the single business SSoT and the single editable field.**
No second name field, no deploy mode, no extra operator button. Three internal
runtime columns carry what the hand-off needs: `mecm_rollout_hostname` (the name
THIS rollout was given), `mecm_rollout_revision` (the monotonic fence over every
mutating callback of this rollout) and `mecm_previous_id` (a delete tombstone).

**The snapshot freezes at the hand-off.** While `mecm_id IS NULL` nothing has
been handed over and the snapshot simply follows the desired value. Once a
ResourceID is bound it freezes, because MECM, Windows and the client all already
act on it. Only the existing, confirmed "Reset MECM ID" action moves it again -
it is the one activation point, and there is no second one.

**Identity is normalised in exactly one way, everywhere.** Trim plus ASCII case
folding (`mecm_hostname_key()`, mirrored by `ConvertTo-VsHostnameKey`). It never
truncates and never repairs: a normaliser that silently fixes input collapses
two different desired names onto one claim. A pure change of spelling is
therefore the same machine - no reset, no second claim, no new revision.

**Global uniqueness is enforced transactionally, not by a preceding SELECT.**
`deploy_vm_hostname_claims` has `hostname_key` as a case-insensitive primary key
and a cascading `vm_id`. One VM may legitimately hold two keys at once - its
frozen rollout name and its new desired name - and another VM may take the old
one only after the reset commit releases it, because the old Windows machine
still answers to it until then. Templates hold no claim, no revision and no
tombstone.

**Every mutating callback passes the fence, under the same lock as its write.**
`updateDevice`, `reportMembership` and `mecm_client_ack.php` read the revision
under the row lock their write runs behind, inside one transaction. In
autocommit a bare `SELECT ... FOR UPDATE` gives its lock straight back, so the
transaction is the load-bearing part, not the `FOR UPDATE`. Three answers:
current (write), current-and-already-written (idempotent 200, no second write
and no second status event), and anything else - missing, older, newer, or a
different ResourceID over an existing binding - which is 409 with no domain
write at all. A FUTURE revision is refused as firmly as an old one: only this
server hands revisions out.

**Backward compatibility is fail-closed and measurable.** A callback with no
revision is accepted only for revision 1 with no tombstone, which is exactly the
estate a site runs before the script and client packages are cut over. The
moment anything reset or a pending snapshot moved, the revision is above 1 and
an un-upgraded caller gets 409 instead of writing into the wrong rollout.
Removing that branch after a proven cutover is its own E3/ADR decision.

**VirtuSphere deletes nothing in MECM.** The tombstone keeps the next hand-off
fail-closed until the administrator has removed the old device there. Deleting a
portal VM releases its local claims by cascade but proves no external deletion,
so a newly created VM with the same hostname stays blocked at the existing MECM
object.

**A display-name difference at a valid ResourceID and MAC is not an error.**
That is the Microsoft-sanctioned path: Windows renames, Discovery updates the
record. The device sync resolves a bound `mecm_id` by ResourceID FIRST and
accepts a differing display name silently. It never adopts a similarly named
replacement record, and it never guesses from an error text.

**The wire projection is explicit.** `getDeviceList` lost its `SELECT *`, which
had been promoting every new `deploy_vms` column to a wire field by accident -
and this stage adds three. `vm_name` keeps meaning the ESXi identity;
`vm_hostname` on the wire is aliased onto the frozen snapshot; `rollout_revision`
and `previous_resource_id` are additive. The current, not-yet-activated portal
desired value is deliberately not exported.

## Consequences

A rollout name can only change through an operator action that also says so.
That is the point, and it costs a step: correcting a typo on an already handed
over VM requires deleting the old device in MECM and running the reset. The
portal says that at the field, in the flash after saving, and in the reset
confirmation.

Two names now exist in the UI where one did before. They are labelled as what
they are ("VM name in ESXi" / "Windows hostname"), and the editor shows the
current-versus-next pair only while they actually diverge.

A partially updated site stays safely queued rather than registering a wrong
record. That is the whole reason the rollout order is web/API first, then the
MECM server scripts and client content, and only then hostname changes and
resets that push a revision above 1.

The site acceptance is explicitly not offline-provable: whether the real task
sequence adopts the imported name unchanged is a lab fact, and the release stays
blocked on it (ADR-0040's separation). ADR-0019 is amended for the additive
client revision field; ADR-0034 keeps owning membership reconciliation, which
this ADR only fences.
