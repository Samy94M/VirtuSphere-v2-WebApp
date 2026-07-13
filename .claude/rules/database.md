---
globs:
  - Docker/mysql/**
  - Docker/WebAPI/lib/migrate.php
---

Schema changes must be idempotent, utf8mb4, and preflight data blockers before DDL. Fresh `struktur.sql` and live migrations must converge to the same shape.

PHP constants in `Docker/WebAPI/lib/` are the SSoT for ENUM value sets; the `struktur.sql` and `migrate.php` ENUM columns are order-exact mirrors. Run `sh scripts/check-enum-sync.sh` after touching a mirrored ENUM or its constants (ADR-0016); it also runs quietly at session start.
