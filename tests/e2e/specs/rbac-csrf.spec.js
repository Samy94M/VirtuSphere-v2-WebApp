// TESTPLAN 3.2 / E6: the RBAC and CSRF runtime matrix. PermissionParityTest
// proves visibility == handler permission statically; this is the runtime half:
// a direct POST (no UI) with an unauthorized role must be refused (403), a POST
// without or with a foreign session's CSRF token must die with 400 before any
// handler runs, and an anonymous POST must bounce to the login instead of
// executing. Every refusal is proven twice: by status code and by the DB row
// that is still there.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

const MARK = 'e2erbac';

function seedOsRow(name) {
  return phpJson(`
$db = db();
$stmt = $db->prepare("INSERT INTO deploy_os (os_name, os_status) VALUES (?, 'Aktiv')");
$n = '${name}';
$stmt->bind_param('s', $n);
$stmt->execute();
echo 'JSON' . json_encode(['id' => (int) $db->insert_id]) . 'JSON';
`);
}

function osRowExists(id) {
  return phpJson(`
$db = db();
$stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_os WHERE id = ?');
$id = ${Number(id)};
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode(['c' => (int) $stmt->get_result()->fetch_assoc()['c']]) . 'JSON';
`).c === 1;
}

function userCount(name) {
  return phpJson(`
$db = db();
$stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_users WHERE name = ?');
$n = '${name}';
$stmt->bind_param('s', $n);
$stmt->execute();
echo 'JSON' . json_encode(['c' => (int) $stmt->get_result()->fetch_assoc()['c']]) . 'JSON';
`).c;
}

function cleanup() {
  runPhp(`
$db = db();
$stmt = $db->prepare("DELETE FROM deploy_os WHERE os_name LIKE ?");
$like = '${MARK}%';
$stmt->bind_param('s', $like);
$stmt->execute();
$stmt = $db->prepare("DELETE FROM deploy_users WHERE name LIKE ?");
$stmt->bind_param('s', $like);
$stmt->execute();
echo 'CLEANED';
`);
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

async function csrfTokenFor(context, pagePath) {
  const page = await context.newPage();
  await page.goto(pagePath);
  const token = await page.locator('input[name="_csrf"]').first().inputValue();
  await page.close();
  return token;
}

test('CSRF: a POST without a token dies with 400 before the handler runs', async ({ browser }) => {
  const seeded = seedOsRow(MARK + '-csrf-1');
  const admin = await browser.newContext({ storageState: ROLES.admin.storageState });
  try {
    const response = await admin.request.post('os.php', {
      form: { action: 'delete', os_id: String(seeded.id) },
    });
    expect(response.status(), 'the missing token is a 400').toBe(400);
    expect(osRowExists(seeded.id), 'nothing was deleted').toBe(true);
  } finally {
    await admin.close();
  }
});

test('CSRF: a token from another session is rejected (session-bound)', async ({ browser }) => {
  const seeded = seedOsRow(MARK + '-csrf-2');
  const admin = await browser.newContext({ storageState: ROLES.admin.storageState });
  const user = await browser.newContext({ storageState: ROLES.user.storageState });
  try {
    // A valid token, but minted for the user session: hash_equals compares it
    // against the admin session's token and must refuse.
    const foreignToken = await csrfTokenFor(user, 'missions.php?type=missions');
    expect(foreignToken.length, 'the user session hands out a token').toBeGreaterThan(10);

    const response = await admin.request.post('os.php', {
      form: { action: 'delete', os_id: String(seeded.id), _csrf: foreignToken },
    });
    expect(response.status(), 'the foreign token is a 400').toBe(400);
    expect(osRowExists(seeded.id), 'nothing was deleted').toBe(true);
  } finally {
    await admin.close();
    await user.close();
  }
});

test('RBAC: a plain user cannot create accounts by posting users.php directly', async ({ browser }) => {
  const user = await browser.newContext({ storageState: ROLES.user.storageState });
  try {
    const token = await csrfTokenFor(user, 'missions.php?type=missions');
    const response = await user.request.post('users.php', {
      form: {
        action: 'create',
        name: MARK + '-smuggled',
        password: 'Rbac-Smuggle-123456',
        role: 'admin',
        _csrf: token,
      },
    });
    expect(response.status(), 'users.manage is enforced on the handler, not the button').toBe(403);
    expect(userCount(MARK + '-smuggled'), 'no account was created').toBe(0);
  } finally {
    await user.close();
  }
});

test('RBAC: catalog write is denied for the user role even with a valid token', async ({ browser }) => {
  const seeded = seedOsRow(MARK + '-rbac-1');
  const user = await browser.newContext({ storageState: ROLES.user.storageState });
  try {
    // Visibility half first: the page renders for the user, but without the
    // delete column (visibility == handler permission).
    const page = await user.newPage();
    await page.goto('os.php');
    await expect(page.locator('tr', { hasText: MARK + '-rbac-1' }).first(), 'the row is readable').toBeVisible();
    await expect(page.locator('button[name="action"][value="delete"]'), 'no delete button renders for the user role').toHaveCount(0);
    await page.close();

    // Handler half: the same action posted directly, with a valid session
    // token, still dies on the permission gate.
    const token = await csrfTokenFor(user, 'missions.php?type=missions');
    const response = await user.request.post('os.php', {
      form: { action: 'delete', os_id: String(seeded.id), _csrf: token },
    });
    expect(response.status(), 'catalog.write is enforced on the handler').toBe(403);
    expect(osRowExists(seeded.id), 'nothing was deleted').toBe(true);
  } finally {
    await user.close();
  }
});

test('anonymous: a POST without a session bounces to the login and executes nothing', async ({ browser }) => {
  const seeded = seedOsRow(MARK + '-anon-1');
  const anonymous = await browser.newContext();
  try {
    const response = await anonymous.request.post('os.php', {
      form: { action: 'delete', os_id: String(seeded.id) },
      maxRedirects: 0,
    });
    expect(response.status(), 'the anonymous POST is redirected').toBe(302);
    expect(String(response.headers()['location'] || ''), 'the target is the login').toContain('login.php');
    expect(osRowExists(seeded.id), 'nothing was deleted').toBe(true);
  } finally {
    await anonymous.close();
  }
});
