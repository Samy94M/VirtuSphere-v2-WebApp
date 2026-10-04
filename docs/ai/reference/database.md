# database implementation reference

Read only the owner sections relevant to the change. These detailed contracts are shared by all agent adapters. Paths refer to the repository or Docker/WebAPI as in the source contract. Commands in the reference identify underlying checks; the public execution entry remains scripts/check.ps1 according to AGENTS.md and docs/QA.md.

## R1 Schema changes must be idempotent, utf8mb4, and preflight data blockers before DDL. Fresh struk

Schema changes must be idempotent, utf8mb4, and preflight data blockers before DDL. Fresh `struktur.sql` and live migrations must converge to the same shape.

Migration `0059_ansible_host_identity` grants one-time accept-new only to existing Ansible credentials. Follow the supported upgrade combination in `docs/operations/upgrade-recovery.md`: portal and workers are not released while migrations are pending. Fresh schema/new credential inserts explicitly default to no upgrade eligibility. The marker comes after the nullable identity/observation columns, so interrupted DDL can be retried without clearing an existing pin or regranting a consumed exception. Its temporary default of 1 bridges interrupted DDL; the new credential writer explicitly inserts 0 even during that interval. An old application writing credentials during this schema transition is outside the supported upgrade contract. Pins and their initial audit commit together; observed mismatches are separate from the anchor. Confirmation carries credential revision plus old pin.

## R2 ESXi object-name columns use binary collation because case and raw Unicode are operative identi

ESXi object-name columns use binary collation because case and raw Unicode are operative identity. Inventory name semantics, freshness and observation are updated per kind in the same cache transaction; one kind cannot upgrade another, and the VLAN catalog must not retire against semantics-1 evidence. Interface VLAN writers route through `lib/repo/vm_network.php`, preserve effective MACs and take the mission/active-job lock before validating the proposed fingerprint.

## R3 PHP constants in Docker/WebAPI/lib/ are the SSoT for ENUM value sets; the struktur.sql and migr

PHP constants in `Docker/WebAPI/lib/` are the SSoT for ENUM value sets; the `struktur.sql` and `migrate.php` ENUM columns are order-exact mirrors. Run `sh scripts/check-enum-sync.sh` after touching a mirrored ENUM or its constants (ADR-0016); it also runs quietly at session start.

## R4 The effective MECM rollout hostname is globally unique through deploy_vm_hostname_claims, not t

The effective MECM rollout hostname is globally unique through `deploy_vm_hostname_claims`, not through a preceding `SELECT`: two missions created in parallel both read "free" and both write. `hostname_key` is the primary key in `ascii_bin`, holding the output of `mecm_hostname_key()` so PHP and the column compare the same bytes; putting the case folding in a collation would be a second, differently-answering copy of the rule. `repo_vm_hostname_claims_sync()` is the ONLY writer, pinned by `MecmRolloutContractTest`. One VM may legitimately hold two keys at once, its frozen rollout name and its new desired name, and another VM may take the old one only in the reset commit, because the old Windows machine answers to it until then. Templates hold no claim, no revision and no tombstone. `REPO_VM_COLUMNS` carries no rollout runtime and must not: that list is what a template capture, a template clone and the mission JSON export copy field by field.

## R5 Logical backup is the only supported recovery stream; MySQL binlog is disabled.

`scripts/backup.sh`, its timestamp-matched manifest/config/database triplet and
`scripts/restore_test.sh` own the recovery contract (ADR-0017). There is no
replication or point-in-time-recovery consumer, so Compose starts MySQL with
`mysqld --skip-log-bin`. Disabling does not authorize direct deletion of old
binlog files from the data directory. Enabling it again requires a separately
accepted archive, retention and restore proof.

## R6 Audit category pagination uses the composite category/id index.

`deploy_logs_category_lookup` is exactly `(category, id)`. Migration 0054
replaces the earlier category-only shape under the same name: retaining both
made MariaDB prefer the shorter index and inspect thousands of rows for a
51-row keyset window. Fresh `struktur.sql` and live migration converge on the
same index. The portal orders audit windows by the primary identity, not by the
non-unique timestamp; do not introduce OFFSET or a second competing category
index.

## R7 Package-report identity, replay and retention

Migration 0056 and the matching fresh schema own ADR-0044 persistence. Each
`deploy_vms` row receives one immutable `package_report_generation`; the
singleton `deploy_package_report_state.acceptance_generation` is rotated only
by `lib/package_report_restore_converge.php` in the supported restore workflow,
after migrations and before the webserver starts. A first accepted event creates
the permanent
minimal `(run_id, expires_at)` marker and its diagnostic run in one transaction.
Diagnostic runs, events and step projections share the immutable 90-day expiry
and are purged by the maintenance retention job; markers are never cascaded or
removed by routine retention.
The repository admits indices 1..256 as normal details, one distinct
first-failure projection and one completion core, with closed event fingerprints
and no replay-based timestamp extension.

## R8 Package-report reads keep expiry, identity and counts together

`lib/repo/package_run_reads.php` scopes detail and step reads by exact VM/run
identity and the still-live marker. `lib/repo/package_run_search.php` applies
one normalized predicate to the list and the run/distinct-VM counts inside a
single transaction, then pages by `(first_received_at,id)` with a 50-row
window. First receipt is the date filter's UTC axis; last stored evidence is
separate. Search `%`, `_` and `!` are literal, exact package/version remains
binary, and a similar-error predicate binds category and known child code to
the same first-failure row. Neither a GET nor a count extends retention or
reconstructs a run from its permanent replay marker.
