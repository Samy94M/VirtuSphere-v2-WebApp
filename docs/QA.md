# QA Baseline

Date: 2026-07-07

This page documents the lightweight local QA baseline introduced with ADR-0015. It is intentionally container-first so checks work the same way on Windows hosts and in air-gapped LAN environments once Docker images and Composer vendor artifacts are present.

## Test Commands

Run PHPUnit inside the PHP container:

```powershell
docker exec virtusphere-v2-webapp-php-1 composer --working-dir=/var/www/html test
```

Run PHPStan (level 4, baseline ratchet per ADR-0015) inside the PHP container:

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

`.github/workflows/ci.yml` runs the same check-set as this page on every `push` to `main` and on every pull request. It uses `shivammathur/setup-php` (PHP 8.4, `mysqli` + `sodium`) and Node 20; **no MySQL server** is provisioned, so the integration suite skips itself and only the unit + static suites run. Steps, in order:

1. `php -l` over every first-party `Docker/WebAPI/**/*.php` (vendor excluded).
2. `composer install` then `composer run test:unit` (unit + static suites).
3. `composer run stan` (PHPStan level 4 over `lib`/`portal`/`tests`, baseline ratchet per ADR-0015).
4. `php scripts/lang-audit.php --ci` (DE/EN parity).
5. `node --check` over each `Docker/WebAPI/portal/assets/*.js` (`core.js`, `forms.js`, `deploy.js`).
6. `check-enum-sync.sh`, `check-php-version-sync.sh`, `check-doc-hygiene.sh` (SSoT + doc drift).
7. `lint-csp-patterns.sh --file` over the PHP files changed in the push/PR (same changed-file scope as the local hook).

End-to-end browser tests stay out of this baseline; they are re-evaluated at the E3 milestone (ADR-0015). PHPStan findings in analysed files are fixed, not re-baselined; the legacy machine-API root files join the scope after the E3 retirement decision. To confirm the pipeline actually fails on regressions, remove a `__t()` key from one locale and observe the lang-audit step turn red.

## Phase C Regression Coverage

`Docker/WebAPI/tests/Static/PhaseCContractTest.php` locks down the Phase C contracts that are easy to regress during later refactors: debug-gated error details, generic machine-API 500 envelopes, type-scoped MECM package sync, portal permission/admin guards, login `ip_locked` messaging, validator i18n, generic health failures, deploy-worker heartbeat/reaper wiring and terminal-job lock guards.

`Docker/WebAPI/tests/Static/PortalConfirmContractTest.php` locks down the confirmation contract (see below). Like `PortalComboHooksTest`, it pins a markup-to-JavaScript agreement as text, because no compiler or linter checks one.

`Docker/WebAPI/tests/Static/ModalAxisContractTest.php` does the same for the modal layout rules in `components.css` (see below), for the same reason: nothing else in the toolchain reads that stylesheet.

`Docker/WebAPI/tests/Integration/DeployJobReaperTest.php` exercises the real database path for stale running jobs. It creates only `phpunit_phase_c_*` missions, removes them in setup/teardown, and skips if unrelated running deploy jobs exist because the production reaper is intentionally global.

The PHP container mounts `Docker/WebAPI` as `/var/www/html`, so schema baseline files outside that tree are checked from the host when relevant:

```powershell
Select-String -Path Docker\mysql\mysql-init\struktur.sql -Pattern 'deploy_interfaces_mac_lookup'
```

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

## Drift Checks

Four checks guard SSoT mirrors and doc hygiene. They run quietly on every Claude session start and must be green before commits that touch the mirrored places:

```powershell
sh scripts/check-enum-sync.sh          # PHP-Const-SSoT vs. ENUM in struktur.sql und migrate.php
sh scripts/check-php-version-sync.sh   # Dockerfile-FROM (SSoT) vs. composer.json, constants.php, Docs
sh scripts/check-doc-hygiene.sh        # Changelog-Marker-Verbot + Zeilen-Budgets fuer AGENTS/GROK/CLAUDE/README
php scripts/check-bounds-sync.php      # keine Konstante als ausgeschriebene Zahl in Portal-Texten
```

`check-bounds-sync` guards a failure that is quiet by construction: the code keeps working and only the prose starts lying, so no test notices. A text that states a number followed by a unit must interpolate the constant that owns it (`:min`, `:days`, …) instead of writing the digits. It matches on value **and** unit, because the stale timeout is 600 seconds, which is also 10 minutes, and "10 Prozent" in the backup hint is not that; a check that cries wolf is a check that gets ignored. Numbers the project does not own (the NetBIOS 15, a VARCHAR width, the MECM sync cadence configured on the SCCM server) are listed in `BOUNDS_EXEMPT` with the reason, and a stale exemption fails the check too.

## Backup and Restore Proof

```powershell
sh scripts/backup.sh        # DB- und Config-Backup nach Docker/backups/
sh scripts/restore_test.sh  # Restore-Probe in Wegwerf-Container
```

See `docs/operations/backup.md` for the runbook and `PRE-SHIP-CHECKLIST.md` for when these are mandatory.

## Schema Convergence Proof

`struktur.sql` (the fresh-install schema, mounted into `docker-entrypoint-initdb.d`) and `lib/migrate.php` (17+ incremental delta migrations on top of that base) must converge to the same shape, and `struktur.sql` must load standalone on an empty volume:

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

A Playwright layer under `tests/e2e/` (ADR-0028). Dev-host tooling: `node_modules` is git-ignored, nothing is mounted into the containers, and it does **not** run in CI (no MySQL, no browser there). Run it against the running stack before a release, not on every commit.

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

Two traps worth knowing before writing a spec: the layout header carries a logout form whose submit is the **first** on every page, so a `.first()` submit selector logs you out instead of saving; and the real deploy worker polls the same database, so a fixture job seeded as `queued` gets claimed and finished mid-test (seed it `running` with a fresh heartbeat when a test depends on it staying active).

## PowerShell Integration Clients (dev host + CI)

The `Powershell-MECM/` tree runs as SYSTEM in endless loops on the customer's SCCM server and on every freshly PXE-installed client. Until 2026-07 nothing checked it: no linter, no test, no CI, no `Set-StrictMode`. It now has all four (ADR-0029).

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

This one **does run in CI**: `pwsh` is preinstalled on `ubuntu-latest`, so the job costs about a minute and covers code that runs with SYSTEM rights at the customer's site.

### The MAC canonicalization is a cross-language contract

`Docker/WebAPI/tests/fixtures/mac-vectors.json` is the shared source of truth for three implementations that cannot share a file, because they are deployed to three different machines: `virtusphere_normalize_mac()` (PHP), and the two `ConvertTo-VsNormalizedMac` twins (MECM server, deploy client). PHPUnit's `MacNormalizeTest` and Pester's `VirtuSphere.Common.Tests.ps1` both read that table, and a further Pester test asserts the two PowerShell twins stay textually identical.

Change the canonicalization in one place and a build fails. That is the point: this seam already produced a P1 once (TESTPLAN 2.2 — a MAC stored in the wrong notation makes a VM invisible to MECM, with no error anywhere).

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

`tests/load/portal-read.js` is a k6 read-path load test (TESTPLAN 4.7), dev-host tooling on the same footing as the E2E and mutation layers: not vendored, not in CI. It logs in once and has each VU poll the pages an operator sits on (dashboard, mission and VM lists, health), ramping to 30 concurrent VUs — 3× a realistic LAN peak. k6 runs from a container sharing the web server's network namespace; see `tests/load/README.md` for the command and the recorded baseline (0 % errors over 2469 requests, all p95 thresholds met, `missions.php` the slowest page). Writes stay out of scope: a load test must not mutate real rows. Run it if the volume behaviour of the list pages is ever in question, not on every change.

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