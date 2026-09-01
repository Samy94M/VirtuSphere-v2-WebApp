---
globs:
  - Docker/mysql/**
  - Docker/WebAPI/lib/migrate.php
---

Schema changes must be idempotent, utf8mb4, and preflight data blockers before DDL. Fresh `struktur.sql` and live migrations must converge to the same shape.

ESXi object-name columns use binary collation because case and raw Unicode are operative identity. Inventory name semantics, freshness and observation are updated per kind in the same cache transaction; one kind cannot upgrade another, and the VLAN catalog must not retire against semantics-1 evidence. Interface VLAN writers route through `lib/repo/vm_network.php`, preserve effective MACs and take the mission/active-job lock before validating the proposed fingerprint.

PHP constants in `Docker/WebAPI/lib/` are the SSoT for ENUM value sets; the `struktur.sql` and `migrate.php` ENUM columns are order-exact mirrors. Run `sh scripts/check-enum-sync.sh` after touching a mirrored ENUM or its constants (ADR-0016); it also runs quietly at session start.
