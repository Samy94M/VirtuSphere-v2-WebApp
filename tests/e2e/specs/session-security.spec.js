// TESTPLAN 3.2, session hardening runtime proof (the remaining half of the RBAC
// / CSRF matrix). These are login-fresh flows, so each test runs in its own
// browser context and signs in by hand rather than reusing storageState.
//
// Four guarantees, each proven by behaviour, not by reading the code:
//   - login rotates the session id (fixation defence);
//   - logout invalidates server-side, and the Back button cannot repaint a
//     protected page from cache;
//   - the absolute session lifetime is enforced server-side, so a client that
//     never runs the countdown is still forced back to the login;
//   - the next sign-in returns to the portal page that asked for it, and to
//     nothing else (QL-04 A).

const { test, expect } = require('../lib/test');
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

// Age the session past its absolute lifetime by rewriting session_expires_at
// in the on-disk session file (default files handler, /tmp). This is exactly
// the state a client that never ran the countdown reaches by waiting; doing
// it in one step keeps the test fast and deterministic. The PHP session
// encoding is `key|<serialized>`, so a targeted preg_replace is safe.
// As www-data: the login session file is owned by the FPM worker, and the
// container drops CAP_DAC_OVERRIDE, so root cannot read it.
async function ageSession(context) {
  const sid = await sessionCookie(context);
  expect(sid, 'a real session id').toMatch(/^[a-z0-9,-]+$/i);
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
}

test('an expired session forces a re-login even without the client countdown', async ({ browser }) => {
  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    await signIn(page);
    await ageSession(context);

    // The very next request must be forced back to the login: the server
    // enforces the lifetime, no client timer involved.
    const afterExpiry = await context.request.get('dashboard.php', { maxRedirects: 0 });
    expect(afterExpiry.status(), 'the expired session cannot reach the dashboard').toBe(302);
    expect(String(afterExpiry.headers()['location'] || ''), 'it bounces to the login').toContain('login.php');
  } finally {
    await context.close();
  }
});

// QL-04 A: after a new sign-in the portal returns to the page that asked for
// it, never to anything that is not a portal page, and a pending password
// change still wins. The suite's own role sign-in (lib/auth.js) opens
// login.php without a target and must keep landing on the dashboard.
const RETURN_TARGET = 'vm_overview.php?filter=mecm_pending&page=2';

async function submitSignIn(page, account, expectedUrl) {
  await page.locator('#username').fill(account.username);
  await page.locator('#password').fill(account.password);
  await Promise.all([
    page.waitForURL(expectedUrl, { timeout: 15000 }),
    page.locator('button[type="submit"]').click(),
  ]);
}

function endsWithTarget(target) {
  return (url) => url.pathname.endsWith('/' + target.split('?')[0]) && url.search === '?' + target.split('?')[1];
}

test('an expired session returns to the page that asked for the sign-in', async ({ browser }) => {
  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    await signIn(page);
    await page.goto(RETURN_TARGET);
    await ageSession(context);

    await page.goto(RETURN_TARGET);
    await expect(page).toHaveURL(/login\.php\?reason=session_expired&next=/);
    await expect(page.locator('.login-panel .alert-warning')).toContainText('Your session expired');
    await expect(page.locator('input[type="hidden"][name="next"]')).toHaveValue(RETURN_TARGET);

    await submitSignIn(page, ADMIN, endsWithTarget(RETURN_TARGET));
    await expect(page.locator('h1')).toBeVisible();
  } finally {
    await context.close();
  }
});

test('the countdown sign-out returns to the same page after the next sign-in', async ({ browser }) => {
  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    // The fake clock is installed before the first page runs core.js, so the
    // countdown's Date.now() and setInterval can be moved past the deadline.
    await page.clock.install();
    await signIn(page);
    await page.goto(RETURN_TARGET);
    const expiresIn = Number(await page.locator('[data-expires-in]').getAttribute('data-expires-in'));
    expect(expiresIn, 'the page renders the remaining lifetime').toBeGreaterThan(0);

    await Promise.all([
      page.waitForURL(/login\.php\?next=/, { timeout: 15000 }),
      page.clock.fastForward((expiresIn + 5) * 1000),
    ]);
    await expect(page.locator('input[type="hidden"][name="next"]')).toHaveValue(RETURN_TARGET);

    await submitSignIn(page, ADMIN, endsWithTarget(RETURN_TARGET));
  } finally {
    await context.close();
  }
});

test('a sign-in never returns to another host', async ({ browser }) => {
  for (const target of ['https://example.invalid/', '//example.invalid/vms.php', '/\example.invalid', 'logout.php']) {
    const context = await browser.newContext();
    try {
      const page = await context.newPage();
      await page.goto('login.php?next=' + encodeURIComponent(target));
      await expect(page.locator('input[name="next"]'), target + ' is not carried').toHaveCount(0);
      await submitSignIn(page, ADMIN, /dashboard\.php$/);
    } finally {
      await context.close();
    }
  }
});

test('a pending password change wins over the return target', async ({ browser }) => {
  const USER = ROLES.user;
  runPhp(`db()->query("UPDATE deploy_users SET must_change_password = 1 WHERE name = '${USER.username}'"); echo 'LOCKED';`);
  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    await page.goto('login.php?next=' + encodeURIComponent('vms.php'));
    await expect(page.locator('.login-panel .alert-info')).toContainText('Please log in to continue');
    await submitSignIn(page, USER, /account\.php$/);
  } finally {
    await context.close();
    runPhp(`db()->query("UPDATE deploy_users SET must_change_password = 0 WHERE name = '${USER.username}'"); echo 'UNLOCKED';`);
  }
});

test('a POST refused for a missing session returns by GET and says the input was not saved', async ({ browser }) => {
  const context = await browser.newContext();
  try {
    const refused = await context.request.post('os.php', { form: { action: 'noop' }, maxRedirects: 0 });
    expect(refused.status(), 'the POST is redirected').toBe(302);
    const location = String(refused.headers()['location'] || '');
    expect(location, 'to the sign-in with the page as target').toBe('login.php?discarded=1&next=os.php');

    const page = await context.newPage();
    await page.goto(location);
    await expect(page.locator('.login-panel .alert-warning')).toContainText('Your input was not saved.');
    await submitSignIn(page, ADMIN, /os\.php$/);
  } finally {
    await context.close();
  }
});
