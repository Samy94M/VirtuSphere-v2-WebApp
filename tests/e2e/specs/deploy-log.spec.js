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

  await expect(page.locator('[data-deploy-status]')).toHaveText('succeeded', { timeout: 12000 });
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
    await expect(page.locator('[data-deploy-log-feedback]')).not.toHaveText('', { timeout: 7000 });
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
  await expect(page.locator('[data-deploy-log-feedback]')).not.toHaveText('', { timeout: 7000 });
  await expect.poll(() => requests, { timeout: 10000 }).toBeGreaterThanOrEqual(2);
  await expect(page.locator('[data-deploy-log-feedback]')).toHaveText('');
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
