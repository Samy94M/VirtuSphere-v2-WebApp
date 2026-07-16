// TESTPLAN 3.3 / E6: the two catalog delete actions. OS and VLAN are
// MECM-/ESXi-owned (ADR-0020/0023), so delete is the only postable action on
// these pages; both prove the Cancel branch by DB state. The VLAN delete only
// renders on retired rows, so the seed retires its row up front.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const PREFIX = 'e2ecat-';

function cleanup() {
  runPhp(`
$db = db();
foreach (['deploy_os' => 'os_name', 'deploy_vlan' => 'vlan_name'] as $table => $column) {
    $stmt = $db->prepare("DELETE FROM {$table} WHERE {$column} LIKE ?");
    $like = '${PREFIX}%';
    $stmt->bind_param('s', $like);
    $stmt->execute();
}
echo 'CLEANED';
`);
}

function rowCount(table, column, name) {
  const data = phpJson(`
$db = db();
$stmt = $db->prepare('SELECT COUNT(*) AS c FROM ${table} WHERE ${column} = ?');
$n = '${name}';
$stmt->bind_param('s', $n);
$stmt->execute();
echo 'JSON' . json_encode(['c' => (int) $stmt->get_result()->fetch_assoc()['c']]) . 'JSON';
`);
  return data.c;
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

// e2e-covers: os.php:delete
// e2e-covers-cancel: os.php:delete
test('OS delete: Cancel keeps the row, Confirm removes it', async ({ page }) => {
  const name = PREFIX + 'os-1';
  runPhp(`
$db = db();
$stmt = $db->prepare("INSERT INTO deploy_os (os_name, os_status) VALUES (?, 'Aktiv')");
$n = '${name}';
$stmt->bind_param('s', $n);
$stmt->execute();
echo 'SEEDED';
`);

  await page.goto('os.php');
  const row = page.locator('tr', { hasText: name }).first();
  const dialog = page.locator('[data-confirm-dialog]');

  await row.locator('button[name="action"][value="delete"]').click();
  await expect(dialog, 'the confirm dialog opens').toBeVisible();
  await expect(page.locator('[data-confirm-msg]'), 'the dialog names the OS').toContainText(name);

  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(rowCount('deploy_os', 'os_name', name), 'Cancel left the OS in the DB').toBe(1);

  await row.locator('button[name="action"][value="delete"]').click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForURL(/os\.php/),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  expect(rowCount('deploy_os', 'os_name', name), 'Confirm deleted the OS').toBe(0);
});

// e2e-covers: vlans.php:delete
// e2e-covers-cancel: vlans.php:delete
test('VLAN delete: only offered for retired rows; Cancel keeps it, Confirm removes it', async ({ page }) => {
  const name = PREFIX + 'vlan-1';
  runPhp(`
$db = db();
$stmt = $db->prepare('INSERT INTO deploy_vlan (vlan_name, retired_at) VALUES (?, NOW())');
$n = '${name}';
$stmt->bind_param('s', $n);
$stmt->execute();
echo 'SEEDED';
`);

  await page.goto('vlans.php?status=retired');
  const row = page.locator('tr', { hasText: name }).first();
  const dialog = page.locator('[data-confirm-dialog]');

  await row.locator('button[type="submit"]').click();
  await expect(dialog, 'the confirm dialog opens').toBeVisible();
  await expect(page.locator('[data-confirm-msg]'), 'the dialog names the VLAN').toContainText(name);

  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(rowCount('deploy_vlan', 'vlan_name', name), 'Cancel left the VLAN in the DB').toBe(1);

  await row.locator('button[type="submit"]').click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForURL(/vlans\.php/),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  expect(rowCount('deploy_vlan', 'vlan_name', name), 'Confirm deleted the VLAN').toBe(0);
});
