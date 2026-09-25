# VirtuSphere machine contracts

Read the sections relevant to the current change. Paths below are relative to Docker/WebAPI unless they already name a repository directory. Forbidden patterns remain in GROK.md section 1; these are the constructive implementation contracts.

## A54 Keep machine API contracts intact: exact 5 legacy status strings, updated MECM flag, mecm_id pr

- Keep machine API contracts intact: exact 5 legacy status strings, `updated` MECM flag, `mecm_id` preservation, MAC import by `(mission_id, vm_name)` after the deploy migration.

## A55 getDeviceList uses the pinned VIRTUSPHERE_MECM_DEVICE_LIST_COLUMNS projection, never SELECT *;

- `getDeviceList` uses the pinned `VIRTUSPHERE_MECM_DEVICE_LIST_COLUMNS` projection, never `SELECT *`; on the wire `vm_name` is the ESXi identity and `vm_hostname` is the frozen rollout snapshot. Every mutating MECM/client callback carries `rollout_revision` and is fenced inside one transaction with its write (ADR-0043).

## A62 mecm-api.php: read surface with getDeviceList and the minimal, side-effect-free getDeviceInfos&

- `mecm-api.php`: read surface with `getDeviceList` and the minimal, side-effect-free `getDeviceInfos&mac=...`; `getMissionName` was retired by ADR-0019. The bootstrap payload additively includes ADR-0044 `device_generation` and `acceptance_generation`, both canonical lowercase UUIDs, so the package reporter can carry its VM-incarnation and post-restore fences.

## A63 mecm_client_ack.php: POST-only, idempotent client-ready acknowledgement by known MAC; sole writ

- `mecm_client_ack.php`: POST-only, idempotent client-ready acknowledgement by known MAC; sole writer of the 5/5 transition.

## A64 mecm_updateid.php: accepts deviceResourceID and deviceid.

- `mecm_updateid.php`: accepts `deviceResourceID` and `deviceid`.

## A65 mecm_packages.php: package/task sequence sync.

- `mecm_packages.php`: package/task sequence sync.

## A66 db_importMAC.php: Ansible MAC import; payload { "mission_id": 123, "job_id": 45, "results": [..

- `db_importMAC.php`: Ansible MAC import; payload `{ "mission_id": 123, "job_id": 45, "results": [...] }`, `job_id` required (ADR-0035). New writes use strict result V2 with per-VM exact WDS evidence and semantic callback fingerprint; historical V1 remains readable. The request/result/response bounds are centralized in `lib/mac_import_constants.php`, and callback fencing must preserve the existing endpoint envelope and HTTP meanings.

## A67 mecm_report.php: report channel (ADR-0018/ADR-0044), POST-only

- `mecm_report.php`: report channel, POST-only. The existing `reportPhase`, `heartbeat` and `reportRun` contracts remain as defined by ADR-0018; the optional `X-VirtuSphere-Token` still applies only to `heartbeat` and `reportRun`. Additive `reportPackageRun` is the ADR-0044 package-wrapper channel: IP-allowlist only, strict V1 JSON up to 64 KiB, unique MAC-set resolution, rollout/device/restore-generation fences and transactional replay semantics. Its successful response repeats `schema_version`, `run_id`, `event` and `event_seq` plus exact `accepted`/`deduplicated` booleans. It never changes VM lifecycle, integration heartbeat state or package catalog state and emits no per-report audit event.
