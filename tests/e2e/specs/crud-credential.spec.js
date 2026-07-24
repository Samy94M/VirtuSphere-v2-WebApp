// TESTPLAN 3.3 / E6: CRUD round-trip for the credential entity, verified through
// state (DB + fresh GET), never through the POST response. The delete proves
// both dialog branches; the connection test runs against a closed local port so
// it fails fast, deterministically and without leaving the air gap.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { submitAndWaitForNavigation } = require('../lib/navigation');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const PREFIX = 'e2ecred-';

function seedCredential(name, host = '127.0.0.1', port = 1, type = 'esxi') {
  const data = phpJson(`
$db = db();
$admin = (int) ($db->query("SELECT id FROM deploy_users WHERE role='admin' LIMIT 1")->fetch_assoc()['id'] ?? 1);
$id = repo_create_credential($db, ['type' => '${type}', 'name' => '${name}', 'host' => '${host}', 'port' => ${Number(port)}, 'username' => 'root'], 'secret123', $admin);
echo 'JSON' . json_encode(['id' => $id]) . 'JSON';
`, ['lib/repo/credentials.php']);
  return data.id;
}

function credentialRow(id) {
  return phpJson(`
$db = db();
$stmt = $db->prepare('SELECT type, name, host, port, username FROM deploy_credentials WHERE id = ? LIMIT 1');
$id = ${Number(id)};
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode($stmt->get_result()->fetch_assoc() ?: null) . 'JSON';
`);
}

function credentialSecret(id) {
  return phpJson(`
$id = ${Number(id)};
echo 'JSON' . json_encode(['secret' => repo_credential_secret(db(), $id)]) . 'JSON';
`, ['lib/repo/credentials.php']).secret;
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
$stmt = $db->prepare("DELETE FROM deploy_jobs WHERE credential_esxi_id IN (SELECT id FROM deploy_credentials WHERE name LIKE ?) OR credential_ansible_id IN (SELECT id FROM deploy_credentials WHERE name LIKE ?)");
$like = '${PREFIX}%';
$stmt->bind_param('ss', $like, $like);
$stmt->execute();
$stmt = $db->prepare("DELETE FROM deploy_credentials WHERE name LIKE ?");
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
  await submitAndWaitForNavigation(page, form.locator('button[type="submit"]'), 'credentials.php');

  const id = idByName(name);
  expect(id, 'row exists in the DB').toBeGreaterThan(0);

  await page.goto('credentials.php');
  const row = page.locator('tr', { hasText: name }).first();
  await expect(row, 'the credential is listed').toBeVisible();
  await expect(row.locator('form:has(input[name="action"][value="test"]) button')).toHaveText(/Inventarabruf starten|Start inventory pull/);
  await expect(row.locator(`a[href="system_status.php?inventory=${id}#credential-${id}"]`)).toBeVisible();
  await expect(page.locator(`#credential-editor-${id} input[name="secret"]`)).toHaveValue('');
});

test('create validation: required fields, type, host, lengths and port bounds stay field-bound', async ({ page }) => {
  await page.goto('credentials.php');
  let form = page.locator('form:has(input[name="action"][value="create"])');
  await form.locator('select[name="type"]').evaluate((select) => {
    select.add(new Option('invalid', 'invalid'));
    select.value = 'invalid';
  });
  await form.locator('input[name="name"]').fill('');
  await form.locator('input[name="host"]').fill('');
  await form.locator('input[name="port"]').fill('0');
  await form.locator('input[name="username"]').fill('');
  await form.locator('input[name="secret"]').fill('');
  await form.evaluate((node) => { node.noValidate = true; });
  await submitAndWaitForNavigation(page, form.locator('button[type="submit"]'), 'credentials.php');
  form = page.locator('form:has(input[name="action"][value="create"])');
  await expect(form.locator('.field-error')).toHaveCount(6);
  expect(idByName(PREFIX + 'invalid-never-written')).toBe(0);

  await form.locator('select[name="type"]').selectOption('ansible');
  await form.locator('input[name="name"]').fill('n'.repeat(192));
  await form.locator('input[name="host"]').fill('https://ansible.invalid/path');
  await form.locator('input[name="port"]').fill('65536');
  await form.locator('input[name="username"]').fill('u'.repeat(192));
  await form.locator('input[name="secret"]').fill('secret-for-validation');
  await form.evaluate((node) => { node.noValidate = true; });
  await submitAndWaitForNavigation(page, form.locator('button[type="submit"]'), 'credentials.php');
  form = page.locator('form:has(input[name="action"][value="create"])');
  await expect(form.locator('.field-error')).toHaveCount(4);
  await expect(form.locator('input[name="host"]')).toHaveValue('https://ansible.invalid/path');
  await expect(form.locator('input[name="port"]')).toHaveValue('65536');
  await expect(form.locator('input[name="secret"]'), 'a rejected secret is never echoed back').toHaveValue('');

  const validName = PREFIX + 'upper-port';
  await form.locator('input[name="name"]').fill(validName);
  await form.locator('input[name="host"]').fill('::1');
  await form.locator('input[name="port"]').fill('65535');
  await form.locator('input[name="username"]').fill('ansible');
  await form.locator('input[name="secret"]').fill('valid-secret-123');
  await submitAndWaitForNavigation(page, form.locator('button[type="submit"]'), 'credentials.php');
  const id = idByName(validName);
  expect(id).toBeGreaterThan(0);
  expect(Number(credentialRow(id).port)).toBe(65535);
});

// e2e-covers: credentials.php:update
test('update: every editable field round-trips, blank secret stays and a later secret rotates', async ({ page }) => {
  const name = PREFIX + 'edit-1';
  const id = seedCredential(name);

  await page.goto('credentials.php');
  const row = page.locator('tr', { hasText: name }).first();
  await row.locator(`button[data-row-toggle="credential-editor-${id}"]`).click();

  const editor = page.locator(`#credential-editor-${id}`);
  await expect(editor).toBeVisible();
  await row.locator(`button[data-row-toggle="credential-editor-${id}"]`).click();
  await expect(editor).toBeHidden();
  await row.locator(`button[data-row-toggle="credential-editor-${id}"]`).click();
  await editor.locator('select[name="type"]').selectOption('esxi');
  await editor.locator('input[name="name"]').fill(PREFIX + 'edit-renamed');
  await editor.locator('input[name="host"]').fill('127.0.0.2');
  await editor.locator('input[name="port"]').fill('444');
  await editor.locator('input[name="username"]').fill('administrator');
  await expect(editor.locator('input[name="secret"]')).toHaveValue('');
  await submitAndWaitForNavigation(page, editor.locator('button[type="submit"]'), 'credentials.php');

  const stored = credentialRow(id);
  expect(stored.type).toBe('esxi');
  expect(stored.name).toBe(PREFIX + 'edit-renamed');
  expect(stored.host, 'the host change persisted').toBe('127.0.0.2');
  expect(Number(stored.port)).toBe(444);
  expect(stored.username).toBe('administrator');
  expect(credentialSecret(id), 'blank secret keeps the encrypted value').toBe('secret123');

  await page.goto('credentials.php');
  const renamed = page.locator('tr', { hasText: PREFIX + 'edit-renamed' }).first();
  await renamed.locator(`button[data-row-toggle="credential-editor-${id}"]`).click();
  const reopened = page.locator(`#credential-editor-${id}`);
  await reopened.locator('input[name="secret"]').fill('rotated-secret-456');
  await submitAndWaitForNavigation(page, reopened.locator('button[type="submit"]'), 'credentials.php');
  expect(credentialSecret(id)).toBe('rotated-secret-456');
  await page.goto('credentials.php');
  await expect(page.locator(`#credential-editor-${id} input[name="secret"]`), 'the secret is never rendered back').toHaveValue('');
});

// e2e-covers: credentials.php:test
test('Ansible test: a failing connection is categorized, persisted and linked to Systemstatus', async ({ page }) => {
  const name = PREFIX + 'test-1';
  const id = seedCredential(name, '127.0.0.1', 1, 'ansible'); // closed local SSH port, fast.
  const before = credentialRow(id);

  await page.goto('credentials.php');
  const row = page.locator('tr', { hasText: name }).first();
  const response = await Promise.all([
    page.waitForResponse((r) => r.url().includes('credentials.php') && r.request().method() === 'POST'),
    row.locator('form:has(input[name="action"][value="test"]) button').click(),
  ]).then(([r]) => r);

  expect(response.status(), 'the failed test is not a server error').toBeLessThan(500);
  const alert = page.locator('.alert-error, .alert').first();
  await expect(alert, 'the operator sees a result sentence').toBeVisible();
  expect(credentialRow(id), 'the connection test does not rewrite the credential').toEqual(before);
  const refreshedRow = page.locator('tr', { hasText: name }).first();
  await expect(refreshedRow.locator('form:has(input[name="action"][value="test"]) button')).toHaveText(/Verbindung und Umgebung prüfen|Check connection and environment/);
  await expect(refreshedRow.locator(`a[href="system_status.php#credential-${id}"]`)).toBeVisible();
  await expect(refreshedRow.locator('.status-time')).not.toHaveText(/Nie|Never|never/);
  const proof = phpJson(`
$db = db();
$state = $db->query('SELECT last_status, last_checked_at FROM deploy_ansible_preflight_state WHERE credential_id = ${Number(id)}')->fetch_assoc();
$audit = $db->query("SELECT category FROM deploy_logs WHERE category = 'credentials' AND log_message LIKE 'tested credential id ${Number(id)}:%' ORDER BY id DESC LIMIT 1")->fetch_assoc();
echo 'JSON' . json_encode(['state' => $state, 'audit' => $audit]) . 'JSON';
`);
  expect(proof.state.last_status).toBe('failed');
  expect(proof.audit.category).toBe('credentials');
});

test('ESXi action: starts an inventory job and links to the exact status card', async ({ page }) => {
  const selectionBefore = phpJson(`
$row = repo_setting(db(), VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL);
echo 'JSON' . json_encode(['row' => $row]) . 'JSON';
`, ['lib/deploy_constants.php', 'lib/repo/settings.php']);
  const ansibleId = seedCredential(PREFIX + 'inventory-ansible', '127.0.0.1', 1, 'ansible');
  const esxiId = seedCredential(PREFIX + 'inventory-esxi', '127.0.0.1', 1, 'esxi');
  try {
    runPhp(`repo_set_setting(db(), VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL, '${ansibleId}'); echo 'SELECTED';`, ['lib/deploy_constants.php', 'lib/repo/settings.php']);
    await page.goto('credentials.php');
    const row = page.locator('tr', { hasText: PREFIX + 'inventory-esxi' }).first();
    const button = row.locator('form:has(input[name="action"][value="test"]) button');
    await expect(button).toHaveText(/Inventarabruf starten|Start inventory pull/);
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('credentials.php') && r.request().method() === 'POST'),
      button.click(),
    ]);
    await expect(page.locator('[data-flash]').first()).toBeVisible();
    await expect(page.locator(`[data-flash] a[href="system_status.php?inventory=${esxiId}#credential-${esxiId}"]`)).toBeVisible();
    const proof = phpJson(`
$db = db();
$jobs = (int) $db->query("SELECT COUNT(*) AS c FROM deploy_jobs WHERE mission_id IS NULL AND credential_esxi_id = ${esxiId} AND credential_ansible_id = ${ansibleId}")->fetch_assoc()['c'];
$audit = $db->query("SELECT category, log_message FROM deploy_logs WHERE category = 'deploy' AND log_message LIKE 'requested ESXi inventory pull for credential id ${esxiId}%' ORDER BY id DESC LIMIT 1")->fetch_assoc();
echo 'JSON' . json_encode(['jobs' => $jobs, 'audit' => $audit]) . 'JSON';
`);
    expect(proof.jobs).toBeGreaterThan(0);
    expect(proof.audit.category).toBe('deploy');
  } finally {
    if (selectionBefore.row === null) {
      runPhp(`repo_delete_setting(db(), VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL); echo 'RESTORED';`, ['lib/deploy_constants.php', 'lib/repo/settings.php']);
    } else {
      runPhp(`repo_set_setting(db(), VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL, '${String(selectionBefore.row?.setting_value || '')}'); echo 'RESTORED';`, ['lib/deploy_constants.php', 'lib/repo/settings.php']);
    }
  }
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
  await submitAndWaitForNavigation(page, dialog.locator('[data-confirm-accept]'), 'credentials.php');
  expect(credentialRow(id), 'Confirm deleted the credential').toBeNull();
  await expect(page.locator('tr', { hasText: name }), 'the row is gone from the list').toHaveCount(0);
});

/** Writes a preflight result whose age the test controls. */
function seedPreflight(id, status, daysAgo) {
  runPhp(`
$db = db();
$db->query("INSERT INTO deploy_ansible_preflight_state (credential_id, last_status, last_checked_at, last_component)
  VALUES (${Number(id)}, '${status}', DATE_SUB(NOW(), INTERVAL ${Number(daysAgo)} DAY), NULL)
  ON DUPLICATE KEY UPDATE last_status = VALUES(last_status), last_checked_at = VALUES(last_checked_at)");
echo 'SEEDED';
`);
}

/**
 * Reads and writes the global "which Ansible host runs inventory pulls"
 * setting. The cadence test has to own it for its duration: other specs seed
 * extra Ansible credentials and point this setting at them, and a selection
 * left dangling resolves to "no host", which is itself one of the cadence
 * branches. Ambient state would decide which sentence the row renders.
 */
function inventoryAnsibleSetting(value = null) {
  return phpJson(`
$db = db();
$key = VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL;
$before = repo_setting_value($db, $key, '');
${value === null ? '' : `repo_set_setting($db, $key, '${String(Number(value))}');`}
echo 'JSON' . json_encode(['before' => $before]) . 'JSON';
`, ['lib/deploy_constants.php', 'lib/repo/settings.php']).before;
}

function cleanupStatusRows() {
  runPhp(`
$db = db();
$like = '${PREFIX}%';
foreach (['deploy_ansible_preflight_state', 'deploy_esxi_inventory_state'] as $table) {
    $stmt = $db->prepare("DELETE FROM {$table} WHERE credential_id IN (SELECT id FROM deploy_credentials WHERE name LIKE ?)");
    $stmt->bind_param('s', $like);
    $stmt->execute();
}
echo 'CLEANED';
`);
}

// The status column stacks a badge over a timestamp, which reads as "last poll"
// everywhere else in the portal. Only ESXi is polled. These two facts are what
// the cadence line and the ageing Ansible badge exist to state, and both are
// only observable in the rendered row, which is why they are pinned here rather
// than in a unit test. Locale-independent on purpose: the assertions compare
// rendered values against each other instead of quoting DE or EN prose.
test('the status column says whether it refreshes itself, and an old Ansible result stops claiming OK', async ({ page }) => {
  const esxiName = PREFIX + 'cadence-esxi';
  const ansibleName = PREFIX + 'cadence-ansible';
  const esxiId = seedCredential(esxiName);
  const ansibleId = seedCredential(ansibleName, '10.99.0.7', 22, 'ansible');
  // Own the global selection instead of assuming a clean database: whether the
  // scheduler resolves a host is one of the branches under test, so it must be
  // set here rather than inherited from whatever ran before.
  const selectionBefore = inventoryAnsibleSetting(ansibleId);

  const config = phpJson(`
echo 'JSON' . json_encode([
    'hours' => esxi_inventory_interval_hours(db()),
    'window' => VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS,
]) . 'JSON';
`, ['lib/esxi_inventory.php']);

  try {
    seedPreflight(ansibleId, 'ok', 0);

    await page.goto('credentials.php');
    const cadenceOf = (name) => page.locator('tr', { hasText: name }).first().locator('.status-cadence');
    const badgeOf = (name) => page.locator('tr', { hasText: name }).first().locator('.badge');

    // Every row states its cadence; the two types must not state the same one,
    // which is exactly what the identical badge/timestamp shape used to imply.
    const esxiCadence = (await cadenceOf(esxiName).innerText()).trim();
    const ansibleCadence = (await cadenceOf(ansibleName).innerText()).trim();
    expect(esxiCadence.length, 'the ESXi row states a cadence').toBeGreaterThan(0);
    expect(ansibleCadence, 'polled and on-click rows must not claim the same cadence').not.toBe(esxiCadence);
    expect(esxiCadence, 'the polled row names its interval').toContain(String(config.hours));
    expect(ansibleCadence, 'the on-click row names its expiry window').toContain(String(config.window));

    // The ageing axis: same recorded result, only older, must not read the same.
    const freshBadge = (await badgeOf(ansibleName).innerText()).trim();
    seedPreflight(ansibleId, 'ok', config.window + 1);
    await page.reload();
    const staleBadge = (await badgeOf(ansibleName).innerText()).trim();
    expect(staleBadge, 'a preflight older than the window stops reading as the fresh one').not.toBe(freshBadge);

    // A failure must not age into the same grey: that would hide a known break.
    seedPreflight(ansibleId, 'failed', config.window + 1);
    await page.reload();
    const agedFailure = (await badgeOf(ansibleName).innerText()).trim();
    expect(agedFailure, 'an old failure keeps its own state').not.toBe(staleBadge);

    // Blocker 1, per row: a paused ESXi credential is skipped by the scheduler,
    // so the row must stop naming the interval instead of promising a cycle
    // that is not running for it.
    runPhp(`
$db = db();
$db->query("INSERT INTO deploy_esxi_inventory_state (credential_id, last_attempt_at, last_status, failure_streak, paused_until_credential_change)
  VALUES (${Number(esxiId)}, NOW(), 'failed', 3, 1)
  ON DUPLICATE KEY UPDATE paused_until_credential_change = 1");
echo 'PAUSED';
`);
    await page.reload();
    const pausedCadence = (await cadenceOf(esxiName).innerText()).trim();
    expect(pausedCadence, 'a paused credential does not keep claiming the interval').not.toBe(esxiCadence);
    expect(pausedCadence, 'a paused credential no longer names an interval').not.toContain(String(config.hours));

    // Blocker 2, global: with the selection cleared and more than one Ansible
    // credential present, the pull has no host to run over and nothing is
    // enqueued for ANY credential. The row must name that instead of the pause,
    // because un-pausing would not start the cycle either.
    runPhp(`
$db = db();
$db->query("UPDATE deploy_esxi_inventory_state SET paused_until_credential_change = 0 WHERE credential_id = ${Number(esxiId)}");
echo 'UNPAUSED';
`);
    const secondAnsible = seedCredential(PREFIX + 'cadence-ansible-2', '10.99.0.8', 22, 'ansible');
    expect(secondAnsible, 'the second Ansible credential was created').toBeGreaterThan(0);
    inventoryAnsibleSetting(0);
    await page.reload();
    const blockedCadence = (await cadenceOf(esxiName).innerText()).trim();
    expect(blockedCadence, 'an unresolvable Ansible selection stops the promise').not.toBe(esxiCadence);
    expect(blockedCadence, 'a blocked scheduler no longer names an interval').not.toContain(String(config.hours));
    expect(blockedCadence, 'the blocked reason differs from the paused reason').not.toBe(pausedCadence);

    // The cadence line needed a width floor on the status cell, or it broke
    // across four lines and even the timestamp wrapped mid-date. A floor inside
    // a table is how a page starts pushing sideways instead of reflowing
    // (WCAG 1.4.10), so the floor has to stay contained in .table-wrap.
    await page.setViewportSize({ width: 360, height: 800 });
    const narrow = await page.evaluate(() => ({
      pageOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      wrapScrolls: (() => {
        const wrap = document.querySelector('.table-wrap');
        return wrap ? wrap.scrollWidth > wrap.clientWidth : false;
      })(),
    }));
    expect(narrow.pageOverflow, 'the status cell floor does not widen the page at 360 px').toBe(false);
    expect(narrow.wrapScrolls, 'the wide table scrolls inside its own wrapper instead').toBe(true);
  } finally {
    inventoryAnsibleSetting(Number(selectionBefore) || 0);
    cleanupStatusRows();
  }
});
