# QA Baseline

This page is the operating manual for the VirtuSphere QA battery: how to run each check and how to debug a red one locally. What a gate means and how to interpret its result lives in `docs/QUALITY-GATES.md`; the decisions behind the setup are ADR-0015 (baseline and skip policy), ADR-0028 (E2E tiers) and ADR-0031 (runner). It is intentionally container-first so checks work the same way on Windows hosts and in air-gapped LAN environments once Docker images and Composer vendor artifacts are present.

## Canonical Check Runner

`scripts/check.ps1` is the executable SSoT of all quality gates (ADR-0031). It runs under Windows PowerShell 5.1 and PowerShell 7 and replaces "run these commands in order" lists; the commands below stay documented for targeted debugging of a single gate.

```powershell
powershell -NoProfile -File scripts\check.ps1 -List                 # Gates der Fast-Lane anzeigen
powershell -NoProfile -File scripts\check.ps1                       # Fast-Lane (jeder PR / lokaler Vorabcheck)
powershell -NoProfile -File scripts\check.ps1 -Lane Integration     # Merge/Nightly-Gates (baut eigenen QA-Stack)
powershell -NoProfile -File scripts\check.ps1 -Gate enum-sync,phpstan   # gezielte Teilmenge
powershell -NoProfile -File scripts\check.ps1 -Json qa.json -KeepArtifacts
```

Every gate reports `pass`, `fail`, `skip`, `not_applicable` or `infrastructure_error`; a missing tool is an infrastructure error, never a skip. Runner exit codes: `0` all gates green, `1` at least one gate failed (dominates), `2` environment incomplete, `3` invalid invocation. Containerized linters (yamllint, actionlint, ShellCheck, Hadolint, ansible-lint) run through Docker; on Windows the Fast lane therefore requires Docker. `-NoNetwork` marks network-dependent gates (`composer-audit`, `secret-scan`, the AP8 supply-chain gates) as not applicable and never pulls images.

Tool versions are pinned in `scripts/tool-lock.json` (AP4): registry images by digest, PowerShell modules by exact version. The runner refuses to start without a valid lock file (exit 2). The Ansible gates (`ansible-syntax`, `ansible-lint`, `yaml-roundtrip`) run against the locally built `virtusphere-qa-ansible` image; build it once with `docker build -f Docker/qa-ansible/Dockerfile -t virtusphere-qa-ansible:latest .` (pip pins with hashes in `Docker/qa-ansible/requirements.txt`, collection pin from `Ansible/requirements.yml`).

The `yaml-roundtrip` gate (AP5) is the semantic complement to the substring pins in PHPUnit: `Docker/WebAPI/tests/tools/render-golden-serverlist.php` renders the hostile golden mission (`tests/fixtures/golden-mission.json` — Norway tokens, control bytes, unicode, overrides, autostart clamps) through the production generators, and `Ansible/tests/roundtrip_verify.py` loads the result with PyYAML (Ansible's YAML 1.1 semantics) and deep-compares it against the fixture's `expected` contract. Change the generator and the `expected` block must move with it — that forced conversation is the point. `AnsiblePlaybookHygieneContractTest` pins the playbook side: `ignore_errors` only registered and allowlisted, command tasks classified, the ESXi password never outside the no_log module argument, credential files at mode 0600. Still open for the Release lane / real staging (rollout step 10): a second idempotence run against a real ESXi host.

`scripts/test-guards.ps1` proves the guards themselves: every check runs once against the real repo (green), once against a mutated fixture copy (must turn red with the right `[check.case]` diagnostic ID) and once against a zero-match root (must not pass silently). Fixtures are wired through `VIRTUSPHERE_CHECK_ROOT`; the repo is never mutated. Exit codes: `0` all proven, `1` a guard failed to detect its mutation, `2` only infrastructure gaps (e.g. no host PHP for the php-lint hook case).

### Container hardening and supply chain (AP8)

The `compose-hardening` gate (all lanes) runs `scripts/check-compose-hardening.ps1`: it parses the resolved `docker compose --profile "*" config` semantically and pins the existing hardening as a contract — `read_only`+tmpfs, `cap_drop: ALL` plus the exact documented `cap_add` sets, `no-new-privileges`, PID/memory limits, restart policy, healthchecks, `service_healthy` start ordering, phpMyAdmin's `tools` profile and loopback binding, tag+digest pins on registry images, digest pins in every first-party `FROM`/`COPY --from`, and the absence of any Docker socket mount. Any loosening fails the build with a stable `[compose.<case>]` ID; the resolved config JSON carries interpolated secrets and is never printed or stored.

The Release lane adds the supply-chain gates. `sbom` writes an SPDX SBOM per runtime image; `image-cve` scans each image with the digest-pinned trivy from `scripts/tool-lock.json`. Its documented policy: the full report (including unfixable findings) goes to the QA artifacts, but the gate only **blocks** on Critical/High findings that have a fix available (`--ignore-unfixed`) — a gate that is permanently red on Debian `will_not_fix` entries guards nothing. Exceptions live in `.trivyignore.yaml` and are only valid with CVE ID, justification, owner and an `expired_at` date; trivy re-reports expired entries automatically, so an expired exception breaks the build exactly like a missing one. `npm-audit` is the composer-audit counterpart for the dev-host e2e tooling (`tests/e2e`), blocking at `high`.

`offline-bundle` (Release lane) runs `scripts/build-offline-bundle.sh`: it saves the runtime images, builds `vendor.tar.gz` (`composer install --no-dev` inside the PHP image), downloads the Ansible collections for the air-gapped control node, produces SBOMs and CVE reports, snapshots the source (`git archive`), writes `provenance.json`, `INSTALL.md` and a `SHA256SUMS` manifest, and then verifies itself with the bundled `verify.sh` — which needs only `sha256sum` and no network, matching how the target system verifies it offline.

### Integration lane and the QA stack

The Integration lane provisions its own throwaway stack as its first gate (`qa-stack`): a separate Compose project `virtusphere-qa` built from `docker-compose.yml` plus `Docker/qa/docker-compose.qa.yml` with `Docker/qa/qa.env` (committed throwaway values, no secrets). The database lives in a project-scoped volume seeded fresh from `struktur.sql`, `ssl/` and `conf.d/` are project volumes, and the web port is `127.0.0.1:8031`; nothing in the lane ever touches the dev stack or the dev database. The gate then applies migrations, seeds the QA admin from `qa.env` and waits for portal health. `check.ps1` tears the stack down (`down -v`) when the run ends; `-KeepArtifacts` leaves it up for debugging.

Against that stack the lane runs `migrate-check`, the **full** PHPUnit suite with `--fail-on-skipped` (a dynamic skip is never legitimate here; tests that need an allowlisted or non-allowlisted client IP arrange it themselves via `tests/Integration/ClientIpAllowlist.php` and restore the previous state), `schema-convergence`, the health/exposure contract, the guard harness, and `e2e-portal`: the Playwright Chromium suite from `tests/e2e/` (ADR-0028 revision). The browser resolves from `PLAYWRIGHT_CHROMIUM`, the local dev default, or the Playwright cache (`npx playwright install chromium`); `npm ci` runs automatically when `tests/e2e/node_modules` is missing. The windows-only `legacy-csharp-build` gate reports `not_applicable` off Windows and runs as a dedicated Windows job in CI.

## Test Commands

Run PHPUnit inside the PHP container:

```powershell
docker exec virtusphere-v2-webapp-php-1 composer --working-dir=/var/www/html test
```

Run PHPStan (level 5, baseline ratchet per ADR-0015) inside the PHP container:

```powershell
docker exec virtusphere-v2-webapp-php-1 composer --working-dir=/var/www/html run stan
```

Run the stdlib-only MAC upload client tests in an isolated Python container:

```powershell
docker run --rm -v C:\projekte\VirtuSphere-v2-WebApp:/repo:ro -w /repo python:3.13-alpine python -m unittest discover -s Ansible/tests -v
```

Run the language catalog parity audit from the project image:

```powershell
docker run --rm -v C:\projekte\VirtuSphere-v2-WebApp:/repo -w /repo virtusphere-v2-webapp-php php scripts/lang-audit.php --ci
```

Run the migration preflight without mutating the database:

```powershell
docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php --check
```

Check nginx and the live health/test exposure contract:

```powershell
docker exec virtusphere-v2-webapp-webserver-1 nginx -t
curl.exe -s -S -i http://127.0.0.1:8021/portal/health.php
curl.exe -s -S -i http://127.0.0.1:8021/tests/bootstrap.php
```

Expected results: PHPUnit and the Python client tests exit green, lang audit reports DE/EN parity clean, migration check ends with `check: ok`, the health endpoint returns HTTP 200, and `/tests/bootstrap.php` returns HTTP 403.

## Continuous Integration (GitHub Actions)

`.github/workflows/ci.yml` runs the **Fast lane of the canonical runner** (`scripts/check.ps1 -Lane Fast`) on every `push` to `main` and on every pull request, instead of maintaining a second step list that could drift from the local gates. The **Integration lane** runs as its own job on every merge to `main`, nightly and on manual dispatch (never on PRs): same setup plus a Playwright Chromium install pinned by `tests/e2e/package-lock.json`, then `scripts/check.ps1 -Lane Integration` with the throwaway QA stack described above; the machine-readable result and, on failure, the Playwright report are uploaded as artifacts. The windows-only `legacy-csharp-build` gate runs as a third job on a Windows runner (vswhere-located MSBuild, NuGet restore into `..\packages` to match the historical HintPaths). Actions are pinned to full commit SHAs, the job has a `timeout-minutes` budget, and the machine-readable lane result (`qa-fast.json`) is uploaded as a build artifact with limited retention. Setup before the lane: PHP 8.4 on the host (so `php -l`/lang-audit lint with the runtime version, not the runner default), Node 20, `.env` from `.env.example` for the compose gate, the project PHP image and the QA Ansible image built from their Dockerfiles, `composer install` inside the project image, and Pester/PSScriptAnalyzer at the exact versions from `scripts/tool-lock.json`.

**No MySQL server** is provisioned: the Fast lane runs the unit + static suites without skips (`--fail-on-skipped`); the full suite including integration tests belongs to the Integration lane, which follows the ADR-0015 amendment and ADR-0028 revision. One step stays outside the lane on purpose: `lint-csp-patterns.sh --range <base> <head>` checks the pushed commit range (the lane's `csp-patterns` gate checks the worktree, which is always clean in CI; locally use `--worktree`).

End-to-end browser tests gate the Integration and Release lanes, never the PR-facing Fast lane (ADR-0028 revision); connected CI may fetch the Playwright tooling while the shipped artifact stays browser- and Node-free. PHPStan findings in analysed files are fixed, not re-baselined; the legacy machine-API root files join the scope after the E3 retirement decision. To confirm the pipeline actually fails on regressions, remove a `__t()` key from one locale and observe the `lang-parity` gate turn red.

## Phase C Regression Coverage

`Docker/WebAPI/tests/Static/PhaseCContractTest.php` locks down the Phase C contracts that are easy to regress during later refactors: debug-gated error details, generic machine-API 500 envelopes, type-scoped MECM package sync, portal permission/admin guards, login `ip_locked` messaging, validator i18n, generic health failures, deploy-worker heartbeat/reaper wiring and terminal-job lock guards.

`Docker/WebAPI/tests/Static/PortalConfirmContractTest.php` locks down the confirmation contract (see below). Like `PortalComboHooksTest`, it pins a markup-to-JavaScript agreement as text, because no compiler or linter checks one.

`Docker/WebAPI/tests/Static/ModalAxisContractTest.php` does the same for the modal layout rules in `components.css` (see below), for the same reason: nothing else in the toolchain reads that stylesheet.

`Docker/WebAPI/tests/Integration/DeployJobReaperTest.php` exercises the real database path for stale running jobs. It creates only `phpunit_phase_c_*` missions, removes them in setup/teardown, and skips if unrelated running deploy jobs exist because the production reaper is intentionally global.

The PHP container mounts `Docker/WebAPI` as `/var/www/html`, so schema baseline files outside that tree are checked from the host when relevant:

```powershell
Select-String -Path Docker\mysql\mysql-init\struktur.sql -Pattern 'deploy_interfaces_mac_lookup'
```

## MECM Report Channel Coverage (reportRun + Site Health)

The additive `action=reportRun` on `mecm_report.php` and the new "VirtuSphere MECM
Site Health" reporter (ADR-0018, Amendment 2026-07-23) are pinned across the three
languages that carry the contract:

- **Wire (`MecmReportWireTest`, PHPUnit).** The legacy `heartbeat` stays byte-exact
  and its response unchanged. `reportRun` covers validation, auth, the allowed
  sources (`device-sync`, `packages-sync`, `autoimporter`, `mecm-site-health`) and
  the `error`-keyed envelope; `started`/`completed` with site health sending only
  `completed`; the idempotent replay of an identical completed `run_id`
  (`200 {deduplicated:true}` with no counter or timestamp change); a completed report
  with a new `run_id` always accepted, never rejected for arrival order; the
  category/outcome binding for site health
  (`site_warning`/`site_critical`/`provider_*`/`query_failed`); the size limit; and
  no VM-lifecycle write.
- **Status derivation and repository (PHPUnit).** `last_event` drives the badge:
  fresh V2 `ok`/`warning`/`fail`/`unknown`, a fresh V1 heartbeat yellow as Legacy
  (again after a script rollback), the two-clock running run with "läuft seit" and
  stale only after `max(3 × interval, 60 s, RUN_GRACE)`, group-scoped `missing` (site
  health does not make a sync red), and `failure_streak` counting consecutive `fail`
  only.
- **Pester (`VirtuSphere-Common`, `mecm_site-health`).** `Send-VsRunReport` payload,
  header and byte-length truncation before send; token/secret redaction; the pure
  status mapping `0→ok`, `1→warning`, `2→fail`, anything else `→unknown`; provider
  discovery order; `provider_unreachable` only after two consecutive failures; each
  loop turn sends at most one `started` and exactly one `completed`; the installer
  registers exactly four task definitions, all `IgnoreNew`/SYSTEM/AtStartup/`PT0S`.
  Interval resolution: every task reports the cadence it sleeps on (each
  `IntervalSeconds` argument carries the same variable as the sleep, a literal
  fails), `Resolve-VsInterval` clamps to the per-task range and logs the
  correction at WARN, and the installer's `ValidateRange` plus its four parameter
  defaults are pinned against `$script:VsIntervalBounds` and `Get-VsConfig`.
- **Static (`PhaseCContractTest`, `MachineApiPanelContractTest`).** The
  maintenance-worker keeps retention and no longer carries a probe/socket path (this
  replaces the former pin on `maintenance_worker_tcp_check`); the machine-API panel
  has no outbound path, target or port, and the allowlist stays deny-by-default.
- **Playwright (`system-status-ampel.spec.js`, `system-status.spec.js`,
  `settings-flow.spec.js`).** Legacy yellow (and yellow again after V2→V1); V2 green;
  warning yellow; fail immediately red; a running attempt keeps the last result and
  shows "läuft seit"; stale/missing/unknown; site warning/critical; a provider fault
  grey and never labelled site-critical; sync and site health rendered separately;
  the dashboard tile carrying two labelled badges; no probe/retry button; the removed
  probe card/host/port/mode; and the old `save_probe` POST returning 400.

## Portal Confirmation Dialogs

Confirmations are the shared `<dialog>` from `lib/layout_modals.php`, rendered once per page by `layout_footer()` and driven only by `data-confirm` (ADR-0013). The contract is an attribute agreement between markup, that module and the portal scripts (`assets/core.js`, which holds the confirm dialog), so `php -l`, `node --check`, the lang audit and the rest of the suite all stay green while it is broken. `tests/Static/PortalConfirmContractTest.php` closes that gap and runs in the normal `unit` suite. It fails when:

- a postable form action is neither confirmed with `data-confirm` nor declared in `SAFE_ACTIONS` with its reason, or a `SAFE_ACTIONS` entry goes stale or contradicts the markup;
- a `.button-danger` submit ships without `data-confirm`;
- a prompt is a literal instead of a `__t()` key, directly or through one local variable;
- a page hand-rolls `window.confirm()`, `alert()`, a second confirm dialog or any `<dialog>` of its own;
- the accepted click is replayed with `form.submit()` instead of `form.requestSubmit(trigger)`;
- `layout_footer()` stops calling `layout_confirm_dialog()` or `layout_session_modal()`, which would ship a portal whose destructive buttons submit with no prompt at all.

The classification is closed on purpose. An earlier version guessed danger from the action name and so never looked at `generate_token`, which invalidates the token deployed on the MECM server, or `set_role`, by which an admin demotes themselves. Adding an action now fails the build until it is confirmed or declared safe.

That last one is the reason the test exists. `form.submit()` drops the form's submitter, which strips a `name="action"` button's value, so the handler falls through to its default branch: the page redirects, the flash appears, and nothing is deleted. A deliberately unconfirmed action (today only `clear_lock`, a reversible unlock) is listed in `UNCONFIRMED_BY_DESIGN` with its reason.

The static test cannot see behaviour, so after touching `assets/core.js`, `lib/layout_modals.php` or a `data-confirm` call site, still drive one confirmation of each shape in a browser:

1. A button whose action rides on a hidden input (`missions.php` delete) and one whose action rides on the button itself (`os.php`, `credentials.php`, the `vms.php` bulk actions). Accept, then confirm the record is really gone rather than trusting the flash.
2. Escape, backdrop click and the dismiss button each close the dialog without sending a request.
3. A form with a required field (`users.php` password reset) shows its validation bubble instead of opening the dialog.

The accept button takes its label from the trigger, so a new destructive button needs no JavaScript change; only a trigger whose own label would collide with the dialog's "Abbrechen" needs `data-confirm-action`.

## Modal Axis

Where modal content sits is decided in `components.css`, which no linter in this repo reads: `php -l`, `node --check` and the whole unit suite stay green while a dialog drifts off its axis or clips the name it is asking about. `tests/Static/ModalAxisContractTest.php` runs in the normal `unit` suite and parses the stylesheet (brace-depth aware, so `@media` does not swallow the rules nested inside it). It fails when:

- any rule other than `.modal[open]`, `.modal-box`, `.modal-msg` or `.modal-actions` declares `text-align`, `justify-content` or `align-items` on a modal, which is how a per-dialog override gets in;
- one of those four stops making its decision, or makes a different one;
- `.modal-msg` loses `width: fit-content`, `max-width: 100%` or its auto inline margins, any of which leaves `text-align: left` applying to every message so a short question hangs off the axis;
- `.modal-msg` loses `overflow-wrap: anywhere`.

Alignment is derived from the text length rather than restated per dialog. `fit-content` plus auto inline margins let a one-line message shrink to its own width and recentre, while a wrapping one hits `max-width` and keeps its left edge, where the reader needs it. No rule has to guess at the sentence count, and the same rule holds in German and English although the two wrap at different points.

`overflow-wrap` must be `anywhere` and not `break-word`. A confirm question names its target (`:name`) and a target name is user input; `anywhere` also lowers the element's min-content width, and min-content is what `fit-content` resolves against. With `break-word` the longest word stays the min-content width, the block outgrows the box, and only `max-width` catches it, clipping the name mid-token, which is exactly the misread the naming rule exists to prevent.

Each guard was run against a deliberately broken stylesheet, so each is known to *fail* and not merely to pass. Two traps worth knowing if you repeat that: the file is CRLF, so a `\n` in a mutation pattern silently matches nothing, and `overflow-wrap: anywhere` occurs three times in it, so an unanchored substitution breaks a foreign rule and leaves the modal test rightly green.

## Settings Tab Redirects

`settings.php` re-opens the posting form's tab after the POST redirect via a URL fragment (`settings.php#panel-<tab>`, restored by `initTabs` in `assets/core.js`). The `$actionTabs` map next to the action dispatch is the SSoT for which form lives in which tab. `tests/Static/SettingsTabRedirectContractTest.php` runs in the normal `unit` suite and fails when a postable action has no map entry (its redirect would fall back to the first tab, hiding sticky field errors and the one-time report token in a hidden panel), when a map entry names an action no form posts any more, or when a mapped tab has no rendered tabpanel.

## Correlation IDs (ADR-0032)

Every portal error page shows a reference like `error [a1b2c3d4e5f60718]`. That value is the request's correlation id, and the same id sits on the audit rows (`deploy_logs.correlation_id`), on the deploy job the request enqueued (`deploy_jobs.correlation_id`) and on every log line the worker writes for that job (`deploy_job_logs.correlation_id`). To trace an incident, take the id from the screenshot or from any of the three tables and grep the other two; the remote Ansible run sees it as `VS_CORRELATION_ID`, the MAC callback echoes it, and the MECM PowerShell scripts send their own per-run id as `X-VirtuSphere-Correlation`. A retry deliberately starts a new id; the new job's first system line names the old one (`[correlation …]`). The id is diagnostic only and never grants access.

## Drift Checks

Five checks guard SSoT mirrors and doc hygiene. They run quietly on every Claude session start and must be green before commits that touch the mirrored places:

```powershell
sh scripts/check-enum-sync.sh          # PHP-Const-SSoT vs. ENUM in struktur.sql und migrate.php
sh scripts/check-php-version-sync.sh   # Dockerfile-FROM (SSoT) vs. composer.json, constants.php, Docs
sh scripts/check-doc-hygiene.sh        # Changelog-Marker-Verbot + Zeilen-Budgets fuer AGENTS/GROK/CLAUDE/README
sh scripts/check-doc-semantics.sh      # Betriebsdoku behauptet keine veraltbaren Staende (Zahlen, Level, Pfade)
php scripts/check-bounds-sync.php      # keine Konstante als ausgeschriebene Zahl in Portal-Texten
```

`check-doc-semantics` (AP9) polices the operating docs the way `check-bounds-sync` polices portal texts: `PRE-SHIP-CHECKLIST.md` must stay an empty template (no `[x]`, no dated evidence), no active doc may hardcode test/migration counts or load metrics, and PHPStan-level, MySQL- and Node-version mentions must match their SSoT (`phpstan.neon.dist`, `docker-compose.yml`, `ci.yml`). Retired backup paths may only appear next to a retirement marker. Historical documents (`docs/audits/`, `docs/CHANGELOG.md`, ADRs) are exempt: they describe a dated state on purpose.

Five more rules cover failure classes that were each found the hard way. A file path claimed in backticks needs a producer outside `docs/` (the go-live runbook sent the admin to an initial-password file nothing ever writes). Every `.env.example` key that compose interpolates without a default must be named in the go-live runbook, or nobody can set it without reading the compose file. A migration *range* spanning from the first migration to the current one is as stale as a count, even though both ends look like legitimate references, which is why this sentence cannot show you one. German documents write real umlauts, the same rule the portal catalog follows, with code spans and fenced blocks excluded because an ASCII identifier in backticks is a quoted value (`Uebersprungen` is a string a PowerShell script really compares against); `docs/INSTALLATION-ANLEITUNG.md` carries a named exemption until its own umlaut pass lands. And the hardware version in `createVMs-ESXi_playbook.yml` is checked against the ESXi support matrix: vmx-21 needs 8.0 Update 2, so a matrix that promises 7.0 for *creating* VMs promises a hard failure.

`check-bounds-sync` guards a failure that is quiet by construction: the code keeps working and only the prose starts lying, so no test notices. A text that states a number followed by a unit must interpolate the constant that owns it (`:min`, `:days`, …) instead of writing the digits. It matches on value **and** unit, because the stale timeout is 600 seconds, which is also 10 minutes, and "10 Prozent" in the backup hint is not that; a check that cries wolf is a check that gets ignored. Numbers the project does not own (the NetBIOS 15, a VARCHAR width, the MECM sync cadence configured on the MECM server) are listed in `BOUNDS_EXEMPT` with the reason, and a stale exemption fails the check too.

## Backup and Restore Proof

```powershell
sh scripts/backup.sh        # DB- und Config-Backup nach Docker/backups/
sh scripts/restore_test.sh  # Restore-Probe in Wegwerf-Container
```

See `docs/operations/backup.md` for the runbook and `PRE-SHIP-CHECKLIST.md` for when these are mandatory.

## Schema Convergence Proof

`struktur.sql` (the fresh-install schema, mounted into `docker-entrypoint-initdb.d`) and `lib/migrate.php` (incremental delta migrations on top of that base) must converge to the same shape, and `struktur.sql` must load standalone on an empty volume:

```bash
sh scripts/check-schema-convergence.sh
```

The script builds one throwaway DB from `struktur.sql` alone and one from `struktur.sql` + all migrations, then diffs the `--no-data` dumps (AUTO_INCREMENT stripped). Before the migration run it reconstructs the schema directly preceding migrations 0019/0020 and seeds the default-interface edge cases: materializable VM, empty WDS VLAN, template, and an already stored interface. It asserts the JSON column, exact backfill, named skip report, untouched rows, and a forced second run of migration 0020. The script is mandatory after any schema change touching either side. The migrations are deltas on the `struktur.sql` base, not a from-empty rebuild, so "build DB from migrations on an empty DB" is not a valid check; this script is. On Git Bash it exports `MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'` itself, because Git Bash otherwise mangles the container-absolute paths.

## Concurrency and HTTPS Integration Tests

These live in `Docker/WebAPI/tests/Integration/` and need a running MySQL, so they skip in CI (no DB) and run against the dev stack:

- `DeployEnqueueRaceTest` pins the one-active-job-per-mission guard under two overlapping enqueues. It reproduces the interleaving deterministically with two connections (session A pins its snapshot, session B commits, A enqueues inside the old snapshot), so it cannot flake on timing. Both enqueue paths (single job and staggered group) are covered.
- `RepoTransactionReentrancyTest` proves `repo_transaction()` re-entrancy against the live server: nested calls commit exactly once (observed through a second connection, i.e. what is actually committed), an inner failure rolls back the outer work, and the depth tracker recovers after an exception.
- `DeployJobReaperTest` (Phase C) exercises the stale-job reaper on the real DB path.
- `HttpsConfigTest` pins the WP7 HTTPS admin flow, including that the redirect only fires while the generated listener config exists (the boot-quarantine lockout guard) and that the HTTP and generated HTTPS server blocks keep the same deny rules and fallback security headers. It reads `Docker/nginx/default.conf`, which is not mounted into the PHP container, so run it against a repo checkout:

```powershell
docker run --rm -v C:\projekte\VirtuSphere-v2-WebApp:/repo -w /repo/Docker/WebAPI virtusphere-v2-webapp-php ./vendor/bin/phpunit --filter HttpsConfig
```

## Browser E2E (dev-host only)

A Playwright layer under `tests/e2e/` (ADR-0028). Dev-host tooling: `node_modules` is git-ignored and nothing is mounted into the containers. The same suite runs in three contexts: on demand against the local dev stack (this section), as the `e2e-portal` gate of the Integration lane (Chromium), and as the `e2e-browser-matrix`/`e2e-msedge` gates of the Release lane (Firefox, WebKit, Windows Edge) against the throwaway QA stack (ADR-0028 revision); the PR-facing Fast lane stays browser-free. `npm test` stays pinned to Chromium for the dev loop; `npm run test:matrix` runs the other engines after a one-time `npx playwright install firefox webkit` (Edge resolves through the installed browser).

```bash
cd tests/e2e
PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install   # once
npm test                                          # all specs
npx playwright test accessibility                 # one spec
npm run report                                    # last HTML report
```

The `setup` project seeds an `e2e_user` account through the PHP container and caches a `storageState` per role; specs reuse those sessions. Fixtures are prefixed (`e2e*`) and cleaned in setup/teardown, the same discipline the PHPUnit integration suite follows. What it covers:

- `health-matrix.spec.js` — every page x {light, dark} x {admin, user}: right access outcome, no PHP error in the HTML, no console error or CSP violation, **no request off `127.0.0.1`** (the air-gap proof), no untranslated key.
- `field-roundtrip.spec.js` — a hostile-value matrix (XSS payload, attribute breakout, HTML/SQL metachars, umlauts, 4-byte emoji) through the real form: byte-exact in MySQL with no entity in storage, escaped in every render context, and a dialog handler fails the test if a script ever executes.
- `crud-mission.spec.js` / `crud-negative.spec.js` — the entity lifecycle verified through state, plus the reference guard (deleting a credential an active job holds must refuse in the operator's language, without a 500 and without a partial cascade).
- `accessibility.spec.js` — axe-core, WCAG 2.1/2.2 A+AA, every page in both themes.
- `system-status-ampel.spec.js` — the statements the status page makes about its own traffic lights: the legend explains every state the page can render and lists **the same** heartbeat states as the help panel (compared across the two rendered pages, which is the only place that drift is visible), an action hint appears on a broken row and not on a healthy one, an unconnected MECM names the setup step once instead of repeating repair hints, and Dashboard and System status render the two separated MECM badges (Integration, MECM-Site) from the same health snapshot, a provider fault shown grey and never labelled site-critical. Drives result and site-health states through seeded rows and hands the table back intact.

Two traps worth knowing before writing a spec: the layout header carries a logout form whose submit is the **first** on every page, so a `.first()` submit selector logs you out instead of saving; and the real deploy worker polls the same database, so a fixture job seeded as `queued` gets claimed and finished mid-test (seed it `running` with a fresh heartbeat when a test depends on it staying active).

## PowerShell Integration Clients (dev host + CI)

The `Powershell-MECM/` tree runs as SYSTEM in endless loops on the customer's MECM server and on every freshly PXE-installed client. Until 2026-07 nothing checked it: no linter, no test, no CI, no `Set-StrictMode`. It now has all four (ADR-0029).

```powershell
powershell -NoProfile -File scripts\run-pester.ps1              # analyzer + tests
powershell -NoProfile -File scripts\run-pester.ps1 -SkipTests   # analyzer only
```

The modules are dev-host tooling and are **not vendored** (same rule as Playwright and Infection). Install once:

```powershell
Install-Module Pester -MinimumVersion 5.5.0 -Scope CurrentUser -Force -SkipPublisherCheck
Install-Module PSScriptAnalyzer -Scope CurrentUser -Force
```

Windows ships Pester **3.4** in the box, whose syntax is incompatible; the script refuses to run against it and says so. If `Install-Module` fails with a NuGet provider error, force TLS 1.2 first (`[Net.ServicePointManager]::SecurityProtocol = 'Tls12'`) — Windows PowerShell 5.1 still negotiates TLS 1.0 by default and the gallery no longer accepts it.

This one **does run in CI**, twice (AP5): under `pwsh` on `ubuntu-latest` in the Fast lane, and under real `powershell.exe` 5.1 in the `windows-powershell-51` job — the engine the scripts run on in production. Only the Windows job executes the registry-backed error-path tests (`VirtuSphere.ErrorPaths.Tests.ps1`: lost/broken registry values, address chain, scheme override) and enforces the coverage ratchet over the Common/Packaging files (`pesterCoverageFloorPercent` in `scripts/tool-lock.json`; the floor only ever rises). PSScriptAnalyzer additionally runs the compatibility rules (syntax 5.1+7.0, commands/types against the Server-2019/5.1 profile), so a cmdlet or .NET type that 5.1 does not know fails the build instead of failing at night as SYSTEM. Still manual by design (Release lane / staging, rollout step 10): SYSTEM smoke in a throwaway Windows VM, the installer lifecycle on a real host, and an MECM staging acceptance.

### The MAC canonicalization is a cross-language contract

`Docker/WebAPI/tests/fixtures/mac-vectors.json` is the shared source of truth for three implementations that cannot share a file, because they are deployed to three different machines: `virtusphere_normalize_mac()` (PHP), and the two `ConvertTo-VsNormalizedMac` twins (MECM server, deploy client). PHPUnit's `MacNormalizeTest` and Pester's `VirtuSphere.Common.Tests.ps1` both read that table, and a further Pester test asserts the two PowerShell twins stay textually identical.

Change the canonicalization in one place and a build fails. That is the point: this seam already produced a P1 once (finding 2.2 in `docs/audits/2026-07-hardening.md` — a MAC stored in the wrong notation makes a VM invisible to MECM, with no error anywhere).

`PSAvoidUsingEmptyCatchBlock` stays enabled on purpose; a silent failure is exactly this code's failure mode. Empty catches carry a `Write-Debug` line, so a `-Debug` run shows what was swallowed. Three rules are excluded, each with its reason in `PSScriptAnalyzerSettings.psd1`.

## Session Hardening

`Docker/php/conf.d/zz-virtusphere.ini` carries the session settings, and `tests/Static/SessionHardeningContractTest.php` pins them. The one number the application owns — `session.gc_maxlifetime` — must stay at or above `VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MAX * 60`, because an `.ini` cannot interpolate a PHP constant and a mirror drifts.

It drifted on the default: `gc_maxlifetime` was 1440 s (24 min) while the settings card promised sessions up to 480 min. PHP's garbage collector was free to delete the session file of an operator the portal still considered signed in, and because the GC fires probabilistically on *other people's* requests, the logout happened at a random moment nobody could reproduce.

The file is **COPYed at image build time**, not bind-mounted:

```powershell
docker compose build php ; docker compose up -d php
docker exec virtusphere-v2-webapp-php-1 php -r "echo ini_get('session.gc_maxlifetime');"
```

A `docker restart` alone changes nothing, which makes a wrong `.ini` easy to believe you have fixed.

## Mutation Testing (dev-host only)

Infection answers what coverage cannot: whether a test actually pins the behaviour it names, or would still pass if the code were subtly wrong. It is dev-host tooling, on the same footing as the Playwright layer (ADR-0028): **not vendored** into `vendor/`, **not in CI**, and it needs a coverage driver (pcov or xdebug) that the air-gapped runtime image deliberately does not carry. Config lives in `Docker/WebAPI/infection.json5.dist`.

Run it on a networked dev host with coverage enabled:

```bash
# once, outside the tracked vendor/ (its tree is large and dev-only):
curl -sSLO https://github.com/infection/infection/releases/download/0.29.14/infection.phar   # pin the version
# then, from Docker/WebAPI, against the fast suites and a targeted file:
php -d pcov.enabled=1 infection.phar --configuration=infection.json5.dist --filter=validate.php
```

Target the files where the payoff is highest with `--filter` rather than mutating all of `lib`: the pure-logic ones with dense unit tests, where a surviving mutant means a test asserts too little. Highest value first: `validate.php` (the validation matrix), `ansible_yaml.php` (the deploy-YAML escaper), `permissions.php`, `password_policy.php`, `catalog.php` (status normalization). A surviving mutant is a to-do for the test, not for the code: strengthen the assertion that let the mutant live. Raise the `minMsi`/`minCoveredMsi` floors as tests are hardened; never lower them to make a run pass.

The baseline run is deferred to a dev host on purpose: this stack is air-gapped and carries no coverage driver, which is exactly what Infection needs, so it cannot run here.

## Load Probe (dev-host only)

`tests/load/` holds the k6 profiles (campaign reference: TESTPLAN 4.7), dev-host tooling on the same footing as the E2E and mutation layers: not vendored, not in CI. `portal-read.js` has each VU sign in as its own operator and poll the pages an operator sits on; `portal-write.js` drives the write path and refuses to run without an explicit throwaway-stack opt-in, because a load test must never mutate real rows. k6 runs from a container sharing the web server's network namespace. Commands, thresholds and the recorded baseline live **only** in `tests/load/README.md`; this page deliberately repeats none of those numbers. Run the probes if the volume behaviour of the list pages is ever in question, not on every change.

## Hook Scan

The Claude hook delegates to the project script:

```powershell
docker run --rm -v C:\projekte\VirtuSphere-v2-WebApp:/repo -w /repo virtusphere-v2-webapp-php sh scripts/lint-csp-patterns.sh --all-changed
```

`BLOCK:` findings are release blockers. `WARN:` findings are cleanup signals and can remain when they document legacy or staged refactor work. Vendor paths are excluded from the hook's project-code checks.

## Git Hygiene

Run whitespace checks for first-party files before commit:

```powershell
git diff --check -- ':!Docker/WebAPI/vendor/**'
```

Vendored Composer packages can contain upstream trailing whitespace or blank EOF lines. Do not rewrite vendor files just to satisfy whitespace checks; keep them as Composer installed them.

## Vendored Dev Dependencies

This repository already tracks `Docker/WebAPI/vendor` and the application must stay usable in air-gapped environments. When PHPUnit is updated through Composer, commit the matching `composer.json`, `composer.lock`, `vendor/composer/*` metadata and new `vendor/*` package directories together.

Do not commit runtime logs, `.env`, MySQL data, `Docker/WebAPI/var/`, `Docker/WebAPI/.phpunit.result.cache` or other generated local state.
