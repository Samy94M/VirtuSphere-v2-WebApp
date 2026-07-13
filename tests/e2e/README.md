# VirtuSphere Portal E2E (dev-only)

Playwright end-to-end layer for the portal. **Dev-host tooling, not part of the shipped artifact** (ADR-0028): `node_modules` is git-ignored, nothing here is mounted into the containers, and this suite does not run in CI. It runs against the local Docker stack.

## Prerequisites

- The Docker stack is up (`docker compose up -d`) and reachable at `http://127.0.0.1:8021/portal/`.
- Node on the dev host.
- A local Playwright Chromium at the path in `playwright.config.js` (override with `PLAYWRIGHT_CHROMIUM`). Playwright does not download a browser here.

## Install

```bash
cd tests/e2e
PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install
```

## Run

```bash
cd tests/e2e
npm test              # headless, all specs
npm run test:headed   # watch it drive the browser
npm run report        # open the last HTML report
```

The `setup` project seeds the `e2e_user` account (role `user`) through the PHP container and logs in once per role, caching `storageState` under `.auth/`. Specs reuse those sessions.

## What it covers

- `specs/auth.setup.js` — seeds the user role, authenticates admin + user.
- `specs/health-matrix.spec.js` (TESTPLAN 3.1) — every page in `lib/pages.js` × {light, dark} × {admin, user}: loads with the right access outcome, no PHP error/notice in the HTML, no console error or CSP violation, no request off `127.0.0.1` (air-gap proof), no raw `module.key` leaking as text.

DB assertions (later specs) go through `lib/db.js`, which shells into the MySQL container with `docker exec` because its port is deliberately unpublished.

## Environment overrides

| Variable | Default |
|---|---|
| `VIRTUSPHERE_BASE_URL` | `http://127.0.0.1:8021/portal/` |
| `PLAYWRIGHT_CHROMIUM` | local `chromium-1223` path |
| `VIRTUSPHERE_ADMIN_USER` / `VIRTUSPHERE_ADMIN_PASS` | `admin` / `admin12345678` |
| `VIRTUSPHERE_PHP_CONTAINER` | `virtusphere-v2-webapp-php-1` |
| `VIRTUSPHERE_MYSQL_CONTAINER` | `virtusphere-v2-webapp-mysql-1` |
