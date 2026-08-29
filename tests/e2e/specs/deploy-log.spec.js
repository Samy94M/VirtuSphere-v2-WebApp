const { test, expect, request } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const MARK = 'e2edeploylog';

function cleanup() {
  runPhp(`
$db = db();
$db->query("DELETE FROM deploy_jobs WHERE payload_json LIKE '%${MARK}%'");
echo 'CLEANED';
`);
}

function seedJob(status, count, worker = null) {
  try {
    return phpJson(`
$db = db();
$status = ${JSON.stringify(status)};
$worker = ${worker === null ? 'null' : JSON.stringify(worker)};
$payload = json_encode(['mode' => 'inventory', 'fixture' => '${MARK}'], JSON_THROW_ON_ERROR);
if ($worker === null) {
    $stmt = $db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json) VALUES (NULL, ?, ?)');
    $stmt->bind_param('ss', $status, $payload);
} else {
    $stmt = $db->prepare('INSERT INTO deploy_jobs (mission_id, status, payload_json, locked_at, locked_by, heartbeat_at) VALUES (NULL, ?, ?, NOW(), ?, NOW())');
    $stmt->bind_param('sss', $status, $payload, $worker);
}
$stmt->execute();
$job = (int) $db->insert_id;
for ($start = 1; $start <= ${Number(count)}; $start += 250) {
    $values = [];
    for ($seq = $start; $seq <= min(${Number(count)}, $start + 249); $seq++) {
        $values[] = sprintf("(%d,%d,'ansible','${MARK} line %d')", $job, $seq, $seq);
    }
    $db->query('INSERT INTO deploy_job_logs (job_id, seq, stream, line) VALUES ' . implode(',', $values));
}
echo 'JSON' . json_encode(['job' => $job]) . 'JSON';
`, ['lib/repo/deploy_jobs.php']).job;
  } catch (error) {
    throw new Error(`deploy-log fixture failed: ${error.message || error}\ncode: ${error.status || error.code || ''}\nstdout: ${error.stdout || ''}\nstderr: ${error.stderr || ''}`);
  }
}

function appendAndFinish(job, first, last, worker) {
  runPhp(`
$db = db();
$job = ${Number(job)};
for ($start = ${Number(first)}; $start <= ${Number(last)}; $start += 250) {
    $values = [];
    for ($seq = $start; $seq <= min(${Number(last)}, $start + 249); $seq++) {
        $values[] = sprintf("(%d,%d,'ansible','${MARK} line %d')", $job, $seq, $seq);
    }
    $db->query('INSERT INTO deploy_job_logs (job_id, seq, stream, line) VALUES ' . implode(',', $values));
}
if (!repo_finish_deploy_job($db, $job, ${JSON.stringify(worker)}, VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED)) {
    throw new RuntimeException('fixture terminal CAS failed');
}
echo 'FINISHED';
`, ['lib/repo/deploy_jobs.php']);
}

test.beforeEach(() => cleanup());
test.afterAll(() => cleanup());

test('initial HTML is the newest tail, older cursor preserves the anchor, raw download is complete', async ({ page }) => {
  const job = seedJob('succeeded', 1205);
  await page.goto(`deploy_log.php?id=${job}`);

  const rows = page.locator('[data-deploy-log-body] [data-log-seq]');
  await expect(rows).toHaveCount(1000);
  await expect(rows.first()).toHaveAttribute('data-log-seq', '206');
  await expect(rows.last()).toHaveAttribute('data-log-seq', '1205');

  const wrap = page.locator('[data-deploy-log-body]').locator('..').locator('..');
  await wrap.evaluate((element) => { element.scrollTop = 120; });
  const before = await wrap.evaluate((element) => element.scrollTop);
  await page.locator('[data-deploy-log-older]').click();
  await expect(rows).toHaveCount(1205);
  await expect(rows.first()).toHaveAttribute('data-log-seq', '1');
  await expect(page.locator('[data-deploy-log-feedback]')).not.toHaveText('');
  expect(await wrap.evaluate((element) => element.scrollTop)).toBeGreaterThan(before);

  const response = await page.request.get(`deploy_log.php?id=${job}&format=raw`);
  expect(response.status()).toBe(200);
  expect(response.headers()['content-type']).toContain('application/x-ndjson');
  expect(response.headers()['content-disposition']).toBe(`attachment; filename="virtusphere-deploy-job-${job}.ndjson"`);
  expect(response.headers()['x-content-type-options']).toBe('nosniff');
  expect(response.headers()['x-virtusphere-log-retention-days']).toBe('30');
  const objects = (await response.text()).trim().split('\n').map((line) => JSON.parse(line));
  expect(objects).toHaveLength(1206);
  expect(objects[0]).toMatchObject({ type: 'meta', job_id: job, retention_days: 30 });
  expect(objects[1]).toMatchObject({ type: 'log', seq: 1, source: 'ansible' });
  expect(objects.at(-1)).toMatchObject({ type: 'log', seq: 1205, line: `${MARK} line 1205` });
  expect(objects[1].created_at_utc).toMatch(/Z$/);
  expect(objects[1].portal_timezone).toBeTruthy();
});

test('the live browser window stays bounded while every retained line remains in raw output', async ({ page }) => {
  const worker = 'e2e:deploy-log-window';
  const job = seedJob('running', 1200, worker);
  await page.goto(`deploy_log.php?id=${job}`);
  appendAndFinish(job, 1201, 1800, worker);

  const rows = page.locator('[data-deploy-log-body] [data-log-seq]');
  await expect(rows).toHaveCount(1500, { timeout: 12000 });
  await expect(rows.first()).toHaveAttribute('data-log-seq', '302');
  await expect(rows.last()).toHaveAttribute('data-log-seq', '1801');
  await expect(page.locator('[data-deploy-log-older]')).toBeVisible();

  const response = await page.request.get(`deploy_log.php?id=${job}&format=raw`);
  const objects = (await response.text()).trim().split('\n').map((line) => JSON.parse(line));
  expect(objects).toHaveLength(1802);
  expect(objects[1]).toMatchObject({ type: 'log', seq: 1 });
  expect(objects.at(-1)).toMatchObject({ type: 'log', seq: 1801, line: 'Deploy job succeeded.' });
});

test('terminal response with more than 500 pending lines drains to the atomic final line', async ({ page }) => {
  const worker = 'e2e:deploy-log';
  const job = seedJob('running', 5, worker);
  await page.goto(`deploy_log.php?id=${job}`);
  appendAndFinish(job, 6, 605, worker);

  // The badge shows the LABEL, never the stored token (Etappe 13,
  // requirement 3). The browser negotiates en, so this is the EN catalog's
  // wording; the second assertion is the one that would have caught the old
  // behaviour, because a raw `succeeded` is exactly what must not reappear.
  const badge = page.locator('[data-deploy-status]');
  await expect(badge).toHaveText('Succeeded', { timeout: 12000 });
  await expect(badge).not.toHaveText('succeeded');
  await expect(page.locator('[data-deploy-terminal-blocks] code')).toContainText('completed');
  await expect(page.locator('[data-deploy-cancel-form]')).toHaveCount(0);
  const rows = page.locator('[data-deploy-log-body] [data-log-seq]');
  await expect(rows).toHaveCount(606);
  await expect(rows.last()).toHaveAttribute('data-log-seq', '606');
  await expect(rows.last()).toContainText('Deploy job succeeded.');
  const seqs = await rows.evaluateAll((elements) => elements.map((row) => Number(row.dataset.logSeq)));
  expect(new Set(seqs).size).toBe(seqs.length);
  expect(seqs).toEqual(Array.from({ length: 606 }, (_, index) => index + 1));

  expect((await page.request.get(`deploy_log.php?id=${job}&format=json&after_seq=-1`)).status()).toBe(400);
  expect((await page.request.get(`deploy_log.php?id=${job}&format=json&before_seq=0`)).status()).toBe(400);
  expect((await page.request.get(`deploy_log.php?id=${job}&format=json&after_seq=1&before_seq=2`)).status()).toBe(400);
});

for (const status of [401, 403]) {
  test(`polling stops after HTTP ${status}`, async ({ page }) => {
    const job = seedJob('running', 1, `e2e:http-${status}`);
    let requests = 0;
    await page.route(/deploy_log\.php\?.*format=json/, async (route) => {
      requests += 1;
      await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ ok: false }) });
    });
    await page.goto(`deploy_log.php?id=${job}`);
    // The reason a poll STOPPED belongs to the connection state, not to the
    // batch feedback line (Etappe 13, requirement 8): a session that ended and
    // a missing permission are states of the live connection, and the feedback
    // line now carries the throttled "N new lines" summary instead. What the
    // contract pins is unchanged: the reader is told, and nothing polls again.
    await expect(page.locator('[data-deploy-log-connection]')).not.toHaveText('', { timeout: 7000 });
    await page.waitForTimeout(2600);
    expect(requests).toBe(1);
  });
}

test('a network fault retries with bounded single-flight polling', async ({ page }) => {
  const job = seedJob('running', 1, 'e2e:network');
  let requests = 0;
  let active = 0;
  let maxActive = 0;
  await page.route(/deploy_log\.php\?.*format=json/, async (route) => {
    requests += 1;
    active += 1;
    maxActive = Math.max(maxActive, active);
    if (requests === 1) {
      await route.abort('connectionfailed');
    } else {
      await new Promise((resolve) => setTimeout(resolve, 100));
      await route.continue();
    }
    active -= 1;
  });
  await page.goto(`deploy_log.php?id=${job}`);
  // Same move as the 401/403 cases: a network fault is a state of the live
  // connection. What matters is unchanged and still asserted here: the reader
  // is told while it is broken, the sentence goes away once it works again, and
  // there is never more than one request in flight.
  const connection = page.locator('[data-deploy-log-connection]');
  await expect(connection).not.toHaveText('', { timeout: 7000 });
  await expect.poll(() => requests, { timeout: 10000 }).toBeGreaterThanOrEqual(2);
  await expect(connection).not.toContainText('interrupted');
  expect(maxActive).toBe(1);
});

test('an anonymous JSON poll receives 401 instead of login HTML', async ({}, testInfo) => {
  const job = seedJob('queued', 0);
  const anonymous = await request.newContext({
    baseURL: testInfo.project.use.baseURL,
    storageState: { cookies: [], origins: [] },
  });
  const response = await anonymous.get(`deploy_log.php?id=${job}&format=json&after_seq=0`);
  expect(response.status()).toBe(401);
  expect(response.headers()['content-type']).toContain('application/json');
  expect((await response.json()).ok).toBe(false);
  await anonymous.dispose();
});

// The follow contract of Etappe 13 is geometry, and geometry is only decidable
// in a browser: the static contract can prove that `scrollPaused = true;` is in
// the file, and the file can still open a live log at the top of its window
// with a switch that says it is following. It did, until this spec was written.
function appendLines(job, first, last) {
  runPhp(`
$db = db();
$job = ${Number(job)};
$values = [];
for ($seq = ${Number(first)}; $seq <= ${Number(last)}; $seq++) {
    $values[] = sprintf("(%d,%d,'ansible','${MARK} line %d')", $job, $seq, $seq);
}
// ONE statement: a poll reads a consistent snapshot, so the batch is either
// fully visible or not at all, which is what makes the counted assertion exact.
$db->query('INSERT INTO deploy_job_logs (job_id, seq, stream, line) VALUES ' . implode(',', $values));
echo 'APPENDED';
`, ['lib/repo/deploy_jobs.php']);
}

function distanceToEnd(locator) {
  return locator.evaluate((element) => element.scrollHeight - element.scrollTop - element.clientHeight);
}

test('following opens at the end, pauses when the reader scrolls up and returns only on demand', async ({ page }) => {
  const worker = 'e2e:deploy-log-follow';
  const job = seedJob('running', 400, worker);
  await page.goto(`deploy_log.php?id=${job}`);

  const scroller = page.locator('[data-deploy-log-scroller]');
  // The window really scrolls; without this the rest of the test would pass on
  // an element that can never be anywhere but at its own bottom.
  expect(await scroller.evaluate((element) => element.scrollHeight - element.clientHeight)).toBeGreaterThan(100);
  // Opening a running job puts the reader at the newest line, because that is
  // what the switch promises. VIRTUSPHERE_DEPLOY_LOG_BOTTOM_TOLERANCE_PX is 4.
  await expect.poll(() => distanceToEnd(scroller), { timeout: 8000 }).toBeLessThanOrEqual(4);

  await scroller.evaluate((element) => { element.scrollTop = 0; });
  const parked = await scroller.evaluate((element) => element.scrollTop);
  await expect(page.locator('[data-deploy-log-feedback]')).not.toHaveText('');

  appendLines(job, 401, 420);

  const jump = page.locator('[data-deploy-log-jump]');
  await expect(jump).toBeVisible({ timeout: 12000 });
  await expect(jump).toContainText('20');
  // The point of the whole mechanism: twenty lines arrived and the reader was
  // not moved a single pixel.
  expect(await scroller.evaluate((element) => element.scrollTop)).toBe(parked);
  await expect(page.locator('[data-deploy-log-body] [data-log-seq]')).toHaveCount(420);

  await jump.click();
  await expect(jump).toBeHidden();
  expect(await distanceToEnd(scroller)).toBeLessThanOrEqual(4);
});

test('turning following off keeps the reader where they are while lines keep arriving', async ({ page }) => {
  const job = seedJob('running', 400, 'e2e:deploy-log-follow-off');
  await page.goto(`deploy_log.php?id=${job}`);

  const scroller = page.locator('[data-deploy-log-scroller]');
  await expect.poll(() => distanceToEnd(scroller), { timeout: 8000 }).toBeLessThanOrEqual(4);
  await page.locator('[data-deploy-log-follow]').uncheck();
  await scroller.evaluate((element) => { element.scrollTop = 40; });
  const parked = await scroller.evaluate((element) => element.scrollTop);

  appendLines(job, 401, 410);
  await expect(page.locator('[data-deploy-log-body] [data-log-seq]')).toHaveCount(410, { timeout: 12000 });

  expect(await scroller.evaluate((element) => element.scrollTop)).toBe(parked);
  // The preference is this browser's, and it survives the next visit.
  await page.reload();
  await expect(page.locator('[data-deploy-log-follow]')).not.toBeChecked();
  expect(await scroller.evaluate((element) => element.scrollTop)).toBe(0);
});

test('the output is announced as a log without speaking per line', async ({ page }) => {
  const job = seedJob('running', 5, 'e2e:deploy-log-aria');
  await page.goto(`deploy_log.php?id=${job}`);

  const scroller = page.locator('[data-deploy-log-scroller]');
  await expect(scroller).toHaveAttribute('role', 'log');
  // Not "polite": an Ansible run emits thousands of lines and a live region
  // would queue every one of them into the screen reader.
  await expect(scroller).toHaveAttribute('aria-live', 'off');
  for (const summary of ['[data-deploy-log-connection]', '[data-deploy-log-feedback]']) {
    await expect(page.locator(summary)).toHaveAttribute('role', 'status');
    await expect(page.locator(summary)).toHaveAttribute('aria-atomic', 'true');
  }
});

test('a background tab stops asking and returns with exactly one catch-up', async ({ page }) => {
  const job = seedJob('running', 3, 'e2e:deploy-log-hidden');
  let requests = 0;
  await page.route(/deploy_log\.php\?.*format=json/, async (route) => {
    requests += 1;
    await route.continue();
  });
  await page.goto(`deploy_log.php?id=${job}`);
  await expect.poll(() => requests, { timeout: 8000 }).toBeGreaterThanOrEqual(1);

  // document.hidden is what the module reads, so overriding the property is the
  // honest stand-in for a tab in the background.
  await page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  const parked = requests;
  appendLines(job, 4, 9);
  await page.waitForTimeout(5000);
  expect(requests).toBe(parked);

  await page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  // The cadence is 2000ms, so within this window a burst of the missed polls
  // would be visible and a single catch-up cannot be.
  await expect.poll(() => requests, { timeout: 1500 }).toBe(parked + 1);
  await page.waitForTimeout(300);
  expect(requests).toBe(parked + 1);

  const rows = page.locator('[data-deploy-log-body] [data-log-seq]');
  await expect(rows).toHaveCount(9);
  const seqs = await rows.evaluateAll((elements) => elements.map((row) => row.dataset.logSeq));
  expect(new Set(seqs).size).toBe(seqs.length);
});
