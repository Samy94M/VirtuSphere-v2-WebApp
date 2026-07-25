// System-status acceptance coverage for the MECM result reports and site
// status, fixed-clock overview, compact ESXi cards and role-safe repair actions.

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

// The MECM site-health row is the one row these tests seed. Snapshot it (all
// columns) and restore it afterwards so the shared dev DB is left untouched.
function siteSnapshot() {
  return phpJson(`
$db = db();
$r = $db->query("SELECT * FROM deploy_integration_heartbeats WHERE source = 'mecm-site-health'")->fetch_assoc() ?: null;
echo 'JSON' . json_encode(['row' => $r]) . 'JSON';
`);
}

function restoreSite(snapshot) {
  const payload = Buffer.from(JSON.stringify(snapshot.row), 'utf8').toString('base64');
  runPhp(`
$db = db();
$db->query("DELETE FROM deploy_integration_heartbeats WHERE source = 'mecm-site-health'");
$row = json_decode(base64_decode('${payload}'), true, 16, JSON_THROW_ON_ERROR);
if (is_array($row)) {
    $cols = array_keys($row);
    $sql = 'INSERT INTO deploy_integration_heartbeats (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $stmt = $db->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($cols)), ...array_values($row));
    $stmt->execute();
}
echo 'RESTORED';
`);
}

// Replaces the mecm-site-health row with one V2 completed report of the given
// outcome/category (no summary, so no JSON escaping in the seed).
function seedSite(outcome, category) {
  runPhp(`
$db = db();
$db->query("DELETE FROM deploy_integration_heartbeats WHERE source = 'mecm-site-health'");
$stmt = $db->prepare("INSERT INTO deploy_integration_heartbeats (source, last_seen_at, last_checked_at, last_result_at, last_failure_at, last_status, last_event, last_error_category, report_version, interval_seconds, last_ip, beat_count) VALUES ('mecm-site-health', NOW(), NOW(), NOW(), NOW(), ?, 'completed', ?, 2, 300, '10.0.0.5', 1)");
$outcome = '${outcome}';
$category = '${category}';
$stmt->bind_param('ss', $outcome, $category);
$stmt->execute();
echo 'SEEDED';
`);
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
  // Exactly one <h1>, and it is the topbar's. The page used to render a second
  // one of its own, larger than the chrome's because nothing styled it, so the
  // document had two top-level headings saying the same thing.
  await expect(page.locator('h1')).toHaveCount(1);
  await expect(page.locator('h1')).toHaveText(/Systemstatus|System status/);
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

test('MECM sync cards: the same field sits in the same column in every card', async ({ page }) => {
  await page.goto('system_status.php#mecm');

  // Three reporters, one fixed six-field list each. The fields used to render
  // only when they had a value, so a card with a duration had one column more
  // than its neighbours and the whole block started somewhere else; this is the
  // geometry assertion ADR-0013 asks for on a repaired spacing boundary.
  const columns = await page.locator('#mecm .status-subgroup').first().evaluate((group) => {
    const rows = [...group.querySelectorAll('article.status-row')];
    return rows.map((row) => [...row.querySelectorAll('.status-facts dt')]
      .map((dt) => Math.round(dt.getBoundingClientRect().x)));
  });

  expect(columns.length, 'the sync subgroup holds the three reporter rows').toBe(3);
  for (const row of columns) {
    expect(row.length, 'every reporter renders all six fields, missing ones as a dash').toBe(6);
    expect(row, 'the fields of one card line up with the fields of the card above').toEqual(columns[0]);
  }
});

test('MECM section: two separate subgroups and no outbound probe control', async ({ page }) => {
  await page.goto('system_status.php#mecm');
  // The MECM section is two visually equal subgroups, never one worst-of.
  await expect(page.locator('#mecm')).toContainText(/VirtuSphere-MECM-Integration|VirtuSphere MECM integration/);
  await expect(page.locator('#mecm')).toContainText(/MECM-Site-Status|MECM site status/);
  // The 445 probe is gone: no "Erneut prüfen"/"Check again" control, no run_mecm_probe form.
  await expect(page.locator('form:has(input[name="action"][value="run_mecm_probe"])')).toHaveCount(0);
  await expect(page.locator('#mecm')).not.toContainText(/Erneut prüfen|Check again/);
});

test('MECM site: a confirmed critical is red, a provider fault is grey not critical', async ({ page }) => {
  const snapshot = siteSnapshot();
  try {
    // MECM-confirmed critical (status 2): red, names the MECM console.
    seedSite('fail', 'site_critical');
    await page.goto('system_status.php');
    await expect(page.locator('#mecm')).toContainText(/MECM meldet kritisch|MECM reports critical/);
    await expect(page.locator('#mecm')).toContainText(/Monitoring, System Status/);

    // A provider fault is an unknown outcome: grey, and must NOT read as the
    // MECM-critical console pointer. reload() (not a same-hash goto) forces a
    // fresh server render of the reseeded row.
    seedSite('unknown', 'provider_access_denied');
    await page.reload();
    await expect(page.locator('#mecm')).toContainText(/Providerzugriff verweigert|Provider access denied/);
    await expect(page.locator('#mecm')).not.toContainText(/Monitoring, System Status/);
    await expect(page.locator('#mecm')).not.toContainText(/MECM meldet kritisch|MECM reports critical/);
  } finally {
    restoreSite(snapshot);
  }
});

test('Dashboard: the MECM tile shows two separate signal rows', async ({ page }) => {
  await page.goto('dashboard.php');
  const tile = page.locator('a.card.kpi:has(.value-signals)');
  await expect(tile.locator('.signal-row')).toHaveCount(2);
  await expect(tile).toContainText(/Integration/);
  await expect(tile).toContainText(/MECM-Site|MECM site/);
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
