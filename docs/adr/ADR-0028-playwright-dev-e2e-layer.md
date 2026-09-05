# ADR-0028: Playwright as a Dev-only E2E Layer

Date: 2026-07-12
Status: Accepted; revised 2026-07-16, 2026-08-27 and 2026-09-05 (lane gates, deterministic visual harness, reviewed baselines and release gating, see below)

## Context

ADR-0015 established PHPUnit (unit, static, integration) as the test baseline and deliberately kept browser tests out, to re-evaluate at a later milestone. The pre-release hardening campaign (`docs/TESTPLAN.md`) has since proven the security- and logic-critical flows at the HTTP level with curl: RBAC, CSRF, session rotation, the deploy-enqueue guard, security headers, machine-API error shapes, the HTTPS admin flow. What no test covers is the runtime behaviour of the rendered portal in a real browser: that every one of the 22 pages loads without a PHP notice or a CSP violation in both locales and both themes, that a control's visibility matches the permission of its handler, that a value round-trips through the DB and back to every escaping context, and that no page reaches for an off-LAN asset.

That is a browser's job, and the project already has a working local Playwright Chromium (`portal-screenshot-setup` memory). The open question ADR-0015 left is how to add it without breaking the two hard constraints: the shipped artifact must stay air-gapped (no CDN, no external runtime assets, no JS build pipeline), and the delivery must not grow an npm dependency tree.

## Decision

Add a Playwright end-to-end layer as a **dev-only** tier, extending ADR-0015 rather than changing the shipped baseline.

- **Location.** The suite lives in `tests/e2e/` at the **repository root**, not under `Docker/WebAPI`. The PHP container mounts `Docker/WebAPI` as the webroot, so anything under `tests/e2e/` is structurally incapable of reaching the delivery artifact. This mirrors the reasoning already applied to `scripts/` (not mounted into the container).
- **Not vendored.** `tests/e2e/node_modules` is git-ignored. `@playwright/test` and `@axe-core/playwright` are dev-host tooling, installed with `npm ci` on the machine that runs the E2E pass, exactly as PHPUnit's `vendor/` is vendored but Playwright's browsers are not. The air-gap rule governs the **runtime** artifact; it does not govern the dev host, which already runs Docker, Composer and Node. The committed `package.json` + `package-lock.json` pin the versions for a reproducible install; a locked-down dev host mirrors them into its own registry the same way it already mirrors Composer and Docker images.
- **Browser from one resolver.** `tests/e2e/lib/browser-resolver.js` is consumed by both `scripts/check.ps1` and `playwright.config.js`. An explicit `PLAYWRIGHT_CHROMIUM` wins; otherwise the lockfile-installed `playwright-core` supplies its exact executable and revision. Neither consumer scans for the highest cache directory and neither carries a user or revision literal. Playwright does **not** download a browser at runtime; `npm ci` and the documented one-time browser provisioning remain dev-/CI-host setup and can use an offline mirror.
- **Out of CI.** The suite does not run in `.github/workflows/ci.yml`. CI provisions no MySQL and no browser, and the shipped baseline stays the PHPUnit + static + drift set. E2E is a pre-release / on-demand gate on the dev host against the running Docker stack, documented in `docs/QA.md`.
- **Known gotchas are codified, not rediscovered.** Base URL `http://127.0.0.1:8021/portal/` with the trailing slash (the no-slash redirect fails in this Chromium); theme via `localStorage['virtusphere.theme']`; auth as a setup project that logs in once per role and reuses `storageState`; DB assertions through `docker exec` into the MySQL container (its port is deliberately not published to the host, so a shelled-out `mysql` client is the honest path and needs no compose change that would expose the DB on the LAN); web-first assertions only (`await expect(locator)`), no `waitForTimeout`; `getByRole`/`getByLabel` preferred so accessibility is exercised in passing.

The Selenium-era desktop tests and any prior browser harness stay retired; this is the one browser tier.

## Consequences

The portal gains a runtime regression net over the full page matrix that curl cannot express (console errors, CSP violations, visual baselines, escaping contexts), while the shipped artifact and CI are untouched: nothing new is vendored into `Docker/WebAPI`, and a checkout without `npm ci` simply has no E2E layer, not a broken build.

The cost is a second toolchain on the dev host (Node + Playwright browsers) and a suite that only runs where a browser and the stack exist, so it cannot gate a PR on GitHub. That is the deliberate trade: the security and logic contracts that must hold on every push already live in PHPUnit and the static guards (ADR-0015); Playwright is the deeper, slower, dev-host proof, re-run before a release rather than on every commit.

Because the browser and the DB are both reachable from the test, an E2E test can leave residue in a shared dev database. Suites therefore scope their fixtures to a recognizable prefix and clean up in setup/teardown, the same discipline the PHPUnit integration suite already follows (`phpunit_*` rows), and destructive checks operate on their own seeded objects, never on ambient dev data.

## Revision 2026-07-16: E2E Additionally Gates the Integration and Release Lanes

The "Out of CI" decision above described the minimal CI of ADR-0015. With the canonical runner and its lanes (ADR-0031), that reasoning no longer holds for every CI context: the Integration lane provisions exactly what this ADR said CI lacks, a MySQL server, the full stack and a controlled toolchain. The revision:

- **Playwright Chromium becomes a gate of the Integration lane** (merge, nightly, release candidates) against the lane's throwaway QA stack, and the fuller browser matrix (Firefox, WebKit, Windows Edge) belongs to the Release lane. The Fast lane stays browser-free: PRs are still gated by PHPUnit, the static contracts and the drift guards alone.
- **Connected CI may fetch the tooling.** `npm ci` for `tests/e2e/` and a Playwright browser download are allowed in the CI sandbox, like Composer advisories and lint images already are (ADR-0031/tool-lock). Nothing changes for the shipped artifact: nothing is vendored into `Docker/WebAPI`, the runtime needs no Node, no browser and no internet. On dev hosts the existing offline pattern (`PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1`, `PLAYWRIGHT_CHROMIUM` from the environment) remains the default.
- **The dev-host tier stays.** Local on-demand runs against the dev stack keep working exactly as decided above; the lanes add a second, reproducible execution context, they do not replace the first.
- **Scope follows the coverage contract (E6).** The lane-gated suite proves each portal POST action, upload/download flow and CRUD round-trip once in a real browser, including the confirm-cancel branch; exhaustive field matrices stay in PHPUnit. The E2E layer gates a lane only for what a browser alone can prove.

An Integration-lane run without a usable browser or QA stack is `infrastructure_error`, never a skip and never a pass (ADR-0015 amendment, ADR-0031).

## Revision 2026-08-27: Deterministic Visual Project

The existing configuration gains exactly one pixel-owning Chromium project, `visual`. Firefox, WebKit and Edge remain functional release projects and never share its pixel expectations. The visual project reuses the existing base URL, setup authentication and reporters, but it may run only against the synthetic `virtusphere-qa` throwaway Compose project. Its seed guard requires the exact loopback URL, QA container names, database name and an explicit allowance set by the canonical runner.

The committed runner contract fixes Windows build and architecture, Playwright version, Chromium revision/version, Segoe UI font files and SHA-256 hashes, locale, portal timezone, both viewports, device scale and CSS screenshot scale, fixed clock and pseudo-random seed, light/dark themes, reduced motion, disabled animations and hidden caret. The harness validates these values before taking an image. A mismatch exits as `infrastructure_error`; it never updates an expectation. Etappe 11 intentionally commits no approved screenshot baseline. Each theme is rendered twice, PNGs are decoded and compared pixel by pixel with the committed zero-drift tolerance, and the ignored evidence plus actual metadata is written below `qa-artifacts/` for review. Baseline review and release gating were owned by Etappe 17 and are described in the 2026-09-05 revision below.

Before the four visual runs, the runner proves the exact Compose project/service labels and that the QA database has no queued, running or cancelling jobs. It stops only workers that were running and restores precisely that state in `finally`, even after a failed visual run. A shared, dev or production stack therefore cannot be paused by this path. Visual fixtures use only the `visuale11fixture` namespace, are deleted idempotently before seeding and cleaned afterward; no screenshot may contain ambient or real data.

This revision changes QA tooling only. Portal help and runtime rendering code, audit/event writers, deploy-job persistence, container runtime configuration and every machine-API wire shape remain untouched.

## Revision 2026-09-05: Reviewed Target Baselines and Release Gating

Comparing two runs of one build proves the harness deterministic and nothing else; a build whose every page had turned magenta would have passed it twice. The visual project therefore gains committed target PNGs under `tests/e2e/visual/baselines/`, reviewed by a person, and equality with them at the same zero tolerance becomes the pass criterion in the Integration and Release lanes. The set is derived from the runner contract (both themes across the desktop, wrap and mobile viewports, for each captured page), never from what happens to lie in the directory, and it is bound to its runner by a manifest carrying a SHA-256 per file, so a swapped image or a set taken on another browser build is refused before any comparison.

Only `scripts/update-visual-baselines.ps1` writes that directory. It is not a gate and belongs to no lane: it demands an explicit allowance and a written reason, refuses on runner or font drift before overwriting anything, and keeps the replaced image plus a diff image as audit artifacts. Every lane clears the three variables that could enable an update, and the harness refuses to start while any of them is set, so no gate can reach the writer through an inherited environment.

Two decisions preserve the zero tolerance against the sub-pixel drift carried as an open finding since Etappe 14D. A control the portal does not style, the browser-drawn file input, is masked with its recorded reason: the portal cannot influence its rasterisation and therefore cannot regress it, and a mask that stops matching fails the capture so the exemption cannot silently grow. Everything the portal does own stays unmasked and is retried within a bounded number of attempts, because a design regression appears in every capture while rasterisation noise does not; a retry that was needed is reported rather than absorbed. Raising the threshold was rejected: a softened determinism contract admits exactly the drift it exists to catch.

This revision changes QA tooling and its documentation only. Portal rendering code, help text, audit writers, deploy-job persistence, container runtime configuration and every machine-API wire shape remain untouched.
