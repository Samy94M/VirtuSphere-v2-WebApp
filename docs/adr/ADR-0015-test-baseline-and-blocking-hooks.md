# ADR-0015: Test Baseline and Blocking Hooks

Date: 2026-07-06
Status: Accepted

## Context

The portal and machine API now have enough shared security and deploy logic that warnings alone no longer protect follow-up work. At the same time, the project must stay air-gap friendly and avoid introducing a large CI/static-analysis stack before the migration stabilizes.

The immediate need is a small, runnable safety net for language catalog parity, permission parity and machine API wire contracts, plus a hook policy that blocks dangerous new patterns while allowing older cleanup to proceed in focused slices.

## Decision

Use PHPUnit as the first project test baseline with three suites under `Docker/WebAPI/tests`: unit, static and integration. The initial tests cover DE/EN catalog placeholders and translation literal existence, declared permission literals and selected page guard parity, plus machine API HTTP characterization checks when the Docker stack is reachable.

A minimal GitHub Actions CI (`.github/workflows/ci.yml`) runs this same check-set on push and pull request (PHP-lint, unit + static PHPUnit, PHPStan, lang-audit, JS syntax, the SSoT/doc drift guards and the changed-file CSP lint). It runs without a MySQL server, so the integration suite skips itself. Composer exposes `test`, `test:unit` and `stan`; the documented local runtime path is the PHP container with `COMPOSER_CACHE_DIR=/tmp/composer-cache`.

PHPStan runs at level 4 over `lib`, `portal` and `tests` (`phpstan.neon.dist`) with a committed baseline (`phpstan-baseline.neon`) as ratchet: pre-existing findings live in the baseline, new findings fail the build and are fixed rather than re-baselined. The legacy machine-API files in the WebAPI root (`access.php`, `mecm-*.php`, `db_importMAC.php`, `function.php`, `api/`) stay out of scope until the E3 retirement decision; widening the scope and raising the level are the ratchet targets after E3.

Use a thin `.claude/hooks/lint-csp-patterns.sh` hook that delegates to `scripts/lint-csp-patterns.sh`. Hard findings exit 2 with `BLOCK:` and include PHP syntax failures, interpolated SQL, secret fallbacks, external runtime assets, inline event handlers and script/style tags without nonces. Soft findings remain warnings for legacy cleanup signals such as raw portal `getMessage()` flashes, inline style attributes, short tags and oversized files.

## Consequences

Follow-up edits get fast local feedback before runtime testing. Machine API wire behavior is preserved as an explicit test contract rather than an informal memory.

The hook is intentionally conservative and can be refined as legacy pages are migrated. It is not a full static analyzer and does not replace runtime checks, language audit, migration checks or targeted portal smoke tests.

Dead-code deletion remains a separate step because current nginx PHP routing must be verified before relying on unknown `*.php` paths to fall back to `index.php`.
