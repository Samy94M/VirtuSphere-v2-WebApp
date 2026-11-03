# ADR-0015: Test Baseline and Blocking Hooks

Date: 2026-07-06
Status: Accepted; amended 2026-07-16 (lane split and skip policy, see below)

## Context

The portal and machine API now have enough shared security and deploy logic that warnings alone no longer protect follow-up work. At the same time, the project must stay air-gap friendly and avoid introducing a large CI/static-analysis stack before the migration stabilizes.

The immediate need is a small, runnable safety net for language catalog parity, permission parity and machine API wire contracts, plus a hook policy that blocks dangerous new patterns while allowing older cleanup to proceed in focused slices.

## Decision

Use PHPUnit as the first project test baseline with three suites under `Docker/WebAPI/tests`: unit, static and integration. The initial tests cover DE/EN catalog placeholders and translation literal existence, declared permission literals and selected page guard parity, plus machine API HTTP characterization checks when the Docker stack is reachable.

A minimal GitHub Actions CI (`.github/workflows/ci.yml`) runs this same check-set on push and pull request (PHP-lint, unit + static PHPUnit, PHPStan, lang-audit, JS syntax, the SSoT/doc drift guards and the changed-file CSP lint). It runs without a MySQL server, so the integration suite skips itself. Composer exposes `test`, `test:unit` and `stan`; the documented local runtime path is the PHP container with `COMPOSER_CACHE_DIR=/tmp/composer-cache`.

PHPStan runs at level 5 over `lib`, `portal` and `tests` (`phpstan.neon.dist`) with a committed baseline (`phpstan-baseline.neon`) as ratchet: pre-existing findings live in the baseline, new findings fail the build and are fixed rather than re-baselined. The level-4-to-5 step added only two real findings, both fixed at the source (a redundant `array_values`, and an `@phpstan-ignore` on the intentional phpseclib `exec($cmd, false)` quirk the bounded AP6 read loop needs), so the baseline did not grow. The legacy machine-API files in the WebAPI root (`access.php`, `mecm-*.php`, `db_importMAC.php`, `function.php`, `api/`) stay out of scope until the E3 retirement decision; widening the scope and raising the level further are the ratchet targets after E3.

Use a thin `.claude/hooks/lint-csp-patterns.sh` hook that delegates to `scripts/lint-csp-patterns.sh`. Hard findings exit 2 with `BLOCK:` and include PHP syntax failures, interpolated SQL, secret fallbacks, external runtime assets, inline event handlers and script/style tags without nonces. Soft findings remain warnings for legacy cleanup signals such as raw portal `getMessage()` flashes, inline style attributes, short tags and oversized files.

## Consequences

Follow-up edits get fast local feedback before runtime testing. Machine API wire behavior is preserved as an explicit test contract rather than an informal memory.

The hook is intentionally conservative and can be refined as legacy pages are migrated. It is not a full static analyzer and does not replace runtime checks, language audit, migration checks or targeted portal smoke tests.

Dead-code deletion remains a separate step because current nginx PHP routing must be verified before relying on unknown `*.php` paths to fall back to `index.php`.

## Amendment 2026-07-16: Lane Split and the Skip Policy

ADR-0031 replaced the single "minimal CI check-set" of this ADR with the canonical runner and its lanes. The baseline decision above stands; what changes is where the suites run and what a skip may mean.

**Where the suites run.** The Fast lane (every PR, local pre-check) runs the unit and static suites; the Integration lane (merge, nightly, release candidates) runs the full PHPUnit suite including the integration tests against a dedicated throwaway QA stack. "CI runs without a MySQL server, so the integration suite skips itself" now describes only the Fast lane.

**Skip policy (`--fail-on-skipped`).** A skip is a structural statement, not an outcome. It is legitimate in exactly one place: the Fast lane, where the integration suite excludes itself as a whole because no database exists there by design; the Fast lane therefore runs unit + static with `--fail-on-skipped` and the integration suite is not part of its run set. In the Integration lane the full suite runs with `--fail-on-skipped`, and no test may skip itself dynamically: no allowlist skips, no missing-credential skips, no "stack not reachable" skips. The QA stack is part of the lane's contract; if it is missing or broken, the gate reports `infrastructure_error` (exit 2), never a skip and never a pass. A test whose precondition is a deliberate platform/flag decision is declared `not_applicable` at the gate level with its reason in the artifact, not skipped from inside the test.

**Why.** The 2026-07 hardening campaign showed that dynamic skips rot silently: eight structurally skipped config-contract tests surfaced only when the Fast lane first ran with `--fail-on-skipped`. A skip that depends on runtime probing is indistinguishable from a test that stopped protecting anything.

The PHPStan ratchet targets of this ADR (baseline only shrinks, scope widens after E3) are unchanged by the amendment.
