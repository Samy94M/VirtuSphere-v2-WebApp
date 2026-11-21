// VB2: phase, finding and MECM cell of a VM, the editor's status blocks and the
// dashboard VM tiles follow without a reload. The poll is read-only
// (portal/vm_status_live.php), so no e2e-covers marker.
//
// The interval is a server constant; every test installs Playwright's clock
// before the page loads and moves it past the interval instead of waiting.

const { test, expect } = require('../lib/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const PREFIX = 'e2evl-';
const LIVE = /vm_status_live\.php\?/;

/** A mission whose VMs carry the given lifecycle/MECM pairs. */
function seedMission(name, states) {
  return phpJson(`
$db = db();
$id = repo_create_mission($db, ['mission_name' => '${name}'], false, null);
$vmIds = [];
foreach (json_decode('${JSON.stringify(states)}', true) as $i => [$lifecycle, $mecm]) {
    $vmName = 'E2EVL' . $id . 'N' . $i;
    $stmt = $db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('issss', $id, $vmName, $vmName, $lifecycle, $mecm);
    $stmt->execute();
    $vmIds[] = (int) $db->insert_id;
}
echo 'JSON' . json_encode(['missionId' => $id, 'vmIds' => $vmIds]) . 'JSON';
`, ['lib/repo/missions.php']);
}

function setState(vmId, lifecycle, mecm, updated = 0) {
  runPhp(`
$stmt = db()->prepare('UPDATE deploy_vms SET lifecycle_state = ?, mecm_sync_state = ?, updated = ? WHERE id = ?');
$lifecycle = '${lifecycle}';
$mecm = '${mecm}';
$updated = ${Number(updated)};
$vmId = ${Number(vmId)};
$stmt->bind_param('ssii', $lifecycle, $mecm, $updated, $vmId);
$stmt->execute();
echo 'OK';
`);
}

function addVm(missionId, name) {
  runPhp(`
$stmt = db()->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
$missionId = ${Number(missionId)};
$name = '${name}';
$stmt->bind_param('iss', $missionId, $name, $name);
$stmt->execute();
echo 'OK';
`);
}

function cleanup() {
  runPhp(`
$db = db();
$like = '${PREFIX}%';
$stmt = $db->prepare('DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)');
$stmt->bind_param('s', $like);
$stmt->execute();
$stmt = $db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
$stmt->bind_param('s', $like);
$stmt->execute();
echo 'CLEAN';
`);
}

/** Loads a page with the fake clock in place and returns its poll interval in ms. */
async function openLive(page, url) {
  await page.clock.install();
  await page.goto(url);
  const line = page.locator('[data-vm-live]');
  await expect(line, 'the script shows the line the server rendered hidden').toBeVisible();
  // A marker a reload would lose: every assertion after a poll re-reads it.
  await page.evaluate(() => { window.vbLiveMarker = 'same-document'; });
  return Number(await line.getAttribute('data-vm-live-interval')) * 1000;
}

/** Moves the clock past one interval and waits for the answer of that poll. */
async function nextPoll(page, intervalMs) {
  const answered = page.waitForResponse((response) => LIVE.test(response.url()), { timeout: 15000 });
  await page.clock.fastForward(intervalMs + 100);
  return answered;
}

async function expectSameDocument(page) {
  expect(await page.evaluate(() => window.vbLiveMarker), 'the page was not reloaded').toBe('same-document');
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

test('the VM list follows a state change without a reload and names a changed list', async ({ page }) => {
  const seed = seedMission(PREFIX + 'list', [['ready', 'not_ready'], ['ready', 'not_ready']]);
  const [moving, still] = seed.vmIds;
  const intervalMs = await openLive(page, `vms.php?mission_id=${seed.missionId}`);
  expect(intervalMs, 'the interval comes from the page').toBeGreaterThan(0);

  const phase = page.locator(`#vm-${moving} [data-vm-live-cell="phase"]`);
  const mecm = page.locator(`#vm-${moving} [data-vm-live-cell="mecm"]`);
  const finding = page.locator(`#vm-${moving} [data-vm-live-cell="finding"]`);
  await expect(phase).toHaveText(/Created|Angelegt/);
  const stillSig = await page.locator(`#vm-${still} [data-vm-live-cell="phase"]`).getAttribute('data-vm-live-sig');
  const asOf = page.locator('[data-vm-live-as-of]');
  await expect(asOf).toHaveText(/VM status as of \d\d:\d\d:\d\d|VM-Status: Stand \d\d:\d\d:\d\d/);
  await expect(page.locator('[data-vm-live-status]'), 'the status sentence is a live region of its own').toHaveAttribute('role', 'status');
  await expect(page.locator('[data-vm-live-status]')).toHaveText('');

  // An unchanged poll replaces nothing: the node of the cell is the same one.
  await phase.evaluate((cell) => { cell.dataset.e2eNode = 'first'; cell.firstElementChild.dataset.e2eChild = 'kept'; });
  const quiet = await nextPoll(page, intervalMs);
  expect(quiet.status()).toBe(200);
  expect(quiet.headers()['content-type']).toContain('application/json');
  await expect(phase.locator('[data-e2e-child="kept"]'), 'a cell that did not change keeps its content').toHaveCount(1);
  await expect(page.locator('[data-vm-live-status]')).toHaveText('');

  setState(moving, 'deployed', 'pending', 1);
  await nextPoll(page, intervalMs);
  await expect(phase, 'the phase follows the stored state').toHaveText(/MAC known|MAC bekannt/);
  await expect(mecm, 'the MECM cell carries the state and the queued flag').toContainText(/Waiting for MECM|Wartet auf MECM/);
  await expect(mecm).toContainText(/Queued for MECM|Für MECM vorgemerkt/);
  await expect(finding.locator('a'), 'the finding of the MECM wait appears with its link').not.toHaveCount(0);
  await expect(phase.locator('[data-e2e-child="kept"]'), 'the changed cell was replaced').toHaveCount(0);
  await expect(page.locator(`#vm-${still} [data-vm-live-cell="phase"]`), 'the other row is untouched').toHaveAttribute('data-vm-live-sig', stillSig);
  await expect(page.locator('[data-vm-live-status]')).toHaveText(/Last change \d\d:\d\d:\d\d|Zuletzt geändert \d\d:\d\d:\d\d/);
  await expect(page.locator('[data-vm-live-notice]'), 'the same VMs, no notice').toBeHidden();
  await expectSameDocument(page);

  // A VM joins the mission: no row is added, a notice names the change once.
  addVm(seed.missionId, `E2EVL${seed.missionId}NEW`);
  await nextPoll(page, intervalMs);
  const notice = page.locator('[data-vm-live-notice]');
  await expect(notice).toBeVisible();
  await expect(notice).toContainText(/The list has changed|Die Liste hat sich geändert/);
  await expect(notice.locator('a'), 'the notice carries the link that shows the list').toHaveAttribute('href', new RegExp(`^vms\\.php\\?mission_id=${seed.missionId}`));
  await expect(page.locator('tbody tr[id^="vm-"]'), 'no row is added by the poll').toHaveCount(2);
  await expect(page.locator('[data-vm-live-status]'), 'the status line speaks the sentence of the notice').toHaveText(/The list has changed|Die Liste hat sich geändert/);
  await expectSameDocument(page);
});

test('a VM list sorted by status says so when a state change breaks its order', async ({ page }) => {
  const seed = seedMission(PREFIX + 'order', [['ready', 'not_ready'], ['ready', 'not_ready']]);
  const intervalMs = await openLive(page, `vms.php?mission_id=${seed.missionId}&sort=status`);
  const first = (await page.locator('tbody tr[id^="vm-"]').first().getAttribute('id')).slice(3);
  const notice = page.locator('[data-vm-live-notice]');

  // The last row moves up the chain: still sorted, no notice.
  const last = seed.vmIds.map(String).find((id) => id !== first);
  setState(Number(last), 'deployed', 'pending', 1);
  await nextPoll(page, intervalMs);
  await expect(page.locator(`#vm-${last} [data-vm-live-cell="phase"]`)).toHaveText(/MAC known|MAC bekannt/);
  await expect(notice, 'the rows are still in status order').toBeHidden();

  // The first row overtakes it: the row stays where it is and the list says so.
  setState(Number(first), 'os_installing', 'registered');
  await nextPoll(page, intervalMs);
  await expect(page.locator(`#vm-${first} [data-vm-live-cell="phase"]`)).toHaveText(/Handed to MECM|An MECM übergeben/);
  await expect(page.locator('tbody tr[id^="vm-"]').first(), 'no row is moved by the poll').toHaveAttribute('id', `vm-${first}`);
  await expect(notice).toContainText(/The list has changed|Die Liste hat sich geändert/);
  await expect(notice.locator('a')).toHaveAttribute('href', /sort=status/);
  await expectSameDocument(page);
});

test('the VM overview keeps a row that left its filter, updates it and says the list changed', async ({ page }) => {
  const seed = seedMission(PREFIX + 'overview', [['failed', 'not_ready']]);
  const vmId = seed.vmIds[0];
  const intervalMs = await openLive(page, 'vm_overview.php?filter=job_failed');

  const row = page.locator(`#vm-${vmId}`);
  await expect(row).toHaveCount(1);
  const phase = row.locator('[data-vm-live-cell="phase"]');
  const before = await phase.innerText();
  await nextPoll(page, intervalMs);
  await expect(page.locator('[data-vm-live-notice]'), 'an unchanged list has no notice').toBeHidden();

  setState(vmId, 'os_installed', 'registered');
  await nextPoll(page, intervalMs);
  await expect(phase, 'the row shows the state that is true now').not.toHaveText(before);
  await expect(row, 'the row is not removed by the poll').toHaveCount(1);
  const notice = page.locator('[data-vm-live-notice]');
  await expect(notice).toContainText(/The list has changed|Die Liste hat sich geändert/);
  await expect(notice.locator('a')).toHaveAttribute('href', /^vm_overview\.php\?filter=job_failed/);
  await expectSameDocument(page);
});

test('the dashboard VM tiles follow the counts and the check-due tile appears in place', async ({ page }) => {
  cleanup();
  const intervalMs = await openLive(page, 'dashboard.php');
  const all = page.locator('[data-vm-live-region="tile-all"] .value');
  const before = Number(await all.innerText());
  // Other specs may have left a failed VM behind, so the group is counted, not assumed absent.
  const group = page.locator('[data-vm-live-region="tile-attention"] a[href*="filter=job_failed"]');
  const groupCount = group.locator('.kpi-group-count');
  const failedBefore = (await group.count()) === 0 ? 0 : Number(await groupCount.innerText());
  await expect(page.locator('[data-vm-live-as-of]')).toHaveText(/VM counts as of|VM-Zahlen: Stand/);

  seedMission(PREFIX + 'tiles', [['failed', 'not_ready'], ['ready', 'not_ready']]);
  await nextPoll(page, intervalMs);
  await expect(all, 'the VM tile counts the two new VMs').toHaveText(String(before + 2));
  await expect(group, 'the check-due group of the failed VM is on the dashboard').toHaveCount(1);
  await expect(groupCount).toHaveText(String(failedBefore + 1));
  // The tile sits where the page renders it: right after the MECM tile.
  const order = await page.locator('.kpi-grid > *').evaluateAll((tiles) => tiles.map((tile) => tile.getAttribute('data-vm-live-region') || ''));
  expect(order.indexOf('tile-attention')).toBe(order.indexOf('tile-mecm_pending') + 1);
  await expect(group, 'the tile link carries its own counting time').toHaveAttribute('href', new RegExp(`counted=\\d+&expected=${failedBefore + 1}`));
  await expectSameDocument(page);

  cleanup();
  await nextPoll(page, intervalMs);
  await expect(all).toHaveText(String(before));
  if (failedBefore === 0) {
    await expect(group, 'a group without hits leaves the tile').toHaveCount(0);
  } else {
    await expect(groupCount).toHaveText(String(failedBefore));
  }
});

test('the VM editor replaces only its status blocks and leaves the form alone', async ({ page }) => {
  const seed = seedMission(PREFIX + 'editor', [['ready', 'not_ready']]);
  const vmId = seed.vmIds[0];
  const intervalMs = await openLive(page, `vm_edit.php?mission_id=${seed.missionId}&vm_id=${vmId}`);

  const status = page.locator('[data-vm-live-region="status"]');
  await expect(status.locator('.vm-status-head .badge')).toHaveText(/Created|Angelegt/);
  await expect(page.locator('#vm-progress-watch'), 'nothing is observed yet').toHaveCount(0);

  // Unsaved input and the focus in a field of the editor form.
  const notes = page.locator('#vm-editor-form textarea[name="vm_notes"]');
  await notes.fill('typed before the poll');
  await notes.focus();
  await page.locator('#vm-editor-form').evaluate((form) => { form.dataset.e2eForm = 'kept'; });
  const version = await page.locator('#vm-editor-form input[name="edit_version"]').inputValue();
  const token = await page.locator('#vm-editor-form input[name="_csrf"]').inputValue();

  setState(vmId, 'os_installing', 'registered');
  await nextPoll(page, intervalMs);

  await expect(status.locator('.vm-status-head .badge'), 'the status panel follows').not.toHaveText(/Created|Angelegt/);
  const progress = page.locator('#vm-progress-watch');
  await expect(progress, '"check progress" appears without a reload').toHaveCount(1);
  expect(
    await page.evaluate(() => {
      const blocks = Array.from(document.querySelectorAll('[data-vm-live-region]')).map((block) => block.getAttribute('data-vm-live-region'));
      return blocks.join(',');
    }),
    'the block sits before the status panel, as the page renders it'
  ).toBe('progress,status');

  await expect(notes, 'the unsaved input is still there').toHaveValue('typed before the poll');
  expect(await notes.evaluate((field) => document.activeElement === field), 'the focus stays in the field').toBe(true);
  await expect(page.locator('#vm-editor-form[data-e2e-form="kept"]'), 'the form element was not replaced').toHaveCount(1);
  await expect(page.locator('#vm-editor-form input[name="edit_version"]'), 'the edit version is the one the page loaded').toHaveValue(version);
  await expectSameDocument(page);

  // The block the poll rendered is usable: its form carries the page's token
  // and its button opens the shared confirmation.
  await expect(progress.locator('input[name="_csrf"]')).toHaveValue(token);
  await progress.locator('button[type="submit"]').click();
  const dialog = page.locator('[data-confirm-dialog]');
  await expect(dialog).toBeVisible();

  // Nothing is replaced while the confirmation is open.
  await progress.evaluate((block) => { block.dataset.e2eBlock = 'kept'; });
  setState(vmId, 'os_installed', 'registered');
  await nextPoll(page, intervalMs);
  await expect(page.locator('#vm-progress-watch[data-e2e-block="kept"]'), 'an open confirmation keeps its block').toHaveCount(1);
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
});

test('a background tab does not poll and returns with exactly one catch-up', async ({ page }) => {
  const seed = seedMission(PREFIX + 'hidden', [['ready', 'not_ready']]);
  let requests = 0;
  await page.route(LIVE, async (route) => {
    requests += 1;
    await route.continue();
  });
  const intervalMs = await openLive(page, `vms.php?mission_id=${seed.missionId}`);
  await nextPoll(page, intervalMs);
  expect(requests).toBe(1);

  // document.hidden is what the module reads, so overriding the property is the
  // honest stand-in for a tab in the background.
  await page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  setState(seed.vmIds[0], 'deployed', 'pending', 1);
  await page.clock.fastForward(intervalMs * 4);
  await page.waitForTimeout(500);
  expect(requests, 'no poll while the tab is in the background').toBe(1);
  await expect(page.locator(`#vm-${seed.vmIds[0]} [data-vm-live-cell="phase"]`)).toHaveText(/Created|Angelegt/);

  const answered = page.waitForResponse((response) => LIVE.test(response.url()), { timeout: 15000 });
  await page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await page.clock.fastForward(50);
  await answered;
  await expect(page.locator(`#vm-${seed.vmIds[0]} [data-vm-live-cell="phase"]`), 'the catch-up brings the state').toHaveText(/MAC known|MAC bekannt/);
  // Well inside one interval: a burst of the missed polls would show here.
  await page.clock.fastForward(Math.floor(intervalMs / 2));
  await page.waitForTimeout(500);
  expect(requests, 'one catch-up, not the polls the tab missed').toBe(2);
});

test('an answer that is no verdict shows "not current", and an ended session stops the polling', async ({ page, context }) => {
  const seed = seedMission(PREFIX + 'session', [['ready', 'not_ready']]);
  let requests = 0;
  let broken = true;
  await page.route(LIVE, async (route) => {
    requests += 1;
    if (broken) {
      await route.fulfill({ status: 500, contentType: 'application/json', body: '{"ok":false}' });
      return;
    }
    await route.continue();
  });
  const intervalMs = await openLive(page, `vms.php?mission_id=${seed.missionId}`);
  const asOf = page.locator('[data-vm-live-as-of]');
  const status = page.locator('[data-vm-live-status]');
  const reload = page.locator('[data-vm-live-reload]');

  await nextPoll(page, intervalMs);
  await expect(status, 'a failed poll is named').toHaveText(/Not current, last update \d\d:\d\d:\d\d\.|Stand nicht aktuell, letzter Abruf \d\d:\d\d:\d\d\./);
  await expect(asOf, 'the old "as of" is not left standing').toBeHidden();
  await expect(reload).toBeHidden();

  // A sign-in page answering 200 is no verdict either.
  await page.unroute(LIVE);
  await page.route(LIVE, async (route) => {
    requests += 1;
    if (broken) {
      await route.fulfill({ status: 200, contentType: 'text/html', body: '<html><body>login</body></html>' });
      return;
    }
    await route.continue();
  });
  await nextPoll(page, intervalMs);
  await expect(asOf).toBeHidden();

  broken = false;
  await nextPoll(page, intervalMs);
  await expect(asOf, 'the next good answer is the state again').toBeVisible();
  await expect(status).not.toHaveText(/Not current|Stand nicht aktuell/);

  // The session ends: the endpoint answers 401 and the script stops for good.
  await context.clearCookies();
  const refused = await nextPoll(page, intervalMs);
  expect(refused.status()).toBe(401);
  await expect(status).toHaveText(/session expired|Sitzung ist abgelaufen/);
  await expect(asOf).toBeHidden();
  await expect(reload, 'the stop carries the way back').toBeVisible();
  const parked = requests;
  await page.clock.fastForward(intervalMs * 3);
  await page.waitForTimeout(500);
  expect(requests, 'no retry after a refusal').toBe(parked);
});

test('the endpoint refuses what is not a poll and answers only with JSON', async ({ page, browser }, testInfo) => {
  const seed = seedMission(PREFIX + 'wire', [['ready', 'not_ready']]);
  const good = await page.request.get(`vm_status_live.php?view=mission&mission_id=${seed.missionId}&ids=${seed.vmIds[0]},999999999&count=1`);
  expect(good.status()).toBe(200);
  expect(good.headers()['cache-control']).toContain('no-store');
  const body = await good.json();
  expect(body.ok).toBe(true);
  expect(Object.keys(body.rows), 'an unknown id is left out without a word').toEqual([String(seed.vmIds[0])]);
  expect(Object.keys(body.rows[String(seed.vmIds[0])]).sort()).toEqual(['finding', 'mecm', 'phase']);
  expect(body.list_changed, 'a requested VM that is not in the mission is a changed list').toBe(true);

  for (const query of ['', 'view=nowhere', 'view=mission', `view=mission&mission_id=${seed.missionId}&ids=1,x`, `view=overview&ids=${Array.from({ length: 201 }, (_, i) => i + 1).join(',')}`]) {
    const refused = await page.request.get('vm_status_live.php?' + query);
    expect(refused.status(), `"${query}" is not a poll`).toBe(400);
    expect(refused.headers()['content-type']).toContain('application/json');
    expect((await refused.json()).ok).toBe(false);
  }
  const post = await page.request.post('vm_status_live.php?view=dashboard');
  expect(post.status()).toBe(405);

  // An explicit empty state: a new context would otherwise inherit the signed-in one.
  const anonymous = await browser.newContext({ baseURL: testInfo.project.use.baseURL, storageState: { cookies: [], origins: [] } });
  try {
    const answer = await anonymous.request.get('vm_status_live.php?view=dashboard');
    expect(answer.status(), 'no session, no status').toBe(401);
    expect((await answer.json()).ok).toBe(false);
  } finally {
    await anonymous.close();
  }
});

test('without JavaScript the pages are what they were', async ({ browser }, testInfo) => {
  const seed = seedMission(PREFIX + 'nojs', [['ready', 'not_ready']]);
  const context = await browser.newContext({ baseURL: testInfo.project.use.baseURL, javaScriptEnabled: false, storageState: ROLES.admin.storageState });
  try {
    const page = await context.newPage();
    for (const url of [`vms.php?mission_id=${seed.missionId}`, 'vm_overview.php?filter=all', 'dashboard.php', `vm_edit.php?mission_id=${seed.missionId}&vm_id=${seed.vmIds[0]}`]) {
      await page.goto(url);
      await expect(page.locator('[data-vm-live]'), `${url} renders the line`).toHaveCount(1);
      await expect(page.locator('[data-vm-live]'), `${url} keeps it hidden`).toBeHidden();
      await expect(page.locator('[data-vm-live-notice]')).toBeHidden();
    }
  } finally {
    await context.close();
  }
});
