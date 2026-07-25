# VirtuSphere Agent Guide

## Architecture Map

- `Docker/WebAPI/function.php`: legacy aggregate data layer. New portal paths use focused helpers in `Docker/WebAPI/lib/repo/*`; keep existing function names available for machine endpoints during the transition.
- `Docker/WebAPI/portal/`: server-rendered web UI using sessions, CSRF and shared helpers from `lib/`.
- `Docker/WebAPI/lib/`: shared helpers for DB, EnvBoot, headers, CSRF, constants, defaults, status mapping, migrations, auth/permissions, crypto, SSH and deploy work. `layout.php` renders the page chrome; `layout_modals.php` holds the portal's two `<dialog>` modals (confirm, session expiry) and is the only place a modal is built.
- `Docker/WebAPI/lang/` and `Docker/WebAPI/lib/lang.php`: portal i18n catalog and helper. Portal strings use `__t('module.key')` with DE/EN parity.
- Machine API surface: `mecm-api.php`, `mecm_updateid.php`, `mecm_packages.php`, `db_importMAC.php`, `mecm_report.php`. Harden these, but do not remove or silently change their wire contract. `mecm_report.php` (ADR-0018) is display-only telemetry and must never write VM lifecycle state.
- Worker containers: `lib/deploy_worker.php` (`deploy-worker`) runs Ansible deploy jobs; `lib/maintenance_worker.php` (`maintenance-worker`) runs retention purges, the deploy-job reaper/convergence sweep and integration transition audits (no outbound MECM probe; the TCP-445 probe was removed, ADR-0018). Both are `--loop` CLIs that survive MySQL restarts.
- PowerShell integration lives in `Powershell-MECM/` (`mecm/` server scripts, `clients/` client phase scripts, installer). All environment specifics come from the Windows registry, not the code; server scripts report run results and MECM site health, client scripts report install phases, to `mecm_report.php`.
- Legacy desktop token API: `access.php`, `api/login.php`, `deploy_tokens`, `generateToken`, `verifyToken`, `expandToken`. Do not build new flows on it. Physical retirement waits until E3 acceptance.
- `scripts/`: local tooling. `check.ps1` is the executable SSoT of all quality gates (lanes Fast/Integration/Release, ADR-0031); `test-guards.ps1` proves every guard positive/negative/zero-match. Individual checks: `lint-csp-patterns.sh` (pattern scan; `--file`, `--worktree`, `--range`), `lang-audit.php` (DE/EN + placeholder parity), `check-enum-sync.sh`, `check-php-version-sync.sh`, `check-doc-hygiene.sh`, `check-doc-semantics.sh`, `check-bounds-sync.php` (ADR-0016). All honor `VIRTUSPHERE_CHECK_ROOT` and emit stable `[check.case]` diagnostic IDs. Backup/restore is `backup.sh` + `restore_test.sh` (ADR-0017, runbook `docs/operations/backup.md`).
- `.claude/hooks/`: `session-start.sh` runs the drift checks quietly; PostToolUse runs `lint-csp-patterns.sh`, `php-lint.sh` (blocking `php -l`) and `lang-parity.sh` (blocking DE/EN audit on `lang/` edits).

## Hard Rules

Forbidden patterns live only in `GROK.md` section 1 and are not restated here; the rules below are the constructive counterparts and workflow duties.

- Secrets come only from `.env` through `lib/envboot.php`; no default fallback for secret values.
- Use SSoT values from `lib/constants.php`, `lib/defaults.php` and `lib/permissions.php`.
- Render display-only timestamps through `portal_format_timestamp()` in `lib/layout.php` (`d.m.Y H:i:s`); do not echo raw MySQL datetime strings in portal views. Concurrency-check hidden fields (e.g. `updated_at` on `vm_edit.php`/`mission_details.php`) are the exception and must stay raw.
- Solid buttons take their fill/text from the `--btn-bg`/`--btn-fg` theme tokens (`base.css`); do not hardcode `#fff` or an accent color on `.button`. Follow the ADR-0013 design baseline (fill hierarchy, `:focus-visible`, `min-width:0` overflow containment, collapsible mobile nav, system-font stack) for portal UI work.
- A wrapping heading/action row's `gap` spaces only its own children; a following table, grid or card list still needs the shared inter-block gap from a parent stack or a reusable sibling/component rule. For new or changed responsive layout, exercise a viewport that forces the wrap and add Playwright geometry or screenshot coverage for the boundary (ADR-0013).
- Every portal form action is either confirmed with `data-confirm="<__t() question>"` on its submit button, or declared in `SAFE_ACTIONS` (`tests/Static/PortalConfirmContractTest.php`) with the reason it cannot lose anything; a new action fails the build until classified. Add `.button-danger` when the action destroys data. That attribute is all a page adds: the shared `<dialog>` in `lib/layout_modals.php` (rendered by `layout_footer()`) and its `assets/core.js` handler render the prompt, pick the danger variant, validate the form, restore focus and replay the submit via `form.requestSubmit(trigger)` (ADR-0013). Confirm the destructive *branch* of a toggle and omit the attribute otherwise, never render it empty. Any new modal goes into `lib/layout_modals.php` on the `.modal`/`.modal-box` base instead of a second implementation, and inherits its alignment from the shared `.modal-msg`/`.modal-actions` rules rather than restating it (`tests/Static/ModalAxisContractTest.php`).
- A control that reloads the page to re-render server-side data must carry the form it sits in, not only the parameter it changed. The deploy mission select and the job filter both write `mission_id`, and carrying only that emptied every other field of the queue form, because each one re-rendered from its constant default. `deploy.js` builds the query from the live controls (never a field list, and via `form.elements`, since `FormData` drops the disabled-but-filled wait time), and `lib/deploy_form_state.php` picks **one** source per render (the POST it answers, the sticky stash, the query string): a per-field fallback would re-check a box the operator had just cleared, because an absent key is exactly how a checkbox says "off". Pinned by `tests/Static/DeployFormStateContractTest.php` and `deploy-form-state.spec.js`.
- Portal pages that 404 on a missing record should `flash_set()` a localized `__t()` message and `redirect_to()` the parent list, not `exit()` a bare string without layout. JSON/machine endpoints keep their `http_response_code()` + JSON envelope.
- Sortable portal list tables use the `lib/portal_sort.php` helpers (`portal_sort_apply()` + `portal_sort_header()`); do not hand-roll per-page `usort`/sort links. Sort keys are whitelisted, sorting is display-only (no locale, auth or wire impact), and any CSV export link carries the active `sort`/`dir`.
- Deep links into the log viewer go through `log_category_url()` (`lib/repo/log.php`), which derives the tab from `VIRTUSPHERE_LOG_TABS`; do not hand-write `logs.php?category=<x>`. `logs.php` scopes the category filter to the active tab and discards a category the tab does not contain, so a tab-less link lands on the default `security` tab and shows unrelated rows without an error. Pinned by `tests/Static/LogDeepLinkContractTest.php` and `tests/Unit/LogTaxonomyTest.php`.
- PHP target is 8.4 everywhere: Dockerfile, Composer platform, docs, hooks.
- Security headers, CSP and nonces live in `lib/headers.php`; use `virtusphere_csp_nonce()` for any inline script/style.
- Use `virtusphere_is_request_secure()` for Secure cookies and future HSTS behavior.
- Escape HTML with `htmlspecialchars`; emit JSON with `json_encode`. Do not mix the two.
- New or changed portal-visible text goes through `__t()` and keeps DE/EN catalog parity. Run `php scripts/lang-audit.php --ci` or the documented container equivalent for portal text, validation or error-message changes.
- Keep machine API contracts intact: exact 5 legacy status strings, `updated` MECM flag, `mecm_id` preservation, MAC import by `(mission_id, vm_name)` after the deploy migration.
- Do not localize or rename machine API fields, MECM/Ansible status strings or legacy token responses for portal UI language work.
- Keep the app air-gap friendly: no CDN, cloud service, telemetry or runtime package download dependency.
- RBAC uses `can($permission)` from `lib/auth.php`. Do not hand-roll role checks in pages.

## Endpoint Map

- `access.php?action=...`: legacy token API for the desktop client, no new dependencies.
- `api/login.php`: legacy token login, no new dependencies.
- `mecm-api.php`: MECM read surface, keeps `getDeviceList`, `getMissionName`, `getDeviceInfos&mac=...`.
- `mecm_updateid.php`: accepts `deviceResourceID` and `deviceid`.
- `mecm_packages.php`: package/task sequence sync.
- `db_importMAC.php`: Ansible MAC import; target payload becomes `{ "mission_id": 123, "results": [...] }`.
- `mecm_report.php`: report channel (ADR-0018), POST-only; `action=reportPhase` (client phase events by MAC), `action=heartbeat` (legacy sync-loop heartbeats) and `action=reportRun` (additive: per-run `started`/`completed` results from the three sync tasks plus `completed`-only `mecm-site-health` from `SMS_SummarizerSiteStatus`, 0=ok/1=warning/2=critical/else unknown). Display-only: `last_event` drives the badge, arrival order is truth (sequential client, dedup only on an identical completed `run_id`), provider faults are grey and red is reserved for MECM-confirmed status 2; migration 0025 adds columns additively with no backfill. Optional `X-VirtuSphere-Token` header (heartbeat/reportRun), checked only when a token hash is configured.
