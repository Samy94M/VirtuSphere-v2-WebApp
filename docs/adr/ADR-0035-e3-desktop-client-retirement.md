# ADR-0035: E3 accepted: the desktop client and its token API are removed

Status: accepted (2026-07-27). Decision 8 of the 2026-07 hardening campaign.

## Context

VirtuSphere migrated from a C# WinForms desktop client to the server-rendered
portal. The desktop client's token API (`access.php`, `api/login.php`,
`deploy_tokens`, `lib/repo/legacy.php`) stayed deployed as a frozen legacy
surface pending the E3 milestone (ADR-0009, ADR-0019). E3 is now accepted for
the desktop client: everything runs through the portal; the MECM/PowerShell
and Ansible machine chain stays unchanged.

The frozen surface was not free. `access.php?action=updateMission` bypassed
the portal mission-name guards (ADR-0019 candidate 4), the job_id-less MAC
callback in `db_importMAC.php` could rewrite interface rows no running deploy
owned, token verification ignored `is_active` until the portal compensated
with a revoke-on-deactivate transaction (WP-13a), and every one of these
compensations had to be carried through each hardening stage.

## Decision

The desktop client and its token path are removed physically, in one cut, so
no intermediate state re-opens WP-13a (a deactivation without token revoke
while the token endpoint still answers):

- **Endpoints**: `access.php` and `api/login.php` are deleted. The negative
  contract is part of `MachineApiWireTest`: both paths answer 404, checked
  from an allowlisted client so a 403 cannot mask a still-deployed file.
- **Code**: `lib/repo/legacy.php` (token issue/verify/expand, the dead
  `createVM` stub) and the desktop-only wrappers `createMission()`/
  `updateMission()` in `lib/repo/missions.php` are gone. Candidate 4 of
  ADR-0019 is thereby resolved by removal.
- **Schema**: migration `0034_drop_legacy_token_schema` drops `deploy_tokens`
  after counting what it destroys; `struktur.sql` no longer creates the
  table; migrations 0010/0021 are guarded so the migration path still runs
  against a fresh schema (`check-schema-convergence.sh` exercises the
  pre-0010 shape end to end).
- **Portal**: `users.php` no longer revokes tokens on deactivate (there are
  none); the DE/EN confirm texts drop the desktop sentence.
- **MAC callback**: `db_importMAC.php` requires `job_id`. A callback without
  a job scope answers 400 and writes nothing. `upload_mac_list.py` already
  omits an invalid `job_id`, so a mis-templated playbook run now fails
  loudly at the portal instead of importing unscoped.
- **Desktop sources**: `VirtuSphere.sln`, `VirtuSphere.csproj` and all C#
  sources are deleted; the `legacy-csharp-build` gate leaves `check.ps1`,
  CI and QUALITY-GATES.md.

Deliberately **kept**:

- The `legacy_api` log category, its portal tab and its rows: they are the
  usage evidence and stay readable after the drop (the migration touches
  only `deploy_tokens`).
- The machine API's `legacy_payload` field and `mac_import.php`'s payload
  normalization: they describe the *payload shape* of the Ansible upload,
  not the desktop path.
- The five legacy status strings, the `X-VirtuSphere-Token` report-channel
  secret and everything else named "legacy" in the MECM/Ansible chain: the
  ambiguity is real, only the desktop/token path fell.

## Usage evidence

- Dev (2026-07-27): 698 `legacy_api` log rows, all test traffic (PHPUnit/E2E
  seeds); 13 tokens, 12 unexpired, all test-issued. No production client.
- **Prod (open rollout checkpoint)**: before the first 5b deploy on
  ubuntu-102, record the last real usage from the production DB:
  `SELECT MAX(created_at), COUNT(*) FROM deploy_logs WHERE
  category='legacy_api';` plus the latest rows in detail. The check is
  booked in PRE-SHIP-CHECKLIST.md; the migration keeps the evidence
  queryable afterwards.

## Consequences

- ADR-0019 candidates 1-3 and 5 (getMissionName, getDeviceInfos trimming and
  side-effect, machine-API transport) were not decided by this ADR. They were
  resolved separately in ADR-0019 amendment 2 on 2026-08-09.
- The MECM/PowerShell/Ansible wire contract is untouched; the full E2E deploy
  proves the machine chain.
- A future "desktop client" would be a new decision against the portal's
  RBAC/session model, not a revival of the token path.

## Amendment 1 (2026-10-12, VT-01): one role per allowlist entry

Machine authentication stays the IP allowlist plus the optional hashed report
token. Until now one entry opened every endpoint, so the Ansible host listed
for the MAC callback could also call `updateDevice`, `reportMembership` and the
package catalog (finding VT-01, released by the user on 2026-10-05).

- Each entry carries one role: MECM server, Ansible host or client
  (migration 0063). `VIRTUSPHERE_MACHINE_API_GATE_ROLES`
  (`lib/machine_api_roles.php`) maps every IP gate to the roles it admits, and
  `machine_api_ip_allowed()` requires the gate key.
- Entries without a role keep the old behaviour, every endpoint, until an
  administrator assigns one. System status and the settings page count them; no
  role is inferred.
- The refusal stays the frozen legacy 403 including the IP echo; the audit row
  adds the closed `reason`. The MAC-based client paths are unchanged, and the
  Ansible preflight probe keeps asking the MAC callback gate.

## Amendment 2 (2026-10-17, MA-06): the MAC path of the client endpoints stays, deliberately

The three client paths (`getDeviceInfos` in `mecm-api.php`, `reportPhase` in
`mecm_report.php`, `mecm_client_ack.php`) admit a request when the IP allowlist
admits it for the client role OR the reported MAC belongs to a known interface
(`machine_api_mac_allowed()`). This stays (user decision 2026-10-17, audit
finding MA-06), because the client calls `getDeviceInfos` in the task sequence
with its DHCP address, before its static IP exists, and the allowlist holds
exact addresses only.

- Risk, accepted under the LAN and air-gap assumption: a MAC is not a secret.
  Any host in the deploy network that sees a MAC can read that VM's network
  bootstrap data and, through the acknowledgement, move its lifecycle to 5/5.
- Candidate, not built: subnet entries for the client role; once they exist,
  the acknowledgement and `getDeviceInfos` could require the IP role.
