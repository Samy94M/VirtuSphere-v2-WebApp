// System-status acceptance coverage for the MECM result reports and site
// status, fixed-clock overview, compact ESXi cards and role-safe repair actions.

const { test, expect } = require('../lib/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');
const { submitAndWaitForNavigation } = require('../lib/navigation');

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
  const directoryCards = await page.locator('.status-overview-card[href="system_status.php#directory-status"]').count();
  await expect(page.locator('.status-overview-card')).toHaveCount(6 + directoryCards);
  // The hrefs are built by system_status_url(), so they carry the page path;
  // with no query they still resolve to this same document and jump in place.
  for (const anchor of ['deploy-service', 'mecm', 'ansible', 'esxi', 'deviations', 'internal-services']) {
    await expect(page.locator(`.status-overview-card[href="system_status.php#${anchor}"]`)).toHaveCount(1);
    await expect(page.locator(`#${anchor}`)).toHaveCount(1);
  }
  if (directoryCards === 1) {
    await expect(page.locator('#directory-status')).toHaveCount(1);
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

  // Three reporters, one direct fixed six-field run list each. The autoimporter
  // additionally owns a separate distribution axis below that list; descendants
  // of that axis must not be counted as columns of the shared run facts.
  // The run fields used to render only when they had a value, so a card with a
  // duration had one column more than its neighbours and the whole block started
  // somewhere else; this is the geometry assertion ADR-0013 asks for.
  const columns = await page.locator('#mecm .status-subgroup').first().evaluate((group) => {
    const rows = [...group.querySelectorAll('article.status-row')];
    return rows.map((row) => [...row.querySelector(':scope > .status-facts').querySelectorAll('dt')]
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

  // At 900px the portal sidebar is still visible and the dashboard's fixed three
  // columns leave each tile only about 192px wide, so the MECM badge is measured
  // in that narrow layout as well as at the wider widths.
  for (const width of [1280, 1024, 900]) {
    await page.setViewportSize({ width, height: 900 });
    const measurements = await tile.evaluate((node) => {
      const style = getComputedStyle(node);
      const box = node.getBoundingClientRect();
      const rows = [...node.querySelectorAll('.signal-row')].map((row) => {
        const label = row.querySelector('.signal-label');
        const badge = row.querySelector('.badge');
        const labelBox = label.getBoundingClientRect();
        const badgeBox = badge.getBoundingClientRect();
        return {
          name: label.textContent.trim(),
          label: { left: labelBox.left, right: labelBox.right },
          badge: { left: badgeBox.left, right: badgeBox.right },
        };
      });
      return {
        contentLeft: box.left + parseFloat(style.paddingLeft),
        contentRight: box.right - parseFloat(style.paddingRight),
        scrollWidth: node.scrollWidth,
        clientWidth: node.clientWidth,
        rows,
      };
    });

    expect(measurements.scrollWidth, `${width}px: MECM tile has no horizontal overflow`).toBeLessThanOrEqual(measurements.clientWidth + 1);
    for (const row of measurements.rows) {
      for (const [part, child] of [['label', row.label], ['badge', row.badge]]) {
        expect(child.left, `${width}px: row "${row.name}" ${part} starts inside the tile padding`).toBeGreaterThanOrEqual(measurements.contentLeft - 1);
        expect(child.right, `${width}px: row "${row.name}" ${part} ends inside the tile padding`).toBeLessThanOrEqual(measurements.contentRight + 1);
      }
    }
  }
});

test('ESXi cards stay compact, load one detail on demand and ignore foreign ids', async ({ page }) => {
  const seed = phpJson(`
$db = db();
$admin = (int) ($db->query("SELECT id FROM deploy_users WHERE role = 'admin' LIMIT 1")->fetch_assoc()['id'] ?? 1);
$ansible = repo_create_credential($db, ['type' => 'ansible', 'name' => '${MARK}-ansible', 'host' => '127.0.0.1', 'port' => 22, 'username' => 'ansible'], 'secret123', $admin);
$esxi = repo_create_credential($db, ['type' => 'esxi', 'esxi_cert_kind' => 'none', 'name' => '${MARK}-esxi', 'host' => '2001:db8::44', 'port' => 443, 'username' => 'root'], 'secret123', $admin);
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

  for (const theme of ['light', 'dark']) {
    await page.evaluate((value) => document.documentElement.setAttribute('data-theme', value), theme);
    for (const credential of [seed.esxi, seed.ansible]) {
      const target = page.locator(`#credential-${credential}`);
      await expect(target).toHaveAttribute('data-deep-link-target', '');
      await page.evaluate(() => { location.hash = 'esxi'; });
      const before = await target.evaluate((node) => getComputedStyle(node).backgroundColor);
      await page.evaluate((id) => { location.hash = `credential-${id}`; }, credential);
      await expect.poll(() => target.evaluate((node) => getComputedStyle(node).backgroundColor)).not.toBe(before);
      await expect.poll(() => target.evaluate((node) => getComputedStyle(node).boxShadow)).toContain('inset');
    }
  }

  await page.goto(`system_status.php?inventory=${seed.ansible}#esxi`);
  await expect(page.locator('.inventory-card-open')).toHaveCount(0);
  await expect(page.locator('.inventory-details')).toHaveCount(0);
  await page.goto('system_status.php?inventory=999999#esxi');
  await expect(page.locator('.inventory-card-open')).toHaveCount(0);

  runPhp(`
$db = db();
$jobId = ${Number(seed.job)};
$esxiId = ${Number(seed.esxi)};
$db->query("UPDATE deploy_jobs SET status = 'failed', last_error = '[parse] e2e failure', scheduled_at = NULL, updated_at = NOW() WHERE id = $jobId");
$stmt = $db->prepare("INSERT INTO deploy_job_logs (job_id, seq, stream, line) VALUES (?, 1, 'stderr', '[parse] e2e failure')");
$stmt->bind_param('i', $jobId);
$stmt->execute();
repo_esxi_inventory_record_failure($db, $esxiId, VIRTUSPHERE_INVENTORY_ERROR_PARSE, $jobId);
echo 'FAILED';
`, ['lib/repo/esxi_inventory.php']);

  await page.goto(`system_status.php#credential-${seed.esxi}`);
  const failedCard = page.locator(`#credential-${seed.esxi}`);
  const failedLog = failedCard.locator(`.alert-error a[href="deploy_log.php?id=${seed.job}"]`);
  await expect(failedLog, 'the durable failure links the exact job that produced it').toBeVisible();
  await failedLog.click();
  await expect(page).toHaveURL(new RegExp(`deploy_log\\.php\\?id=${seed.job}$`));
  await expect(page.locator('table .log-line')).toContainText(/e2e failure/);
  const origin = `system_status.php?inventory=${seed.esxi}#credential-${seed.esxi}`;
  await expect(page.getByRole('link', { name: /Zurück|Back/ })).toHaveAttribute('href', origin);
  await page.getByRole('link', { name: /Zurück|Back/ }).click();
  await expect(page).toHaveURL(new RegExp(`inventory=${seed.esxi}#credential-${seed.esxi}$`));

  // Etappe 9: the same durable failure, but with an origin this card cannot
  // show. The sentence names the Ansible host, so the card carries the way to
  // the section that does, and the two follow-ups must stay two.
  runPhp(`
$db = db();
repo_esxi_inventory_record_failure($db, ${Number(seed.esxi)}, VIRTUSPHERE_INVENTORY_ERROR_ANSIBLE_AUTH, ${Number(seed.job)});
echo 'FAILED';
`, ['lib/repo/esxi_inventory.php']);

  await page.goto(`system_status.php#credential-${seed.esxi}`);
  const alert = page.locator(`#credential-${seed.esxi} .alert-error`);
  const repair = alert.getByRole('link', { name: /Ansible-Status öffnen|Open Ansible status/ });
  const jobLog = alert.locator(`a[href="deploy_log.php?id=${seed.job}"]`);
  await expect(repair, 'an ansible_* failure carries the way to the Ansible section').toBeVisible();
  await expect(jobLog, 'the job log stays its own separate link').toBeVisible();

  // The defect this branch introduced was purely visual: side by side with one
  // space between them, two underlined links read as a single long link. The
  // geometry is the assertion, not the markup.
  const gap = await alert.evaluate((node) => {
    const links = [...node.querySelectorAll('a')].map((a) => a.getBoundingClientRect());
    return links.length === 2 ? links[1].left - links[0].right : -1;
  });
  expect(gap, 'the repair link and the job log stay visibly separate').toBeGreaterThanOrEqual(10);

  await repair.click();
  await expect(page).toHaveURL(/system_status\.php#ansible$/);
  await expect(page.locator('#ansible'), 'the anchor names a section this page really renders').toBeVisible();
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

// The overview has six fixed signals and one optional directory signal. Desktop
// shows three cards per row; seven cards take four on the first row so that no
// card sits alone on the last one (4 + 3, never 3 + 3 + 1). The renderer derives
// that from the count, so this test measures the rows the browser actually
// placed, grouped by card top, instead of asserting the modifier class.
test('Systemstatus overview: all visible cards share the responsive grid', async ({ page }) => {
  await page.setViewportSize({ width: 1600, height: 900 });
  await page.goto('system_status.php');

  const cards = page.locator('.status-overview .status-overview-card');
  const count = await cards.count();
  expect(count, 'the strip carries all six fixed health signals').toBeGreaterThanOrEqual(6);
  expect(count, 'at most the directory signal is conditional').toBeLessThanOrEqual(7);

  // Cards per row, in document order.
  const rowsAt = async () => page.locator('.status-overview').evaluate((strip) => {
    const rows = new Map();
    for (const card of strip.querySelectorAll('.status-overview-card')) {
      const top = Math.round(card.getBoundingClientRect().top);
      rows.set(top, (rows.get(top) || 0) + 1);
    }
    return [...rows.values()];
  });

  const desktopRows = count === 7 ? [4, 3] : [3, 3];
  expect(await rowsAt(), 'desktop shows three cards per row, four on the first row for seven cards').toEqual(desktopRows);

  // Still the desktop rule at a narrow laptop width, where the sidebar leaves
  // little room: the columns are fixed, so the row split must not change.
  await page.setViewportSize({ width: 1000, height: 900 });
  expect(await rowsAt(), 'the desktop split holds down to the 850px step').toEqual(desktopRows);

  // At 850px and below the strip takes two columns, so the last row may be short.
  await page.setViewportSize({ width: 800, height: 900 });
  const tabletRows = count === 7 ? [2, 2, 2, 1] : [2, 2, 2];
  expect(await rowsAt(), 'at 850px and below the strip takes two columns').toEqual(tabletRows);

  await page.setViewportSize({ width: 500, height: 900 });
  expect(await rowsAt(), 'on a narrow viewport every card gets its own row').toEqual(Array(count).fill(1));
});

test('Systemstatus overview: long labels and statuses stay readable inside each card', async ({ page }) => {
  await page.goto('system_status.php');

  const deployCard = page.locator('.status-overview-card[href="system_status.php#deploy-service"]');
  const ansibleCard = page.locator('.status-overview-card[href="system_status.php#ansible"]');
  await deployCard.locator('.badge').evaluate((badge) => {
    badge.textContent = 'Manual review required with UnbrokenRecoveryToken0123456789';
  });
  await ansibleCard.locator('.status-overview-label').evaluate((label) => {
    label.textContent = 'Ansible integration test';
  });

  const assertContained = async (context) => {
    const measurements = await page.locator('.status-overview').evaluate((overview) => {
      const navigation = overview.getBoundingClientRect();
      const cards = [...overview.querySelectorAll('.status-overview-card')].map((card) => {
        const style = getComputedStyle(card);
        const box = card.getBoundingClientRect();
        const label = card.querySelector('.status-overview-label').getBoundingClientRect();
        const badge = card.querySelector('.badge').getBoundingClientRect();
        return {
          href: card.getAttribute('href'),
          contentLeft: box.left + parseFloat(style.paddingLeft),
          contentRight: box.right - parseFloat(style.paddingRight),
          contentTop: box.top + parseFloat(style.paddingTop),
          contentBottom: box.bottom - parseFloat(style.paddingBottom),
          label: { left: label.left, right: label.right, top: label.top, bottom: label.bottom },
          badge: { left: badge.left, right: badge.right, top: badge.top, bottom: badge.bottom },
          scrollWidth: card.scrollWidth,
          clientWidth: card.clientWidth,
        };
      });
      return {
        navigation: { scrollWidth: overview.scrollWidth, clientWidth: overview.clientWidth, left: navigation.left, right: navigation.right },
        cards,
      };
    });

    expect(measurements.navigation.scrollWidth, `${context}: overview has no horizontal scroll`).toBeLessThanOrEqual(measurements.navigation.clientWidth + 1);
    for (const card of measurements.cards) {
      expect(card.scrollWidth, `${context}: ${card.href} has no horizontal overflow`).toBeLessThanOrEqual(card.clientWidth + 1);
      for (const [name, child] of [['label', card.label], ['badge', card.badge]]) {
        expect(child.left, `${context}: ${card.href} ${name} starts inside padding`).toBeGreaterThanOrEqual(card.contentLeft - 1);
        expect(child.right, `${context}: ${card.href} ${name} ends inside padding`).toBeLessThanOrEqual(card.contentRight + 1);
        expect(child.top, `${context}: ${card.href} ${name} starts inside padding`).toBeGreaterThanOrEqual(card.contentTop - 1);
        expect(child.bottom, `${context}: ${card.href} ${name} ends inside padding`).toBeLessThanOrEqual(card.contentBottom + 1);
      }
      expect(card.badge.top, `${context}: ${card.href} status follows its label`).toBeGreaterThanOrEqual(card.label.bottom - 1);
    }
  };

  for (const width of [1600, 1366, 1024, 900, 768, 560, 360, 320]) {
    await page.setViewportSize({ width, height: 900 });
    await assertContained(`${width}px at normal text size`);
  }

  await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
  for (const width of [1024, 360, 320]) {
    await page.setViewportSize({ width, height: 900 });
    await assertContained(`${width}px at 200% text size`);
  }

  await deployCard.focus();
  await expect(deployCard).toBeFocused();
  const focus = await deployCard.evaluate((card) => {
    const style = getComputedStyle(card);
    return { style: style.outlineStyle, width: parseFloat(style.outlineWidth) };
  });
  expect(focus.style, 'keyboard focus uses a visible outline').not.toBe('none');
  expect(focus.width, 'keyboard focus outline remains at least two pixels wide').toBeGreaterThanOrEqual(2);

  await deployCard.click();
  await expect(page).toHaveURL(/system_status\.php#deploy-service$/);
  await expect(page.locator('#deploy-service')).toBeVisible();
});

// The count is one number with two readers. The strip must not be able to say
// something the section below it contradicts, which is only observable with
// both rendered from the same request.
test('Systemstatus overview: the deviation card and its section agree', async ({ page }) => {
  await page.setViewportSize({ width: 1600, height: 900 });
  await page.goto('system_status.php');

  const card = page.locator('.status-overview .status-overview-card', { hasText: 'Deviations' });
  await expect(card).toHaveCount(1);
  const cardBadge = (await card.locator('.badge').textContent()).trim();
  const sectionBadge = (await page.locator('#deviations h2 .badge').textContent()).trim();

  expect(cardBadge, 'strip and section render the same one count').toBe(sectionBadge);

  // And the card really goes there: same document, so the fragment jump keeps
  // the page rather than reloading it.
  await card.click();
  await expect(page).toHaveURL(/system_status\.php#deviations$/);
  await expect(page.locator('#deviations')).toBeVisible();
});

// K19 (VT-E2): without a stored backup certificate System status says the
// backups are unencrypted. The certificate is pasted on the settings page: a
// private key is refused and never stored, a certificate is stored after its
// confirmation and lifts the finding, and removing it (confirmed) brings the
// finding back. The pair is generated per run; the previous setting is restored.
// e2e-covers: settings.php:save_backup_certificate
// e2e-covers: settings.php:remove_backup_certificate
// e2e-covers-cancel: settings.php:save_backup_certificate
// e2e-covers-cancel: settings.php:remove_backup_certificate
test('backup encryption: no certificate reports unencrypted backups, a stored one lifts it', async ({ page }) => {
  const libs = ['lib/constants.php', 'lib/repo/settings.php'];
  const stored = () => phpJson(`
$v = repo_setting_value(db(), VIRTUSPHERE_SETTING_BACKUP_ENCRYPTION_CERTIFICATE, '');
echo 'JSON' . json_encode(['v' => $v]) . 'JSON';
`, libs).v;
  const before = stored();
  const restore = (value) => runPhp(`
$v = base64_decode('${Buffer.from(value).toString('base64')}');
$v === '' ? repo_delete_setting(db(), VIRTUSPHERE_SETTING_BACKUP_ENCRYPTION_CERTIFICATE) : repo_set_setting(db(), VIRTUSPHERE_SETTING_BACKUP_ENCRYPTION_CERTIFICATE, $v);
echo 'OK';
`, libs);
  const pair = phpJson(`
$key = openssl_pkey_new(['private_key_bits' => VIRTUSPHERE_BACKUP_ENCRYPTION_MIN_RSA_BITS, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'e2e-k19'], $key), null, $key, 30);
openssl_x509_export($cert, $pem);
openssl_pkey_export($key, $keyPem);
echo 'JSON' . json_encode(['cert' => $pem, 'key' => $keyPem]) . 'JSON';
`, ['lib/backup_encryption.php']);
  const finding = page.locator('[data-backup-encryption-finding]');
  const unencrypted = /no certificate is configured|kein Zertifikat hinterlegt/;
  const dialog = page.locator('[data-confirm-dialog]');
  const saveForm = page.locator('form:has(input[name="action"][value="save_backup_certificate"])');
  const cancelConfirm = async (button) => {
    await button.click();
    await expect(dialog, 'the action asks first').toBeVisible();
    await dialog.locator('button[value="cancel"]').click();
    await expect(dialog).toBeHidden();
  };
  const submitConfirmed = async (button) => {
    await button.click();
    await expect(dialog, 'the action asks first').toBeVisible();
    // Wait for the redirect page to load, not only for the POST answer: a
    // following page.goto would otherwise race the redirect (B1).
    await submitAndWaitForNavigation(page, dialog.locator('[data-confirm-accept]'), 'settings.php');
  };
  try {
    restore('');
    await page.goto('system_status.php');
    await expect(finding, 'no certificate: the unencrypted state is named').toContainText(unencrypted);
    await expect(finding.locator('a'), 'the remedy is linked for an admin').toHaveAttribute('href', /settings\.php#panel-backup$/);

    await page.goto('settings.php#panel-backup');
    await saveForm.locator('textarea[name="backup_certificate"]').fill(pair.key);
    await submitConfirmed(saveForm.locator('button[type="submit"]'));
    expect(stored(), 'a private key is refused and never stored').toBe('');
    await expect(page.locator('body'), 'the pasted key is not echoed back').not.toContainText('PRIVATE KEY-----');

    await page.goto('settings.php#panel-backup');
    await saveForm.locator('textarea[name="backup_certificate"]').fill(pair.cert);
    await cancelConfirm(saveForm.locator('button[type="submit"]'));
    expect(stored(), 'Cancel stored nothing').toBe('');
    await submitConfirmed(saveForm.locator('button[type="submit"]'));
    expect(stored(), 'the certificate is stored').toContain('BEGIN CERTIFICATE');
    await page.goto('system_status.php');
    await expect(page.locator('body'), 'a stored certificate lifts the unencrypted state').not.toContainText(unencrypted);

    await page.goto('settings.php#panel-backup');
    const removeButton = page.locator('form:has(input[name="action"][value="remove_backup_certificate"]) button');
    await cancelConfirm(removeButton);
    expect(stored(), 'Cancel kept the certificate').toContain('BEGIN CERTIFICATE');
    await submitConfirmed(removeButton);
    expect(stored(), 'the certificate is removed').toBe('');
    await page.goto('system_status.php');
    await expect(finding, 'removing it brings the finding back').toContainText(unencrypted);
  } finally {
    restore(before);
  }
});
