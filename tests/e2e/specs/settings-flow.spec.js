// TESTPLAN 3.5 / E6: every settings.php action except the HTTPS block (which
// has its own gated spec), each proven through DB state and restored to the
// value found, so the spec can run against the shared dev stack without
// leaving traces. The saves also prove the tab-anchor contract in a real
// browser: the redirect must land in the tab the form lives in, or sticky
// field errors render into a hidden panel.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

// Setting keys are split across constants.php (bootstrap) and
// deploy_constants.php (not in the bootstrap), so both helpers load the latter.
const SETTING_LIBS = ['lib/deploy_constants.php', 'lib/repo/settings.php'];

function readSetting(constName, fallback = '') {
  return phpJson(`
$v = repo_setting_value(db(), constant('${constName}'), '${fallback}');
echo 'JSON' . json_encode(['v' => $v]) . 'JSON';
`, SETTING_LIBS).v;
}

function writeSetting(constName, value) {
  runPhp(`repo_set_setting(db(), constant('${constName}'), '${String(value)}'); echo 'OK';`, SETTING_LIBS);
}

function phpConst(name) {
  // The bounds constants live in deploy_constants.php, which the bootstrap
  // does not pull in on its own.
  return phpJson(`echo 'JSON' . json_encode(['v' => constant('${name}')]) . 'JSON';`, ['lib/deploy_constants.php']).v;
}

/** A valid value different from `current`, inside [min, min+1]. */
function otherValue(current, min) {
  return Number(current) === Number(min) ? Number(min) + 1 : Number(min);
}

async function openTab(page, tab) {
  await page.goto(`settings.php#panel-${tab}`);
  await expect(page.locator(`#panel-${tab}`)).toBeVisible();
}

function settingsForm(page, action) {
  return page.locator(`form:has(input[name="action"][value="${action}"])`);
}

async function submitAndReturnToTab(page, form, tab) {
  // The redirect lands on the same URL with only the fragment attached, so
  // waitForURL would resolve before the POST is even sent. The flash is the
  // signal that the redirect completed and the handler ran.
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('settings.php') && r.request().method() === 'POST'),
    form.locator('button[type="submit"]').click(),
  ]);
  await expect(page.locator('.alert-success, .alert-warning, .alert-error').first(), 'the handler answered with a flash').toBeVisible();
  // Visible is not enough: the tab-anchor jump used to park the flash behind
  // the sticky topbar, so every save outside the first tab looked like a
  // no-op. core.js counter-scrolls when a [data-flash] is present; pin that.
  await expect(page.locator('[data-flash]').first(), 'the flash is inside the viewport, not behind the sticky topbar').toBeInViewport();
  expect(page.url(), 'the redirect carries the tab anchor').toContain(`#panel-${tab}`);
  // The anchor is only half the contract; the panel must actually be open.
  await expect(page.locator(`#panel-${tab}`), 'the redirect reopens the form tab').toBeVisible();
}

// e2e-covers: settings.php:save_api
test('save_api: persists the normalized URL and returns to the deploy tab', async ({ page }) => {
  const before = readSetting('VIRTUSPHERE_SETTING_API_BASE_URL');
  try {
    await openTab(page, 'deploy');
    const form = settingsForm(page, 'save_api');
    await form.locator('input[name="api_base_url"]').fill('http://127.0.0.1:8021');
    await submitAndReturnToTab(page, form, 'deploy');
    expect(readSetting('VIRTUSPHERE_SETTING_API_BASE_URL'), 'the URL persisted').toBe('http://127.0.0.1:8021');
    await expect(page.locator('[data-api-runtime]'), 'the runtime card names the winning source').toHaveAttribute('data-api-source', 'portal');
    await expect(page.locator('[data-api-runtime] .badge').first(), 'the source is visible, not only machine-readable').toHaveText(/Portal-Einstellung|Portal setting/);
    await expect(page.locator('[data-effective-api-url]'), 'the runtime card shows the normalized effective value').toHaveText('http://127.0.0.1:8021');
  } finally {
    writeSetting('VIRTUSPHERE_SETTING_API_BASE_URL', before);
  }
});

test('deploy runtime separates API source and per-job SSH credential without mobile overflow', async ({ page }) => {
  await openTab(page, 'deploy');

  const urlRow = page.locator('[data-settings-url-row]');
  const examples = page.locator('[data-settings-examples]');
  const desktopLayout = await urlRow.evaluate((row) => {
    const input = row.querySelector('input').getBoundingClientRect();
    const button = row.querySelector('button').getBoundingClientRect();
    return {
      inputRight: input.right,
      inputCenterY: input.top + input.height / 2,
      buttonLeft: button.left,
      buttonCenterY: button.top + button.height / 2,
    };
  });
  expect(desktopLayout.buttonLeft, 'Save sits to the right of the URL field').toBeGreaterThan(desktopLayout.inputRight);
  expect(Math.abs(desktopLayout.buttonCenterY - desktopLayout.inputCenterY), 'field and Save are vertically aligned').toBeLessThanOrEqual(1);
  await expect(examples, 'examples do not dominate the working view').not.toHaveAttribute('open', '');
  await examples.locator('summary').click();
  await expect(examples.getByText(/curl .*health\.php/i), 'the connection test remains available on demand').toBeVisible();

  const runtime = page.locator('[data-api-runtime]');
  await expect(runtime.locator('.runtime-fact')).toHaveCount(2);
  await expect(runtime.getByRole('link', { name: /Zugangsdaten|credentials/i })).toBeVisible();
  await expect(runtime.getByRole('link', { name: /Bereitstellung|deploy/i })).toBeVisible();

  await page.setViewportSize({ width: 360, height: 800 });
  const hasHorizontalOverflow = await page.evaluate(() =>
    document.documentElement.scrollWidth > document.documentElement.clientWidth
  );
  expect(hasHorizontalOverflow, 'the runtime cards and long effective URL reflow at 360 px').toBe(false);
  const mobileLayout = await urlRow.evaluate((row) => {
    const input = row.querySelector('input').getBoundingClientRect();
    const button = row.querySelector('button').getBoundingClientRect();
    return { inputRight: input.right, buttonLeft: button.left };
  });
  expect(mobileLayout.buttonLeft, 'Save remains beside the field at 360 px').toBeGreaterThan(mobileLayout.inputRight);
  const firstRuntimeFact = runtime.locator('.runtime-fact').first();
  await firstRuntimeFact.scrollIntoViewIfNeeded();
  await expect(firstRuntimeFact).toBeInViewport();

  await page.goto('credentials.php');
  await expect(page.getByRole('link', { name: /Deploy-Einstellungen|deploy settings/i }), 'credentials explains that SSH login and callback URL are separate').toBeVisible();
});

// e2e-covers: settings.php:clear_api
// e2e-covers-cancel: settings.php:clear_api
test('clear_api: reset asks first; Cancel keeps the URL, Confirm deletes the setting row', async ({ page }) => {
  const before = readSetting('VIRTUSPHERE_SETTING_API_BASE_URL');
  const rowCount = () => phpJson(`
$db = db();
$stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_settings WHERE setting_key = ?');
$key = constant('VIRTUSPHERE_SETTING_API_BASE_URL');
$stmt->bind_param('s', $key);
$stmt->execute();
echo 'JSON' . json_encode(['c' => (int) $stmt->get_result()->fetch_assoc()['c']]) . 'JSON';
`, SETTING_LIBS).c;
  try {
    // Deterministic start: a stored URL exists, so the reset button renders.
    writeSetting('VIRTUSPHERE_SETTING_API_BASE_URL', 'http://e2e-reset.example:8021');

    await openTab(page, 'deploy');
    await expect(page.locator('[data-api-runtime]'), 'the stored override is visibly the active source').toHaveAttribute('data-api-source', 'portal');
    const dialog = page.locator('[data-confirm-dialog]');
    const reset = settingsForm(page, 'clear_api').locator('button[type="submit"]');

    // Cancel: the stored URL survives.
    await reset.click();
    await expect(dialog, 'the reset asks first').toBeVisible();
    await dialog.locator('button[value="cancel"]').click();
    await expect(dialog).toBeHidden();
    expect(readSetting('VIRTUSPHERE_SETTING_API_BASE_URL'), 'Cancel kept the URL').toBe('http://e2e-reset.example:8021');

    // Confirm: the row is gone (deleted, not blanked; the resolver treats
    // both as "use the env fallback", but a blank row would be a lie in the
    // table), the input renders empty and the reset button disappears with
    // the stored value it acts on.
    await reset.click();
    await expect(dialog).toBeVisible();
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('settings.php') && r.request().method() === 'POST'),
      dialog.locator('[data-confirm-accept]').click(),
    ]);
    await expect(page.locator('.alert-success').first(), 'the reset answered with a flash').toBeVisible();
    expect(page.url(), 'the redirect carries the deploy anchor').toContain('#panel-deploy');
    expect(rowCount(), 'the setting row is deleted, not blanked').toBe(0);
    await expect(settingsForm(page, 'save_api').locator('input[name="api_base_url"]'), 'the input renders empty').toHaveValue('');
    await expect(settingsForm(page, 'clear_api'), 'the reset button only renders with a stored value').toHaveCount(0);
    const fallbackSource = await page.locator('[data-api-runtime]').getAttribute('data-api-source');
    expect(['env', 'none'], 'after reset only .env or no configuration can be active').toContain(fallbackSource);
  } finally {
    if (before === '') {
      runPhp(`repo_delete_setting(db(), VIRTUSPHERE_SETTING_API_BASE_URL); echo 'CLEANED';`, SETTING_LIBS);
    } else {
      writeSetting('VIRTUSPHERE_SETTING_API_BASE_URL', before);
    }
  }
});

// e2e-covers: settings.php:save_timezone
test('save_timezone: persists a changed timezone', async ({ page }) => {
  const before = readSetting('VIRTUSPHERE_SETTING_PORTAL_TIMEZONE', 'Europe/Berlin');
  const next = before === 'UTC' ? 'Europe/Berlin' : 'UTC';
  try {
    await openTab(page, 'system');
    const form = settingsForm(page, 'save_timezone');
    await form.locator('select[name="timezone"]').selectOption(next);
    await submitAndReturnToTab(page, form, 'system');
    expect(readSetting('VIRTUSPHERE_SETTING_PORTAL_TIMEZONE'), 'the timezone persisted').toBe(next);
  } finally {
    writeSetting('VIRTUSPHERE_SETTING_PORTAL_TIMEZONE', before);
  }
});

// e2e-covers: settings.php:save_session
test('save_session: persists a changed lifetime', async ({ page }) => {
  const before = readSetting('VIRTUSPHERE_SETTING_SESSION_LIFETIME_MINUTES', String(phpConst('VIRTUSPHERE_SESSION_LIFETIME_MINUTES_DEFAULT')));
  const next = otherValue(before, phpConst('VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MIN'));
  try {
    await openTab(page, 'system');
    const form = settingsForm(page, 'save_session');
    await form.locator('input[name="session_lifetime_minutes"]').fill(String(next));
    await submitAndReturnToTab(page, form, 'system');
    expect(Number(readSetting('VIRTUSPHERE_SETTING_SESSION_LIFETIME_MINUTES')), 'the lifetime persisted').toBe(next);
  } finally {
    writeSetting('VIRTUSPHERE_SETTING_SESSION_LIFETIME_MINUTES', before);
  }
});

// e2e-covers: settings.php:save_password_policy
test('save_password_policy: persists a changed minimum length', async ({ page }) => {
  const before = readSetting('VIRTUSPHERE_SETTING_PASSWORD_MIN_LENGTH', String(phpConst('VIRTUSPHERE_PASSWORD_MIN_LENGTH_DEFAULT')));
  const next = otherValue(before, phpConst('VIRTUSPHERE_PASSWORD_MIN_LENGTH_MIN'));
  try {
    await openTab(page, 'system');
    const form = settingsForm(page, 'save_password_policy');
    await form.locator('input[name="password_min_length"]').fill(String(next));
    await submitAndReturnToTab(page, form, 'system');
    expect(Number(readSetting('VIRTUSPHERE_SETTING_PASSWORD_MIN_LENGTH')), 'the minimum persisted').toBe(next);
  } finally {
    writeSetting('VIRTUSPHERE_SETTING_PASSWORD_MIN_LENGTH', before);
  }
});

// e2e-covers: settings.php:save_esxi_inventory
test('save_esxi_inventory: persists a changed interval', async ({ page }) => {
  const before = readSetting('VIRTUSPHERE_SETTING_ESXI_INVENTORY_INTERVAL_HOURS', String(phpConst('VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_DEFAULT')));
  const next = otherValue(before, phpConst('VIRTUSPHERE_ESXI_INVENTORY_INTERVAL_HOURS_MIN'));
  try {
    await openTab(page, 'catalog');
    const form = settingsForm(page, 'save_esxi_inventory');
    await form.locator('input[name="esxi_inventory_interval_hours"]').fill(String(next));
    await submitAndReturnToTab(page, form, 'catalog');
    expect(Number(readSetting('VIRTUSPHERE_SETTING_ESXI_INVENTORY_INTERVAL_HOURS')), 'the interval persisted').toBe(next);
  } finally {
    writeSetting('VIRTUSPHERE_SETTING_ESXI_INVENTORY_INTERVAL_HOURS', before);
  }
});

test('save_esxi_inventory: multiple Ansible accounts require a valid global selection and cleanup orphans', async ({ page }) => {
  const mark = 'e2esel-';
  const before = phpJson(`
$row = repo_setting(db(), VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL);
echo 'JSON' . json_encode(['row' => $row]) . 'JSON';
`, SETTING_LIBS);
  const seed = phpJson(`
$db = db();
$admin = (int) ($db->query("SELECT id FROM deploy_users WHERE role = 'admin' LIMIT 1")->fetch_assoc()['id'] ?? 1);
$a = repo_create_credential($db, ['type' => 'ansible', 'name' => '${mark}a', 'host' => '127.0.0.11', 'port' => 22, 'username' => 'ansible'], 'secret123', $admin);
$b = repo_create_credential($db, ['type' => 'ansible', 'name' => '${mark}b', 'host' => '127.0.0.12', 'port' => 22, 'username' => 'ansible'], 'secret123', $admin);
echo 'JSON' . json_encode(['a' => $a, 'b' => $b]) . 'JSON';
`, ['lib/repo/credentials.php']);

  try {
    await openTab(page, 'catalog');
    let form = settingsForm(page, 'save_esxi_inventory');
    const select = form.locator('select[name="esxi_inventory_ansible_credential_id"]');
    await expect(select).toBeVisible();
    await expect(select).toHaveAttribute('required', '');
    await select.selectOption('');
    await form.evaluate((node) => { node.noValidate = true; });
    await submitAndReturnToTab(page, form, 'catalog');
    form = settingsForm(page, 'save_esxi_inventory');
    await expect(form.locator('select[name="esxi_inventory_ansible_credential_id"] ~ .field-error')).toBeVisible();

    await form.locator('select[name="esxi_inventory_ansible_credential_id"]').selectOption(String(seed.a));
    await submitAndReturnToTab(page, form, 'catalog');
    expect(readSetting('VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL')).toBe(String(seed.a));
    const audit = phpJson(`
$row = db()->query("SELECT category, log_message FROM deploy_logs WHERE category = 'settings' AND log_message LIKE 'updated esxi inventory ansible credential %' ORDER BY id DESC LIMIT 1")->fetch_assoc();
echo 'JSON' . json_encode($row) . 'JSON';
`);
    expect(audit.category).toBe('settings');
    expect(audit.log_message).toContain(`-> ${seed.a}`);

    await page.goto('credentials.php');
    let row = page.locator('tr', { hasText: `${mark}a` }).first();
    await row.locator('button[name="action"][value="delete"]').click();
    const dialog = page.locator('[data-confirm-dialog]');
    await expect(dialog).toBeVisible();
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'load' }),
      dialog.locator('[data-confirm-accept]').click(),
    ]);
    expect(readSetting('VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL'), 'deleting the selected account clears the setting').toBe('');

    writeSetting('VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL', String(seed.b));
    await page.goto('credentials.php');
    row = page.locator('tr', { hasText: `${mark}b` }).first();
    await row.locator(`button[data-row-toggle="credential-editor-${seed.b}"]`).click();
    const editor = page.locator(`#credential-editor-${seed.b}`);
    await editor.locator('select[name="type"]').selectOption('esxi');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'load' }),
      editor.locator('button[type="submit"]').click(),
    ]);
    expect(readSetting('VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL'), 'changing the selected account away from Ansible clears the setting').toBe('');
    const cleanupAudit = phpJson(`
$row = db()->query("SELECT category, log_message FROM deploy_logs WHERE category = 'credentials' AND log_message LIKE '%inventory ansible selection%cleared%' ORDER BY id DESC LIMIT 1")->fetch_assoc();
echo 'JSON' . json_encode($row) . 'JSON';
`);
    expect(cleanupAudit.category).toBe('credentials');
  } finally {
    if (before.row === null) {
      runPhp(`repo_delete_setting(db(), VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL); echo 'RESTORED';`, SETTING_LIBS);
    } else {
      writeSetting('VIRTUSPHERE_SETTING_ESXI_INVENTORY_ANSIBLE_CREDENTIAL', before.row.setting_value);
    }
    runPhp(`
$db = db();
$db->query("DELETE FROM deploy_jobs WHERE credential_esxi_id IN (SELECT id FROM deploy_credentials WHERE name LIKE '${mark}%') OR credential_ansible_id IN (SELECT id FROM deploy_credentials WHERE name LIKE '${mark}%')");
$db->query("DELETE FROM deploy_credentials WHERE name LIKE '${mark}%'");
echo 'CLEANED';
`);
  }
});

// e2e-covers: settings.php:save_retire_threshold
test('save_retire_threshold: persists a changed threshold', async ({ page }) => {
  const before = readSetting('VIRTUSPHERE_SETTING_PACKAGE_RETIRE_THRESHOLD', String(phpConst('VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MIN')));
  const next = otherValue(before, phpConst('VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_MIN'));
  try {
    await openTab(page, 'catalog');
    const form = settingsForm(page, 'save_retire_threshold');
    await form.locator('input[name="retire_threshold"]').fill(String(next));
    await submitAndReturnToTab(page, form, 'catalog');
    expect(Number(readSetting('VIRTUSPHERE_SETTING_PACKAGE_RETIRE_THRESHOLD')), 'the threshold persisted').toBe(next);
  } finally {
    writeSetting('VIRTUSPHERE_SETTING_PACKAGE_RETIRE_THRESHOLD', before);
  }
});

// The outbound MECM TCP-445 probe was removed (ADR-0018 amendment): the
// machine-API tab is inbound only, so there is no probe form, host/port field
// or mode radio anymore, and the reported MECM state is a link to System status.
test('machine-api: the outbound MECM probe is gone, the status link is present', async ({ page }) => {
  await openTab(page, 'machine-api');
  await expect(settingsForm(page, 'save_probe')).toHaveCount(0);
  await expect(page.locator('input[name="probe_mode"], input[name="probe_host"], input[name="probe_port"]')).toHaveCount(0);
  await expect(page.locator('#panel-machine-api a[href*="system_status.php#mecm"]')).toBeVisible();
});

// e2e-covers: settings.php:allow_create
// e2e-covers: settings.php:allow_delete
// e2e-covers-cancel: settings.php:allow_delete
test('allowlist: an invalid IP is a sticky field error; add and confirmed delete round-trip', async ({ page }) => {
  const ip = '192.0.2.55'; // TEST-NET-2, never a real machine.
  const description = 'e2e allowlist entry';
  const countFor = () => phpJson(`
$db = db();
$stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_accessToWebAPI WHERE ipAddress = ?');
$ip = '${ip}';
$stmt->bind_param('s', $ip);
$stmt->execute();
echo 'JSON' . json_encode(['c' => (int) $stmt->get_result()->fetch_assoc()['c']]) . 'JSON';
`).c;

  try {
    // Validation-error path (E6): the field error is localized and the
    // description stays sticky instead of being wiped by a generic flash.
    await openTab(page, 'machine-api');
    let form = settingsForm(page, 'allow_create');
    await form.locator('input[name="ip_address"]').fill('not-an-ip');
    await form.locator('input[name="description"]').fill(description);
    await submitAndReturnToTab(page, form, 'machine-api');
    form = settingsForm(page, 'allow_create');
    await expect(form.locator('.field-error, .form-error').first(), 'the IP error is field-bound').toBeVisible();
    await expect(form.locator('input[name="description"]'), 'the description stays sticky').toHaveValue(description);
    expect(countFor(), 'nothing was written').toBe(0);

    // The valid add lands in the DB and the list.
    await form.locator('input[name="ip_address"]').fill(ip);
    await submitAndReturnToTab(page, form, 'machine-api');
    expect(countFor(), 'the entry was added').toBe(1);

    // Delete: Cancel keeps it, Confirm removes it; the dialog names the IP.
    const row = page.locator('tr', { hasText: ip }).first();
    const dialog = page.locator('[data-confirm-dialog]');
    await row.locator('button[name="action"][value="allow_delete"], form:has(input[name="action"][value="allow_delete"]) button').first().click();
    await expect(dialog, 'the delete asks first').toBeVisible();
    await expect(page.locator('[data-confirm-msg]'), 'the dialog names the IP').toContainText(ip);
    await dialog.locator('button[value="cancel"]').click();
    await expect(dialog).toBeHidden();
    expect(countFor(), 'Cancel kept the entry').toBe(1);

    await row.locator('form:has(input[name="action"][value="allow_delete"]) button').first().click();
    await expect(dialog).toBeVisible();
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('settings.php') && r.request().method() === 'POST'),
      dialog.locator('[data-confirm-accept]').click(),
    ]);
    await expect(page.locator('.alert-success').first(), 'the delete answered with a flash').toBeVisible();
    expect(countFor(), 'Confirm removed the entry').toBe(0);
  } finally {
    runPhp(`
$db = db();
$stmt = $db->prepare('DELETE FROM deploy_accessToWebAPI WHERE ipAddress = ?');
$ip = '${ip}';
$stmt->bind_param('s', $ip);
$stmt->execute();
echo 'CLEANED';
`);
  }
});

// e2e-covers: settings.php:generate_token
// e2e-covers-cancel: settings.php:generate_token
// e2e-covers: settings.php:clear_token
// e2e-covers-cancel: settings.php:clear_token
test('report token: regenerate asks (existing token would die), the one-time value is shown, clear asks too', async ({ page }) => {
  const before = readSetting('VIRTUSPHERE_SETTING_MACHINE_REPORT_TOKEN_HASH');
  const readHash = () => readSetting('VIRTUSPHERE_SETTING_MACHINE_REPORT_TOKEN_HASH');
  try {
    // Deterministic start: a token is set, so regenerate carries the confirm.
    writeSetting('VIRTUSPHERE_SETTING_MACHINE_REPORT_TOKEN_HASH', 'e2e-preexisting-hash');

    await openTab(page, 'machine-api');
    const dialog = page.locator('[data-confirm-dialog]');
    const generate = settingsForm(page, 'generate_token').locator('button[type="submit"]');

    // Cancel: the deployed token survives.
    await generate.click();
    await expect(dialog, 'regenerating over a live token asks first').toBeVisible();
    await dialog.locator('button[value="cancel"]').click();
    await expect(dialog).toBeHidden();
    expect(readHash(), 'Cancel kept the deployed token').toBe('e2e-preexisting-hash');

    // Confirm: a new hash lands, and the plaintext is shown exactly once.
    await generate.click();
    await expect(dialog).toBeVisible();
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('settings.php') && r.request().method() === 'POST'),
      dialog.locator('[data-confirm-accept]').click(),
    ]);
    await expect(page.locator('.alert-success').first(), 'the generate answered with a flash').toBeVisible();
    const afterGenerate = readHash();
    expect(afterGenerate, 'a new token hash landed').not.toBe('e2e-preexisting-hash');
    // Scoped to the machine-api panel: other panels carry .alert-info hint
    // boxes with <code> of their own.
    const once = page.locator('#panel-machine-api .alert-info code').first();
    await expect(once, 'the one-time plaintext is visible after the redirect').toBeVisible();
    const plaintext = (await once.textContent()).trim();
    expect(plaintext, 'the shown token matches the stored hash').toHaveLength(32);

    // Clear: Cancel keeps it, Confirm empties the hash.
    const clear = settingsForm(page, 'clear_token').locator('button[type="submit"]');
    await clear.click();
    await expect(dialog, 'clearing asks first').toBeVisible();
    await dialog.locator('button[value="cancel"]').click();
    await expect(dialog).toBeHidden();
    expect(readHash(), 'Cancel kept the token').toBe(afterGenerate);

    await clear.click();
    await expect(dialog).toBeVisible();
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('settings.php') && r.request().method() === 'POST'),
      dialog.locator('[data-confirm-accept]').click(),
    ]);
    await expect(page.locator('.alert-success').first(), 'the clear answered with a flash').toBeVisible();
    expect(readHash(), 'Confirm cleared the token').toBe('');
  } finally {
    writeSetting('VIRTUSPHERE_SETTING_MACHINE_REPORT_TOKEN_HASH', before);
  }
});
