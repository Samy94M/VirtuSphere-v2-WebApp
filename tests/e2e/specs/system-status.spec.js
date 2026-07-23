// System-status acceptance coverage for the explicit MECM probe, fixed-clock
// overview, compact ESXi cards and role-safe repair actions.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const MARK = 'e2estatus';

function cleanup() {
  runPhp(`
$db = db();
$db->query("DELETE FROM deploy_jobs WHERE credential_esxi_id IN (SELECT id FROM deploy_credentials WHERE name LIKE '${MARK}%')");
$db->query("DELETE FROM deploy_credentials WHERE name LIKE '${MARK}%'");
echo 'CLEANED';
`);
}

function probeSnapshot() {
  return phpJson(`
$db = db();
$host = repo_setting($db, VIRTUSPHERE_SETTING_MECM_PROBE_HOST);
$port = repo_setting($db, VIRTUSPHERE_SETTING_MECM_PROBE_PORT);
$row = $db->query("SELECT source, last_seen_at, last_checked_at, last_status, last_detail, last_ip, interval_seconds, beat_count FROM deploy_integration_heartbeats WHERE source = 'mecm-server-probe'")->fetch_assoc() ?: null;
echo 'JSON' . json_encode(['host' => $host, 'port' => $port, 'row' => $row]) . 'JSON';
`, ['lib/repo/settings.php']);
}

function restoreProbe(snapshot) {
  const payload = Buffer.from(JSON.stringify(snapshot), 'utf8').toString('base64');
  runPhp(`
$data = json_decode(base64_decode('${payload}'), true, 16, JSON_THROW_ON_ERROR);
$db = db();
foreach (['host' => VIRTUSPHERE_SETTING_MECM_PROBE_HOST, 'port' => VIRTUSPHERE_SETTING_MECM_PROBE_PORT] as $field => $key) {
    if ($data[$field] === null) {
        repo_delete_setting($db, $key);
    } else {
        repo_set_setting($db, $key, (string) $data[$field]['setting_value']);
    }
}
$db->query("DELETE FROM deploy_integration_heartbeats WHERE source = 'mecm-server-probe'");
if (is_array($data['row'])) {
    $r = $data['row'];
    $stmt = $db->prepare('INSERT INTO deploy_integration_heartbeats (source, last_seen_at, last_checked_at, last_status, last_detail, last_ip, interval_seconds, beat_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $source = (string) $r['source'];
    $seen = $r['last_seen_at'];
    $checked = $r['last_checked_at'];
    $status = (string) $r['last_status'];
    $detail = $r['last_detail'];
    $ip = (string) $r['last_ip'];
    $interval = (int) $r['interval_seconds'];
    $beats = (int) $r['beat_count'];
    $stmt->bind_param('ssssssii', $source, $seen, $checked, $status, $detail, $ip, $interval, $beats);
    $stmt->execute();
}
echo 'RESTORED';
`, ['lib/repo/settings.php']);
}

async function csrfTokenFor(context, path) {
  const page = await context.newPage();
  await page.goto(path);
  const token = await page.locator('input[name="_csrf"]').first().inputValue();
  await page.close();
  return token;
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

test('Systemstatus: title, overview links and GET refresh are read-only', async ({ page }) => {
  const before = phpJson(`
$db = db();
$id = (int) ($db->query("SELECT id FROM deploy_users WHERE role = 'admin' LIMIT 1")->fetch_assoc()['id'] ?? 0);
$stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_logs WHERE user_id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode(['c' => (int) $stmt->get_result()->fetch_assoc()['c']]) . 'JSON';
`).c;

  const response = await page.goto('system_status.php');
  expect(response.status()).toBe(200);
  await expect(page.locator('#main h1')).toHaveText(/Systemstatus|System status/);
  await expect(page.locator('nav a[href="system_status.php"]')).toHaveText(/Systemstatus|System status/);
  await expect(page.locator('.status-overview-card')).toHaveCount(4);
  for (const target of ['#mecm', '#ansible', '#esxi', '#internal-services']) {
    await expect(page.locator(`.status-overview-card[href="${target}"]`)).toHaveCount(1);
    await expect(page.locator(target)).toHaveCount(1);
  }

  const firstTimestamp = await page.locator('.status-generated time').textContent();
  await page.waitForTimeout(1100);
  await page.getByRole('link', { name: /Status aktualisieren|Refresh status/ }).click();
  await expect(page).toHaveURL(/system_status\.php$/);
  await expect(page.locator('.status-generated time')).not.toHaveText(firstTimestamp.trim());

  const after = phpJson(`
$db = db();
$id = (int) ($db->query("SELECT id FROM deploy_users WHERE role = 'admin' LIMIT 1")->fetch_assoc()['id'] ?? 0);
$stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_logs WHERE user_id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode(['c' => (int) $stmt->get_result()->fetch_assoc()['c']]) . 'JSON';
`).c;
  expect(after, 'GET refresh writes no user audit row').toBe(before);
});

// e2e-covers: system_status.php:run_mecm_probe
test('run_mecm_probe: closed local port reports safely, audits, enforces CSRF and RBAC', async ({ page, browser }) => {
  const snapshot = probeSnapshot();
  try {
    runPhp(`
repo_set_setting(db(), VIRTUSPHERE_SETTING_MECM_PROBE_HOST, '127.0.0.1');
repo_set_setting(db(), VIRTUSPHERE_SETTING_MECM_PROBE_PORT, '1');
echo 'READY';
`, ['lib/repo/settings.php']);

    await page.goto('system_status.php#mecm');
    const form = page.locator('form:has(input[name="action"][value="run_mecm_probe"])');
    await expect(form).toBeVisible();
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('system_status.php') && r.request().method() === 'POST'),
      form.locator('button[type="submit"]').click(),
    ]);
    await expect(page.locator('[data-flash]').first()).toBeVisible();
    await expect(page.locator('[data-flash] a[href="system_status.php#mecm"]')).toBeVisible();

    const proof = phpJson(`
$db = db();
$row = $db->query("SELECT last_status, last_detail FROM deploy_integration_heartbeats WHERE source = 'mecm-server-probe'")->fetch_assoc();
$audit = $db->query("SELECT category, log_message, correlation_id FROM deploy_logs WHERE category = 'mecm' AND log_message LIKE 'manual mecm probe correlation %' ORDER BY id DESC LIMIT 1")->fetch_assoc();
echo 'JSON' . json_encode(['row' => $row, 'audit' => $audit]) . 'JSON';
`);
    expect(proof.row.last_status).toBe('fail');
    const detail = JSON.parse(proof.row.last_detail);
    expect(detail.target).toBe('127.0.0.1');
    expect(detail.port).toBe(1);
    expect(detail.status).toBe('fail');
    expect(['refused', 'network', 'unknown']).toContain(detail.error_category);
    expect(proof.audit.category).toBe('mecm');
    expect(proof.audit.correlation_id).toMatch(/^[a-f0-9]{16,32}$/);

    // PHP-FPM listens inside the same app container on 9000. This provides a
    // deterministic successful TCP target without reaching any external host.
    runPhp(`
repo_set_setting(db(), VIRTUSPHERE_SETTING_MECM_PROBE_PORT, '9000');
echo 'READY';
`, ['lib/repo/settings.php']);
    await page.goto('system_status.php#mecm');
    const successForm = page.locator('form:has(input[name="action"][value="run_mecm_probe"])');
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('system_status.php') && r.request().method() === 'POST'),
      successForm.locator('button[type="submit"]').click(),
    ]);
    const success = phpJson(`
$row = db()->query("SELECT last_status, last_seen_at, last_detail FROM deploy_integration_heartbeats WHERE source = 'mecm-server-probe'")->fetch_assoc();
echo 'JSON' . json_encode($row) . 'JSON';
`);
    expect(success.last_status).toBe('ok');
    expect(success.last_seen_at).toBeTruthy();
    expect(JSON.parse(success.last_detail)).toMatchObject({
      target: '127.0.0.1',
      port: 9000,
      status: 'ok',
      error_category: null,
    });

    const admin = await browser.newContext({ storageState: ROLES.admin.storageState });
    const noCsrf = await admin.request.post('system_status.php', { form: { action: 'run_mecm_probe' } });
    expect(noCsrf.status()).toBe(400);
    await admin.close();

    const user = await browser.newContext({ storageState: ROLES.user.storageState });
    const userPage = await user.newPage();
    await userPage.goto('system_status.php#mecm');
    await expect(userPage.locator('form:has(input[name="action"][value="run_mecm_probe"])')).toHaveCount(0);
    const userToken = await csrfTokenFor(user, 'missions.php?type=missions');
    const denied = await user.request.post('system_status.php', {
      form: { action: 'run_mecm_probe', _csrf: userToken },
    });
    expect(denied.status()).toBe(403);
    await user.close();
  } finally {
    restoreProbe(snapshot);
  }
});

test('ESXi cards stay compact, load one detail on demand and ignore foreign ids', async ({ page }) => {
  const seed = phpJson(`
$db = db();
$admin = (int) ($db->query("SELECT id FROM deploy_users WHERE role = 'admin' LIMIT 1")->fetch_assoc()['id'] ?? 1);
$ansible = repo_create_credential($db, ['type' => 'ansible', 'name' => '${MARK}-ansible', 'host' => '127.0.0.1', 'port' => 22, 'username' => 'ansible'], 'secret123', $admin);
$esxi = repo_create_credential($db, ['type' => 'esxi', 'name' => '${MARK}-esxi', 'host' => '2001:db8::44', 'port' => 443, 'username' => 'root'], 'secret123', $admin);
repo_esxi_inventory_apply($db, $esxi, [
    'hosts' => [['name' => 'esxi-01']],
    'datacenters' => ['DC-QA'],
    'datastores' => [['name' => 'DS-QA', 'capacity_bytes' => 1000000, 'free_bytes' => 400000]],
    'networks' => ['PG-QA'],
]);
repo_esxi_inventory_record_success($db, $esxi, ['api_type' => 'HostAgent', 'product_version' => '8.0', 'license_product' => 'free', 'license_free' => true, 'in_ha_cluster' => false, 'in_maintenance' => false]);
$payload = json_encode(['mode' => 'inventory'], JSON_THROW_ON_ERROR);
$correlation = 'e2estatuspending';
$stmt = $db->prepare("INSERT INTO deploy_jobs (mission_id, user_id, status, payload_json, credential_esxi_id, credential_ansible_id, correlation_id, scheduled_at) VALUES (NULL, ?, 'queued', ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY))");
$stmt->bind_param('isiis', $admin, $payload, $esxi, $ansible, $correlation);
$stmt->execute();
echo 'JSON' . json_encode(['esxi' => $esxi, 'ansible' => $ansible, 'job' => (int) $db->insert_id]) . 'JSON';
`, ['lib/repo/credentials.php', 'lib/repo/esxi_inventory.php']);

  await page.goto('system_status.php#esxi');
  const card = page.locator(`#credential-${seed.esxi}`);
  await expect(card).toBeVisible();
  await expect(card).toContainText(`${MARK}-esxi`);
  await expect(card.locator('.inventory-counts dd')).toHaveText(['1', '1', '1', '1']);
  await expect(card.locator('form:has(input[name="action"][value="refresh_inventory"]) button')).toBeDisabled();
  await expect(card.locator(`a[href="deploy_log.php?id=${seed.job}"]`)).toBeVisible();
  await expect(card.locator('.inventory-details')).toHaveCount(0);

  const inventoryBlockGap = () => page.locator('#esxi').evaluate((section) => {
    const button = section.querySelector('.section-heading-actions button').getBoundingClientRect();
    const grid = section.querySelector('.inventory-card-grid').getBoundingClientRect();
    return grid.top - button.bottom;
  });
  expect(await inventoryBlockGap(), 'refresh-all stays separated from the inventory cards on desktop').toBeGreaterThanOrEqual(12);

  await page.setViewportSize({ width: 360, height: 800 });
  const mobileHeadingLayout = await page.locator('#esxi').evaluate((section) => {
    const copy = section.querySelector('.section-heading-actions > div').getBoundingClientRect();
    const button = section.querySelector('.section-heading-actions button').getBoundingClientRect();
    return { copyBottom: copy.bottom, buttonTop: button.top };
  });
  expect(mobileHeadingLayout.buttonTop, 'the refresh-all action wraps below the long heading copy at 360 px').toBeGreaterThanOrEqual(mobileHeadingLayout.copyBottom);
  expect(await inventoryBlockGap(), 'the wrapped refresh-all action stays separated from the cards at 360 px').toBeGreaterThanOrEqual(12);
  await page.setViewportSize({ width: 1280, height: 720 });

  await card.locator(`a[href="system_status.php?inventory=${seed.esxi}#credential-${seed.esxi}"]`).click();
  await expect(page).toHaveURL(new RegExp(`inventory=${seed.esxi}#credential-${seed.esxi}`));
  await expect(page.locator(`#credential-${seed.esxi} .inventory-details`)).toBeVisible();
  await expect(page.locator(`#credential-${seed.esxi} .inventory-details`)).toContainText('DS-QA');
  await expect(page.getByRole('link', { name: /Status aktualisieren|Refresh status/ })).toHaveAttribute('href', `system_status.php?inventory=${seed.esxi}#credential-${seed.esxi}`);

  await page.goto(`system_status.php?inventory=${seed.ansible}#esxi`);
  await expect(page.locator('.inventory-card-open')).toHaveCount(0);
  await expect(page.locator('.inventory-details')).toHaveCount(0);
  await page.goto('system_status.php?inventory=999999#esxi');
  await expect(page.locator('.inventory-card-open')).toHaveCount(0);
});

test('unknown Systemstatus POST action is rejected with HTTP 400', async ({ browser }) => {
  const admin = await browser.newContext({ storageState: ROLES.admin.storageState });
  try {
    const token = await csrfTokenFor(admin, 'system_status.php');
    const response = await admin.request.post('system_status.php', {
      form: { action: 'not-a-real-action', _csrf: token },
    });
    expect(response.status()).toBe(400);
  } finally {
    await admin.close();
  }
});
