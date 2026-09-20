# VirtuSphere architecture contracts

Read the sections relevant to the current change. Paths below are relative to Docker/WebAPI unless they already name a repository directory. Forbidden patterns remain in GROK.md section 1; these are the constructive implementation contracts.

## A5 Docker/WebAPI/function.php: legacy aggregate data layer. New portal paths use focused helpers i

- `Docker/WebAPI/function.php`: legacy aggregate data layer. New portal paths use focused helpers in `Docker/WebAPI/lib/repo/*`; keep existing function names available for machine endpoints during the transition. `lib/repo/vms.php` is the compatibility facade for the registered validation, persistence, operations and legacy VM modules.

## A6 Docker/WebAPI/portal/: server-rendered web UI using sessions, CSRF and shared helpers from lib/

- `Docker/WebAPI/portal/`: server-rendered web UI using sessions, CSRF and shared helpers from `lib/`. The large deploy, settings and VM-editor routes are thin Auth/RBAC/request shells: deploy actions, view model, queue/job panels and blockers live in focused `lib/deploy_*` modules; settings actions/view model live in `lib/settings_*.php` and its tab renderers in `lib/settings/`; VM editor state and rendering live in the registered `lib/vm_edit_*` modules.

## A7 Docker/WebAPI/lib/: shared helpers for DB, EnvBoot, headers, CSRF, constants, defaults, status

- `Docker/WebAPI/lib/`: shared helpers for DB, EnvBoot, headers, CSRF, constants, defaults, status mapping, migrations, auth/permissions, crypto, SSH and deploy work. `layout.php` renders the page chrome; `layout_modals.php` holds the portal's two `<dialog>` modals (confirm, session expiry) and is the only place a modal is built.

## A8 Docker/WebAPI/lang/ and Docker/WebAPI/lib/lang.php: portal i18n catalog and helper. Portal stri

- `Docker/WebAPI/lang/` and `Docker/WebAPI/lib/lang.php`: portal i18n catalog and helper. Portal strings use `__t('module.key')` with DE/EN parity.

## A9 Machine API surface: mecm-api.php, mecm_client_ack.php, mecm_updateid.php, mecm_packages.php, d

- Machine API surface: `mecm-api.php`, `mecm_client_ack.php`, `mecm_updateid.php`, `mecm_packages.php`, `db_importMAC.php`, `mecm_report.php`. Harden these, but do not remove or silently change their wire contract. `mecm_report.php` (ADR-0018) is display-only telemetry and must never write VM lifecycle state; the POST-only client-ready ACK owns the 5/5 transition (ADR-0019).

## A10 Worker containers: lib/deploy_worker.php (deploy-worker) runs Ansible deploy jobs; lib/maintena

- Worker containers: `lib/deploy_worker.php` (`deploy-worker`) runs Ansible deploy jobs; `lib/maintenance_worker.php` (`maintenance-worker`) runs retention purges, the deploy-job reaper/convergence sweep and integration transition audits (no outbound MECM probe; the TCP-445 probe was removed, ADR-0018). Both are `--loop` CLIs that survive MySQL restarts.

## A11 PowerShell integration lives in Powershell-MECM/ (mecm/ server scripts, clients/ client phase s

- PowerShell integration lives in `Powershell-MECM/` (`mecm/` server scripts, `clients/` client phase scripts, installer). All environment specifics come from the Windows registry, not the code; server scripts report run results and MECM site health, client scripts report install phases, to `mecm_report.php`. Server and client logging remain separate shipped modules with one mirrored versioned contract; Common facades hard-fail on missing/version-wrong modules, installers stage and hash the full package, sink failures stay locally throttled and non-fatal, and a correlation ID is only an additive diagnostic header that never changes JSON or creates extra report/audit traffic.

## A12 The former desktop token API is removed (ADR-0035); its paths answer 404 by contract. Do not re

- The former desktop token API is removed (ADR-0035); its paths answer 404 by contract. Do not reintroduce token-based machine auth; machine access is the IP allowlist plus the optional report-channel token.

## A13 scripts/: local tooling. check.ps1 is the only public runner and executable SSoT of all quality

- `scripts/`: local tooling. `check.ps1` is the only public runner and executable SSoT of all quality gates (lanes Fast/Integration/Release, ADR-0031); focused modules under `scripts/lib/check/` define functions without import side effects. `test-guards.ps1` proves every guard positive/negative/zero-match. Individual checks: `lint-csp-patterns.sh` (pattern scan; `--file`, `--worktree`, `--range`), `lang-audit.php` (DE/EN + placeholder parity), `check-enum-sync.sh`, `check-php-version-sync.sh`, `check-doc-hygiene.sh`, `check-doc-semantics.sh`, `check-bounds-sync.php` (ADR-0016), `check-file-size.php` (ADR-0006 budget; `FILE_SIZE_ALLOWANCES` records every oversize file with its exact size, reason and teardown stage, and rejects growth as well as a stale entry). All honor `VIRTUSPHERE_CHECK_ROOT` and emit stable `[check.case]` diagnostic IDs. Backup/restore is `backup.sh` + `restore_test.sh` (ADR-0017, runbook `docs/operations/backup.md`).

## A14 .claude/hooks/: session-start.sh runs the drift checks quietly; PostToolUse runs lint-csp-patte

- `.claude/hooks/`: `session-start.sh` runs the drift checks quietly; PostToolUse runs `lint-csp-patterns.sh`, `php-lint.sh` (blocking `php -l`) and `lang-parity.sh` (blocking DE/EN audit on `lang/` edits).
