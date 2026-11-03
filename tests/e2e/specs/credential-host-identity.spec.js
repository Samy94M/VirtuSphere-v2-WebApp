const { test, expect } = require('../lib/test');
const { ROLES } = require('../lib/auth');
const { phpJson, runPhp } = require('../lib/php');
const { submitAndWaitForNavigation } = require('../lib/navigation');

test.use({ storageState: ROLES.admin.storageState });
const PREFIX = 'e2e-host-identity-';

// The fixture is a provisional pin: the observed key is the pinned one, and the row opens by itself (UX-HK).
function seed(suffix) {
  return phpJson(`
$db = db();
$user = (int) $db->query("SELECT id FROM deploy_users WHERE role = 'admin' LIMIT 1")->fetch_assoc()['id'];
$id = repo_create_credential($db, ['type' => 'ansible', 'name' => '${PREFIX}${suffix}', 'host' => 'fixture.invalid', 'port' => 22, 'username' => 'worker'], 'fixture-password', $user);
$stmt = $db->prepare('UPDATE deploy_credentials SET ansible_host_accept_new = 1 WHERE id = ?');
$stmt->bind_param('i', $id); $stmt->execute();
$identity = ['type' => 'ssh-ed25519', 'fingerprint' => 'SHA256:' . rtrim(base64_encode(hash('sha256', 'public-fixture', true)), '=')];
$other = 'SHA256:' . rtrim(base64_encode(hash('sha256', 'public-fixture-other', true)), '=');
repo_verify_ansible_host_identity($db, repo_credential($db, $id), $identity);
echo 'JSON' . json_encode(['id' => $id, 'fingerprint' => $identity['fingerprint'], 'other' => $other]) . 'JSON';
`, ['lib/repo/credential_host_identity.php']);
}

function state(id) {
  return phpJson(`
$stmt = db()->prepare('SELECT ansible_host_fingerprint AS fingerprint, ansible_host_confirmed_at AS confirmed_at, ansible_host_confirmed_by AS confirmed_by, config_revision AS revision FROM deploy_credentials WHERE id = ?');
$id = ${Number(id)}; $stmt->bind_param('i', $id); $stmt->execute();
echo 'JSON' . json_encode($stmt->get_result()->fetch_assoc()) . 'JSON';
`);
}

function form(page, id) {
  return page.locator('form:has(input[name="action"][value="confirm_host_identity"]):has(input[name="credential_id"][value="' + id + '"])');
}

test.afterAll(() => {
  runPhp(`
$db = db(); $prefix = '${PREFIX}%';
$stmt = $db->prepare("DELETE l FROM deploy_logs l JOIN deploy_credentials c ON CAST(l.object_id AS UNSIGNED) = c.id WHERE l.object_type = 'credential' AND c.name LIKE ?");
$stmt->bind_param('s', $prefix); $stmt->execute();
$stmt = $db->prepare('DELETE FROM deploy_credentials WHERE name LIKE ?');
$stmt->bind_param('s', $prefix); $stmt->execute();
echo 'CLEANED';
`);
});

// e2e-covers: credentials.php:confirm_host_identity
// e2e-covers-cancel: credentials.php:confirm_host_identity
test('host confirmation preserves the provisional pin on cancel and records the admin on confirm', async ({ page }) => {
  const fixture = seed('dialog');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('credentials.php');
  const panel = form(page, fixture.id);
  // The row is open by itself while the key is provisional; the type comes from the observed key.
  await expect(panel).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  await expect(panel.locator('[name="host_fingerprint"]')).toHaveValue('');
  await expect(panel.locator('[name="host_key_type"]')).toHaveCount(0);
  await panel.locator('[name="host_fingerprint"]').fill(fixture.fingerprint);
  const before = state(fixture.id);
  await panel.locator('button[type="submit"]').click();
  const dialog = page.locator('[data-confirm-dialog]');
  await expect(dialog).toBeVisible();
  await dialog.locator('button[value="cancel"]').click();
  expect(state(fixture.id)).toEqual(before);
  await expect(panel.locator('[name="host_fingerprint"]')).toHaveValue(fixture.fingerprint);
  await panel.locator('button[type="submit"]').click();
  await submitAndWaitForNavigation(page, dialog.locator('[data-confirm-accept]'), 'credentials.php');
  const after = state(fixture.id);
  expect(after.fingerprint).toBe(fixture.fingerprint);
  expect(after.confirmed_at).not.toBeNull();
  expect(Number(after.confirmed_by)).toBeGreaterThan(0);
  expect(after.revision).toBe(before.revision);
  await page.goto('system_status.php');
  await expect(page.locator('#credential-' + fixture.id)).toContainText(fixture.fingerprint);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});

test('host confirmation remains usable without JavaScript', async ({ browser }) => {
  const fixture = seed('no-js');
  const context = await browser.newContext({ storageState: ROLES.admin.storageState, javaScriptEnabled: false, baseURL: test.info().project.use.baseURL });
  try {
    const page = await context.newPage();
    await page.goto('credentials.php');
    const panel = form(page, fixture.id);
    await expect(panel).toBeVisible();
    await expect(panel.locator('[name="host_fingerprint"]')).toHaveValue('');
    await panel.locator('[name="host_fingerprint"]').fill(fixture.fingerprint);
    await submitAndWaitForNavigation(page, panel.locator('button[type="submit"]'), 'credentials.php');
    expect(state(fixture.id).confirmed_at).not.toBeNull();
  } finally { await context.close(); }
});

// UX-HK second step: a value that differs from the pin is shown with both values and saved only on "Pin ersetzen".
test('a deviating host key is saved only after the second confirmation', async ({ page }) => {
  const fixture = seed('deviation');
  await page.goto('credentials.php');
  const panel = form(page, fixture.id);
  await expect(panel).toBeVisible();
  const before = state(fixture.id);
  await panel.locator('[name="host_fingerprint"]').fill('256 ' + fixture.other + ' root@fixture.invalid (ED25519)');
  await panel.locator('button[type="submit"]').click();
  const dialog = page.locator('[data-confirm-dialog]');
  await submitAndWaitForNavigation(page, dialog.locator('[data-confirm-accept]'), 'credentials.php');
  const warning = page.locator('[data-host-identity-deviation]');
  await expect(warning).toBeVisible();
  await expect(warning).toContainText(fixture.other);
  expect(state(fixture.id)).toEqual(before);
  await warning.locator('form.host-identity-replace button[type="submit"]').click();
  await submitAndWaitForNavigation(page, dialog.locator('[data-confirm-accept]'), 'credentials.php');
  expect(state(fixture.id).fingerprint).toBe(fixture.other);
});

// UX-HK anchor: the System status link and the test result open the credential's own row.
test('the host identity anchor opens the credential row', async ({ page }) => {
  const fixture = seed('anchor');
  await page.goto('system_status.php');
  await expect(page.locator('a[href="credentials.php#host-identity-' + fixture.id + '"]').first()).toBeVisible();
  await page.goto('credentials.php#host-identity-' + fixture.id);
  await expect(page.locator('#host-identity-' + fixture.id)).toBeVisible();
  await expect(page.locator('#host-identity-' + fixture.id + ' details')).toHaveAttribute('open', '');
});

test('host confirmation requires credentials.manage before looking up the credential', async ({ browser }) => {
  const fixture = seed('permission');
  const context = await browser.newContext({ storageState: ROLES.user.storageState, baseURL: test.info().project.use.baseURL });
  try {
    const response = await context.request.post('credentials.php', {
      form: { action: 'confirm_host_identity', credential_id: String(fixture.id) },
    });
    expect(response.status()).toBe(403);
    expect(state(fixture.id).confirmed_at).toBeNull();
  } finally { await context.close(); }
});
