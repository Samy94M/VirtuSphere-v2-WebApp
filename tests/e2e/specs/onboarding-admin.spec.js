// Etappe 10 exit proof: a freshly seeded admin must be able to follow the
// operator-first path from forced password change through every setup area.

const { test, expect } = require('@playwright/test');
const { runPhp } = require('../lib/php');
const { submitAndWaitForNavigation } = require('../lib/navigation');

const USERNAME = 'e2e-onboarding-admin';
const INITIAL_PASSWORD = 'E2e-Onboarding-Initial-123456789!';
const CHANGED_PASSWORD = 'E2e-Onboarding-Changed-987654321!';

function seedFreshAdmin() {
  runPhp(`
$db = db();
$name = '${USERNAME}';
$stmt = $db->prepare('DELETE FROM deploy_users WHERE name = ?');
$stmt->bind_param('s', $name);
$stmt->execute();
$hash = password_hash('${INITIAL_PASSWORD}', PASSWORD_DEFAULT);
$role = 'admin';
$stmt = $db->prepare("INSERT INTO deploy_users (name, password, email, role, is_active, must_change_password) VALUES (?, ?, '', ?, 1, 1)");
$stmt->bind_param('sss', $name, $hash, $role);
$stmt->execute();
echo 'SEEDED';
`);
}

function removeFreshAdmin() {
  runPhp(`
$db = db();
$name = '${USERNAME}';
$stmt = $db->prepare('DELETE FROM deploy_users WHERE name = ?');
$stmt->bind_param('s', $name);
$stmt->execute();
echo 'CLEANED';
`);
}

async function followNav(page, href, expectedPath) {
  await Promise.all([
    page.waitForURL((url) => url.pathname.endsWith(expectedPath)),
    page.locator(`a[href="${href}"]`).first().click(),
  ]);
}

test.beforeEach(() => seedFreshAdmin());
test.afterEach(() => removeFreshAdmin());

test('new admin completes password change, help, setup and operations click path', async ({ page }) => {
  await page.goto('login.php');
  await page.locator('#username').fill(USERNAME);
  await page.locator('#password').fill(INITIAL_PASSWORD);
  await Promise.all([
    page.waitForURL(/account\.php$/),
    page.locator('button[type="submit"]').click(),
  ]);

  await expect(page.locator('.alert-error')).toBeVisible();
  await page.goto('dashboard.php');
  await expect(page).toHaveURL(/account\.php$/);

  const passwordForm = page.locator('form[action="account.php"]');
  await passwordForm.locator('[name="current_password"]').fill(INITIAL_PASSWORD);
  await passwordForm.locator('[name="new_password"]').fill(CHANGED_PASSWORD);
  await passwordForm.locator('[name="confirm_password"]').fill(CHANGED_PASSWORD);
  const response = await submitAndWaitForNavigation(page, passwordForm.locator('button[type="submit"]'), 'account.php');
  expect(response.status()).toBe(302);
  await expect(page).toHaveURL(/dashboard\.php$/);

  await followNav(page, 'help.php', '/portal/help.php');
  for (const panel of ['overview', 'missions', 'packages', 'deploy', 'system-status', 'stack', 'users', 'credentials', 'settings']) {
    const tab = page.locator(`#tab-${panel}`);
    await expect(tab).toBeVisible();
    await tab.click();
    await expect(page.locator(`#panel-${panel}`)).toBeVisible();
  }

  const destinations = [
    ['credentials.php', '/portal/credentials.php'],
    ['settings.php', '/portal/settings.php'],
    ['os.php', '/portal/os.php'],
    ['vlans.php', '/portal/vlans.php'],
    ['packages.php', '/portal/packages.php'],
    ['missions.php?type=missions', '/portal/missions.php'],
    ['deploy.php', '/portal/deploy.php'],
    ['system_status.php', '/portal/system_status.php'],
    ['logs.php', '/portal/logs.php'],
  ];
  for (const [href, path] of destinations) {
    await followNav(page, href, path);
  }
});
