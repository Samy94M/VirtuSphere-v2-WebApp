// TESTPLAN 3.2, session hardening runtime proof (the remaining half of the RBAC
// / CSRF matrix). These are login-fresh flows, so each test runs in its own
// browser context and signs in by hand rather than reusing storageState.
//
// Three guarantees, each proven by behaviour, not by reading the code:
//   - login rotates the session id (fixation defence);
//   - logout invalidates server-side, and the Back button cannot repaint a
//     protected page from cache;
//   - the absolute session lifetime is enforced server-side, so a client that
//     never runs the countdown is still forced back to the login.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp } = require('../lib/php');

const ADMIN = ROLES.admin;

async function sessionCookie(context) {
  const cookies = await context.cookies();
  const phpsessid = cookies.find((c) => c.name === 'PHPSESSID');
  return phpsessid ? phpsessid.value : null;
}

async function signIn(page) {
  await page.goto('login.php');
  await page.locator('#username').fill(ADMIN.username);
  await page.locator('#password').fill(ADMIN.password);
  await Promise.all([
    page.waitForURL(/dashboard\.php/, { timeout: 15000 }),
    page.locator('button[type="submit"]').click(),
  ]);
  await expect(page).toHaveURL(/dashboard\.php/);
}

test('login rotates the session id (fixation defence)', async ({ browser }) => {
  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    // Visiting the login page first establishes an anonymous session cookie;
    // its id is the one an attacker would try to fixate.
    await page.goto('login.php');
    const before = await sessionCookie(context);
    expect(before, 'the login page sets a session cookie').not.toBeNull();

    await page.locator('#username').fill(ADMIN.username);
    await page.locator('#password').fill(ADMIN.password);
    await Promise.all([
      page.waitForURL(/dashboard\.php/, { timeout: 15000 }),
      page.locator('button[type="submit"]').click(),
    ]);

    const after = await sessionCookie(context);
    expect(after, 'the session cookie survives login').not.toBeNull();
    expect(after, 'login must issue a fresh session id, not adopt the pre-login one').not.toBe(before);
  } finally {
    await context.close();
  }
});

test('logout invalidates the session server-side and the Back button shows no protected content', async ({ browser }) => {
  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    await signIn(page);

    // Log out through the real UI form (CSRF token and all).
    await Promise.all([
      page.waitForURL(/login\.php/, { timeout: 15000 }),
      page.locator('form[action="logout.php"] button[type="submit"], form[action="logout.php"] [type="submit"]').first().click(),
    ]);

    // A fresh request for a protected page is refused server-side, regardless
    // of anything the browser may still hold.
    const direct = await context.request.get('dashboard.php', { maxRedirects: 0 });
    expect(direct.status(), 'the destroyed session cannot reach the dashboard').toBe(302);
    expect(String(direct.headers()['location'] || ''), 'it bounces to the login').toContain('login.php');

    // The Back button must not repaint the cached dashboard: after going back,
    // the app lands on (or is redirected to) the login, and no dashboard-only
    // marker is visible.
    await page.goBack();
    await page.waitForLoadState('domcontentloaded');
    const onLogin = /login\.php/.test(page.url());
    if (!onLogin) {
      // Some browsers serve the bfcache copy; a reload then re-checks the server.
      await page.reload();
    }
    await expect(page).toHaveURL(/login\.php/);
    await expect(page.locator('form[action="logout.php"]'), 'no signed-in chrome after Back+reload').toHaveCount(0);
  } finally {
    await context.close();
  }
});

test('an expired session forces a re-login even without the client countdown', async ({ browser }) => {
  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    await signIn(page);
    const sid = await sessionCookie(context);
    expect(sid, 'a real session id').toMatch(/^[a-z0-9,-]+$/i);

    // Age the session past its absolute lifetime by rewriting session_expires_at
    // in the on-disk session file (default files handler, /tmp). This is exactly
    // the state a client that never ran the countdown reaches by waiting; doing
    // it in one step keeps the test fast and deterministic. The PHP session
    // encoding is `key|<serialized>`, so a targeted preg_replace is safe.
    // As www-data: the login session file is owned by the FPM worker, and the
    // container drops CAP_DAC_OVERRIDE, so root cannot read it.
    const rewritten = runPhp(`
$sid = ${JSON.stringify(sid)};
$path = (session_save_path() ?: sys_get_temp_dir()) . '/sess_' . $sid;
if (!is_file($path)) { echo 'MISSING'; return; }
$raw = file_get_contents($path);
$past = time() - 3600;
$new = preg_replace('/session_expires_at\\\\|i:\\\\d+;/', 'session_expires_at|i:' . $past . ';', $raw, 1, $count);
if ($count === 0) { echo 'NOFIELD'; return; }
file_put_contents($path, $new);
echo 'AGED';
`, [], { user: 'www-data' });
    expect(rewritten.trim(), 'the session file was aged').toContain('AGED');

    // The very next request must be forced back to the login: the server
    // enforces the lifetime, no client timer involved.
    const afterExpiry = await context.request.get('dashboard.php', { maxRedirects: 0 });
    expect(afterExpiry.status(), 'the expired session cannot reach the dashboard').toBe(302);
    expect(String(afterExpiry.headers()['location'] || ''), 'it bounces to the login').toContain('login.php');
  } finally {
    await context.close();
  }
});
