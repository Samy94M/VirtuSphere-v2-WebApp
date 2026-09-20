# VirtuSphere machine contracts

Read the sections relevant to the current change. Paths below are relative to Docker/WebAPI unless they already name a repository directory. Forbidden patterns remain in GROK.md section 1; these are the constructive implementation contracts.

## A54 Keep machine API contracts intact: exact 5 legacy status strings, updated MECM flag, mecm_id pr

- Keep machine API contracts intact: exact 5 legacy status strings, `updated` MECM flag, `mecm_id` preservation, MAC import by `(mission_id, vm_name)` after the deploy migration.

## A55 getDeviceList uses the pinned VIRTUSPHERE_MECM_DEVICE_LIST_COLUMNS projection, never SELECT *;

- `getDeviceList` uses the pinned `VIRTUSPHERE_MECM_DEVICE_LIST_COLUMNS` projection, never `SELECT *`; on the wire `vm_name` is the ESXi identity and `vm_hostname` is the frozen rollout snapshot. Every mutating MECM/client callback carries `rollout_revision` and is fenced inside one transaction with its write (ADR-0043).

## A62 mecm-api.php: read surface with getDeviceList and the minimal, side-effect-free getDeviceInfos&

- `mecm-api.php`: read surface with `getDeviceList` and the minimal, side-effect-free `getDeviceInfos&mac=...`; `getMissionName` was retired by ADR-0019.

## A63 mecm_client_ack.php: POST-only, idempotent client-ready acknowledgement by known MAC; sole writ

- `mecm_client_ack.php`: POST-only, idempotent client-ready acknowledgement by known MAC; sole writer of the 5/5 transition.

## A64 mecm_updateid.php: accepts deviceResourceID and deviceid.

- `mecm_updateid.php`: accepts `deviceResourceID` and `deviceid`.

## A65 mecm_packages.php: package/task sequence sync.

- `mecm_packages.php`: package/task sequence sync.

## A66 db_importMAC.php: Ansible MAC import; payload { "mission_id": 123, "job_id": 45, "results": [..

- `db_importMAC.php`: Ansible MAC import; payload `{ "mission_id": 123, "job_id": 45, "results": [...] }`, `job_id` required (ADR-0035). New writes use strict result V2 with per-VM exact WDS evidence and semantic callback fingerprint; historical V1 remains readable. The request/result/response bounds are centralized in `lib/mac_import_constants.php`, and callback fencing must preserve the existing endpoint envelope and HTTP meanings.

## A67 mecm_report.php: report channel (ADR-0018), POST-only; action=reportPhase (client phase events

- `mecm_report.php`: report channel (ADR-0018), POST-only; `action=reportPhase` (client phase events by MAC), `action=heartbeat` (legacy sync-loop heartbeats) and `action=reportRun` (additive: per-run `started`/`completed` results from the three sync tasks plus `completed`-only `mecm-site-health` from `SMS_SummarizerSiteStatus`, 0=ok/1=warning/2=critical/else unknown). Display-only: `last_event` drives the badge, arrival order is truth (sequential client, dedup only on an identical completed `run_id`), provider faults are grey and red is reserved for MECM-confirmed status 2; migration 0025 adds columns additively with no backfill. Optional `X-VirtuSphere-Token` header (heartbeat/reportRun), checked only when a token hash is configured.
