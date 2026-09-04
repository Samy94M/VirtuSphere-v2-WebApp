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

// Etappe 15.4: the three catalogs share one status filter and tell an empty
// catalog from an empty filter result.
//
// The filtered branch is seeded rather than assumed: the spec creates one
// retired row of its own, so `status=retired` is guaranteed non-empty and
// `status=active` is guaranteed to hold it back, and both sentences are then
// observable on a stack of any prior content.
test('catalog filter: one shared control on all three pages', async ({ page }) => {
  for (const url of ['os.php', 'packages.php', 'vlans.php']) {
    await page.goto(url);
    const select = page.locator('form select[name="status"]');
    await expect(select, `${url} renders the shared status filter`).toHaveCount(1);
    await expect(select.locator('option'), `${url} offers the three shared tokens`).toHaveCount(3);
    // vlans.php used to render a row of links instead. Only those two tokens
    // are checked: `status=all` stays a legal href because the empty state
    // offers it as the way out of a narrowing filter, and the sort headers
    // legitimately carry the CURRENT status alongside their sort parameters,
    // so a prefix match would flag the sorting instead of a second control.
    for (const token of ['active', 'retired']) {
      await expect(
        page.locator(`a[href="${url}?status=${token}"]`),
        `${url} keeps no second filter control`
      ).toHaveCount(0);
    }
  }
});

test('catalog empty states: a filtered miss offers the way out, an all-view does not', async ({ page }) => {
  const name = PREFIX + 'retired-os';
  runPhp(`
$db = db();
$stmt = $db->prepare("INSERT INTO deploy_os (os_name, os_status, retired_at) VALUES (?, 'Retired', NOW())");
$n = '${name}';
$stmt->bind_param('s', $n);
$stmt->execute();
echo 'SEEDED';
`);

  // The retired view holds the seeded row, so it is never the empty case.
  await page.goto('os.php?status=retired');
  await expect(page.locator('tbody tr', { hasText: name })).toHaveCount(1);

  // Whether the active view is empty depends on what else the stack holds, so
  // the expectation is DERIVED from the catalog rather than assumed - and it is
  // always asserted. A spec that skipped the check when the table happened to
  // have rows would read exactly like a passing one.
  const counts = phpJson(`
$db = db();
$r = $db->query("SELECT COUNT(*) a, SUM(os_status <> 'Retired') n FROM deploy_os")->fetch_assoc();
echo 'JSON' . json_encode(['all' => (int) $r['a'], 'active' => (int) $r['n']]) . 'JSON';
`);

  await page.goto('os.php?status=active');
  const activeEmpty = page.locator('td.table-empty');
  await expect(activeEmpty, 'the active view is empty exactly when no active row exists').toHaveCount(counts.active === 0 ? 1 : 0);
  if (counts.active === 0) {
    await expect(activeEmpty.locator('a[href="os.php?status=all"]'), 'a narrowing filter that matched nothing carries the way out').toHaveCount(1);
  }

  // Under "all" there is nothing left to widen, so no way out is ever offered.
  // The catalog holds the seeded row, so this view is never empty either.
  await page.goto('os.php?status=all');
  await expect(page.locator('td.table-empty'), 'the seeded row makes the all-view non-empty').toHaveCount(0);
  await expect(page.locator('a[href="os.php?status=all"]'), 'no way out is offered where there is no filter to widen').toHaveCount(0);
});
