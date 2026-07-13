# VirtuSphere Agent Entry

VirtuSphere is migrating from a C# WinForms desktop client to a LAN-only, server-rendered PHP web application. The existing MECM and Ansible machine integrations stay available during the migration; the desktop token API remains legacy-only until the E3 deploy milestone is accepted.

Read these files before non-trivial changes:
- `AGENTS.md` for tool-agnostic implementation rules.
- `GROK.md` for the project SSoT and forbidden patterns.
- `docs/adr/README.md` for the decision index.

Current target stack:
- PHP 8.4 FPM, mysqli, libsodium, Composer.
- MySQL 8.4.
- nginx, HTTP-first on the LAN; HTTPS is enabled later through an admin config flow.
- No JS build pipeline, no CDN, no telemetry, no external runtime assets.

The `.claude/rules` directory contains path-scoped rules. The `.claude/hooks/lint-csp-patterns.sh` hook is intentionally thin and delegates to `scripts/lint-csp-patterns.sh`. Hard findings block edits with `BLOCK:` and exit 2; soft findings remain warnings so legacy cleanup can continue in focused slices.

## Workflow Checks

- The default Compose project name creates `virtusphere-v2-webapp-php-1`; use that container name unless the stack was started with a custom project name.
- For PHP dependency/test setup: `docker exec -e COMPOSER_CACHE_DIR=/tmp/composer-cache virtusphere-v2-webapp-php-1 composer --working-dir=/var/www/html install`.
- For PHPUnit: `docker exec virtusphere-v2-webapp-php-1 composer --working-dir=/var/www/html test`.
- For portal text, validation or user-facing error changes, keep `Docker/WebAPI/lang/{de,en}` in parity and run `php scripts/lang-audit.php --ci` (host PHP) or the `docker run` form documented in `docs/QA.md` — `scripts/` is not mounted into the PHP container.
- For PHP changes, prefer container linting when host PHP is unavailable: `docker exec virtusphere-v2-webapp-php-1 php -l /var/www/html/<path>`.
- For JavaScript changes, run `node --check` on the changed portal script(s) under `Docker/WebAPI/portal/assets/` (`core.js`, `forms.js`, `deploy.js`).
- For backend, migration or deploy-path changes, run `docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php --check` when the Docker stack is available.
- Keep `AGENTS.md`, `GROK.md` and ADRs as durable rules, not changelogs. Add history to `docs/CHANGELOG.md` only when a change needs a release note. `scripts/check-doc-hygiene.sh` enforces this plus line budgets.
- Drift checks run quietly at session start; on a warning, run the named script (`scripts/check-enum-sync.sh`, `check-php-version-sync.sh`, `check-doc-hygiene.sh`, `check-bounds-sync.php`) and fix the drift at its SSoT source, not at the mirror.
- A user-facing text must never spell out a number a constant owns (a limit, a timeout, an interval, a threshold): interpolate it (`:min`, `:days`) and pass the constant at the call site. The text otherwise lies the moment the constant moves, and nothing else notices. `scripts/check-bounds-sync.php` enforces it; a number that is genuinely not ours goes in its `BOUNDS_EXEMPT` with the reason.
