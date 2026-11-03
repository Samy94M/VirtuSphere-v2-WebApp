# ADR-0016: SSoT Drift Checks and Doc Hygiene

Date: 2026-07-07
Status: Accepted

## Context

ADR-0015 established the test baseline and a blocking CSP/pattern hook, but several single-source-of-truth relationships stayed protected by convention only:

- The lifecycle, MECM-sync, role, deploy-job-status and credential-type ENUMs must mirror the PHP constants in `lib/`, and fresh `struktur.sql` must converge with `lib/migrate.php` (`.claude/rules/database.md`). Nothing checked this mechanically.
- The PHP target version is asserted "everywhere" in AGENTS.md but lived in the Dockerfile, `composer.json`, `constants.php` and the docs independently. A real drift class (container vs. code version) is easy to introduce and hard to notice.
- The always-loaded agent docs (`AGENTS.md`, `GROK.md`, `CLAUDE.md`, `README.md`) tend to accumulate dated changelog entries and grow into context-heavy files. The README had already drifted this way.

The PostToolUse hook also only ran the CSP/pattern scan; PHP syntax and DE/EN language parity were left to agent discipline documented in CLAUDE.md.

## Decision

Add three quiet, air-gap-friendly shell checks under `scripts/`, each supporting `--quiet` (session-start mode) and a plain/`--ci` mode:

- `check-enum-sync.sh` — PHP constants are the SSoT; the DB ENUM columns in `struktur.sql` and `migrate.php` are mirrors and must match order-exact.
- `check-php-version-sync.sh` — the `Docker/php/Dockerfile` `FROM` line is the SSoT; `composer.json`, `constants.php`, `CLAUDE.md` and `AGENTS.md` must match.
- `check-doc-hygiene.sh` — bans changelog markers in the four always-loaded docs and enforces line budgets (AGENTS 120, GROK 150, CLAUDE 60, README 100).

Wire all three into the SessionStart hook in quiet mode: they emit a one-line warning with the reproduction command only on drift. Add two blocking PostToolUse hooks: `php-lint.sh` (host `php -l`, or the PHP container for files under `Docker/WebAPI/`) and `lang-parity.sh` (DE/EN catalog audit on `lang/` edits).

Keep PHPStan/Psalm and a CI pipeline out of scope, consistent with ADR-0015; these checks are deliberately small shell scripts that run locally and at session start.

## Consequences

SSoT drift is caught at the mirror before it reaches runtime, and the "fix at the SSoT, not the mirror" rule is now enforceable rather than aspirational. Doc regrowth is bounded. The checks are mechanical string/line comparisons, not semantic analysis: they assume the documented literal shapes (single-quoted const values, `FROM php:X.Y-fpm`, `ENUM(...)` on one line) and must be updated if those shapes change. Re-evaluate promoting these to a CI job at the E3 milestone alongside the ADR-0015 static-analysis decision.
