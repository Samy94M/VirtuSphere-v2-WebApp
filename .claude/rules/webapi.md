---
globs:
  - Docker/WebAPI/**
---

Use `lib/db.php` for database access and keep secrets behind EnvBoot. New SQL must use prepared statements. New web output must use centralized CSP/security headers and correct escaping for HTML versus JSON.

- A repo function that rewrites a row set (DELETE followed by INSERT) wraps it in `repo_transaction()` itself instead of trusting its callers. The empty middle state must never be committed: another reader treats the missing rows as proof that they are gone. `repo_transaction()` is re-entrant, so callers may nest it; MySQL has no nested transactions and would answer a second `BEGIN` by committing the outer one.
- `repo_transaction()` is the only way `lib/` code opens a transaction. A raw `$db->begin_transaction()` there is a defect even while it works: the depth tracker cannot see it, so composing it with any `repo_transaction()` caller silently commits half a transaction. Sole exception: the single outermost request transaction in the legacy machine-API scripts (`db_importMAC.php`, `mecm_packages.php`), which nothing nests; code inside those blocks must not call `repo_transaction()`-wrapped repos.
- Connection tests and fetch failures return a `VIRTUSPHERE_INVENTORY_ERROR_*` category plus a redacted operator detail, never a finished sentence. The portal maps the category through `connection_error_message()` and renders the detail behind the alert's `<details>`.
- Distinguish portal HTML from machine/API JSON. Portal errors are localized and HTML-escaped; machine/API responses keep their wire shape and use `json_encode`.
- Do not localize MECM, Ansible, deploy-worker or legacy token response fields as part of portal language work.
- Shared helpers that emit user-facing portal strings should use `__t()` with a safe fallback if they can also run before the portal bootstrap.
- Keep logs and operator diagnostics detailed, but map them to safe user-facing messages before rendering in the portal.
- The free-text `os_status`/`package_status` columns are normalized on write: the repo validators run `catalog_normalize_status()` (`lib/repo/catalog.php`), which folds known synonyms onto the canonical `Aktiv`/`Retired` and passes unknown text through. Any new write path for these columns routes through the validators, not a raw INSERT/UPDATE.
- Detect a template by its name with `mission_name_is_template()` (`lib/defaults.php`), never an inline `str_starts_with($name, VIRTUSPHERE_TEMPLATE_PREFIX)`; the helper trims to match stored names.