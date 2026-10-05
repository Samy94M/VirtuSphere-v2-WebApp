# VirtuSphere machine contracts

Read the sections relevant to the current change. Paths below are relative to Docker/WebAPI unless they already name a repository directory. Forbidden patterns remain in GROK.md section 1; these are the constructive implementation contracts.

## A54 Keep machine API contracts intact: exact 5 legacy status strings, updated MECM flag, mecm_id pr

- Keep machine API contracts intact: exact 5 legacy status strings, `updated` MECM flag, `mecm_id` preservation, MAC import by `(mission_id, vm_name)` after the deploy migration.

## A55 getDeviceList uses the pinned VIRTUSPHERE_MECM_DEVICE_LIST_COLUMNS projection, never SELECT *;

- `getDeviceList` uses the pinned `VIRTUSPHERE_MECM_DEVICE_LIST_COLUMNS` projection, never `SELECT *`; on the wire `vm_name` is the ESXi identity and `vm_hostname` is the frozen rollout snapshot. Every mutating MECM/client callback carries `rollout_revision` and is fenced inside one transaction with its write (ADR-0043). `transfer_generation` (additive, AV-P0) is the queueing counter of the row; `updateDevice` may report it back, and the portal clears `updated` only when the reported value equals the stored one, in the binding write and in the `noop` duplicate alike. A callback without it clears whatever is queued (the behaviour before the counter); a present value that is not a non-negative integer is 400.

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
- WM-E1: a stored nonempty ResourceID makes export a MAC comparison. Equal MACs preserve the entire VM/interface state and return the existing V2 `success` row with `updated_interfaces=0`; the portal calls it unchanged. A changed mapped MAC returns `bound_mac_changed`, preserves MACs and ResourceID, and is failed by the existing worker conclusion. An empty stored MAC on a bound VM is "not known yet" (K3 decision (b)): the callback writes only those MACs, keeps every VM state field, counts them in `updated_interfaces` and records a fixed system line in the job log in the same transaction; the portal derives its "first MAC" notice from that line. Comparison is on normalized MACs. There is no new response field, outcome token or result version.

## A67 mecm_report.php: report channel (ADR-0018/ADR-0044), POST-only

- `mecm_report.php`: report channel, POST-only. The existing `reportPhase`, `heartbeat` and `reportRun` contracts remain as defined by ADR-0018; the optional `X-VirtuSphere-Token` still applies only to `heartbeat` and `reportRun`. Additive `reportPackageRun` is the ADR-0044 package-wrapper channel: IP-allowlist only, strict V1 JSON up to 64 KiB, unique MAC-set resolution, rollout/device/restore-generation fences and transactional replay semantics. Its successful response repeats `schema_version`, `run_id`, `event` and `event_seq` plus exact `accepted`/`deduplicated` booleans. It never changes VM lifecycle, integration heartbeat state or package catalog state and emits no per-report audit event.
