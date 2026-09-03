---
globs:
  - Docker/mysql/**
  - Docker/WebAPI/lib/migrate.php
---

Schema changes must be idempotent, utf8mb4, and preflight data blockers before DDL. Fresh `struktur.sql` and live migrations must converge to the same shape.

ESXi object-name columns use binary collation because case and raw Unicode are operative identity. Inventory name semantics, freshness and observation are updated per kind in the same cache transaction; one kind cannot upgrade another, and the VLAN catalog must not retire against semantics-1 evidence. Interface VLAN writers route through `lib/repo/vm_network.php`, preserve effective MACs and take the mission/active-job lock before validating the proposed fingerprint.

PHP constants in `Docker/WebAPI/lib/` are the SSoT for ENUM value sets; the `struktur.sql` and `migrate.php` ENUM columns are order-exact mirrors. Run `sh scripts/check-enum-sync.sh` after touching a mirrored ENUM or its constants (ADR-0016); it also runs quietly at session start.

The effective MECM rollout hostname is globally unique through `deploy_vm_hostname_claims`, not through a preceding `SELECT`: two missions created in parallel both read "free" and both write. `hostname_key` is the primary key in `ascii_bin`, holding the output of `mecm_hostname_key()` so PHP and the column compare the same bytes; putting the case folding in a collation would be a second, differently-answering copy of the rule. `repo_vm_hostname_claims_sync()` is the ONLY writer, pinned by `MecmRolloutContractTest`. One VM may legitimately hold two keys at once, its frozen rollout name and its new desired name, and another VM may take the old one only in the reset commit, because the old Windows machine answers to it until then. Templates hold no claim, no revision and no tombstone. `REPO_VM_COLUMNS` carries no rollout runtime and must not: that list is what a template capture, a template clone and the mission JSON export copy field by field.
