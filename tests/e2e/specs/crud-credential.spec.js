// TESTPLAN 3.3 / E6: CRUD round-trip for the credential entity, verified through
// state (DB + fresh GET), never through the POST response. The delete proves
// both dialog branches; the connection test runs against a closed local port so
// it fails fast, deterministically and without leaving the air gap.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const PREFIX = 'e2ecred-';

function seedCredential(name, host = '127.0.0.1', port = 1) {
  const data = phpJson(`
$db = db();
$admin = (int) ($db->query("SELECT id FROM deploy_users WHERE role='admin' LIMIT 1")->fetch_assoc()['id'] ?? 1);
$id = repo_create_credential($db, ['type' => 'esxi', 'name' => '${name}', 'host' => '${host}', 'port' => ${Number(port)}, 'username' => 'root'], 'secret123', $admin);
echo 'JSON' . json_encode(['id' => $id]) . 'JSON';
`, ['lib/repo/credentials.php']);
  return data.id;
}

function credentialRow(id) {
  return phpJson(`
$db = db();
$stmt = $db->prepare('SELECT name, host, username FROM deploy_credentials WHERE id = ? LIMIT 1');
$id = ${Number(id)};
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode($stmt->get_result()->fetch_assoc() ?: null) . 'JSON';
`);
}

function idByName(name) {
  const data = phpJson(`
$db = db();
$stmt = $db->prepare('SELECT id FROM deploy_credentials WHERE name = ? ORDER BY id DESC LIMIT 1');
$n = '${name}';
$stmt->bind_param('s', $n);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
echo 'JSON' . json_encode(['id' => $row ? (int) $row['id'] : 0]) . 'JSON';
`);
  return data.id;
}

function cleanup() {
  runPhp(`
$db = db();
$stmt = $db->prepare("DELETE FROM deploy_credentials WHERE name LIKE ?");
$like = '${PREFIX}%';
$stmt->bind_param('s', $like);
$stmt->execute();
echo 'CLEANED';
`);
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

// e2e-covers: credentials.php:create
test('create: the credential appears in the DB and in a fresh GET', async ({ page }) => {
  const name = PREFIX + 'create-1';
  await page.goto('credentials.php');
  const form = page.locator('form:has(input[name="action"][value="create"])');
  await form.locator('select[name="type"]').selectOption('esxi');
  await form.locator('input[name="name"]').fill(name);
  await form.locator('input[name="host"]').fill('127.0.0.1');
  await form.locator('input[name="port"]').fill('1');
  await form.locator('input[name="username"]').fill('root');
  await form.locator('input[name="secret"]').fill('e2e-secret-123');
  await Promise.all([
    page.waitForURL(/credentials\.php/),
    form.locator('button[type="submit"]').click(),
  ]);

  const id = idByName(name);
  expect(id, 'row exists in the DB').toBeGreaterThan(0);

  await page.goto('credentials.php');
  await expect(page.locator('tr', { hasText: name }).first(), 'the credential is listed').toBeVisible();
});

// e2e-covers: credentials.php:update
test('update: a host change persists and the username neighbor is untouched', async ({ page }) => {
  const name = PREFIX + 'edit-1';
  const id = seedCredential(name);

  await page.goto('credentials.php');
  const row = page.locator('tr', { hasText: name }).first();
  await row.locator(`button[data-row-toggle="credential-editor-${id}"]`).click();

  const editor = page.locator(`#credential-editor-${id}`);
  await expect(editor).toBeVisible();
  await editor.locator('input[name="host"]').fill('127.0.0.2');
  await Promise.all([
    page.waitForURL(/credentials\.php/),
    editor.locator('button[type="submit"]').click(),
  ]);

  const stored = credentialRow(id);
  expect(stored.host, 'the host change persisted').toBe('127.0.0.2');
  expect(stored.username, 'the username neighbor is unchanged').toBe('root');
});

// e2e-covers: credentials.php:test
test('test: a failing connection reports a categorized flash, not a crash, and writes nothing', async ({ page }) => {
  const name = PREFIX + 'test-1';
  const id = seedCredential(name); // 127.0.0.1:1 -> connection refused, fast.
  const before = credentialRow(id);

  await page.goto('credentials.php');
  const row = page.locator('tr', { hasText: name }).first();
  const response = await Promise.all([
    page.waitForResponse((r) => r.url().includes('credentials.php') && r.request().method() === 'POST'),
    row.locator('button[name="action"][value="test"]').click(),
  ]).then(([r]) => r);

  expect(response.status(), 'the failed test is not a server error').toBeLessThan(500);
  const alert = page.locator('.alert-error, .alert').first();
  await expect(alert, 'the operator sees a result sentence').toBeVisible();

  expect(credentialRow(id), 'the test wrote nothing').toEqual(before);
});

// e2e-covers: credentials.php:delete
// e2e-covers-cancel: credentials.php:delete
test('delete: Cancel keeps the row, Confirm removes it', async ({ page }) => {
  const name = PREFIX + 'del-1';
  const id = seedCredential(name);

  await page.goto('credentials.php');
  const row = page.locator('tr', { hasText: name }).first();
  const dialog = page.locator('[data-confirm-dialog]');

  await row.locator('button[name="action"][value="delete"]').click();
  await expect(dialog, 'the confirm dialog opens').toBeVisible();
  await expect(page.locator('[data-confirm-msg]'), 'the dialog names the credential').toContainText(name);

  // Cancel: nothing is deleted (DB proof).
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(credentialRow(id), 'Cancel left the credential in the DB').not.toBeNull();

  // Confirm: the row is gone from the DB and the list.
  await row.locator('button[name="action"][value="delete"]').click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForURL(/credentials\.php/),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  expect(credentialRow(id), 'Confirm deleted the credential').toBeNull();
  await expect(page.locator('tr', { hasText: name }), 'the row is gone from the list').toHaveCount(0);
});
