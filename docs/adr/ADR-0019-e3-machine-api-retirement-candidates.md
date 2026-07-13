# ADR-0019: E3 Machine-API Retirement Candidates

Date: 2026-07-08
Status: Proposed

## Context

The MECM/PowerShell/Ansible machine API surface must stay wire-compatible until
the E3 deploy milestone is accepted (see CLAUDE.md, ADR-0009). The hardening
work in the plan stages E1–E5 deliberately added behavior without changing or
removing existing wire fields, endpoints or their semantics. Several such
change candidates were identified and intentionally deferred to E3, where a
coordinated retirement (with updated scripts and `MachineApiWireTest`) is
allowed. This ADR records them so they are not lost.

## Decision

The following are tracked as E3 retirement / cleanup candidates. None may be
changed before an explicit E3 retirement decision:

1. **`mecm-api.php?action=getMissionName`** — redundant. `getDeviceList`
   already embeds the full `mission` object; the sanitized `mecm_new-device-sync.ps1`
   uses that instead of the per-device call. Retire the action once no
   deployed script calls it.
2. **`getDeviceInfos` payload trimming** — the endpoint returns
   `SELECT * FROM deploy_vms` plus the full mission, including `vm_notes`,
   `mission_notes` and `vm_creator`, and the client persists all of it into
   the registry of every deployed machine. Clients only need hostname/domain
   and the interface data. Trim the wire payload to the needed fields.
   (The V22 client already whitelists what it stores as defense-in-depth.)
3. **`getDeviceInfos` GET side effect** — the GET sets the VM lifecycle to
   `os_installed` on every call. With the E1 report channel a client now
   sends explicit phase events, so the coarse side effect can be replaced by
   an explicit acknowledgement and the GET made side-effect-free.
4. **Legacy mission-rename guard gap** — `access.php?action=updateMission`
   (legacy token API) routes through `updateMission()` and bypasses the
   portal/repo mission-name guards (template-prefix rule and the E2
   MECM-rename lock in `repo_update_mission_checked`). Retire the legacy
   `updateMission` path or route it through the guarded repo function.
5. **Move the machine API to HTTPS** — the wire surface stays HTTP until E3
   (ADR-0012/ADR-0027 exempt it from the portal redirect). Motivation: the
   report token (`X-VirtuSphere-Token`) crosses the LAN in cleartext today;
   the damage is bounded (the report channel is display-only per ADR-0018,
   plus the IP allowlist), and the worst cleartext offender, `access.php`, is
   retired at E3 anyway. Server side already serves the API paths on the WP7
   8443 listener, so the migration is client-only: PowerShell clients change
   the registry URL to `https://` and add a one-time
   `[Net.ServicePointManager]::SecurityProtocol = Tls12` line (PowerShell 5.1
   stays mandatory at the customer); domain-joined Windows/MECM machines
   trust the domain CA automatically, so no per-client certificate is
   distributed. The Ubuntu Ansible host needs the domain root CA in its
   system trust store and the `upload_mac_list.py` URL switched (Python
   stdlib verifies certificates correctly by default). Afterwards the HTTP
   port can be closed.

## Consequences

- The current wire contract is fully preserved; deployed scripts keep working.
- When E3 is accepted, each item can be executed independently, each paired
  with a `tests/Integration/MachineApiWireTest.php` update and a migration-doc
  note per `.claude/rules/powershell.md`.
- Until then these are known, documented gaps rather than latent surprises.
