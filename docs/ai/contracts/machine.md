# VirtuSphere machine contracts

Read the sections relevant to the current change. Paths below are relative to Docker/WebAPI unless they already name a repository directory. Forbidden patterns remain in GROK.md section 1; these are the constructive implementation contracts.

## A54 Keep machine API contracts intact: exact 5 legacy status strings, updated MECM flag, mecm_id pr

- Keep machine API contracts intact: exact 5 legacy status strings, `updated` MECM flag, `mecm_id` preservation, MAC import by `(mission_id, vm_name)` after the deploy migration.

## A55 getDeviceList uses the pinned VIRTUSPHERE_MECM_DEVICE_LIST_COLUMNS projection, never SELECT *;

- `getDeviceList` uses the pinned `VIRTUSPHERE_MECM_DEVICE_LIST_COLUMNS` projection, never `SELECT *`; on the wire `vm_name` is the ESXi identity and `vm_hostname` is the frozen rollout snapshot. Every mutating MECM/client callback carries `rollout_revision` and is fenced inside one transaction with its write (ADR-0043). `transfer_generation` (additive, AV-P0) is the queueing counter of the row; the MAC callback increments it with `updated = 1` for each successful unbound VM, while the bound-VM comparison path stays unchanged. `updateDevice` may report it back, and the portal clears `updated` only when the reported value equals the stored one, in the binding write and in the `noop` duplicate alike. A callback without it clears whatever is queued (the behaviour before the counter); a present value that is not a non-negative integer is 400.

## A62 mecm-api.php: read surface with getDeviceList and the minimal, side-effect-free getDeviceInfos&

- `mecm-api.php`: read surface with `getDeviceList` and the minimal, side-effect-free `getDeviceInfos&mac=...`; `getMissionName` was retired by ADR-0019. The bootstrap payload additively includes ADR-0044 `device_generation` and `acceptance_generation`, both canonical lowercase UUIDs, so the package reporter can carry its VM-incarnation and post-restore fences.

## A63 mecm_client_ack.php: POST-only, idempotent client-ready acknowledgement by known MAC; sole writ

- `mecm_client_ack.php`: POST-only, idempotent client-ready acknowledgement by known MAC; sole writer of the 5/5 transition. It writes the lifecycle only and keeps the stored MECM sync state (VT-E1 C, ADR-0019 amendment 4); `registered` comes from `updateDevice` alone, and the retry check reads lifecycle and legacy status only.

## A64 mecm_updateid.php: accepts deviceResourceID and deviceid.

- `mecm_updateid.php`: accepts `deviceResourceID` and `deviceid`.

## A65 mecm_packages.php: package/task sequence sync.

- `mecm_packages.php`: package/task sequence sync.

## A66 db_importMAC.php: Ansible MAC import; payload { "mission_id": 123, "job_id": 45, "results": [..

- `db_importMAC.php`: Ansible MAC import; payload `{ "mission_id": 123, "job_id": 45, "results": [...] }`, `job_id` required (ADR-0035). New writes use strict result V2 with per-VM exact WDS evidence and semantic callback fingerprint; historical V1 remains readable. The request/result/response bounds are centralized in `lib/mac_import_constants.php`, and callback fencing must preserve the existing endpoint envelope and HTTP meanings.
- WM-E1: a stored nonempty ResourceID makes export a MAC comparison. Equal MACs preserve the entire VM/interface state and return the existing V2 `success` row with `updated_interfaces=0`; the portal calls it unchanged. A changed mapped MAC returns `bound_mac_changed`, preserves MACs and ResourceID, and is failed by the existing worker conclusion. Its retry is blocked as an identity finding while the VM stays bound and its stored MAC still differs from the reported one (K24, the same `virtusphere_bound_mac_conflict()` predicate, evaluated against current state); a MECM-ID reset or a MAC correction lifts the block. An empty stored MAC on a bound VM is "not known yet" (K3 decision (b)): the callback writes only those MACs, keeps every VM state field, counts them in `updated_interfaces` and records a fixed system line in the job log in the same transaction; the portal derives its "first MAC" notice from that line. Comparison is on normalized MACs. There is no new response field, outcome token or result version.
- K15 changes only incomplete-job VM-state convergence: Export first records an admitted attempt for each VM before `guest_info`; failure, cancellation, reaper and sweep apply the same per-VM journal decision. Successful imported VM IDs remain protected. This does not change the callback envelope, outcome tokens or V2 fingerprint.

## A67 mecm_report.php: report channel (ADR-0018/ADR-0044), POST-only

- `mecm_report.php`: report channel, POST-only. The existing `reportPhase`, `heartbeat` and `reportRun` contracts remain as defined by ADR-0018; the optional `X-VirtuSphere-Token` still applies only to `heartbeat` and `reportRun`. `reportRun.script_version` stays optional free text of at most 32 characters. The MECM server tasks send `<task>/<x.y>+<release>` with the release `JJJJ.MM.N` (N 1 to 99), or the older `+<commit7>`, `+unbekannt`, `+ohne-manifest`, each optionally followed by `-abw`; the portal parses it only for the settings tab "Version" and never rejects a report because of it. The sync error categories are the closed list `VIRTUSPHERE_RUN_SYNC_ERROR_CATEGORIES` (mirror `$script:VsRunSyncErrorCategories` in `VirtuSphere-Common.ps1`, pinned by `RunReport.Tests.ps1` and `RunReportValidateTest`); `journal_blocked`, `portal_rejected` and `source_changing` are accepted since 17.10.2026, portal first, and the server scripts send them only from a later server update. Additive `reportPackageRun` is the ADR-0044 package-wrapper channel: IP-allowlist only, strict V1 JSON up to 64 KiB, unique MAC-set resolution, rollout/device/restore-generation fences and transactional replay semantics. Its successful response repeats `schema_version`, `run_id`, `event` and `event_seq` plus exact `accepted`/`deduplicated` booleans. It never changes VM lifecycle, integration heartbeat state or package catalog state and emits no per-report audit event.

## A68 deploy_vm_touch.php admits one exact ESXi attempt per VM

- The new JSON-only POST keeps the existing machine-IP allowlist and adds no token, nonce, worker ownership or MAC-result contract. Its closed request is `{job_id, vm_id, attempt, generation, phase}`; exact success echoes those fields plus `success: true`. The active job, current attempt/runtime generation, persisted mission scope and playbook phase must all match before the journal row is marked. Refusal is closed 4xx (`400` malformed, `405` method, `409` stale/scope/phase, `413` oversized). The marker proves an authorized access attempt, not a successful ESXi mutation; the Ansible client must receive the exact acknowledgement before the matching VM's first `guest_info`. It writes no `deploy_vms` field and does not alter existing machine status or MAC-import wire contracts.

## A69 Every machine IP gate names its caller role; an entry admits only its role's endpoints

- Migration 0063 adds `deploy_accessToWebAPI.role` (`mecm_server`, `ansible_host`, `client`, or NULL). `lib/machine_api_roles.php` owns the role set and `VIRTUSPHERE_MACHINE_API_GATE_ROLES`, the one map from gate to admitted roles: Ansible host for `db_importMAC.php` and `deploy_vm_touch.php`; MECM server for `getDeviceList`, `updateDevice`/`reportMembership`, `mecm_packages.php`, `heartbeat` and `reportRun`; client for `getDeviceInfos`, `reportPhase`, `mecm_client_ack.php` and `reportPackageRun`. `machine_api_ip_allowed($db, $ip, $gate)` requires the gate key; `MachineApiRoleContractTest` derives the gates from the endpoint files and fails on a call without a key or with an unknown one.
- An entry without a role keeps opening every gate until an administrator assigns one; a role is never inferred. System status and the settings page count such entries. A role mismatch answers the unchanged legacy 403 body; only the throttled `machine_api.access_denied` audit row adds the closed `reason` (`ip_not_allowlisted` or `ip_role_not_permitted`). The throttle is keyed by IP and closed cause, so a change of cause writes its row at once; a refusal without a cause stays on the IP-only scope. The MAC-based client paths are untouched on purpose (ADR-0035 Amendment 2). One IP may hold one entry per role; an entry without a role blocks a second entry for that IP.
