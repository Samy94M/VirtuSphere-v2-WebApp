// The three Ampeln of the System status page and the statements the page makes
// about them, proven in the browser rather than in markup assertions.
//
// The defect this spec exists for: the page legend and the help panel each
// hand-listed the heartbeat states and had drifted apart. The page rendered a
// yellow "Erwartet, nie gemeldet" badge whose only explanation lived in help,
// next to a legend that explained a *different* yellow badge. The states now
// come from lib/constants.php through one renderer, and the first test below
// compares the two rendered pages against each other, which is the only place
// that drift was ever visible.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const WIRE_SOURCES = ['device-sync', 'packages-sync', 'autoimporter'];
const PREFIX = 'e2eampel';

/** Ansible credential through the repo, so the secret is encrypted like a real one. */
function seedAnsibleCredential(name, preflightStatus, component) {
  return phpJson(
    `
$db = db();
$id = repo_create_credential($db, [
    'type' => VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE,
    'name' => '${name}',
    'host' => '10.99.0.7',
    'port' => 22,
    'username' => 'e2e',
], 'e2e-secret-value', 1);
repo_ansible_preflight_record($db, $id, '${preflightStatus}', ${component === null ? 'null' : `'${component}'`});
echo 'JSON' . json_encode(['id' => $id]) . 'JSON';
`,
    ['lib/repo/credentials.php', 'lib/repo/ansible_preflight.php'],
  ).id;
}

function cleanupSeeded() {
  runPhp(`
$db = db();
$like = '${PREFIX}%';
$stmt = $db->prepare('DELETE FROM deploy_ansible_preflight_state WHERE credential_id IN (SELECT id FROM deploy_credentials WHERE name LIKE ?)');
$stmt->bind_param('s', $like);
$stmt->execute();
$stmt = $db->prepare('DELETE FROM deploy_credentials WHERE name LIKE ?');
$stmt->bind_param('s', $like);
$stmt->execute();
echo 'CLEANED';
`);
}

test.beforeAll(() => cleanupSeeded());
test.afterAll(() => cleanupSeeded());

/** Full heartbeat table, so a test may drive states and hand it back intact. */
function heartbeatSnapshot() {
  return phpJson(`
$db = db();
$rows = [];
$res = $db->query('SELECT source, last_seen_at, last_checked_at, last_status, last_detail, last_ip, interval_seconds, beat_count FROM deploy_integration_heartbeats');
while ($row = $res->fetch_assoc()) { $rows[] = $row; }
echo 'JSON' . json_encode($rows) . 'JSON';
`);
}

function restoreHeartbeats(rows) {
  const payload = Buffer.from(JSON.stringify(rows), 'utf8').toString('base64');
  runPhp(`
$rows = json_decode(base64_decode('${payload}'), true, 16, JSON_THROW_ON_ERROR);
$db = db();
$db->query('DELETE FROM deploy_integration_heartbeats');
$stmt = $db->prepare('INSERT INTO deploy_integration_heartbeats (source, last_seen_at, last_checked_at, last_status, last_detail, last_ip, interval_seconds, beat_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($rows as $r) {
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
`);
}

/** Badge texts of one legend group, in render order. */
async function legendGroup(page, index) {
  return page.locator('details.status-legend ul.ampel-legend').nth(index).locator('.badge').allTextContents();
}

test('the page legend explains every Ampel state the page can render, and says the same as help', async ({ page }) => {
  await page.goto('system_status.php');
  await page.locator('details.status-legend summary').click();

  const [heartbeat, esxi, ansible] = await Promise.all([
    legendGroup(page, 0),
    legendGroup(page, 1),
    legendGroup(page, 2),
  ]);

  // The state sets are owned by lib/constants.php; the legend must show all of
  // them, not the four somebody remembered.
  expect(heartbeat).toHaveLength(5);
  expect(esxi).toHaveLength(4);
  expect(ansible).toHaveLength(4);

  const missing = heartbeat.find((label) => /Erwartet, nie gemeldet|Expected, never reported/.test(label));
  expect(missing, 'the `missing` state must have a legend entry on the page itself').toBeTruthy();

  // Every group is explained by a sentence, not left as a bare badge.
  const items = page.locator('details.status-legend ul.ampel-legend li');
  await expect(items).toHaveCount(13);
  for (const text of await items.allTextContents()) {
    expect(text.replace(/\s+/g, ' ').trim().length, `legend entry "${text}" has no explanation`).toBeGreaterThan(20);
  }

  // The cross-page comparison: help must list exactly the same states.
  await page.goto('help.php#panel-system-status');
  const helpHeartbeat = await page
    .locator('#panel-system-status ul.ampel-legend')
    .first()
    .locator('.badge')
    .allTextContents();
  expect(helpHeartbeat, 'help and the page must explain the same heartbeat states').toEqual(heartbeat);
});

test('an action hint is a repair instruction: it appears on a broken row and not on a healthy one', async ({ page }) => {
  const snapshot = heartbeatSnapshot();
  try {
    // One source healthy, one clearly overdue, so both branches are on screen
    // in the same render and cannot be confused with a timing artefact.
    runPhp(`
$db = db();
$db->query("DELETE FROM deploy_integration_heartbeats WHERE source IN ('device-sync','packages-sync','autoimporter')");
$db->query("INSERT INTO deploy_integration_heartbeats (source, last_seen_at, last_checked_at, last_status, last_detail, last_ip, interval_seconds, beat_count)
  VALUES ('device-sync', NOW(), NOW(), 'ok', '', '10.0.0.1', 30, 5),
         ('packages-sync', NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY, 'ok', '', '10.0.0.1', 30, 5)");
echo 'SEEDED';
`);

    await page.goto('system_status.php');
    // Source labels are localized (DE "MECM Device-Sync", EN "MECM device
    // sync") and the e2e session runs in English, so the row is addressed in
    // both locales rather than in whichever one the dev host happens to use.
    const healthy = page.locator('#mecm article.status-row').filter({ hasText: /device[- ]sync/i });
    const broken = page.locator('#mecm article.status-row').filter({ hasText: /paket-sync|package sync/i }).first();

    await expect(healthy.locator('.badge')).toHaveText(/^OK$/);
    await expect(healthy.locator('.status-action')).toHaveCount(0);

    await expect(broken.locator('.badge')).toHaveText(/Ausgefallen|Verzögert|Down|Delayed/);
    await expect(broken.locator('.status-action')).toHaveCount(1);
    await expect(broken.locator('.status-action')).toContainText(/Aufgabenplanung|Task Scheduler/);
  } finally {
    restoreHeartbeats(snapshot);
  }
});

test('an unconnected MECM states the setup step once instead of repeating repair hints per row', async ({ page }) => {
  const snapshot = heartbeatSnapshot();
  try {
    runPhp(`
$db = db();
$db->query("DELETE FROM deploy_integration_heartbeats WHERE source IN ('${WIRE_SOURCES.join("','")}')");
echo 'SEEDED';
`);

    await page.goto('system_status.php');

    const callout = page.locator('#mecm .empty-state');
    await expect(callout).toHaveCount(1);
    await expect(callout).toContainText(/Noch keine MECM-Anbindung|No MECM connection yet/);
    await expect(callout.getByRole('link', { name: /IP-Freigaben|IP allowlist/ })).toHaveAttribute(
      'href',
      'settings.php#panel-machine-api',
    );

    // The rows survive, so the operator still sees which sources are expected.
    await expect(page.locator('#mecm article.status-row')).toHaveCount(WIRE_SOURCES.length + 1);
    await expect(page.locator('#mecm .status-action')).toHaveCount(0);

    // The probe has no effective target, so it must not claim the server is
    // unreachable either.
    await expect(page.locator('#mecm')).toContainText(/wartet auf Device-Sync|waiting for device sync/);

    // Internal services are ours and keep their instruction: a never-started
    // container really can be started.
    await expect(page.locator('#internal-services')).toHaveCount(1);
  } finally {
    restoreHeartbeats(snapshot);
  }
});

test('the deviation count picks its sentence by number, never ":count" and never "1 deviations"', async ({ page }) => {
  await page.goto('system_status.php');
  const badge = page.locator('#deviations h2 .badge');
  await expect(badge).toHaveCount(1);
  // This stack has an ESXi credential, so the scan runs; the not-checked branch
  // has its own test below, which removes that precondition on purpose.
  await expect(page.locator('article.inventory-card').first()).toBeVisible();

  const label = (await badge.textContent()).trim();
  expect(label).toMatch(/^(Keine Abweichungen|No deviations|1 Abweichung|1 deviation|\d+ Abweichungen|\d+ deviations)$/);
  expect(label).not.toMatch(/^1 Abweichungen|^1 deviations/);
  expect(label).not.toContain(':count');
});

test('without an ESXi credential the scan says "Not checked" instead of a green zero it never verified', async ({ page }) => {
  // The scan needs an inventory to compare against, so the precondition is
  // removed rather than simulated: the ESXi credential is parked as an Ansible
  // one for the duration (the ENUM allows both, the FKs of its cached inventory
  // rows do not care) and handed back in the finally.
  const parked = phpJson(`
$db = db();
$rows = [];
$res = $db->query("SELECT id FROM deploy_credentials WHERE type = 'esxi'");
while ($row = $res->fetch_assoc()) { $rows[] = (int) $row['id']; }
if ($rows !== []) { $db->query('UPDATE deploy_credentials SET type = ' . "'ansible'" . ' WHERE id IN (' . implode(',', $rows) . ')'); }
echo 'JSON' . json_encode(['ids' => $rows]) . 'JSON';
`);
  try {
    await page.goto('system_status.php');
    await expect(page.locator('article.inventory-card')).toHaveCount(0);

    const badge = page.locator('#deviations h2 .badge');
    await expect(badge).toHaveText(/Nicht geprüft|Not checked/);
    await expect(badge).toHaveClass(/badge-neutral/);
    await expect(badge).not.toHaveClass(/badge-success/);
    await expect(page.locator('#deviations')).toContainText(
      /konnte nicht verglichen|nothing could be compared/,
    );
    // No deviation list and no repair form without a comparison behind them.
    await expect(page.locator('#deviations .deviation-groups')).toHaveCount(0);
    await expect(page.locator('#deviations details.repair-actions')).toHaveCount(0);
  } finally {
    if (parked.ids.length > 0) {
      runPhp(`db()->query("UPDATE deploy_credentials SET type = 'esxi' WHERE id IN (${parked.ids.join(',')})"); echo 'RESTORED';`);
    }
  }
});

test('a restricted Ansible credential explains the allowlist instead of claiming a failed component', async ({ page }) => {
  const warned = PREFIX + '-warning';
  const failed = PREFIX + '-failed';
  seedAnsibleCredential(warned, 'warning', 'allowlist');
  seedAnsibleCredential(failed, 'failed', 'pyvmomi');
  try {
    await page.goto('system_status.php');

    const restricted = page.locator('#ansible article.status-row').filter({ hasText: warned });
    await expect(restricted.locator('.badge')).toHaveText(/Eingeschränkt|Restricted/);
    await expect(restricted.locator('.alert-warning')).toContainText(
      /IP-Freigaben|IP allowlist/,
    );
    // The warning stores its check in the same column a failure stores its
    // broken component in, so the row must not say the test failed at it.
    await expect(restricted).not.toContainText(/Fehlgeschlagen an|Failed at/);

    // A real failure still names its component, so suppressing the warning
    // wording did not suppress the diagnosis.
    const broken = page.locator('#ansible article.status-row').filter({ hasText: failed });
    await expect(broken.locator('.badge')).toHaveText(/Fehlgeschlagen|Failed/);
    await expect(broken).toContainText(/pyvmomi/);
    await expect(broken.locator('.alert-warning')).toHaveCount(0);

    // The overview rolls the worst of the two up, and `danger` outranks `warning`.
    await expect(
      page.locator('.status-overview-card[href="#ansible"] .badge'),
    ).toHaveText(/Fehlgeschlagen|Failed/);
  } finally {
    cleanupSeeded();
  }
});

test('Settings and System status never disagree about the same probe heartbeat', async ({ page }) => {
  const snapshot = heartbeatSnapshot();
  try {
    // A probe whose last check succeeded long ago and was never marked failed:
    // reading last_status alone called this "OK" on Settings while System
    // status called it "Ausgefallen".
    runPhp(`
$db = db();
$db->query("DELETE FROM deploy_integration_heartbeats WHERE source = 'mecm-server-probe'");
$db->query("INSERT INTO deploy_integration_heartbeats (source, last_seen_at, last_checked_at, last_status, last_detail, last_ip, interval_seconds, beat_count)
  VALUES ('mecm-server-probe', NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 3 DAY, 'ok', '', '10.0.0.1', 300, 3)");
echo 'SEEDED';
`);

    await page.goto('system_status.php');
    const onStatus = (
      await page
        .locator('#mecm article.status-row')
        .filter({ hasText: /erreichbar|reachable/ })
        .first()
        .locator('.badge')
        .textContent()
    ).trim();

    await page.goto('settings.php#panel-machine-api');
    const onSettings = (
      await page.locator('.status-facts .badge').first().textContent()
    ).trim();

    expect(onStatus, 'a stale probe must not read OK').toMatch(/Ausgefallen|Down/);
    expect(onSettings, 'Settings must reach the same verdict as System status').toBe(onStatus);
  } finally {
    restoreHeartbeats(snapshot);
  }
});
