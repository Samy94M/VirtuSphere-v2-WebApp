# ADR-0028: Playwright as a Dev-only E2E Layer

Date: 2026-07-12
Status: Accepted

## Context

ADR-0015 established PHPUnit (unit, static, integration) as the test baseline and deliberately kept browser tests out, to re-evaluate at a later milestone. The pre-release hardening campaign (`docs/TESTPLAN.md`) has since proven the security- and logic-critical flows at the HTTP level with curl: RBAC, CSRF, session rotation, the deploy-enqueue guard, security headers, machine-API error shapes, the HTTPS admin flow. What no test covers is the runtime behaviour of the rendered portal in a real browser: that every one of the 22 pages loads without a PHP notice or a CSP violation in both locales and both themes, that a control's visibility matches the permission of its handler, that a value round-trips through the DB and back to every escaping context, and that no page reaches for an off-LAN asset.

That is a browser's job, and the project already has a working local Playwright Chromium (`portal-screenshot-setup` memory). The open question ADR-0015 left is how to add it without breaking the two hard constraints: the shipped artifact must stay air-gapped (no CDN, no external runtime assets, no JS build pipeline), and the delivery must not grow an npm dependency tree.

## Decision

Add a Playwright end-to-end layer as a **dev-only** tier, extending ADR-0015 rather than changing the shipped baseline.

- **Location.** The suite lives in `tests/e2e/` at the **repository root**, not under `Docker/WebAPI`. The PHP container mounts `Docker/WebAPI` as the webroot, so anything under `tests/e2e/` is structurally incapable of reaching the delivery artifact. This mirrors the reasoning already applied to `scripts/` (not mounted into the container).
- **Not vendored.** `tests/e2e/node_modules` is git-ignored. `@playwright/test` and `@axe-core/playwright` are dev-host tooling, installed with `npm ci` on the machine that runs the E2E pass, exactly as PHPUnit's `vendor/` is vendored but Playwright's browsers are not. The air-gap rule governs the **runtime** artifact; it does not govern the dev host, which already runs Docker, Composer and Node. The committed `package.json` + `package-lock.json` pin the versions for a reproducible install; a locked-down dev host mirrors them into its own registry the same way it already mirrors Composer and Docker images.
- **Browser from the environment.** `executablePath` comes from `PLAYWRIGHT_CHROMIUM` with a default on the local install (`C:\Users\Samy\AppData\Local\ms-playwright\chromium-1223\chrome-win64\chrome.exe`), launched `--no-sandbox`. Playwright does **not** download a browser (`PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1`), so the install is offline-friendly against an existing browser.
- **Out of CI.** The suite does not run in `.github/workflows/ci.yml`. CI provisions no MySQL and no browser, and the shipped baseline stays the PHPUnit + static + drift set. E2E is a pre-release / on-demand gate on the dev host against the running Docker stack, documented in `docs/QA.md`.
- **Known gotchas are codified, not rediscovered.** Base URL `http://127.0.0.1:8021/portal/` with the trailing slash (the no-slash redirect fails in this Chromium); theme via `localStorage['virtusphere.theme']`; auth as a setup project that logs in once per role and reuses `storageState`; DB assertions through `docker exec` into the MySQL container (its port is deliberately not published to the host, so a shelled-out `mysql` client is the honest path and needs no compose change that would expose the DB on the LAN); web-first assertions only (`await expect(locator)`), no `waitForTimeout`; `getByRole`/`getByLabel` preferred so accessibility is exercised in passing.

The Selenium-era desktop tests and any prior browser harness stay retired; this is the one browser tier.

## Consequences

The portal gains a runtime regression net over the full page matrix that curl cannot express (console errors, CSP violations, visual baselines, escaping contexts), while the shipped artifact and CI are untouched: nothing new is vendored into `Docker/WebAPI`, and a checkout without `npm ci` simply has no E2E layer, not a broken build.

The cost is a second toolchain on the dev host (Node + Playwright browsers) and a suite that only runs where a browser and the stack exist, so it cannot gate a PR on GitHub. That is the deliberate trade: the security and logic contracts that must hold on every push already live in PHPUnit and the static guards (ADR-0015); Playwright is the deeper, slower, dev-host proof, re-run before a release rather than on every commit.

Because the browser and the DB are both reachable from the test, an E2E test can leave residue in a shared dev database. Suites therefore scope their fixtures to a recognizable prefix and clean up in setup/teardown, the same discipline the PHPUnit integration suite already follows (`phpunit_*` rows), and destructive checks operate on their own seeded objects, never on ambient dev data.
