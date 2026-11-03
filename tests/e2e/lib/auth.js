// Role fixtures and the storageState paths the auth setup writes and the specs
// reuse. Credentials come from the environment with the documented dev defaults
// (portal-screenshot-setup); the plain-user account is seeded by auth.setup.js.

const path = require('node:path');

const AUTH_DIR = path.join(__dirname, '..', '.auth');

const ROLES = {
  admin: {
    username: process.env.VIRTUSPHERE_ADMIN_USER || 'admin',
    password: process.env.VIRTUSPHERE_ADMIN_PASS || 'admin12345678',
    storageState: path.join(AUTH_DIR, 'admin.json'),
    seeded: false,
  },
  user: {
    username: 'e2e_user',
    password: 'E2eUser-12345',
    storageState: path.join(AUTH_DIR, 'user.json'),
    seeded: true,
  },
};

/**
 * Logs `role` in on `page` and waits for the dashboard. Shared by the setup
 * project and the per-project refresh in lib/test.js, so both sign in the same
 * way.
 */
async function signIn(page, role) {
  await page.goto('login.php');
  await page.locator('#username').fill(role.username);
  await page.locator('#password').fill(role.password);
  await Promise.all([
    page.waitForURL(/dashboard\.php/, { timeout: 15000 }),
    page.locator('button[type="submit"]').click(),
  ]);
}

/**
 * Signs `role` in through a throwaway context of `browser` and rewrites its
 * storageState file. English like the setup project: the login stores no
 * language choice, but the specs assume the same starting point everywhere.
 */
async function refreshStorageState(browser, baseURL, role) {
  // Inside a test, Playwright hands the spec's `use` options to every
  // browser.newContext(), storageState included. Without the empty state the
  // renewal context started signed in, login.php forwarded to the dashboard,
  // and the username field never appeared (first matrix run, 2026-10-26).
  const context = await browser.newContext({
    baseURL,
    locale: 'en-US',
    storageState: { cookies: [], origins: [] },
  });
  // A context from browser.newContext() does not inherit the config's `use`
  // timeouts, and Playwright's own action default is unlimited. Bound every
  // step so a slow portal fails this renewal instead of the whole test.
  context.setDefaultTimeout(15000);
  context.setDefaultNavigationTimeout(15000);
  try {
    const page = await context.newPage();
    await signIn(page, role);
    await context.storageState({ path: role.storageState });
  } finally {
    await context.close();
  }
}

module.exports = { ROLES, AUTH_DIR, signIn, refreshStorageState };
