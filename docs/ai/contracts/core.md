# VirtuSphere core contracts

Read the sections relevant to the current change. Paths below are relative to Docker/WebAPI unless they already name a repository directory. Forbidden patterns remain in GROK.md section 1; these are the constructive implementation contracts.

## A20 Secrets come from .env without default fallbacks and are scoped to their runtime owner.

- Secrets come from `.env` without a default fallback. Compose passes PHP and each worker only its explicit application allowlist; `MYSQL_ROOT_PASSWORD` belongs exclusively to the MySQL bootstrap and host-side backup/restore tools. `lib/envboot.php` neither imports that key from a readable dotenv file nor requires it for application boot. MySQL validates its own root and application bootstrap passwords before the upstream entrypoint starts.

## A21 Use SSoT values from lib/constants.php, lib/defaults.php and lib/permissions.php.

- Use SSoT values from `lib/constants.php`, `lib/defaults.php` and `lib/permissions.php`.

## A49 PHP target is 8.4 everywhere: Dockerfile, Composer platform, docs, hooks.

- PHP target is 8.4 everywhere: Dockerfile, Composer platform, docs, hooks.

## A50 Security headers, CSP and nonces live in lib/headers.php; use virtusphere_csp_nonce() for any i

- Security headers, CSP and nonces live in `lib/headers.php`; use `virtusphere_csp_nonce()` for any inline script/style.

## A51 Use virtusphere_is_request_secure() for Secure cookies and future HSTS behavior.

- Use `virtusphere_is_request_secure()` for Secure cookies and future HSTS behavior.

## A52 Escape HTML with htmlspecialchars; emit JSON with json_encode. Do not mix the two.

- Escape HTML with `htmlspecialchars`; emit JSON with `json_encode`. Do not mix the two.

## A53 New or changed portal-visible text goes through __t() and keeps DE/EN catalog parity. Run php s

- New or changed portal-visible text goes through `__t()` and keeps DE/EN catalog parity. Run `php scripts/lang-audit.php --ci` or the documented container equivalent for portal text, validation or error-message changes.

## A56 Do not localize or rename machine API fields or MECM/Ansible status strings for portal UI langu

- Do not localize or rename machine API fields or MECM/Ansible status strings for portal UI language work.

## A57 Keep the app air-gap friendly: no CDN, cloud service, telemetry or runtime package download dep

- Keep the app air-gap friendly: no CDN, cloud service, telemetry or runtime package download dependency.

## A58 RBAC uses can($permission) from lib/auth.php. Do not hand-roll role checks in pages.

- RBAC uses `can($permission)` from `lib/auth.php`. Do not hand-roll role checks in pages.

## A59 Runtime container logs are bounded and nginx has no file-log mount.

- Every long-lived Compose service uses the shared `json-file` policy with
  `max-size=10m` and `max-file=5`. HTTP and generated HTTPS nginx blocks write
  access/error output to stdout/stderr; `/var/log/nginx` is never a host or QA
  volume. Diagnostics use `docker compose logs`.

## A60 PHP runtime, build tooling and optional admin tooling have separate owners.

- PHP-FPM, deploy-worker and maintenance-worker resolve the same versioned
  `virtusphere-php:8.4-runtime` image and Dockerfile target. The runtime contains
  the required extensions and shared libraries, but no Composer, git, compiler,
  PHP headers or archive tools. CI, Composer and fallback checks use only the
  `tooling` target from `scripts/tool-lock.json`.
- The offline bundle resolves images once but emits complete, separate core and
  optional-tools manifests. A core install neither loads nor starts phpMyAdmin;
  `tools/install.sh` verifies its own closed payload before starting the
  loopback-only profile.

## A61 Portal assets are content-addressed and only their exact versioned URLs are immutable.

- `layout_asset_version()` hashes the bytes with SHA-256; file timestamps are
  not an asset identity because an offline update may preserve them.
- nginx compresses CSS and JavaScript from the shared HTTP context. Only
  `/portal/assets/` with a full hexadecimal digest in `v` receives the
  year-long `immutable` policy. Unversioned/missing assets receive `no-cache`,
  missing paths stay nginx 404s, and dynamic responses receive no asset policy.
  The baked HTTP and generated HTTPS blocks carry the same location/header
  contract.

## A62 Portal pages load assets only through the closed page registry.

- `VIRTUSPHERE_LAYOUT_PAGES`, `layout_style_registry()` and
  `layout_script_registry()` are the one page-to-asset owner. An unknown page
  fails closed. Every page keeps the selected subsequence of the global
  cascade/script order; `core.js` and the shared theme/layout sheets remain
  common, while tables, System-status rules and feature scripts name their
  actual consumers. Login uses the same registry with its explicit page id.
