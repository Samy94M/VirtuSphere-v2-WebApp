# ADR-0044: Package-wrapper reports are fenced by VM incarnation and restore generation

Date: 2026-09-21
Status: Accepted; wire validator and fixtures implemented, persistence not yet activated

## Context

The package wrapper will report bounded installation evidence to the existing
machine-report surface. A client-generated run UUID and rollout revision are not
enough to bind that evidence to one VM lifetime: a deleted VM can be recreated
with the same MAC and revision. Likewise, restoring an older database can remove
both an accepted run and its replay marker. Reaccepting either report would make
old evidence look current.

The channel remains best effort and display-only. It must not change package
execution, detection, VM lifecycle, MECM identity or Software Center exit codes.

## Decision

1. The additive V1 action is named `reportPackageRun`. It has its own closed
   schema and never falls back to `reportPhase` or `reportRun`. The first package
   establishes the pure validator and the shared fixture; the endpoint is not
   enabled until its schema and transactional repository are present.
2. Every self-describing event repeats immutable identity metadata: canonical
   lowercase run UUID, exact package project/version strings, canonical MAC
   candidate set, rollout revision, VM incarnation UUID, restore acceptance UUID,
   client start time and system/user context. Unknown fields, numeric strings and
   non-canonical UUIDs are rejected. JSON key order and MAC list order are not
   semantic identity.
3. A VM incarnation UUID is generated server-side for each new `deploy_vms` row.
   Existing rows receive one during migration. It survives ordinary edits and a
   MECM-ID reset, but cloning or recreating a VM creates a new value. It is not a
   name, secret or authentication credential.
4. A singleton restore acceptance UUID is generated server-side. The supported
   disaster-restore procedure rotates it after importing and migrating the dump,
   before the restored WebAPI accepts package reports. A report carrying the
   generation from the backup or from the pre-restore live database is rejected.
   Local package execution continues when reporting is rejected. Importing a dump
   outside the supported restore procedure cannot claim this replay guarantee.
5. The first accepted event creates both the diagnostic run and a global minimal
   replay marker in one `repo_transaction()`. The marker contains only the run UUID
   and the immutable diagnostic expiry (`first server acceptance + 90 days`). It
   does not cascade with the VM/run and is retained after diagnostic deletion.
   Replays never extend the expiry. An expired marker returns 410 and cannot create
   a new run; a marker without a live run before expiry is an integrity failure.
6. The detail budget is 256 normal step indices, plus one reserved first-failure
   projection and the bounded completion core. Eligibility uses stable step index,
   not arrival order. An excess ordinary detail returns 422 `detail_limit`; this
   does not consume the failure/completion reserve. An identical event replay is
   200/deduplicated without timestamp or retention refresh; different content for
   the same run/event key is 409 with no partial write.
7. Accepted/deduplicated responses are bounded JSON and repeat run ID, event and
   event sequence. HTML, 202, a foreign identity or an invalid body is never a
   delivery confirmation. Authorization and object checks occur before revealing
   whether a run UUID is known.

## Consequences

- The schema needs package runs, bounded event fingerprints/steps, permanent
  minimal replay markers, a VM-incarnation column and one restore-generation row.
  Fresh schema and migrations must converge.
- `getDeviceInfos` and the client snapshot need an additive incarnation and
  acceptance-generation extension before a reporter can be enabled. The values
  fence evidence; they do not strengthen the LAN allowlist into cryptographic
  client authentication.
- The restore runbook and drill must prove generation rotation. A restored client
  snapshot becomes stale and reporting stays unavailable until refreshed; the
  package payload is unaffected.
- Minimal replay markers grow with accepted run IDs. Capacity admission may reject
  new runs, but must never delete markers to make room. Measured storage and load
  remain an implementation/acceptance requirement.
- The canonical executable fixture is
  `Docker/WebAPI/tests/fixtures/package-report-v1.json`. Stateful cases in that
  file become repository/integration tests when persistence is added; their
  presence alone is not a database or endpoint proof.
