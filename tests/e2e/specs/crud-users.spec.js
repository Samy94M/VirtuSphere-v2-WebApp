// TESTPLAN 3.3 / E6: every users.php action once in the browser, verified via
// DB state. The active toggle proves the confirm asymmetry (deactivating asks,
// activating does not, per portal.md), the role change and password reset prove
// their Cancel branches, and clear_lock runs on a genuinely locked account.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { submitAndWaitForNavigation } = require('../lib/navigation');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const PREFIX = 'e2eusr-';
// Long enough for any configurable password policy the dev DB may carry.
const PASSWORD = 'E2eUsers-Abc123456!';

function seedUser(name, extra = '') {
  const data = phpJson(`
$db = db();
$hash = password_hash('${PASSWORD}', PASSWORD_DEFAULT);
$stmt = $db->prepare("INSERT INTO deploy_users (name, password, email, role, is_active, must_change_password) VALUES (?, ?, '', 'user', 1, 0)");
$n = '${name}';
$stmt->bind_param('ss', $n, $hash);
$stmt->execute();
$id = (int) $db->insert_id;
${extra}
echo 'JSON' . json_encode(['id' => $id]) . 'JSON';
`);
  return data.id;
}

function userRow(id) {
  return phpJson(`
$db = db();
$stmt = $db->prepare('SELECT name, password, role, is_active, must_change_password, locked_until FROM deploy_users WHERE id = ? LIMIT 1');
$id = ${Number(id)};
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode($stmt->get_result()->fetch_assoc() ?: null) . 'JSON';
`);
}

function cleanup() {
  runPhp(`
$db = db();
$stmt = $db->prepare("DELETE FROM deploy_users WHERE name LIKE ?");
$like = '${PREFIX}%';
$stmt->bind_param('s', $like);
$stmt->execute();
echo 'CLEANED';
`);
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

function targetRow(page, name) {
  return page.locator('tr', { hasText: name }).first();
}

// e2e-covers: users.php:create
test('create: the account exists with must_change_password set', async ({ page }) => {
  const name = PREFIX + 'new-1';
  await page.goto('users.php');
  const form = page.locator('form:has(input[name="action"][value="create"])');
  await form.locator('input[name="name"]').fill(name);
  await form.locator('input[name="password"]').fill(PASSWORD);
  await form.locator('select[name="role"]').selectOption('user');
  await submitAndWaitForNavigation(page, form.locator('button[type="submit"]'), 'users.php');

  const stored = phpJson(`
$db = db();
$stmt = $db->prepare('SELECT id, is_active, must_change_password FROM deploy_users WHERE name = ? LIMIT 1');
$n = '${name}';
$stmt->bind_param('s', $n);
$stmt->execute();
echo 'JSON' . json_encode($stmt->get_result()->fetch_assoc() ?: null) . 'JSON';
`);
  expect(stored, 'row exists in the DB').not.toBeNull();
  expect(Number(stored.is_active), 'the account is active').toBe(1);
  expect(Number(stored.must_change_password), 'first login forces a password change').toBe(1);

  await page.goto('users.php');
  await expect(targetRow(page, name), 'the account is listed').toBeVisible();
});

// e2e-covers: users.php:set_active
// e2e-covers-cancel: users.php:set_active
test('set_active: deactivating asks (Cancel changes nothing), activating does not ask', async ({ page }) => {
  const name = PREFIX + 'act-1';
  const id = seedUser(name);

  await page.goto('users.php');
  const dialog = page.locator('[data-confirm-dialog]');
  const deactivate = targetRow(page, name).locator('form:has(input[name="action"][value="set_active"]) button');

  // Destructive branch: the toggle asks, Cancel leaves the account active.
  await deactivate.click();
  await expect(dialog, 'deactivating asks first').toBeVisible();
  await expect(page.locator('[data-confirm-msg]'), 'the dialog names the account').toContainText(name);
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(Number(userRow(id).is_active), 'Cancel left the account active').toBe(1);

  // Confirm: the account is deactivated.
  await deactivate.click();
  await expect(dialog).toBeVisible();
  await submitAndWaitForNavigation(page, dialog.locator('[data-confirm-accept]'), 'users.php');
  expect(Number(userRow(id).is_active), 'Confirm deactivated the account').toBe(0);

  // Harmless branch: activating fires without any dialog.
  const activate = targetRow(page, name).locator('form:has(input[name="action"][value="set_active"]) button');
  await expect(activate, 'the activate branch renders no confirm').not.toHaveAttribute('data-confirm', /./);
  await submitAndWaitForNavigation(page, activate, 'users.php');
  expect(Number(userRow(id).is_active), 'the account is active again').toBe(1);
});

// e2e-covers: users.php:set_role
// e2e-covers-cancel: users.php:set_role
test('set_role: Cancel keeps the role, Confirm changes it', async ({ page }) => {
  const name = PREFIX + 'role-1';
  const id = seedUser(name);

  await page.goto('users.php');
  const dialog = page.locator('[data-confirm-dialog]');
  const form = targetRow(page, name).locator('form:has(input[name="action"][value="set_role"])');
  await form.locator('select[name="role"]').selectOption('admin');

  await form.locator('button[type="submit"]').click();
  await expect(dialog, 'the role change asks first').toBeVisible();
  await expect(page.locator('[data-confirm-msg]'), 'the dialog names the account').toContainText(name);
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(userRow(id).role, 'Cancel kept the role').toBe('user');

  await form.locator('button[type="submit"]').click();
  await expect(dialog).toBeVisible();
  await submitAndWaitForNavigation(page, dialog.locator('[data-confirm-accept]'), 'users.php');
  expect(userRow(id).role, 'Confirm changed the role').toBe('admin');
});

// e2e-covers: users.php:reset_password
// e2e-covers-cancel: users.php:reset_password
test('reset_password: Cancel keeps the hash, Confirm replaces it and forces a change', async ({ page }) => {
  const name = PREFIX + 'pw-1';
  const id = seedUser(name);
  const before = userRow(id);

  await page.goto('users.php');
  const dialog = page.locator('[data-confirm-dialog]');
  const form = targetRow(page, name).locator('form:has(input[name="action"][value="reset_password"])');
  await form.locator('input[name="password"]').fill(PASSWORD + 'x');

  await form.locator('button[type="submit"]').click();
  await expect(dialog, 'the reset asks first').toBeVisible();
  await expect(page.locator('[data-confirm-msg]'), 'the dialog names the account').toContainText(name);
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(userRow(id).password, 'Cancel kept the password hash').toBe(before.password);

  await form.locator('button[type="submit"]').click();
  await expect(dialog).toBeVisible();
  await submitAndWaitForNavigation(page, dialog.locator('[data-confirm-accept]'), 'users.php');
  const after = userRow(id);
  expect(after.password, 'Confirm replaced the hash').not.toBe(before.password);
  expect(Number(after.must_change_password), 'the next login forces a change').toBe(1);
});

// e2e-covers: users.php:clear_lock
test('clear_lock: unlocking a locked account clears locked_until without a prompt', async ({ page }) => {
  const name = PREFIX + 'lock-1';
  const id = seedUser(name, `
$stmt = $db->prepare('UPDATE deploy_users SET locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
`);
  expect(userRow(id).locked_until, 'the seed produced a locked account').not.toBeNull();

  await page.goto('users.php');
  const unlock = targetRow(page, name).locator('form:has(input[name="action"][value="clear_lock"]) button');
  await expect(unlock, 'unlocking is a reversible remediation and needs no prompt').not.toHaveAttribute('data-confirm', /./);
  await submitAndWaitForNavigation(page, unlock, 'users.php');
  expect(userRow(id).locked_until, 'the lock is cleared').toBeNull();
});
