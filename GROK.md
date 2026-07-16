# VirtuSphere SSoT

## 1. Forbidden Patterns

- Interpolated SQL in `query()` or `mysqli_query()`.
- Hardcoded credentials, weak secret defaults or `getenv(...) ?:` fallback for secrets.
- PHP short tags `<?` instead of `<?php`.
- Plaintext password storage.
- POST writes without CSRF on new portal pages.
- Inline `<script>`, `<style>`, `style=` or `on*=` handlers without a CSP nonce.
- External `src=` or `href=` runtime assets.
- New portal-visible strings hardcoded in PHP views, layout or validation paths instead of `__t('module.key')`.
- Raw generic `$exception->getMessage()` rendered in portal flash or HTML output instead of `ValidationException` field messages or `portal_error_message()`.
- Hardcoded translatable JavaScript labels instead of labels rendered through a CSP-nonced JSON island.
- `window.confirm()`, `alert()` or a second hand-rolled modal/focus-trap for portal confirmations. Destructive actions carry `data-confirm`; the shared `<dialog class="modal modal-confirm">` in `lib/layout_modals.php`, rendered once by `layout_footer()`, is the only renderer (ADR-0013). Enforced by `tests/Static/PortalConfirmContractTest.php`.
- A per-dialog `text-align`/`justify-content` on a modal. `.modal[open]`, `.modal-box`, `.modal-msg` and `.modal-actions` in `components.css` place all modal content; `.modal-msg` picks alignment by text length and must keep its `overflow-wrap`, or a confirm question clips the target name it is asking about. Enforced by `tests/Static/ModalAxisContractTest.php`.
- A portal form action that is neither confirmed with `data-confirm` nor declared in the test's `SAFE_ACTIONS` with the reason it cannot lose anything. The classification is closed on purpose: naming a verb list ("delete, clear, reset, ...") missed `generate_token`, which invalidates the token deployed on the MECM server, and `set_role`, which lets an admin demote themselves.
- A `.button-danger` submit without `data-confirm`. The danger fill is reserved for destructive actions; the confirmation and the fill are independent signals (`generate_token` confirms without the fill).
- Rendering a conditional `data-confirm=""` empty instead of omitting the attribute. The `[data-confirm]` selector matches a blank value.
- Re-submitting a confirmed action with `form.submit()`. It drops the form's submitter and silently strips a `name="action"` button value, turning a delete into a no-op. Replay with `form.requestSubmit(trigger)`.
- Treating a confirmation dialog as an authorization or validation gate. Without JavaScript the POST goes straight through, so `can()`, CSRF and validation stay in the POST handler.
- Hand-building `<span class="badge badge-*">` markup. `portal_badge($variant, $label)` (`lib/layout.php`) is the one renderer; it escapes both, so pass the raw label. A badge that needs an extra attribute (`title`, `data-*`, the live `data-deploy-status`) is the only exception.
- Hand-rolling `csrf_verify()` in a standard portal POST handler. `portal_guard_post($connection, $user)` (`lib/audit_events.php`) is the shared prologue; it derives the audit context from the script name. Only `login.php` (soft redirect), `logout.php` (custom body) and `session_ping.php` (JSON) keep their own check. Enforced by `tests/Static/PortalPostGuardContractTest.php`.
- Inline `str_starts_with($name, VIRTUSPHERE_TEMPLATE_PREFIX)` to detect a template. `mission_name_is_template()` (`lib/defaults.php`) is the one predicate; it trims first, matching how names are stored.
- Re-inlining the catalog status filter list `['active','retired','all']`. `VIRTUSPHERE_CATALOG_FILTERS` (`lib/constants.php`) is the token SSoT; `portal_catalog_status_filter()` renders the shared `<select>`. Enforced by `tests/Static/CatalogFilterContractTest.php`.
- Storing a raw free-text catalog status. The `os`/`package` repo validators fold known synonyms onto the canonical `Aktiv`/`Retired` via `catalog_normalize_status()` (`lib/repo/catalog.php`) on every write; unknown text passes through (narrowing the legacy API is E3).
- Reintroducing broken legacy `createVM`/`createOrUpdateVm` flows as new code.
- New code depending on `access.php`, HTTP 418 token behavior or plaintext deploy tokens.
- Normal VM edits that reset `mecm_id`, `updated` or machine-owned status transitions. Use only the explicit `Reset MECM ID` action for MECM requeue recovery.
- Raw `$db->begin_transaction()` in `Docker/WebAPI/lib/`. Transactions open through the re-entrant `repo_transaction()` (`lib/repo/helpers.php`): a raw `BEGIN` is invisible to its depth tracking, and MySQL answers a nested `BEGIN` by silently committing the outer transaction. Sole exception: the single outermost request transaction in the legacy machine-API scripts (`db_importMAC.php`, `mecm_packages.php`), which nothing nests; code inside those blocks must not call `repo_transaction()`-wrapped repos.
- `db_importMAC.php` validates an optional `job_id` (exists, same mission, `running`) before its raw request transaction, rechecks it with a locking read inside, and then uses only raw prepared statements for interface, VM-state, status-event and `result_json` writes. Do not call `repo_set_vm_state()` or any `repo_transaction()`-wrapped helper from that transaction.
- Reading an ownership/provenance field (`vm_creator`, `mission_creator`) out of a caller's payload. `repo_save_vm()` and `repo_create_mission()` own them: stamped from the acting user on create, preserved from the stored row on edit, never taken from `$vmData`/`$missionData`. Render them `readonly` **and** without a `name` attribute, because a readonly input still submits. `repo_validate_vm_payload()` keeps accepting `vm_creator` on purpose: mission import and the legacy desktop API carry their own. A user-less caller (legacy `createMission()`) leaves the column empty rather than guessing an author.

## 2. Architecture

The target is a server-rendered PHP web app in `Docker/WebAPI/portal`, backed by shared PHP helpers in `Docker/WebAPI/lib`. The browser talks to nginx over HTTP in the LAN. PHP talks to MySQL through `lib/db.php`. Server-side deploy work runs in a separate `deploy-worker` loop container; periodic maintenance (MECM reachability probe, retention purges, integration-state transition audits per ADR-0018, and the deploy-VM convergence sweep that fails `deploying` VMs whose mission has no active job left) runs in a sibling `maintenance-worker` container (`lib/maintenance_worker.php`). The machine API files stay as an explicit internal surface.

Portal i18n is governed by ADR-0014. `Lang` and `__t()` are the SSoT for portal UI text, with catalogs in `Docker/WebAPI/lang/{de,en}` and DE/EN parity checked by `scripts/lang-audit.php`. Locale is display-only; it must not affect auth, permissions, deploy behavior or machine API contracts.

The `data-confirm` attribute is the SSoT for portal confirmations (ADR-0013). It is the whole markup contract: a single `<dialog class="modal modal-confirm">` from `lib/layout_modals.php` (rendered once by `layout_footer()`) and one handler in `assets/core.js` build every prompt, and both portal modals (confirm, session expiry) share the `.modal` base in `components.css`. The accept button reuses the trigger's own localized label, so no translatable string lives in JavaScript; `data-confirm-action` overrides that label only when it would collide with the dialog's dismiss button.

`portal_format_timestamp()` in `lib/layout.php` is the SSoT for rendering stored `created_at`/`updated_at`/`last_seen_at`/`locked_until` values in the portal (`d.m.Y H:i:s`). Route every new display-only timestamp through it instead of echoing the raw MySQL string. Values that round-trip through a form for optimistic-concurrency checks (the hidden `updated_at` field on `vm_edit.php`/`mission_details.php`) must stay raw, since they are compared byte-for-byte against the DB column.

## 3. Security Constraints

- EnvBoot must fail fast if `APP_KEY`, `DB_PASS` or `MYSQL_ROOT_PASSWORD` are missing or weak.
- CSP and security headers are centralized in `lib/headers.php`.
- CSP and CSRF rules stay unchanged for localized portal pages. Inline scripts/styles still require a nonce, and POST writes still require CSRF.
- Compose keeps phpMyAdmin loopback-only and does not mount the Docker socket into PHP.
- HTTPS is admin-configured in the portal (ADR-0027): cert upload plus three toggles. PHP writes config/certs only to a shared volume; nginx reloads itself behind an `nginx -t` gate. The HTTP->HTTPS redirect lives in `lib/bootstrap.php`, so the machine API and `health.php` are exempt by construction; the machine API stays HTTP until E3, its HTTPS migration is candidate 5 in ADR-0019.

## 4. ADR Index

See `docs/adr/README.md`. ADR-0001 through ADR-0013 are the initial decision baseline; ADR-0014 adds the portal i18n and user-facing error-message contract; ADR-0015 defines the PHPUnit baseline and blocking hook policy; ADR-0016 adds the SSoT drift checks and doc-hygiene budgets; ADR-0017 defines backup and restore.

## 5. Integration Contracts

- Legacy status strings remain exact: `1/5 Initializing`, `2/5 Registered`, `3/5 Deployed`, `4/5 OS Installing`, `5/5 OS Installed`.
- `updated=1` means MECM pickup eligibility; `mecm_updateid.php` clears it after registration.
- Normal VM edit must preserve existing interface MACs and `mecm_id`; the explicit `Reset MECM ID` action clears `mecm_id`, sets `updated=1`, and requeues the VM as `deployed/pending`.
- Template clone resets runtime data: new IDs, empty MACs, `mecm_id=NULL`, `ready/not_ready`, `2/5 Registered`, `updated=0`.
- Mission and template names must not contain whitespace; templates are distinguished by the `_` prefix. Enforced centrally in `repo_validate_mission_values()` (create/update/clone/save-as-template all route through it); `mission_name_is_template()` (`lib/defaults.php`) is the single predicate that reads the prefix.
- `upload_mac_list.py` migrates to payload `{ "mission_id": 123, "results": [...] }`; legacy array payload may be accepted only during migration.
- `mecm_report.php` (ADR-0018) is display-only telemetry: it never mutates `deploy_vms` lifecycle/sync state. Client phase events live in `deploy_client_events` (VM-cascade, 30-day retention), heartbeats in `deploy_integration_heartbeats` (one upsert row per source). The optional report token is stored only as SHA-256 hash (`deploy_settings.machine_report_token_hash`).
- Heartbeats and client phase events are never duplicated into `deploy_logs`; only throttled `mecm`-category audits (token/flood/sync rejections, state transitions) go there.
- Known accepted gap: the legacy token API (`access.php?action=updateMission`) bypasses the portal mission-name guards; it is an E3 retirement candidate.
